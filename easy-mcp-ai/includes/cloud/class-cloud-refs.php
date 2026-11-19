<?php

namespace Easy_MCP_AI\Cloud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cloud_Refs {

    const OPTION_PREFIX = 'easy_mcp_ai_cloud_ref_';
    const REF_PATTERN   = '/^tpl_[0-9a-f]{32}$/D';
    const TTL_SECONDS   = 604800;
    const PRUNE_BATCH   = 50;
    const MAX_REFS      = 50;
    const EVICT_BATCH   = 1000;

    const DATABASE_REFUSAL_BYTES = 1000000;

    const MESSAGE_NOT_STORED = "Couldn't store the blocks on this site. Try again.";

    public static function put( string $markup, array $block_types, array $summary, ?int $now = null ): array {
        if ( strlen( $markup ) > Block_Checks::MAX_MARKUP_BYTES ) {
            throw new Cloud_Error( Block_Checks::MARKUP_TOO_LARGE );
        }
        $now = $now ?? time();
        self::prune( $now );
        self::evict_oldest( self::MAX_REFS - 1 );
        $hex        = bin2hex( random_bytes( 16 ) );
        $expires_at = gmdate( 'Y-m-d\TH:i:s', $now + self::TTL_SECONDS ) . '.000Z';
        $value = array(
            'expiresAt'  => $expires_at,
            'markup'     => $markup,
            'blockTypes' => array_values( $block_types ),
            'summary'    => $summary,
        );
        if ( ! \add_option( self::OPTION_PREFIX . $hex, $value, '', false ) ) {
            throw new Cloud_Error( strlen( $markup ) > self::DATABASE_REFUSAL_BYTES ? Block_Checks::MARKUP_TOO_LARGE : self::MESSAGE_NOT_STORED );
        }
        return array( 'ref' => 'tpl_' . $hex, 'expiresAt' => $expires_at );
    }

    public static function get( string $ref, ?int $now = null ): ?array {
        if ( 1 !== preg_match( self::REF_PATTERN, $ref ) ) {
            return null;
        }
        $name  = self::OPTION_PREFIX . substr( $ref, 4 );
        $value = \get_option( $name, null );
        if ( ! is_array( $value ) ) {
            return null;
        }
        $expires = isset( $value['expiresAt'] ) && is_string( $value['expiresAt'] ) ? strtotime( $value['expiresAt'] ) : false;
        if ( false === $expires || $expires <= ( $now ?? time() ) ) {
            \delete_option( $name );
            return null;
        }
        $summary = isset( $value['summary'] ) && is_array( $value['summary'] ) ? $value['summary'] : array();
        if ( ! isset( $value['markup'] ) || ! is_string( $value['markup'] ) || ! isset( $value['blockTypes'] ) || ! is_array( $value['blockTypes'] ) ) {
            return null;
        }
        return array(
            'markup'     => $value['markup'],
            'blockTypes' => array_values( array_filter( $value['blockTypes'], 'is_string' ) ),
            'summary'    => array(
                'sections'   => array_values( array_filter( isset( $summary['sections'] ) && is_array( $summary['sections'] ) ? $summary['sections'] : array(), 'is_string' ) ),
                'blockCount' => isset( $summary['blockCount'] ) ? (int) $summary['blockCount'] : 0,
            ),
            'expiresAt'  => $value['expiresAt'],
        );
    }

    public static function prune( ?int $now = null ): void {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
            return;
        }
        $now = $now ?? time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the head of each value is the point; options are not cached by prefix.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, SUBSTRING( option_value, 1, 120 ) AS head FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%',
                self::PRUNE_BATCH
            ),
            ARRAY_A
        );
        foreach ( (array) $rows as $row ) {
            $name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
            $head = isset( $row['head'] ) ? (string) $row['head'] : '';
            if ( 1 !== preg_match( '/^' . self::OPTION_PREFIX . '[0-9a-f]{32}$/D', $name ) ) {
                continue;
            }
            $expires = 1 === preg_match( '/"expiresAt";s:\d+:"([^"]+)"/', $head, $found ) ? strtotime( $found[1] ) : false;
            if ( false === $expires || $expires <= $now ) {
                \delete_option( $name );
            }
        }
    }

    private static function evict_oldest( int $keep ): void {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- names only; options are not cached by prefix.
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%',
                self::EVICT_BATCH,
                $keep
            )
        );
        foreach ( (array) $names as $name ) {
            if ( 1 === preg_match( '/^' . self::OPTION_PREFIX . '[0-9a-f]{32}$/D', (string) $name ) ) {
                \delete_option( (string) $name );
            }
        }
    }
}
