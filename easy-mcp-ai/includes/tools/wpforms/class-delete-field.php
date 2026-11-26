<?php

namespace Easy_MCP_AI\Tools\WPForms;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delete_Field extends Base_Tool {

    use WPForms_Tool;

    public function get_name() {
        return 'wp_wpforms_delete_field';
    }

    public function get_description() {
        return 'Deletes one field from a WPForms form, as Delete on the field in the WPForms builder followed by Save. Required: `form_id` and `field_id`' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_describe( 'wp_ability_wpforms_get_form' ) ? ' (WPForms\' wpforms/get-form ability lists a form\'s fields)' : '' ) . '. The other fields keep their IDs. Entries already submitted keep the deleted field\'s values. A field WPForms marks as not deletable, a page break, layout or repeater field, or a field inside a layout or repeater is refused: change those in the WPForms builder. Returns { deleted, form_id, field: { id, type, label }, fields, also_changed }: fields are the form\'s fields left, in order, as WPForms stored them; also_changed lists what WPForms\' own save changed besides the delete (such as an email field\'s lists it fills in), as paths in the form\'s data. A form holding HTML is refused for an account without unfiltered_html, since WPForms would strip it from the whole form. Requires the capability WPForms requires to manage forms (manage_options by default).';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
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
                    'description' => 'The ID of the field to delete' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_describe( 'wp_ability_wpforms_get_form' ) ? ', as WPForms\' wpforms/get-form ability lists it' : '' ) . ' (WPForms numbers fields from 0).',
                ),
            ),
            'required'   => array( 'form_id', 'field_id' ),
        );
    }

    public function describe( array $arguments ) {
        $preview = self::previewed_field( $arguments );
        if ( null === $preview ) {
            return self::generic_description( $this, $arguments );
        }
        $field = $preview['field'];
        return sprintf( 'Delete the %s field #%d "%s" from WPForms form #%d "%s"', $field['type'], $field['id'], $field['label'], (int) $preview['post']->ID, $preview['post']->post_title );
    }

    public function precheck( array $arguments ): void {
        $this->plan( $arguments );
    }

    public function execute( array $arguments ) {
        return WPForms_Store::locked(
            $this->form_id_argument( $arguments ),
            static::lock_wait(),
            function ( string $lock ) use ( $arguments ): array {
                $plan  = $this->plan( $arguments );
                $saved = WPForms_Store::save_fields( $plan['form_id'], $plan['data'], $plan['fields'], $lock );
                return array(
                    'deleted'      => true,
                    'form_id'      => $plan['form_id'],
                    'field'        => $plan['field'],
                    'fields'       => WPForms_Store::summaries( $saved['fields'] ),
                    'also_changed' => $saved['also_changed'],
                );
            }
        );
    }

    private function plan( array $arguments ): array {
        $form_id  = $this->form_id_argument( $arguments );
        $field_id = $this->field_id_argument( $arguments );
        $form     = WPForms_Store::require_form( $form_id );
        $fields   = WPForms_Store::fields( $form['data'] );
        $field    = WPForms_Store::require_field( $fields, $form_id, $field_id );
        if ( isset( $field['meta'] ) && is_array( $field['meta'] ) && array_key_exists( 'delete', $field['meta'] ) && false === $field['meta']['delete'] ) {
            throw new \InvalidArgumentException( sprintf( 'WPForms marks field %d of form %d as not deletable, and its builder refuses to delete it. %s', $field_id, $form_id, WPForms_Store::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $structure = WPForms_Store::structure_refusal( $fields, $field_id );
        if ( null !== $structure ) {
            throw new \InvalidArgumentException( $structure . ' ' . WPForms_Store::NOTHING_CHANGED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $left = $fields;
        unset( $left[ $field_id ] );
        WPForms_Store::require_savable( $form_id, $form['data'] );
        return array(
            'form_id' => $form_id,
            'data'    => $form['data'],
            'field'   => WPForms_Store::summary( $field_id, $field ),
            'fields'  => $left,
        );
    }
}
