<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Slide extends Base_Tool {

    use Smart_Slider_Tool;

    public function get_name() {
        return 'wp_smartslider_get_slide';
    }

    public function get_description() {
        return 'Reads one Smart Slider 3 slide and its layers, as its slide editor shows them. Required: `slide_id` (from wp_smartslider_get_slider). Returns { slide_id, slider_id, title, published, dynamic, static_overlay, editable, version, layers: [{ address, type, label, name, fields?, hidden_on? }] }: `address` is the layer\'s dot-joined zero-based path in the slide (e.g. "0.0.1.2"); `type` is the item type (heading, button, text, image, …) and `label` Smart Slider\'s name for it; `fields` holds the text and link of heading, button and text layers as Smart Slider shows them (a text layer\'s tablet or mobile text only while its switch is on), the fields wp_smartslider_update_slide changes; `hidden_on` lists the devices the layer is switched off for. `version` is what wp_smartslider_update_slide sends back; `editable` is false for a slide Smart Slider last saved before 3.4, which must be saved once in its editor first. A `dynamic` slide is built by a generator: its layers, with {placeholders}, are the template of every generated slide. Requires Smart Slider\'s own permission to edit sliders, which also needs unfiltered_html.';
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
                'slide_id' => array(
                    'type'        => 'integer',
                    'description' => 'The slide id, from wp_smartslider_get_slider.',
                ),
            ),
            'required'   => array( 'slide_id' ),
        );
    }

    public function execute( array $arguments ) {
        Smart_Slider_Store::require_access( Smart_Slider_Store::EDIT );
        return self::read( Smart_Slider_Store::require_slide( $this->slide_id_argument( $arguments ) ) );
    }

    private static function read( array $slide ): array {
        $labels = Smart_Slider_Store::item_labels();
        $layers = array();
        foreach ( Smart_Slider_Store::layer_nodes( Smart_Slider_Store::layers( $slide ) ) as $node ) {
            $layers[] = self::layer( $node['address'], $node['layer'], $labels );
        }
        return array(
            'slide_id'       => (int) $slide['id'],
            'slider_id'      => (int) $slide['slider'],
            'title'          => (string) $slide['title'],
            'published'      => 1 === (int) $slide['published'],
            'dynamic'        => self::is_dynamic( $slide ),
            'static_overlay' => self::is_static_overlay( $slide ),
            'editable'       => ! Smart_Slider_Store::is_adjusted_on_load( $slide ),
            'version'        => Smart_Slider_Store::version( $slide ),
            'layers'         => $layers,
        );
    }

    private static function layer( string $address, array $layer, array $labels ): array {
        $item  = Smart_Slider_Store::item( $layer );
        $type  = Smart_Slider_Store::item_type( $item );
        $entry = array(
            'address' => $address,
            'type'    => $type,
            'label'   => $labels[ $type ] ?? $type,
            'name'    => isset( $layer['name'] ) && is_string( $layer['name'] ) ? $layer['name'] : '',
        );
        if ( isset( Smart_Slider_Store::EDITABLE[ $type ] ) ) {
            $values          = Smart_Slider_Store::upgraded_values( $type, isset( $item['values'] ) && is_array( $item['values'] ) ? $item['values'] : array() );
            $entry['fields'] = array();
            foreach ( Smart_Slider_Store::editable_fields( $type, $values ) as $field ) {
                $entry['fields'][ $field ] = isset( $values[ $field ] ) && is_scalar( $values[ $field ] ) ? (string) $values[ $field ] : '';
            }
        }
        $hidden = array();
        foreach ( array( 'desktopportrait', 'desktoplandscape', 'tabletportrait', 'tabletlandscape', 'mobileportrait', 'mobilelandscape' ) as $device ) {
            if ( isset( $layer[ $device ] ) && 0 === (int) $layer[ $device ] ) {
                $hidden[] = $device;
            }
        }
        if ( array() !== $hidden ) {
            $entry['hidden_on'] = $hidden;
        }
        return $entry;
    }
}
