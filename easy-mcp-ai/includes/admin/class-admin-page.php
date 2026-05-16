<?php
namespace Easy_MCP_AI\Admin;

use Easy_MCP_AI\Auth\Token_Manager;
use Easy_MCP_AI\Tools\Tool_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Page {

    private $token_manager;
    private $tool_registry;
    private $plugin_integrations_page;

    public function __construct( Token_Manager $token_manager, Tool_Registry $tool_registry ) {
        $this->token_manager             = $token_manager;
        $this->tool_registry             = $tool_registry;
        $this->plugin_integrations_page  = new Plugin_Integrations_Page();
        \add_action( 'admin_menu', array( $this, 'register_menus' ) );
        \add_action( 'admin_menu', array( $this, 'register_external_data_menu' ), 11 );
        \add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        \add_action( 'admin_init', array( $this, 'handle_form_actions' ) );
    }

    public function register_menus() {
        \add_menu_page( __( 'Easy MCP AI for WP', 'easy-mcp-ai' ), __( 'Easy MCP AI', 'easy-mcp-ai' ), 'manage_options', 'easy-mcp-ai', array( $this, 'render_dashboard' ), 'dashicons-rest-api', 80 );
        \add_submenu_page( 'easy-mcp-ai', __( 'Dashboard', 'easy-mcp-ai' ), __( 'Dashboard', 'easy-mcp-ai' ), 'manage_options', 'easy-mcp-ai', array( $this, 'render_dashboard' ) );
        \add_submenu_page( 'easy-mcp-ai', __( 'API Token & OAuth', 'easy-mcp-ai' ), __( 'API Token & OAuth', 'easy-mcp-ai' ), 'manage_options', 'easy-mcp-ai-tokens', array( $this, 'render_tokens_page' ) );
        \add_submenu_page( 'easy-mcp-ai', __( 'Audit Log', 'easy-mcp-ai' ), __( 'Audit Log', 'easy-mcp-ai' ), 'manage_options', 'easy-mcp-ai-audit', array( $this, 'render_audit_page' ) );
        \add_submenu_page( 'easy-mcp-ai', __( 'Settings', 'easy-mcp-ai' ), __( 'Settings', 'easy-mcp-ai' ), 'manage_options', 'easy-mcp-ai-settings', array( $this, 'render_settings_page' ) );
        $this->plugin_integrations_page->register_submenu();
    }

    public function register_external_data_menu() {
        \add_submenu_page( 'easy-mcp-ai', __( 'External Data', 'easy-mcp-ai' ), __( 'External Data', 'easy-mcp-ai' ), 'manage_options', 'easy-mcp-ai-external-data', array( $this, 'render_external_data_page' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'easy-mcp-ai' ) ) { return; }
        \wp_enqueue_style( 'easy-mcp-ai-admin', EASY_MCP_AI_PLUGIN_URL . 'assets/css/admin.css', array(), EASY_MCP_AI_VERSION );
        \wp_enqueue_script( 'easy-mcp-ai-admin', EASY_MCP_AI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), EASY_MCP_AI_VERSION, true );
        if ( false !== strpos( $hook, 'plugin-integrations' ) ) {
            \add_thickbox();
        }
    }

    public function handle_form_actions() {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( isset( $_POST['easy_mcp_ai_create_token'] ) && \check_admin_referer( 'easy_mcp_ai_create_token' ) ) {
            $name          = isset( $_POST['token_name'] ) ? sanitize_text_field( wp_unslash( $_POST['token_name'] ) ) : '';
            $wp_user_id    = isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : \get_current_user_id();
            $expires_at    = isset( $_POST['expires_at'] ) && ! empty( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : null;
            $allowed_tools = isset( $_POST['allowed_tools'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['allowed_tools'] ) ) : array( '*' );
            if ( ! $this->is_assignable_user( $wp_user_id ) ) {
                \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&action=new&error=invalid_user' ) );
                exit;
            }
            $this->handle_create_token( $name, $wp_user_id, $allowed_tools, $expires_at );
        }
        if ( isset( $_POST['easy_mcp_ai_update_token'] ) && \check_admin_referer( 'easy_mcp_ai_update_token' ) ) {
            $token_id      = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;
            $name          = isset( $_POST['token_name'] ) ? sanitize_text_field( wp_unslash( $_POST['token_name'] ) ) : '';
            $wp_user_id    = isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0;
            $expires_at    = isset( $_POST['expires_at'] ) && ! empty( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : null;
            $allowed_tools = isset( $_POST['allowed_tools'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['allowed_tools'] ) ) : array( '*' );
            $is_active     = isset( $_POST['is_active'] ) ? 1 : 0;
            if ( ! $this->is_assignable_user( $wp_user_id ) ) {
                \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&error=invalid_user' ) );
                exit;
            }
            $this->handle_update_token( $token_id, $name, $wp_user_id, $allowed_tools, $expires_at, $is_active );
        }
        if ( isset( $_GET['action'] ) && 'revoke' === $_GET['action'] && isset( $_GET['token_id'] ) ) {
            if ( \check_admin_referer( 'revoke_token_' . absint( $_GET['token_id'] ) ) ) {
                $this->token_manager->revoke_token( absint( $_GET['token_id'] ) );
                \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&message=revoked' ) );
                exit;
            }
        }
        if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['token_id'] ) ) {
            if ( \check_admin_referer( 'delete_token_' . absint( $_GET['token_id'] ) ) ) {
                $this->token_manager->delete_token( absint( $_GET['token_id'] ) );
                \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&message=deleted' ) );
                exit;
            }
        }
        if ( isset( $_POST['easy_mcp_ai_save_settings'] ) && \check_admin_referer( 'easy_mcp_ai_save_settings' ) ) {
            $this->handle_save_settings( array(
                'rate_limit_per_minute' => isset( $_POST['rate_limit_per_minute'] ) ? absint( $_POST['rate_limit_per_minute'] ) : 60,
                'audit_log_retention'   => isset( $_POST['audit_log_retention'] ) ? absint( $_POST['audit_log_retention'] ) : 30,
                'ip_whitelist'          => isset( $_POST['ip_whitelist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ip_whitelist'] ) ) : '',
                'disabled_tools'        => isset( $_POST['disabled_tools'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['disabled_tools'] ) ) : array(),
                'force_draft_on_create' => isset( $_POST['force_draft_on_create'] ) ? 1 : 0,
                'max_title_length'      => isset( $_POST['max_title_length'] ) ? absint( $_POST['max_title_length'] ) : 0,
                'audit_log_enabled'     => isset( $_POST['audit_log_enabled'] ) ? 1 : 0,
                'allowed_tool_patterns' => isset( $_POST['allowed_tool_patterns'] ) ? sanitize_text_field( wp_unslash( $_POST['allowed_tool_patterns'] ) ) : '',
                'admin_language'        => isset( $_POST['admin_language'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_language'] ) ) : '',
            ) );
        }
        if ( isset( $_POST['easy_mcp_ai_cleanup_audit'] ) && \check_admin_referer( 'easy_mcp_ai_cleanup_audit' ) ) {
            $this->handle_cleanup_audit();
        }
    }

    



    private function is_assignable_user( $user_id ) {
        if ( ! $user_id ) {
            return false;
        }
        $user = \get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }
        $min_cap = apply_filters( 'easy_mcp_ai_oauth_min_capability', 'publish_posts' );
        return \user_can( $user, $min_cap );
    }

    private function handle_create_token( $name, $wp_user_id, $allowed_tools, $expires_at ) {
        if ( empty( $name ) ) {
            \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&action=new&error=name_required' ) );
            exit;
        }
        $result = $this->token_manager->create_token( $name, $wp_user_id, $allowed_tools, $expires_at );
        if ( \is_wp_error( $result ) ) {
            \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&action=new&error=create_failed' ) );
            exit;
        }
        \update_user_meta( \get_current_user_id(), '_easy_mcp_ai_new_token_' . $result['id'], array(
            'token'   => $result['raw_token'],
            'expires' => time() + 60,
        ) );
        \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&message=created&token_id=' . $result['id'] ) );
        exit;
    }

    private function handle_update_token( $token_id, $name, $wp_user_id, $allowed_tools, $expires_at, $is_active ) {
        if ( ! $token_id ) {
            \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&error=invalid_token' ) );
            exit;
        }
        $this->token_manager->update_token( $token_id, array(
            'name' => $name, 'wp_user_id' => $wp_user_id, 'allowed_tools' => $allowed_tools,
            'expires_at' => $expires_at, 'is_active' => $is_active,
        ) );
        \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&message=updated' ) );
        exit;
    }

    private function handle_save_settings( array $post_data ) {
        $patterns    = array_values( array_filter( array_map( 'trim', explode( ',', $post_data['allowed_tool_patterns'] ) ) ) );
        $ip_whitelist = $this->sanitize_ip_whitelist( $post_data['ip_whitelist'] );

        $settings = array(
            'rate_limit_per_minute'  => max( 1, min( 1000, $post_data['rate_limit_per_minute'] ) ),
            'audit_log_retention'    => max( 1, min( 365, $post_data['audit_log_retention'] ) ),
            'ip_whitelist'           => $ip_whitelist['value'],
            
            
            
            'disabled_tools'         => array_values( array_unique( array_merge(
                (array) $post_data['disabled_tools'],
                (array) \get_option( 'easy_mcp_ai_disabled_plugin_tools', array() ),
                (array) \get_option( 'easy_mcp_ai_disabled_gsc_tools', array() ),
                (array) \get_option( 'easy_mcp_ai_disabled_ga_tools', array() ),
                (array) \get_option( 'easy_mcp_ai_disabled_dfs_tools', array() ),
                (array) \get_option( 'easy_mcp_ai_disabled_semrush_tools', array() )
            ) ) ),
            'force_draft_on_create'  => $post_data['force_draft_on_create'],
            'max_title_length'       => max( 0, $post_data['max_title_length'] ),
            'audit_log_enabled'      => $post_data['audit_log_enabled'],
            'allowed_tool_patterns'  => $patterns,
            'admin_language'         => $post_data['admin_language'],
        );
        foreach ( $settings as $key => $value ) {
            \update_option( 'easy_mcp_ai_' . $key, $value );
        }

        $redirect = \admin_url( 'admin.php?page=easy-mcp-ai-settings&message=saved' );
        if ( ! empty( $ip_whitelist['invalid'] ) ) {
            $redirect = \add_query_arg( 'ip_invalid', implode( ',', $ip_whitelist['invalid'] ), $redirect );
        }
        \wp_safe_redirect( $redirect );
        exit;
    }

    private function sanitize_ip_whitelist( $raw ) {
        if ( empty( trim( $raw ) ) ) {
            return array( 'value' => '', 'invalid' => array() );
        }
        $lines   = preg_split( '/\r\n|\r|\n/', $raw );
        $valid   = array();
        $invalid = array();
        foreach ( $lines as $line ) {
            
            list( $ip_part ) = explode( '#', $line, 2 );
            $ip_part         = trim( $ip_part );

            if ( '' === $ip_part ) {
                continue;
            }

            if ( false !== strpos( $ip_part, '/' ) ) {
                if ( $this->is_valid_cidr( $ip_part ) ) {
                    $valid[] = $ip_part;
                } else {
                    $invalid[] = $line;
                }
            } elseif ( filter_var( $ip_part, FILTER_VALIDATE_IP ) ) {
                $valid[] = $ip_part;
            } else {
                $invalid[] = $line;
            }
        }
        return array(
            'value'   => implode( "\n", $valid ),
            'invalid' => $invalid,
        );
    }

    private function is_valid_cidr( $cidr ) {
        if ( substr_count( $cidr, '/' ) !== 1 ) {
            return false;
        }
        list( $subnet, $prefix ) = explode( '/', $cidr, 2 );
        if ( ! ctype_digit( $prefix ) ) {
            return false;
        }
        $prefix_int = (int) $prefix;
        if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            return $prefix_int <= 32;
        }
        if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            return $prefix_int <= 128;
        }
        return false;
    }

    private function handle_cleanup_audit() {
        global $wpdb;
        $retention = (int) \get_option( 'easy_mcp_ai_audit_log_retention', 30 );
        
        
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct DB call for audit cleanup.
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}easy_mcp_ai_audit_log WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 500", $retention ) );
        } while ( $deleted > 0 );
        \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-audit&message=cleaned' ) );
        exit;
    }

    public function render_dashboard() {
        global $wpdb;
        $endpoint_url  = \rest_url( 'easy-mcp-ai/v1/mcp' );
        $token_count   = $this->token_manager->count_tokens();
        $tool_groups   = $this->build_dashboard_tool_groups();
        $tool_count    = $tool_groups['total'];
        $client_guides = self::get_client_guides();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, no user input
        $oauth_client_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}easy_mcp_ai_oauth_clients WHERE is_active = 1" );

        $external_data_integrations = array(
            __( 'Google Search Console', 'easy-mcp-ai' ) => \get_option( 'easy_mcp_ai_gsc_service_account_json', '' ) !== '',
            __( 'Google Analytics', 'easy-mcp-ai' )      => \get_option( 'easy_mcp_ai_ga_service_account_json', '' ) !== '',
            __( 'DataForSEO', 'easy-mcp-ai' )            => \get_option( 'easy_mcp_ai_dfs_login', '' ) !== '',
            __( 'SEMrush', 'easy-mcp-ai' )               => \get_option( 'easy_mcp_ai_semrush_api_key', '' ) !== '',
        );

        require EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/views/dashboard.php';
    }

    









    private function build_dashboard_tool_groups() {
        $core_category_labels = array(
            'posts'     => 'Posts',
            'pages'     => 'Pages',
            'media'     => 'Media',
            'taxonomy'  => 'Taxonomy',
            'comments'  => 'Comments',
            'users'     => 'Users',
            'site'      => 'Site Settings',
            'menus'     => 'Menus',
            'plugins'   => 'Plugins',
            'themes'    => 'Themes',
            'revisions' => 'Revisions',
            'meta'      => 'Post Meta',
            'search'    => 'Search',
            'blocks'    => 'Blocks',
            'cpt'       => 'Custom Post Types',
            'templates' => 'Templates',
            'styles'    => 'Global Styles',
            'general'   => 'General',
        );

        
        $known_plugins = array(
            'woocommerce'     => array( 'label' => 'WooCommerce',                 'class' => 'WooCommerce',             'fn' => 'WC' ),
            'acf'             => array( 'label' => 'Advanced Custom Fields (ACF)','class' => 'ACF',                     'fn' => 'acf' ),
            'events_calendar' => array( 'label' => 'The Events Calendar',         'class' => 'Tribe__Events__Main',     'fn' => '' ),
            'buddypress'      => array( 'label' => 'BuddyPress',                  'class' => 'BuddyPress',              'fn' => 'bp_is_active' ),
            'seo_yoast'       => array( 'label' => 'Yoast SEO',                   'class' => 'WPSEO_Options',           'fn' => '' ),
            'seo_rankmath'    => array( 'label' => 'Rank Math SEO',               'class' => 'RankMath',                'fn' => '' ),
            'seo_aioseo'      => array( 'label' => 'All in One SEO',              'class' => 'AIOSEO\Plugin\AIOSEO',    'fn' => 'aioseo' ),
        );

        
        $known_external = array(
            'gsc' => array( 'label' => 'Google Search Console', 'option' => 'easy_mcp_ai_gsc_service_account_json' ),
            'ga'  => array( 'label' => 'Google Analytics',      'option' => 'easy_mcp_ai_ga_service_account_json' ),
            'dfs' => array( 'label' => 'DataforSEO',             'option' => 'easy_mcp_ai_dfs_login' ),
            'semrush' => array( 'label' => 'Semrush',             'option' => 'easy_mcp_ai_semrush_api_key' ),
        );

        $tools_by_category = $this->tool_registry->get_tools_by_category();

        
        $core           = array();
        $total          = 0;
        $ability_defs   = array();
        $disabled_tools   = (array) \get_option( 'easy_mcp_ai_disabled_tools', array() );
        $allowed_patterns = (array) \get_option( 'easy_mcp_ai_allowed_tool_patterns', array() );

        
        
        
        $is_tool_active = function ( $tool ) use ( $disabled_tools, $allowed_patterns ) {
            $name = $tool['name'];
            if ( in_array( $name, $disabled_tools, true ) ) {
                return false;
            }
            if ( ! empty( $allowed_patterns ) ) {
                foreach ( $allowed_patterns as $pattern ) {
                    if ( fnmatch( $pattern, $name ) ) {
                        return true;
                    }
                }
                return false;
            }
            return true;
        };

        foreach ( $tools_by_category as $category => $tools ) {
            $active_tools = array_values( array_filter( $tools, $is_tool_active ) );
            $total       += count( $active_tools );
            if ( 'abilities' === $category ) {
                $ability_defs = $tools;
            } elseif ( isset( $core_category_labels[ $category ] ) ) {
                $core[ $core_category_labels[ $category ] ] = $active_tools;
            }
        }

        
        $plugins = array();
        foreach ( $known_plugins as $category => $info ) {
            $installed = ( ! empty( $info['class'] ) && \class_exists( $info['class'] ) )
                || ( ! empty( $info['fn'] ) && \function_exists( $info['fn'] ) );
            $tools     = isset( $tools_by_category[ $category ] )
                ? array_values( array_filter( $tools_by_category[ $category ], $is_tool_active ) )
                : array();

            if ( ! $installed ) {
                $status = 'not_installed';
            } elseif ( empty( $tools ) ) {
                $status = 'no_tools';
            } else {
                $status = 'active';
            }

            $plugins[ $info['label'] ] = array(
                'status' => $status,
                'tools'  => $tools,
            );
        }

        
        $external = array();
        foreach ( $known_external as $category => $info ) {
            $configured = ! empty( \get_option( $info['option'], '' ) );
            $tools      = isset( $tools_by_category[ $category ] )
                ? array_values( array_filter( $tools_by_category[ $category ], $is_tool_active ) )
                : array();

            if ( ! $configured ) {
                $status = 'not_configured';
            } elseif ( empty( $tools ) ) {
                $status = 'no_tools';
            } else {
                $status = 'active';
            }

            $external[ $info['label'] ] = array(
                'status' => $status,
                'tools'  => $tools,
            );
        }

        
        $abilities = array();

        $ability_def_by_name = array();
        foreach ( $ability_defs as $def ) {
            $ability_def_by_name[ $def['name'] ] = $def;
        }

        
        $normalize_slug = function ( $s ) {
            return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $s ) );
        };

        
        $resolve_ability = function ( $ability, $enabled_abilities ) use ( $ability_def_by_name ) {
            $name       = $ability->get_name();
            $is_enabled = in_array( $name, $enabled_abilities, true );
            $tool_name  = 'wp_ability_' . trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( $name ) ), '_' );
            $tool_def   = isset( $ability_def_by_name[ $tool_name ] ) ? $ability_def_by_name[ $tool_name ] : null;
            return array( 'is_enabled' => $is_enabled, 'tool_def' => $tool_def );
        };

        if ( function_exists( 'wp_get_abilities' ) ) {
            $wp_abilities      = \wp_get_abilities();
            $enabled_abilities = (array) \get_option( 'easy_mcp_ai_enabled_abilities', array() );

            
            
            $by_prefix = array();
            foreach ( $wp_abilities as $ability ) {
                $name        = $ability->get_name();
                $parts       = explode( '/', $name, 2 );
                $raw_prefix  = count( $parts ) > 1 ? $parts[0] : 'core';
                $prefix_norm = $normalize_slug( $raw_prefix );
                if ( ! isset( $by_prefix[ $prefix_norm ] ) ) {
                    $by_prefix[ $prefix_norm ] = array( 'raw_prefix' => $raw_prefix, 'abilities' => array() );
                }
                $by_prefix[ $prefix_norm ]['abilities'][] = $ability;
            }

            
            if ( isset( $by_prefix['core'] ) ) {
                $tools        = array();
                $any_not_enabled = false;
                foreach ( $by_prefix['core']['abilities'] as $ability ) {
                    $r = $resolve_ability( $ability, $enabled_abilities );
                    if ( ! $r['is_enabled'] ) { $any_not_enabled = true; }
                    if ( $r['tool_def'] ) { $tools[] = $r['tool_def']; }
                }
                $abilities['Core'] = array(
                    'tools'           => $tools,
                    'is_known'        => false,
                    'has_abilities'   => true,
                    'any_not_enabled' => $any_not_enabled,
                );
                unset( $by_prefix['core'] );
            }

            
            $known_norms = array();
            foreach ( $known_plugins as $category => $info ) {
                $known_norms[ $normalize_slug( $info['label'] ) ] = true;
                $known_norms[ $normalize_slug( $category ) ]      = true;
            }

            
            $matched_prefixes = array();

            if ( function_exists( 'get_plugins' ) ) {
                $all_plugins  = \get_plugins();
                $active_paths = (array) \get_option( 'active_plugins', array() );
                if ( \is_multisite() ) {
                    $active_paths = array_merge( $active_paths, array_keys( (array) \get_site_option( 'active_sitewide_plugins', array() ) ) );
                }

                foreach ( $active_paths as $plugin_path ) {
                    if ( ! isset( $all_plugins[ $plugin_path ] ) ) { continue; }
                    $plugin_name = $all_plugins[ $plugin_path ]['Name'];
                    if ( false !== stripos( $plugin_name, 'Easy MCP' ) ) { continue; }

                    $folder = ( false !== strpos( $plugin_path, '/' ) )
                        ? explode( '/', $plugin_path )[0]
                        : pathinfo( $plugin_path, PATHINFO_FILENAME );
                    $folder_norm = $normalize_slug( $folder );

                    
                    $tools           = array();
                    $has_abilities   = false;
                    $any_not_enabled = false;
                    if ( isset( $by_prefix[ $folder_norm ] ) ) {
                        $has_abilities = true;
                        foreach ( $by_prefix[ $folder_norm ]['abilities'] as $ability ) {
                            $r = $resolve_ability( $ability, $enabled_abilities );
                            if ( ! $r['is_enabled'] ) { $any_not_enabled = true; }
                            if ( $r['tool_def'] ) { $tools[] = $r['tool_def']; }
                        }
                        $matched_prefixes[ $folder_norm ] = true;
                    }

                    $is_known = isset( $known_norms[ $folder_norm ] )
                        || isset( $known_norms[ $normalize_slug( $plugin_name ) ] );

                    $abilities[ $plugin_name ] = array(
                        'tools'           => $tools,
                        'is_known'        => $is_known,
                        'has_abilities'   => $has_abilities,
                        'any_not_enabled' => $any_not_enabled,
                    );
                }
            }

            
            foreach ( $by_prefix as $prefix_norm => $data ) {
                if ( isset( $matched_prefixes[ $prefix_norm ] ) ) { continue; }
                $label = ucwords( str_replace( array( '-', '_' ), ' ', $data['raw_prefix'] ) );
                if ( isset( $abilities[ $label ] ) ) { continue; }

                $tools           = array();
                $any_not_enabled = false;
                foreach ( $data['abilities'] as $ability ) {
                    $r = $resolve_ability( $ability, $enabled_abilities );
                    if ( ! $r['is_enabled'] ) { $any_not_enabled = true; }
                    if ( $r['tool_def'] ) { $tools[] = $r['tool_def']; }
                }
                $abilities[ $label ] = array(
                    'tools'           => $tools,
                    'is_known'        => false,
                    'has_abilities'   => true,
                    'any_not_enabled' => $any_not_enabled,
                );
            }

            
            $core_entry = isset( $abilities['Core'] ) ? $abilities['Core'] : null;
            unset( $abilities['Core'] );
            ksort( $abilities );
            if ( null !== $core_entry ) {
                $abilities = array_merge( array( 'Core' => $core_entry ), $abilities );
            }
        } else {
            
            $abilities['Core'] = array(
                'tools'           => $ability_defs,
                'is_known'        => false,
                'has_abilities'   => ! empty( $ability_defs ),
                'any_not_enabled' => false,
            );
        }

        
        
        
        
        $bucket_disables       = array_merge(
            (array) \get_option( 'easy_mcp_ai_disabled_plugin_tools', array() ),
            (array) \get_option( 'easy_mcp_ai_disabled_ga_tools', array() ),
            (array) \get_option( 'easy_mcp_ai_disabled_gsc_tools', array() ),
            (array) \get_option( 'easy_mcp_ai_disabled_dfs_tools', array() ),
            (array) \get_option( 'easy_mcp_ai_disabled_semrush_tools', array() )
        );
        $settings_only_disabled = array_diff( $disabled_tools, $bucket_disables );
        $has_global_overrides   = ! empty( $settings_only_disabled ) || ! empty( $allowed_patterns );

        
        
        $disabled_abilities_present = false;
        foreach ( $abilities as $g ) {
            if ( ! empty( $g['any_not_enabled'] ) ) {
                $disabled_abilities_present = true;
                break;
            }
        }

        
        
        $enabled_plugin_groups     = (array) \get_option( 'easy_mcp_ai_enabled_plugin_groups', array() );
        $disabled_plugin_tools_raw = (array) \get_option( 'easy_mcp_ai_disabled_plugin_tools', array() );
        $disabled_plugin_tools_present = false;
        foreach ( Plugin_Integration_Registry::get_groups() as $group ) {
            if ( ! Plugin_Integration_Registry::is_installed( $group ) ) {
                continue;
            }
            
            if ( ! in_array( $group['slug'], $enabled_plugin_groups, true ) ) {
                $disabled_plugin_tools_present = true;
                break;
            }
            
            $group_tool_names = array_column( $group['tools'], 'name' );
            if ( ! empty( array_intersect( $disabled_plugin_tools_raw, $group_tool_names ) ) ) {
                $disabled_plugin_tools_present = true;
                break;
            }
        }

        
        $ga_configured       = ! empty( \get_option( 'easy_mcp_ai_ga_service_account_json', '' ) );
        $gsc_configured      = ! empty( \get_option( 'easy_mcp_ai_gsc_service_account_json', '' ) );
        $dfs_configured      = ! empty( \get_option( 'easy_mcp_ai_dfs_login', '' ) ) && ! empty( \get_option( 'easy_mcp_ai_dfs_api_password', '' ) );
        $semrush_configured  = ! empty( \get_option( 'easy_mcp_ai_semrush_api_key', '' ) );
        $disabled_ga_present  = $ga_configured  && ! empty( (array) \get_option( 'easy_mcp_ai_disabled_ga_tools', array() ) );
        $disabled_gsc_present = $gsc_configured && ! empty( (array) \get_option( 'easy_mcp_ai_disabled_gsc_tools', array() ) );
        $disabled_dfs_present = $dfs_configured && ! empty( (array) \get_option( 'easy_mcp_ai_disabled_dfs_tools', array() ) );

        
        $ga_missing       = ! $ga_configured;
        $gsc_missing      = ! $gsc_configured;
        $dfs_missing      = ! $dfs_configured;
        $semrush_missing  = ! $semrush_configured;

        return array(
            'total'    => $total,
            'core'     => $core,
            'plugins'  => $plugins,
            'external' => $external,
            'abilities'=> $abilities,
            'hints'    => array(
                'has_global_overrides'         => $has_global_overrides,
                'disabled_abilities_present'   => $disabled_abilities_present,
                'disabled_plugin_tools_present'=> $disabled_plugin_tools_present,
                'disabled_ga_present'          => $disabled_ga_present,
                'disabled_gsc_present'         => $disabled_gsc_present,
                'disabled_dfs_present'         => $disabled_dfs_present,
                'ga_missing'                   => $ga_missing,
                'gsc_missing'                  => $gsc_missing,
                'dfs_missing'                  => $dfs_missing,
                'semrush_missing'              => $semrush_missing,
            ),
        );
    }

    











    public static function get_client_guides() {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        $auth  = array( 'Authorization' => 'Bearer YOUR_API_TOKEN' );

        



        $mcp_servers_json = function ( array $server ) use ( $flags ) {
            return wp_json_encode( array( 'mcpServers' => array( 'wordpress' => $server ) ), $flags );
        };

        
        $http_config            = $mcp_servers_json( array( 'type' => 'http', 'url' => '%s', 'headers' => $auth ) );
        $cline_style_config     = $mcp_servers_json( array( 'autoApprove' => array(), 'disabled' => false, 'transportType' => 'streamableHttp', 'url' => '%s', 'headers' => $auth ) );

        return array(
            
            array(
                'id'     => 'manus',
                'name'   => __( 'Manus', 'easy-mcp-ai' ),
                'hint'   => __( 'In Manus, open Connectors > Custom MCP and fill in the configuration form with the following values:', 'easy-mcp-ai' ),
                'link'   => 'https://manus.im/app#settings/connectors',
                'link_label' => __( 'Open Settings', 'easy-mcp-ai' ),
                'config' => "Server Name:    WordPress\nTransport Type: HTTP\nServer URL:     %s\n\nCustom headers:\n  Header name:  Authorization\n  Header value: Bearer YOUR_API_TOKEN",
                'show_url_copy' => true,
                'note'   => __( 'Manus uses a form (not JSON). Leave Icon and Note empty, or add your own description.', 'easy-mcp-ai' ),
                'signup_link'       => 'https://manus.im/invitation/BOMGVX7BSFBJLX?utm_source=invitation&utm_medium=plugin&utm_campaign=easymcpaicom',
                'signup_label'      => __( 'Sign up for Manus', 'easy-mcp-ai' ),
            ),
            array(
                'id'           => 'claude-connector',
                'name'         => __( 'Claude.ai (Claude Connector)', 'easy-mcp-ai' ),
                'oauth_steps'  => array(
                    __( 'Go to Settings > Connectors', 'easy-mcp-ai' ),
                    __( 'Click Add connector', 'easy-mcp-ai' ),
                    __( 'Paste the MCP endpoint URL (below) as the server URL', 'easy-mcp-ai' ),
                    __( 'Set the name to "WordPress" (or anything)', 'easy-mcp-ai' ),
                    __( 'Click Save then Connect — OAuth is handled automatically, no token needed', 'easy-mcp-ai' ),
                ),
                'oauth_config' => '%s',
                'hint'         => __( 'Alternative — add a connector with a token embedded in the URL:', 'easy-mcp-ai' ),
                'config'       => '%s/YOUR_API_TOKEN',
                'note'         => __( 'Replace YOUR_API_TOKEN with your actual token (e.g. wpmcp_abc123…).', 'easy-mcp-ai' ),
                'link'         => 'https://claude.ai/settings/connectors',
                'link_label'   => __( 'Open Settings', 'easy-mcp-ai' ),
            ),
            array(
                'id'           => 'chatgpt',
                'name'         => __( 'ChatGPT (Developer Mode)', 'easy-mcp-ai' ),
                'oauth_steps'  => array(
                    __( 'Go to Settings > Apps > Advanced settings and enable Developer Mode', 'easy-mcp-ai' ),
                    __( 'Go to Create apps', 'easy-mcp-ai' ),
                    __( 'Enter a name (e.g. "WordPress") and paste the MCP endpoint URL (below)', 'easy-mcp-ai' ),
                    __( 'Select OAuth as the authentication method', 'easy-mcp-ai' ),
                    __( 'Check "I trust this application" and click Create', 'easy-mcp-ai' ),
                    __( 'Complete the OAuth login flow — no token needed', 'easy-mcp-ai' ),
                ),
                'oauth_config' => '%s',
                'hint'         => __( 'Alternative — create a connector with a token embedded in the URL:', 'easy-mcp-ai' ),
                'config'       => '%s/YOUR_API_TOKEN',
                'note'         => __( 'Requires Pro, Plus, Business, Enterprise, or Education plan. Replace YOUR_API_TOKEN with your actual token (e.g. wpmcp_abc123…).', 'easy-mcp-ai' ),
                'link'         => 'https://chatgpt.com/',
            ),
            array(
                'id'           => 'claude-desktop',
                'name'         => __( 'Claude Desktop & Cowork', 'easy-mcp-ai' ),
                'oauth_steps'  => array(
                    __( 'Go to Settings > Connectors', 'easy-mcp-ai' ),
                    __( 'Click Add connector', 'easy-mcp-ai' ),
                    __( 'Paste the MCP endpoint URL (below) as the server URL', 'easy-mcp-ai' ),
                    __( 'Set the name to "WordPress" (or anything)', 'easy-mcp-ai' ),
                    __( 'Click Save then Connect — OAuth is handled automatically, no token needed', 'easy-mcp-ai' ),
                ),
                'oauth_config' => '%s',
                'hint'         => __( 'Alternative — add to claude_desktop_config.json with a manual token:', 'easy-mcp-ai' ),
                'config'       => wp_json_encode( array( 'mcpServers' => array( 'wordpress' => array(
                    'command' => 'npx',
                    'args'    => array( 'mcp-remote', '%s', '--header', 'Authorization: Bearer YOUR_API_TOKEN' ),
                ) ) ), $flags ),
            ),
            array(
                'id'     => 'cursor',
                'name'   => __( 'Cursor', 'easy-mcp-ai' ),
                'hint'   => __( 'Add to ~/.cursor/mcp.json:', 'easy-mcp-ai' ),
                'config' => $mcp_servers_json( array( 'url' => '%s', 'headers' => $auth ) ),
                'link'   => 'https://www.cursor.com/',
            ),

            array(
                'id'         => 'gemini-cli',
                'name'       => __( 'Gemini CLI', 'easy-mcp-ai' ),
                'hint'       => __( 'Add to ~/.gemini/settings.json:', 'easy-mcp-ai' ),
                'cli_config' => 'gemini mcp add wordpress %s --transport http --scope user -H "Authorization: Bearer YOUR_API_TOKEN"',
                'config'     => $mcp_servers_json( array( 'url' => '%s', 'type' => 'http', 'headers' => $auth ) ),
                'link'       => 'https://geminicli.com/',
            ),

            array(
                'id'           => 'antigravity',
                'name'         => __( 'Google Antigravity', 'easy-mcp-ai' ),
                'oauth_steps'  => array(
                    __( 'Click "Open MCP Config" in Antigravity to open the configuration file', 'easy-mcp-ai' ),
                    __( 'Add the server config below (paste the MCP endpoint URL as the serverUrl)', 'easy-mcp-ai' ),
                    __( 'Save the file and restart Antigravity', 'easy-mcp-ai' ),
                    __( 'Antigravity will prompt you to authorize — click Authorize and complete the OAuth login flow', 'easy-mcp-ai' ),
                ),
                'oauth_config' => $mcp_servers_json( array( 'serverUrl' => '%s' ) ),
                'link'         => 'https://antigravity.google/',
            ),
            
            array(
                'id'         => 'claude-code',
                'name'       => __( 'Claude Code', 'easy-mcp-ai' ),
                'hint'       => __( 'Add to your Claude Code MCP config file:', 'easy-mcp-ai' ),
                'cli_config' => 'claude mcp add --transport http wordpress %s --header "Authorization: Bearer YOUR_API_TOKEN"',
                'config'     => $http_config,
            ),
            array(
                'id'     => 'windsurf',
                'group'  => 'others',
                'name'   => __( 'Windsurf', 'easy-mcp-ai' ),
                'hint'   => __( 'Add to ~/.codeium/windsurf/mcp_config.json:', 'easy-mcp-ai' ),
                'config' => $mcp_servers_json( array( 'serverUrl' => '%s', 'headers' => $auth ) ),
                'link'   => 'https://windsurf.com/',
            ),
            array(
                'id'     => 'cline',
                'group'  => 'others',
                'name'   => __( 'Cline (VS Code)', 'easy-mcp-ai' ),
                'hint'   => __( 'Add to cline_mcp_settings.json:', 'easy-mcp-ai' ),
                'config' => $cline_style_config,
                'link'   => 'https://cline.bot/',
            ),
            array(
                'id'     => 'roocode',
                'group'  => 'others',
                'name'   => __( 'Roo Code (VS Code)', 'easy-mcp-ai' ),
                'hint'   => __( 'Add to Roo Code MCP settings (roo_cline_mcp_settings.json):', 'easy-mcp-ai' ),
                'config' => $cline_style_config,
                'link'   => 'https://roocode.com/',
            ),

            array(
                'id'     => 'zed',
                'group'  => 'others',
                'name'   => __( 'Zed Editor', 'easy-mcp-ai' ),
                'hint'   => __( 'Add to ~/.config/zed/settings.json:', 'easy-mcp-ai' ),
                'config' => wp_json_encode( array( 'context_servers' => array( 'wordpress' => array( 'settings' => new \stdClass(), 'url' => '%s', 'headers' => $auth ) ) ), $flags ),
                'link'   => 'https://zed.dev/',
            ),
            array(
                'id'     => 'copilot',
                'group'  => 'others',
                'name'   => __( 'GitHub Copilot (VS Code)', 'easy-mcp-ai' ),
                'hint'   => __( 'Add to .vscode/mcp.json in your project:', 'easy-mcp-ai' ),
                'config' => wp_json_encode( array( 'servers' => array( 'wordpress' => array( 'type' => 'http', 'url' => '%s', 'headers' => $auth ) ) ), $flags ),
                'link'   => 'https://github.com/features/copilot',
            ),

            array(
                'id'     => 'librechat',
                'group'  => 'others',
                'name'   => __( 'LibreChat', 'easy-mcp-ai' ),
                'hint'   => __( 'Add to librechat.yaml:', 'easy-mcp-ai' ),
                'config' => "mcpServers:\n  wordpress:\n    type: http\n    url: %s\n\n    headers:\n      Authorization: \"Bearer YOUR_API_TOKEN\"",
                'link'   => 'https://www.librechat.ai/',
            ),

            array(
                'id'     => 'pydantic',
                'group'  => 'others',
                'name'   => __( 'Pydantic AI (Python)', 'easy-mcp-ai' ),
                'hint'   => __( 'Use in your Python application:', 'easy-mcp-ai' ),
                'config' => "from pydantic_ai import Agent\nfrom pydantic_ai.mcp import MCPServerStreamableHTTP\n\nwordpress_mcp = MCPServerStreamableHTTP(\n    url=\"%s\",\n    headers={\"Authorization\": \"Bearer YOUR_API_TOKEN\"},\n)\n\nagent = Agent(\"openai:gpt-4o\", toolsets=[wordpress_mcp])",
            ),
            array(
                'id'     => 'opencode',
                'group'  => 'others',
                'name'   => __( 'OpenCode', 'easy-mcp-ai' ),
                'hint'   => __( 'Add to opencode.json in your project:', 'easy-mcp-ai' ),
                'config' => wp_json_encode( array( 'mcp' => array( 'wordpress' => array( 'type' => 'remote', 'url' => '%s', 'headers' => $auth ) ) ), $flags ),
                'link'   => 'https://opencode.ai/',
            ),
            array(
                'id'         => 'codex',
                'group'      => 'others',
                'name'       => __( 'OpenAI Codex CLI', 'easy-mcp-ai' ),
                'hint'       => __( 'Add to ~/.codex/config.toml:', 'easy-mcp-ai' ),
                'cli_config' => 'codex mcp add wordpress -- npx -y mcp-remote %s --header "Authorization:Bearer YOUR_API_TOKEN" --transport http-only',
                'config'     => "[mcp_servers.wordpress]\ncommand = \"npx\"\nargs = [\"-y\", \"mcp-remote\", \"%s\", \"--header\", \"Authorization:Bearer YOUR_API_TOKEN\", \"--transport\", \"http-only\"]",
                'link'       => 'https://github.com/openai/codex',
            ),
            array(
                'id'     => 'stdio-generic',
                'group'  => 'others',
                'name'   => __( 'Other (stdio via mcp-remote)', 'easy-mcp-ai' ),
                'hint'   => __( 'For any stdio-based MCP client, use the mcp-remote bridge:', 'easy-mcp-ai' ),
                'config' => wp_json_encode( array( 'command' => 'npx', 'args' => array( '-y', 'mcp-remote', '%s', '--header', 'Authorization:Bearer YOUR_API_TOKEN', '--transport', 'http-only' ) ), $flags ),
                'note'   => __( 'Replace the outer object key with the server name your client expects.', 'easy-mcp-ai' ),
            ),
        );
    }

    public function render_tokens_page() {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( 'new' === $action ) {
            $users        = \get_users( array( 'capability' => apply_filters( 'easy_mcp_ai_oauth_min_capability', 'publish_posts' ) ) );
            $tools_by_cat = $this->tool_registry->get_tools_by_category();
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/views/token-create.php';
            return;
        }
        if ( 'edit' === $action && isset( $_GET['token_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $token = $this->token_manager->get_token_by_id( absint( $_GET['token_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! $token ) {
                \wp_safe_redirect( \admin_url( 'admin.php?page=easy-mcp-ai-tokens&error=token_not_found' ) );
                exit;
            }
            $users        = \get_users( array( 'capability' => apply_filters( 'easy_mcp_ai_oauth_min_capability', 'publish_posts' ) ) );
            $tools_by_cat = $this->tool_registry->get_tools_by_category();
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/views/token-create.php';
            return;
        }
        $endpoint_url    = \rest_url( 'easy-mcp-ai/v1/mcp' );
        $client_guides   = self::get_client_guides();
        $tokens_per_page = 200;
        $tokens          = $this->token_manager->get_all_tokens( $tokens_per_page );
        $total_tokens    = $this->token_manager->count_tokens();
        $tokens_truncated = $total_tokens > $tokens_per_page;
        $message         = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $new_token_id    = isset( $_GET['token_id'] ) ? absint( $_GET['token_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $new_raw_token   = false;
        if ( $new_token_id ) {
            $meta_key   = '_easy_mcp_ai_new_token_' . $new_token_id;
            $stored     = \get_user_meta( \get_current_user_id(), $meta_key, true );
            if ( is_array( $stored ) && ! empty( $stored['token'] ) && isset( $stored['expires'] ) && (int) $stored['expires'] >= time() ) {
                $new_raw_token = $stored['token'];
            }
            
            if ( '' !== $stored && false !== $stored ) {
                \delete_user_meta( \get_current_user_id(), $meta_key );
            }
        }
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/views/token-list.php';
    }

    public function render_audit_page() {
        global $wpdb;
        $table     = \esc_sql( $wpdb->prefix . 'easy_mcp_ai_audit_log' );
        $page      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $per_page  = 50;
        $offset    = ( $page - 1 ) * $per_page;
        $retention = (int) \get_option( 'easy_mcp_ai_audit_log_retention', 30 );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is not user input
        $total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $retention ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names are not user input
        $entries   = $wpdb->get_results( $wpdb->prepare( "SELECT l.*, t.name as token_name FROM `{$table}` l LEFT JOIN `{$wpdb->prefix}easy_mcp_ai_tokens` t ON l.token_id = t.id ORDER BY l.created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
        $total_pages = ceil( $total / $per_page );
        $message   = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/views/audit-log.php';
    }

    public function render_external_data_page(): void {
        ( new \Easy_MCP_AI\Admin\External_Data_Admin() )->render_page();
    }

    public function render_settings_page() {
        $settings = array(
            'rate_limit_per_minute'  => (int)   \get_option( 'easy_mcp_ai_rate_limit_per_minute', 60 ),
            'audit_log_retention'    => (int)   \get_option( 'easy_mcp_ai_audit_log_retention', 30 ),
            'ip_whitelist'           =>         \get_option( 'easy_mcp_ai_ip_whitelist', '' ),
            'disabled_tools'         => (array) \get_option( 'easy_mcp_ai_disabled_tools', array() ),
            'force_draft_on_create'  => (bool)  \get_option( 'easy_mcp_ai_force_draft_on_create', false ),
            'max_title_length'       => (int)   \get_option( 'easy_mcp_ai_max_title_length', 0 ),
            'audit_log_enabled'      => (bool)  \get_option( 'easy_mcp_ai_audit_log_enabled', true ),
            'allowed_tool_patterns'  => (array) \get_option( 'easy_mcp_ai_allowed_tool_patterns', array() ),
            'admin_language'         =>         \get_option( 'easy_mcp_ai_admin_language', '' ),
        );
        $all_tool_names = array_values( array_diff(
            $this->tool_registry->get_all_tool_names(),
            $settings['disabled_tools']
        ) );
        $message    = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ip_invalid = isset( $_GET['ip_invalid'] ) ? sanitize_text_field( wp_unslash( $_GET['ip_invalid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/views/settings.php';
    }
}
