<?php

namespace Easy_MCP_AI\Rest_Routes;

use Easy_MCP_AI\MCP\Detailed_Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Wordfence_Routes {

    const PREFIX = '/wordfence/v1';

    const SIGN_IN = '/wordfence/v1/authenticate';

    const TOKEN_REFUSAL = 'rest_forbidden_context';

    const READ_TOOLS = array( 'wp_wordfence_get_scan_issues', 'wp_wordfence_get_live_traffic' );

    public static function refusal( string $method, string $path, \WP_REST_Response $response ): ?Detailed_Tool_Error {
        $route = strtolower( $path );
        if ( ( self::PREFIX !== $route && 0 !== strpos( $route, self::PREFIX . '/' ) ) || self::SIGN_IN === rtrim( $route, '/' ) ) {
            return null;
        }
        $status = (int) $response->get_status();
        $data   = $response->get_data();
        if ( ! in_array( $status, array( 401, 403 ), true ) || ! is_array( $data ) || self::TOKEN_REFUSAL !== ( $data['code'] ?? null ) ) {
            return null;
        }
        $line = sprintf( '%1$s %2$s answered HTTP %3$d: Wordfence\'s REST API accepts only a Wordfence Central token.', $method, $path, $status );
        if ( strlen( $line ) > Detailed_Tool_Error::MAX_MESSAGE ) {
            $line = sprintf( 'Wordfence answered HTTP %d: its REST API accepts only a Wordfence Central token.', $status );
        }
        $said = isset( $data['message'] ) && is_string( $data['message'] ) ? sprintf( ' Wordfence answered %s: %s', self::TOKEN_REFUSAL, \wp_strip_all_tags( $data['message'] ) ) : '';
        return new Detailed_Tool_Error(
            $line,
            'Every wordfence/v1 route but Central\'s own sign-in checks a token signed by Wordfence Central, which no WordPress account or key of this plugin carries, so no call through wp_rest_read, wp_rest_write or wp_rest_delete reaches it.' . self::pointer() . $said
        );
    }

    private static function pointer(): string {
        $tools = array();
        if ( class_exists( '\\Easy_MCP_AI\\MCP\\Server' ) ) {
            foreach ( self::READ_TOOLS as $tool ) {
                if ( true === \Easy_MCP_AI\MCP\Server::current_caller_may_reach( $tool ) ) {
                    $tools[] = $tool;
                }
            }
        }
        if ( array() === $tools ) {
            return ' Scans, firewall settings and blocks are managed in Wordfence\'s own screens in wp-admin.';
        }
        return sprintf( ' Read Wordfence\'s scan results and Live Traffic with %s; scans, firewall settings and blocks are managed in Wordfence\'s own screens in wp-admin.', implode( ' and ', $tools ) );
    }
}
