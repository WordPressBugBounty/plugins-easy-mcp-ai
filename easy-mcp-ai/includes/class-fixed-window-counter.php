<?php

namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Fixed_Window_Counter {

    public static function count( string $key, bool $network = false ): int {
        $window = self::read( $key, $network );
        return null === $window ? 0 : $window['count'];
    }

    public static function hit( string $key, int $window, int $units = 1, bool $network = false ): array {
        $now    = time();
        $count  = $units;
        $ends   = $now + $window;
        $stored = self::read( $key, $network );
        if ( null !== $stored ) {
            $count = $stored['count'] + $units;
            $ends  = $stored['ends'];
        }
        self::write( $key, $count, $ends, $network );
        return array(
            'count' => $count,
            'ends'  => $ends,
        );
    }

    public static function refund( string $key, int $units, bool $network = false, ?int $ends = null ): void {
        $stored = self::read( $key, $network );
        if ( null !== $stored && ( null === $ends || $stored['ends'] === $ends ) ) {
            self::write( $key, max( 0, $stored['count'] - $units ), $stored['ends'], $network );
        }
    }

    private static function read( string $key, bool $network ): ?array {
        $packed = $network ? get_site_transient( $key ) : get_transient( $key );
        if ( ! is_string( $packed ) || false === strpos( $packed, ':' ) ) {
            return null;
        }
        list( $count, $ends ) = explode( ':', $packed, 2 );
        if ( (int) $ends <= time() ) {
            return null;
        }
        return array(
            'count' => (int) $count,
            'ends'  => (int) $ends,
        );
    }

    private static function write( string $key, int $count, int $ends, bool $network ): void {
        $ttl = max( 1, $ends - time() );
        if ( $network ) {
            set_site_transient( $key, $count . ':' . $ends, $ttl );
            return;
        }
        set_transient( $key, $count . ':' . $ends, $ttl );
    }
}
