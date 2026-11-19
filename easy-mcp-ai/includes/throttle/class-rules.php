<?php

namespace Easy_MCP_AI\Throttle;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rules {

    const OVERRIDE_NAME = 'EASY_MCP_AI_THROTTLES';

    const MAX_ENTRIES = 20;

    const WINDOWS = array(
        'minute' => 60,
        'hour'   => 3600,
        'day'    => 86400,
    );

    const SCOPES = array( 'user', 'site', 'credential' );

    const KIND_ALL = 'all';

    public static function configured(): array {
        return self::read()['rules'];
    }

    public static function ignored(): array {
        return self::read()['ignored'];
    }

    public static function request_rules( array $rules ): array {
        return array_values(
            array_filter(
                $rules,
                static function ( array $rule ): bool {
                    return self::KIND_ALL === $rule['kind'];
                }
            )
        );
    }

    public static function call_rules( array $rules, string $tool_name, string $category, bool $read_only, bool $composite ): array {
        $matched = array();
        foreach ( $rules as $rule ) {
            if ( self::matches( $rule, $tool_name, $category, $read_only, $composite ) ) {
                $matched[] = $rule;
            }
        }
        return $matched;
    }

    public static function replaces_default( array $charged ): bool {
        return array() !== self::in_window( $charged, true );
    }

    public static function request_limit_replacement( array $rules ): string {
        foreach ( self::readable( self::request_rules( $rules ) ) as $rule ) {
            if ( 'minute' === $rule['window'] ) {
                return $rule['entry'];
            }
        }
        return '';
    }

    public static function in_window( array $rules, bool $per_minute ): array {
        return array_values(
            array_filter(
                self::readable( $rules ),
                static function ( array $rule ) use ( $per_minute ): bool {
                    return ( 'minute' === $rule['window'] ) === $per_minute;
                }
            )
        );
    }

    public static function readable( array $rules ): array {
        return array_values(
            array_filter(
                $rules,
                static function ( array $rule ): bool {
                    return $rule['readable'];
                }
            )
        );
    }

    public static function subject( array $rule ): string {
        switch ( $rule['kind'] ) {
            case self::KIND_ALL:
                return 'requests';
            case 'pattern':
                return 'tools matching ' . $rule['match'];
            case 'category':
                return $rule['match'] . ' tools';
            case 'writes':
                return 'writes';
            case 'reads':
                return 'reads';
            default:
                return $rule['match'];
        }
    }

    public static function parse( $raw ): array {
        $rules   = array();
        $ignored = array();
        $seen    = array();
        $count   = 0;
        foreach ( self::entries( $raw ) as $entry ) {
            if ( ! is_string( $entry ) ) {
                $ignored[] = '(a value that is not text)';
                continue;
            }
            $entry = trim( $entry );
            if ( '' === $entry ) {
                continue;
            }
            ++$count;
            $rule = self::parse_entry( $entry );
            if ( null === $rule ) {
                $ignored[] = $entry;
                continue;
            }
            if ( $count > self::MAX_ENTRIES ) {
                $rule['readable'] = false;
            }
            if ( isset( $seen[ $rule['entry'] ] ) ) {
                continue;
            }
            $seen[ $rule['entry'] ] = true;
            $rules[]                = $rule;
        }
        return array(
            'rules'   => $rules,
            'ignored' => $ignored,
        );
    }

    private static function read(): array {
        $entries = self::entries( self::raw() );
        $filtered = \apply_filters( 'easy_mcp_ai_throttles', $entries );
        return self::parse( is_array( $filtered ) ? $filtered : array() );
    }

    private static function raw() {
        if ( defined( self::OVERRIDE_NAME ) ) {
            return constant( self::OVERRIDE_NAME );
        }
        $env = getenv( self::OVERRIDE_NAME );
        return false === $env ? null : $env;
    }

    private static function entries( $raw ): array {
        if ( is_string( $raw ) ) {
            return (array) preg_split( '/[,\r\n]+/', $raw );
        }
        return is_array( $raw ) ? array_values( $raw ) : array();
    }

    private static function parse_entry( string $entry ): ?array {
        $parts    = explode( '=', $entry, 2 );
        $selector = (string) preg_replace( '/^category\s*:\s*/', 'category:', strtolower( trim( $parts[0] ) ) );
        $kind     = self::kind_of( $selector );
        if ( null === $kind ) {
            return null;
        }
        $rule = array(
            'entry'    => $entry,
            'kind'     => $kind,
            'match'    => 'category' === $kind ? substr( $selector, strlen( 'category:' ) ) : $selector,
            'limit'    => 0,
            'window'   => '',
            'seconds'  => 0,
            'scope'    => '',
            'readable' => false,
        );
        $value = isset( $parts[1] ) ? array_map( 'trim', explode( '/', strtolower( $parts[1] ) ) ) : array();
        if ( 3 !== count( $value ) || ! ctype_digit( $value[0] ) || ! isset( self::WINDOWS[ $value[1] ] ) || ! in_array( $value[2], self::SCOPES, true ) ) {
            return $rule;
        }
        $limit = (int) $value[0];
        if ( $limit < 1 ) {
            return $rule;
        }
        $rule['entry']    = $selector . '=' . $limit . '/' . $value[1] . '/' . $value[2];
        $rule['limit']    = $limit;
        $rule['window']   = $value[1];
        $rule['seconds']  = self::WINDOWS[ $value[1] ];
        $rule['scope']    = $value[2];
        $rule['readable'] = true;
        return $rule;
    }

    private static function kind_of( string $selector ): ?string {
        if ( '*' === $selector ) {
            return self::KIND_ALL;
        }
        if ( 'writes' === $selector || 'reads' === $selector ) {
            return $selector;
        }
        if ( 0 === strpos( $selector, 'category:' ) ) {
            return 1 === preg_match( '/^category:[a-z0-9_\-]+$/', $selector ) ? 'category' : null;
        }
        if ( 1 !== preg_match( '/^[a-z0-9_\-*?\[\]!^]+$/', (string) preg_replace( '/\[:[a-z]+:\]/', '', $selector ) ) ) {
            return null;
        }
        return 1 === preg_match( '/[*?\[]/', $selector ) ? 'pattern' : 'name';
    }

    private static function matches( array $rule, string $tool_name, string $category, bool $read_only, bool $composite ): bool {
        switch ( $rule['kind'] ) {
            case 'name':
                return $rule['match'] === $tool_name;
            case 'pattern':
                return fnmatch( $rule['match'], $tool_name );
            case 'category':
                return $rule['match'] === $category;
            case 'writes':
                return ! $composite && ! $read_only;
            case 'reads':
                return ! $composite && $read_only;
            default:
                return false;
        }
    }
}
