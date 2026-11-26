<?php
namespace Easy_MCP_AI\Auth;

use Easy_MCP_AI\OAuth\Client_Metadata_Support;
use Easy_MCP_AI\OAuth\OAuth_Site_Move;
use Easy_MCP_AI\OAuth\OAuth_Token_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Usable_Credentials {

    public static function oauth_enabled(): bool {
        return (bool) \apply_filters( 'easy_mcp_ai_oauth_enabled', \Easy_MCP_AI\Config::get( 'oauth_enabled' ) );
    }

    public static function user_exists( int $wp_user_id ): bool {
        return $wp_user_id > 0 && false !== \get_userdata( $wp_user_id );
    }

    public static function api_keys( ?int $now = null ): ?array {
        global $wpdb;
        $now = null === $now ? time() : $now;
        if ( property_exists( $wpdb, 'last_error' ) ) {
            $wpdb->last_error = '';
        }
        $table = $wpdb->prefix . 'easy_mcp_ai_tokens';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table named from $wpdb->prefix; nothing to bind; the answer must be live.
        $rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE is_active = 1", ARRAY_A );
        if ( ! is_array( $rows ) || ( property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error ) ) {
            return null;
        }
        $keys = array();
        foreach ( $rows as $row ) {
            if ( null === self::key_refusal( $row, $now ) ) {
                $keys[ (int) $row['id'] ] = (int) $row['wp_user_id'];
            }
        }
        return $keys;
    }

    public static function key_refusal( array $row, int $now ): ?string {
        if ( empty( $row['is_active'] ) ) {
            return 'revoked';
        }
        if ( ! Token_Manager::key_is_live( $row, $now ) ) {
            return 'expired';
        }
        if ( ! self::user_exists( (int) ( $row['wp_user_id'] ?? 0 ) ) ) {
            return 'user_deleted';
        }
        if ( ! Token_Manager::key_is_held( $row ) ) {
            return 'key_removed';
        }
        if ( Token_Manager::is_bound_elsewhere( $row ) ) {
            return 'bound_elsewhere';
        }
        return null;
    }

    public static function grants( ?int $now = null ): ?array {
        global $wpdb;
        if ( ! self::oauth_enabled() ) {
            return array();
        }
        $now = null === $now ? time() : $now;
        if ( property_exists( $wpdb, 'last_error' ) ) {
            $wpdb->last_error = '';
        }
        $rows = OAuth_Site_Move::active_rows();
        if ( null === $rows ) {
            return null;
        }

        $consents = $wpdb->prefix . 'easy_mcp_ai_oauth_consents';
        $clients  = $wpdb->prefix . 'easy_mcp_ai_oauth_clients';
        $document = Client_Metadata_Support::schema_ready() ? 'cl.metadata_url' : 'NULL';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned tables named from $wpdb->prefix and a fixed column name; nothing to bind; the count must be live.
        $pairs = $wpdb->get_results(
            "SELECT con.client_id, con.wp_user_id, cl.is_active AS client_active, {$document} AS client_document FROM `{$consents}` con
             LEFT JOIN `{$clients}` cl ON cl.client_id = con.client_id",
            ARRAY_A
        );
        if ( ! is_array( $pairs ) || ( property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error ) ) {
            return null;
        }
        $documents_resolve = Client_Metadata_Support::resolves();
        $refresh_finds     = array();
        $approved          = array();
        foreach ( $pairs as $pair ) {
            $key              = self::pair_key( (string) $pair['client_id'], (int) $pair['wp_user_id'] );
            $approved[ $key ] = true;
            $refresh_finds[ (string) $pair['client_id'] ] = 1 === (int) ( $pair['client_active'] ?? 0 )
                && ( $documents_resolve || '' === (string) ( $pair['client_document'] ?? '' ) );
        }

        $usable = array();
        foreach ( $rows as $row ) {
            $key = self::pair_key( $row['client_id'], $row['wp_user_id'] );
            if ( $row['foreign'] || ! isset( $approved[ $key ] ) || ! self::user_exists( (int) $row['wp_user_id'] ) ) {
                continue;
            }
            $refreshable = ! empty( $refresh_finds[ $row['client_id'] ] ) && OAuth_Token_Manager::refresh_is_live( $row, $now );
            if ( $refreshable || OAuth_Token_Manager::access_is_live( $row, $now ) ) {
                $usable[ $key ] = true;
            }
        }
        return $usable;
    }

    public static function counts(): array {
        $keys   = self::api_keys();
        $grants = self::grants();
        return array(
            'keys'   => null === $keys ? null : count( $keys ),
            'grants' => null === $grants ? null : count( $grants ),
        );
    }

    public static function pair_key( string $client_id, int $wp_user_id ): string {
        return $client_id . ':' . $wp_user_id;
    }
}
