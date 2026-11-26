<?php
namespace Easy_MCP_AI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OAuth_Admin {

    public static function save_settings( $access_ttl, $refresh_ttl, $dcr_enabled ) {
        $access_ttl  = max( 60, (int) $access_ttl );
        $refresh_ttl = max( 60, (int) $refresh_ttl );

        \Easy_MCP_AI\Config::update( 'easy_mcp_ai_oauth_access_token_ttl', $access_ttl );
        \Easy_MCP_AI\Config::update( 'easy_mcp_ai_oauth_refresh_token_ttl', $refresh_ttl );
        \Easy_MCP_AI\Config::update( 'easy_mcp_ai_oauth_dcr_enabled', $dcr_enabled ? 1 : 0 );
    }

    public static function revoke_client( $client_id ) {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return false;
        }
        global $wpdb;

        $clients_table  = $wpdb->prefix . 'easy_mcp_ai_oauth_clients';
        $tokens_table   = $wpdb->prefix . 'easy_mcp_ai_oauth_access_tokens';
        $codes_table    = $wpdb->prefix . 'easy_mcp_ai_oauth_codes';
        $consents_table = $wpdb->prefix . 'easy_mcp_ai_oauth_consents';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( false === $wpdb->update( $consents_table, array( 'scope' => '' ), array( 'client_id' => $client_id ), array( '%s' ), array( '%s' ) ) ) {
            return self::revoke_client_failed( \__( 'The client was not deleted: the database refused emptying its grants first, so nothing was deleted, its tokens still work and it is still listed. Try again.', 'easy-mcp-ai' ) );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $tokens = $wpdb->delete( $tokens_table, array( 'client_id' => $client_id ), array( '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $codes = $wpdb->delete( $codes_table, array( 'client_id' => $client_id ), array( '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $consents = $wpdb->delete( $consents_table, array( 'client_id' => $client_id ), array( '%s' ) );

        $refused = array();
        foreach ( array(
            array( $tokens, \__( 'deleting its tokens', 'easy-mcp-ai' ) ),
            array( $codes, \__( 'deleting its unused authorization codes', 'easy-mcp-ai' ) ),
            array( $consents, \__( 'deleting its grants', 'easy-mcp-ai' ) ),
        ) as $write ) {
            if ( false === $write[0] ) {
                $refused[] = $write[1];
            }
        }
        if ( false === $tokens && ( false === $consents || ! \Easy_MCP_AI\OAuth\Role_Limit::column_ready() ) ) {
            /* translators: %s: the writes the database refused. */
            return self::revoke_client_failed( sprintf( \__( 'The client was not deleted: the database refused %s, so its tokens still work. It is still listed: try again.', 'easy-mcp-ai' ), self::joined( $refused ) ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete( $clients_table, array( 'client_id' => $client_id ), array( '%s' ) );
        if ( false === $deleted ) {
            $refused[] = \__( 'deleting the client', 'easy-mcp-ai' );
            /* translators: %s: the writes the database refused. */
            return self::revoke_client_failed( sprintf( \__( 'The client\'s tokens are refused, but the database refused %s, so the client is still listed. Delete it again to finish.', 'easy-mcp-ai' ), self::joined( $refused ) ) );
        }
        if ( array() !== $refused ) {
            /* translators: %s: the writes the database refused. */
            return self::revoke_client_failed( sprintf( \__( 'The client was deleted and its tokens are refused, but the database refused %s. Nothing more is needed.', 'easy-mcp-ai' ), self::joined( $refused ) ) );
        }

        return (int) $deleted > 0;
    }

    private static function revoke_client_failed( string $message ): \WP_Error {
        return new \WP_Error( 'easy_mcp_ai_client_not_deleted', $message, array( 'status' => 500 ) );
    }

    private static function joined( array $parts ): string {
        $last = array_pop( $parts );
        return array() === $parts ? (string) $last : implode( ', ', $parts ) . ' ' . \__( 'and', 'easy-mcp-ai' ) . ' ' . $last;
    }

    public static function revoke_grant( $consent_id ) {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return false;
        }
        $consent = self::revoke_consent( (int) $consent_id, 0 );
        return \is_wp_error( $consent ) ? $consent : null !== $consent;
    }

    public static function revoke_own_grant( int $consent_id ) {
        $user_id = \get_current_user_id();
        if ( $user_id < 1 ) {
            return null;
        }
        $consent = self::revoke_consent( $consent_id, $user_id );
        if ( null === $consent || \is_wp_error( $consent ) ) {
            return $consent;
        }
        return (string) $consent->client_id;
    }

    private static function revoke_consent( int $consent_id, int $owner_id ) {
        global $wpdb;

        $consents_table = $wpdb->prefix . 'easy_mcp_ai_oauth_consents';
        $tokens_table   = $wpdb->prefix . 'easy_mcp_ai_oauth_access_tokens';
        $where          = array( 'id' => $consent_id );
        $where_format   = array( '%d' );
        if ( $owner_id > 0 ) {
            $where['wp_user_id'] = $owner_id;
            $where_format[]      = '%d';
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; name prefixed by $wpdb->prefix.
        $owner_sql = $owner_id > 0 ? $wpdb->prepare( ' AND wp_user_id = %d', $owner_id ) : '';
        $consent   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT wp_user_id, client_id FROM {$consents_table} WHERE id = %d",
                $consent_id
            ) . $owner_sql
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $consent ) {
            if ( '' !== (string) $wpdb->last_error ) {
                return new \WP_Error(
                    'easy_mcp_ai_grant_revoke_failed',
                    \__( 'The connection was not revoked: the database refused reading it, so nothing changed and it still works. Please try again.', 'easy-mcp-ai' ),
                    array( 'status' => 500 )
                );
            }
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $emptied = $wpdb->update(
            $consents_table,
            array(
                'scope'      => '',
                'updated_at' => \current_time( 'mysql', true ),
            ),
            $where,
            array( '%s', '%s' ),
            $where_format
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deactivated = $wpdb->update(
            $tokens_table,
            array( 'is_active' => 0 ),
            array(
                'client_id'  => $consent->client_id,
                'wp_user_id' => $consent->wp_user_id,
            ),
            array( '%d' ),
            array( '%s', '%d' )
        );

        $deleted = null;
        if ( false !== $deactivated || \Easy_MCP_AI\OAuth\Role_Limit::column_ready() ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $deleted = false !== $wpdb->delete(
                $consents_table,
                $where,
                $where_format
            );
        }
        if ( false === $emptied || false === $deactivated || false === $deleted ) {
            return self::revoke_failed( false !== $emptied, false !== $deactivated, $deleted );
        }

        return $consent;
    }

    private static function revoke_failed( bool $emptied, bool $deactivated, ?bool $deleted ): \WP_Error {
        if ( true === $deleted ) {
            $refused = ! $emptied && ! $deactivated
                ? \__( 'emptying its permissions and deactivating its tokens', 'easy-mcp-ai' )
                : ( ! $emptied ? \__( 'emptying its permissions first', 'easy-mcp-ai' ) : \__( 'deactivating its tokens', 'easy-mcp-ai' ) );
            /* translators: %s: the writes the database refused. */
            $message = sprintf( \__( 'The connection was revoked: it was deleted and its tokens are refused, but the database refused %s. Nothing more is needed.', 'easy-mcp-ai' ), $refused );
        } elseif ( $deactivated && $emptied ) {
            $message = \__( 'The connection\'s tokens were deactivated, so it no longer works, but the database refused deleting it, so it is still listed. Revoke it again to finish.', 'easy-mcp-ai' );
        } elseif ( $deactivated ) {
            $message = \__( 'The connection\'s tokens were deactivated, but the database refused emptying its permissions and deleting it, so a token refreshed meanwhile may still work. It is still listed: revoke it again.', 'easy-mcp-ai' );
        } elseif ( null === $deleted ) {
            $message = \__( 'The connection was not revoked: the database refused deactivating its tokens, so it still works and is still listed. Please try again.', 'easy-mcp-ai' );
        } else {
            $message = \__( 'The connection was not revoked: the database refused deactivating its tokens and deleting it, so it still works. Please try again.', 'easy-mcp-ai' );
        }
        return new \WP_Error( 'easy_mcp_ai_grant_revoke_failed', $message, array( 'status' => 500 ) );
    }

    public static function save_scope( $consent_id, array $scopes ) {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return false;
        }

        $valid_scopes = \Easy_MCP_AI\OAuth\Scope_Map::get_all_scopes();

        $clean_scopes = array_values( array_intersect( $scopes, $valid_scopes ) );
        $new_scope    = implode( ' ', $clean_scopes );

        global $wpdb;

        $consents_table = $wpdb->prefix . 'easy_mcp_ai_oauth_consents';
        $tokens_table   = $wpdb->prefix . 'easy_mcp_ai_oauth_access_tokens';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; name prefixed by $wpdb->prefix.
        $consent = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT wp_user_id, client_id FROM {$consents_table} WHERE id = %d",
                $consent_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $consent ) {
            if ( '' !== (string) $wpdb->last_error ) {
                return new \WP_Error(
                    'easy_mcp_ai_scope_not_saved',
                    \__( 'The grant was not narrowed: the database refused reading it, so nothing changed. Try again.', 'easy-mcp-ai' ),
                    array( 'status' => 500 )
                );
            }
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $consent_update_result = $wpdb->update(
            $consents_table,
            array(
                'scope'      => $new_scope,
                'updated_at' => \current_time( 'mysql', true ),
            ),
            array( 'id' => $consent_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $token_update_result = $wpdb->update(
            $tokens_table,
            array( 'scope' => $new_scope ),
            array(
                'client_id'  => $consent->client_id,
                'wp_user_id' => $consent->wp_user_id,
                'is_active'  => 1,
            ),
            array( '%s' ),
            array( '%s', '%d', '%d' )
        );

        if ( false === $consent_update_result || false === $token_update_result ) {
            if ( false !== $consent_update_result ) {
                $message = \__( 'The grant was narrowed for new tokens, but the database refused narrowing its current tokens, so they keep their wider access until they expire. Save again to finish.', 'easy-mcp-ai' );
            } elseif ( false !== $token_update_result ) {
                $message = \__( 'The grant\'s current tokens were narrowed, but the database refused changing the grant itself, so a refreshed token may get the wider access back. Save again to finish.', 'easy-mcp-ai' );
            } else {
                $message = \__( 'The grant was not narrowed: the database refused changing it and its tokens, so nothing changed. Try again.', 'easy-mcp-ai' );
            }
            return new \WP_Error( 'easy_mcp_ai_scope_not_saved', $message, array( 'status' => 500 ) );
        }

        if ( 0 === $consent_update_result ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; name prefixed by $wpdb->prefix.
            $still = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT wp_user_id, client_id FROM {$consents_table} WHERE id = %d",
                    $consent_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( ! $still ) {
                if ( '' !== (string) $wpdb->last_error ) {
                    return new \WP_Error(
                        'easy_mcp_ai_scope_not_saved',
                        \__( 'The change could not be confirmed: the database refused reading the grant back. Reload the grants to see whether it is still there and what it allows.', 'easy-mcp-ai' ),
                        array( 'status' => 500 )
                    );
                }
                return false;
            }
        }

        return true;
    }
}
