<?php
namespace Easy_MCP_AI\Tools\Search;

use Easy_MCP_AI\Privileged\Search_Replace\Replacer;
use Easy_MCP_AI\Text_Redactor;
use Easy_MCP_AI\Tools\Posts\Text_Matcher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Site_Finder {

    const MAX_PATH_KEY = 100;

    private $forms = array();

    private $needles = array();

    private static $fold_classes;

    const LAST_CASED_CODE_POINT = 0x1E943;

    private $literal;

    private $regex;

    public function __construct( array $queries, bool $regex, bool $case_sensitive ) {
        $this->regex   = $regex;
        $this->literal = ! $regex && $case_sensitive;
        foreach ( $queries as $query ) {
            if ( $regex ) {
                $this->forms[] = array( 'raw' => Text_Matcher::pattern( $query, $case_sensitive ? '' : 'i' ) );
                continue;
            }
            $forms = array();
            foreach ( self::variants( $query ) as $form => $text ) {
                if ( ! $case_sensitive && 'json' === $form ) {
                    list( $forms['json'], $needles ) = self::loose_json( $query );
                    $this->needles                   = array_merge( $this->needles, $needles );
                    continue;
                }
                $forms[ $form ]  = $case_sensitive ? $text : self::loose( $text );
                $this->needles[] = $text;
            }
            $this->forms[] = $forms;
        }
        $this->needles = array_values( array_unique( $this->needles ) );
    }

    public static function variants( string $query ): array {
        $out = array();
        foreach ( array(
            'raw'        => $query,
            'json'       => Replacer::json_inner( $query ),
            'block_attr' => self::block_attr_inner( $query ),
            'html'       => htmlspecialchars( $query, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false ),
        ) as $form => $text ) {
            if ( '' !== $text && ! in_array( $text, $out, true ) ) {
                $out[ $form ] = $text;
            }
        }
        return $out;
    }

    private static function block_attr_inner( string $value ): string {
        $encoded = serialize_block_attributes( array( 'a' => $value ) );
        $prefix  = '{"a":"';
        if ( ! is_string( $encoded ) || 0 !== strpos( $encoded, $prefix ) || '"}' !== substr( $encoded, -2 ) ) {
            return '';
        }
        return substr( $encoded, strlen( $prefix ), -2 );
    }

    private static function loose( string $text ): string {
        return '/' . preg_quote( $text, '/' ) . '/iu';
    }

    private static function loose_json( string $query ): array {
        $chars = preg_split( '//u', $query, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $chars ) ) {
            $text = Replacer::json_inner( $query );
            return array( self::loose( $text ), array( $text ) );
        }
        $pattern = '';
        $pieces  = array( '' );
        $first   = array();
        foreach ( $chars as $char ) {
            $escapes = array();
            foreach ( self::fold_class( $char ) as $case ) {
                $escape = Replacer::json_inner( $case );
                if ( 0 === strpos( $escape, '\\u' ) && ! in_array( $escape, $escapes, true ) ) {
                    $escapes[] = $escape;
                }
            }
            if ( count( $escapes ) > 1 ) {
                $pattern .= '(?:' . implode( '|', array_map( array( __CLASS__, 'quote' ), $escapes ) ) . ')';
                $pieces[] = '';
                $first    = array() === $first ? $escapes : $first;
                continue;
            }
            $json                          = Replacer::json_inner( $char );
            $pattern                      .= self::quote( $json );
            $pieces[ count( $pieces ) - 1 ] .= $json;
        }
        $needles = array_values( array_filter( $pieces, 'strlen' ) );
        return array( '/' . $pattern . '/i', array() !== $needles ? $needles : $first );
    }

    private static function fold_class( string $char ): array {
        if ( ! function_exists( 'mb_convert_case' ) || ! function_exists( 'mb_chr' ) ) {
            return array( $char );
        }
        if ( null === self::$fold_classes ) {
            self::$fold_classes = array();
            for ( $code = 0x80; $code <= self::LAST_CASED_CODE_POINT; $code++ ) {
                if ( $code >= 0xD800 && $code <= 0xDFFF ) {
                    continue;
                }
                $letter = mb_chr( $code, 'UTF-8' );
                if ( false === $letter ) {
                    continue;
                }
                $fold = mb_convert_case( $letter, MB_CASE_FOLD_SIMPLE, 'UTF-8' );
                if ( $fold !== $letter ) {
                    self::$fold_classes[ $fold ][] = $letter;
                }
            }
        }
        $fold  = mb_convert_case( $char, MB_CASE_FOLD_SIMPLE, 'UTF-8' );
        $class = array_merge( array( $char, $fold ), self::$fold_classes[ $fold ] ?? array() );
        return array_values( array_unique( $class ) );
    }

    private static function quote( string $text ): string {
        return preg_quote( $text, '/' );
    }

    public function needles(): array {
        return $this->needles;
    }

    public function find( string $value, bool $decode, ?int $context, bool $redact ): array {
        if ( '' === $value ) {
            return array();
        }
        $read = $decode ? Stored_Value::read( $value ) : array(
            'strings'  => array(
                array(
                    'text' => $value,
                    'path' => null,
                ),
            ),
            'searched' => $value,
        );
        if ( null === $read || ( ! $this->regex && ! $this->shortlisted( $read['searched'] ) ) ) {
            return array();
        }
        $out = array();
        foreach ( $read['strings'] as $string ) {
            foreach ( $this->forms as $i => $forms ) {
                $matches = array();
                foreach ( $forms as $form => $needle ) {
                    $match = $this->match( $string['text'], $needle, $context, $redact );
                    if ( null !== $match ) {
                        $matches[ $form ] = $match;
                    }
                }
                if ( count( $matches ) > 1 ) {
                    $matches = $this->without_overlaps( $string['text'], $forms, $matches, $context, $redact );
                }
                foreach ( $matches as $form => $match ) {
                    $entry = array( 'query' => $i );
                    if ( null !== $string['path'] ) {
                        $entry['path'] = array_map( array( __CLASS__, 'path_key' ), $string['path'] );
                    }
                    $out[] = $entry + array( 'form' => $form ) + $match;
                }
            }
        }
        return $out;
    }

    private function without_overlaps( string $text, array $forms, array $matches, ?int $context, bool $redact ): array {
        $left = $text;
        $out  = array();
        foreach ( array_reverse( $matches, true ) as $form => $match ) {
            $claim = is_int( $match['count'] ) ? $this->claim( $left, $forms[ $form ] ) : null;
            if ( null === $claim ) {
                $out[ $form ] = $match;
                continue;
            }
            if ( 0 === $claim['count'] ) {
                continue;
            }
            $out[ $form ] = array( 'count' => $claim['count'] );
            if ( null !== $context ) {
                $out[ $form ]['snippet'] = self::snippet( $text, $claim['at'], $claim['length'], $context, $redact );
            }
        }
        return array_reverse( $out, true );
    }

    private function claim( string &$text, string $needle ): ?array {
        if ( $this->literal ) {
            $at     = strpos( $text, $needle );
            $length = strlen( $needle );
            $text   = str_replace( $needle, str_repeat( "\0", $length ), $text, $count );
            return array(
                'count'  => $count,
                'at'     => (int) $at,
                'length' => $length,
            );
        }
        $found = preg_match( $needle, $text, $first, PREG_OFFSET_CAPTURE );
        if ( 1 !== $found ) {
            return 0 === $found ? array(
                'count'  => 0,
                'at'     => 0,
                'length' => 0,
            ) : null;
        }
        $blanked = preg_replace_callback(
            $needle,
            static function ( array $match ): string {
                return str_repeat( "\0", strlen( $match[0] ) );
            },
            $text,
            -1,
            $count
        );
        if ( null === $blanked ) {
            return null;
        }
        $text = $blanked;
        return array(
            'count'  => $count,
            'at'     => (int) $first[0][1],
            'length' => strlen( $first[0][0] ),
        );
    }

    private function shortlisted( string $searched ): bool {
        foreach ( $this->needles as $needle ) {
            if ( $this->literal ? false !== strpos( $searched, $needle ) : 1 === preg_match( self::loose( $needle ), $searched ) ) {
                return true;
            }
        }
        return false;
    }

    private static function path_key( string $key ): string {
        if ( strlen( $key ) <= self::MAX_PATH_KEY ) {
            return $key;
        }
        $end = self::MAX_PATH_KEY;
        while ( $end > 0 && 0x80 === ( ord( $key[ $end ] ) & 0xC0 ) ) {
            --$end;
        }
        return substr( $key, 0, $end ) . '…';
    }

    private function match( string $text, string $needle, ?int $context, bool $redact ): ?array {
        if ( $this->literal ) {
            $count = substr_count( $text, $needle );
            if ( 0 === $count ) {
                return null;
            }
            $out = array( 'count' => $count );
            if ( null !== $context ) {
                $out['snippet'] = self::snippet( $text, (int) strpos( $text, $needle ), strlen( $needle ), $context, $redact );
            }
            return $out;
        }
        if ( $this->regex && strlen( $text ) > Text_Matcher::MAX_REGEX_SUBJECT_BYTES ) {
            return array(
                'count'      => null,
                'incomplete' => 'too_large_for_regex',
            );
        }
        $count = preg_match_all( $needle, $text );
        if ( false === $count ) {
            return array(
                'count'      => null,
                'incomplete' => self::preg_failure(),
            );
        }
        if ( 0 === $count ) {
            return null;
        }
        $out = array( 'count' => $count );
        if ( null !== $context && 1 === preg_match( $needle, $text, $first, PREG_OFFSET_CAPTURE ) ) {
            $out['snippet'] = self::snippet( $text, (int) $first[0][1], strlen( $first[0][0] ), $context, $redact );
        }
        return $out;
    }

    private static function preg_failure(): string {
        $error = preg_last_error();
        if ( PREG_BACKTRACK_LIMIT_ERROR === $error ) {
            return 'backtrack_limit';
        }
        if ( PREG_BAD_UTF8_ERROR === $error ) {
            return 'invalid_utf8';
        }
        return 'regex_error';
    }

    private static function snippet( string $text, int $offset, int $length, int $context, bool $redact ): string {
        return Text_Redactor::redact( Text_Matcher::window( $text, $offset, $length, $context ), $redact );
    }
}
