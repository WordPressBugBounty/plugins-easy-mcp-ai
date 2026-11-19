<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class List_Sliders extends Base_Tool {

    use Smart_Slider_Tool;

    public function get_name() {
        return 'wp_smartslider_list_sliders';
    }

    public function get_description() {
        return 'Lists the Smart Slider 3 sliders and groups, as its dashboard does. Optional: `status` (published, the default, or trash). Returns { sliders: [{ id, title, alias, type, status, shortcode, slide_count | slider_count }], total }: `type` is the slider type (simple, block, carousel, …) or group, `slide_count` a slider\'s slides, `slider_count` a group\'s sliders, and `shortcode` places it in content. Read one with wp_smartslider_get_slider. Requires Smart Slider\'s own permission to edit sliders (and to delete, for the trash), which also needs unfiltered_html.';
    }

    public function get_required_capability() {
        return self::smart_slider_capability( 'smartslider_edit' );
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
                'status' => array(
                    'type'        => 'string',
                    'enum'        => array( 'published', 'trash' ),
                    'description' => 'Which sliders: published (default) or trash.',
                ),
            ),
            'required'   => array(),
        );
    }

    public function execute( array $arguments ) {
        $status = $arguments['status'] ?? 'published';
        $this->refuse_unless_one_of( 'status', $status, array( 'published', 'trash' ), $status, 'published or trash' );
        Smart_Slider_Store::require_access( 'trash' === $status ? Smart_Slider_Store::DELETE : Smart_Slider_Store::EDIT );
        $sliders = array();
        foreach ( Smart_Slider_Store::sliders( $status ) as $row ) {
            $count     = 'group' === ( $row['type'] ?? '' ) ? 'slider_count' : 'slide_count';
            $sliders[] = self::slider_summary( $row ) + array( $count => (int) ( $row['slides'] ?? 0 ) );
        }
        return array(
            'sliders' => $sliders,
            'total'   => count( $sliders ),
        );
    }
}
