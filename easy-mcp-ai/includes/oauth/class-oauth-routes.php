<?php
namespace Easy_MCP_AI\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OAuth_Routes {

    const NAMESPACE_V1 = 'easy-mcp-ai/v1';

    public function register_routes() {

        register_rest_route(
            self::NAMESPACE_V1,
            '/.well-known/oauth-protected-resource',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( new Discovery(), 'get_protected_resource_metadata' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/.well-known/oauth-authorization-server',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( new Discovery(), 'get_authorization_server_metadata' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/discovery/oauth-protected-resource',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( new Discovery(), 'get_protected_resource_metadata' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/discovery/oauth-authorization-server',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( new Discovery(), 'get_authorization_server_metadata' ),
                'permission_callback' => '__return_true',
            )
        );

        foreach ( array(
            '/discovery/resource/oauth-protected-resource' => 'get_resource_issuer_protected_resource_metadata',
            '/mcp/.well-known/oauth-protected-resource'    => 'get_resource_issuer_protected_resource_metadata',
            '/mcp/.well-known/oauth-authorization-server'  => 'get_resource_issuer_metadata',
            '/mcp/.well-known/openid-configuration'        => 'get_resource_issuer_openid_configuration',
            '/discovery/openid-configuration'              => 'get_openid_configuration',
            '/oauth/jwks'                                  => 'get_jwks',
        ) as $route => $callback ) {
            register_rest_route(
                self::NAMESPACE_V1,
                $route,
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( new Discovery(), $callback ),
                    'permission_callback' => '__return_true',
                )
            );
        }

        register_rest_route(
            self::NAMESPACE_V1,
            '/oauth/register',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( new Client_Registry(), 'handle_register' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/oauth/authorize',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_authorize_redirect' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/oauth/token',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( new Token_Endpoint(), 'handle' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/oauth/device',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( new Device_Authorization(), 'handle_device_request' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/oauth/revoke',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_revoke' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function handle_authorize_redirect( \WP_REST_Request $request ) {
        $url = self::build_authorize_redirect_url( (array) $request->get_query_params() );
        \Easy_MCP_AI\Cache\Page_Cache_Opt_Out::apply_to_direct_response();
        \nocache_headers();
        \wp_safe_redirect( $url, 302 );
        exit;
    }

    public static function build_authorize_redirect_url( array $query_params ) {
        unset( $query_params['rest_route'], $query_params['_locale'] );

        $query_params['easy_mcp_ai_oauth'] = 'authorize';

        return \home_url( '/' ) . '?' . http_build_query( $query_params );
    }

    public function handle_revoke( \WP_REST_Request $request ) {
        $transport_error = Token_Endpoint::enforce_transport_security();
        if ( null !== $transport_error ) {
            return $transport_error;
        }

        $rate_error = Rate_Limit::check( 'revoke', 'easy_mcp_ai_revoke_rl_', 60, 600 );
        if ( null !== $rate_error ) {
            return $rate_error;
        }

        $query_error = Token_Endpoint::reject_query_credentials( $request, array( 'token', 'client_secret' ) );
        if ( null !== $query_error ) {
            return $query_error;
        }
        $token     = Token_Endpoint::body_param( $request, 'token' );
        $hint      = sanitize_text_field( $request->get_param( 'token_type_hint' ) );
        $basic     = Token_Endpoint::read_basic_credentials( $request );
        $client_id = Token_Endpoint::resolve_client_id( $request, $basic );
        if ( $client_id instanceof \WP_REST_Response ) {
            return $client_id;
        }

        if ( '' === $token || '' === $client_id ) {
            $response = new \WP_REST_Response( '', 200 );
            $response->header( 'Content-Length', '0' );
            $response->header( 'Referrer-Policy', 'no-referrer' );
            $response->header( 'X-Content-Type-Options', 'nosniff' );
            $response->header( 'Cache-Control', 'no-store' );
            return $response;
        }

        $client = ( new Client_Registry() )->find_client( $client_id );
        if ( null !== $client ) {
            $auth_error = Token_Endpoint::authenticate_client( $request, $client, $basic );
            if ( null !== $auth_error ) {
                return $auth_error;
            }
        }
        $client_id = Client_Metadata::storage_id( $client_id );

        $token_hash = OAuth_Token_Manager::hash_presented( $token );
        $manager    = new OAuth_Token_Manager();

        $looks_like_access = ( 0 === strpos( $token, 'wpmcp_oat_' ) );

        $revoked = false;
        if ( null !== $token_hash ) {
            $refresh_first = 'refresh_token' === $hint || ! $looks_like_access;
            $revoked       = $refresh_first
                ? $manager->revoke_by_refresh_hash( $token_hash, $client_id )
                : $manager->revoke_token( $token_hash, $client_id );
            if ( false === $revoked ) {
                $revoked = $refresh_first
                    ? $manager->revoke_token( $token_hash, $client_id )
                    : $manager->revoke_by_refresh_hash( $token_hash, $client_id );
            }
        }
        if ( null === $revoked ) {
            $response = new \WP_REST_Response(
                array(
                    'error'             => 'temporarily_unavailable',
                    'error_description' => __( 'The token was not revoked: the database refused the change. Please try again.', 'easy-mcp-ai' ),
                ),
                503
            );
            $response->header( 'Referrer-Policy', 'no-referrer' );
            $response->header( 'X-Content-Type-Options', 'nosniff' );
            $response->header( 'Cache-Control', 'no-store' );
            return $response;
        }

        $response = new \WP_REST_Response( '', 200 );
        $response->header( 'Content-Length', '0' );
        $response->header( 'Referrer-Policy', 'no-referrer' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        $response->header( 'Cache-Control', 'no-store' );

        return $response;
    }
}
