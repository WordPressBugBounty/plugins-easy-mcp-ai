<?php
namespace Easy_MCP_AI\Tools\Appearance;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Theme_Mod extends Base_Tool {

    public function get_name() {
        return 'wp_update_theme_mod';
    }

    public function get_description() {
        return 'Changes one theme mod of the active theme, exactly as saving it in Appearance → Customize would. Required: `key` (the Customizer setting id, e.g. `header_textcolor`, or an array-style id such as `theme_options[accent]`) and `value` (a string, number or boolean, passed to the setting\'s validation and sanitize callbacks with exactly the JSON type you send — use the type the setting stores, which its `default` in wp_get_theme_mods include_editable shows; a checkbox control does not always mean a boolean: WordPress\'s own background_attachment checkbox stores "scroll" or "fixed"). Only keys the active theme or a plugin registers as Customizer settings are accepted; an unknown key is refused with the list of valid keys (wp_get_theme_mods with include_editable: true lists them with labels and allowed choices). Refused: settings stored as site options rather than theme mods, menu locations (`nav_menu_locations`), `custom_css_post_id`, and Additional CSS (use wp_update_custom_css). A setting may require a stronger capability than edit_theme_options, and a value WordPress rejects is reported with the Customizer\'s own message; nothing is written in either case. Returns { key, previous, value, stored }: `previous` and `value` are what the theme reads through get_theme_mod() before and after (its registered default when nothing is stored), and `stored` is the raw value now saved — they differ when the theme filters the mod or the setting\'s sanitizer changed your value. Arrays and objects are not accepted. Some themes register their settings only inside the Customizer screen; those keys are refused here.';
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
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'key'   => array(
                    'type'        => 'string',
                    'description' => 'Customizer setting id of the theme mod to change.',
                ),
                
                
                
                
                
                
                'value' => array(
                    'description' => 'New value: a string, number or boolean, of the type the setting stores (see its default).',
                    'oneOf'       => array(
                        array( 'type' => 'string' ),
                        array( 'type' => 'number' ),
                        array( 'type' => 'boolean' ),
                    ),
                ),
            ),
            'required'   => array( 'key', 'value' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'key' ) );
        if ( ! array_key_exists( 'value', $arguments ) ) {
            throw new \InvalidArgumentException( 'Missing required parameter: value' );
        }

        $key   = Customizer_Settings::parse_key( $arguments['key'] );
        $value = $arguments['value'];
        if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) && ! is_bool( $value ) ) {
            throw new \InvalidArgumentException( '"value" must be a string, number or boolean. Arrays, objects and null are not accepted; use wp_delete_theme_mod to go back to the default.' );
        }

        
        $denied = Customizer_Settings::denied_reason( $key );
        if ( null !== $denied ) {
            throw new \InvalidArgumentException( $denied ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        Customizer_Settings::assert_can_customize();

        return Customizer_Settings::with_manager(
            static function ( $manager ) use ( $key, $value ) {
                $setting  = Customizer_Settings::resolve_writable_setting( $manager, $key );
                $previous = Customizer_Settings::effective_value( $setting );

                Customizer_Settings::validate_and_save( $manager, $setting, $value );
                Customizer_Settings::after_save( $manager );

                return array(
                    'key'      => (string) $setting->id,
                    'previous' => $previous,
                    'value'    => Customizer_Settings::effective_value( $setting ),
                    'stored'   => Customizer_Settings::stored_value( $setting ),
                );
            }
        );
    }
}
