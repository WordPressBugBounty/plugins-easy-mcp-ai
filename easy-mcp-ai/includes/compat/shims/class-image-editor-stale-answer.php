<?php

namespace Easy_MCP_AI\Compat\Shims;

use Easy_MCP_AI\Compat\Compat_Shim;
use Easy_MCP_AI\Tools\Image_Editor_State;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Image_Editor_Stale_Answer extends Compat_Shim {

    const ID = 'image-editor-stale-answer';

    const HOOK = 'wp_image_editors';

    private $active = null;

    public function id() {
        return self::ID;
    }

    public function applies_to( $tool ) {
        return is_object( $tool );
    }

    public function is_needed() {
        return (bool) \wp_using_ext_object_cache();
    }

    public function enter( array $arguments = array(), $tool = null ) {
        if ( null !== $this->active ) {
            return null;
        }
        $arming            = new \stdClass();
        $arming->refreshed = false;
        $arming->callback  = static function ( $editors ) use ( $arming ) {
            if ( ! $arming->refreshed ) {
                $arming->refreshed = true;
                Image_Editor_State::refresh();
            }
            return $editors;
        };
        \add_filter( self::HOOK, $arming->callback, PHP_INT_MAX );
        $this->active = $arming;
        return $arming;
    }

    public function leave( $state = null ): void {
        if ( null === $state || $state !== $this->active ) {
            return;
        }
        \remove_filter( self::HOOK, $state->callback, PHP_INT_MAX );
        $this->active = null;
    }
}
