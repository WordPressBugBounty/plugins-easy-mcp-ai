<?php
namespace Easy_MCP_AI\Tools\Appearance;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delete_Theme_Mod extends Base_Tool {

    public function get_name() {
        return 'wp_delete_theme_mod';
    }

    public function get_description() {
        return 'Removes the stored value of one theme mod of the active theme, so the theme falls back to its registered default. Required: `key` — a Customizer setting id; the same keys as wp_update_theme_mod are accepted and the same ones refused (unknown keys, settings stored as site options, `nav_menu_locations`, `custom_css_post_id`, Additional CSS, and settings that need a capability you lack). For an array-style id such as `theme_options[accent]` only that entry is removed from the `theme_options` mod; the other entries stay. Returns { key, deleted, previous, value }: `deleted` is false when nothing was stored (no write happens), `previous` is the value before, and `value` is the default the theme now reads.';
    }

    public function get_category() {
        return 'appearance';
    }

    public function get_required_capability() {
        return 'edit_theme_options';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'key' => array(
                    'type'        => 'string',
                    'description' => 'Customizer setting id of the theme mod to reset to its default.',
                ),
            ),
            'required'   => array( 'key' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'key' ) );
        $key = Customizer_Settings::parse_key( $arguments['key'] );

        $denied = Customizer_Settings::denied_reason( $key );
        if ( null !== $denied ) {
            throw new \InvalidArgumentException( $denied ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        Customizer_Settings::assert_can_customize();

        return Customizer_Settings::with_manager(
            static function ( $manager ) use ( $key ) {
                $setting  = Customizer_Settings::resolve_writable_setting( $manager, $key );
                $previous = Customizer_Settings::effective_value( $setting );

                if ( ! Customizer_Settings::is_stored( $setting ) ) {
                    return array(
                        'key'      => (string) $setting->id,
                        'deleted'  => false,
                        'previous' => $previous,
                        'value'    => $previous,
                    );
                }

                $data = $setting->id_data();
                if ( empty( $data['keys'] ) ) {
                    remove_theme_mod( $data['base'] );
                } else {
                    
                    
                    
                    
                    
                    $mods = get_theme_mods();
                    $root = $mods[ $data['base'] ];
                    self::unset_path( $root, $data['keys'] );
                    set_theme_mod( $data['base'], $root );
                }

                return array(
                    'key'      => (string) $setting->id,
                    'deleted'  => true,
                    'previous' => $previous,
                    'value'    => Customizer_Settings::effective_value( $setting ),
                );
            }
        );
    }

    






    private static function unset_path( array &$root, array $keys ): void {
        $last = array_pop( $keys );
        $node = &$root;
        foreach ( $keys as $k ) {
            $node = &$node[ $k ];
        }
        unset( $node[ $last ] );
    }
}
