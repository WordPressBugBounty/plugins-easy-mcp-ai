<?php
namespace Easy_MCP_AI\Tools\Widgets;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delete_Widget extends Base_Tool {

    use Widget_Rest;

    public function get_name() {
        return 'wp_delete_widget';
    }

    public function get_description() {
        return 'Removes a widget from the site. Required: `widget_id` (from wp_list_widgets). Optional: `force` (boolean, default false). With force false the widget is moved to `wp_inactive_widgets`: it disappears from every page but keeps its settings, and wp_update_widget with a `sidebar` puts it back. With force true the widget and its settings are deleted permanently — this cannot be undone. Returns { id, deleted: false, sidebar: "wp_inactive_widgets" } for a move, or { id, deleted: true, previous_sidebar } for a permanent delete. If a plugin stops the move or the delete, the call is an error. Works for legacy widgets too. Refused when the theme has no widget areas (a block theme). Requires the edit_theme_options capability, the same as Appearance > Widgets.';
    }

    public function get_category() {
        return 'widgets';
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
                'widget_id' => array(
                    'type'        => 'string',
                    'description' => 'The widget id, e.g. "block-3".',
                ),
                'force'     => array(
                    'type'        => 'boolean',
                    'description' => 'false (default): move the widget to wp_inactive_widgets, keeping its settings. true: delete it and its settings permanently.',
                    'default'     => false,
                ),
            ),
            'required'   => array( 'widget_id' ),
        );
    }

    public function precheck( array $arguments ): void {
        self::boolean_arg( $arguments, 'force', false, 'Nothing was deleted.' );
    }

    public function execute( array $arguments ) {
        $this->require_widget_support();
        $this->validate_required( $arguments, array( 'widget_id' ) );

        $widget_id = $this->validate_rest_route_segment( $arguments['widget_id'], 'widget_id' );
        $force     = self::boolean_arg( $arguments, 'force', false, 'Nothing was deleted.' );

        $data   = (array) $this->rest_request( 'DELETE', '/wp/v2/widgets/' . $widget_id, array( 'force' => $force ) );
        $listed = self::sidebars_listing( $widget_id );

        if ( $force ) {
            $kept_settings = self::settings_remain( $widget_id );
            if ( array() !== $listed && false === $kept_settings ) {
                $listed = self::without_leftover_copies( $widget_id );
            }
            if ( array() !== $listed || true === $kept_settings ) {
                throw self::unfinished( $widget_id, 'deleted', '', $listed, true === $kept_settings ? array( 'its settings are still stored' ) : array() );
            }
            $previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();
            return array(
                'id'               => $widget_id,
                'deleted'          => true,
                'previous_sidebar' => isset( $previous['sidebar'] ) ? (string) $previous['sidebar'] : '',
            );
        }

        if ( array( 'wp_inactive_widgets' ) !== $listed ) {
            throw self::unfinished( $widget_id, 'moved to the inactive widgets', 'wp_inactive_widgets', $listed );
        }
        return array(
            'id'      => $widget_id,
            'deleted' => false,
            'sidebar' => isset( $data['sidebar'] ) ? (string) $data['sidebar'] : 'wp_inactive_widgets',
        );
    }

    private static function without_leftover_copies( string $widget_id ): array {
        $row = \Easy_MCP_AI\Tools\Delete_Outcome::stored_option( 'sidebars_widgets' );
        if ( null === $row ) {
            throw self::unread( $widget_id );
        }
        $sidebars = is_array( $row[1] ) ? $row[1] : array();
        foreach ( self::sidebars_listing( $widget_id ) as $sidebar ) {
            $sidebars[ $sidebar ] = array_values( array_diff( $sidebars[ $sidebar ], array( $widget_id ) ) );
        }
        wp_set_sidebars_widgets( $sidebars );
        return self::sidebars_listing( $widget_id );
    }
}
