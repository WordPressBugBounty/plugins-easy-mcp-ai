<?php

namespace Easy_MCP_AI\Multilingual;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Translation_Read {

    const ALL = 'all';

    public static function properties(): array {
        $adapter = Translation_Registry::active();
        if ( null === $adapter ) {
            return array();
        }
        return array(
            'lang' => array(
                'type'        => 'string',
                'enum'        => array_merge( $adapter->codes(), array( self::ALL ) ),
                'description' => sprintf( 'List only posts in this %1$s language, or "all" for every language. Without it the list is what %1$s shows by default%2$s. Each row carries its lang.', $adapter->label(), 'wpml' === $adapter->id() ? ' (the default language only)' : ' (every language)' ),
            ),
        );
    }

    public static function requested( array $arguments ): ?string {
        if ( ! isset( $arguments['lang'] ) || '' === $arguments['lang'] ) {
            return null;
        }
        $adapter = Translation_Registry::active();
        if ( null === $adapter ) {
            throw new \InvalidArgumentException( 'lang needs WPML or Polylang, and neither is active on this site.' );
        }
        return self::ALL === $arguments['lang'] ? self::ALL : Translation_Write::code( $adapter, $arguments['lang'] );
    }

    public static function query_vars( ?string $code ): array {
        $adapter = Translation_Registry::active();
        return null === $code || null === $adapter ? array() : $adapter->read_query_vars( $code );
    }

    public static function run( ?string $code, callable $read ) {
        $adapter = Translation_Registry::active();
        return null === $code || null === $adapter ? $read() : $adapter->while_reading( $code, $read );
    }

    public static function search( \WP_REST_Request $request, ?string $code ): \WP_REST_Response {
        $vars = self::query_vars( $code );
        if ( array() === $vars ) {
            return rest_do_request( $request );
        }
        $filter = static function ( $args, $current ) use ( $request, $vars ) {
            return $current === $request && is_array( $args ) ? array_merge( $args, $vars ) : $args;
        };
        add_filter( 'rest_post_search_query', $filter, 10, 2 );
        try {
            return rest_do_request( $request );
        } finally {
            remove_filter( 'rest_post_search_query', $filter, 10 );
        }
    }

    public static function label_rows( array $rows ): array {
        $adapter = Translation_Registry::active();
        if ( null === $adapter ) {
            return $rows;
        }
        foreach ( $rows as $i => $row ) {
            if ( is_array( $row ) && isset( $row['id'] ) ) {
                $rows[ $i ]['lang'] = $adapter->language_of( (int) $row['id'] );
            }
        }
        return $rows;
    }

    public static function fields_for( int $post_id ): array {
        $adapter = Translation_Registry::active();
        $type    = (string) get_post_type( $post_id );
        if ( null === $adapter || '' === $type || ! $adapter->is_translated_type( $type ) ) {
            return array();
        }
        return array(
            'language'     => $adapter->language_of( $post_id ),
            'translations' => self::readable( $adapter->translations_of( $post_id ) ),
        );
    }

    public static function readable( array $group ): array {
        $out = array_filter(
            $group,
            static function ( int $id ): bool {
                return current_user_can( 'read_post', $id );
            }
        );
        ksort( $out );
        return $out;
    }
}
