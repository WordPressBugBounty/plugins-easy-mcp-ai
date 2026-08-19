<?php
namespace Easy_MCP_AI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}











class History_Settings_Page {

    const PAGE_SLUG = 'easy-mcp-ai-history-settings';

    const VALID_MODES = array( 'allowlist', 'all_except_denylist' );

    public function register() {
        \add_action( 'admin_menu', array( $this, 'register_menu' ), 13 );
        \add_action( 'admin_init', array( $this, 'maybe_handle_actions' ) );
        \add_filter( 'admin_title', array( $this, 'filter_admin_title' ), 10, 2 );
    }

    

















    public function filter_admin_title( $admin_title, $title = '' ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.
        $page = isset( $_GET['page'] ) ? \sanitize_key( \wp_unslash( $_GET['page'] ) ) : '';
        if ( self::PAGE_SLUG !== $page ) {
            return $admin_title;
        }
        
        
        if ( '' !== trim( (string) $title ) ) {
            return $admin_title;
        }
        return \__( 'Change History Capture Settings', 'easy-mcp-ai' ) . ' ' . ltrim( (string) $admin_title );
    }

    public function register_menu() {
        
        
        $hook = \add_submenu_page(
            '',
            \__( 'Change History Capture Settings', 'easy-mcp-ai' ),
            \__( 'Change History Capture Settings', 'easy-mcp-ai' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render' )
        );

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        if ( $hook ) {
            \add_action(
                'load-' . $hook,
                static function () {
                    global $title;
                    
                    
                    if ( null === $title || '' === $title ) {
                        $title = \__( 'Change History Capture Settings', 'easy-mcp-ai' );
                    }
                }
            );
        }
    }

    





    public static function sanitize_mode( $raw ) {
        return ( is_string( $raw ) && in_array( $raw, self::VALID_MODES, true ) ) ? $raw : 'all_except_denylist';
    }

    public function maybe_handle_actions() {
        if ( isset( $_POST['easy_mcp_ai_save_history_settings'] ) && \check_admin_referer( 'easy_mcp_ai_save_history_settings' ) ) {
            $this->handle_save( \wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is whitelisted/cast in handle_save().
        }
    }

    



    public function handle_save( array $post ) {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return false;
        }
        \update_option( 'easy_mcp_ai_change_log_option_mode', self::sanitize_mode( $post['easy_mcp_ai_change_log_option_mode'] ?? null ) );
        
        \update_option( 'easy_mcp_ai_change_log_capture_meta', ! empty( $post['easy_mcp_ai_change_log_capture_meta'] ) );
        
        \update_option( 'easy_mcp_ai_change_log_capture_db', ! empty( $post['easy_mcp_ai_change_log_capture_db'] ) );
        \update_option( 'easy_mcp_ai_change_log_db_retention', self::sanitize_db_retention( $post['easy_mcp_ai_change_log_db_retention'] ?? null ) );
        
        \update_option( 'easy_mcp_ai_change_log_external_intent', ! empty( $post['easy_mcp_ai_change_log_external_intent'] ) );
        return true;
    }

    



    public static function sanitize_db_retention( $raw ) {
        if ( ! is_numeric( $raw ) ) {
            return 7;
        }
        $days = (int) $raw;
        if ( $days < 0 ) {
            return 7;
        }
        return min( $days, 3650 );
    }

    



    public static function settings_link_html() {
        return '<a href="' . \esc_url( \admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">'
            . \esc_html__( 'Advanced capture settings →', 'easy-mcp-ai' )
            . '</a>';
    }

    



    public static function display_capture_mode( $raw ) {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        return '' === $raw ? 'hooked' : $raw;
    }

    public function render() {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( \esc_html__( 'You do not have permission to access this page.', 'easy-mcp-ai' ) );
        }
        $option_mode  = self::sanitize_mode( \get_option( 'easy_mcp_ai_change_log_option_mode', 'all_except_denylist' ) );
        $capture_meta = (bool) \get_option( 'easy_mcp_ai_change_log_capture_meta', true );
        $capture_db      = (bool) \get_option( 'easy_mcp_ai_change_log_capture_db', false );
        $db_retention    = self::sanitize_db_retention( \get_option( 'easy_mcp_ai_change_log_db_retention', 7 ) );
        $external_intent = (bool) \get_option( 'easy_mcp_ai_change_log_external_intent', true );
        include __DIR__ . '/views/history-settings.php';
    }
}
