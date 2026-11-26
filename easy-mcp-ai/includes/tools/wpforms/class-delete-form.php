<?php

namespace Easy_MCP_AI\Tools\WPForms;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Delete_Outcome;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delete_Form extends Base_Tool {

    use WPForms_Tool;

    public function get_name() {
        return 'wp_wpforms_delete_form';
    }

    public function get_description() {
        return 'Moves a WPForms form (or form template) to the trash, or with `force: true` deletes it permanently, as Trash and Delete Permanently under WPForms › All Forms. Required: `form_id`' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_describe( 'wp_ability_wpforms_list_forms' ) ? ' (WPForms\' wpforms/list-forms ability lists the forms)' : '' ) . '. A trashed form can be restored '
            . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_describe( 'wp_wpforms_restore_form' ) ? 'with wp_wpforms_restore_form or ' : '' )
            . 'under WPForms › All Forms › Trash; a deleted one cannot, and WPForms Pro deletes its entries with it. Pages and widgets that embed the form show nothing in its place: `locations` lists those WPForms found, but for posts this account cannot read, which `locations_withheld` counts. Returns { status: "trash" or "deleted", form: { id, title }, locations, locations_withheld }. Requires the capability WPForms requires to manage forms (manage_options by default).';
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
                'form_id' => array(
                    'type'        => 'integer',
                    'description' => 'The form ID.',
                ),
                'force'   => array(
                    'type'        => 'boolean',
                    'description' => 'true deletes the form permanently; false or absent moves it to the trash.',
                ),
            ),
            'required'   => array( 'form_id' ),
        );
    }

    public function describe( array $arguments ) {
        try {
            $plan = $this->plan( $arguments );
        } catch ( \RuntimeException | \InvalidArgumentException $e ) {
            return self::generic_description( $this, $arguments );
        }
        return sprintf( '%s WPForms form #%d "%s"', $plan['force'] ? 'Permanently delete' : 'Move to the trash', (int) $plan['post']->ID, $plan['post']->post_title );
    }

    public function precheck( array $arguments ): void {
        $this->plan( $arguments );
    }

    public function execute( array $arguments ) {
        return WPForms_Store::locked(
            $this->form_id_argument( $arguments ),
            static::lock_wait(),
            function ( string $lock ) use ( $arguments ): array {
                return $this->remove( $arguments, $lock );
            }
        );
    }

    private function remove( array $arguments, string $lock ): array {
        $plan      = $this->plan( $arguments );
        $id        = (int) $plan['post']->ID;
        $label     = 'form ' . $id;
        $locations = WPForms_Store::locations( $id );
        $handler   = WPForms_Store::handler();
        WPForms_Store::require_lock( $id, $lock );
        if ( $plan['force'] ) {
            if ( ! $handler->delete( array( $id ) ) ) {
                throw new \RuntimeException( sprintf( 'WPForms did not delete form %d. %s', $id, Delete_Outcome::NOTHING_DELETED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            Delete_Outcome::require_post_gone( $id, $label );
            $status = Delete_Outcome::DELETED;
        } else {
            $before = (string) $plan['post']->post_status;
            $handler->update_status( $id, 'trash' );
            $stored = Delete_Outcome::stored_post_status( $id, $label );
            if ( 'trash' !== $stored ) {
                throw new \RuntimeException( WPForms_Store::status_not_stored( $id, 'move form %d to the trash', 'trash', $before, $stored ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $status = Delete_Outcome::TRASH;
        }
        return array(
            'status'             => $status,
            'form'               => array(
                'id'    => $id,
                'title' => $plan['post']->post_title,
            ),
            'locations'          => $locations['shown'],
            'locations_withheld' => $locations['withheld'],
        );
    }

    private function plan( array $arguments ): array {
        $form_id = $this->form_id_argument( $arguments );
        $force   = self::boolean_arg( $arguments, 'force', false, Delete_Outcome::NOTHING_DELETED );
        $post    = WPForms_Store::require_form_post( $form_id, 'delete_form_single' );
        if ( ! $force && 'trash' === $post->post_status ) {
            throw new \InvalidArgumentException( sprintf( 'Form %d is already in the trash. Send force: true to delete it permanently. %s', $form_id, WPForms_Store::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return array(
            'post'  => $post,
            'force' => $force,
        );
    }
}
