<?php

namespace Easy_MCP_AI\Compat\Shims;

use Easy_MCP_AI\Compat\Compat_Shim;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Navigation_Hooked_Blocks_Meta extends Compat_Shim {

    const ID = 'navigation-hooked-blocks-meta';

    const HOOK = 'rest_pre_insert_wp_navigation';

    const META_KEY = '_wp_ignored_hooked_blocks';

    private $active = null;

    public function id() {
        return self::ID;
    }

    public function applies_to( $tool ) {
        return is_object( $tool );
    }

    public function is_needed() {
        return false !== has_filter( self::HOOK );
    }

    public function enter( array $arguments = array(), $tool = null ) {
        if ( null !== $this->active ) {
            return null;
        }
        $arming         = new \stdClass();
        $arming->frames = array();
        $arming->begin  = static function ( $prepared ) use ( $arming ) {
            $arming->frames[] = array(
                'menu'   => is_object( $prepared ) && ! empty( $prepared->ID ) ? (int) $prepared->ID : 0,
                'held'   => false,
                'value'  => null,
                'saving' => false,
            );
            return $prepared;
        };
        $arming->hold   = static function ( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) use ( $arming ) {
            $last = count( $arming->frames ) - 1;
            if ( null !== $check || self::META_KEY !== $meta_key || '' !== $prev_value || $last < 0 ) {
                return $check;
            }
            $frame = $arming->frames[ $last ];
            if ( 0 === $frame['menu'] || (int) $object_id !== $frame['menu'] || $frame['saving'] ) {
                return $check;
            }
            $arming->frames[ $last ]['held']  = true;
            $arming->frames[ $last ]['value'] = $meta_value;
            return true;
        };
        $arming->saving = static function ( $post_id ) use ( $arming ) {
            foreach ( $arming->frames as $i => $frame ) {
                if ( $frame['menu'] === (int) $post_id ) {
                    $arming->frames[ $i ]['saving'] = true;
                }
            }
        };
        $arming->finish = static function ( $prepared ) use ( $arming ) {
            $frame = self::own_frame( $arming, $prepared );
            if ( null === $frame || ! $frame['held'] ) {
                return $prepared;
            }
            if ( ! is_object( $prepared ) || (int) ( $prepared->ID ?? 0 ) !== $frame['menu'] ) {
                return $prepared;
            }
            $input = isset( $prepared->meta_input ) && is_array( $prepared->meta_input ) ? $prepared->meta_input : array();
            if ( ! array_key_exists( self::META_KEY, $input ) ) {
                $input[ self::META_KEY ] = $frame['value'];
                $prepared->meta_input    = $input;
            }
            return $prepared;
        };
        add_filter( self::HOOK, $arming->begin, PHP_INT_MIN );
        add_filter( 'update_post_metadata', $arming->hold, PHP_INT_MIN, 5 );
        add_action( 'pre_post_update', $arming->saving, PHP_INT_MIN );
        add_filter( self::HOOK, $arming->finish, PHP_INT_MAX );
        $this->active = $arming;
        return $arming;
    }

    private static function own_frame( \stdClass $arming, $prepared ): ?array {
        $menu = is_object( $prepared ) && ! empty( $prepared->ID ) ? (int) $prepared->ID : 0;
        for ( $i = count( $arming->frames ) - 1; 0 !== $menu && $i >= 0; $i-- ) {
            if ( $arming->frames[ $i ]['menu'] === $menu ) {
                $frame          = $arming->frames[ $i ];
                $arming->frames = array_slice( $arming->frames, 0, $i );
                return $frame;
            }
        }
        $frame = array_pop( $arming->frames );
        return is_array( $frame ) ? $frame : null;
    }

    public function leave( $state = null ): void {
        if ( null === $state || $state !== $this->active ) {
            return;
        }
        remove_filter( self::HOOK, $state->begin, PHP_INT_MIN );
        remove_filter( 'update_post_metadata', $state->hold, PHP_INT_MIN );
        remove_action( 'pre_post_update', $state->saving, PHP_INT_MIN );
        remove_filter( self::HOOK, $state->finish, PHP_INT_MAX );
        $this->active = null;
    }
}
