<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Slider extends Base_Tool {

    use Smart_Slider_Tool;

    const TEXTS_PER_SLIDE = 5;
    const TEXT_LENGTH     = 100;

    const TEXT_FIELDS = array(
        'heading' => 'heading',
        'button'  => 'content',
        'text'    => 'content',
    );

    public function get_name() {
        return 'wp_smartslider_get_slider';
    }

    public function get_description() {
        return 'Reads one Smart Slider 3 slider and its slides, in their order. Required: `slider_id` (from wp_smartslider_list_sliders). Returns { slider: { id, title, alias, type, status, shortcode }, slides: [{ slide_id, title, published, first, dynamic, static_overlay, publish_up?, publish_down?, layer_count, texts }] }: `texts` are the first heading, button and text layers\' text, shortened; `dynamic` slides are built by a generator, so their layers are the template of every generated slide; a `static_overlay` slide is shown over every slide. For a group, `sliders` lists its sliders instead of `slides`. Read a slide\'s layers with wp_smartslider_get_slide. Requires Smart Slider\'s own permission to edit sliders, which also needs unfiltered_html.';
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
                'slider_id' => array(
                    'type'        => 'integer',
                    'description' => 'The slider or group id, from wp_smartslider_list_sliders.',
                ),
            ),
            'required'   => array( 'slider_id' ),
        );
    }

    public function execute( array $arguments ) {
        Smart_Slider_Store::require_access( Smart_Slider_Store::EDIT );
        $this->validate_required( $arguments, array( 'slider_id' ) );
        $id  = $this->parse_required_id( $arguments['slider_id'], 'slider_id' );
        $row = Smart_Slider_Store::slider( $id );
        if ( null === $row ) {
            throw new \InvalidArgumentException( sprintf( 'Smart Slider 3 has no slider %d. wp_smartslider_list_sliders lists them.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $reply = array( 'slider' => self::slider_summary( $row ) );
        if ( 'group' === ( $row['type'] ?? '' ) ) {
            $reply['sliders'] = array_map( array( self::class, 'slider_summary' ), Smart_Slider_Store::group_members( $id ) );
            return $reply;
        }
        $reply['slides'] = array_map( array( self::class, 'slide_summary' ), Smart_Slider_Store::slides( $id ) );
        return $reply;
    }

    private static function slide_summary( array $slide ): array {
        $layers  = Smart_Slider_Store::layer_nodes( Smart_Slider_Store::layers( $slide ) );
        $summary = array(
            'slide_id'       => (int) $slide['id'],
            'title'          => (string) $slide['title'],
            'published'      => 1 === (int) $slide['published'],
            'first'          => 1 === (int) ( $slide['first'] ?? 0 ),
            'dynamic'        => self::is_dynamic( $slide ),
            'static_overlay' => self::is_static_overlay( $slide ),
        );
        foreach ( array( 'publish_up', 'publish_down' ) as $column ) {
            $value = (string) ( $slide[ $column ] ?? '' );
            if ( '' !== $value && 0 !== strpos( $value, '1970-01-01' ) && 0 !== strpos( $value, '0000-00-00' ) ) {
                $summary[ $column ] = $value;
            }
        }
        $summary['layer_count'] = count( $layers );
        $summary['texts']       = self::texts( $layers );
        return $summary;
    }

    private static function texts( array $layers ): array {
        $texts = array();
        foreach ( $layers as $node ) {
            $item = Smart_Slider_Store::item( $node['layer'] );
            $text = self::TEXT_FIELDS[ Smart_Slider_Store::item_type( $item ) ] ?? null;
            if ( null === $text || ! isset( $item['values'][ $text ] ) || ! is_string( $item['values'][ $text ] ) ) {
                continue;
            }
            $plain = trim( wp_strip_all_tags( $item['values'][ $text ] ) );
            if ( '' !== $plain ) {
                $texts[] = mb_strlen( $plain ) > self::TEXT_LENGTH ? mb_substr( $plain, 0, self::TEXT_LENGTH ) . '…' : $plain;
            }
            if ( count( $texts ) >= self::TEXTS_PER_SLIDE ) {
                break;
            }
        }
        return $texts;
    }
}
