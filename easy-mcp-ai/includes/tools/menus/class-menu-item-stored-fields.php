<?php

namespace Easy_MCP_AI\Tools\Menus;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Menu_Item_Stored_Fields {

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

    public static function around( callable $send ) {
        add_filter( 'rest_pre_insert_nav_menu_item', array( self::class, 'keep' ), PHP_INT_MIN, 2 );
        add_filter( 'wp_insert_post_data', array( self::class, 'stored' ), PHP_INT_MIN, 2 );
        try {
            return $send();
        } finally {
            remove_filter( 'rest_pre_insert_nav_menu_item', array( self::class, 'keep' ), PHP_INT_MIN );
            remove_filter( 'wp_insert_post_data', array( self::class, 'stored' ), PHP_INT_MIN );
            self::$kept = array();
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
        $kept         = array();
        foreach ( self::FIELDS as $param => list( $argument, $column ) ) {
            if ( isset( $request[ $param ] ) || ( 'title' === $param && $turns_custom && '' === (string) $post->post_title ) ) {
                continue;
            }
            $prepared->{$argument} = (string) $post->{$column};
            $kept[ $column ]       = (string) $post->{$column};
        }
        self::$kept[ (int) $post->ID ] = $kept;
        foreach ( self::LINK_FIELDS as $param => list( $argument, $meta_key ) ) {
            if ( isset( $request[ $param ] ) ) {
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
