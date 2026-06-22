<?php
namespace Easy_MCP_AI\Tools\Site;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Site_Settings extends Base_Tool {

    public function get_name() {
        return 'wp_update_site_settings';
    }

    public function get_description() {
        return 'Updates WordPress site settings (PATCH semantics — only supplied fields change). Editable: `title`, `description` (tagline), `timezone` (e.g. "America/New_York" or "Europe/London"; `timezone_string` is accepted as an alias), `date_format` (e.g. "F j, Y"), `time_format` (e.g. "g:i a"), `posts_per_page` (integer). Requires `manage_options` (administrators only). Changes take effect immediately. Returns { updated: true, title, description }.';
    }

    public function get_category() {
        return 'site';
    }

    public function get_required_capability() {
        return 'manage_options';
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
                'title'          => array(
                    'type'        => 'string',
                    'description' => 'The site title.',
                ),
                'description'    => array(
                    'type'        => 'string',
                    'description' => 'The site tagline/description.',
                ),
                'timezone'        => array(
                    'type'        => 'string',
                    'description' => 'The site timezone string (e.g. America/New_York, Europe/London). This is the WP REST API settings field name.',
                ),
                'timezone_string' => array(
                    'type'        => 'string',
                    'description' => 'Deprecated alias for `timezone`; accepted for backward compatibility.',
                ),
                'date_format'    => array(
                    'type'        => 'string',
                    'description' => 'The date format string (e.g. F j, Y).',
                ),
                'time_format'    => array(
                    'type'        => 'string',
                    'description' => 'The time format string (e.g. g:i a).',
                ),
                'posts_per_page' => array(
                    'type'        => 'integer',
                    'description' => 'The number of posts to show per page.',
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $params = array();

        if ( isset( $arguments['title'] ) ) {
            $params['title'] = sanitize_text_field( $arguments['title'] );
        }

        if ( isset( $arguments['description'] ) ) {
            $params['description'] = sanitize_text_field( $arguments['description'] );
        }

        
        
        
        if ( isset( $arguments['timezone'] ) ) {
            $params['timezone'] = sanitize_text_field( $arguments['timezone'] );
        } elseif ( isset( $arguments['timezone_string'] ) ) {
            $params['timezone'] = sanitize_text_field( $arguments['timezone_string'] );
        }

        if ( isset( $arguments['date_format'] ) ) {
            $params['date_format'] = sanitize_text_field( $arguments['date_format'] );
        }

        if ( isset( $arguments['time_format'] ) ) {
            $params['time_format'] = sanitize_text_field( $arguments['time_format'] );
        }

        if ( isset( $arguments['posts_per_page'] ) ) {
            $params['posts_per_page'] = absint( $arguments['posts_per_page'] );
        }

        if ( empty( $params ) ) {
            throw new \InvalidArgumentException( 'At least one setting parameter must be provided.' );
        }

        $data = $this->rest_request( 'POST', '/wp/v2/settings', $params );

        return array(
            'updated'     => true,
            'title'       => $data['title'],
            'description' => $data['description'],
        );
    }
}
