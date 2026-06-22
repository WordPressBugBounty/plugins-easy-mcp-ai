<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}









class Dynamic_Tool_Registrar {

    




    public function register_to( Tool_Registry $registry ) {
        $this->register_ability_tools( $registry );
    }

    
    
    

    private function register_ability_tools( Tool_Registry $registry ) {
        if ( ! \function_exists( 'wp_get_abilities' ) ) {
            return;
        }

        $enabled_slugs = (array) \get_option( 'easy_mcp_ai_enabled_abilities', array() );
        if ( empty( $enabled_slugs ) ) {
            return;
        }

        
        
        
        $all_abilities = \wp_get_abilities();

        foreach ( $enabled_slugs as $slug ) {
            $slug = (string) $slug;
            if ( ! isset( $all_abilities[ $slug ] ) ) {
                continue; 
            }

            $ability = $all_abilities[ $slug ];

            $tool_name = 'wp_ability_' . self::normalize_identifier( $slug );

            
            $label = $ability->get_label() ?: $slug;

            
            $description = $ability->get_description();
            if ( ! empty( $description ) ) {
                $description = "{$description} (ability: {$slug})";
            } else {
                $description = "Execute WordPress Ability: {$label} ({$slug})";
            }

            
            $input_schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
            if ( empty( $input_schema ) || ! is_array( $input_schema ) ) {
                $input_schema = array( 'type' => 'object', 'properties' => new \stdClass() );
            } else {
                
                
                
                
                
                if ( ! isset( $input_schema['type'] ) ) {
                    $input_schema['type'] = 'object';
                }
                if ( ! isset( $input_schema['properties'] ) ) {
                    $input_schema['properties'] = new \stdClass();
                }
                $input_schema = self::normalize_json_schema( $input_schema );
            }

            
            $annotations_meta = array();
            if ( method_exists( $ability, 'get_meta_item' ) ) {
                $annotations_meta = (array) $ability->get_meta_item( 'annotations' );
            } elseif ( method_exists( $ability, 'get_annotations' ) ) {
                $annotations_meta = (array) $ability->get_annotations();
            }
            $readonly    = isset( $annotations_meta['readonly'] )    ? (bool) $annotations_meta['readonly']    : false;
            $destructive = isset( $annotations_meta['destructive'] ) ? (bool) $annotations_meta['destructive'] : false;
            $idempotent  = isset( $annotations_meta['idempotent'] )  ? (bool) $annotations_meta['idempotent']  : false;

            $captured_ability = $ability; 
            $captured_slug    = $slug;

            $tool = new Dynamic_Tool( array(
                'name'        => $tool_name,
                'description' => $description,
                'category'    => 'abilities',
                
                
                
                
                'capability'  => 'read',
                'input_schema' => $input_schema,
                'annotations' => array(
                    'title'           => $label,
                    'readOnlyHint'    => $readonly,
                    'destructiveHint' => $destructive,
                    'idempotentHint'  => $idempotent,
                    'openWorldHint'   => true,
                ),
                'executor' => function ( array $arguments ) use ( $captured_ability, $captured_slug ) {
                    
                    $ability = \function_exists( 'wp_get_ability' ) ? \wp_get_ability( $captured_slug ) : $captured_ability;
                    if ( ! $ability ) {
                        throw new \RuntimeException( "Ability '{$captured_slug}' is no longer registered." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }

                    
                    
                    
                    
                    
                    $has_input_schema = method_exists( $ability, 'get_input_schema' )
                        && ! empty( $ability->get_input_schema() );
                    $exec_args = $has_input_schema ? $arguments : null;

                    $perm = $ability->check_permissions( $exec_args );
                    if ( \is_wp_error( $perm ) ) {
                        throw new \RuntimeException( 'Permission denied: ' . $perm->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }
                    if ( false === $perm ) {
                        throw new \RuntimeException( "Permission denied for ability: {$captured_slug}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }

                    $result = $ability->execute( $exec_args );
                    if ( \is_wp_error( $result ) ) {
                        throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }
                    return $result;
                },
            ) );

            $registry->register( $tool );
        }
    }

    
    
    

    











    public static function normalize_identifier( $value ) {
        $normalized = strtolower( (string) $value );
        $normalized = preg_replace( '/[^a-z0-9]+/', '_', $normalized );
        return trim( $normalized, '_' );
    }

    










    private static function normalize_json_schema( array $schema ) {
        
        
        foreach ( array( 'properties', 'patternProperties', '$defs', 'definitions' ) as $map_key ) {
            if ( array_key_exists( $map_key, $schema ) && is_array( $schema[ $map_key ] ) ) {
                if ( empty( $schema[ $map_key ] ) ) {
                    $schema[ $map_key ] = new \stdClass();
                } else {
                    foreach ( $schema[ $map_key ] as $name => $child ) {
                        if ( is_array( $child ) ) {
                            $schema[ $map_key ][ $name ] = self::normalize_json_schema( $child );
                        }
                    }
                }
            }
        }

        
        foreach ( array( 'allOf', 'anyOf', 'oneOf', 'prefixItems' ) as $list_key ) {
            if ( array_key_exists( $list_key, $schema ) && is_array( $schema[ $list_key ] ) ) {
                foreach ( $schema[ $list_key ] as $i => $child ) {
                    if ( is_array( $child ) ) {
                        $schema[ $list_key ][ $i ] = self::normalize_json_schema( $child );
                    }
                }
            }
        }

        
        
        foreach ( array( 'additionalProperties', 'additionalItems', 'contains', 'propertyNames', 'not', 'if', 'then', 'else' ) as $sub_key ) {
            if ( array_key_exists( $sub_key, $schema ) && is_array( $schema[ $sub_key ] ) ) {
                $schema[ $sub_key ] = empty( $schema[ $sub_key ] )
                    ? new \stdClass()
                    : self::normalize_json_schema( $schema[ $sub_key ] );
            }
        }

        
        if ( array_key_exists( 'items', $schema ) && is_array( $schema['items'] ) ) {
            if ( self::is_list_array( $schema['items'] ) ) {
                foreach ( $schema['items'] as $i => $child ) {
                    if ( is_array( $child ) ) {
                        $schema['items'][ $i ] = self::normalize_json_schema( $child );
                    }
                }
            } else {
                $schema['items'] = self::normalize_json_schema( $schema['items'] );
            }
        }

        return $schema;
    }

    





    private static function is_list_array( array $arr ) {
        $i = 0;
        foreach ( $arr as $k => $unused ) {
            if ( $k !== $i++ ) {
                return false;
            }
        }
        return true;
    }
}
