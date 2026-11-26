<?php

namespace Easy_MCP_AI\Tools\WPForms;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Move_Field extends Base_Tool {

    use WPForms_Tool;

    public function get_name() {
        return 'wp_wpforms_move_field';
    }

    public function get_description() {
        return 'Moves one field of a WPForms form to another place, as dragging it in the WPForms builder followed by Save. ' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_describe( 'wp_ability_wpforms_add_field' ) ? 'WPForms\' wpforms/add-field ability puts a new field last; call this after it to place the field. ' : '' ) . 'Required: `form_id`, `field_id`' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_describe( 'wp_ability_wpforms_get_form' ) ? ' (WPForms\' wpforms/get-form ability lists a form\'s fields)' : '' ) . ' and `position`, the place the field takes counting from 1 (1 = the first field). A form with a page break, layout or repeater field is refused: move fields there in the WPForms builder. Returns { updated, form_id, field_id, position, fields, also_changed }: updated is false when the field was already there (nothing is saved), fields is the form\'s fields in their new order as WPForms stored them, and also_changed lists what WPForms\' own save changed besides the move, as paths in the form\'s data. A move on a form holding HTML is refused for an account without unfiltered_html, since WPForms would strip it from the whole form. Requires the capability WPForms requires to manage forms (manage_options by default).';
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
                'form_id'  => array(
                    'type'        => 'integer',
                    'description' => 'The form ID.',
                ),
                'field_id' => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the field to move' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_describe( 'wp_ability_wpforms_get_form' ) ? ', as WPForms\' wpforms/get-form ability lists it' : '' ) . ' (WPForms numbers fields from 0).',
                ),
                'position' => array(
                    'type'        => 'integer',
                    'description' => 'The place the field takes, counting from 1 (1 = the first field, the number of fields = the last).',
                ),
            ),
            'required'   => array( 'form_id', 'field_id', 'position' ),
        );
    }

    public function describe( array $arguments ) {
        $preview = self::previewed_field( $arguments );
        if ( null === $preview || ! isset( $arguments['position'] ) || ! is_numeric( $arguments['position'] ) ) {
            return self::generic_description( $this, $arguments );
        }
        $field = $preview['field'];
        return sprintf( 'Move the %s field #%d "%s" of WPForms form #%d "%s" to position %d', $field['type'], $field['id'], $field['label'], (int) $preview['post']->ID, $preview['post']->post_title, (int) $arguments['position'] );
    }

    public function precheck( array $arguments ): void {
        $this->plan( $arguments );
    }

    public function execute( array $arguments ) {
        return WPForms_Store::locked(
            $this->form_id_argument( $arguments ),
            static::lock_wait(),
            function ( string $lock ) use ( $arguments ): array {
                return $this->move( $arguments, $lock );
            }
        );
    }

    private function move( array $arguments, string $lock ): array {
        $plan  = $this->plan( $arguments );
        $reply = array(
            'updated'      => false,
            'form_id'      => $plan['form_id'],
            'field_id'     => $plan['field_id'],
            'position'     => $plan['position'],
            'fields'       => WPForms_Store::summaries( $plan['current'] ),
            'also_changed' => array(),
        );
        if ( $plan['fields'] === $plan['current'] ) {
            return $reply;
        }
        $saved = WPForms_Store::save_fields( $plan['form_id'], $plan['data'], $plan['fields'], $lock );
        return array_merge(
            $reply,
            array(
                'updated'      => true,
                'fields'       => WPForms_Store::summaries( $saved['fields'] ),
                'also_changed' => $saved['also_changed'],
            )
        );
    }

    private function plan( array $arguments ): array {
        $form_id  = $this->form_id_argument( $arguments );
        $field_id = $this->field_id_argument( $arguments );
        $this->validate_required( $arguments, array( 'position' ) );
        $position = $this->parse_required_id( $arguments['position'], 'position' );
        $form     = WPForms_Store::require_form( $form_id );
        $fields   = WPForms_Store::fields( $form['data'] );
        WPForms_Store::require_field( $fields, $form_id, $field_id );
        foreach ( $fields as $id => $field ) {
            $type = WPForms_Store::summary( $id, $field )['type'];
            if ( in_array( $type, WPForms_Store::STRUCTURE_TYPES, true ) ) {
                throw new \InvalidArgumentException( sprintf( 'Form %d has a %s field (%d), which other fields depend on for their place. Move its fields in the WPForms builder. %s', $form_id, $type, $id, WPForms_Store::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
        if ( $position > count( $fields ) ) {
            throw new \InvalidArgumentException( sprintf( 'position must be from 1 to %d, the number of fields form %d has. %s', count( $fields ), $form_id, WPForms_Store::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $others = $fields;
        unset( $others[ $field_id ] );
        $ids = array_keys( $others );
        array_splice( $ids, $position - 1, 0, array( $field_id ) );
        $moved = array();
        foreach ( $ids as $id ) {
            $moved[ $id ] = $fields[ $id ];
        }
        if ( $moved !== $fields ) {
            WPForms_Store::require_savable( $form_id, $form['data'] );
        }
        return array(
            'form_id'  => $form_id,
            'field_id' => $field_id,
            'position' => $position,
            'data'     => $form['data'],
            'current'  => $fields,
            'fields'   => $moved,
        );
    }
}
