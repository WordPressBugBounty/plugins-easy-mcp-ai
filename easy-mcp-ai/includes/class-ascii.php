<?php

namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ascii {

    public static function lower( string $text ): string {
        return Autoloader::lower( $text );
    }

    public static function upper( string $text ): string {
        return strtr( $text, Autoloader::ASCII_LOWER, Autoloader::ASCII_UPPER );
    }

    public static function matches( string $pattern, string $subject ): bool {
        return 1 === preg_match( $pattern, self::lower( $subject ) );
    }

    public static function replace_callback( string $pattern, callable $callback, string $subject ): ?string {
        $out    = '';
        $at     = 0;
        $splice = static function ( array $match ) use ( $subject, $callback, &$out, &$at ): string {
            $groups = array();
            foreach ( $match as $name => $group ) {
                $groups[ $name ] = -1 === $group[1] ? '' : substr( $subject, $group[1], strlen( $group[0] ) );
            }
            $out .= substr( $subject, $at, $match[0][1] - $at ) . $callback( $groups );
            $at   = $match[0][1] + strlen( $match[0][0] );
            return '';
        };
        if ( null === preg_replace_callback( $pattern, $splice, self::lower( $subject ), -1, $count, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }
        return $out . substr( $subject, $at );
    }

    public static function replace( string $pattern, string $replacement, string $subject ): ?string {
        return self::replace_callback(
            $pattern,
            static function () use ( $replacement ): string {
                return $replacement;
            },
            $subject
        );
    }
}
