<?php

namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Filesystem_State {

    public static function method( string $context ) {
        if ( ! function_exists( 'get_filesystem_method' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return \get_filesystem_method( array(), $context );
    }

    public static function method_configured(): bool {
        return defined( 'FS_METHOD' ) && (bool) constant( 'FS_METHOD' );
    }

    public static function stored_credentials( string $context ): bool {
        if ( ! function_exists( 'request_filesystem_credentials' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $keep = static function ( $value, $option, $old_value ) {
            return 'ftp_credentials' === $option ? $old_value : $value;
        };
        \add_filter( 'pre_update_option', $keep, PHP_INT_MAX, 3 );
        ob_start();
        try {
            $stored = \request_filesystem_credentials( \self_admin_url(), '', false, $context );
        } finally {
            ob_end_clean();
            \remove_filter( 'pre_update_option', $keep, PHP_INT_MAX );
        }
        return (bool) $stored;
    }

    public static function configured_not_direct( string $context ): bool {
        return self::method_configured() && ( 'direct' !== self::method( $context ) || 'direct' !== self::method( (string) WP_CONTENT_DIR ) );
    }

    public static function theme_roots(): array {
        $roots = array( (string) \get_theme_root() );
        foreach ( (array) ( $GLOBALS['wp_theme_directories'] ?? array() ) as $root ) {
            if ( is_string( $root ) && '' !== $root ) {
                $roots[] = $root;
            }
        }
        return array_values( array_unique( $roots ) );
    }

    public static function every_theme_root_without_access(): bool {
        foreach ( self::theme_roots() as $root ) {
            if ( ! self::configured_without_access( $root ) ) {
                return false;
            }
        }
        return true;
    }

    public static function configured_without_access( string $context ): bool {
        return self::method_configured() && 'direct' !== self::method( $context ) && ! self::stored_credentials( $context );
    }
}
