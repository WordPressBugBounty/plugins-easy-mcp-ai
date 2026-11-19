<?php

namespace Easy_MCP_AI\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rate_Limit {

    const WINDOW = 3600;

    const CACHE_GROUP = 'easy_mcp_ai';

    public static function check( string $endpoint, string $prefix, int $per_ip, int $site_wide, ?callable $admits_over_site_limit = null ): ?\WP_REST_Response {
        $ip = self::hit( $prefix . md5( \Easy_MCP_AI\Client_IP::get() ) );
        if ( $ip['count'] > $per_ip ) {
            return self::refuse( $endpoint, 'ip', $ip );
        }
        $site = self::hit( $prefix . 'global' );
        if ( $site['count'] > $site_wide && ( null === $admits_over_site_limit || true !== $admits_over_site_limit() ) ) {
            return self::refuse( $endpoint, 'site', $site );
        }
        return null;
    }

    private static function hit( string $key ): array {
        if ( ! \wp_using_ext_object_cache() ) {
            $counted = \Easy_MCP_AI\Fixed_Window_Counter::hit( $key, self::WINDOW );
            return $counted + array( 'flag' => $key . '_logged_' . $counted['ends'] );
        }
        $window = intdiv( time(), self::WINDOW );
        $ends   = ( $window + 1 ) * self::WINDOW;
        $slot   = $key . '_' . $window;
        \wp_cache_add( $slot, 0, self::CACHE_GROUP, max( 1, $ends - time() ) );
        return array(
            'count' => (int) \wp_cache_incr( $slot, 1, self::CACHE_GROUP ),
            'ends'  => $ends,
            'flag'  => $slot . '_logged',
        );
    }

    private static function refuse( string $endpoint, string $limit, array $counter ): \WP_REST_Response {
        $wait     = max( 1, $counter['ends'] - time() );
        $minutes  = (int) ceil( $wait / 60 );
        $message  = 'ip' === $limit
            /* translators: %d: minutes until the limit resets. */
            ? sprintf( _n( 'Too many requests from this address. Try again in %d minute.', 'Too many requests from this address. Try again in %d minutes.', $minutes, 'easy-mcp-ai' ), $minutes )
            /* translators: %d: minutes until the limit resets. */
            : sprintf( _n( 'Too many requests to this site\'s sign-in endpoints. Try again in %d minute.', 'Too many requests to this site\'s sign-in endpoints. Try again in %d minutes.', $minutes, 'easy-mcp-ai' ), $minutes );
        $response = new \WP_REST_Response(
            array(
                'error'             => 'rate_limited',
                'error_description' => Token_Endpoint::wire_safe_description( $message ),
            ),
            429
        );
        $response->header( 'Retry-After', (string) $wait );
        if ( ! self::flag_raised( $counter['flag'] ) && OAuth_Audit_Log::write(
            '_oauth_rate_limit',
            array(
                'endpoint' => $endpoint,
                'limit'    => $limit,
            ),
            'refused'
        ) ) {
            self::raise_flag( $counter['flag'], $wait );
        }
        return $response;
    }

    private static function flag_raised( string $key ): bool {
        if ( \wp_using_ext_object_cache() ) {
            return false !== \wp_cache_get( $key, self::CACHE_GROUP );
        }
        return \Easy_MCP_AI\Fixed_Window_Counter::count( $key ) > 0;
    }

    private static function raise_flag( string $key, int $ttl ): void {
        if ( \wp_using_ext_object_cache() ) {
            \wp_cache_set( $key, 1, self::CACHE_GROUP, $ttl );
            return;
        }
        \set_transient( $key, '1:' . ( time() + $ttl ), $ttl );
    }
}
