<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Delete_Outcome {

    const TRASH = 'trash';

    const DELETED = 'deleted';

    const NOTHING_DELETED = 'Nothing was deleted.';

    public static function of_post( array $data, int $id, string $label ): array {
        return self::read( $data, $label, self::stored_post_status( $id, $label ) );
    }

    public static function of_comment( array $data, int $id ): array {
        $comment = self::read_back(
            static function () use ( $id ) {
                return get_comment( $id );
            },
            'comment ' . $id
        );
        return self::read( $data, 'comment ' . $id, is_object( $comment ) ? (string) $comment->comment_approved : null );
    }

    public static function stored_post_status( int $id, string $label = '' ): ?string {
        wp_cache_delete( $id, 'posts' );
        $post = self::read_back(
            static function () use ( $id ) {
                return get_post( $id );
            },
            '' === $label ? 'post ' . $id : $label
        );
        return is_object( $post ) ? (string) $post->post_status : null;
    }

    public static function hook_depth(): int {
        return isset( $GLOBALS['wp_current_filter'] ) && is_array( $GLOBALS['wp_current_filter'] ) ? count( $GLOBALS['wp_current_filter'] ) : 0;
    }

    public static function stored_option( string $name, bool $network = false ): ?array {
        global $wpdb;
        if ( $network && is_multisite() ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the stored row is the answer; a cache or filter could stand in for it.
            $sql = $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d LIMIT 1", $name, get_current_network_id() );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the stored row is the answer; a cache or filter could stand in for it.
            $sql = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name );
        }
        if ( isset( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
        $row = $wpdb->get_row( $sql, ARRAY_N );
        if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
            return null;
        }
        return is_array( $row ) ? array( true, maybe_unserialize( $row[0] ) ) : array( false, null );
    }

    public static function stored_theme_mods(): ?array {
        $row = self::stored_option( 'theme_mods_' . get_option( 'stylesheet' ) );
        if ( null === $row ) {
            return null;
        }
        $mods = $row[0] ? $row[1] : false;
        if ( false === $mods ) {
            $theme_name = get_option( 'current_theme' );
            if ( false === $theme_name ) {
                $theme_name = wp_get_theme()->get( 'Name' );
            }
            $legacy = self::stored_option( 'mods_' . $theme_name );
            if ( null === $legacy ) {
                return null;
            }
            $mods = $legacy[0] ? $legacy[1] : false;
        }
        return is_array( $mods ) ? $mods : array();
    }

    public static function refusal( string $format, array $parts, int $index = 0 ): \Exception {
        $whole = vsprintf( $format, $parts );
        if ( \Easy_MCP_AI\MCP\Refusal_Text::fits( $whole ) ) {
            return new \RuntimeException( $whole ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return new \Easy_MCP_AI\MCP\Detailed_Tool_Error( \Easy_MCP_AI\MCP\Refusal_Text::fit( $format, $parts, $index ), $whole ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    public static function stored_user( int $id, string $label ) {
        return self::read_back(
            static function () use ( $id ) {
                return get_userdata( $id );
            },
            $label
        );
    }

    public static function read_back( callable $lookup, string $label ) {
        global $wpdb;
        if ( is_object( $wpdb ) && isset( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
        }
        $found = $lookup();
        if ( ( null === $found || false === $found ) && is_object( $wpdb ) && '' !== (string) ( $wpdb->last_error ?? '' ) ) {
            throw new \RuntimeException( sprintf( 'A database error stopped %s being read back after the call, so whether it changed is not known. Check it before trying again.', $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $found;
    }

    public static function require_post_gone( int $id, string $label, string $after = self::NOTHING_DELETED ): void {
        $refusal = self::post_kept( $id, $label, $after );
        if ( null !== $refusal ) {
            throw new \RuntimeException( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    public static function post_kept( int $id, string $label, string $after = self::NOTHING_DELETED ): ?string {
        try {
            $status = self::stored_post_status( $id, $label );
        } catch ( \RuntimeException $e ) {
            return $e->getMessage();
        }
        return null === $status ? null : self::stopped( $label, $status, true, $after );
    }

    public static function hpos_order_kept( int $id, string $label ): ?string {
        global $wpdb;
        $store = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore';
        if ( ! is_callable( array( $store, 'get_orders_table_name' ) ) ) {
            return sprintf( 'WooCommerce answered that %s was deleted, but its orders table could not be named to confirm it. Check the order under WooCommerce › Orders.', $label );
        }
        $table = (string) call_user_func( array( $store, 'get_orders_table_name' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the stored row is the answer; the table name is WooCommerce's own.
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d LIMIT 1", $id ) );
        if ( '' !== (string) $wpdb->last_error ) {
            return sprintf( 'WooCommerce answered that %s was deleted, but a database error stopped the orders table being read to confirm it. Check the order under WooCommerce › Orders.', $label );
        }
        if ( null !== $row ) {
            return sprintf( 'WooCommerce answered that %s was deleted, but it is still in the orders table: WooCommerce does not check its own database delete. Some of the order\'s other data may already be gone; check the order under WooCommerce › Orders.', $label );
        }
        return null;
    }

    public static function require_term_gone( int $term_id, string $taxonomy, string $label ): void {
        wp_cache_delete( $term_id, 'terms' );
        $term = self::read_back(
            static function () use ( $term_id, $taxonomy ) {
                return get_term( $term_id, $taxonomy );
            },
            $label
        );
        if ( is_object( $term ) && ! is_wp_error( $term ) ) {
            throw new \RuntimeException( sprintf( 'WordPress answered that %s was deleted, but it still exists. A plugin or theme on this site may have answered the request in its place.', $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    public static function require_route_gone( string $route, string $label, string $gone_code, string $after = self::NOTHING_DELETED ): void {
        $response = rest_do_request( new \WP_REST_Request( 'GET', $route ) );
        if ( ! $response->is_error() ) {
            throw self::still_exists( $label, $after );
        }
        $status = (int) $response->get_status();
        $error  = $response->as_error();
        $code   = $error instanceof \WP_Error ? (string) $error->get_error_code() : '';
        if ( 404 !== $status || $gone_code !== $code ) {
            $named = '' === $code || (string) $status === $code ? 'with no error code' : $code;
            throw self::refusal( 'WordPress answered that %1$s was deleted, but reading it back answered %2$s %3$s, so whether it was deleted is not known. Check it before trying again.', array( $label, (string) $status, $named ) );
        }
    }

    public static function still_exists( string $label, string $after = self::NOTHING_DELETED ): \Exception {
        return self::refusal( '%s was not deleted: it still exists. A plugin or theme on this site stopped it. %s', array( ucfirst( $label ), $after ) );
    }

    public static function site_membership( int $user_id ): ?bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the stored row itself is the answer; a cache or filter could stand in for it.
        $row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
                $user_id,
                $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities'
            )
        );
        if ( '' !== (string) $wpdb->last_error ) {
            return null;
        }
        return null !== $row;
    }

    public static function require_meta_gone( string $meta_type, int $object_id, string $meta_key, $meta_value, string $object, string $name ): void {
        $kept = self::meta_kept( $meta_type, $object_id, $meta_key, $meta_value );
        if ( false === $kept ) {
            return;
        }
        $every = self::matches_every_row( wp_unslash( $meta_value ) );
        throw new \RuntimeException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            null === $kept
                ? sprintf( 'WordPress answered that this meta was deleted from %1$s, but a database error stopped it being read to confirm it. Key: %2$s', $object, $name )
                : sprintf( 'WordPress did not delete this meta from %1$s: %2$s is still stored. A plugin or theme on this site stopped it. Nothing was deleted. Key: %3$s', $object, $every ? 'the key' : 'a row with that value', $name )
        );
    }

    public static function meta_kept( string $meta_type, int $object_id, string $meta_key, $meta_value = '' ): ?bool {
        global $wpdb;
        $table = _get_meta_table( $meta_type );
        if ( ! $table ) {
            return null;
        }
        $id_column  = 'user' === $meta_type ? 'umeta_id' : 'meta_id';
        $column     = sanitize_key( $meta_type . '_id' );
        $meta_value = wp_unslash( $meta_value );
        $sql        = "SELECT $id_column FROM $table WHERE meta_key = %s AND $column = %d";
        $args       = array( wp_unslash( $meta_key ), $object_id );
        if ( ! self::matches_every_row( $meta_value ) ) {
            $sql   .= ' AND meta_value = %s';
            $args[] = maybe_serialize( $meta_value );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- core's own delete query; $table and the columns come from _get_meta_table() and the meta type, not input.
        $row = $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', ...$args ) );
        if ( '' !== (string) $wpdb->last_error ) {
            return null;
        }
        return null !== $row;
    }

    public static function stored_meta_values( string $meta_type, int $object_id, string $meta_key ): ?array {
        global $wpdb;
        $table = _get_meta_table( $meta_type );
        if ( ! $table ) {
            return null;
        }
        $id_column = 'user' === $meta_type ? 'umeta_id' : 'meta_id';
        $column    = sanitize_key( $meta_type . '_id' );
        if ( isset( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the stored rows are the answer; $table and the columns come from _get_meta_table() and the meta type, not input.
        $values = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM $table WHERE meta_key = %s AND $column = %d ORDER BY $id_column", wp_unslash( $meta_key ), $object_id ) );
        if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
            return null;
        }
        return array_map( 'strval', is_array( $values ) ? $values : array() );
    }

    public static function stored_meta_keys( string $meta_type, int $object_id ): ?array {
        global $wpdb;
        $table = _get_meta_table( $meta_type );
        if ( ! $table ) {
            return null;
        }
        $column = sanitize_key( $meta_type . '_id' );
        if ( isset( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the stored rows are the answer; $table and the column come from _get_meta_table() and the meta type, not input.
        $keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM $table WHERE $column = %d", $object_id ) );
        if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
            return null;
        }
        return array_map( 'strval', is_array( $keys ) ? $keys : array() );
    }

    private static function matches_every_row( $meta_value ): bool {
        return '' === $meta_value || null === $meta_value || false === $meta_value;
    }

    private static function read( array $data, string $label, ?string $stored ): array {
        $permanent = true === ( $data['deleted'] ?? null );
        $item      = $permanent ? ( is_array( $data['previous'] ?? null ) ? $data['previous'] : array() ) : $data;
        if ( null === $stored ) {
            return array( 'status' => self::DELETED, 'item' => $item );
        }
        if ( ! $permanent && self::TRASH === $stored ) {
            return array( 'status' => self::TRASH, 'item' => $item );
        }
        throw new \RuntimeException( self::stopped( $label, $stored, $permanent ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    private static function stopped( string $label, string $status, bool $permanent, string $after = self::NOTHING_DELETED ): string {
        return sprintf(
            $permanent
                ? 'WordPress did not delete %1$s: it is still "%2$s". A plugin or theme on this site stopped it. %3$s'
                : 'WordPress did not move %1$s to the Trash: it is still "%2$s". A plugin or theme on this site stopped it. %3$s',
            $label,
            $status,
            $after
        );
    }
}
