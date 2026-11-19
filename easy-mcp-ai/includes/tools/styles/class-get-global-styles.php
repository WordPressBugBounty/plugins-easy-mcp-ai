<?php
namespace Easy_MCP_AI\Tools\Styles;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Global_Styles extends Base_Tool {

    private const MERGED_PRESET_PATHS = array(
        array( 'color', 'palette', 'defaultPalette' ),
        array( 'color', 'gradients', 'defaultGradients' ),
        array( 'typography', 'fontFamilies', null ),
        array( 'typography', 'fontSizes', 'defaultFontSizes' ),
        array( 'spacing', 'spacingSizes', 'defaultSpacingSizes' ),
    );

    public function get_name() {
        return 'wp_get_global_styles';
    }

    public function get_description() {
        return 'Gets the current global styles (theme.json) settings and styles. Requires an active block theme. Returns { id, settings, styles, title }. Optional `include_variations` (default false) adds `available_variations: [{ slug, title, scope }]` — the style variations the active theme ships (its /styles/*.json files, shown as "Browse styles" in the Site Editor). `scope` is `full`, `color` (a colour-only partial) or `typography` (a typography-only partial); when a theme ships a full variation and a partial under the same title, the full one keeps the bare slug and the partials are `<slug>-color` / `<slug>-typography`; every slug in the list is unique — a later variation whose slug is already taken gets `-2`, `-3`, … appended (so a full "Noon Color" next to a colour-only "Noon" is `noon-color-2`). Pass a slug exactly as listed to wp_update_global_styles `variation_slug` to apply one. Optional `merged` (default false) adds `merged_settings: { color: { palette, gradients }, typography: { fontFamilies, fontSizes }, spacing: { spacingSizes }, layout: { contentSize, wideSize } }`, the presets the block editor offers (core defaults unless the theme switches them off, the theme\'s presets and the saved user styles, each list keyed by origin `default` / `theme` / `custom`, entries as WordPress stores them), and also works on a classic theme, where the result is just `{ merged_settings }`.';
    }

    public function get_category() {
        return 'styles';
    }

    public function get_required_capability() {
        return 'edit_theme_options';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'include_variations' => array(
                    'type'        => 'boolean',
                    'description' => 'When true, also list the theme\'s available style variations as available_variations: [{ slug, title, scope }].',
                    'default'     => false,
                ),
                'merged_only'        => array(
                    'type'        => 'boolean',
                    'description' => 'When true, return only merged_settings, on any theme, and create nothing: without it, the first read on a block theme creates the site\'s empty global styles post.',
                    'default'     => false,
                ),
                'merged'             => array(
                    'type'        => 'boolean',
                    'description' => 'When true, also return merged_settings: the palette, gradients, font families, font sizes, spacing sizes (each keyed by origin default / theme / custom) and layout sizes the block editor offers. Works on a classic theme too.',
                    'default'     => false,
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $merged = ! empty( $arguments['merged'] ) && rest_sanitize_boolean( $arguments['merged'] );

        $merged_only = ! empty( $arguments['merged_only'] ) && rest_sanitize_boolean( $arguments['merged_only'] );
        if ( $merged_only || ( $merged && ! wp_is_block_theme() ) ) {
            return array( 'merged_settings' => $this->merged_settings() );
        }

        $global_styles_id = $this->discover_global_styles_id();

        $request  = new \WP_REST_Request( 'GET', '/wp/v2/global-styles/' . $global_styles_id );
        $request->set_param( 'context', 'edit' );
        $response = rest_do_request( $request );

        if ( $response->is_error() ) {
            $wp_error = $response->as_error();
            if ( 'rest_no_route' === $wp_error->get_error_code() ) {
                throw new \RuntimeException(
                    'Global styles endpoint is not available. This requires an active block theme (Full Site Editing). The current theme appears to be a classic theme.'
                );
            }
            throw new \RuntimeException( $wp_error->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $data = $response->get_data();

        $result = array(
            'id'       => $data['id'],
            'settings' => $data['settings'] ?? new \stdClass(),
            'styles'   => $data['styles'] ?? new \stdClass(),
            'title'    => $data['title']['raw'] ?? wp_strip_all_tags( $data['title']['rendered'] ?? '' ),
        );

        if ( ! empty( $arguments['include_variations'] ) && rest_sanitize_boolean( $arguments['include_variations'] ) ) {
            $result['available_variations'] = array_map(
                function ( $variation ) {
                    return array(
                        'slug'  => $variation['slug'],
                        'title' => $variation['title'],
                        'scope' => $variation['scope'],
                    );
                },
                $this->fetch_style_variations()
            );
        }

        if ( $merged ) {
            $result['merged_settings'] = $this->merged_settings();
        }

        return $result;
    }

    private function merged_settings() {
        $settings = wp_get_global_settings();
        $settings = is_array( $settings ) ? $settings : array();

        $merged = array();
        foreach ( self::MERGED_PRESET_PATHS as list( $section, $key, $default_flag ) ) {
            $group   = isset( $settings[ $section ] ) && is_array( $settings[ $section ] ) ? $settings[ $section ] : array();
            $presets = $group[ $key ] ?? null;
            if ( null !== $default_flag && is_array( $presets ) && false === ( $group[ $default_flag ] ?? null ) ) {
                unset( $presets['default'] );
            }
            $merged[ $section ][ $key ] = $this->presets_by_origin( $presets );
        }

        $layout           = isset( $settings['layout'] ) && is_array( $settings['layout'] ) ? $settings['layout'] : array();
        $merged['layout'] = array(
            'contentSize' => isset( $layout['contentSize'] ) && is_string( $layout['contentSize'] ) ? $layout['contentSize'] : null,
            'wideSize'    => isset( $layout['wideSize'] ) && is_string( $layout['wideSize'] ) ? $layout['wideSize'] : null,
        );

        return $merged;
    }

    private function presets_by_origin( $presets ) {
        $by_origin = array();
        if ( is_array( $presets ) ) {
            foreach ( $presets as $origin => $list ) {
                if ( is_string( $origin ) && is_array( $list ) ) {
                    $by_origin[ $origin ] = array_values( $list );
                }
            }
        }

        return $by_origin ? $by_origin : new \stdClass();
    }
}
