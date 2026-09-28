<?php
namespace Easy_MCP_AI\Tools\Styles;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Global_Styles extends Base_Tool {

    public function get_name() {
        return 'wp_update_global_styles';
    }

    public function get_description() {
        return 'Updates the global styles (theme.json) settings and/or styles. Either pass `styles` (object — CSS custom properties, element styles) and/or `settings` (object — color palette, typography, spacing), or pass `variation_slug` to apply one of the theme\'s style variations (list them with wp_get_global_styles `include_variations: true`); `variation_slug` cannot be combined with `styles`/`settings`. `variation_mode` (default `merge`) controls how a variation is applied: `merge` deep-merges the variation over the current user styles and settings, so keys the variation does not mention survive (lists such as a color palette are replaced whole, never spliced by index); `replace` writes the variation\'s settings and styles as the whole user configuration, discarding every existing override. Returns { id, settings, styles, title } plus `applied_variation: { slug, title, scope, mode }` when a variation was applied (`scope` is `full`, `color` or `typography` — a colour-only partial of a full variation is addressed as `<slug>-color`, and a slug that would otherwise repeat carries `-2`, `-3`, …; the slugs wp_get_global_styles lists are unique, use them exactly as listed). Changes are user-level overrides — they persist across theme updates but can be reset by clearing the Global Styles post. Requires an active block theme (Full Site Editing).';
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
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'styles'         => array(
                    'type'        => 'object',
                    'description' => 'Styles object following theme.json structure (color, typography, spacing, etc.).',
                ),
                'settings'       => array(
                    'type'        => 'object',
                    'description' => 'Settings object following theme.json structure.',
                ),
                'variation_slug' => array(
                    'type'        => 'string',
                    'description' => 'Slug of a theme style variation to apply (from wp_get_global_styles with include_variations). Cannot be combined with styles or settings.',
                ),
                'variation_mode' => array(
                    'type'        => 'string',
                    'description' => 'How to apply the variation: `merge` (default — deep-merge over the current user styles/settings) or `replace` (the variation becomes the whole user configuration).',
                    'enum'        => array( 'merge', 'replace' ),
                    'default'     => 'merge',
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $has_styles    = isset( $arguments['styles'] );
        $has_settings  = isset( $arguments['settings'] );
        $has_variation = isset( $arguments['variation_slug'] ) && '' !== $arguments['variation_slug'];

        if ( $has_variation && ( $has_styles || $has_settings ) ) {
            throw new \InvalidArgumentException( '"variation_slug" cannot be combined with "styles" or "settings". Apply the variation first, then send the overrides in a second call.' );
        }
        if ( ! $has_styles && ! $has_settings && ! $has_variation ) {
            throw new \InvalidArgumentException( 'At least one of "styles" or "settings" must be provided, or a "variation_slug" to apply.' );
        }

        $mode = 'merge';
        if ( isset( $arguments['variation_mode'] ) && '' !== $arguments['variation_mode'] ) {
            $mode = is_string( $arguments['variation_mode'] ) ? strtolower( trim( $arguments['variation_mode'] ) ) : '';
            if ( ! in_array( $mode, array( 'merge', 'replace' ), true ) ) {
                throw new \InvalidArgumentException( 'Invalid variation_mode. Use "merge" or "replace".' );
            }
        }

        $global_styles_id = $this->discover_global_styles_id();

        $body    = array();
        $applied = null;

        if ( $has_variation ) {
            $variation = $this->resolve_variation( $arguments['variation_slug'] );
            $applied   = array(
                'slug'  => $variation['slug'],
                'title' => $variation['title'],
                'scope' => $variation['scope'],
                'mode'  => $mode,
            );

            if ( 'replace' === $mode ) {
                
                
                
                
                $body['settings'] = empty( $variation['settings'] ) ? new \stdClass() : $variation['settings'];
                $body['styles']   = empty( $variation['styles'] ) ? new \stdClass() : $variation['styles'];
            } else {
                $current          = $this->read_current( $global_styles_id );
                $body['settings'] = $this->merge_theme_json( $current['settings'], $variation['settings'] );
                $body['styles']   = $this->merge_theme_json( $current['styles'], $variation['styles'] );
            }
        } else {
            if ( $has_styles ) {
                $body['styles'] = $this->parse_json_param( $arguments['styles'], 'styles' );
            }
            if ( $has_settings ) {
                $body['settings'] = $this->parse_json_param( $arguments['settings'], 'settings' );
            }
        }

        $request = new \WP_REST_Request( 'POST', '/wp/v2/global-styles/' . $global_styles_id );

        if ( ! empty( $body ) ) {
            $request->set_body( wp_json_encode( $body ) );
            $request->set_header( 'content-type', 'application/json' );
        }

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
        if ( null !== $applied ) {
            $result['applied_variation'] = $applied;
        }

        return $result;
    }

    







    private function resolve_variation( $slug ) {
        $slug       = is_string( $slug ) ? trim( $slug ) : '';
        $variations = $this->fetch_style_variations();

        foreach ( $variations as $variation ) {
            if ( $variation['slug'] === $slug ) {
                return $variation;
            }
        }

        $available = array_values( array_unique( array_column( $variations, 'slug' ) ) );
        throw new \InvalidArgumentException(
            sprintf(
                'Unknown variation_slug "%s". %s',
                $slug, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                empty( $available )
                    ? 'The active theme ships no style variations.'
                    : 'Available: ' . implode( ', ', $available ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            )
        );
    }

    







    private function read_current( $global_styles_id ) {
        $request = new \WP_REST_Request( 'GET', '/wp/v2/global-styles/' . $global_styles_id );
        $request->set_param( 'context', 'edit' );
        $response = rest_do_request( $request );

        if ( $response->is_error() ) {
            throw new \RuntimeException( $response->as_error()->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $data = $response->get_data();
        return array(
            'settings' => isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
            'styles'   => isset( $data['styles'] ) && is_array( $data['styles'] ) ? $data['styles'] : array(),
        );
    }

    














    private function merge_theme_json( array $current, array $variation ) {
        if ( empty( $current ) && empty( $variation ) ) {
            return new \stdClass();
        }
        return $this->merge_recursive( $current, $variation );
    }

    private function merge_recursive( array $current, array $variation ) {
        if ( $this->is_list( $variation ) || $this->is_list( $current ) ) {
            return $variation;
        }
        foreach ( $variation as $key => $value ) {
            if ( is_array( $value ) && isset( $current[ $key ] ) && is_array( $current[ $key ] ) ) {
                $current[ $key ] = $this->merge_recursive( $current[ $key ], $value );
            } else {
                $current[ $key ] = $value;
            }
        }
        return $current;
    }

    
    private function is_list( array $value ) {
        if ( empty( $value ) ) {
            return false;
        }
        return array_keys( $value ) === range( 0, count( $value ) - 1 );
    }
}
