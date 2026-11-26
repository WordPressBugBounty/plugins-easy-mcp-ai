<?php

namespace Easy_MCP_AI\Compat\Shims;

use Easy_MCP_AI\Compat\Compat_Shim;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Blocksy_Customizer_Loader extends Compat_Shim {

    const TOOLS = array( 'wp_get_theme_mods', 'wp_update_theme_mod', 'wp_delete_theme_mod', 'wp_update_custom_css', 'wp_cli_run' );

    const PRIORITY = PHP_INT_MIN;

    const FILE = 'inc/customizer/init.php';

    const LOADED_FUNCTION = 'blocksy_customizer_register_options';

    private $theme_class;

    private $theme_dir;

    private $loaded_function;

    private $callback = null;

    private $added = array();

    public function __construct( string $theme_class = 'Blocksy_Manager', ?string $theme_dir = null, string $loaded_function = self::LOADED_FUNCTION ) {
        $this->theme_class     = $theme_class;
        $this->theme_dir       = $theme_dir;
        $this->loaded_function = $loaded_function;
    }

    public function id(): string {
        return 'blocksy-customizer-loader';
    }

    public function applies_to( $tool ): bool {
        return is_object( $tool ) && method_exists( $tool, 'get_name' ) && in_array( $tool->get_name(), self::TOOLS, true );
    }

    public function is_needed(): bool {
        return '' !== $this->file() && ! function_exists( $this->loaded_function );
    }

    public function enter( array $arguments = array(), $tool = null ): ?\stdClass {
        if ( null !== $this->callback ) {
            return null;
        }
        $this->added    = array();
        $this->callback = function (): void {
            $this->load();
        };
        add_action( 'customize_register', $this->callback, self::PRIORITY );
        return new \stdClass();
    }

    public function leave( $state = null ): void {
        if ( null === $this->callback || ! $state instanceof \stdClass ) {
            return;
        }
        remove_action( 'customize_register', $this->callback, self::PRIORITY );
        foreach ( $this->added as $hooked ) {
            remove_filter( $hooked['hook'], $hooked['function'], $hooked['priority'] );
        }
        $this->added    = array();
        $this->callback = null;
    }

    private function load(): void {
        if ( ! $this->is_needed() ) {
            return;
        }
        $before = self::hooked();
        require $this->file();
        foreach ( self::hooked() as $key => $hooked ) {
            if ( ! isset( $before[ $key ] ) ) {
                $this->added[] = $hooked;
            }
        }
    }

    private function file(): string {
        if ( ! class_exists( $this->theme_class, false ) ) {
            return '';
        }
        $dir  = null !== $this->theme_dir ? $this->theme_dir : (string) get_template_directory();
        $path = '' === $dir ? '' : trailingslashit( $dir ) . self::FILE;
        return '' !== $path && is_readable( $path ) ? $path : '';
    }

    private static function hooked(): array {
        $all = array();
        foreach ( (array) ( $GLOBALS['wp_filter'] ?? array() ) as $hook => $wp_hook ) {
            if ( ! is_object( $wp_hook ) || ! isset( $wp_hook->callbacks ) || ! is_array( $wp_hook->callbacks ) ) {
                continue;
            }
            foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
                foreach ( (array) $callbacks as $id => $entry ) {
                    if ( is_array( $entry ) && isset( $entry['function'] ) ) {
                        $all[ $hook . "\0" . $priority . "\0" . $id ] = array(
                            'hook'     => (string) $hook,
                            'priority' => (int) $priority,
                            'function' => $entry['function'],
                        );
                    }
                }
            }
        }
        return $all;
    }
}
