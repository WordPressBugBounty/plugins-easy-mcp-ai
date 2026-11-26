<?php
namespace Easy_MCP_AI\Tools\CPT;

use Easy_MCP_AI\Tools\Post_Type_Rest_Base;
use Easy_MCP_AI\Tools\Post_Type_Tool_Hint;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Elementor_Templates {

    const POST_TYPE = 'elementor_library';

    const TYPE_META = '_elementor_template_type';

    const ADMIN_ONLY = 'Elementor lets only administrators reach its templates through the REST API.%s Nothing was changed.';

    const KIT = 'Item %d is Elementor\'s Site Settings (kit), which Elementor deletes only after its own confirmation in wp-admin. Nothing was deleted.';

    public static function refuse_admin_only( string $route, \WP_Error $error ): void {
        if ( 'rest_forbidden' !== $error->get_error_code()
            || false === strpos( $route, self::POST_TYPE )
            || ! class_exists( '\\Elementor\\Plugin' )
            || current_user_can( 'manage_options' ) ) {
            return;
        }
        throw new \RuntimeException( self::admin_only() );
    }

    public static function admin_only(): string {
        return sprintf( self::ADMIN_ONLY, Post_Type_Tool_Hint::may_reach( 'wp_update_page_layout' ) ? ' wp_update_page_layout edits a template\'s layout for anyone who may edit it.' : '' );
    }

    public static function refuse_kit_delete( string $rest_base, int $id ): void {
        $served = self::served( $rest_base );
        if ( null === $served || self::POST_TYPE !== $served['type'] || isset( $_GET['force_delete_kit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor's own check, read the same way.
            return;
        }
        $elementor_refuses = false !== strpos( $served['base'], self::POST_TYPE ) && ! current_user_can( 'manage_options' );
        $post              = get_post( $id );
        if ( $elementor_refuses || ! is_object( $post ) || self::POST_TYPE !== ( $post->post_type ?? '' ) || 'trash' === ( $post->post_status ?? '' ) || ! current_user_can( 'delete_post', $id ) || ! self::is_kit( $id ) ) {
            return;
        }
        throw new \RuntimeException( sprintf( self::KIT, $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    private static function served( string $rest_base ): ?array {
        try {
            $base = Post_Type_Rest_Base::resolve( trim( $rest_base ) );
            return array(
                'base' => $base,
                'type' => Post_Type_Rest_Base::require_served_type( $base )[0],
            );
        } catch ( \InvalidArgumentException $e ) {
            return null;
        }
    }

    public static function template_type( int $id ): string {
        $type = get_post_meta( $id, self::TYPE_META, true );
        return is_string( $type ) ? $type : '';
    }

    private static function is_kit( int $id ): bool {
        if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance->kits_manager ) ) {
            return false;
        }
        $kits = \Elementor\Plugin::$instance->kits_manager;
        return is_object( $kits ) && method_exists( $kits, 'is_kit' ) && (bool) $kits->is_kit( $id );
    }
}
