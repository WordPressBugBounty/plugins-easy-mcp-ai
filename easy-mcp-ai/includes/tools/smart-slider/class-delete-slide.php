<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delete_Slide extends Base_Tool {

    use Smart_Slider_Tool;

    public function get_name() {
        return 'wp_smartslider_delete_slide';
    }

    public function get_description() {
        return 'Permanently deletes a Smart Slider 3 slide, as Delete in its slide list does: Smart Slider keeps no trash for slides, and a dynamic slide\'s generator is deleted with its last slide. Required: `slide_id` (from wp_smartslider_get_slider). To hide a slide instead, set `published` false with wp_smartslider_update_slide. Returns { deleted, slide_id, slider_id, title }. Requires Smart Slider\'s own permissions to edit sliders and to delete, which also need unfiltered_html.';
    }

    public function get_required_capability() {
        return self::smart_slider_capability( 'smartslider_delete' );
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'slide_id' => array(
                    'type'        => 'integer',
                    'description' => 'The slide to delete, from wp_smartslider_get_slider.',
                ),
            ),
            'required'   => array( 'slide_id' ),
        );
    }

    public function describe( array $arguments ) {
        return $this->describe_slide( 'Permanently delete', $arguments, Smart_Slider_Store::DELETE );
    }

    public function precheck( array $arguments ): void {
        Smart_Slider_Store::require_access( Smart_Slider_Store::DELETE );
        Smart_Slider_Store::require_slide( $this->slide_id_argument( $arguments ) );
    }

    public function execute( array $arguments ) {
        Smart_Slider_Store::require_access( Smart_Slider_Store::DELETE );
        $slide = Smart_Slider_Store::require_slide( $this->slide_id_argument( $arguments ) );
        Smart_Slider_Store::delete( (int) $slide['id'] );
        if ( null !== Smart_Slider_Store::slide( (int) $slide['id'] ) ) {
            throw new \RuntimeException( 'Smart Slider 3 did not delete the slide. Read the slider again with wp_smartslider_get_slider.' );
        }
        return array(
            'deleted'   => true,
            'slide_id'  => (int) $slide['id'],
            'slider_id' => (int) $slide['slider'],
            'title'     => (string) $slide['title'],
        );
    }
}
