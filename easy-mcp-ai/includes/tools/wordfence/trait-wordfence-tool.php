<?php

namespace Easy_MCP_AI\Tools\Wordfence;

use Easy_MCP_AI\Text_Redactor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Wordfence_Tool {

    protected static $max_field_chars = 2000;

    public function get_category() {
        return 'wordfence';
    }

    public function get_required_capability() {
        return is_multisite() ? 'manage_network' : 'manage_options';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    protected static function require_wordfence( array $methods ): void {
        if ( ! class_exists( '\\wordfence' ) || ! class_exists( '\\wfUtils' ) ) {
            throw new \RuntimeException( 'Wordfence is not active on this site. Install and activate the Wordfence Security plugin.' );
        }
        $methods['wfUtils'] = array_merge( $methods['wfUtils'] ?? array(), array( 'isAdmin' ) );
        foreach ( $methods as $class => $names ) {
            foreach ( $names as $name ) {
                if ( ! method_exists( '\\' . $class, $name ) ) {
                    throw new \RuntimeException( sprintf( 'This Wordfence version (%1$s) has no %2$s::%3$s(), which this tool reads. It was measured on Wordfence 7.5.0 to 9.1.1.', defined( 'WORDFENCE_VERSION' ) ? (string) WORDFENCE_VERSION : 'unknown', $class, $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
            }
        }
        if ( ! \wfUtils::isAdmin() ) {
            throw new \RuntimeException( sprintf( 'Wordfence shows this only to accounts with the %s capability, and this account does not have it.', is_multisite() ? 'manage_network' : 'manage_options' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    protected static function bounded_int( array $arguments, string $key, int $default, int $min, int $max ): int {
        if ( ! isset( $arguments[ $key ] ) || '' === $arguments[ $key ] ) {
            return $default;
        }
        $value = $arguments[ $key ];
        if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ) ) {
            throw new \InvalidArgumentException( sprintf( '%s must be a whole number.', $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $value = (int) $value;
        if ( $value < $min || $value > $max ) {
            throw new \InvalidArgumentException( PHP_INT_MAX === $max ? sprintf( '%1$s must be %2$d or more.', $key, $min ) : sprintf( '%1$s must be from %2$d to %3$d.', $key, $min, $max ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $value;
    }

    protected static function iso_time( $timestamp ): ?string {
        if ( ! is_numeric( $timestamp ) || (float) $timestamp <= 0 ) {
            return null;
        }
        return gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp );
    }

    protected static function redact_url( $url ): string {
        return is_string( $url ) ? Text_Redactor::redact( self::mask_query_values( $url ), false ) : '';
    }

    protected static function redact_text( $text ): string {
        return is_scalar( $text ) ? Text_Redactor::redact( self::mask_query_values( (string) $text ) ) : '';
    }

    protected static function redact_data( array $data ): array {
        array_walk_recursive(
            $data,
            static function ( &$leaf ): void {
                if ( is_string( $leaf ) ) {
                    $leaf = self::mask_query_values( $leaf );
                }
            }
        );
        return (array) Text_Redactor::redact_value( $data );
    }

    private static function mask_query_values( string $text ): string {
        $spans = array();
        foreach ( self::query_depths() as $level => $depth ) {
            if ( ! preg_match_all( '/' . $depth['start'] . '((?:' . $depth['name'] . ')+)' . $depth['equals'] . '/i', $text, $found, PREG_OFFSET_CAPTURE ) ) {
                continue;
            }
            foreach ( $found[0] as $i => $match ) {
                if ( ! self::is_secret_parameter( self::decoded_name( $found[1][ $i ][0], $level ) ) ) {
                    continue;
                }
                $from    = $match[1] + strlen( $match[0] );
                $length  = preg_match( '/' . $depth['stop'] . '/i', $text, $stop, PREG_OFFSET_CAPTURE, $from ) ? $stop[0][1] - $from : strlen( $text ) - $from;
                $spans[] = array( $from, $from + $length );
            }
        }
        sort( $spans );
        $merged = array();
        foreach ( $spans as $span ) {
            $last = count( $merged ) - 1;
            if ( $last >= 0 && $span[0] <= $merged[ $last ][1] ) {
                $merged[ $last ][1] = max( $merged[ $last ][1], $span[1] );
            } elseif ( $span[1] > $span[0] ) {
                $merged[] = $span;
            }
        }
        foreach ( array_reverse( $merged ) as $span ) {
            $text = substr_replace( $text, Text_Redactor::MASK, $span[0], $span[1] - $span[0] );
        }
        return $text;
    }

    private static function decoded_name( string $name, int $level ): string {
        for ( $i = 0; $i <= $level; $i++ ) {
            $name = rawurldecode( $name );
        }
        return $name;
    }

    private static function query_depths(): array {
        $raw = '[^=&#?\s"<>%]';
        return array(
            array(
                'start'  => '[?&]',
                'equals' => '=',
                'name'   => '[^=&#?\s"<>]',
                'stop'   => '[&#\s"<>]',
            ),
            array(
                'start'  => '%(?:3f|26)',
                'equals' => '%3d',
                'name'   => $raw . '|%(?!3d|26|3f|23)[0-9a-f]{2}',
                'stop'   => '[&#\s"<>]|%(?:26|23)',
            ),
            array(
                'start'  => '%25(?:3f|26)',
                'equals' => '%253d',
                'name'   => $raw . '|%25(?!3d|26|3f|23)[0-9a-f]{2}|%(?!25|3d|26|3f|23)[0-9a-f]{2}',
                'stop'   => '[&#\s"<>]|%(?:26|23)|%25(?:26|23)',
            ),
        );
    }

    protected static function is_secret_parameter( string $name ): bool {
        foreach ( (array) preg_split( '/[\[\]]+/', $name, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
            $part = \Easy_MCP_AI\Ascii::lower( (string) $part );
            if ( in_array( $part, array( 'code', 'sig', 'otp', 'newuseremail' ), true )
                || 1 === preg_match( '/(?:key|hash)$/', $part )
                || Text_Redactor::is_sensitive_key( $part )
                || 1 === preg_match( '/signature|nonce|session|cookie/', $part ) ) {
                return true;
            }
        }
        return false;
    }

    protected static function capped( string $text, string $field, array &$cut ): string {
        $short = self::cut_to( $text, self::$max_field_chars );
        if ( $short !== $text ) {
            $cut[] = $field;
        }
        return $short;
    }

    protected static function cut_to( string $text, int $limit ): string {
        if ( strlen( $text ) <= $limit ) {
            return $text;
        }
        if ( 1 === preg_match( '/^.{0,' . $limit . '}/su', $text, $m ) ) {
            return $m[0];
        }
        $bytes = substr( $text, 0, $limit );
        if ( 1 === preg_match( '/([\xC0-\xF7])([\x80-\xBF]*)$/', $bytes, $tail ) ) {
            $lead  = ord( $tail[1] );
            $needs = $lead >= 0xF0 ? 3 : ( $lead >= 0xE0 ? 2 : 1 );
            if ( strlen( $tail[2] ) < $needs ) {
                $bytes = substr( $bytes, 0, -strlen( $tail[0] ) );
            }
        }
        return $bytes;
    }

    protected static function capped_data( array $data, string $path, array &$cut ): array {
        foreach ( $data as $key => $value ) {
            if ( is_string( $value ) ) {
                $data[ $key ] = self::capped( $value, $path . '.' . $key, $cut );
            } elseif ( is_array( $value ) ) {
                $data[ $key ] = self::capped_data( $value, $path . '.' . $key, $cut );
            }
        }
        return $data;
    }

    protected static function plain_text( $html ): string {
        if ( ! is_scalar( $html ) ) {
            return '';
        }
        $text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
        return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
    }
}
