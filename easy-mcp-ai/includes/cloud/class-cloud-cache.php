<?php

namespace Easy_MCP_AI\Cloud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cloud_Cache {

    const PREFIX = 'easy_mcp_ai_cloud_cache_';

    public static function key( string $route, array $args ): string {
        return self::PREFIX . md5( EASY_MCP_AI_VERSION . $route . \wp_json_encode( (object) $args ) );
    }

    public static function get( string $route, array $args ): ?array {
        $cached = \get_transient( self::key( $route, $args ) );
        return is_array( $cached ) && self::has_shape( $route, $args, $cached ) ? $cached : null;
    }

    private static function has_shape( string $route, array $args, array $cached ): bool {
        if ( '/schema' === $route && 'list' === ( $args['action'] ?? null ) ) {
            return isset( $cached['blocks'] ) && is_array( $cached['blocks'] );
        }
        return in_array( $route, array( '/schema', '/atomic-wind-context' ), true ) && isset( $cached['schema'], $cached['usageRules'] ) && is_string( $cached['schema'] ) && is_string( $cached['usageRules'] );
    }

    public static function put( string $route, array $args, array $answer ): void {
        \set_transient( self::key( $route, $args ), $answer, DAY_IN_SECONDS );
    }
}
