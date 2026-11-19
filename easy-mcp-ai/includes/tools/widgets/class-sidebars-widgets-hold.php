<?php
namespace Easy_MCP_AI\Tools\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Sidebars_Widgets_Hold {

    public static function during( callable $read ) {
        global $sidebars_widgets, $_wp_sidebars_widgets;
        $memory = $sidebars_widgets;
        $cached = $_wp_sidebars_widgets;
        $hold   = static function ( $value, $option, $old_value ) {
            return 'sidebars_widgets' === $option ? $old_value : $value;
        };
        add_filter( 'pre_update_option', $hold, PHP_INT_MAX, 3 );
        try {
            return $read();
        } finally {
            remove_filter( 'pre_update_option', $hold, PHP_INT_MAX );
            $sidebars_widgets     = $memory;
            $_wp_sidebars_widgets = $cached;
        }
    }
}
