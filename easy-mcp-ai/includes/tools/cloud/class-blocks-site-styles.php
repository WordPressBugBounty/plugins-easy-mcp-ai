<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Block_Checks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks_Site_Styles extends Local_Block_Tool {

    const STYLE_NAMESPACES = array(
        'otter'      => 'themeisle-blocks',
        'atomicWind' => 'atomic-wind',
    );

    const PRESET_ORIGINS = array( 'default', 'blocks', 'theme', 'custom' );

    const CLASSIC_THEME = '/block theme|classic theme|could not discover global styles/i';

    protected function is_idempotent(): bool {
        return true;
    }

    public function get_required_capability() {
        return 'edit_theme_options';
    }

    public function get_name() {
        return 'blocks_site_styles';
    }

    public function get_title() {
        return 'Site styles';
    }

    public function get_description() {
        return "Read the styles this site's block editor offers (color palette, gradients, font families and sizes, spacing sizes, content and wide widths: core's, the theme's and the saved ones) to use their slugs in blocks, and whether Otter Blocks, Otter Pro's blocks and Atomic Wind (an Otter module) are available here.";
    }

    public function get_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => new \stdClass(),
        );
    }

    protected function perform( array $arguments ) {
        $styles    = self::site_styles( null );
        $reply     = $this->request( 'wp_get_global_styles', array( 'merged_only' => true ) );
        $merged    = false;
        $classic   = false;
        if ( null !== $reply['data'] ) {
            $merged = isset( $reply['data']['merged_settings'] ) && is_array( $reply['data']['merged_settings'] );
            $styles = self::site_styles( $merged ? $reply['data']['merged_settings'] : ( $reply['data']['settings'] ?? null ) );
        } elseif ( $reply['tool_error'] && 1 === preg_match( self::CLASSIC_THEME, $reply['text'] ) ) {
            $classic = true;
        } else {
            throw self::refusal_for( $reply );
        }

        $plugins = array(
            'otter'      => false,
            'otterPro'   => false,
            'atomicWind' => false,
        );
        foreach ( self::STYLE_NAMESPACES as $flag => $namespace ) {
            $listed = $this->ask( 'wp_list_block_types', array( 'search' => $namespace, 'per_page' => Block_Checks::BLOCK_TYPES_PER_PAGE ) );
            $names  = self::block_type_names( $listed );
            $found  = false;
            foreach ( $names as $name ) {
                $found = $found || 0 === strpos( $name, $namespace . '/' );
            }
            $plugins[ $flag ] = $found;
            if ( 'otter' === $flag ) {
                $plugins['otterPro'] = array() !== array_intersect( $names, Block_Checks::OTTER_PRO_BLOCKS );
            }
        }
        $output = array_merge( array( 'content_notice' => Block_Checks::SITE_CONTENT_NOTICE ), $styles, array( 'plugins' => $plugins ) );

        $counts = sprintf(
            '%1$d colors, %2$d gradients, %3$d font families, %4$d font sizes, %5$d spacing sizes',
            count( $styles['palette'] ),
            count( $styles['gradients'] ),
            count( $styles['fontFamilies'] ),
            count( $styles['fontSizes'] ),
            count( $styles['spacingSizes'] )
        );
        $source = $merged ? '' : ( $classic ? ' (a classic theme: no global styles)' : ' (only the styles saved in the Site Editor)' );
        $output['message'] = sprintf(
            'Styles of this site%1$s: %2$s. Otter Blocks %3$s%4$s, Atomic Wind %5$s.',
            $source,
            $counts,
            $plugins['otter'] ? 'active' : 'not active',
            $plugins['otter'] ? ', Otter Pro blocks ' . ( $plugins['otterPro'] ? 'available' : 'not available (form-file, form-hidden-field and form-stripe-field need Otter Pro)' ) : '',
            $plugins['atomicWind'] ? 'turned on' : "not turned on (a module of Otter Blocks: Otter's settings, Enable Atomic Wind Blocks)"
        );
        return $output;
    }

    private static function site_styles( $settings ): array {
        $layout_value = static function ( string $key ) use ( $settings ) {
            $value = self::at( $settings, 'layout', $key );
            return is_string( $value ) ? $value : null;
        };
        return array(
            'palette'      => self::presets( self::at( $settings, 'color', 'palette' ), 'color' ),
            'gradients'    => self::presets( self::at( $settings, 'color', 'gradients' ), 'gradient' ),
            'fontFamilies' => self::presets( self::at( $settings, 'typography', 'fontFamilies' ), 'fontFamily' ),
            'fontSizes'    => self::presets( self::at( $settings, 'typography', 'fontSizes' ), 'size' ),
            'spacingSizes' => self::presets( self::at( $settings, 'spacing', 'spacingSizes' ), 'size' ),
            'layout'       => array(
                'contentSize' => $layout_value( 'contentSize' ),
                'wideSize'    => $layout_value( 'wideSize' ),
            ),
        );
    }

    private static function at( $value, string ...$path ) {
        foreach ( $path as $key ) {
            $value = is_array( $value ) && array_key_exists( $key, $value ) ? $value[ $key ] : null;
        }
        return $value;
    }

    private static function presets( $value, string $value_key ): array {
        $lists = array();
        if ( is_array( $value ) ) {
            if ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
                $lists[] = $value;
            } else {
                foreach ( self::PRESET_ORIGINS as $origin ) {
                    if ( isset( $value[ $origin ] ) && is_array( $value[ $origin ] ) ) {
                        $lists[] = $value[ $origin ];
                    }
                }
            }
        }
        $by_slug = array();
        foreach ( $lists as $list ) {
            foreach ( $list as $entry ) {
                if ( is_array( $entry ) && isset( $entry['slug'], $entry['name'], $entry[ $value_key ] ) && is_string( $entry['slug'] ) && is_string( $entry['name'] ) && is_string( $entry[ $value_key ] ) ) {
                    $by_slug[ $entry['slug'] ] = array(
                        'slug'      => $entry['slug'],
                        'name'      => $entry['name'],
                        $value_key  => $entry[ $value_key ],
                    );
                }
            }
        }
        return array_values( $by_slug );
    }
}
