<?php

namespace Easy_MCP_AI\Drafts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Strict_Ceiling {

    const LIVE_STATUSES = array( 'publish', 'future', 'private' );

    const NOT_LIVE_STATUSES = array( 'draft', 'pending', 'auto-draft', 'trash', 'inherit' );

    const CAP_KEYS = array( 'publish_posts', 'edit_published_posts', 'delete_published_posts', 'edit_private_posts', 'delete_private_posts' );

    const SITE_CAPS = array( 'edit_theme_options', 'manage_options' );

    const COMMENT_CAPS = array( 'edit_comment', 'edit_comment_meta', 'add_comment_meta', 'delete_comment_meta' );

    const SHOWN_TYPES = array( 'wp_block' );

    const CHILD_TYPES = array( 'product_variation' );

    const POST_WRITE_CAPS = array( 'edit_post', 'delete_post', 'publish_post', 'edit_post_meta', 'add_post_meta', 'delete_post_meta' );

    private static $depth = 0;

    private static $denied = array();

    private static $post_caps = array();

    private static $refusals = 0;

    private static $save_refusals = 0;

    private static $drafted = 0;

    private static $last_post = 0;

    private static $last_cap_refusal = 0;

    private static $lifted = 0;

    private static $capable = 0;

    private static $last_save_refusal = null;

    private static $frames = array();

    public static function arm(): bool {
        if ( ! Draft_Mode::strict() ) {
            return false;
        }
        if ( 0 === self::$depth ) {
            self::$denied    = self::denied_caps();
            self::$post_caps = self::post_caps();
            \add_filter( 'map_meta_cap', array( self::class, 'filter_caps' ), PHP_INT_MAX, 4 );
            \add_filter( 'wp_insert_post_data', array( self::class, 'guard_save' ), PHP_INT_MAX, 2 );
            \add_filter( 'pre_delete_post', array( self::class, 'guard_delete' ), PHP_INT_MAX, 2 );
            \add_action( 'delete_user', array( self::class, 'guard_user_delete' ), PHP_INT_MIN, 1 );
            \add_action( 'wpmu_delete_user', array( self::class, 'guard_network_user_delete' ), PHP_INT_MIN, 1 );
            \add_action( 'remove_user_from_blog', array( self::class, 'guard_user_reassign' ), PHP_INT_MIN, 3 );
        }
        ++self::$depth;
        self::$frames[] = array( self::$lifted, self::$capable );
        self::$lifted   = 0;
        self::$capable = 0;
        return true;
    }

    public static function release(): void {
        if ( array() !== self::$frames ) {
            list( self::$lifted, self::$capable ) = array_pop( self::$frames );
        }
        if ( self::$depth > 0 && 0 === --self::$depth ) {
            \remove_filter( 'map_meta_cap', array( self::class, 'filter_caps' ), PHP_INT_MAX );
            \remove_filter( 'wp_insert_post_data', array( self::class, 'guard_save' ), PHP_INT_MAX );
            \remove_filter( 'pre_delete_post', array( self::class, 'guard_delete' ), PHP_INT_MAX );
            \remove_action( 'delete_user', array( self::class, 'guard_user_delete' ), PHP_INT_MIN );
            \remove_action( 'wpmu_delete_user', array( self::class, 'guard_network_user_delete' ), PHP_INT_MIN );
            \remove_action( 'remove_user_from_blog', array( self::class, 'guard_user_reassign' ), PHP_INT_MIN );
            self::$denied    = array();
            self::$post_caps = array();
        }
    }

    public static function is_armed(): bool {
        return self::$depth > 0;
    }

    public static function mark(): int {
        return self::$refusals;
    }

    public static function refused_since( int $mark ): bool {
        return self::$refusals > $mark;
    }

    public static function save_mark(): int {
        return self::$save_refusals;
    }

    public static function save_refused_since( int $mark ): bool {
        return self::$save_refusals > $mark;
    }

    public static function drafted(): int {
        return self::$drafted;
    }

    public static function last_save_refusal(): ?Strict_Refusal {
        return self::$last_save_refusal;
    }

    public static function last_post(): int {
        return self::$last_post;
    }

    public static function without( callable $check ) {
        ++self::$lifted;
        try {
            return $check();
        } finally {
            --self::$lifted;
        }
    }

    public static function user_can( string $cap, ...$args ): bool {
        return (bool) self::without(
            static function () use ( $cap, $args ) {
                return \current_user_can( $cap, ...$args );
            }
        );
    }

    public static function with_capabilities( callable $run ) {
        ++self::$capable;
        try {
            return $run();
        } finally {
            --self::$capable;
        }
    }

    public static function filter_caps( $caps, $cap, $user_id, $args ) {
        if ( self::$lifted > 0 || self::$capable > 0 || (int) $user_id !== (int) \get_current_user_id() ) {
            return $caps;
        }
        if ( in_array( $cap, self::COMMENT_CAPS, true ) ) {
            return self::comment_caps( $cap, (int) $user_id, (array) $args );
        }
        $first = is_array( $args ) ? reset( $args ) : null;
        $post  = is_numeric( $first ) ? \get_post( (int) $first ) : null;
        $known = is_object( $post ) && isset( $post->post_type, $post->ID );
        if ( $known ) {
            if ( ! self::is_content_type( (string) $post->post_type ) ) {
                return self::is_live_child( $post ) && is_string( $cap ) && isset( self::$post_caps[ $cap ] ) ? self::refuse_cap( (int) $post->ID ) : $caps;
            }
            if ( is_string( $cap ) && isset( self::$post_caps[ $cap ] ) && self::is_live( $post ) ) {
                return self::refuse_cap( (int) $post->ID );
            }
        }
        foreach ( array_merge( array( $cap ), (array) $caps ) as $checked ) {
            if ( is_string( $checked ) && isset( self::$denied[ $checked ] ) ) {
                return self::refuse_cap( $known ? (int) $post->ID : 0 );
            }
        }
        return $caps;
    }

    private static function comment_caps( string $cap, int $user_id, array $args ): array {
        $comment = isset( $args[0] ) && is_numeric( $args[0] ) ? \get_comment( (int) $args[0] ) : null;
        $post_id = is_object( $comment ) && isset( $comment->comment_post_ID ) ? (int) $comment->comment_post_ID : 0;
        if ( 0 !== $post_id && self::$last_post === $post_id && self::$last_cap_refusal === self::$refusals ) {
            --self::$refusals;
            self::$last_post        = 0;
            self::$last_cap_refusal = -1;
        }
        return (array) self::without(
            static function () use ( $cap, $user_id, $args ) {
                return \map_meta_cap( $cap, $user_id, ...array_values( $args ) );
            }
        );
    }

    public static function guard_save( $data, $postarr ) {
        if ( self::$lifted > 0 || ! is_array( $data ) ) {
            return $data;
        }
        $type = isset( $data['post_type'] ) ? (string) $data['post_type'] : 'post';
        $id   = is_array( $postarr ) && ! empty( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
        if ( ! self::is_content_type( $type ) ) {
            return $data;
        }
        $to = isset( $data['post_status'] ) ? (string) $data['post_status'] : '';
        if ( 0 === $id ) {
            if ( self::is_live_status( $to ) ) {
                $data['post_status'] = 'draft';
                ++self::$drafted;
            }
            return $data;
        }
        $from = self::stored_status( $id );
        if ( self::is_live_status( $from ) || self::is_live_status( $to ) ) {
            self::refuse_write( $id, Strict_Refusal::for_save( $id, $from, $to ) );
        }
        return $data;
    }

    public static function guard_delete( $delete, $post ) {
        if ( self::$lifted > 0 || ! is_object( $post ) || ! isset( $post->ID, $post->post_type ) ) {
            return $delete;
        }
        if ( self::is_content_type( (string) $post->post_type ) && self::is_live( $post ) ) {
            self::refuse_write( (int) $post->ID, Strict_Refusal::for_delete( (int) $post->ID, (string) $post->post_status ) );
        }
        if ( self::is_live_child( $post ) ) {
            self::refuse_write( (int) $post->ID, Strict_Refusal::for_child( (int) $post->ID, (int) $post->post_parent ) );
        }
        return $delete;
    }

    public static function guard_user_delete( $user_id ): void {
        if ( self::$lifted > 0 || ! is_numeric( $user_id ) ) {
            return;
        }
        $post_id = self::live_post_of( (int) $user_id );
        if ( 0 !== $post_id ) {
            self::refuse_write( $post_id, Strict_Refusal::for_user( (int) $user_id, $post_id ) );
        }
    }

    public static function guard_network_user_delete( $user_id ): void {
        if ( self::$lifted > 0 || ! is_numeric( $user_id ) ) {
            return;
        }
        $post_id = 0;
        foreach ( (array) \get_blogs_of_user( (int) $user_id ) as $blog ) {
            if ( ! is_object( $blog ) || ! isset( $blog->userblog_id ) ) {
                continue;
            }
            \switch_to_blog( (int) $blog->userblog_id );
            try {
                $post_id = self::live_post_of( (int) $user_id );
            } finally {
                \restore_current_blog();
            }
            if ( 0 !== $post_id ) {
                break;
            }
        }
        if ( 0 !== $post_id ) {
            self::refuse_write( $post_id, Strict_Refusal::for_user( (int) $user_id, $post_id ) );
        }
    }

    public static function guard_user_reassign( $user_id, $blog_id = 0, $reassign = 0 ): void {
        if ( self::$lifted > 0 || ! is_numeric( $user_id ) || empty( $reassign ) ) {
            return;
        }
        $post_id = self::live_post_of( (int) $user_id );
        if ( 0 !== $post_id ) {
            \restore_current_blog();
            self::refuse_write( $post_id, Strict_Refusal::for_user( (int) $user_id, $post_id ) );
        }
    }

    private static function live_post_of( int $user_id ): int {
        global $wpdb;
        $switched = function_exists( 'ms_is_switched' ) && \ms_is_switched();
        $listed   = $switched ? self::NOT_LIVE_STATUSES : self::live_statuses();
        $match    = $switched ? 'NOT IN' : 'IN';
        $statuses = implode( ', ', array_fill( 0, count( $listed ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholders are built above; one read per removal.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, MIN(ID) AS ID FROM {$wpdb->posts} WHERE post_author = %d AND post_status {$match} ( {$statuses} ) GROUP BY post_type", array_merge( array( $user_id ), $listed ) ) );
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $type = is_object( $row ) && isset( $row->post_type ) ? (string) $row->post_type : '';
            if ( '' !== $type && ( ! \post_type_exists( $type ) || self::is_content_type( $type ) ) ) {
                return (int) $row->ID;
            }
        }
        return 0;
    }

    public static function is_content_type( string $type ): bool {
        if ( in_array( $type, self::SHOWN_TYPES, true ) ) {
            return \post_type_exists( $type );
        }
        $object = \get_post_type_object( $type );
        return is_object( $object ) && \is_post_type_viewable( $object ) && empty( $object->exclude_from_search );
    }

    private static function is_live_child( $post ): bool {
        if ( ! isset( $post->post_type, $post->post_parent ) || ! in_array( (string) $post->post_type, self::CHILD_TYPES, true ) || ! $post->post_parent ) {
            return false;
        }
        $parent = \get_post( (int) $post->post_parent );
        return is_object( $parent ) && self::is_live_parent( $parent );
    }

    private static function is_live_parent( $parent ): bool {
        return isset( $parent->post_type ) && self::is_content_type( (string) $parent->post_type ) && self::is_live( $parent );
    }

    public static function denied_caps(): array {
        return self::cap_names( self::CAP_KEYS );
    }

    private static function post_caps(): array {
        return array_fill_keys( self::POST_WRITE_CAPS, true ) + self::cap_names( array( 'edit_post', 'delete_post', 'publish_post' ) );
    }

    private static function cap_names( array $keys ): array {
        $names = array();
        foreach ( self::content_types() as $type ) {
            foreach ( $keys as $key ) {
                $cap = isset( $type->cap->$key ) ? $type->cap->$key : '';
                if ( is_string( $cap ) && '' !== $cap && ! in_array( $cap, self::SITE_CAPS, true ) ) {
                    $names[ $cap ] = true;
                }
            }
        }
        return $names;
    }

    private static function content_types(): array {
        $types = array();
        foreach ( \get_post_types( array(), 'objects' ) as $type ) {
            if ( is_object( $type ) && isset( $type->cap, $type->name ) && self::is_content_type( (string) $type->name ) ) {
                $types[] = $type;
            }
        }
        return $types;
    }

    private static function is_live( $post ): bool {
        return isset( $post->post_status ) && self::is_live_status( (string) $post->post_status );
    }

    public static function live_statuses(): array {
        return array_values( array_unique( array_merge( self::LIVE_STATUSES, array_values( \get_post_stati( array( 'public' => true ) ) ) ) ) );
    }

    public static function is_live_status( string $status ): bool {
        return in_array( $status, self::live_statuses(), true );
    }

    private static function stored_status( int $post_id ): string {
        $post = \get_post( $post_id );
        return is_object( $post ) && isset( $post->post_status ) ? (string) $post->post_status : '';
    }

    private static function refuse_cap( int $post_id ): array {
        ++self::$refusals;
        self::$last_post        = $post_id;
        self::$last_cap_refusal = self::$refusals;
        return array( 'do_not_allow' );
    }

    private static function refuse_write( int $post_id, Strict_Refusal $refusal ): void {
        ++self::$refusals;
        ++self::$save_refusals;
        self::$last_post         = $post_id;
        self::$last_save_refusal = $refusal;
        throw $refusal;
    }
}
