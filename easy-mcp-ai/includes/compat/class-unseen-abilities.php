<?php

namespace Easy_MCP_AI\Compat;

use Easy_MCP_AI\Tools\Dynamic_Tool_Registrar;
use Easy_MCP_AI\Tools\Excluded_Abilities;
use Easy_MCP_AI\Tools\Tool_State;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Unseen_Abilities {

    public static function definitions(): array {
        if ( Vendor_Abilities::served() || ! function_exists( 'wp_get_abilities' ) ) {
            return array();
        }
        $enabled = \get_option( 'easy_mcp_ai_enabled_abilities', array() );
        if ( ! is_array( $enabled ) || array() === $enabled ) {
            return array();
        }
        $registered = (array) \wp_get_abilities();
        $out        = array();
        foreach ( $enabled as $slug ) {
            if ( ! is_string( $slug ) || '' === $slug || isset( $registered[ $slug ] ) || ! self::is_vendor_slug( $slug ) || Excluded_Abilities::excludes( $slug ) ) {
                continue;
            }
            $name = Dynamic_Tool_Registrar::build_tool_name( $slug );
            if ( isset( $out[ $name ] ) ) {
                continue;
            }
            $out[ $name ] = array(
                'name'       => $name,
                'slug'       => $slug,
                'category'   => 'abilities',
                'capability' => Dynamic_Tool_Registrar::required_capability( $slug ),
                'on'         => (bool) Tool_State::resolve( $name, false )[0],
            );
        }
        return array_values( $out );
    }

    public static function is_slug( string $slug ): bool {
        return ! Vendor_Abilities::served() && self::is_vendor_slug( $slug );
    }

    private static function loaded_class_of( string $slug ): ?string {
        $prefix = (string) strstr( $slug, '/', true );
        foreach ( Vendor_Abilities::CLASSES as $class ) {
            if ( $class::PREFIX === $prefix . '/' ) {
                return $class::plugin_loaded() ? $class : null;
            }
        }
        return null;
    }

    public static function is_tool( string $name ): bool {
        if ( Vendor_Abilities::served() ) {
            return false;
        }
        foreach ( Vendor_Abilities::CLASSES as $class ) {
            if ( 0 === strpos( $name, Dynamic_Tool_Registrar::build_tool_name( rtrim( $class::PREFIX, '/' ) ) . '_' ) ) {
                return $class::plugin_loaded();
            }
        }
        return false;
    }

    public static function in_scope( string $scope ): array {
        if ( Vendor_Abilities::served() || ! function_exists( 'wp_get_abilities' ) ) {
            return array();
        }
        $vendors = array();
        foreach ( preg_split( '/\s+/', trim( $scope ) ) as $part ) {
            if ( 0 !== strpos( (string) $part, 'mcp:abilities:' ) ) {
                continue;
            }
            $prefix = Dynamic_Tool_Registrar::normalize_identifier( substr( (string) $part, strlen( 'mcp:abilities:' ) ) );
            foreach ( Vendor_Abilities::CLASSES as $class ) {
                if ( $class::PREFIX === $prefix . '/' && $class::plugin_loaded() && ! $class::own_abilities_registered() ) {
                    $vendors[ $class::VENDOR ] = true;
                }
            }
        }
        return array_keys( $vendors );
    }

    public static function vendors( array $definitions ): array {
        $vendors = array();
        foreach ( $definitions as $definition ) {
            $vendor = Vendor_Abilities::vendor_of( (string) strstr( (string) ( $definition['slug'] ?? '' ), '/', true ) );
            if ( null !== $vendor ) {
                $vendors[ $vendor ] = true;
            }
        }
        return array_keys( $vendors );
    }

    private static function is_vendor_slug( string $slug ): bool {
        return null !== self::loaded_class_of( $slug );
    }
}
