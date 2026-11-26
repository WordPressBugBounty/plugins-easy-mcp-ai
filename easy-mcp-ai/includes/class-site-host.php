<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Site_Host {

    const SETTING = 'api_key_site_host';

    const FILTER = 'easy_mcp_ai_api_key_site_host';

    public static function current() {
        return self::normalize( self::current_candidate() );
    }

    private static function current_candidate(): string {
        return (string) \apply_filters( self::FILTER, self::binding_input() );
    }

    private static function binding_input(): string {
        $configured = Config::get( self::SETTING );
        return ( is_string( $configured ) && '' !== $configured ) ? $configured : self::site_address();
    }

    private static function site_address(): string {
        $stored = \get_option( 'home' );
        return ( is_string( $stored ) && '' !== $stored ) ? $stored : (string) \home_url();
    }

    private static function turkish_candidates(): array {
        $stored      = \get_option( 'easy_mcp_ai_' . self::SETTING );
        $from_option = is_string( $stored ) ? self::canonical( $stored, true ) : '';
        $old_input   = '' !== $from_option ? $from_option : self::site_address();
        if ( self::normalize( $old_input ) !== self::normalize( self::binding_input() ) ) {
            return array();
        }
        $inputs = '' !== $from_option ? array( $old_input ) : array( $old_input, (string) \home_url() );
        $out    = array();
        foreach ( $inputs as $input ) {
            $out[] = (string) \apply_filters( self::FILTER, $input );
        }
        return $out;
    }

    public static function request_host(): string {
        $candidate = self::request_candidate();
        return null === $candidate ? '' : self::normalize( $candidate );
    }

    private static function request_candidate(): ?string {
        $configured = Config::get( self::SETTING );
        if ( is_string( $configured ) && '' !== $configured ) {
            return null;
        }
        return (string) \apply_filters( self::FILTER, (string) \home_url() );
    }

    public static function normalize( $value ) {
        return self::canonical( $value, false );
    }

    private static function canonical( $value, bool $keep_capital_i ): string {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }
        $to_parse = ( false === strpos( $value, '//' ) ) ? '//' . $value : $value;
        $host     = \wp_parse_url( $to_parse, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            return '';
        }
        $host = rtrim( $host, '.' );
        $host = $keep_capital_i ? strtr( $host, 'ABCDEFGHJKLMNOPQRSTUVWXYZ', 'abcdefghjklmnopqrstuvwxyz' ) : Ascii::lower( $host );
        if ( ! preg_match( '/^(?:\[[0-9a-f:.]+\]|[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*)$/u', $host ) ) {
            return '';
        }
        while ( 0 === strpos( $host, 'www.' ) && strlen( $host ) > 4 ) {
            $host = substr( $host, 4 );
        }
        return $host;
    }

    public static function matches( $host, $current = null ) {
        $host    = self::normalize( (string) $host );
        $current = null === $current ? self::current() : self::normalize( (string) $current );
        return '' !== $host && '' !== $current && $host === $current;
    }

    public static function hash( $host ) {
        $host = self::normalize( (string) $host );
        return '' === $host ? '' : hash( 'sha256', $host );
    }

    public static function current_hash() {
        return self::hash( self::current() );
    }

    public static function matches_hash( $stored_hash, $current = null ) {
        $stored_hash = Ascii::lower( trim( (string) $stored_hash ) );
        if ( ! preg_match( '/^[0-9a-f]{64}$/', $stored_hash ) ) {
            return false;
        }
        if ( null !== $current ) {
            $host_hash = self::hash( $current );
            return '' !== $host_hash && hash_equals( $host_hash, $stored_hash );
        }
        $hashes = array_map( array( self::class, 'hash' ), array_filter( array( self::current_candidate(), self::request_candidate() ), 'is_string' ) );
        foreach ( self::turkish_candidates() as $candidate ) {
            $hashes[] = self::turkish_hash( $candidate );
        }
        foreach ( array_unique( $hashes ) as $host_hash ) {
            if ( '' !== $host_hash && hash_equals( $host_hash, $stored_hash ) ) {
                return true;
            }
        }
        return false;
    }

    private static function turkish_hash( string $candidate ): string {
        $host = self::canonical( $candidate, true );
        return '' === $host ? '' : hash( 'sha256', $host );
    }
}
