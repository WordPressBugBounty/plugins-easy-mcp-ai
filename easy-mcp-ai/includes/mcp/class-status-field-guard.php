<?php

namespace Easy_MCP_AI\MCP;

use Easy_MCP_AI\Tools\CPT\Status_Taxonomy;
use Easy_MCP_AI\Tools\Narrow_Post_Write;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Status_Field_Guard {

    private static $depth = 0;

    private static $pending = array();

    public static function open(): void {
        if ( 0 === self::$depth++ ) {
            self::$pending = array();
            add_filter( 'rest_pre_dispatch', array( self::class, 'pre_dispatch' ), PHP_INT_MIN, 3 );
            add_filter( 'rest_request_before_callbacks', array( self::class, 'before_callbacks' ), PHP_INT_MAX, 3 );
            add_filter( 'rest_request_after_callbacks', array( self::class, 'after_callbacks' ), PHP_INT_MIN, 3 );
        }
    }

    public static function close(): void {
        if ( 0 === self::$depth || 0 !== --self::$depth ) {
            return;
        }
        remove_filter( 'rest_pre_dispatch', array( self::class, 'pre_dispatch' ), PHP_INT_MIN );
        remove_filter( 'rest_request_before_callbacks', array( self::class, 'before_callbacks' ), PHP_INT_MAX );
        remove_filter( 'rest_request_after_callbacks', array( self::class, 'after_callbacks' ), PHP_INT_MIN );
        foreach ( self::$pending as $state ) {
            self::remove_filters( $state );
        }
        self::$pending = array();
    }

    public static function pre_dispatch( $result, $server, $request ) {
        if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
            return $result;
        }
        $target = self::target( $request );
        if ( null === $target ) {
            return $result;
        }
        $method  = strtoupper( $request->get_method() );
        $refusal = null;
        if ( in_array( $method, array( 'GET', 'HEAD' ), true ) && 'collection' === $target['kind'] ) {
            $refusal = self::collection( $request, $target );
        } elseif ( 'edit' !== $target['kind'] && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $refusal = self::status_text( $request, $target );
        }
        if ( null === $refusal ) {
            return $result;
        }
        $data = $refusal->get_error_data();
        return new \WP_REST_Response(
            array(
                'code'    => $refusal->get_error_code(),
                'message' => $refusal->get_error_message(),
                'data'    => $data,
            ),
            (int) $data['status']
        );
    }

    public static function before_callbacks( $response, $handler, $request ) {
        if ( ! $request instanceof \WP_REST_Request ) {
            return $response;
        }
        $state = self::state( $request );
        if ( null !== $state ) {
            self::remove_endpoints_filter( $state );
        }
        if ( is_wp_error( $response ) || ! in_array( strtoupper( $request->get_method() ), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            return $response;
        }
        $target = self::target( $request );
        $terms  = null === $target ? null : self::term_ids( $request->get_param( Status_Taxonomy::FIELD ) );
        if ( null === $terms ) {
            return $response;
        }
        if ( in_array( $target['kind'], array( 'autosave', 'edit' ), true ) ) {
            $request->offsetUnset( Status_Taxonomy::FIELD );
            return $response;
        }
        foreach ( $terms as $term_id ) {
            foreach ( $target['taxonomies'] as $taxonomy ) {
                if ( get_term( $term_id, $taxonomy ) && ! current_user_can( 'assign_term', $term_id ) ) {
                    return new \WP_Error( 'rest_cannot_assign_term', 'Sorry, you are not allowed to assign the provided terms.', array( 'status' => rest_authorization_required_code() ) );
                }
            }
        }
        $request->offsetUnset( Status_Taxonomy::FIELD );
        $key    = spl_object_id( $request );
        $insert = static function ( $post, $current ) use ( $request, $key ) {
            $state = self::$pending[ $key ] ?? null;
            if ( $current !== $request || null === $state || $state['request'] !== $request || isset( $state['set'] ) || ! is_object( $post ) ) {
                return;
            }
            self::$pending[ $key ]['set'] = self::set_terms( (int) $post->ID, $state );
        };
        $state  = array(
            'request'    => $request,
            'post_type'  => $target['post_type'],
            'taxonomies' => $target['taxonomies'],
            'id'         => null === $target['id'] ? null : (int) ( $request->get_param( 'id' ) ?? $target['id'] ),
            'terms'      => $terms,
        );
        if ( 'attachment' !== $target['post_type'] || null !== $target['id'] ) {
            add_action( 'rest_insert_' . $target['post_type'], $insert, PHP_INT_MAX, 2 );
            $state['insert'] = $insert;
        }
        self::$pending[ $key ] = $state;
        return $response;
    }

    public static function after_callbacks( $response, $handler, $request ) {
        $state = $request instanceof \WP_REST_Request ? self::state( $request ) : null;
        if ( null === $state ) {
            return $response;
        }
        if ( isset( $state['terms'] ) && is_wp_error( $response ) && Narrow_Post_Write::STOPPED === $response->get_error_code() ) {
            return $response;
        }
        unset( self::$pending[ spl_object_id( $request ) ] );
        self::remove_filters( $state );
        if ( ( $state['set'] ?? null ) instanceof \WP_Error ) {
            return $state['set'];
        }
        if ( ! isset( $state['terms'] ) || isset( $state['set'] ) || is_wp_error( $response ) || ( $response instanceof \WP_REST_Response && $response->get_status() >= 400 ) ) {
            return $response;
        }
        $data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
        $id   = $state['id'] ?? ( is_array( $data ) && isset( $data['id'] ) ? (int) $data['id'] : 0 );
        if ( $id < 1 ) {
            return $response;
        }
        $set = self::set_terms( $id, $state );
        if ( $set instanceof \WP_Error ) {
            return $set;
        }
        if ( $response instanceof \WP_REST_Response && is_array( $data ) && array_key_exists( Status_Taxonomy::FIELD, $data ) ) {
            $data[ Status_Taxonomy::FIELD ] = array_map( 'intval', (array) wp_get_object_terms( $id, (string) end( $state['taxonomies'] ), array( 'fields' => 'ids' ) ) );
            $response->set_data( $data );
        }
        return $response;
    }

    private static function set_terms( int $id, array $state ) {
        foreach ( $state['taxonomies'] as $taxonomy ) {
            $set = wp_set_object_terms( $id, $state['terms'], $taxonomy );
            if ( is_wp_error( $set ) ) {
                return new \WP_Error( 'easy_mcp_ai_terms_not_set', sprintf( 'Item %1$d was saved, but its terms in taxonomy "%2$s" were not: %3$s', $id, $taxonomy, $set->get_error_message() ), array( 'status' => 500 ) );
            }
        }
        return true;
    }

    public static function give_back( \WP_REST_Request $request ): void {
        $state = self::state( $request );
        if ( null === $state || ! isset( $state['terms'] ) ) {
            return;
        }
        unset( self::$pending[ spl_object_id( $request ) ] );
        self::remove_filters( $state );
        $request->set_param( Status_Taxonomy::FIELD, $state['terms'] );
    }

    public static function taken_fields( \WP_REST_Request $request ): array {
        $state = self::state( $request );
        return null !== $state && isset( $state['terms'] ) ? array( Status_Taxonomy::FIELD ) : array();
    }

    public static function list_refusal( string $post_type, $sent ): ?string {
        $type = self::types()[ $post_type ] ?? null;
        if ( null === $type || null !== self::term_ids( $sent ) ) {
            return null;
        }
        $statuses = self::statuses( $sent );
        if ( null === $statuses || array() === array_diff( $statuses, array( $type['default'] ) ) ) {
            return null;
        }
        return Status_Taxonomy::message( sprintf( 'Only %1$s items with status %2$s can be listed', $post_type, $type['default'] ), $type['taxonomies'][0], sprintf( 'Send no status, or %s. Nothing was listed.', $type['default'] ) );
    }

    private static function collection( \WP_REST_Request $request, array $target ) {
        $sent    = $request->get_param( Status_Taxonomy::FIELD );
        $refusal = self::list_refusal( $target['post_type'], $sent );
        if ( null !== $refusal ) {
            return new \WP_Error( 'easy_mcp_ai_status_taxonomy', $refusal, array( 'status' => 400 ) );
        }
        if ( null === self::term_ids( $sent ) && null !== self::statuses( $sent ) ) {
            $request->offsetUnset( Status_Taxonomy::FIELD );
        }
        $default = $target['default'];
        $query   = static function ( $args, $query_request = null ) use ( $request, $default ) {
            if ( $query_request === $request && is_array( $args ) ) {
                $args['post_status'] = $default;
            }
            return $args;
        };
        add_filter( 'rest_' . $target['post_type'] . '_query', $query, PHP_INT_MIN, 2 );
        $route     = $target['route'];
        $endpoints = static function ( $routes ) use ( $route ) {
            if ( is_array( $routes ) && isset( $routes[ $route ] ) && is_array( $routes[ $route ] ) ) {
                foreach ( $routes[ $route ] as $i => $handler ) {
                    if ( is_array( $handler ) && isset( $handler['args'][ Status_Taxonomy::FIELD ] ) && is_array( $handler['args'][ Status_Taxonomy::FIELD ] ) ) {
                        unset( $routes[ $route ][ $i ]['args'][ Status_Taxonomy::FIELD ]['default'] );
                    }
                }
            }
            return $routes;
        };
        add_filter( 'rest_endpoints', $endpoints, PHP_INT_MAX );
        self::$pending[ spl_object_id( $request ) ] = array(
            'request'   => $request,
            'post_type' => $target['post_type'],
            'query'     => $query,
            'endpoints' => $endpoints,
        );
        return null;
    }

    private static function status_text( \WP_REST_Request $request, array $target ) {
        $sent    = $request->get_param( Status_Taxonomy::FIELD );
        $item_id = 'collection' === $target['kind'] ? null : ( null !== $request->get_param( 'id' ) ? (int) $request->get_param( 'id' ) : (int) $target['id'] );
        $refusal = self::write_refusal( $target['post_type'], $item_id, $sent );
        if ( null !== $refusal ) {
            return new \WP_Error( 'easy_mcp_ai_status_taxonomy', $refusal, array( 'status' => 400 ) );
        }
        if ( is_string( $sent ) && null === self::term_ids( $sent ) ) {
            $request->offsetUnset( Status_Taxonomy::FIELD );
        }
        return null;
    }

    public static function write_refusal( string $post_type, ?int $item_id, $sent ): ?string {
        $type = self::types()[ $post_type ] ?? null;
        if ( null === $type || ! is_string( $sent ) || null !== self::term_ids( $sent ) ) {
            return null;
        }
        if ( null === $item_id ) {
            $kept = $type['created'];
        } else {
            $post = get_post( $item_id );
            $kept = is_object( $post ) && $post_type === $post->post_type && current_user_can( 'edit_post', $item_id ) ? (string) $post->post_status : $sent;
        }
        if ( $sent === $kept ) {
            return null;
        }
        $lead = null === $item_id
            ? sprintf( 'New %s items keep the status WordPress gives them', $post_type )
            : sprintf( '%s items keep their status', $post_type );
        return Status_Taxonomy::message( $lead, $type['taxonomies'][0], sprintf( 'Send no status. Nothing was %s.', null === $item_id ? 'written' : 'changed' ) );
    }

    private static function target( \WP_REST_Request $request ): ?array {
        $route = (string) $request->get_route();
        foreach ( self::types() as $post_type => $type ) {
            $base = '/' . trim( $type['namespace'], '/' ) . '/' . trim( $type['rest_base'], '/' );
            if ( 0 === strcasecmp( $route, $base ) ) {
                $kind = 'collection';
                $id   = null;
            } elseif ( 1 === preg_match( '#^' . preg_quote( $base, '#' ) . '/(\d+)(/autosaves|/edit)?$#iD', $route, $m ) ) {
                $kind = array( '' => 'item', '/autosaves' => 'autosave', '/edit' => 'edit' )[ strtolower( $m[2] ?? '' ) ];
                $id   = (int) $m[1];
                if ( 'edit' === $kind && 'attachment' !== $post_type ) {
                    continue;
                }
            } else {
                continue;
            }
            return array(
                'post_type'  => $post_type,
                'taxonomies' => $type['taxonomies'],
                'kind'       => $kind,
                'id'         => $id,
                'default'    => $type['default'],
                'route'      => $base,
            );
        }
        return null;
    }

    private static function types(): array {
        $types = array();
        foreach ( (array) get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type => $object ) {
            $post_type  = (string) $post_type;
            $taxonomies = Status_Taxonomy::all( $post_type );
            if ( array() === $taxonomies || ! is_object( $object ) || ! self::uses_posts_controller( $object ) ) {
                continue;
            }
            $types[ $post_type ] = array(
                'taxonomies' => $taxonomies,
                'namespace'  => ! empty( $object->rest_namespace ) ? (string) $object->rest_namespace : 'wp/v2',
                'rest_base'  => ! empty( $object->rest_base ) ? (string) $object->rest_base : $post_type,
                'default'    => 'attachment' === $post_type ? 'inherit' : 'publish',
                'created'    => 'attachment' === $post_type ? 'inherit' : ( 'nav_menu_item' === $post_type ? 'publish' : 'draft' ),
            );
        }
        return $types;
    }

    private static function uses_posts_controller( object $object ): bool {
        if ( ! method_exists( $object, 'get_rest_controller' ) ) {
            return true;
        }
        $controller = $object->get_rest_controller();
        return ! class_exists( 'WP_REST_Posts_Controller', false ) || $controller instanceof \WP_REST_Posts_Controller;
    }

    private static function term_ids( $value ): ?array {
        if ( is_string( $value ) ) {
            $value = '' === trim( $value ) ? null : preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
        }
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            return null;
        }
        $ids = array();
        foreach ( $value as $id ) {
            if ( is_bool( $id ) || ! is_numeric( $id ) || (float) $id !== round( (float) $id ) ) {
                return null;
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    private static function statuses( $value ): ?array {
        if ( null === $value ) {
            return array();
        }
        if ( is_string( $value ) ) {
            $value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
        }
        if ( ! is_array( $value ) ) {
            return null;
        }
        $statuses = array();
        foreach ( $value as $one ) {
            if ( ! is_string( $one ) ) {
                return null;
            }
            $statuses[] = sanitize_key( $one );
        }
        return array_values( array_unique( $statuses ) );
    }

    private static function state( \WP_REST_Request $request ): ?array {
        $state = self::$pending[ spl_object_id( $request ) ] ?? null;
        return null !== $state && $state['request'] === $request ? $state : null;
    }

    private static function remove_filters( array $state ): void {
        if ( isset( $state['insert'] ) ) {
            remove_action( 'rest_insert_' . $state['post_type'], $state['insert'], PHP_INT_MAX );
        }
        if ( isset( $state['query'] ) ) {
            remove_filter( 'rest_' . $state['post_type'] . '_query', $state['query'], PHP_INT_MIN );
        }
        self::remove_endpoints_filter( $state );
    }

    private static function remove_endpoints_filter( array $state ): void {
        if ( isset( $state['endpoints'] ) ) {
            remove_filter( 'rest_endpoints', $state['endpoints'], PHP_INT_MAX );
        }
    }
}
