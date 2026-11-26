<?php
namespace Easy_MCP_AI\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Token_Endpoint {

    const NAMESPACE_V1 = 'easy-mcp-ai/v1';

    const BODY_ONLY_PARAMS = array( 'code', 'code_verifier', 'refresh_token', 'client_secret' );

    private $failure_detail = array();

    const AUDIT_TEXT_MAX = 255;

    public function handle( \WP_REST_Request $request ) {

        $transport_error = self::enforce_transport_security();

        $rate_error = self::enforce_rate_limit( null === $transport_error ? $request : null );
        if ( null !== $rate_error ) {
            return $rate_error;
        }

        $this->failure_detail = array();
        $response             = $transport_error ?? $this->handle_grant( $request );
        $this->log_failure( $request, $response );
        return $response;
    }

    private function handle_grant( \WP_REST_Request $request ) {
        $query_error = self::reject_query_credentials( $request, self::BODY_ONLY_PARAMS );
        if ( null !== $query_error ) {
            return $query_error;
        }

        $grant_type = sanitize_text_field( $request->get_param( 'grant_type' ) );

        switch ( $grant_type ) {
            case 'authorization_code':
                return $this->handle_authorization_code( $request );

            case 'refresh_token':
                return $this->handle_refresh_token( $request );

            case Device_Authorization::GRANT_TYPE:
                return $this->handle_device_code( $request );

            default:
                return new \WP_REST_Response(
                    array(
                        'error'             => 'unsupported_grant_type',
                        'error_description' => __( 'Only authorization_code, refresh_token and urn:ietf:params:oauth:grant-type:device_code grant types are supported.', 'easy-mcp-ai' ),
                    ),
                    400
                );
        }
    }

    private function log_failure( \WP_REST_Request $request, \WP_REST_Response $response ): void {
        $data  = $response->get_data();
        $error = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';
        if ( $response->get_status() < 400 || in_array( $error, array( 'authorization_pending', 'slow_down' ), true ) ) {
            return;
        }
        $basic     = self::read_basic_credentials( $request );
        $client_id = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
        if ( '' === $client_id && is_array( $basic ) && isset( $basic['client_id'] ) ) {
            $client_id = sanitize_text_field( (string) $basic['client_id'] );
        }
        OAuth_Audit_Log::write(
            '_oauth_token',
            array_map(
                array( self::class, 'audit_text' ),
                array(
                    'stage'      => 'token',
                    'grant_type' => self::audit_grant_type( $request->get_param( 'grant_type' ) ),
                    'error'      => $error,
                    'reason'     => is_array( $data ) && isset( $data['error_description'] ) ? (string) $data['error_description'] : '',
                    'client_id'  => $client_id,
                ) + $this->failure_detail
            ),
            'auth_failure',
            $client_id
        );
    }

    private static function audit_grant_type( $grant_type ): string {
        $grant_type = is_string( $grant_type ) ? $grant_type : '';
        if ( '' === $grant_type || in_array( $grant_type, array( 'authorization_code', 'refresh_token', Device_Authorization::GRANT_TYPE ), true ) ) {
            return $grant_type;
        }
        return 'unsupported';
    }

    private static function audit_text( $value ): string {
        return mb_substr( (string) $value, 0, self::AUDIT_TEXT_MAX );
    }

    private function checked_device( \WP_REST_Request $request, bool $poll ) {
        $device_code = is_string( $request->get_param( 'device_code' ) ) ? $request->get_param( 'device_code' ) : '';
        $resource    = is_string( $request->get_param( 'resource' ) ) ? esc_url_raw( $request->get_param( 'resource' ) ) : '';

        $basic     = self::read_basic_credentials( $request );
        $client_id = self::resolve_client_id( $request, $basic );
        if ( $client_id instanceof \WP_REST_Response ) {
            return $client_id;
        }

        if ( '' === $device_code || '' === $client_id ) {
            return $this->token_error( 'invalid_request', __( 'Missing required parameter.', 'easy-mcp-ai' ) );
        }

        $client = ( new Client_Registry() )->find_client( $client_id );
        if ( null === $client ) {
            return $this->token_error( 'invalid_client', __( 'Client not found or inactive.', 'easy-mcp-ai' ), 401 );
        }

        $auth_error = self::authenticate_client( $request, $client, $basic );
        if ( null !== $auth_error ) {
            return $auth_error;
        }

        $row = Device_Authorization::find_by_device_code( $device_code );

        if ( null === $row || ! hash_equals( (string) $row->client_id, $client_id ) ) {
            return $this->token_error( 'invalid_grant', __( 'The device code is invalid.', 'easy-mcp-ai' ) );
        }

        if ( Device_Authorization::STATUS_CONSUMED === $row->status ) {
            return $this->token_error( 'invalid_grant', __( 'The device code has already been redeemed.', 'easy-mcp-ai' ) );
        }

        if ( Device_Authorization::is_expired( $row ) ) {
            return $this->token_error( 'expired_token', __( 'The device code has expired. Start the login again.', 'easy-mcp-ai' ) );
        }

        if ( ! empty( $row->last_polled_at ) ) {
            $previous = strtotime( $row->last_polled_at . ' UTC' );
            if ( false !== $previous && ( time() - $previous ) < ( Device_Authorization::POLL_INTERVAL - 1 ) ) {
                return $this->token_error( 'slow_down', __( 'Polling too frequently.', 'easy-mcp-ai' ) );
            }
        }
        if ( $poll ) {
            Device_Authorization::touch_polled( (int) $row->id );
        }

        if ( Device_Authorization::STATUS_PENDING === $row->status ) {
            return $this->token_error( 'authorization_pending', __( 'The authorization request is still pending.', 'easy-mcp-ai' ) );
        }

        if ( Device_Authorization::STATUS_DENIED === $row->status ) {
            return $this->token_error( 'access_denied', __( 'The user denied the authorization request.', 'easy-mcp-ai' ) );
        }

        if ( '' === $resource ) {
            $resource = rest_url( self::NAMESPACE_V1 . '/mcp' );
        }
        if ( ! empty( $row->resource ) && ! self::resource_matches( $resource, $row->resource ) ) {
            return $this->token_error( 'invalid_target', __( 'Resource parameter does not match the authorized resource.', 'easy-mcp-ai' ) );
        }

        return $row;
    }

    private function handle_device_code( \WP_REST_Request $request ) {
        $row = $this->checked_device( $request, true );
        if ( $row instanceof \WP_REST_Response ) {
            return $row;
        }

        $claimed = Device_Authorization::STATUS_APPROVED === $row->status ? Device_Authorization::claim_approved( (int) $row->id ) : false;
        if ( null === $claimed ) {
            return $this->token_error( 'server_error', __( 'The device code was not redeemed: the database refused the change. Please try again.', 'easy-mcp-ai' ), 500 );
        }
        if ( ! $claimed ) {
            return $this->token_error( 'invalid_grant', __( 'The device code has already been redeemed.', 'easy-mcp-ai' ) );
        }

        $scope = $this->scope_backed_by_consent( Device_Authorization::GRANT_TYPE, $row );
        if ( $scope instanceof \WP_REST_Response ) {
            return $scope;
        }

        $token_manager = new OAuth_Token_Manager();
        $tokens        = $token_manager->mint_access_token(
            (string) $row->client_id,
            (int) $row->wp_user_id,
            (string) $row->resource,
            $scope
        );

        if ( null === $tokens ) {
            return $this->token_error( 'server_error', __( 'Failed to issue access token.', 'easy-mcp-ai' ), 500 );
        }

        $tokens = $this->settle_against_consent( $token_manager, $tokens, Device_Authorization::GRANT_TYPE, $row, $scope );
        if ( $tokens instanceof \WP_REST_Response ) {
            return $tokens;
        }

        $response = new \WP_REST_Response(
            array(
                'access_token'  => $tokens['access_token'],
                'token_type'    => 'Bearer',
                'expires_in'    => $tokens['expires_in'],
                'refresh_token' => $tokens['refresh_token'],
                'scope'         => $tokens['scope'],
            ),
            200
        );
        $this->add_token_headers( $response );
        return $response;
    }

    private function scope_backed_by_consent( string $grant_type, $grant ) {
        global $wpdb;
        $consent = self::live_consent( (int) $grant->wp_user_id, (string) $grant->client_id );
        if ( null === $consent && ! empty( $wpdb->last_error ) ) {
            return $this->grant_unreadable();
        }
        if ( ! self::consent_backs( $consent, self::bound_consent_id( $grant_type, $grant ) ) ) {
            return $this->consent_refusal( $grant_type, 'revoked' );
        }
        $scope = self::cap_by_consent( (string) $grant->scope, (string) $consent->scope );
        if ( '' === $scope ) {
            return $this->consent_refusal( $grant_type, 'empty' );
        }
        return $scope;
    }

    private function settle_against_consent( OAuth_Token_Manager $token_manager, array $tokens, string $grant_type, $grant, string $minted_scope ) {
        global $wpdb;
        $post_consent = self::live_consent( (int) $grant->wp_user_id, (string) $grant->client_id );
        $token_id     = isset( $tokens['token_id'] ) ? (int) $tokens['token_id'] : 0;
        if ( null === $post_consent && ! empty( $wpdb->last_error ) ) {
            $token_manager->revoke_chain( $token_id );
            return $this->grant_unreadable();
        }
        if ( ! self::consent_backs( $post_consent, self::bound_consent_id( $grant_type, $grant ) ) ) {
            $token_manager->revoke_chain( $token_id );
            return $this->consent_refusal( $grant_type, 'revoked' );
        }
        $settled_scope = self::cap_by_consent( (string) $grant->scope, (string) $post_consent->scope );
        if ( '' === $settled_scope ) {
            $token_manager->revoke_chain( $token_id );
            return $this->consent_refusal( $grant_type, 'empty' );
        }
        if ( $token_id <= 0 ) {
            return $tokens;
        }

        $tokens_table = $wpdb->prefix . 'easy_mcp_ai_oauth_access_tokens';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table prefixed by $wpdb->prefix; the conditional UPDATE and the read-back are the point.
        if ( $settled_scope !== $minted_scope ) {
            $narrowed = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$tokens_table} SET scope = %s WHERE id = %d AND scope = %s",
                    $settled_scope,
                    $token_id,
                    $minted_scope
                )
            );
            if ( false === $narrowed ) {
                $token_manager->revoke_chain( $token_id );
                return $this->token_error( 'server_error', __( 'The token was not issued: the database refused narrowing it to the permissions the connection now has. Please authorize again.', 'easy-mcp-ai' ), 500 );
            }
        }
        $final = $wpdb->get_row(
            $wpdb->prepare( "SELECT scope, is_active FROM {$tokens_table} WHERE id = %d LIMIT 1", $token_id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( null === $final && ! empty( $wpdb->last_error ) ) {
            $token_manager->revoke_chain( $token_id );
            return $this->grant_unreadable();
        }
        if ( ! is_object( $final ) || empty( $final->is_active ) || '' === trim( (string) $final->scope ) ) {
            $token_manager->revoke_chain( $token_id );
            return $this->consent_refusal( $grant_type, 'empty' );
        }
        $final_scope = (string) $final->scope;
        if ( self::cap_by_consent( $final_scope, (string) $post_consent->scope ) !== $final_scope ) {
            $token_manager->revoke_chain( $token_id );
            return $this->consent_refusal( $grant_type, 'not_narrowed' );
        }
        $tokens['scope'] = $final_scope;
        return $tokens;
    }

    private static function bound_consent_id( string $grant_type, $grant ): ?int {
        if ( Device_Authorization::GRANT_TYPE !== $grant_type ) {
            return null;
        }
        return isset( $grant->consent_id ) ? (int) $grant->consent_id : 0;
    }

    private static function consent_backs( $consent, ?int $consent_id ): bool {
        return null !== $consent
            && '' !== trim( (string) $consent->scope )
            && ( null === $consent_id || ( $consent_id > 0 && (int) $consent->id === $consent_id ) );
    }

    private function grant_unreadable(): \WP_REST_Response {
        return $this->token_error( 'server_error', __( 'No token was issued: the database refused reading the grant. Please authorize again.', 'easy-mcp-ai' ), 500 );
    }

    private function consent_refusal( string $grant_type, string $reason ): \WP_REST_Response {
        if ( Device_Authorization::GRANT_TYPE === $grant_type ) {
            $messages = array(
                'revoked'      => __( 'The grant behind this device code has been revoked.', 'easy-mcp-ai' ),
                'not_narrowed' => __( 'The grant behind this device code could not be narrowed to its current permissions.', 'easy-mcp-ai' ),
                'empty'        => __( 'The grant behind this device code no longer covers any permission.', 'easy-mcp-ai' ),
            );
        } elseif ( 'refresh_token' === $grant_type ) {
            $messages = array(
                'revoked'      => __( 'The grant behind this refresh token has been revoked.', 'easy-mcp-ai' ),
                'not_narrowed' => __( 'The grant behind this refresh token could not be narrowed to its current permissions.', 'easy-mcp-ai' ),
                'empty'        => __( 'The grant behind this refresh token no longer covers any permission.', 'easy-mcp-ai' ),
            );
        } else {
            $messages = array(
                'revoked'      => __( 'The grant behind this authorization code has been revoked.', 'easy-mcp-ai' ),
                'not_narrowed' => __( 'The grant behind this authorization code could not be narrowed to its current permissions.', 'easy-mcp-ai' ),
                'empty'        => __( 'The grant behind this authorization code no longer covers any permission.', 'easy-mcp-ai' ),
            );
        }
        return $this->token_error( 'invalid_grant', $messages[ $reason ] ?? $messages['empty'] );
    }

    private function checked_code( \WP_REST_Request $request ) {
        global $wpdb;

        $code          = self::body_param( $request, 'code' );
        $code_verifier = self::body_param( $request, 'code_verifier' );
        $basic         = self::read_basic_credentials( $request );
        $client_id     = self::resolve_client_id( $request, $basic );
        if ( $client_id instanceof \WP_REST_Response ) {
            return $client_id;
        }
        $redirect_uri  = is_string( $request->get_param( 'redirect_uri' ) ) ? esc_url_raw( $request->get_param( 'redirect_uri' ), Client_Registry::redirect_uri_allowed_protocols() ) : '';
        $resource      = is_string( $request->get_param( 'resource' ) ) ? esc_url_raw( $request->get_param( 'resource' ) ) : '';

        if ( '' === $code || '' === $code_verifier || '' === $client_id || '' === $redirect_uri ) {
            return $this->token_error( 'invalid_request', __( 'Missing required parameter.', 'easy-mcp-ai' ) );
        }

        if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $code_verifier ) ) {
            return $this->token_error( 'invalid_request', __( 'Invalid code_verifier format.', 'easy-mcp-ai' ) );
        }

        $code_hash   = \Easy_MCP_AI\Auth\Token_Keys::hash_current( $code );
        $codes_table = $wpdb->prefix . 'easy_mcp_ai_oauth_codes';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table prefixed by $wpdb->prefix; auth code lookup must be fresh.
        $code_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$codes_table} WHERE code_hash = %s LIMIT 1",
                $code_hash
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $code_row ) {
            return $this->token_error( 'invalid_grant', __( 'Authorization code not found.', 'easy-mcp-ai' ) );
        }

        $is_reuse = ( null !== $code_row->used_at );

        $now_utc = gmdate( 'Y-m-d H:i:s' );
        if ( ! $is_reuse && $code_row->expires_at <= $now_utc ) {
            return $this->token_error( 'invalid_grant', __( 'Authorization code has expired.', 'easy-mcp-ai' ) );
        }

        if ( $code_row->client_id !== Client_Metadata::storage_id( $client_id ) ) {
            return $this->token_error( 'invalid_grant', __( 'Client ID mismatch.', 'easy-mcp-ai' ) );
        }

        $registry = new Client_Registry();
        $client   = $registry->find_client( $client_id );
        if ( null === $client ) {
            return $this->token_error( 'invalid_client', __( 'Client not found or inactive.', 'easy-mcp-ai' ), 401 );
        }

        $auth_error = self::authenticate_client( $request, $client, $basic );
        if ( null !== $auth_error ) {
            return $auth_error;
        }

        if ( $code_row->redirect_uri !== $redirect_uri ) {
            $this->failure_detail = array(
                'redirect_host'          => Authorization_Endpoint::redirect_host( $redirect_uri ),
                'expected_redirect_host' => Authorization_Endpoint::redirect_host( $code_row->redirect_uri ),
            );
            return $this->token_error( 'invalid_grant', __( 'Redirect URI mismatch.', 'easy-mcp-ai' ) );
        }

        if ( '' === $resource ) {
            $resource = rest_url( self::NAMESPACE_V1 . '/mcp' );
        }
        if ( ! empty( $code_row->resource ) && ! self::resource_matches( $resource, $code_row->resource ) ) {
            return $this->token_error( 'invalid_target', __( 'Resource parameter does not match the authorized resource.', 'easy-mcp-ai' ) );
        }

        $computed_challenge = self::base64url_encode( hash( 'sha256', $code_verifier, true ) );
        if ( ! hash_equals( $code_row->code_challenge, $computed_challenge ) ) {
            return $this->token_error( 'invalid_grant', __( 'PKCE verification failed.', 'easy-mcp-ai' ) );
        }

        return array(
            'row'       => $code_row,
            'code_hash' => $code_hash,
            'reuse'     => $is_reuse,
            'now'       => $now_utc,
        );
    }

    private function handle_authorization_code( \WP_REST_Request $request ) {
        global $wpdb;

        $checked = $this->checked_code( $request );
        if ( $checked instanceof \WP_REST_Response ) {
            return $checked;
        }
        $code_row    = $checked['row'];
        $code_hash   = $checked['code_hash'];
        $is_reuse    = $checked['reuse'];
        $now_utc     = $checked['now'];
        $codes_table = $wpdb->prefix . 'easy_mcp_ai_oauth_codes';

        if ( $is_reuse ) {
            return $this->refuse_spent_code( $code_row, __( 'Authorization code has already been used.', 'easy-mcp-ai' ) );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table prefixed by $wpdb->prefix; atomic redemption must hit DB.
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$codes_table} SET used_at = %s WHERE code_hash = %s AND used_at IS NULL AND expires_at > %s",
                $now_utc,
                $code_hash,
                $now_utc
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false === $affected ) {
            return $this->token_error( 'server_error', __( 'The authorization code was not redeemed: the database refused the change. Please try again.', 'easy-mcp-ai' ), 500 );
        }
        if ( 1 !== (int) $affected ) {
            return $this->refuse_spent_code( $code_row, __( 'Authorization code already redeemed or expired.', 'easy-mcp-ai' ) );
        }

        $scope = $this->scope_backed_by_consent( 'authorization_code', $code_row );
        if ( $scope instanceof \WP_REST_Response ) {
            return $scope;
        }

        $token_manager = new OAuth_Token_Manager();
        $tokens = $token_manager->mint_access_token(
            $code_row->client_id,
            (int) $code_row->wp_user_id,
            $code_row->resource,
            $scope
        );

        if ( null === $tokens ) {
            return $this->token_error( 'server_error', __( 'Failed to issue access token.', 'easy-mcp-ai' ), 500 );
        }

        $minted_token_id = isset( $tokens['token_id'] ) ? (int) $tokens['token_id'] : 0;
        $linked          = false;
        if ( $minted_token_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table write.
            $linked = 1 === $wpdb->update(
                $codes_table,
                array( 'minted_token_id' => $minted_token_id ),
                array( 'code_hash' => $code_hash ),
                array( '%d' ),
                array( '%s' )
            );
        }
        if ( ! $linked ) {
            $token_manager->revoke_token( (string) OAuth_Token_Manager::hash_presented( $tokens['access_token'] ) );
            return $this->token_error( 'server_error', __( 'Failed to issue access token: the database refused recording it. Please authorize again.', 'easy-mcp-ai' ), 500 );
        }

        $tokens = $this->settle_against_consent( $token_manager, $tokens, 'authorization_code', $code_row, $scope );
        if ( $tokens instanceof \WP_REST_Response ) {
            return $tokens;
        }

        $response = new \WP_REST_Response(
            array(
                'access_token'  => $tokens['access_token'],
                'token_type'    => 'Bearer',
                'expires_in'    => $tokens['expires_in'],
                'refresh_token' => $tokens['refresh_token'],
                'scope'         => $tokens['scope'],
            ),
            200
        );
        $this->add_token_headers( $response );
        return $response;
    }

    private function checked_refresh( \WP_REST_Request $request ) {

        $refresh_token = self::body_param( $request, 'refresh_token' );
        $basic         = self::read_basic_credentials( $request );
        $client_id     = self::resolve_client_id( $request, $basic );
        if ( $client_id instanceof \WP_REST_Response ) {
            return $client_id;
        }
        $resource      = is_string( $request->get_param( 'resource' ) ) ? $request->get_param( 'resource' ) : '';

        if ( '' === $refresh_token || '' === $client_id ) {
            return $this->token_error( 'invalid_request', __( 'Missing required parameter.', 'easy-mcp-ai' ) );
        }

        $client = ( new Client_Registry() )->find_client( $client_id );
        if ( ! $client ) {
            return $this->token_error( 'invalid_client', __( 'Client not found or inactive.', 'easy-mcp-ai' ), 401 );
        }

        $auth_error = self::authenticate_client( $request, $client, $basic );
        if ( null !== $auth_error ) {
            return $auth_error;
        }

        if ( empty( $resource ) ) {
            $resource = rest_url( self::NAMESPACE_V1 . '/mcp' );
        }

        $expected_resource = rest_url( self::NAMESPACE_V1 . '/mcp' );
        if ( ! self::resource_matches( $resource, $expected_resource ) ) {
            return $this->token_error( 'invalid_grant', __( 'Resource mismatch.', 'easy-mcp-ai' ) );
        }

        return array(
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'resource'      => $resource,
        );
    }

    private function handle_refresh_token( \WP_REST_Request $request ) {
        $checked = $this->checked_refresh( $request );
        if ( $checked instanceof \WP_REST_Response ) {
            return $checked;
        }

        $stored_client_id = Client_Metadata::storage_id( $checked['client_id'] );
        $token_manager    = new OAuth_Token_Manager();
        $result           = $token_manager->refresh( $checked['refresh_token'], $stored_client_id, $checked['resource'] );

        if ( null === $result ) {
            return $this->token_error( 'server_error', __( 'The token was not refreshed: the database refused the change. Please try again, or authorize again if the refresh token is no longer accepted.', 'easy-mcp-ai' ), 500 );
        }
        if ( false === $result ) {
            return $this->token_error( 'invalid_grant', __( 'Refresh token is invalid, expired, or revoked.', 'easy-mcp-ai' ) );
        }

        $grant  = (object) array(
            'wp_user_id' => $result['wp_user_id'],
            'client_id'  => $stored_client_id,
            'scope'      => $result['scope'],
        );
        $result = $this->settle_against_consent( $token_manager, $result, 'refresh_token', $grant, $result['scope'] );
        if ( $result instanceof \WP_REST_Response ) {
            return $result;
        }

        $response = new \WP_REST_Response(
            array(
                'access_token'  => $result['access_token'],
                'token_type'    => 'Bearer',
                'expires_in'    => $result['expires_in'],
                'refresh_token' => $result['refresh_token'],
                'scope'         => $result['scope'],
            ),
            200
        );
        $this->add_token_headers( $response );
        return $response;
    }

    public static function live_consent( int $wp_user_id, string $client_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'easy_mcp_ai_oauth_consents';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table prefixed by $wpdb->prefix; the read must be fresh, it is the revocation check.
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, scope FROM {$table} WHERE wp_user_id = %d AND client_id = %s LIMIT 1", $wp_user_id, $client_id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return is_object( $row ) && isset( $row->id, $row->scope ) ? $row : null;
    }

    public static function cap_scope( string $granted, string $consent ): string {
        $g = array_values( array_filter( array_map( 'trim', explode( ' ', $granted ) ) ) );
        $c = array_values( array_filter( array_map( 'trim', explode( ' ', $consent ) ) ) );
        if ( in_array( 'mcp', $c, true ) ) {
            return implode( ' ', $g );
        }
        if ( in_array( 'mcp', $g, true ) ) {
            return implode( ' ', $c );
        }
        return implode( ' ', array_values( array_intersect( $g, $c ) ) );
    }

    public static function cap_by_consent( string $granted, string $consent ): string {
        $held = array_values( array_filter( array_map( 'trim', explode( ' ', $consent ) ) ) );
        $want = array_values( array_filter( array_map( 'trim', explode( ' ', $granted ) ) ) );
        if ( in_array( 'mcp', $held, true ) || in_array( 'mcp', $want, true ) ) {
            return implode( ' ', array_values( array_unique( explode( ' ', self::cap_scope( $granted, $consent ) ) ) ) );
        }
        $held = array_values( array_unique( array_merge( $held, self::leaves_of( $held ) ) ) );
        $kept = array();
        foreach ( $want as $scope ) {
            $grants = array_merge( array( $scope ), self::leaves_of( array( $scope ) ) );
            $parts  = self::holds( $held, $scope ) ? array( $scope ) : array_filter(
                $held,
                static function ( $part ) use ( $grants ) {
                    return self::holds( $grants, $part );
                }
            );
            foreach ( $parts as $part ) {
                if ( ! in_array( $part, $kept, true ) ) {
                    $kept[] = $part;
                }
            }
        }
        return implode( ' ', $kept );
    }

    private static function holds( array $scopes, string $scope ): bool {
        return in_array( $scope, $scopes, true )
            || ( 0 === strpos( $scope, 'mcp:abilities:' ) && in_array( 'mcp:abilities', $scopes, true ) );
    }

    private static function leaves_of( array $scopes ): array {
        return Scope_Map::expand_category_scopes( Scope_Map::apply_legacy_scope_upgrades( $scopes ) );
    }

    private function revoke_tokens_for_code( $code_row ) {
        global $wpdb;
        $tokens_table = $wpdb->prefix . 'easy_mcp_ai_oauth_access_tokens';
        $codes_table  = $wpdb->prefix . 'easy_mcp_ai_oauth_codes';

        if ( empty( $code_row->minted_token_id ) && ! empty( $code_row->code_hash ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table prefixed by $wpdb->prefix.
            $fresh_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT minted_token_id FROM {$codes_table} WHERE code_hash = %s LIMIT 1",
                    $code_row->code_hash
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( ! empty( $fresh_id ) ) {
                $code_row->minted_token_id = (int) $fresh_id;
            } elseif ( ! empty( $wpdb->last_error ) ) {
                return null;
            }
        }

        if ( empty( $code_row->minted_token_id ) ) {
            return false;
        }
        return ( new OAuth_Token_Manager() )->revoke_chain( (int) $code_row->minted_token_id ) ? true : null;
    }

    private function refuse_spent_code( $code_row, string $refusal ): \WP_REST_Response {
        $revoked = $this->revoke_tokens_for_code( $code_row );
        if ( null === $revoked ) {
            return $this->token_error( 'server_error', $refusal . ' ' . __( 'The tokens issued from it were not revoked: the database refused the change.', 'easy-mcp-ai' ), 500 );
        }
        return $this->token_error( 'invalid_grant', true === $revoked ? $refusal . ' ' . __( 'The tokens issued from it have been revoked.', 'easy-mcp-ai' ) : $refusal );
    }

    private function add_token_headers( \WP_REST_Response $response ) {
        $response->header( 'Content-Type', 'application/json' );
        $response->header( 'Cache-Control', 'no-store' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Referrer-Policy', 'no-referrer' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
    }

    public static function wire_safe_description( $description ): string {
        if ( ! is_scalar( $description ) ) {
            return '';
        }
        $text = function_exists( 'remove_accents' ) ? remove_accents( (string) $description ) : (string) $description;
        $text = preg_replace( '/[^\x20\x21\x23-\x5B\x5D-\x7E]+/', ' ', $text );
        return trim( (string) $text );
    }

    private function token_error( $error, $description, $status = 400 ) {
        return self::build_token_error( $error, $description, $status, 'Bearer' );
    }

    public static function build_token_error( $error, $description, $status = 400, $challenge_scheme = 'Bearer' ) {
        $response = new \WP_REST_Response(
            array(
                'error'             => $error,
                'error_description' => self::wire_safe_description( $description ),
            ),
            $status
        );
        if ( 401 === (int) $status ) {
            $response->header(
                'WWW-Authenticate',
                sprintf( '%s realm="oauth", error="%s"', 'Basic' === $challenge_scheme ? 'Basic' : 'Bearer', $error )
            );
        }
        return $response;
    }

    public static function body_param( \WP_REST_Request $request, $key ) {
        $json = $request->get_json_params();
        if ( is_array( $json ) && isset( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
            return $json[ $key ];
        }
        $body = $request->get_body_params();
        if ( is_array( $body ) && isset( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
            return $body[ $key ];
        }
        return '';
    }

    public static function reject_query_credentials( \WP_REST_Request $request, array $keys ) {
        $query = $request->get_query_params();
        if ( ! is_array( $query ) ) {
            return null;
        }
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $query ) ) {
                return self::build_token_error(
                    'invalid_request',
                    /* translators: %s: parameter name */
                    sprintf( __( 'The %s parameter must be sent in the request body, not in the query string.', 'easy-mcp-ai' ), $key ),
                    400
                );
            }
        }
        return null;
    }

    public static function read_basic_credentials( \WP_REST_Request $request ) {
        $raw = $request->get_header( 'authorization' );
        if ( is_string( $raw ) && 0 === strncasecmp( $raw, 'Basic ', 6 ) ) {
            return self::decode_basic( substr( $raw, 6 ) );
        }

        if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) && is_string( $_SERVER['PHP_AUTH_USER'] ) && is_string( $_SERVER['PHP_AUTH_PW'] ) ) {
            return array(
                'client_id' => urldecode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Credential compared by SHA-256 hash; sanitizing would alter it.
                'secret'    => urldecode( wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Credential compared by SHA-256 hash; sanitizing would alter it.
            );
        }

        if ( class_exists( '\Easy_MCP_AI\Auth_Header' ) ) {
            $headers = \Easy_MCP_AI\Auth_Header::request_headers();
            if ( null !== $headers ) {
                $found = \Easy_MCP_AI\Auth_Header::find_authorization( $headers, 'Basic' );
                if ( null !== $found ) {
                    return self::decode_basic( substr( $found, 6 ) );
                }
            }
        }

        return null;
    }

    private static function decode_basic( $payload ) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an HTTP Basic credential (RFC 7617), not obfuscation.
        $decoded = base64_decode( trim( (string) $payload ), true );
        if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
            return array( 'client_id' => '', 'secret' => '' );
        }
        list( $user, $pass ) = explode( ':', $decoded, 2 );
        return array(
            'client_id' => urldecode( $user ),
            'secret'    => urldecode( $pass ),
        );
    }

    public static function resolve_client_id( \WP_REST_Request $request, $basic ) {
        $body_client_id  = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
        $basic_client_id = ( is_array( $basic ) && isset( $basic['client_id'] ) ) ? sanitize_text_field( $basic['client_id'] ) : '';

        if ( '' !== $body_client_id && '' !== $basic_client_id && ! hash_equals( $body_client_id, $basic_client_id ) ) {
            return self::build_token_error( 'invalid_client', __( 'client_id in the request body does not match the Authorization header.', 'easy-mcp-ai' ), 401, 'Basic' );
        }

        return '' !== $body_client_id ? $body_client_id : $basic_client_id;
    }

    public static function authenticate_client( \WP_REST_Request $request, $client, $basic ) {
        $method       = Client_Registry::effective_auth_method( $client );
        $body_secret  = self::body_param( $request, 'client_secret' );
        $basic_secret = ( is_array( $basic ) && isset( $basic['secret'] ) ) ? (string) $basic['secret'] : '';
        $used_basic   = is_array( $basic );
        $scheme       = ( $used_basic || Client_Registry::AUTH_METHOD_BASIC === $method ) ? 'Basic' : 'Bearer';

        if ( '' === $method ) {
            return self::build_token_error( 'invalid_client', __( 'Client registration is unusable; an administrator must revoke and re-register it.', 'easy-mcp-ai' ), 401, $scheme );
        }

        if ( Client_Registry::AUTH_METHOD_NONE === $method ) {
            if ( '' !== $body_secret || $used_basic ) {
                return self::build_token_error( 'invalid_client', __( 'This client is registered as a public client (token_endpoint_auth_method=none); do not send a client secret.', 'easy-mcp-ai' ), 401, $scheme );
            }
            return null;
        }

        if ( '' !== $body_secret && $used_basic ) {
            return self::build_token_error( 'invalid_request', __( 'Use exactly one client authentication method per request: client_secret in the body OR an Authorization: Basic header.', 'easy-mcp-ai' ), 400 );
        }

        $secret = '' !== $body_secret ? $body_secret : $basic_secret;
        if ( '' === $secret ) {
            return self::build_token_error( 'invalid_client', __( 'Client authentication required: this client was registered with a client secret.', 'easy-mcp-ai' ), 401, $scheme );
        }

        $client_arr = (array) $client;
        if ( ! hash_equals( (string) $client_arr['client_secret_hash'], hash( 'sha256', $secret ) ) ) {
            return self::build_token_error( 'invalid_client', __( 'Client authentication failed.', 'easy-mcp-ai' ), 401, $scheme );
        }

        return null;
    }

    public static function resource_matches( string $candidate, string $expected ): bool {
        $a = self::canonicalize_resource_uri( $candidate );
        $b = self::canonicalize_resource_uri( $expected );
        return '' !== $a && $a === $b;
    }

    public static function canonicalize_resource_uri( string $uri ): string {
        $uri = trim( $uri );
        if ( '' === $uri ) {
            return '';
        }

        $parts = wp_parse_url( $uri );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
            return '';
        }

        $scheme = \Easy_MCP_AI\Ascii::lower( $parts['scheme'] );
        $host   = \Easy_MCP_AI\Ascii::lower( $parts['host'] );

        $port = '';
        if ( isset( $parts['port'] ) ) {
            $default = ( 'https' === $scheme ) ? 443 : ( ( 'http' === $scheme ) ? 80 : 0 );
            if ( (int) $parts['port'] !== $default ) {
                $port = ':' . (int) $parts['port'];
            }
        }

        $path  = isset( $parts['path'] ) ? $parts['path'] : '';
        $query = isset( $parts['query'] ) ? $parts['query'] : '';

        if ( '' !== $query ) {
            parse_str( $query, $q );
            if ( isset( $q['rest_route'] ) && is_string( $q['rest_route'] ) && '' !== $q['rest_route'] ) {
                $path  = '/wp-json' . $q['rest_route'];
                $query = '';
            }
        }

        if ( strlen( $path ) > 1 && '/' === substr( $path, -1 ) ) {
            $path = rtrim( $path, '/' );
        }

        $canonical = $scheme . '://' . $host . $port . $path;
        if ( '' !== $query ) {
            $canonical .= '?' . $query;
        }

        return $canonical;
    }

    private static function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    public static function enforce_transport_security() {
        if ( is_ssl() ) {
            return null;
        }

        if ( self::is_local_dev_request() ) {
            return null;
        }

        return new \WP_REST_Response(
            array(
                'error'             => 'insecure_transport',
                'error_description' => self::insecure_transport_description(),
            ),
            403
        );
    }

    private static function insecure_transport_description(): string {
        $proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
            ? strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) )
            : '';

        if ( 'https' === $proto ) {
            return __( 'HTTPS is required, and this request arrived over HTTPS at your proxy or CDN — but WordPress does not know that, so is_ssl() returns false. TLS is terminating in front of PHP and the forwarded protocol is not being honoured. Fix it in wp-config.php, above the "stop editing" line: if ( isset( $_SERVER["HTTP_X_FORWARDED_PROTO"] ) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https" ) { $_SERVER["HTTPS"] = "on"; } Only add this if a proxy you trust sets that header. This also corrects WordPress core and every other plugin on the site, not just this one.', 'easy-mcp-ai' );
        }

        return __( 'HTTPS is required. This request reached PHP over plain HTTP. If your site is served over HTTPS through a proxy or CDN, WordPress is not being told — set $_SERVER["HTTPS"] from the forwarded protocol in wp-config.php. For local development over plain HTTP, define EASY_MCP_AI_OAUTH_ALLOW_HTTP as true in wp-config.php instead. Never set that on a production site.', 'easy-mcp-ai' );
    }

    private static function is_local_dev_request(): bool {
        if ( \Easy_MCP_AI\Config::get( 'oauth_allow_http' ) ) {
            return true;
        }

        if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
            return false;
        }

        foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP' ) as $proxy_header ) {
            if ( ! empty( $_SERVER[ $proxy_header ] ) ) {
                return false;
            }
        }

        $remote = trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), '[]' );
        return in_array( $remote, array( '127.0.0.1', '::1' ), true );
    }

    public static function enforce_rate_limit( ?\WP_REST_Request $request = null ) {
        $admitted = null === $request ? null : static function () use ( $request ): bool {
            return self::admitted_past_site_limit( $request );
        };
        return Rate_Limit::check( 'token', 'easy_mcp_ai_token_rl_', 120, 1200, $admitted );
    }

    private static function admitted_past_site_limit( \WP_REST_Request $request ): bool {
        if ( null !== self::reject_query_credentials( $request, self::BODY_ONLY_PARAMS ) ) {
            return false;
        }
        $grant = new self();
        switch ( sanitize_text_field( (string) $request->get_param( 'grant_type' ) ) ) {
            case 'refresh_token':
                $checked = $grant->checked_refresh( $request );
                return is_array( $checked ) && ( new OAuth_Token_Manager() )->refresh_token_is_live( $checked['refresh_token'], Client_Metadata::storage_id( $checked['client_id'] ) );
            case 'authorization_code':
                $checked = $grant->checked_code( $request );
                return is_array( $checked ) && ! $checked['reuse'];
            case Device_Authorization::GRANT_TYPE:
                $checked = $grant->checked_device( $request, false );
                return ! $checked instanceof \WP_REST_Response;
        }
        return false;
    }

    public static function rl_transient_increment( string $key ): int {
        return \Easy_MCP_AI\Fixed_Window_Counter::hit( $key, HOUR_IN_SECONDS )['count'];
    }
}
