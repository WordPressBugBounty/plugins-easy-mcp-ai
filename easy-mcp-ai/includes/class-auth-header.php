<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}










































class Auth_Header {

    


















    public static function recover_bearer( array $headers ) {
        foreach ( $headers as $name => $value ) {
            if ( 0 !== strcasecmp( (string) $name, 'Authorization' ) ) {
                continue;
            }
            if ( is_string( $value ) && 0 === stripos( $value, 'Bearer ' ) ) {
                return $value;
            }
            return null;
        }
        return null;
    }

    








    public static function request_headers() {
        if ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
        } elseif ( function_exists( 'apache_request_headers' ) ) {
            $headers = apache_request_headers();
        } else {
            return null;
        }
        return is_array( $headers ) ? $headers : null;
    }
}
