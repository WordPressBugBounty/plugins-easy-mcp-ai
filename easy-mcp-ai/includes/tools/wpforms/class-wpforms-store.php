<?php

namespace Easy_MCP_AI\Tools\WPForms;

use Easy_MCP_AI\Builders\Layout_Lock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPForms_Store {

    const NOTHING_CHANGED = 'Nothing was changed.';

    const LOCK_WAIT = 10;

    const MAX_CHANGED_PATHS = 20;

    const LISTED_IN_REFUSAL = 60;

    const STRUCTURE_TYPES = array( 'pagebreak', 'layout', 'repeater' );

    public static function unavailable(): ?string {
        if ( ! function_exists( 'wpforms' ) || ! function_exists( 'wpforms_current_user_can' ) || ! function_exists( 'wpforms_decode' ) ) {
            return 'WPForms is not active on this site. Install and activate WPForms (wpforms-lite) or WPForms Pro.';
        }
        $handler = \wpforms()->obj( 'form' );
        if ( ! is_object( $handler ) || ! method_exists( $handler, 'update' ) ) {
            return 'WPForms did not load its form handler on this request. Nothing was changed.';
        }
        return null;
    }

    public static function capability(): string {
        if ( function_exists( 'wpforms_get_capability_manage_options' ) ) {
            $capability = (string) \wpforms_get_capability_manage_options();
            if ( '' !== $capability ) {
                return $capability;
            }
        }
        return 'manage_options';
    }

    public static function handler(): object {
        $refusal = self::unavailable();
        if ( null !== $refusal ) {
            throw new \RuntimeException( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return \wpforms()->obj( 'form' );
    }

    public static function locked( int $form_id, int $wait, callable $call ) {
        $lock = Layout_Lock::acquire_waiting( $form_id, $wait );
        try {
            return $call( $lock );
        } finally {
            Layout_Lock::release( $form_id, $lock );
        }
    }

    public static function require_lock( int $form_id, string $lock ): void {
        if ( ! Layout_Lock::holds( $form_id, $lock ) ) {
            throw new \RuntimeException( sprintf( 'This call on form %d ran longer than %d seconds and another call took over, so nothing was saved. Call again.', $form_id, Layout_Lock::TTL ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    public static function status_not_stored( int $form_id, string $action, string $wanted, string $before, ?string $stored ): string {
        $did_not = 'WPForms did not ' . sprintf( $action, $form_id );
        if ( $stored === $before ) {
            return sprintf( '%s: it is still "%s". %s', $did_not, $before, self::NOTHING_CHANGED );
        }
        if ( null === $stored ) {
            return $did_not . ', and the form no longer exists.';
        }
        return sprintf( 'WPForms stored form %d as "%s", not "%s" as the call asked. Check it under WPForms › All Forms.', $form_id, $stored, $wanted );
    }

    public static function require_form_post( int $form_id, string $capability ): object {
        self::handler();
        wp_cache_delete( $form_id, 'posts' );
        $post = get_post( $form_id );
        if ( ! is_object( $post ) || ! isset( $post->post_type ) || ! in_array( $post->post_type, self::post_types(), true ) ) {
            throw new \InvalidArgumentException( sprintf( 'WPForms has no form %d. %s %s', $form_id, \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_ability_wpforms_list_forms' ) ? 'WPForms\' wpforms/list-forms ability and WPForms › All Forms list the forms.' : 'WPForms › All Forms lists the forms.', self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( ! \wpforms_current_user_can( $capability, $form_id ) ) {
            throw new \RuntimeException( sprintf( 'WPForms does not let this account %s form %d (WPForms checks %s). %s', 'delete_form_single' === $capability ? 'delete' : 'edit', $form_id, $capability, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $post;
    }

    public static function require_form( int $form_id ): array {
        $post = self::require_form_post( $form_id, 'edit_form_single' );
        $data = \wpforms_decode( $post->post_content );
        if ( ! is_array( $data ) || array() === $data ) {
            throw new \RuntimeException( sprintf( 'WPForms form %d holds no form data it can read. Open it in the WPForms builder. %s', $form_id, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return array(
            'post' => $post,
            'data' => $data,
        );
    }

    public static function post_types(): array {
        if ( class_exists( '\\WPForms_Form_Handler' ) && defined( '\\WPForms_Form_Handler::POST_TYPES' ) ) {
            return array_values( array_map( 'strval', (array) \WPForms_Form_Handler::POST_TYPES ) );
        }
        return array( 'wpforms', 'wpforms-template' );
    }

    public static function fields( array $data ): array {
        $fields = array();
        if ( ! isset( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
            return $fields;
        }
        foreach ( $data['fields'] as $key => $field ) {
            if ( is_array( $field ) ) {
                $fields[ (int) $key ] = $field;
            }
        }
        return $fields;
    }

    public static function summaries( array $fields ): array {
        $list = array();
        foreach ( $fields as $id => $field ) {
            $list[] = self::summary( $id, $field );
        }
        return $list;
    }

    public static function summary( int $id, array $field ): array {
        return array(
            'id'    => $id,
            'type'  => self::text( $field, 'type' ),
            'label' => self::text( $field, 'label' ),
        );
    }

    public static function require_field( array $fields, int $form_id, int $field_id ): array {
        if ( ! isset( $fields[ $field_id ] ) ) {
            $listed = implode( ', ', array_keys( $fields ) );
            if ( array() === $fields ) {
                $ids = 'it has none';
            } elseif ( strlen( $listed ) > self::LISTED_IN_REFUSAL ) {
                $ids = \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_ability_wpforms_get_form' ) ? sprintf( 'its %d fields are listed by WPForms\' wpforms/get-form ability', count( $fields ) ) : sprintf( 'it has %d fields', count( $fields ) );
            } else {
                $ids = 'its fields are ' . $listed;
            }
            throw new \InvalidArgumentException( sprintf( 'Form %d has no field %d; %s. %s', $form_id, $field_id, $ids, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $fields[ $field_id ];
    }

    public static function structure_refusal( array $fields, int $field_id ): ?string {
        $type = self::summary( $field_id, $fields[ $field_id ] )['type'];
        if ( in_array( $type, self::STRUCTURE_TYPES, true ) ) {
            return sprintf( 'Field %d is a %s field, which other fields depend on for their place. Change it in the WPForms builder.', $field_id, $type );
        }
        $holder = self::holder_of( $fields, $field_id );
        if ( null !== $holder ) {
            return sprintf( 'Field %d is inside the %s field %d. Change it in the WPForms builder.', $field_id, self::summary( $holder, $fields[ $holder ] )['type'], $holder );
        }
        return null;
    }

    private static function holder_of( array $fields, int $field_id ): ?int {
        foreach ( $fields as $id => $field ) {
            if ( ! isset( $field['columns'] ) || ! is_array( $field['columns'] ) ) {
                continue;
            }
            foreach ( $field['columns'] as $column ) {
                if ( ! is_array( $column ) || ! isset( $column['fields'] ) || ! is_array( $column['fields'] ) ) {
                    continue;
                }
                foreach ( $column['fields'] as $held ) {
                    if ( is_scalar( $held ) && (int) $held === $field_id ) {
                        return (int) $id;
                    }
                }
            }
        }
        return null;
    }

    public static function require_savable( int $form_id, array $data ): void {
        if ( ! current_user_can( 'unfiltered_html' ) && self::holds_markup( $data ) ) {
            throw new \RuntimeException( sprintf( 'Form %d holds HTML, which WPForms strips from the whole form when an account without unfiltered_html saves it. %s', $form_id, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    public static function save_fields( int $form_id, array $data, array $fields, string $lock ): array {
        self::require_lock( $form_id, $lock );
        $handler        = self::handler();
        $data['fields'] = $fields;
        $data['id']     = $form_id;
        $saved          = $handler->update( $form_id, self::slashed_for_update( $data ) );
        if ( empty( $saved ) ) {
            throw new \RuntimeException( sprintf( 'WPForms did not save form %d. %s', $form_id, self::NOTHING_CHANGED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        clean_post_cache( $form_id );
        $post   = get_post( $form_id );
        $stored = is_object( $post ) && isset( $post->post_content ) ? \wpforms_decode( $post->post_content ) : null;
        $kept   = is_array( $stored ) ? self::fields( $stored ) : array();
        if ( ! is_array( $stored ) || array_keys( $kept ) !== array_keys( $fields ) ) {
            throw new \RuntimeException( sprintf( 'WPForms stored form %d with fields %s, not %s as sent. %s', $form_id, implode( ', ', array_keys( $kept ) ), implode( ', ', array_keys( $fields ) ), \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_ability_wpforms_get_form' ) ? 'Read it again with WPForms\' wpforms/get-form ability, or open it in the WPForms builder.' : 'Open it in the WPForms builder to see what it holds.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return array(
            'fields'       => $kept,
            'also_changed' => self::changed_paths( $data, $stored, '' ),
        );
    }

    private static function slashed_for_update( array $data ): array {
        if ( ! self::slashing_enabled() ) {
            return wp_slash( wp_slash( $data ) );
        }
        $sent = wp_slash( $data );
        foreach ( (array) ( $sent['fields'] ?? array() ) as $id => $field ) {
            foreach ( array( 'calculation_code', 'columns-json' ) as $key ) {
                if ( is_array( $field ) && isset( $field[ $key ] ) ) {
                    $sent['fields'][ $id ][ $key ] = wp_slash( $field[ $key ] );
                }
            }
        }
        return $sent;
    }

    private static function changed_paths( $sent, $stored, string $path ): array {
        if ( ! is_array( $sent ) || ! is_array( $stored ) ) {
            return self::same_value( $sent, $stored ) ? array() : array( '' === $path ? '(the form)' : $path );
        }
        $paths = array();
        if ( array_keys( $sent ) !== array_keys( $stored ) && self::numbered( $sent ) && self::numbered( $stored ) && array() === array_diff_key( $sent, $stored ) && array() === array_diff_key( $stored, $sent ) ) {
            $paths[] = '' === $path ? '(the form)' : $path;
        }
        foreach ( array_keys( $sent + $stored ) as $key ) {
            $at    = '' === $path ? (string) $key : $path . '.' . $key;
            $paths = array_merge(
                $paths,
                array_key_exists( $key, $sent ) && array_key_exists( $key, $stored ) ? self::changed_paths( $sent[ $key ], $stored[ $key ], $at ) : array( $at )
            );
            if ( count( $paths ) >= self::MAX_CHANGED_PATHS ) {
                return array_slice( $paths, 0, self::MAX_CHANGED_PATHS );
            }
        }
        return $paths;
    }

    private static function numbered( array $values ): bool {
        foreach ( array_keys( $values ) as $key ) {
            if ( ! is_int( $key ) ) {
                return false;
            }
        }
        return true;
    }

    private static function same_value( $sent, $stored ): bool {
        if ( $sent === $stored ) {
            return true;
        }
        $number = static function ( $value ): bool {
            return is_int( $value ) || is_float( $value );
        };
        return ( $number( $sent ) && is_string( $stored ) || is_string( $sent ) && $number( $stored ) ) && (string) $sent === (string) $stored;
    }

    private static function holds_markup( $value ): bool {
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( self::holds_markup( $item ) ) {
                    return true;
                }
            }
            return false;
        }
        return is_string( $value ) && wp_strip_all_tags( $value ) !== trim( $value );
    }

    private static function slashing_enabled(): bool {
        return function_exists( 'wpforms_is_form_data_slashing_enabled' ) && (bool) \wpforms_is_form_data_slashing_enabled();
    }

    public static function locations( int $form_id ): array {
        $stored   = get_post_meta( $form_id, 'wpforms_form_locations', true );
        $shown    = array();
        $withheld = 0;
        foreach ( is_array( $stored ) ? $stored : array() as $location ) {
            if ( ! is_array( $location ) ) {
                continue;
            }
            $id = self::text( $location, 'id' );
            if ( ctype_digit( $id ) && ! current_user_can( 'read_post', (int) $id ) ) {
                ++$withheld;
                continue;
            }
            $shown[] = array(
                'type'   => self::text( $location, 'type' ),
                'id'     => $id,
                'title'  => self::text( $location, 'title' ),
                'status' => self::text( $location, 'status' ),
                'url'    => self::text( $location, 'url' ),
            );
        }
        return array(
            'shown'    => $shown,
            'withheld' => $withheld,
        );
    }

    private static function text( array $values, string $key ): string {
        return isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
    }
}
