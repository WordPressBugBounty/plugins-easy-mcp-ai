<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}






















class Client_IP {

    

















    public static function get(): string {
        $remote = isset( $_SERVER['REMOTE_ADDR'] )
            ? trim( \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), '[]' )
            : '';

        











        $filtered = \apply_filters( 'easy_mcp_ai_client_ip', $remote );

        if ( is_string( $filtered ) && '' !== $filtered ) {
            $candidate = trim( $filtered, '[]' );
            if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                return $candidate;
            }
        }

        return '' !== $remote ? $remote : 'unknown';
    }
}
