<?php

namespace Easy_MCP_AI\Loco;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class String_Check {

    const CONVERSION = '/\G%(?:%|(?:(\d+)\$)?(?:[-+ 0]|\'.)*(\*(?:\d+\$)?|\d+)?(?:\.(\*(?:\d+\$)?|\d*))?l?[bcdeEfFgGhHosuxX%])/s';

    const WIDTH = 100;

    const KINDS = array(
        'b' => 'a whole number',
        'd' => 'a whole number',
        'o' => 'a whole number',
        'u' => 'a whole number',
        'x' => 'a whole number',
        'X' => 'a whole number',
        'c' => 'a character',
        'e' => 'a decimal number',
        'E' => 'a decimal number',
        'f' => 'a decimal number',
        'F' => 'a decimal number',
        'g' => 'a decimal number',
        'G' => 'a decimal number',
        'h' => 'a decimal number',
        'H' => 'a decimal number',
        's' => 'text',
        '%' => 'a percent sign',
    );

    public function refusal( string $source, string $translation, string $format, string $plural_source = '' ): ?string {
        $wants = self::combined( self::conversions( $source ), '' === $plural_source ? null : self::conversions( $plural_source ) );
        if ( 'no-php' !== $format && null !== $wants && ( array() !== $wants['read'] || 'php' === $format ) ) {
            $reason = self::mismatch( $wants, self::conversions( $translation ) );
            if ( null !== $reason ) {
                return sprintf( '%1$s (source: "%2$s"). Keep the source\'s placeholders, and write a literal percent sign as %%%%.', $reason, '' === $plural_source ? $source : $source . '" / "' . $plural_source );
            }
        }
        if ( ! current_user_can( 'unfiltered_html' ) && $this->kses( $translation ) !== $translation ) {
            return 'it holds HTML this account may not publish (WordPress would filter it, and themes print translations unescaped).';
        }
        return null;
    }

    protected function kses( string $text ): string {
        return wp_kses_post( $text );
    }

    public static function conversions( string $text ): ?array {
        $out    = array( 'read' => array(), 'star' => array(), 'kind' => array(), 'width' => 0 );
        $next   = 0;
        $offset = strpos( $text, '%' );
        while ( false !== $offset ) {
            if ( 1 !== preg_match( self::CONVERSION, $text, $match, PREG_UNMATCHED_AS_NULL, $offset ) ) {
                return null;
            }
            if ( '%%' !== $match[0] ) {
                $general = false !== strpos( 'gGhH', substr( $match[0], -1 ) );
                foreach ( array( 'w' => $match[2], 'p' => $match[3] ) as $role => $part ) {
                    if ( null !== $part && '*' === substr( $part, 0, 1 ) ) {
                        $read = self::argument( rtrim( substr( $part, 1 ), '$' ), $next );
                        if ( null === $read ) {
                            return null;
                        }
                        $out['read'][]          = $read;
                        $out['star'][ $read ][] = 'p' === $role && $general ? 'g' : $role;
                    } elseif ( null !== $part ) {
                        $out['width'] = max( $out['width'], (int) $part );
                    }
                }
                $read = self::argument( (string) $match[1], $next );
                if ( null === $read ) {
                    return null;
                }
                $out['read'][] = $read;
                $kind          = self::KINDS[ substr( $match[0], -1 ) ];
                if ( ! in_array( $kind, $out['kind'][ $read ] ?? array(), true ) ) {
                    $out['kind'][ $read ][] = $kind;
                }
            }
            $offset = strpos( $text, '%', $offset + strlen( $match[0] ) );
        }
        $out['read'] = array_values( array_unique( $out['read'] ) );
        return $out;
    }

    private static function combined( ?array $one, ?array $other ): ?array {
        if ( null === $one || null === $other ) {
            return $one ?? $other;
        }
        $star = $one['star'];
        foreach ( $other['star'] as $read => $roles ) {
            $star[ $read ] = array_merge( $star[ $read ] ?? array(), $roles );
        }
        $kind = $one['kind'];
        foreach ( $other['kind'] as $read => $kinds ) {
            $kind[ $read ] = array_values( array_unique( array_merge( $kind[ $read ] ?? array(), $kinds ) ) );
        }
        return array(
            'read'  => array_values( array_unique( array_merge( $one['read'], $other['read'] ) ) ),
            'star'  => $star,
            'kind'  => $kind,
            'width' => max( $one['width'], $other['width'] ),
        );
    }

    private static function mismatch( array $source, ?array $translation ): ?string {
        if ( null === $translation ) {
            return 'it holds a % sequence PHP cannot format (a lone %, an unknown conversion or argument 0), so PHP would stop with a fatal error where the string prints';
        }
        $most = array() === $source['read'] ? 0 : max( $source['read'] );
        foreach ( $translation['read'] as $read ) {
            if ( $read > $most ) {
                return sprintf( 'it reads argument %1$d, and the source is given %2$d, so PHP would stop with a fatal error where the string prints', $read, $most );
            }
            if ( ! in_array( $read, $source['read'], true ) ) {
                return sprintf( 'it reads argument %d, which the source never shows, so the page would print a value the source does not', $read );
            }
        }
        foreach ( $translation['kind'] as $read => $kinds ) {
            $given = $source['kind'][ $read ] ?? array();
            $other = array_diff( $kinds, $given, array( self::KINDS['%'] ) );
            if ( array() !== $given && array() !== $other ) {
                return sprintf( 'it formats argument %1$d as %2$s, and the source formats it as %3$s, so the page would print that value wrongly', $read, reset( $other ), implode( ' or ', $given ) );
            }
        }
        foreach ( $translation['star'] as $read => $roles ) {
            $given = $source['star'][ $read ] ?? array();
            $needs = array() !== array_diff( $roles, array( 'g' ) ) ? array( 'w', 'p' ) : array( 'w', 'p', 'g' );
            if ( array() === array_intersect( $given, $needs ) ) {
                return sprintf( 'it reads argument %d as a * width or precision, which the source does not read as one that cannot be negative (it may be given text, or -1), so PHP may stop with a fatal error where the string prints', $read );
            }
        }
        if ( $translation['width'] > max( $source['width'], self::WIDTH ) ) {
            return sprintf( 'its width or precision %d is longer than any the source uses, which can exhaust memory where the string prints', $translation['width'] );
        }
        return null;
    }

    private static function argument( string $number, int &$next ): ?int {
        if ( '' === $number ) {
            return ++$next;
        }
        return 0 === (int) $number ? null : (int) $number;
    }
}
