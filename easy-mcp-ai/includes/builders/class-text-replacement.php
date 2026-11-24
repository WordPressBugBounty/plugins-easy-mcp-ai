<?php

namespace Easy_MCP_AI\Builders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Text_Replacement {

    const PASSAGE_CONTEXT = 40;

    const MAX_PASSAGES = 3;

    const NEAR_SEARCH_MAX = 500;

    const NEAR_WORK_MAX = 20000000;

    const NEAR_QUOTE_MAX = 300;

    const NEAR_GAP = '(?:(?:[\\s\\x{00A0}]|&nbsp;|&#0*160;|&#x0*a0;)++|<[^<>]*+>)+';

    const NEAR_CHARS = array(
        '&'  => '(?:&|&amp;|&#0*38;|&#x0*26;)',
        '<'  => '(?:<|&lt;|&#0*60;|&#x0*3c;)',
        '>'  => '(?:>|&gt;|&#0*62;|&#x0*3e;)',
        '"'  => '(?:"|&quot;|&#0*34;|&#x0*22;|\\x{201C}|\\x{201D}|&ldquo;|&rdquo;|&#0*822[01];|&#x0*201[cd];)',
        "'"  => "(?:'|&#0*39;|&#x0*27;|&apos;|\\x{2018}|\\x{2019}|&lsquo;|&rsquo;|&#0*821[67];|&#x0*201[89];)",
    );

    public static function arguments( array $op ) {
        if ( ! isset( $op['search'] ) || ! is_string( $op['search'] ) || '' === $op['search'] ) {
            return 'replace_text needs `search`, the exact text to find as stored (HTML included), not empty.';
        }
        if ( ! isset( $op['replace'] ) || ! is_string( $op['replace'] ) ) {
            return 'replace_text needs `replace`, a string ("" removes the text).';
        }
        $expected = self::expected_count( $op );
        if ( is_string( $expected ) ) {
            return $expected;
        }
        return array(
            'search'   => $op['search'],
            'replace'  => $op['replace'],
            'expected' => $expected,
        );
    }

    public static function holder( array $values, string $search, string $where ) {
        $holders = array();
        foreach ( $values as $field => $stored ) {
            $count = substr_count( $stored, $search );
            if ( $count > 0 ) {
                $holders[ $field ] = sprintf( '"%s" (%d)', $field, $count );
            }
        }
        if ( array() === $holders ) {
            return self::not_found( $search, $values, $where );
        }
        if ( count( $holders ) > 1 ) {
            return sprintf( '`search` is in more than one field of %s: %s. Give `field`, one of them.', $where, implode( ', ', $holders ) );
        }
        return array( 'field' => (string) array_key_first( $holders ) );
    }

    public static function replaced( string $stored, array $args, string $field, string $where ) {
        $count = substr_count( $stored, $args['search'] );
        if ( 0 === $count ) {
            return self::not_found( $args['search'], array( $field => $stored ), $where );
        }
        if ( $count !== $args['expected'] ) {
            $quoted = array();
            foreach ( self::offsets( $stored, $args['search'], self::MAX_PASSAGES ) as $offset ) {
                $quoted[] = self::json( self::passage( $stored, $offset, strlen( $args['search'] ) ) );
            }
            return sprintf(
                '`search` occurs %1$d %2$s in field "%3$s" of %4$s, and `expected_count` is %5$d, so nothing is replaced. Make `search` longer so it matches the one place meant, or send `expected_count: %1$d` to replace every one. Where it occurs: %6$s.',
                $count,
                1 === $count ? 'time' : 'times',
                $field,
                $where,
                $args['expected'],
                implode( '; ', $quoted )
            );
        }
        return array( 'value' => str_replace( $args['search'], $args['replace'], $stored ) );
    }

    public static function result( string $stored, string $planned, string $search, string $replace ): array {
        $count = substr_count( $stored, $search );
        if ( str_replace( $search, $replace, $stored ) !== $planned ) {
            return array( 'replacements' => $count );
        }
        $shift    = strlen( $replace ) - strlen( $search );
        $passages = array();
        foreach ( self::offsets( $stored, $search, self::MAX_PASSAGES ) as $index => $offset ) {
            $passages[] = array(
                'before' => self::passage( $stored, $offset, strlen( $search ) ),
                'after'  => self::passage( $planned, $offset + $index * $shift, strlen( $replace ) ),
            );
        }
        return array(
            'replacements' => $count,
            'passages'     => $passages,
        );
    }

    public static function shown( array $results, array $planned ): array {
        foreach ( $results as $index => $result ) {
            if ( 'replace_text' !== $result['op'] || ! isset( $result['passages'] ) || ! is_array( $result['passages'] ) ) {
                continue;
            }
            $results[ $index ]['before'] = array_column( $result['passages'], 'before' );
            if ( $result['after'] === ( $planned[ $index ]['after'] ?? null ) ) {
                $results[ $index ]['after'] = array_column( $result['passages'], 'after' );
            }
            unset( $results[ $index ]['passages'] );
        }
        return $results;
    }

    private static function expected_count( array $op ) {
        if ( ! isset( $op['expected_count'] ) ) {
            return 1;
        }
        $value = $op['expected_count'];
        if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 1 ) {
            return (int) $value;
        }
        return '`expected_count` must be a whole number from 1: how many times `search` occurs in the field, every one of them replaced.';
    }

    private static function not_found( string $search, array $values, string $where ): string {
        $names = array();
        foreach ( array_keys( $values ) as $field ) {
            $names[] = '"' . $field . '"';
        }
        $refusal = sprintf( '`search` is not in %s %s of %s exactly as sent, so nothing is replaced.', 1 === count( $names ) ? 'field' : 'fields', implode( ', ', $names ), $where );
        $near    = array();
        $pattern = self::near_pattern( $search );
        foreach ( null === $pattern ? array() : $values as $field => $stored ) {
            if ( '' === $stored || strlen( $stored ) * $pattern['bytes'] > self::NEAR_WORK_MAX ) {
                continue;
            }
            $at = 0;
            while ( count( $near ) < self::MAX_PASSAGES && 1 === preg_match( $pattern['pattern'], $stored, $match, PREG_OFFSET_CAPTURE, $at ) ) {
                $near[] = sprintf( 'field "%s": %s', $field, self::near_quote( $match[0][0] ) );
                $at     = $match[0][1] + strlen( $match[0][0] );
            }
        }
        if ( array() !== $near ) {
            return sprintf( '%s Stored text that nearly matches, quoted as JSON exactly as stored (send it as `search` if it is the text meant): %s.', $refusal, implode( '; ', $near ) );
        }
        return sprintf( '%s Read the stored value with wp_get_page_layout (`element` and `include_settings: true`) and send `search` exactly as stored, HTML included.', $refusal );
    }

    private static function near_pattern( string $search ): ?array {
        $text = html_entity_decode( $search, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = str_replace( array( "\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}" ), array( '"', '"', "'", "'" ), $text );
        if ( strlen( $text ) > self::NEAR_SEARCH_MAX ) {
            return null;
        }
        $words = preg_split( '/[\s\x{00A0}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $words ) || array() === $words ) {
            return null;
        }
        $parts = array();
        foreach ( $words as $word ) {
            $chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
            if ( ! is_array( $chars ) ) {
                return null;
            }
            $part = '';
            foreach ( $chars as $char ) {
                $part .= self::NEAR_CHARS[ $char ] ?? preg_quote( $char, '/' );
            }
            $parts[] = $part;
        }
        return array(
            'pattern' => '/' . implode( self::NEAR_GAP, $parts ) . '/iu',
            'bytes'   => strlen( $text ),
        );
    }

    private static function offsets( string $text, string $search, int $limit ): array {
        $offsets = array();
        $at      = strpos( $text, $search );
        while ( false !== $at && count( $offsets ) < $limit ) {
            $offsets[] = $at;
            $at        = strpos( $text, $search, $at + strlen( $search ) );
        }
        return $offsets;
    }

    private static function passage( string $text, int $offset, int $length ): string {
        $start = self::char_start( $text, max( 0, $offset - self::PASSAGE_CONTEXT ) );
        $end   = self::char_start( $text, min( strlen( $text ), $offset + $length + self::PASSAGE_CONTEXT ) );
        return ( $start > 0 ? '…' : '' ) . substr( $text, $start, $end - $start ) . ( $end < strlen( $text ) ? '…' : '' );
    }

    private static function near_quote( string $text ): string {
        if ( strlen( $text ) <= self::NEAR_QUOTE_MAX ) {
            return self::json( $text );
        }
        $cut = substr( $text, 0, self::char_start( $text, self::NEAR_QUOTE_MAX ) );
        return sprintf( '%s (its first %d bytes of %d; read the rest with wp_get_page_layout)', self::json( $cut ), strlen( $cut ), strlen( $text ) );
    }

    private static function char_start( string $text, int $at ): int {
        while ( $at > 0 && $at < strlen( $text ) && 0x80 === ( ord( $text[ $at ] ) & 0xC0 ) ) {
            --$at;
        }
        return $at;
    }

    private static function json( string $text ): string {
        return (string) wp_json_encode( $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }
}
