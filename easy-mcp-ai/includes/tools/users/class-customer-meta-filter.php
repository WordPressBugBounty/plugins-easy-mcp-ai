<?php
namespace Easy_MCP_AI\Tools\Users;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Customer_Meta_Filter {

    const ROUTE = '#^/wc/v[0-9]+/customers(?:/|$)#i';

    const MAX_DEPTH = 32;

    public static function covers_route( string $route ): bool {
        return 1 === preg_match( self::ROUTE, $route );
    }

    const WRITE_METHODS = array( 'POST', 'PUT', 'PATCH' );

    public static function write_refusal( string $method, string $route, array $params ): ?string {
        if ( ! in_array( strtoupper( $method ), self::WRITE_METHODS, true ) || ! self::covers_route( $route ) ) {
            return null;
        }
        $keys = array();
        self::collect_sent_secrets( $params, 0, $keys );
        if ( array() === $keys ) {
            return null;
        }
        $keys = array_values( array_unique( $keys ) );
        sort( $keys, SORT_STRING );
        return sprintf( 'meta_data cannot write auth-sensitive user meta (a two-factor secret, session data), which wp_update_user_meta refuses too. Nothing was written. Keys: %s.', implode( ', ', $keys ) );
    }

    private static function collect_sent_secrets( $params, int $depth, array &$keys ): void {
        if ( ! is_array( $params ) || $depth >= self::MAX_DEPTH ) {
            return;
        }
        if ( isset( $params['meta_data'] ) && is_array( $params['meta_data'] ) ) {
            foreach ( $params['meta_data'] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $key = isset( $entry['key'] ) && is_scalar( $entry['key'] ) ? (string) $entry['key'] : null;
                if ( null !== $key && Get_User_Meta::is_auth_sensitive( $key ) ) {
                    $keys[] = $key;
                }
                $row_key = self::row_key( $entry['id'] ?? null );
                if ( null !== $row_key && Get_User_Meta::is_auth_sensitive( $row_key ) ) {
                    $keys[] = $row_key;
                }
            }
        }
        foreach ( $params as $name => $value ) {
            if ( 'meta_data' !== $name && is_array( $value ) ) {
                self::collect_sent_secrets( $value, $depth + 1, $keys );
            }
        }
    }

    private static function row_key( $id ): ?string {
        if ( ! ( is_int( $id ) || is_float( $id ) || is_string( $id ) ) || ! function_exists( 'rest_is_integer' ) || ! \rest_is_integer( $id ) ) {
            return null;
        }
        $mid = (int) $id;
        if ( $mid <= 0 || ! function_exists( 'get_metadata_by_mid' ) ) {
            return null;
        }
        $row = \get_metadata_by_mid( 'user', $mid );
        return is_object( $row ) && isset( $row->meta_key ) && is_string( $row->meta_key ) ? $row->meta_key : null;
    }

    public static function filter_response( string $route, $response ) {
        if ( ! $response instanceof \WP_REST_Response || ! self::covers_route( $route ) ) {
            return $response;
        }
        $data = $response->get_data();
        if ( ! is_array( $data ) && ! is_object( $data ) ) {
            return $response;
        }
        $json = \wp_json_encode( $data );
        if ( ! is_string( $json ) ) {
            throw new \RuntimeException( 'WordPress answered, but the reply could not be read as JSON, so it is not returned.' );
        }
        $plain    = json_decode( $json, true );
        $filtered = self::filter_data( $plain );
        if ( $filtered === $plain ) {
            return $response;
        }
        $copy = clone $response;
        $copy->set_data( $filtered );
        return $copy;
    }

    public static function filter_data( $data, int $depth = 0 ) {
        if ( ! is_array( $data ) || $depth >= self::MAX_DEPTH ) {
            return $data;
        }
        if ( array_key_exists( 'meta_data', $data ) ) {
            $data = self::filter_customer( $data );
        }
        foreach ( $data as $k => $v ) {
            if ( 'meta_data' !== $k && is_array( $v ) ) {
                $data[ $k ] = self::filter_data( $v, $depth + 1 );
            }
        }
        return $data;
    }

    public static function filter_customer( array $data ): array {
        if ( ! isset( $data['meta_data'] ) || ! is_array( $data['meta_data'] ) ) {
            return $data;
        }
        $meta     = $data['meta_data'];
        $kept     = array();
        $withheld = array();
        foreach ( $meta as $i => $entry ) {
            $key = is_array( $entry ) && isset( $entry['key'] ) && is_scalar( $entry['key'] ) ? (string) $entry['key'] : null;
            if ( null !== $key && Get_User_Meta::is_auth_sensitive( $key ) ) {
                $withheld[] = $key;
                continue;
            }
            $kept[ $i ] = $entry;
        }
        if ( array() === $withheld ) {
            return $data;
        }
        if ( isset( $data['withheld_keys'] ) && is_array( $data['withheld_keys'] ) ) {
            $withheld = array_merge( array_filter( $data['withheld_keys'], 'is_scalar' ), $withheld );
        }
        $withheld = array_values( array_unique( array_map( 'strval', $withheld ) ) );
        sort( $withheld, SORT_STRING );
        $data['meta_data']     = array_keys( $meta ) === range( 0, count( $meta ) - 1 ) ? array_values( $kept ) : $kept;
        $data['withheld_keys'] = $withheld;
        return $data;
    }
}
