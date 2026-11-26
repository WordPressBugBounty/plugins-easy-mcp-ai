<?php

namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rest_Header_Names {

    public static function register(): void {
        \add_filter( 'rest_pre_dispatch', array( __CLASS__, 'repair' ), PHP_INT_MIN, 3 );
    }

    public static function repair( $result, $server, $request ) {
        if ( $request instanceof \WP_REST_Request && Compat\Vendor_Abilities::is_our_rest_route( (string) $request->get_route() ) ) {
            self::restore( $request );
        }
        return $result;
    }

    public static function restore( \WP_REST_Request $request ): void {
        foreach ( (array) $request->get_headers() as $name => $values ) {
            $ascii = strtr( Ascii::lower( (string) $name ), "\xFD", 'i' );
            if ( $ascii !== (string) $name && null === $request->get_header( $ascii ) ) {
                $request->set_header( $ascii, $values );
            }
        }
    }
}
