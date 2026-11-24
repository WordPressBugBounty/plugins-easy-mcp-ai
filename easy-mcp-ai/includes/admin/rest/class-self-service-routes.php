<?php

namespace Easy_MCP_AI\Admin\Rest;

use Easy_MCP_AI\Admin\Self_Service_Keys;
use Easy_MCP_AI\Auth\Token_Manager;
use Easy_MCP_AI\Tools\Tool_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Self_Service_Routes {

    const NAMESPACE_V1 = 'easy-mcp-ai-profile/v1';

    const TOOLS_ROUTE = '/self-service/tools';

    const KEYS_ROUTE = '/self-service/keys';

    const REQUEST_CLAIM = 'easy_mcp_ai_self_create_';

    const REQUEST_TTL = 600;

    const PENDING_TTL = 60;

    private $manager;

    private $registry;

    public function __construct( Token_Manager $manager, Tool_Registry $registry ) {
        $this->manager  = $manager;
        $this->registry = $registry;
    }

    public function register(): void {
        \register_rest_route( self::NAMESPACE_V1, self::TOOLS_ROUTE, array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'tools' ),
            'permission_callback' => array( $this, 'permission' ),
        ) );
        \register_rest_route( self::NAMESPACE_V1, self::KEYS_ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create' ),
            'permission_callback' => array( $this, 'permission' ),
        ) );
    }

    public function permission( $request ) {
        $nonce = (string) $request->get_header( 'X-WP-Nonce' );
        if ( '' === $nonce || ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( 'easy_mcp_ai_self_service_nonce', __( 'The security token for this request is invalid or has expired.', 'easy-mcp-ai' ), array( 'status' => 403 ) );
        }
        $cookie_user = \wp_validate_auth_cookie( '', 'logged_in' );
        if ( null !== \rest_get_authenticated_app_password() || ! $cookie_user || (int) $cookie_user !== \get_current_user_id() ) {
            return new \WP_Error( 'easy_mcp_ai_self_service_session', __( 'Self-service keys are managed from your profile, signed in to this site.', 'easy-mcp-ai' ), array( 'status' => 403 ) );
        }
        if ( ! Self_Service_Keys::enabled() ) {
            return new \WP_Error( 'easy_mcp_ai_self_service_forbidden', __( 'Self-service keys are not available for your account.', 'easy-mcp-ai' ), array( 'status' => \rest_authorization_required_code() ) );
        }
        return true;
    }

    public function tools( $request ) {
        unset( $request );
        return new \WP_REST_Response( array( 'tools' => $this->keys()->offered_rows() ), 200 );
    }

    public function create( $request ) {
        $body       = $request->get_json_params();
        $body       = is_array( $body ) ? $body : array();
        $request_id = isset( $body['request_id'] ) && is_string( $body['request_id'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $body['request_id'] ) ? $body['request_id'] : '';
        $claim      = '';
        $token      = '';
        if ( '' !== $request_id ) {
            $claim = self::REQUEST_CLAIM . \get_current_user_id() . '_' . $request_id;
            self::forget_old_claims( \get_current_user_id() );
            $token = \Easy_MCP_AI\Option_Lock::acquire( $claim, self::REQUEST_TTL );
            if ( '' === $token ) {
                return self::repeat( $claim );
            }
        }
        $result = $this->keys()->create( $body );
        if ( \is_wp_error( $result ) ) {
            if ( '' !== $token ) {
                \Easy_MCP_AI\Option_Lock::release( $claim, $token );
            }
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 'forbidden' === $result->get_error_code() ? 403 : 400 ) );
        }
        Token_Manager::stash_new_token( $result );
        if ( '' !== $token ) {
            self::complete( $claim, $token, (int) $result['id'] );
        }
        return self::created( (int) $result['id'] );
    }

    private static function repeat( string $claim ) {
        $value = self::claim_value( $claim );
        if ( null === $value ) {
            return new \WP_Error( 'easy_mcp_ai_self_service_not_claimed', __( 'The key could not be created, and nothing was stored. Try again.', 'easy-mcp-ai' ), array( 'status' => 503 ) );
        }
        $parts = explode( ':', $value );
        $id    = isset( $parts[2] ) ? (int) $parts[2] : 0;
        if ( $id <= 0 && (int) $parts[0] + self::PENDING_TTL < time() ) {
            return new \WP_Error( 'easy_mcp_ai_self_service_unconfirmed', __( 'This key may have been created, but that could not be confirmed. Check your keys below before creating another.', 'easy-mcp-ai' ), array( 'status' => 409 ) );
        }
        if ( $id <= 0 ) {
            return new \WP_Error( 'easy_mcp_ai_self_service_pending', __( 'This key is still being created. Wait a moment, then try again.', 'easy-mcp-ai' ), array( 'status' => 409 ) );
        }
        if ( Token_Manager::new_token_waiting( $id ) ) {
            return self::created( $id );
        }
        return new \WP_Error( 'easy_mcp_ai_self_service_key_shown', __( 'This key was created, but it can no longer be shown. Revoke it below and create another.', 'easy-mcp-ai' ), array( 'status' => 409, 'id' => $id ) );
    }

    private static function created( int $id ): \WP_REST_Response {
        return new \WP_REST_Response( array(
            'id'       => $id,
            'redirect' => \admin_url( 'profile.php?easy_mcp_key=' . $id . '#easy-mcp-keys' ),
        ), 201 );
    }

    private static function complete( string $claim, string $token, int $id ): void {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- completes only the row this request claimed.
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token . ':' . $id, $claim, $token ) );
        \wp_cache_delete( $claim, 'options' );
    }

    private static function claim_value( string $claim ): ?string {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- another request's claim, as it is now.
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $claim ) );
        return null === $value ? null : (string) $value;
    }

    private static function forget_old_claims( int $user_id ): void {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
            return;
        }
        $prefix = self::REQUEST_CLAIM . $user_id . '_';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the user's claims, as they are now.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            if ( (int) ( $row['option_value'] ?? 0 ) + self::REQUEST_TTL < time() ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- only the expired value read above.
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $row['option_name'], $row['option_value'] ) );
            }
        }
    }

    private function keys(): Self_Service_Keys {
        return new Self_Service_Keys( $this->manager, $this->registry, false );
    }
}
