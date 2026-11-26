<?php

namespace Easy_MCP_AI\Tools\ACF;

use Easy_MCP_AI\MCP\Detailed_Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Acf_Sent_Fields {

    const LAYOUT_KEYS = array( 'acf_fc_layout', 'acf_fc_layout_disabled', 'acf_fc_layout_custom_label' );

    public static function refuse_duplicates( array $sent, callable $resolve ): void {
        self::refuse_in_row( $sent, $resolve, '', false );
    }

    public static function sub_fields( array $field ): array {
        return isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
    }

    public static function layouts( array $field ): array {
        return isset( $field['layouts'] ) && is_array( $field['layouts'] ) ? $field['layouts'] : array();
    }

    private static function layout( array $field, $name ): ?array {
        foreach ( self::layouts( $field ) as $layout ) {
            if ( is_array( $layout ) && is_string( $name ) && ( $layout['name'] ?? null ) === $name ) {
                return self::sub_fields( $layout );
            }
        }
        return null;
    }

    private static function refuse_in_row( array $row, callable $resolve, string $path, bool $layout_row ): void {
        $seen = array();
        foreach ( $row as $name => $value ) {
            $name = (string) $name;
            if ( $layout_row && in_array( $name, self::LAYOUT_KEYS, true ) ) {
                continue;
            }
            $field = $resolve( $name );
            if ( ! is_array( $field ) || ! isset( $field['key'] ) ) {
                continue;
            }
            $key = (string) $field['key'];
            if ( isset( $seen[ $key ] ) ) {
                throw new Detailed_Tool_Error(
                    'Nothing was changed: fields names one ACF field twice, so only one of the two values would be saved. Send it once; the details name both.',
                    sprintf( '"%1$s" and "%2$s" are both the field %3$s (%4$s).', $path . $seen[ $key ], $path . $name, (string) ( $field['name'] ?? '' ), $key )
                );
            }
            $seen[ $key ] = $name;
            self::refuse_in_value( $value, $field, $path . $name );
        }
    }

    private static function refuse_in_value( $value, array $field, string $path ): void {
        if ( ! is_array( $value ) ) {
            return;
        }
        $type = $field['type'] ?? '';
        if ( 'group' === $type ) {
            self::refuse_in_row( $value, self::resolver( self::sub_fields( $field ) ), $path . '.', false );
            return;
        }
        if ( 'clone' === $type && 'group' === ( $field['display'] ?? '' ) ) {
            self::refuse_in_row( $value, self::clone_resolver( self::sub_fields( $field ) ), $path . '.', false );
            return;
        }
        if ( 'repeater' !== $type && 'flexible_content' !== $type ) {
            return;
        }
        foreach ( $value as $i => $row ) {
            $sub_fields = self::row_fields( $field, $row );
            if ( is_array( $row ) && null !== $sub_fields ) {
                self::refuse_in_row( $row, self::resolver( $sub_fields ), $path . '.' . $i . '.', 'flexible_content' === $type );
            }
        }
    }

    public static function row_fields( array $field, $row ): ?array {
        switch ( $field['type'] ?? '' ) {
            case 'repeater':
                return self::sub_fields( $field );
            case 'flexible_content':
                return is_array( $row ) ? self::layout( $field, $row['acf_fc_layout'] ?? null ) : null;
        }
        return null;
    }

    public static function refuse_rest_duplicates( array $acf ): void {
        $key_map = isset( $acf['_acf_field_key_map'] ) && is_array( $acf['_acf_field_key_map'] ) ? $acf['_acf_field_key_map'] : array();
        $lookup  = static function ( string $sent ) use ( $key_map ): ?string {
            if ( '_acf_field_key_map' === $sent || '_acf_field_group_scope' === $sent ) {
                return null;
            }
            $name = $key_map[ $sent ] ?? $sent;
            return is_string( $name ) ? $name : null;
        };
        $by_key  = array();
        $by_name = array();
        foreach ( array_keys( $acf ) as $sent ) {
            $name  = $lookup( (string) $sent );
            $field = null !== $name && acf_is_field_key( $name ) ? acf_get_field( $name ) : null;
            if ( is_array( $field ) ) {
                $by_key[ $name ] = $field;
                $by_name[ (string) ( $field['name'] ?? '' ) ][] = $field;
            }
        }
        self::refuse_duplicates(
            $acf,
            static function ( string $sent ) use ( $lookup, $by_key, $by_name ): ?array {
                $name = $lookup( $sent );
                if ( null === $name ) {
                    return null;
                }
                if ( isset( $by_key[ $name ] ) ) {
                    return $by_key[ $name ];
                }
                if ( isset( $by_name[ $name ] ) ) {
                    return $by_name[ $name ][0];
                }
                $field = acf_get_field( $name );
                return is_array( $field ) && ( $field['name'] ?? null ) === $name ? $field : null;
            }
        );
    }

    public static function resolver( array $fields ): callable {
        return static function ( string $name ) use ( $fields ): ?array {
            $field = acf_search_fields( $name, $fields );
            return is_array( $field ) ? $field : null;
        };
    }

    private static function clone_resolver( array $fields ): callable {
        return static function ( string $name ) use ( $fields ): ?array {
            foreach ( array( 'key', '_name' ) as $by ) {
                foreach ( $fields as $field ) {
                    if ( is_array( $field ) && ( $field[ $by ] ?? null ) === $name ) {
                        return $field;
                    }
                }
            }
            return null;
        };
    }
}
