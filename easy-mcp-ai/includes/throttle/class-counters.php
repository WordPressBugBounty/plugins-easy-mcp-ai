<?php

namespace Easy_MCP_AI\Throttle;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Counters {

    const PREFIX = 'easy_mcp_ai_thr_';

    const GROUP = 'easy_mcp_ai';

    const NETWORK_GROUP = 'easy_mcp_ai_network';

    public static function charge( array $rules, array $caller, int $units ): array {
        $charges = array();
        foreach ( $rules as $rule ) {
            $key    = self::key( $rule, $caller );
            $hit    = self::hit( $key, $rule, $units );
            $charge = array(
                'key'    => $key,
                'rule'   => $rule,
                'window' => $hit['window'],
                'ends'   => $hit['ends'],
                'units'  => $units,
            );
            if ( $hit['count'] > $rule['limit'] ) {
                self::refund( array_merge( $charges, array( $charge ) ) );
                return array(
                    'refusal' => array(
                        'rule'        => $rule,
                        'limit'       => $rule['limit'],
                        'retry_after' => max( 1, $hit['ends'] - time() ),
                        'ends'        => $hit['ends'],
                        'units'       => $units,
                    ),
                    'charges' => array(),
                );
            }
            $charges[] = $charge;
        }
        return array(
            'refusal' => null,
            'charges' => $charges,
        );
    }

    public static function refund( array $charges ): void {
        foreach ( $charges as $charge ) {
            $network = 'user' === $charge['rule']['scope'];
            if ( '' === $charge['window'] ) {
                \Easy_MCP_AI\Fixed_Window_Counter::refund( $charge['key'], $charge['units'], $network, $charge['ends'] );
                continue;
            }
            \wp_cache_decr( $charge['window'], $charge['units'], self::group( $network ) );
        }
    }

    public static function log_once( array $rule, array $caller, int $ends, callable $log ): void {
        $flag    = self::key( $rule, $caller ) . '_logged_' . $ends;
        $ttl     = max( 1, $ends - time() );
        $network = 'user' === $rule['scope'];
        if ( \wp_using_ext_object_cache() ) {
            $group = self::group( $network );
            if ( \wp_cache_add( $flag, 1, $group, $ttl ) && ! $log() ) {
                \wp_cache_delete( $flag, $group );
            }
            return;
        }
        if ( 0 === \Easy_MCP_AI\Fixed_Window_Counter::count( $flag, $network ) && $log() ) {
            \Easy_MCP_AI\Fixed_Window_Counter::hit( $flag, $ttl, 1, $network );
        }
    }

    public static function key( array $rule, array $caller ): string {
        switch ( $rule['scope'] ) {
            case 'user':
                $who = 'u' . $caller['user'];
                break;
            case 'site':
                $who = 's' . $caller['site'];
                break;
            default:
                $who = 'c' . $caller['credential'];
        }
        return self::PREFIX . substr( md5( $rule['entry'] ), 0, 12 ) . '_' . $who;
    }

    private static function hit( string $key, array $rule, int $units ): array {
        $network = 'user' === $rule['scope'];
        if ( ! \wp_using_ext_object_cache() ) {
            $hit = \Easy_MCP_AI\Fixed_Window_Counter::hit( $key, $rule['seconds'], $units, $network );
            return array(
                'count'  => $hit['count'],
                'ends'   => $hit['ends'],
                'window' => '',
            );
        }
        $index  = intdiv( time(), $rule['seconds'] );
        $window = $key . '_' . $index;
        $group  = self::group( $network );
        \wp_cache_add( $window, 0, $group, $rule['seconds'] );
        return array(
            'count'  => (int) \wp_cache_incr( $window, $units, $group ),
            'ends'   => ( $index + 1 ) * $rule['seconds'],
            'window' => $window,
        );
    }

    private static function group( bool $network ): string {
        if ( ! $network ) {
            return self::GROUP;
        }
        \wp_cache_add_global_groups( array( self::NETWORK_GROUP ) );
        return self::NETWORK_GROUP;
    }
}
