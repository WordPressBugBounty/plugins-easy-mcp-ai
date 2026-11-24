<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Narrow_Term_Write {

    public static function dispatch( \WP_REST_Request $request, int $term_id, callable $dispatch ) {
        $term = get_term( $term_id );
        if ( ! is_object( $term ) || is_wp_error( $term ) ) {
            return $dispatch();
        }
        $core   = false;
        $served = static function ( $response, $handler, $current ) use ( $request, &$core ) {
            if ( $current === $request ) {
                $callback = $handler['callback'] ?? null;
                $core     = is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_object( $callback[0] )
                    && 'WP_REST_Terms_Controller' === get_class( $callback[0] ) && 'update_item' === $callback[1];
            }
            return $response;
        };
        $skip   = static function ( $prepared, $current ) use ( $request, &$core ) {
            if ( $current !== $request || ! $core || ! $prepared instanceof \stdClass || array() !== get_object_vars( $prepared ) ) {
                return $prepared;
            }
            return array();
        };
        $hook   = 'rest_pre_insert_' . $term->taxonomy;
        add_filter( 'rest_request_before_callbacks', $served, PHP_INT_MAX, 3 );
        add_filter( $hook, $skip, PHP_INT_MAX, 2 );
        try {
            return $dispatch();
        } finally {
            remove_filter( 'rest_request_before_callbacks', $served, PHP_INT_MAX );
            remove_filter( $hook, $skip, PHP_INT_MAX );
        }
    }
}
