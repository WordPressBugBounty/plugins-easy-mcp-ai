<?php

namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cache_Window_Counter {

    const GROUP = 'easy_mcp_ai';

    public static function hit( string $key, int $window ): array {
        $now  = time();
        $ends = (int) \wp_cache_get( $key . '_ends', self::GROUP );
        if ( $ends <= $now ) {
            $ends = self::open( $key, $now, $window );
        }
        $count = \wp_cache_incr( $key, 1, self::GROUP );
        if ( false === $count ) {
            \wp_cache_add( $key, 0, self::GROUP, max( 1, $ends - $now ) );
            $count = \wp_cache_incr( $key, 1, self::GROUP );
        }
        return array(
            'count' => (int) $count,
            'ends'  => $ends,
        );
    }

    private static function open( string $key, int $now, int $window ): int {
        $ends = $now + $window;
        if ( ! \wp_cache_add( $key . '_ends', $ends, self::GROUP, $window ) ) {
            $stored = (int) \wp_cache_get( $key . '_ends', self::GROUP, true );
            if ( $stored > $now ) {
                return $stored;
            }
            \wp_cache_set( $key . '_ends', $ends, self::GROUP, $window );
        }
        \wp_cache_set( $key, 0, self::GROUP, $window );
        return $ends;
    }
}
