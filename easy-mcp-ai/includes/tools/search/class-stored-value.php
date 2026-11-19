<?php
namespace Easy_MCP_AI\Tools\Search;

use Easy_MCP_AI\Cli_Router\Handlers\Option_Handler;
use Easy_MCP_AI\Privileged\Search_Replace\Replacer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Stored_Value {

    const MAX_DEPTH = 64;

    const MAX_STRINGS = 10000;

    const PHP_START = '/\A\s*(?:a:\d+:\{|[OC]:\d+:"|s:\d+:")/';

    const PHP_FLOAT = '/\Gd:(-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|NAN|-?INF);/';

    private $s;

    private $i = 0;

    private $strings = array();

    private $skipped = array();

    private $structured = false;

    private function __construct( string $value ) {
        $this->s = $value;
    }

    public static function read( string $value ): ?array {
        $reader = new self( $value );
        try {
            $kind = $reader->parse_whole();
        } catch ( \UnexpectedValueException $e ) {
            return $reader->falls_back_to_text() ? self::plain( $value ) : null;
        } catch ( \Throwable $e ) {
            return null;
        }
        return 'text' === $kind ? self::plain( $value ) : array(
            'strings'  => $reader->strings,
            'searched' => $reader->searched(),
        );
    }

    private static function plain( string $value ): array {
        return array(
            'strings'  => array(
                array(
                    'text' => $value,
                    'path' => null,
                ),
            ),
            'searched' => $value,
        );
    }

    private function falls_back_to_text(): bool {
        return 'json' === $this->kind() && ! $this->structured;
    }

    private function kind(): string {
        if ( Replacer::looks_serialized( $this->s ) || 1 === preg_match( self::PHP_START, $this->s ) ) {
            return 'php';
        }
        $trimmed = ltrim( $this->s );
        return '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ? 'json' : 'text';
    }

    private function parse_whole(): string {
        $kind = $this->kind();
        if ( 'php' === $kind ) {
            $this->i = strlen( $this->s ) - strlen( ltrim( $this->s ) );
            $this->php( 0, array(), true );
        } elseif ( 'json' === $kind ) {
            $this->json( 0, array(), true );
        } else {
            return 'text';
        }
        $this->space();
        if ( $this->i !== strlen( $this->s ) ) {
            throw new \UnexpectedValueException( 'trailing bytes' );
        }
        return $kind;
    }

    private function searched(): string {
        $out  = '';
        $from = 0;
        foreach ( $this->skipped as $range ) {
            $out .= substr( $this->s, $from, $range[0] - $from ) . "\0";
            $from = $range[1];
        }
        return $out . substr( $this->s, $from );
    }

    private function php( int $depth, array $path, bool $collect ): void {
        if ( $depth > self::MAX_DEPTH ) {
            throw new \OverflowException( 'too deep' );
        }
        $type = $this->s[ $this->i ] ?? '';
        if ( 'N' === $type ) {
            $this->expect( 'N;' );
        } elseif ( 'b' === $type ) {
            $this->match( '/\Gb:[01];/' );
        } elseif ( 'i' === $type || 'd' === $type ) {
            $number = $this->match( 'i' === $type ? '/\Gi:([+-]?\d+);/' : self::PHP_FLOAT );
            $this->leaf( $number[1], $path, $collect );
        } elseif ( 's' === $type ) {
            $start = 0;
            $text  = $this->php_string( 's', $start );
            $this->inner( $text, $start, $path, $collect, $depth );
        } elseif ( 'a' === $type ) {
            $count = (int) $this->match( '/\Ga:(\d+):\{/' )[1];
            $this->php_members( $count, $depth, $path, $collect, false );
            $this->expect( '}' );
        } elseif ( 'O' === $type ) {
            $this->php_string( 'O', $start );
            $this->expect( ':' );
            $count = (int) $this->match( '/\G(\d+):\{/' )[1];
            $this->php_members( $count, $depth, $path, $collect, true );
            $this->expect( '}' );
        } elseif ( 'C' === $type ) {
            $from = $this->i;
            $this->php_string( 'C', $start );
            $this->expect( ':' );
            $length = (int) $this->match( '/\G(\d+):\{/' )[1];
            if ( $length > strlen( $this->s ) - $this->i ) {
                throw new \UnexpectedValueException( 'short object' );
            }
            $this->i += $length;
            $this->expect( '}' );
            if ( $collect ) {
                $this->skipped[] = array( $from, $this->i );
            }
        } elseif ( 'r' === $type || 'R' === $type ) {
            $this->match( '/\G[rR]:\d+;/' );
        } elseif ( 'E' === $type ) {
            $this->php_string( 'E', $start );
            $this->expect( ';' );
        } else {
            throw new \UnexpectedValueException( 'not serialized' );
        }
    }

    private function php_string( string $type, &$start ): string {
        $length = (int) $this->match( '/\G' . $type . ':(\d+):"/' )[1];
        $start  = $this->i;
        if ( $length > strlen( $this->s ) - $start ) {
            throw new \UnexpectedValueException( 'short string' );
        }
        $this->i += $length;
        $this->expect( 's' === $type ? '";' : '"' );
        return (string) substr( $this->s, $start, $length );
    }

    private function php_members( int $count, int $depth, array $path, bool $collect, bool $object ): void {
        for ( $n = 0; $n < $count; $n++ ) {
            if ( 'i' === ( $this->s[ $this->i ] ?? '' ) ) {
                $key = $this->match( '/\Gi:([+-]?\d+);/' )[1];
            } else {
                $key = $this->php_string( 's', $start );
                $cut = $object && "\0" === substr( $key, 0, 1 ) ? strpos( $key, "\0", 1 ) : false;
                $key = false === $cut ? $key : substr( $key, $cut + 1 );
            }
            $this->member( $key, $depth, $path, $collect, 'php' );
        }
    }

    private function json( int $depth, array $path, bool $collect ): void {
        if ( $depth > self::MAX_DEPTH ) {
            throw new \OverflowException( 'too deep' );
        }
        $this->space();
        $c = $this->s[ $this->i ] ?? '';
        if ( '{' === $c || '[' === $c ) {
            $close = '{' === $c ? '}' : ']';
            ++$this->i;
            $this->space();
            if ( ( $this->s[ $this->i ] ?? '' ) === $close ) {
                ++$this->i;
                return;
            }
            for ( $n = 0; ; $n++ ) {
                $key = (string) $n;
                if ( '}' === $close ) {
                    $this->space();
                    $key              = $this->json_string( $start );
                    $this->structured = true;
                    $this->space();
                    $this->expect( ':' );
                }
                $this->member( $key, $depth, $path, $collect, 'json' );
                $this->space();
                $next = $this->s[ $this->i ] ?? '';
                ++$this->i;
                if ( $next === $close ) {
                    return;
                }
                if ( ',' !== $next ) {
                    throw new \UnexpectedValueException( 'not JSON' );
                }
            }
        }
        if ( '"' === $c ) {
            $from = $this->i;
            $text = $this->json_string( $start );
            $this->json_inner( $text, $from, $path, $collect, $depth );
            return;
        }
        $number = preg_match( '/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $this->s, $m, 0, $this->i );
        if ( false === $number ) {
            throw new \OverflowException( 'pcre' );
        }
        if ( 1 === $number ) {
            $this->i += strlen( $m[0] );
            $this->leaf( $m[0], $path, $collect );
            return;
        }
        foreach ( array( 'true', 'false', 'null' ) as $word ) {
            if ( substr( $this->s, $this->i, strlen( $word ) ) === $word ) {
                $this->i += strlen( $word );
                return;
            }
        }
        throw new \UnexpectedValueException( 'not JSON' );
    }

    private function json_string( &$start ): string {
        $start  = $this->i;
        $length = strlen( $this->s );
        if ( '"' !== ( $this->s[ $start ] ?? '' ) ) {
            throw new \UnexpectedValueException( 'not a JSON string' );
        }
        $end = $start + 1;
        while ( true ) {
            $end += strcspn( $this->s, '"\\', $end );
            if ( $end >= $length ) {
                throw new \UnexpectedValueException( 'unterminated JSON string' );
            }
            if ( '\\' === $this->s[ $end ] ) {
                $end += 2;
                continue;
            }
            break;
        }
        $this->i = $end + 1;
        $text    = json_decode( substr( $this->s, $start, $this->i - $start ) );
        if ( ! is_string( $text ) ) {
            throw new \UnexpectedValueException( 'not a JSON string' );
        }
        return $text;
    }

    private function member( string $key, int $depth, array $path, bool $collect, string $format ): void {
        $secret = Option_Handler::key_is_secret( $key );
        if ( 'json' === $format ) {
            $this->space();
        }
        $from         = $this->i;
        $child_path   = $path;
        $child_path[] = $key;
        if ( 'php' === $format ) {
            $this->php( $depth + 1, $child_path, $collect && ! $secret );
        } else {
            $this->json( $depth + 1, $child_path, $collect && ! $secret );
        }
        if ( $secret && $collect ) {
            $this->skipped[] = array( $from, $this->i );
        }
    }

    private function inner( string $text, int $start, array $path, bool $collect, int $depth ): void {
        if ( ! $collect ) {
            return;
        }
        $read = self::nested( $text, $depth );
        if ( null === $read ) {
            $this->skipped[] = array( $start, $start + strlen( $text ) );
            return;
        }
        foreach ( $read['strings'] as $string ) {
            $this->leaf( $string['text'], array_merge( $path, $string['path'] ?? array() ), true );
        }
        foreach ( $read['skipped'] as $range ) {
            $this->skipped[] = array( $start + $range[0], $start + $range[1] );
        }
    }

    private function json_inner( string $text, int $from, array $path, bool $collect, int $depth ): void {
        if ( ! $collect ) {
            return;
        }
        $read = self::nested( $text, $depth );
        if ( 'text' !== ( new self( $text ) )->kind() ) {
            $this->structured = true;
        }
        if ( null === $read || array() !== $read['skipped'] ) {
            $this->skipped[] = array( $from, $this->i );
            return;
        }
        foreach ( $read['strings'] as $string ) {
            $this->leaf( $string['text'], array_merge( $path, $string['path'] ?? array() ), true );
        }
    }

    private static function nested( string $text, int $depth ): ?array {
        $reader = new self( $text );
        if ( 'text' === $reader->kind() ) {
            return self::whole( $text );
        }
        try {
            if ( 'php' === $reader->kind() ) {
                $reader->i = strlen( $text ) - strlen( ltrim( $text ) );
                $reader->php( $depth + 1, array(), true );
            } else {
                $reader->json( $depth + 1, array(), true );
            }
            $reader->space();
            if ( $reader->i !== strlen( $text ) ) {
                throw new \UnexpectedValueException( 'trailing bytes' );
            }
        } catch ( \UnexpectedValueException $e ) {
            return $reader->falls_back_to_text() ? self::whole( $text ) : null;
        }
        return array(
            'strings' => $reader->strings,
            'skipped' => $reader->skipped,
        );
    }

    private static function whole( string $text ): array {
        return array(
            'strings' => array(
                array(
                    'text' => $text,
                    'path' => null,
                ),
            ),
            'skipped' => array(),
        );
    }

    private function leaf( string $text, array $path, bool $collect ): void {
        if ( $collect && '' !== $text ) {
            if ( count( $this->strings ) >= self::MAX_STRINGS ) {
                throw new \OverflowException( 'too many strings' );
            }
            $this->strings[] = array(
                'text' => $text,
                'path' => $path,
            );
        }
    }

    private function expect( string $bytes ): void {
        if ( substr( $this->s, $this->i, strlen( $bytes ) ) !== $bytes ) {
            throw new \UnexpectedValueException( 'expected ' . $bytes );
        }
        $this->i += strlen( $bytes );
    }

    private function match( string $pattern ): array {
        $found = preg_match( $pattern, $this->s, $m, 0, $this->i );
        if ( false === $found ) {
            throw new \OverflowException( 'pcre' );
        }
        if ( 1 !== $found ) {
            throw new \UnexpectedValueException( 'unexpected bytes' );
        }
        $this->i += strlen( $m[0] );
        return $m;
    }

    private function space(): void {
        $this->i += strspn( $this->s, " \t\n\r", $this->i );
    }
}
