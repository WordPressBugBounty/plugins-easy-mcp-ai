<?php

namespace Easy_MCP_AI\Cloud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cloud_Proof_Route {

    const NAMESPACE_V1  = 'easy-mcp-ai/v1';
    const PATH          = '/cloud-proof';
    const NONCE_PATTERN = '/^[0-9a-f]{32}$/D';

    public function register_routes(): void {
        \register_rest_route(
            self::NAMESPACE_V1,
            self::PATH,
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle' ),
                'permission_callback' => '__return_true',
                'show_in_index'       => false,
            )
        );
    }

    public static function proof_for( string $nonce, string $key ): string {
        return hash_hmac( 'sha256', $nonce, $key );
    }

    public function handle( $request ) {
        $nonce = $request->get_param( 'nonce' );
        if ( ! is_string( $nonce ) || 1 !== preg_match( self::NONCE_PATTERN, $nonce ) ) {
            return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
        }
        $key = Cloud_Credentials::key();
        if ( '' === $key || ! Cloud_Tools::any_enabled() ) {
            return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
        }
        return new \WP_REST_Response( array( 'proof' => self::proof_for( $nonce, $key ) ), 200 );
    }
}
