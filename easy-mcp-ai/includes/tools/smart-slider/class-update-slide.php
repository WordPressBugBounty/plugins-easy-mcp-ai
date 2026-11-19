<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Slide extends Base_Tool {

    use Smart_Slider_Tool;

    const NOTHING_CHANGED = 'Nothing was changed.';

    public function get_name() {
        return 'wp_smartslider_update_slide';
    }

    public function get_description() {
        $fields = array();
        foreach ( Smart_Slider_Store::EDITABLE as $type => $names ) {
            $fields[] = $type . ': ' . implode( ', ', $names );
        }
        return 'Changes a Smart Slider 3 slide as its slide editor saves it: the text and links of heading, button and text layers, the slide\'s title, and whether it is published (false hides it). Required: `slide_id` and `version` (both from wp_smartslider_get_slide), and at least one of `title`, `published` or `layers`: [{ address, fields: { name: value } }], the address from wp_smartslider_get_slide and these fields, each a string stored as sent (' . implode( '; ', $fields ) . '). An unknown address or field, a tablet or mobile text whose switch is off, or a version that is no longer the slide\'s, refuses the call and nothing is changed; so does a slide Smart Slider last saved before 3.4 (editable: false on the read). Smart Slider rebuilds the slider on its next view; a page cache may still serve the old page (wp_clear_cache). Returns { updated, changed: [{ address?, field, before, after }], version }: updated is false when every value sent was already stored. Editing a dynamic slide changes the template of every slide its generator builds. Requires Smart Slider\'s own permission to edit sliders, which also needs unfiltered_html.';
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
                'slide_id'  => array(
                    'type'        => 'integer',
                    'description' => 'The slide id, from wp_smartslider_get_slider.',
                ),
                'version'   => array(
                    'type'        => 'string',
                    'description' => 'The slide\'s version, from wp_smartslider_get_slide.',
                ),
                'title'     => array(
                    'type'        => 'string',
                    'description' => 'The slide\'s new title.',
                ),
                'published' => array(
                    'type'        => 'boolean',
                    'description' => 'true shows the slide, false hides it.',
                ),
                'layers'    => array(
                    'type'        => 'array',
                    'description' => 'Layer edits: each layer\'s address and the fields to set.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'address' => array(
                                'type'        => 'string',
                                'description' => 'The layer\'s address, e.g. "0.0.1.2", from wp_smartslider_get_slide.',
                            ),
                            'fields'  => array(
                                'type'        => 'object',
                                'description' => 'Field name => new text, for the fields wp_smartslider_get_slide lists for that layer.',
                            ),
                        ),
                        'required'   => array( 'address', 'fields' ),
                    ),
                ),
            ),
            'required'   => array( 'slide_id', 'version' ),
        );
    }

    public function describe( array $arguments ) {
        return $this->describe_slide( 'Change', $arguments, Smart_Slider_Store::EDIT );
    }

    public function precheck( array $arguments ): void {
        $this->plan( $arguments );
    }

    public function execute( array $arguments ) {
        $plan = $this->plan( $arguments );
        if ( array() === $plan['changed'] ) {
            return array(
                'updated' => false,
                'changed' => array(),
                'version' => Smart_Slider_Store::version( $plan['slide'] ),
            );
        }
        Smart_Slider_Store::save( $plan['slide'], $plan['fields'], $plan['layers'] );
        $after = Smart_Slider_Store::require_slide( (int) $plan['slide']['id'] );
        self::require_stored( $after, $plan['changed'] );
        return array(
            'updated' => true,
            'changed' => $plan['changed'],
            'version' => Smart_Slider_Store::version( $after ),
        );
    }

    private function plan( array $arguments ): array {
        Smart_Slider_Store::require_access( Smart_Slider_Store::EDIT );
        $slide = Smart_Slider_Store::require_slide( $this->slide_id_argument( $arguments ) );
        $this->validate_required( $arguments, array( 'version' ) );
        self::refuse_wrong_shapes( $arguments );
        if ( ! is_string( $arguments['version'] ) || Smart_Slider_Store::version( $slide ) !== $arguments['version'] ) {
            throw new \RuntimeException( 'The slide was changed since that version was read. Read it again with wp_smartslider_get_slide. ' . self::NOTHING_CHANGED );
        }
        if ( Smart_Slider_Store::is_adjusted_on_load( $slide ) ) {
            throw new \RuntimeException( 'Smart Slider 3 adjusts this slide, saved before its 3.4, each time it loads it; a save here would stop that. Save it once in Smart Slider\'s editor, then retry. ' . self::NOTHING_CHANGED );
        }
        if ( ! isset( $arguments['title'] ) && ! isset( $arguments['published'] ) && ! isset( $arguments['layers'] ) ) {
            throw new \InvalidArgumentException( 'Send `title`, `published` or `layers`. ' . self::NOTHING_CHANGED );
        }
        $fields  = array();
        $changed = array();
        if ( isset( $arguments['title'] ) ) {
            if ( $arguments['title'] !== (string) $slide['title'] ) {
                $fields['title'] = $arguments['title'];
                $changed[]       = array(
                    'field'  => 'title',
                    'before' => (string) $slide['title'],
                    'after'  => $arguments['title'],
                );
            }
        }
        if ( isset( $arguments['published'] ) ) {
            $published = self::boolean_arg( $arguments, 'published', true, self::NOTHING_CHANGED );
            if ( $published !== ( 1 === (int) $slide['published'] ) ) {
                $fields['published'] = $published ? 1 : 0;
                $changed[]           = array(
                    'field'  => 'published',
                    'before' => ! $published,
                    'after'  => $published,
                );
            }
        }
        $layers = Smart_Slider_Store::layers( $slide );
        if ( isset( $arguments['layers'] ) ) {
            $changed = array_merge( $changed, self::apply_layer_edits( $layers, $arguments['layers'] ) );
        }
        return array(
            'slide'   => $slide,
            'fields'  => $fields,
            'layers'  => $layers,
            'changed' => $changed,
        );
    }

    private static function refuse_wrong_shapes( array $arguments ): void {
        if ( isset( $arguments['title'] ) && ! is_string( $arguments['title'] ) ) {
            throw new \InvalidArgumentException( '`title` must be text. ' . self::NOTHING_CHANGED );
        }
        self::boolean_arg( $arguments, 'published', true, self::NOTHING_CHANGED );
        if ( isset( $arguments['layers'] ) && ( ! is_array( $arguments['layers'] ) || array() === $arguments['layers'] || array_values( $arguments['layers'] ) !== $arguments['layers'] ) ) {
            throw new \InvalidArgumentException( '`layers` must be a list of { address, fields }. ' . self::NOTHING_CHANGED );
        }
    }

    private static function apply_layer_edits( array &$layers, array $edits ): array {
        $items = array();
        foreach ( $edits as $edit ) {
            $address = is_array( $edit ) && isset( $edit['address'] ) && is_string( $edit['address'] ) ? $edit['address'] : null;
            if ( null === $address || ! isset( $edit['fields'] ) || ! is_array( $edit['fields'] ) || array() === $edit['fields'] ) {
                throw new \InvalidArgumentException( 'Each `layers` entry needs an `address` and a non-empty `fields` object. ' . self::NOTHING_CHANGED );
            }
            if ( ! isset( $items[ $address ] ) ) {
                $keys              = self::item_keys( $layers, $address );
                $item              = self::at( $layers, $keys );
                $type              = Smart_Slider_Store::item_type( $item );
                $values            = Smart_Slider_Store::upgraded_values( $type, isset( $item['values'] ) && is_array( $item['values'] ) ? $item['values'] : array() );
                $items[ $address ] = array(
                    'keys'   => array_merge( $keys, array( 'values' ) ),
                    'type'   => $type,
                    'shown'  => $values,
                    'values' => $values,
                    'sent'   => array(),
                );
            }
            foreach ( $edit['fields'] as $field => $value ) {
                $field = (string) $field;
                self::refuse_unless_editable( $address, $items[ $address ]['type'], $items[ $address ]['shown'], $field );
                if ( ! is_string( $value ) ) {
                    throw new \InvalidArgumentException( sprintf( 'Layer %s field %s must be text. %s', $address, $field, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                if ( isset( $items[ $address ]['sent'][ $field ] ) ) {
                    throw new \InvalidArgumentException( sprintf( 'Layer %s field %s is sent twice. %s', $address, $field, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                $items[ $address ]['sent'][ $field ]   = true;
                $items[ $address ]['values'][ $field ] = $value;
            }
        }
        $changed = array();
        foreach ( $items as $address => $item ) {
            $item_changed = array();
            foreach ( array_keys( $item['sent'] ) as $field ) {
                $before = isset( $item['shown'][ $field ] ) && is_scalar( $item['shown'][ $field ] ) ? (string) $item['shown'][ $field ] : '';
                if ( $before !== $item['values'][ $field ] ) {
                    $item_changed[] = array(
                        'address' => (string) $address,
                        'field'   => $field,
                        'before'  => $before,
                        'after'   => $item['values'][ $field ],
                    );
                }
            }
            if ( array() !== $item_changed ) {
                self::put( $layers, $item['keys'], $item['values'] );
                $changed = array_merge( $changed, $item_changed );
            }
        }
        return $changed;
    }

    private static function refuse_unless_editable( string $address, string $type, array $values, string $field ): void {
        $editable = Smart_Slider_Store::editable_fields( $type, $values );
        if ( in_array( $field, $editable, true ) ) {
            return;
        }
        if ( isset( Smart_Slider_Store::DEVICE_SWITCHES[ $field ] ) && in_array( $field, Smart_Slider_Store::EDITABLE[ $type ] ?? array(), true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Layer %s shows no %s: Smart Slider\'s %s switch is off for it, which its editor turns on. %s', $address, $field, Smart_Slider_Store::DEVICE_SWITCHES[ $field ], self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        throw new \InvalidArgumentException( sprintf( 'Layer %s is a %s layer, whose editable fields are: %s. %s', $address, '' === $type ? 'layer without an item' : $type, array() === $editable ? 'none' : implode( ', ', $editable ), self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    private static function item_keys( array $layers, string $address ): array {
        $keys  = array();
        $level = $layers;
        $node  = array();
        foreach ( explode( '.', $address ) as $depth => $part ) {
            if ( $depth > 0 ) {
                $children = Smart_Slider_Store::children( $node );
                $level    = null === $children ? array() : $node[ $children ];
                if ( null !== $children ) {
                    $keys[] = $children;
                }
            }
            if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]*)\z/', $part ) || ! isset( $level[ (int) $part ] ) || ! is_array( $level[ (int) $part ] ) ) {
                throw new \InvalidArgumentException( sprintf( 'The slide has no layer at %s. wp_smartslider_get_slide lists the layers\' addresses. %s', $address, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $keys[] = (int) $part;
            $node   = $level[ (int) $part ];
        }
        $item_path = Smart_Slider_Store::item_path( $node );
        if ( array() === $item_path ) {
            throw new \InvalidArgumentException( sprintf( '%s is not a layer with content (rows, columns and content areas have no text). wp_smartslider_get_slide lists the layers. %s', $address, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $keys = array_merge( $keys, $item_path );
        $item = self::at( $layers, $keys );
        if ( isset( $item['values'] ) && ! is_array( $item['values'] ) ) {
            throw new \InvalidArgumentException( sprintf( 'Layer %s stores its values in a form Smart Slider 3 did not write, so it is not changed here. %s', $address, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $keys;
    }

    private static function at( array $tree, array $keys ) {
        foreach ( $keys as $key ) {
            if ( ! is_array( $tree ) || ! array_key_exists( $key, $tree ) ) {
                return null;
            }
            $tree = $tree[ $key ];
        }
        return $tree;
    }

    private static function put( array &$tree, array $keys, array $value ): void {
        $ref = &$tree;
        foreach ( $keys as $key ) {
            $ref = &$ref[ $key ];
        }
        $ref = $value;
        unset( $ref );
    }

    private static function require_stored( array $after, array $changed ): void {
        $layers = Smart_Slider_Store::layers( $after );
        foreach ( $changed as $change ) {
            if ( isset( $change['address'] ) ) {
                $item   = self::at( $layers, self::item_keys( $layers, $change['address'] ) );
                $stored = is_array( $item ) ? ( $item['values'][ $change['field'] ] ?? null ) : null;
            } elseif ( 'published' === $change['field'] ) {
                $stored = 1 === (int) $after['published'];
            } else {
                $stored = (string) $after['title'];
            }
            if ( $stored !== $change['after'] ) {
                throw new \RuntimeException( sprintf( 'Smart Slider 3 did not store the new %s%s, so the slide may be unchanged. Read it again with wp_smartslider_get_slide.', $change['field'], isset( $change['address'] ) ? ' of layer ' . $change['address'] : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
    }
}
