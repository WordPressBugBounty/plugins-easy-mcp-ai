<?php

namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Image_Editor_State {

    const CHOOSER_CACHE = array( 'wp_image_editor_choose', 'image_editor' );

    public static function available( array $args = array() ): ?bool {
        if ( defined( 'WPINC' ) ) {
            foreach ( array( 'class-wp-image-editor.php', 'class-wp-image-editor-gd.php', 'class-wp-image-editor-imagick.php', 'class-avif-info.php' ) as $file ) {
                $path = ABSPATH . WPINC . '/' . $file;
                if ( is_readable( $path ) ) {
                    require_once $path;
                }
            }
        }
        if ( ! class_exists( 'WP_Image_Editor' ) ) {
            return null;
        }
        try {
            foreach ( (array) \apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) ) as $implementation ) {
                if ( ! is_string( $implementation ) || ! class_exists( $implementation ) || ! is_callable( array( $implementation, 'test' ) ) ) {
                    continue;
                }
                if ( ! call_user_func( array( $implementation, 'test' ), $args ) ) {
                    continue;
                }
                if ( isset( $args['mime_type'] ) && ! call_user_func( array( $implementation, 'supports_mime_type' ), $args['mime_type'] ) ) {
                    continue;
                }
                if ( isset( $args['methods'] ) && array_diff( (array) $args['methods'], get_class_methods( $implementation ) ) ) {
                    continue;
                }
                return true;
            }
        } catch ( \Throwable $e ) {
            return null;
        }
        return false;
    }

    public static function refresh(): void {
        $cached = \wp_cache_get( self::CHOOSER_CACHE[0], self::CHOOSER_CACHE[1] );
        if ( is_array( $cached ) && in_array( false, $cached, true ) && true === self::available() ) {
            \wp_cache_delete( self::CHOOSER_CACHE[0], self::CHOOSER_CACHE[1] );
        }
    }

    public static function supports( array $args ): bool {
        self::refresh();
        if ( \wp_image_editor_supports( $args ) ) {
            return true;
        }
        if ( true !== self::available( $args ) ) {
            return false;
        }
        \wp_cache_delete( self::CHOOSER_CACHE[0], self::CHOOSER_CACHE[1] );
        return (bool) \wp_image_editor_supports( $args );
    }
}
