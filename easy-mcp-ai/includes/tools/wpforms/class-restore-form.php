<?php

namespace Easy_MCP_AI\Tools\WPForms;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Delete_Outcome;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Restore_Form extends Base_Tool {

    use WPForms_Tool;

    public function get_name() {
        return 'wp_wpforms_restore_form';
    }

    public function get_description() {
        return 'Restores a WPForms form (or form template) from the trash, as Restore under WPForms › All Forms › Trash: WPForms publishes it again, with its fields, settings and entries as they were, and pages that embed it show it again. Required: `form_id` (WPForms\' wpforms/list-forms ability lists trashed forms with status "trash"). A form that is not in the trash is refused naming its status. Returns { restored: true, status: "publish", form: { id, title } }. Requires the capability WPForms requires to manage forms (manage_options by default).';
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
                'form_id' => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the form in the trash.',
                ),
            ),
            'required'   => array( 'form_id' ),
        );
    }

    public function describe( array $arguments ) {
        try {
            $post = $this->trashed_form( $arguments );
        } catch ( \RuntimeException | \InvalidArgumentException $e ) {
            return self::generic_description( $this, $arguments );
        }
        return sprintf( 'Restore WPForms form #%d "%s" from the trash', (int) $post->ID, $post->post_title );
    }

    public function precheck( array $arguments ): void {
        $this->trashed_form( $arguments );
    }

    public function execute( array $arguments ) {
        return WPForms_Store::locked(
            $this->form_id_argument( $arguments ),
            static::lock_wait(),
            function ( string $lock ) use ( $arguments ): array {
                return $this->restore( $arguments, $lock );
            }
        );
    }

    private function restore( array $arguments, string $lock ): array {
        $post = $this->trashed_form( $arguments );
        $id   = (int) $post->ID;
        WPForms_Store::require_lock( $id, $lock );
        WPForms_Store::handler()->update_status( $id, 'publish' );
        $status = Delete_Outcome::stored_post_status( $id, 'form ' . $id );
        if ( 'publish' !== $status ) {
            throw new \RuntimeException( WPForms_Store::status_not_stored( $id, 'restore form %d', 'publish', 'trash', $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return array(
            'restored' => true,
            'status'   => $status,
            'form'     => array(
                'id'    => $id,
                'title' => $post->post_title,
            ),
        );
    }

    private function trashed_form( array $arguments ): object {
        $form_id = $this->form_id_argument( $arguments );
        $post    = WPForms_Store::require_form_post( $form_id, 'delete_form_single' );
        if ( 'trash' !== $post->post_status ) {
            throw new \InvalidArgumentException( sprintf( 'Form %d is not in the trash: its status is "%s". Only a trashed form can be restored. %s', $form_id, (string) $post->post_status, WPForms_Store::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $post;
    }
}
