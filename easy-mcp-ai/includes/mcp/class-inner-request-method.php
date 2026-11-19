<?php

namespace Easy_MCP_AI\MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Inner_Request_Method {

    private static $opened_with = array();

    private static $found = array();

    public static function open(): void {
        if ( ! self::$opened_with ) {
            self::$found = array();
            add_filter( 'rest_request_before_callbacks', array( self::class, 'enter' ), PHP_INT_MIN, 3 );
            add_filter( 'rest_request_after_callbacks', array( self::class, 'leave' ), PHP_INT_MAX, 3 );
        }
        self::$opened_with[] = self::current();
    }

    public static function close(): void {
        if ( ! self::$opened_with ) {
            return;
        }
        self::restore( array_pop( self::$opened_with ) );
        if ( ! self::$opened_with ) {
            remove_filter( 'rest_request_before_callbacks', array( self::class, 'enter' ), PHP_INT_MIN );
            remove_filter( 'rest_request_after_callbacks', array( self::class, 'leave' ), PHP_INT_MAX );
            self::$found = array();
        }
    }

    public static function enter( $response, $handler, $request ) {
        if ( $request instanceof \WP_REST_Request ) {
            self::$found[ spl_object_id( $request ) ] = self::current();
            $_SERVER['REQUEST_METHOD']                = $request->get_method();
        }
        return $response;
    }

    public static function leave( $response, $handler, $request ) {
        if ( $request instanceof \WP_REST_Request ) {
            $id = spl_object_id( $request );
            if ( isset( self::$found[ $id ] ) ) {
                self::restore( self::$found[ $id ] );
                unset( self::$found[ $id ] );
            }
        }
        return $response;
    }

    private static function current(): array {
        return array( array_key_exists( 'REQUEST_METHOD', $_SERVER ), $_SERVER['REQUEST_METHOD'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- kept as found, to be put back unchanged.
    }

    private static function restore( array $was ): void {
        if ( $was[0] ) {
            $_SERVER['REQUEST_METHOD'] = $was[1];
        } else {
            unset( $_SERVER['REQUEST_METHOD'] );
        }
    }
}
