<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Duplicate_Slide extends Base_Tool {

    use Smart_Slider_Tool;

    public function get_name() {
        return 'wp_smartslider_duplicate_slide';
    }

    public function get_description() {
        return 'Copies a Smart Slider 3 slide into the same slider, next to it, as Duplicate in its slide list does: the copy takes the original\'s place in the order, its layers get new ids, and a dynamic slide\'s generator is copied too. Required: `slide_id` (from wp_smartslider_get_slider). Returns { duplicated, slide_id (the copy), source_slide_id, slider_id, title, published }. Change the copy with wp_smartslider_update_slide. Requires Smart Slider\'s own permission to edit sliders, which also needs unfiltered_html.';
    }

    public function get_required_capability() {
        return self::smart_slider_capability( 'smartslider_edit' );
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'slide_id' => array(
                    'type'        => 'integer',
                    'description' => 'The slide to copy, from wp_smartslider_get_slider.',
                ),
            ),
            'required'   => array( 'slide_id' ),
        );
    }

    public function describe( array $arguments ) {
        return $this->describe_slide( 'Duplicate', $arguments, Smart_Slider_Store::EDIT );
    }

    public function precheck( array $arguments ): void {
        Smart_Slider_Store::require_access( Smart_Slider_Store::EDIT );
        Smart_Slider_Store::require_slide( $this->slide_id_argument( $arguments ) );
    }

    public function execute( array $arguments ) {
        Smart_Slider_Store::require_access( Smart_Slider_Store::EDIT );
        $slide = Smart_Slider_Store::require_slide( $this->slide_id_argument( $arguments ) );
        $copy  = Smart_Slider_Store::slide( Smart_Slider_Store::duplicate( (int) $slide['id'] ) );
        if ( null === $copy || (int) $copy['id'] === (int) $slide['id'] ) {
            throw new \RuntimeException( 'Smart Slider 3 did not create the copy. Read the slider again with wp_smartslider_get_slider.' );
        }
        return array(
            'duplicated'      => true,
            'slide_id'        => (int) $copy['id'],
            'source_slide_id' => (int) $slide['id'],
            'slider_id'       => (int) $copy['slider'],
            'title'           => (string) $copy['title'],
            'published'       => 1 === (int) $copy['published'],
        );
    }
}
