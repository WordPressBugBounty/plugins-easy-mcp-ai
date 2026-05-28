<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {

    public static function activate( $network_wide = false ) {
        if ( \is_multisite() && $network_wide ) {
            
            $sites = \get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
            foreach ( $sites as $blog_id ) {
                \switch_to_blog( $blog_id );
                try {
                    self::create_tables();
                    self::create_oauth_tables();
                    self::create_change_log_tables();
                    self::set_default_options();
                } finally {
                    \restore_current_blog();
                }
            }
        } else {
            self::create_tables();
            self::create_oauth_tables();
            self::create_change_log_tables();
            self::set_default_options();
        }
        if ( ! \wp_next_scheduled( 'easy_mcp_ai_cleanup_audit_log' ) ) {
            \wp_schedule_event( time(), 'daily', 'easy_mcp_ai_cleanup_audit_log' );
        }
        if ( ! \wp_next_scheduled( 'easy_mcp_ai_cleanup_oauth' ) ) {
            \wp_schedule_event( time(), 'daily', 'easy_mcp_ai_cleanup_oauth' );
        }
        if ( ! \wp_next_scheduled( 'easy_mcp_ai_cleanup_new_token_meta' ) ) {
            \wp_schedule_event( time(), 'daily', 'easy_mcp_ai_cleanup_new_token_meta' );
        }
        if ( ! \wp_next_scheduled( 'easy_mcp_ai_cleanup_change_log' ) ) {
            \wp_schedule_event( time(), 'daily', 'easy_mcp_ai_cleanup_change_log' );
        }
        \flush_rewrite_rules();
    }

    



    public static function maybe_upgrade() {
        if ( \get_option( 'easy_mcp_ai_db_version' ) !== EASY_MCP_AI_VERSION ) {
            self::create_tables();
        }
        
        
        self::maybe_upgrade_oauth_tables();
        self::maybe_upgrade_change_log_tables();
        if ( ! \wp_next_scheduled( 'easy_mcp_ai_cleanup_audit_log' ) ) {
            \wp_schedule_event( time(), 'daily', 'easy_mcp_ai_cleanup_audit_log' );
        }
        if ( ! \wp_next_scheduled( 'easy_mcp_ai_cleanup_oauth' ) ) {
            \wp_schedule_event( time(), 'daily', 'easy_mcp_ai_cleanup_oauth' );
        }
        if ( ! \wp_next_scheduled( 'easy_mcp_ai_cleanup_new_token_meta' ) ) {
            \wp_schedule_event( time(), 'daily', 'easy_mcp_ai_cleanup_new_token_meta' );
        }
        if ( ! \wp_next_scheduled( 'easy_mcp_ai_cleanup_change_log' ) ) {
            \wp_schedule_event( time(), 'daily', 'easy_mcp_ai_cleanup_change_log' );
        }
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $tokens_table = $wpdb->prefix . 'easy_mcp_ai_tokens';
        $audit_table  = $wpdb->prefix . 'easy_mcp_ai_audit_log';

        $sql = "CREATE TABLE {$tokens_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            token_hash varchar(64) NOT NULL,
            token_prefix varchar(14) NOT NULL,
            allowed_tools longtext NOT NULL,
            wp_user_id bigint(20) unsigned NOT NULL,
            last_used_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY wp_user_id (wp_user_id),
            KEY is_active (is_active)
        ) {$charset_collate};

        CREATE TABLE {$audit_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_id bigint(20) unsigned NOT NULL,
            tool_name varchar(255) NOT NULL,
            arguments longtext DEFAULT NULL,
            result_status varchar(20) NOT NULL DEFAULT 'success',
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY token_id (token_id),
            KEY tool_name (tool_name),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \dbDelta( $sql );
        \update_option( 'easy_mcp_ai_db_version', EASY_MCP_AI_VERSION );
    }

    



    private static function create_oauth_tables() {
        $schema_file = EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-schema.php';
        if ( file_exists( $schema_file ) ) {
            require_once $schema_file;
            \Easy_MCP_AI\OAuth\OAuth_Schema::create_tables();
        }
    }

    



    private static function maybe_upgrade_oauth_tables() {
        $schema_file = EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-schema.php';
        if ( file_exists( $schema_file ) ) {
            require_once $schema_file;
            \Easy_MCP_AI\OAuth\OAuth_Schema::maybe_upgrade();
        }
    }

    



    private static function create_change_log_tables() {
        $schema_file = EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-log-schema.php';
        if ( ! file_exists( $schema_file ) ) {
            return;
        }
        require_once $schema_file;
        \Easy_MCP_AI\History\Change_Log_Schema::create_tables();

        
        
        \add_option( 'easy_mcp_ai_change_log_retention', 30 );
        \add_option( 'easy_mcp_ai_change_log_enabled', true );

        self::ensure_view_all_history_cap();
    }

    









    private static function ensure_view_all_history_cap() {
        $admin_role = \get_role( 'administrator' );
        if ( $admin_role && ! $admin_role->has_cap( 'easy_mcp_ai_view_all_history' ) ) {
            $admin_role->add_cap( 'easy_mcp_ai_view_all_history' );
        }
    }

    




    private static function maybe_upgrade_change_log_tables() {
        $schema_file = EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-log-schema.php';
        if ( ! file_exists( $schema_file ) ) {
            return;
        }
        require_once $schema_file;
        \Easy_MCP_AI\History\Change_Log_Schema::maybe_upgrade();
        self::ensure_view_all_history_cap();
    }

    private static function set_default_options() {
        $defaults = array(
            'rate_limit_per_minute'  => 60,
            'audit_log_retention'    => 30,
            'ip_whitelist'           => '',
            'enabled_categories'     => array( 'posts', 'pages', 'media', 'taxonomy', 'comments', 'users', 'site', 'menus', 'plugins', 'themes' ),
            'allowed_tool_patterns'  => array(),
            'disabled_tools'         => array( 'wp_delete_post', 'wp_delete_page', 'wp_delete_media', 'wp_delete_comment', 'wp_delete_category', 'wp_delete_tag', 'wp_delete_user', 'wp_delete_block', 'wp_delete_cpt_item', 'wp_delete_menu', 'wp_delete_menu_item', 'wp_delete_revision', 'wp_create_user', 'wp_update_user' ),
            'enabled_abilities'      => array(),
            'enabled_hooks'          => array(),
        );
        foreach ( $defaults as $key => $value ) {
            if ( false === \get_option( 'easy_mcp_ai_' . $key ) ) {
                \update_option( 'easy_mcp_ai_' . $key, $value );
            }
        }
    }
}
