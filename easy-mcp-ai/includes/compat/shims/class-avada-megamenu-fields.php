<?php

namespace Easy_MCP_AI\Compat\Shims;

use Easy_MCP_AI\Compat\Compat_Shim;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Avada_Megamenu_Fields extends Compat_Shim {

    const ID = 'avada-megamenu-fields';

    const TOOLS = array( 'wp_create_menu_item', 'wp_delete_menu_item', 'wp_reorder_menu_items', 'wp_update_menu_item', 'wp_rest_write', 'wp_update_cpt_item' );

    const HOOK = 'wp_update_nav_menu_item';

    const CALLBACK = 'save_custom_menu_style_fields';

    const META_KEY = '_menu_item_fusion_megamenu';

    const FIELD_PREFIX = 'menu-item-fusion-megamenu-';

    private $active = null;

    public function id() {
        return self::ID;
    }

    public function applies_to( $tool ) {
        return is_object( $tool ) && method_exists( $tool, 'get_name' ) && in_array( $tool->get_name(), self::TOOLS, true );
    }

    public function is_needed() {
        global $wp_filter;
        if ( ! isset( $wp_filter[ self::HOOK ] ) || ! is_object( $wp_filter[ self::HOOK ] ) || ! isset( $wp_filter[ self::HOOK ]->callbacks ) || ! is_array( $wp_filter[ self::HOOK ]->callbacks ) ) {
            return false;
        }
        foreach ( $wp_filter[ self::HOOK ]->callbacks as $callbacks ) {
            foreach ( (array) $callbacks as $callback ) {
                $function = $callback['function'] ?? null;
                if ( is_array( $function ) && isset( $function[0], $function[1] ) && is_object( $function[0] ) && self::CALLBACK === $function[1] ) {
                    return true;
                }
            }
        }
        return false;
    }

    public function enter( array $arguments = array(), $tool = null ) {
        if ( null !== $this->active ) {
            return null;
        }
        $arming            = new \stdClass();
        $arming->before    = array();
        $arming->present   = static function ( $menu_id, $item_id = 0 ) use ( $arming ) {
            self::present( $arming, (int) $item_id );
        };
        $arming->take_back = static function ( $menu_id, $item_id = 0 ) use ( $arming ) {
            self::take_back( $arming, (int) $item_id );
        };
        add_action( self::HOOK, $arming->present, PHP_INT_MIN, 2 );
        add_action( self::HOOK, $arming->take_back, PHP_INT_MAX, 2 );
        $this->active = $arming;
        return $arming;
    }

    private static function present( \stdClass $arming, int $item_id ): void {
        if ( 1 > $item_id ) {
            return;
        }
        $arming->before[] = array(
            'item'    => $item_id,
            'post'    => self::fields_of( $_POST, $item_id ), // phpcs:ignore WordPress.Security.NonceVerification
            'request' => self::fields_of( $_REQUEST, $item_id ), // phpcs:ignore WordPress.Security.NonceVerification
        );
        self::lists_only( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
        self::lists_only( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification
        $stored = get_post_meta( $item_id, self::META_KEY, true );
        if ( ! is_array( $stored ) ) {
            return;
        }
        foreach ( $stored as $key => $value ) {
            if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
                continue;
            }
            $field = self::FIELD_PREFIX . str_replace( '_', '-', $key );
            if ( isset( $_POST[ $field ][ $item_id ] ) || isset( $_REQUEST[ $field ][ $item_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- only read to leave a real post alone.
                continue;
            }
            $_POST[ $field ][ $item_id ]    = wp_slash( wp_slash( (string) $value ) );
            $_REQUEST[ $field ][ $item_id ] = wp_slash( wp_slash( (string) $value ) );
        }
    }

    private static function lists_only( array &$bag ): void {
        foreach ( $bag as $name => $values ) {
            if ( is_string( $name ) && 0 === strpos( $name, self::FIELD_PREFIX ) && ! is_array( $values ) ) {
                $bag[ $name ] = array();
            }
        }
    }

    private static function fields_of( array $bag, int $item_id ): array {
        $fields = array();
        foreach ( $bag as $name => $values ) {
            if ( ! is_string( $name ) || 0 !== strpos( $name, self::FIELD_PREFIX ) ) {
                continue;
            }
            if ( ! is_array( $values ) ) {
                $fields[ $name ] = array( 'whole' => $values );
            } else {
                $fields[ $name ] = array_key_exists( $item_id, $values ) ? array( 'item' => $values[ $item_id ] ) : array();
            }
        }
        return $fields;
    }

    private static function restore( array &$bag, array $before, int $item_id ): void {
        foreach ( array_keys( $bag ) as $name ) {
            if ( ! is_string( $name ) || 0 !== strpos( $name, self::FIELD_PREFIX ) || ! is_array( $bag[ $name ] ) ) {
                continue;
            }
            if ( isset( $before[ $name ] ) && array_key_exists( 'item', $before[ $name ] ) ) {
                $bag[ $name ][ $item_id ] = $before[ $name ]['item'];
                continue;
            }
            unset( $bag[ $name ][ $item_id ] );
            if ( ! isset( $before[ $name ] ) && array() === $bag[ $name ] ) {
                unset( $bag[ $name ] );
            }
        }
        foreach ( $before as $name => $held ) {
            if ( array_key_exists( 'whole', $held ) ) {
                $bag[ $name ] = $held['whole'];
            }
        }
    }

    private static function take_back( \stdClass $arming, int $item_id ): void {
        for ( $i = count( $arming->before ) - 1; $i >= 0; $i-- ) {
            if ( $arming->before[ $i ]['item'] === $item_id ) {
                $before = $arming->before[ $i ];
                array_splice( $arming->before, $i, 1 );
                self::restore( $_POST, $before['post'], $item_id ); // phpcs:ignore WordPress.Security.NonceVerification
                self::restore( $_REQUEST, $before['request'], $item_id ); // phpcs:ignore WordPress.Security.NonceVerification
                return;
            }
        }
    }

    public function leave( $state = null ): void {
        if ( null === $state || $state !== $this->active ) {
            return;
        }
        while ( array() !== $state->before ) {
            $last = end( $state->before );
            self::take_back( $state, $last['item'] );
        }
        remove_action( self::HOOK, $state->present, PHP_INT_MIN );
        remove_action( self::HOOK, $state->take_back, PHP_INT_MAX );
        $this->active = null;
    }
}
