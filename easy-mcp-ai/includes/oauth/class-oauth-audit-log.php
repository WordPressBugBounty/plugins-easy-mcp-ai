<?php

namespace Easy_MCP_AI\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OAuth_Audit_Log {

    public static function write( string $tool_name, array $details, string $status, string $client_id = '', int $wp_user_id = 0 ): bool {
        if ( ! \Easy_MCP_AI\Config::get( 'easy_mcp_ai_audit_log_enabled', true ) ) {
            return false;
        }
        global $wpdb;
        return (int) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct insert required for audit logging; mirrors Server::log_auth_failure().
            $wpdb->prefix . 'easy_mcp_ai_audit_log',
            array(
                'token_id'        => 0,
                'tool_name'       => $tool_name,
                'arguments'       => wp_json_encode( $details ),
                'result_status'   => $status,
                'ip_address'      => class_exists( '\\Easy_MCP_AI\\Client_IP' ) ? \Easy_MCP_AI\Client_IP::get() : '',
                'auth_source'     => 'oauth',
                'wp_user_id'      => $wp_user_id > 0 ? $wp_user_id : null,
                'oauth_client_id' => Authorization_Endpoint::column_safe_client_id( Client_Metadata::storage_id( $client_id ) ),
                'created_at'      => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        ) > 0;
    }
}
