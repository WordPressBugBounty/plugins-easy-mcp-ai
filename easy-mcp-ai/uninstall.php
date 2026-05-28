<?php






if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;


wp_clear_scheduled_hook( 'easy_mcp_ai_cleanup_audit_log' );
wp_clear_scheduled_hook( 'easy_mcp_ai_cleanup_oauth' );
wp_clear_scheduled_hook( 'easy_mcp_ai_cleanup_new_token_meta' );
wp_clear_scheduled_hook( 'easy_mcp_ai_cleanup_change_log' );


$options = array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    'easy_mcp_ai_db_version',
    'easy_mcp_ai_rate_limit_per_minute',
    'easy_mcp_ai_session_ttl_minutes',  
    'easy_mcp_ai_cors_origins',         
    'easy_mcp_ai_audit_log_retention',
    'easy_mcp_ai_enabled_categories',
    'easy_mcp_ai_ip_whitelist',
    'easy_mcp_ai_disabled_tools',
    'easy_mcp_ai_force_draft_on_create',
    'easy_mcp_ai_max_title_length',
    'easy_mcp_ai_audit_log_enabled',
    'easy_mcp_ai_allowed_tool_patterns',
    'easy_mcp_ai_enabled_abilities',
    'easy_mcp_ai_enabled_hooks',
    'easy_mcp_ai_allowed_plugins',          
    'easy_mcp_ai_admin_language',
    'easy_mcp_ai_enabled_plugin_groups',    
    'easy_mcp_ai_disabled_plugin_tools',   
    'easy_mcp_ai_oauth_db_version',        
    'easy_mcp_ai_oauth_access_token_ttl',  
    'easy_mcp_ai_oauth_refresh_token_ttl', 
    'easy_mcp_ai_oauth_dcr_enabled',       
    'easy_mcp_ai_oauth_max_clients',       
    'easy_mcp_ai_gsc_service_account_json', 
    'easy_mcp_ai_gsc_default_site_url',     
    'easy_mcp_ai_disabled_gsc_tools',       
    'easy_mcp_ai_ga_service_account_json',  
    'easy_mcp_ai_ga_default_property_id',   
    'easy_mcp_ai_disabled_ga_tools',        
    'easy_mcp_ai_gsc_sites_cache',          
    'easy_mcp_ai_ga_properties_cache',      
    'easy_mcp_ai_dfs_login',                
    'easy_mcp_ai_dfs_api_password',         
    'easy_mcp_ai_disabled_dfs_tools',       
    'easy_mcp_ai_semrush_api_key',          
    'easy_mcp_ai_disabled_semrush_tools',   
    'easy_mcp_ai_change_log_db_version',    
    'easy_mcp_ai_change_log_retention',     
    'easy_mcp_ai_change_log_enabled',       
);

if ( is_multisite() ) {
    
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_easy_mcp_ai_new_token_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $easy_mcp_ai_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $easy_mcp_ai_site_ids as $easy_mcp_ai_site_id ) {
        switch_to_blog( $easy_mcp_ai_site_id );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_tokens" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_audit_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_oauth_clients" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_oauth_codes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_oauth_access_tokens" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_oauth_consents" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_change_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
        foreach ( $options as $easy_mcp_ai_option ) {
            delete_option( $easy_mcp_ai_option );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_session_%', '_transient_timeout_easy_mcp_ai_session_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_rate_%', '_transient_timeout_easy_mcp_ai_rate_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_new_token_%', '_transient_timeout_easy_mcp_ai_new_token_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_dcr_rl_%', '_transient_timeout_easy_mcp_ai_dcr_rl_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_revoke_rl_%', '_transient_timeout_easy_mcp_ai_revoke_rl_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_token_rl_%', '_transient_timeout_easy_mcp_ai_token_rl_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_oat_lu_%', '_transient_timeout_easy_mcp_ai_oat_lu_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_oat_srv_%', '_transient_timeout_easy_mcp_ai_oat_srv_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_auth_fail_%', '_transient_timeout_easy_mcp_ai_auth_fail_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
        
        
        
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_easy_mcp_ai_ga_' ) . '%', $wpdb->esc_like( '_transient_timeout_easy_mcp_ai_ga_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_easy_mcp_ai_gsc_' ) . '%', $wpdb->esc_like( '_transient_timeout_easy_mcp_ai_gsc_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        
        
        
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_easy_mcp_ai_dfs_balance' ) . '%', $wpdb->esc_like( '_transient_timeout_easy_mcp_ai_dfs_balance' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        restore_current_blog();
    }
} else {
    
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_tokens" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_audit_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_oauth_clients" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_oauth_codes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_oauth_access_tokens" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_oauth_consents" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easy_mcp_ai_change_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema drop on uninstall.

    foreach ( $options as $easy_mcp_ai_option ) {
        delete_option( $easy_mcp_ai_option );
    }

    
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_session_%', '_transient_timeout_easy_mcp_ai_session_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_rate_%', '_transient_timeout_easy_mcp_ai_rate_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_new_token_%', '_transient_timeout_easy_mcp_ai_new_token_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_dcr_rl_%', '_transient_timeout_easy_mcp_ai_dcr_rl_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_revoke_rl_%', '_transient_timeout_easy_mcp_ai_revoke_rl_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_token_rl_%', '_transient_timeout_easy_mcp_ai_token_rl_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_oat_lu_%', '_transient_timeout_easy_mcp_ai_oat_lu_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_oat_srv_%', '_transient_timeout_easy_mcp_ai_oat_srv_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_easy_mcp_ai_auth_fail_%', '_transient_timeout_easy_mcp_ai_auth_fail_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for transient cleanup on uninstall.
    
    
    
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_easy_mcp_ai_ga_' ) . '%', $wpdb->esc_like( '_transient_timeout_easy_mcp_ai_ga_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_easy_mcp_ai_gsc_' ) . '%', $wpdb->esc_like( '_transient_timeout_easy_mcp_ai_gsc_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    
    
    
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_easy_mcp_ai_dfs_balance' ) . '%', $wpdb->esc_like( '_transient_timeout_easy_mcp_ai_dfs_balance' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_easy_mcp_ai_new_token_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
