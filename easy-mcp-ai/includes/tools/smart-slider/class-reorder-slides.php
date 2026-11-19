<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Reorder_Slides extends Base_Tool {

    use Smart_Slider_Tool;

    const NOTHING_CHANGED = 'Nothing was changed.';

    const LISTED_IN_REFUSAL = 60;

    public function get_name() {
        return 'wp_smartslider_reorder_slides';
    }

    public function get_description() {
        return 'Sets the order of a Smart Slider 3 slider\'s slides, as dragging them in its slide list does. Required: `slider_id` and `slide_ids`, every slide of the slider exactly once, first to last (wp_smartslider_get_slider lists them). Returns { updated, slider_id, slide_ids }: updated is false when the slides were already in that order. Smart Slider rebuilds the slider on its next view; a page cache may still serve the old page (wp_clear_cache). Requires Smart Slider\'s own permission to edit sliders, which also needs unfiltered_html.';
    }

    public function get_required_capability() {
        return self::smart_slider_capability( 'smartslider_edit' );
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'slider_id' => array(
                    'type'        => 'integer',
                    'description' => 'The slider id, from wp_smartslider_list_sliders.',
                ),
                'slide_ids' => array(
                    'type'        => 'array',
                    'description' => 'Every slide id of the slider, in the new order.',
                    'items'       => array( 'type' => 'integer' ),
                ),
            ),
            'required'   => array( 'slider_id', 'slide_ids' ),
        );
    }

    public function describe( array $arguments ) {
        $id     = isset( $arguments['slider_id'] ) && is_numeric( $arguments['slider_id'] ) ? (int) $arguments['slider_id'] : 0;
        $slider = null === Smart_Slider_Store::refusal( Smart_Slider_Store::EDIT ) ? Smart_Slider_Store::slider( $id ) : null;
        if ( null === $slider ) {
            return self::generic_description( $this, $arguments );
        }
        return sprintf( 'Reorder the slides of Smart Slider slider #%d "%s"', $id, (string) $slider['title'] );
    }

    public function precheck( array $arguments ): void {
        $this->plan( $arguments );
    }

    public function execute( array $arguments ) {
        $plan = $this->plan( $arguments );
        if ( $plan['order'] === $plan['current'] ) {
            return array(
                'updated'   => false,
                'slider_id' => $plan['slider_id'],
                'slide_ids' => $plan['current'],
            );
        }
        Smart_Slider_Store::order( $plan['slider_id'], $plan['order'] );
        $after = self::slide_ids( $plan['slider_id'] );
        if ( $after !== $plan['order'] ) {
            throw new \RuntimeException( 'Smart Slider 3 did not store the new order. Read the slider again with wp_smartslider_get_slider.' );
        }
        return array(
            'updated'   => true,
            'slider_id' => $plan['slider_id'],
            'slide_ids' => $after,
        );
    }

    private function plan( array $arguments ): array {
        Smart_Slider_Store::require_access( Smart_Slider_Store::EDIT );
        $this->validate_required( $arguments, array( 'slider_id', 'slide_ids' ) );
        $id     = $this->parse_required_id( $arguments['slider_id'], 'slider_id' );
        $slider = Smart_Slider_Store::slider( $id );
        if ( null === $slider || 'group' === ( $slider['type'] ?? '' ) ) {
            throw new \InvalidArgumentException( sprintf( 'Smart Slider 3 has no slider %d with slides. wp_smartslider_list_sliders lists them. %s', $id, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( ! is_array( $arguments['slide_ids'] ) ) {
            throw new \InvalidArgumentException( '`slide_ids` must be a list of slide ids. ' . self::NOTHING_CHANGED );
        }
        $order = array();
        foreach ( array_values( $arguments['slide_ids'] ) as $value ) {
            $order[] = $this->parse_required_id( $value, 'slide_ids entry' );
        }
        $current = self::slide_ids( $id );
        foreach ( $order as $slide_id ) {
            if ( ! in_array( $slide_id, $current, true ) ) {
                throw new \InvalidArgumentException( sprintf( 'Slider %d has no slide %d. wp_smartslider_get_slider lists its slides. %s', $id, $slide_id, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
        $sorted = $order;
        $all    = $current;
        sort( $sorted );
        sort( $all );
        if ( $sorted !== $all ) {
            $listed = array() === $current ? 'none' : implode( ', ', $current );
            $slides = strlen( $listed ) > self::LISTED_IN_REFUSAL ? sprintf( 'its %d slides, which wp_smartslider_get_slider lists', count( $current ) ) : 'its slides are ' . $listed;
            throw new \InvalidArgumentException( sprintf( '`slide_ids` must list every slide of slider %d exactly once; %s. %s', $id, $slides, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return array(
            'slider_id' => $id,
            'current'   => $current,
            'order'     => $order,
        );
    }

    private static function slide_ids( int $slider_id ): array {
        return array_map(
            static function ( array $slide ): int {
                return (int) $slide['id'];
            },
            Smart_Slider_Store::slides( $slider_id )
        );
    }
}
