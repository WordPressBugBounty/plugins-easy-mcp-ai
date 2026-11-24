<?php

namespace Easy_MCP_AI\Tools\WPForms;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait WPForms_Tool {

    public function get_category() {
        return 'wpforms';
    }

    public function get_required_capability() {
        return WPForms_Store::capability();
    }

    protected function form_id_argument( array $arguments ): int {
        $this->validate_required( $arguments, array( 'form_id' ) );
        return $this->parse_required_id( $arguments['form_id'], 'form_id' );
    }

    protected function field_id_argument( array $arguments ): int {
        $this->validate_required( $arguments, array( 'field_id' ) );
        $value = $arguments['field_id'];
        if ( is_int( $value ) && $value >= 0 ) {
            return $value;
        }
        if ( is_string( $value ) && ctype_digit( $value ) ) {
            return (int) $value;
        }
        throw new \InvalidArgumentException( sprintf( 'Invalid field_id: must be a whole number, 0 or more, got: %s. %s', wp_json_encode( $value ), WPForms_Store::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    protected static function lock_wait(): int {
        return WPForms_Store::LOCK_WAIT;
    }

    protected static function previewed_field( array $arguments ): ?array {
        $id       = isset( $arguments['form_id'] ) && is_numeric( $arguments['form_id'] ) ? (int) $arguments['form_id'] : 0;
        $field_id = isset( $arguments['field_id'] ) && is_numeric( $arguments['field_id'] ) ? (int) $arguments['field_id'] : 0;
        try {
            $form = WPForms_Store::require_form( $id );
        } catch ( \RuntimeException | \InvalidArgumentException $e ) {
            return null;
        }
        $fields = WPForms_Store::fields( $form['data'] );
        if ( ! isset( $fields[ $field_id ] ) ) {
            return null;
        }
        return array(
            'post'  => $form['post'],
            'field' => WPForms_Store::summary( $field_id, $fields[ $field_id ] ),
        );
    }
}
