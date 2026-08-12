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

    













    public static function is_private_or_reserved( string $ip ): bool {
        if ( '' === $ip ) {
            return false;
        }

        $ip = self::unwrap_v4_mapped( $ip );

        if ( in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
            return true;
        }
        return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    


















    private static function unwrap_v4_mapped( string $ip ): string {
        if ( 0 !== stripos( $ip, '::ffff:' ) ) {
            return $ip;
        }

        $candidate = substr( $ip, 7 );

        
        
        
        return ( false !== filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) )
            ? $candidate
            : $ip;
    }

    















    public static function describe( string $ip ): string {
        if ( '' === $ip ) {
            return 'Unknown';
        }
        
        
        if ( in_array( self::unwrap_v4_mapped( $ip ), array( '127.0.0.1', '::1' ), true ) ) {
            return 'Loopback (site is behind a same-host proxy)';
        }
        if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return 'Not a valid IP address';
        }
        if ( self::is_private_or_reserved( $ip ) ) {
            return 'Private/reserved range (site is behind a proxy or load balancer)';
        }
        return 'Public address';
    }
}
