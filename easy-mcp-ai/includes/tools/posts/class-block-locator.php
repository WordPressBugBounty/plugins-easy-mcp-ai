<?php
namespace Easy_MCP_AI\Tools\Posts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Block_Locator {

    const IN_HTML = 'html';

    const IN_DELIMITER = 'delimiter';

    const IN_BETWEEN = 'between';

    public static function locate( string $content, array $offsets ): ?array {
        if ( 'WP_Block_Parser' !== apply_filters( 'block_parser_class', 'WP_Block_Parser' ) ) {
            return null;
        }
        $offsets = array_values( array_unique( $offsets ) );
        sort( $offsets );

        $located = array();
        $next    = 0;
        $stack  = array();
        $root   = 0;
        $end    = 0;
        $length = strlen( $content );
        while ( true ) {
            $found = preg_match( Block_Structure_Guard::DELIMITER, $content, $match, PREG_OFFSET_CAPTURE, $end );
            if ( false === $found ) {
                return null;
            }
            $start = 1 === $found ? $match[0][1] : $length + 1;
            self::between( $content, $end, $start, $stack, $root, $offsets, $next, $located );
            if ( 1 !== $found ) {
                break;
            }
            $end = $start + strlen( $match[0][0] );
            if ( isset( $match['void'] ) && '' !== $match['void'][0] ) {
                $place = self::open( $stack, $root );
                self::assign( $offsets, $next, $end, $stack, $place, self::IN_DELIMITER, $located );
                continue;
            }
            if ( '' !== $match['closer'][0] ) {
                if ( array() === $stack ) {
                    return null;
                }
                self::assign( $offsets, $next, $end, $stack, null, self::IN_DELIMITER, $located );
                array_pop( $stack );
                continue;
            }
            $place   = self::open( $stack, $root );
            $stack[] = array(
                'place'    => $place,
                'children' => 0,
            );
            self::assign( $offsets, $next, $end, $stack, null, self::IN_DELIMITER, $located );
        }
        return array() === $stack ? $located : null;
    }

    private static function between( string $content, int $from, int $to, array $stack, int &$root, array $offsets, int &$next, array &$located ): void {
        if ( array() !== $stack ) {
            self::assign( $offsets, $next, $to, $stack, null, self::IN_HTML, $located );
            return;
        }
        $span = min( $to, strlen( $content ) ) - $from;
        if ( $span > 0 && strspn( $content, " \t\n\r\0\x0B", $from, $span ) < $span ) {
            self::assign( $offsets, $next, $to, array(), $root, self::IN_HTML, $located );
            ++$root;
            return;
        }
        self::assign( $offsets, $next, $to, null, null, self::IN_BETWEEN, $located );
    }

    private static function open( array &$stack, int &$root ): int {
        if ( array() === $stack ) {
            return $root++;
        }
        $parent = count( $stack ) - 1;
        return $stack[ $parent ]['children']++;
    }

    private static function assign( array $offsets, int &$next, int $to, ?array $stack, ?int $place, string $in, array &$located ): void {
        $count = count( $offsets );
        if ( $next >= $count || $offsets[ $next ] >= $to ) {
            return;
        }
        $path = null;
        if ( null !== $stack ) {
            $places = array_column( $stack, 'place' );
            if ( null !== $place ) {
                $places[] = $place;
            }
            $path = implode( '.', $places );
        }
        while ( $next < $count && $offsets[ $next ] < $to ) {
            $located[ $offsets[ $next ] ] = array( 'path' => $path, 'in' => $in );
            ++$next;
        }
    }
}
