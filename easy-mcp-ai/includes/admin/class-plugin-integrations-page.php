<?php
namespace Easy_MCP_AI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin_Integrations_Page {

    public function __construct() {
        \add_action( 'admin_init', array( $this, 'handle_save' ) );
    }

    public function register_submenu() {
        \add_submenu_page(
            'easy-mcp-ai',
            \__( 'Plugin Integrations', 'easy-mcp-ai' ),
            \__( 'Plugins', 'easy-mcp-ai' ),
            'manage_options',
            'easy-mcp-ai-plugin-integrations',
            array( $this, 'render' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['easy_mcp_ai_save_plugin_integrations'] ) ) {
            return;
        }
        if ( ! \check_admin_referer( 'easy_mcp_ai_plugin_integrations' ) ) {
            return;
        }
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }

        $submitted_groups = isset( $_POST['enabled_groups'] )
            ? array_map( 'sanitize_key', \wp_unslash( (array) $_POST['enabled_groups'] ) )
            : array();

        $submitted_tools = isset( $_POST['enabled_tools'] )
            ? array_map( 'sanitize_text_field', \wp_unslash( (array) $_POST['enabled_tools'] ) )
            : array();

        $all_groups = Plugin_Integration_Registry::get_groups();

        
        
        
        
        $enabled_groups = array();
        foreach ( $all_groups as $group ) {
            if ( ! Plugin_Integration_Registry::is_installed( $group ) ) {
                continue;
            }
            $group_checked = in_array( $group['slug'], $submitted_groups, true );
            $any_tool_checked = false;
            foreach ( $group['tools'] as $tool ) {
                if ( in_array( $tool['name'], $submitted_tools, true ) ) {
                    $any_tool_checked = true;
                    break;
                }
            }
            if ( $group_checked || $any_tool_checked ) {
                $enabled_groups[] = $group['slug'];
            }
        }

        
        
        $disabled_plugin_tools = array();
        foreach ( $all_groups as $group ) {
            if ( ! in_array( $group['slug'], $enabled_groups, true ) ) {
                
                foreach ( $group['tools'] as $tool ) {
                    $disabled_plugin_tools[] = $tool['name'];
                }
                continue;
            }
            foreach ( $group['tools'] as $tool ) {
                if ( ! in_array( $tool['name'], $submitted_tools, true ) ) {
                    $disabled_plugin_tools[] = $tool['name'];
                }
            }
        }

        \update_option( 'easy_mcp_ai_enabled_plugin_groups', $enabled_groups );
        \update_option( 'easy_mcp_ai_disabled_plugin_tools',  $disabled_plugin_tools );

        
        
        
        $all_plugin_tool_names = Plugin_Integration_Registry::get_all_tool_names();
        $global_disabled       = (array) \get_option( 'easy_mcp_ai_disabled_tools', array() );
        $non_plugin            = array_values( array_diff( $global_disabled, $all_plugin_tool_names ) );
        $disabled_gsc_tools    = (array) \get_option( 'easy_mcp_ai_disabled_gsc_tools', array() );
        $disabled_ga_tools     = (array) \get_option( 'easy_mcp_ai_disabled_ga_tools', array() );
        $disabled_dfs_tools    = (array) \get_option( 'easy_mcp_ai_disabled_dfs_tools', array() );
        $disabled_semrush_tools = (array) \get_option( 'easy_mcp_ai_disabled_semrush_tools', array() );
        \update_option( 'easy_mcp_ai_disabled_tools', array_values( array_unique( array_merge( $non_plugin, $disabled_plugin_tools, $disabled_gsc_tools, $disabled_ga_tools, $disabled_dfs_tools, $disabled_semrush_tools ) ) ) );

        \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-plugin-integrations&message=saved' ) );
        exit;
    }

    public function render() {
        $all_groups      = Plugin_Integration_Registry::get_groups();
        $enabled_groups  = (array) \get_option( 'easy_mcp_ai_enabled_plugin_groups', array() );
        $disabled_tools  = (array) \get_option( 'easy_mcp_ai_disabled_plugin_tools',  array() );
        $message         = isset( $_GET['message'] ) ? \sanitize_text_field( \wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        
        $groups = array();
        foreach ( $all_groups as $group ) {
            $group['is_installed'] = Plugin_Integration_Registry::is_installed( $group );
            $groups[]              = $group;
        }

        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/views/plugin-integrations.php';
        easy_mcp_ai_view_plugin_integrations( $groups, $enabled_groups, $disabled_tools, $message );
    }
}
