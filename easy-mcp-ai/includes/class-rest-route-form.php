<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rest_Route_Form {

    public static function of( string $rest_url, string $path ): string {
        $path   = ltrim( $path, '/' );
        $suffix = '/' . ltrim( \rest_get_url_prefix(), '/' ) . '/' . $path;
        $at = strrpos( $rest_url, $suffix );
        if ( false === $at || false !== strpos( $rest_url, '?' ) ) {
            return '';
        }
        $base = rtrim( substr( $rest_url, 0, $at ), '/' );
        $base = '/index.php' === substr( $base, -10 ) ? $base : $base . '/index.php';
        return \esc_url_raw( \add_query_arg( array( 'rest_route' => '/' . $path ), $base ) );
    }
}
