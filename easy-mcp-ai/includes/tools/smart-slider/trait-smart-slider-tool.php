<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Smart_Slider_Tool {

    public function get_category() {
        return 'smart-slider-3';
    }

    protected static function smart_slider_capability( string $capability ): string {
        return null === Smart_Slider_Store::unavailable() ? $capability : 'manage_options';
    }

    public function records_database_writes(): bool {
        $annotations = $this->get_annotations();
        return empty( $annotations['readOnlyHint'] );
    }

    protected static function slider_summary( array $row ): array {
        $id = (int) $row['id'];
        return array(
            'id'        => $id,
            'title'     => (string) ( $row['title'] ?? '' ),
            'alias'     => (string) ( $row['alias'] ?? '' ),
            'type'      => (string) ( $row['type'] ?? '' ),
            'status'    => (string) ( $row['slider_status'] ?? '' ),
            'shortcode' => sprintf( '[smartslider3 slider="%d"]', $id ),
        );
    }

    protected static function is_dynamic( array $slide ): bool {
        return (int) ( $slide['generator_id'] ?? 0 ) > 0;
    }

    protected static function is_static_overlay( array $slide ): bool {
        return ! empty( Smart_Slider_Store::params( $slide )['static-slide'] );
    }

    protected function slide_id_argument( array $arguments ): int {
        $this->validate_required( $arguments, array( 'slide_id' ) );
        return $this->parse_required_id( $arguments['slide_id'], 'slide_id' );
    }

    protected function describe_slide( string $verb, array $arguments, array $capabilities ): string {
        $id    = isset( $arguments['slide_id'] ) && is_numeric( $arguments['slide_id'] ) ? (int) $arguments['slide_id'] : 0;
        $slide = null === Smart_Slider_Store::refusal( $capabilities ) ? Smart_Slider_Store::slide( $id ) : null;
        if ( null === $slide ) {
            return self::generic_description( $this, $arguments );
        }
        $slider = Smart_Slider_Store::slider( (int) $slide['slider'] );
        return sprintf( '%s Smart Slider slide #%d "%s" in slider "%s"', $verb, $id, (string) $slide['title'], null === $slider ? '#' . (int) $slide['slider'] : (string) $slider['title'] );
    }
}
