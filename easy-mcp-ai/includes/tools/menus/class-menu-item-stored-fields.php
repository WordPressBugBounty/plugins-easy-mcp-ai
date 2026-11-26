<?php

namespace Easy_MCP_AI\Tools\Menus;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Menu_Item_Stored_Fields {

    const LENT_URL = '#';

    const LENT_TITLE = '#';

    const LENT = array(
        'title' => self::LENT_TITLE,
        'url'   => self::LENT_URL,
    );

    const FIELDS = array(
        'title'       => array( 'menu-item-title', 'post_title' ),
        'description' => array( 'menu-item-description', 'post_content' ),
        'attr_title'  => array( 'menu-item-attr-title', 'post_excerpt' ),
    );

    const LINK_FIELDS = array(
        'url'     => array( 'menu-item-url', '_menu_item_url' ),
        'target'  => array( 'menu-item-target', '_menu_item_target' ),
        'classes' => array( 'menu-item-classes', '_menu_item_classes' ),
        'xfn'     => array( 'menu-item-xfn', '_menu_item_xfn' ),
    );

    private static $kept = array();

    private static $lent_in = null;

    private static $lent_fields = array();

    private static $depth = 0;

    public static function lend( array $item, array $params ): array {
        if ( 'custom' !== ( $params['type'] ?? 'custom' ) ) {
            return array();
        }
        $empty = array(
            'title' => List_Menu_Items::shows_no_title( $item ),
            'url'   => List_Menu_Items::shows_no_url( $item ),
        );
        return array_diff_key( array_intersect_key( self::LENT, array_filter( $empty ) ), $params );
    }

    public static function around( callable $send, ?\WP_REST_Request $lent_in = null, array $lent_fields = array() ) {
        if ( 0 === self::$depth ) {
            add_filter( 'rest_pre_insert_nav_menu_item', array( self::class, 'keep' ), PHP_INT_MIN, 2 );
            add_filter( 'wp_insert_post_data', array( self::class, 'stored' ), PHP_INT_MIN, 2 );
        }
        ++self::$depth;
        $outer_kept        = self::$kept;
        $outer_lent_in     = self::$lent_in;
        $outer_lent_fields = self::$lent_fields;
        self::$lent_in     = $lent_in;
        self::$lent_fields = $lent_fields;
        try {
            return $send();
        } finally {
            self::$lent_in     = $outer_lent_in;
            self::$lent_fields = $outer_lent_fields;
            --self::$depth;
            if ( 0 === self::$depth ) {
                remove_filter( 'rest_pre_insert_nav_menu_item', array( self::class, 'keep' ), PHP_INT_MIN );
                remove_filter( 'wp_insert_post_data', array( self::class, 'stored' ), PHP_INT_MIN );
                self::$kept = array();
            } else {
                self::$kept = $outer_kept;
            }
        }
    }

    public static function keep( $prepared, $request ) {
        if ( ! is_object( $prepared ) || ! $request instanceof \WP_REST_Request || empty( $prepared->{'menu-item-db-id'} ) ) {
            return $prepared;
        }
        $post = get_post( (int) $prepared->{'menu-item-db-id'} );
        if ( ! is_object( $post ) || 'nav_menu_item' !== ( $post->post_type ?? '' ) ) {
            return $prepared;
        }
        $turns_custom = 'custom' === ( $prepared->{'menu-item-type'} ?? '' )
            && 'custom' !== (string) get_post_meta( (int) $post->ID, '_menu_item_type', true );
        $sent         = self::sent_fields( $request );
        $kept         = array();
        foreach ( self::FIELDS as $param => list( $argument, $column ) ) {
            if ( isset( $sent[ $param ] ) || ( 'title' === $param && $turns_custom && '' === (string) $post->post_title ) ) {
                continue;
            }
            $prepared->{$argument} = (string) $post->{$column};
            $kept[ $column ]       = (string) $post->{$column};
        }
        self::$kept[ (int) $post->ID ] = $kept;
        foreach ( self::LINK_FIELDS as $param => list( $argument, $meta_key ) ) {
            if ( isset( $sent[ $param ] ) ) {
                continue;
            }
            $stored = get_post_meta( (int) $post->ID, $meta_key, true );
            $stored = is_array( $stored ) ? implode( ' ', $stored ) : (string) $stored;
            if ( 'url' === $param && $turns_custom && '' === $stored ) {
                continue;
            }
            $prepared->{$argument} = $stored;
        }
        if ( empty( $prepared->{'menu-item-post-date'} ) && empty( $prepared->{'menu-item-post-date-gmt'} ) ) {
            $prepared->{'menu-item-post-date'}     = (string) ( $post->post_date ?? '' );
            $prepared->{'menu-item-post-date-gmt'} = (string) ( $post->post_date_gmt ?? '' );
        }
        return $prepared;
    }

    private static function sent_fields( \WP_REST_Request $request ): array {
        $sent = array();
        foreach ( array_merge( array_keys( self::FIELDS ), array_keys( self::LINK_FIELDS ) ) as $param ) {
            $lent = $request === self::$lent_in && in_array( $param, self::$lent_fields, true );
            if ( isset( $request[ $param ] ) && ! $lent ) {
                $sent[ $param ] = true;
            }
        }
        return $sent;
    }

    public static function stored( $data, $postarr ) {
        $id = is_array( $postarr ) ? (int) ( $postarr['ID'] ?? 0 ) : 0;
        if ( ! is_array( $data ) || ! isset( self::$kept[ $id ] ) ) {
            return $data;
        }
        foreach ( self::$kept[ $id ] as $column => $value ) {
            $data[ $column ] = wp_slash( $value );
        }
        unset( self::$kept[ $id ] );
        return $data;
    }
}
