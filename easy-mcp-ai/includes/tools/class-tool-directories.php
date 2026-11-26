<?php

namespace Easy_MCP_AI\Tools;

use Easy_MCP_AI\Admin\Plugin_Integration_Registry;
use Easy_MCP_AI\Autoloader;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tool_Directories {

    const ALWAYS_ON = array(
        'posts', 'pages', 'media', 'taxonomy', 'comments',
        'users', 'site', 'menus', 'plugins', 'themes',
        'revisions', 'meta', 'search', 'blocks', 'cpt', 'styles',
        'appearance', 'widgets', 'history', 'audit', 'site-health', 'approvals',
        'feedback',
        'cache',
        'mail',
        'skills',
        'rest',
        'privileged',
        'cli',
        'cloud',
    );

    const PLUGIN_GROUPS = array(
        'woocommerce'         => 'woocommerce',
        'acf'                 => 'acf',
        'the-events-calendar' => 'events-calendar',
        'redirection'         => 'redirection',
        'site-kit'            => 'site-kit',
        'divi'                => 'divi',
        'contact-form-7'      => 'contact-form-7',
        'smart-slider-3'      => 'smart-slider',
        'wpforms'             => 'wpforms',
        'loco-translate'      => 'loco-translate',
        'wp-mail-smtp'        => 'wp-mail-smtp',
        'wordfence'           => 'wordfence',
        'buddypress'          => 'buddypress',
        'yoast-seo'           => 'seo/yoast',
        'rank-math'           => 'seo/rank-math',
        'aioseo'              => 'seo/aioseo',
        'seopress'            => 'seo/seopress',
        'slim-seo'            => 'seo/slim-seo',
        'the-seo-framework'   => 'seo/the-seo-framework',
    );

    const EXTERNAL_DATA = array( 'ahrefs', 'gsc', 'ga', 'dfs', 'semrush', 'seranking' );

    const BUILDERS = 'builders';

    const TRANSLATIONS = 'translations';

    const TEMPLATES = 'templates';

    const TEMPLATE_TOOLS = array(
        'Easy_MCP_AI\\Tools\\Templates\\Get_Template'    => 'wp_get_template',
        'Easy_MCP_AI\\Tools\\Templates\\List_Templates'  => 'wp_list_templates',
        'Easy_MCP_AI\\Tools\\Templates\\Update_Template' => 'wp_update_template',
    );

    const PREREQUISITE_GATED = array(
        'Easy_MCP_AI\\Tools\\Widgets\\List_Widgets'         => array( 'wp_list_widgets', array( 'widget_areas' ) ),
        'Easy_MCP_AI\\Tools\\Widgets\\Get_Widget'           => array( 'wp_get_widget', array( 'widget_areas' ) ),
        'Easy_MCP_AI\\Tools\\Widgets\\List_Widget_Types'    => array( 'wp_list_widget_types', array( 'widget_areas' ) ),
        'Easy_MCP_AI\\Tools\\Widgets\\Create_Widget'        => array( 'wp_create_widget', array( 'widget_areas' ) ),
        'Easy_MCP_AI\\Tools\\Widgets\\Update_Widget'        => array( 'wp_update_widget', array( 'widget_areas' ) ),
        'Easy_MCP_AI\\Tools\\Widgets\\Delete_Widget'        => array( 'wp_delete_widget', array( 'widget_areas' ) ),
        'Easy_MCP_AI\\Tools\\Styles\\Update_Global_Styles'  => array( 'wp_update_global_styles', array( 'theme_json' ) ),
        'Easy_MCP_AI\\Tools\\Cloud\\Blocks_Execute'         => array( 'blocks_execute', array( 'cloud_salts' ) ),
        'Easy_MCP_AI\\Tools\\Cloud\\Blocks_Schema_Get'      => array( 'blocks_schema_get', array( 'cloud_salts' ) ),
        'Easy_MCP_AI\\Tools\\Cloud\\Atomic_Wind_Context'    => array( 'atomic_wind_context', array( 'cloud_salts' ) ),
        'Easy_MCP_AI\\Tools\\Comments\\Trash_Comment'        => array( 'wp_trash_comment', array( 'comment_trash' ) ),
        'Easy_MCP_AI\\Tools\\Plugins\\Install_Plugin'         => array( 'wp_install_plugin', array( 'direct_filesystem_plugins' ) ),
        'Easy_MCP_AI\\Tools\\Plugins\\Delete_Plugin'          => array( 'wp_delete_plugin', array( 'direct_filesystem_plugins' ) ),
        'Easy_MCP_AI\\Tools\\Themes\\Install_Theme'           => array( 'wp_install_theme', array( 'direct_filesystem_themes' ) ),
        'Easy_MCP_AI\\Tools\\Themes\\Delete_Theme'            => array( 'wp_delete_theme', array( 'direct_filesystem_themes' ) ),
        'Easy_MCP_AI\\Tools\\Site_Health\\Update_Core'        => array( 'wp_update_core', array( 'direct_filesystem_core' ) ),
        'Easy_MCP_AI\\Tools\\Plugins\\Update_Plugin'          => array( 'wp_update_plugin', array( 'filesystem_access_plugins' ) ),
        'Easy_MCP_AI\\Tools\\Themes\\Update_Theme'            => array( 'wp_update_theme', array( 'filesystem_access_theme_roots' ) ),
        'Easy_MCP_AI\\Tools\\Media\\Edit_Media_Image'         => array( 'wp_edit_media_image', array( 'image_editor' ) ),
        'Easy_MCP_AI\\Tools\\Media\\Regenerate_Media_Sizes'   => array( 'wp_regenerate_media_sizes', array( 'image_editor' ) ),
        'Easy_MCP_AI\\Tools\\Builders\\Update_Page_Layout'    => array( 'wp_update_page_layout', array( 'builder_version' ) ),
        'Easy_MCP_AI\\Tools\\BuddyPress\\List_Groups'          => array( 'wp_bp_list_groups', array( 'bp_groups' ) ),
        'Easy_MCP_AI\\Tools\\BuddyPress\\Get_Group'            => array( 'wp_bp_get_group', array( 'bp_groups' ) ),
        'Easy_MCP_AI\\Tools\\BuddyPress\\List_Group_Members'   => array( 'wp_bp_list_group_members', array( 'bp_groups' ) ),
        'Easy_MCP_AI\\Tools\\BuddyPress\\List_Message_Threads' => array( 'wp_bp_list_message_threads', array( 'bp_messages' ) ),
        'Easy_MCP_AI\\Tools\\BuddyPress\\Get_Message_Thread'   => array( 'wp_bp_get_message_thread', array( 'bp_messages' ) ),
        'Easy_MCP_AI\\Tools\\BuddyPress\\List_Activity'        => array( 'wp_bp_list_activity', array( 'bp_activity' ) ),
        'Easy_MCP_AI\\Tools\\BuddyPress\\Create_Activity'      => array( 'wp_bp_create_activity', array( 'bp_activity' ) ),
        'Easy_MCP_AI\\Tools\\BuddyPress\\Delete_Activity'      => array( 'wp_bp_delete_activity', array( 'bp_activity' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Get_Head'           => array( 'wp_rm_get_head', array( 'rank_math_loaded', 'rank_math_setup', 'rank_math_headless' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Get_Settings'       => array( 'wp_rm_get_settings', array( 'rank_math_loaded', 'rank_math_setup' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Update_Settings'    => array( 'wp_rm_update_settings', array( 'rank_math_loaded', 'rank_math_setup' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Rankmath_List_Redirects'     => array( 'wp_rm_list_redirects', array( 'rank_math_loaded', 'rank_math_setup', 'rank_math_redirections' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Get_Redirect'       => array( 'wp_rm_get_redirect', array( 'rank_math_loaded', 'rank_math_setup', 'rank_math_redirections' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Create_Redirect'    => array( 'wp_rm_create_redirect', array( 'rank_math_loaded', 'rank_math_setup', 'rank_math_redirections' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Update_Redirect'    => array( 'wp_rm_update_redirect', array( 'rank_math_loaded', 'rank_math_setup', 'rank_math_redirections' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Delete_Redirects'   => array( 'wp_rm_delete_redirects', array( 'rank_math_loaded', 'rank_math_setup', 'rank_math_redirections' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Aioseo_Update_Post_Seo'      => array( 'wp_aioseo_update_post_seo', array( 'aioseo_rest_writes' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\Create_Event' => array( 'wp_tec_create_event', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\Create_Organizer' => array( 'wp_tec_create_organizer', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\Create_Venue' => array( 'wp_tec_create_venue', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\Delete_Event' => array( 'wp_tec_delete_event', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\Get_Event' => array( 'wp_tec_get_event', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\Get_Venue' => array( 'wp_tec_get_venue', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\List_Events' => array( 'wp_tec_list_events', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\List_Organizers' => array( 'wp_tec_list_organizers', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\List_Venues' => array( 'wp_tec_list_venues', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Events_Calendar\\Update_Event' => array( 'wp_tec_update_event', array( 'tec_rest' ) ),
        'Easy_MCP_AI\\Tools\\Site_Kit\\GA_Run_Report'       => array( 'wp_sitekit_ga_run_report', array( 'sitekit_analytics' ) ),
        'Easy_MCP_AI\\Tools\\Site_Kit\\GA_List_Key_Events'  => array( 'wp_sitekit_ga_list_key_events', array( 'sitekit_analytics' ) ),
        'Easy_MCP_AI\\Tools\\SEO\\Yoast_Get_Head'             => array( 'wp_yoast_get_head', array( 'yoast_head_endpoint' ) ),
        'Easy_MCP_AI\\Tools\\Loco_Translate\\List_Files'          => array( 'wp_loco_list_files', array( 'loco_classes' ) ),
        'Easy_MCP_AI\\Tools\\Loco_Translate\\Get_Translations'    => array( 'wp_loco_get_translations', array( 'loco_classes' ) ),
        'Easy_MCP_AI\\Tools\\Loco_Translate\\Update_Translations' => array( 'wp_loco_update_translations', array( 'loco_classes', 'loco_tested_version' ) ),
        'Easy_MCP_AI\\Tools\\Site_Kit\\GSC_Query_Performance' => array( 'wp_sitekit_gsc_query_performance', array( 'sitekit_connection' ) ),
        'Easy_MCP_AI\\Tools\\Redirection\\Create_Redirect'    => array( 'wp_redirection_create_redirect', array( 'redirection_groups' ) ),
        'Easy_MCP_AI\\Tools\\WooCommerce\\List_Shipping_Zones'    => array( 'wp_wc_list_shipping_zones', array( 'wc_shipping' ) ),
        'Easy_MCP_AI\\Tools\\WooCommerce\\Create_Shipping_Zone'   => array( 'wp_wc_create_shipping_zone', array( 'wc_shipping' ) ),
        'Easy_MCP_AI\\Tools\\WooCommerce\\Update_Shipping_Zone'   => array( 'wp_wc_update_shipping_zone', array( 'wc_shipping' ) ),
        'Easy_MCP_AI\\Tools\\WooCommerce\\Delete_Shipping_Zone'   => array( 'wp_wc_delete_shipping_zone', array( 'wc_shipping' ) ),
        'Easy_MCP_AI\\Tools\\WooCommerce\\List_Shipping_Methods'  => array( 'wp_wc_list_shipping_methods', array( 'wc_shipping' ) ),
        'Easy_MCP_AI\\Tools\\WooCommerce\\Add_Shipping_Method'    => array( 'wp_wc_add_shipping_method', array( 'wc_shipping' ) ),
        'Easy_MCP_AI\\Tools\\WooCommerce\\Update_Shipping_Method' => array( 'wp_wc_update_shipping_method', array( 'wc_shipping' ) ),
        'Easy_MCP_AI\\Tools\\WooCommerce\\Delete_Shipping_Method' => array( 'wp_wc_delete_shipping_method', array( 'wc_shipping' ) ),
        'Easy_MCP_AI\\Tools\\Smart_Slider\\List_Sliders'      => array( 'wp_smartslider_list_sliders', array( 'unfiltered_html' ) ),
        'Easy_MCP_AI\\Tools\\Smart_Slider\\Get_Slider'        => array( 'wp_smartslider_get_slider', array( 'unfiltered_html' ) ),
        'Easy_MCP_AI\\Tools\\Smart_Slider\\Get_Slide'         => array( 'wp_smartslider_get_slide', array( 'unfiltered_html' ) ),
        'Easy_MCP_AI\\Tools\\Smart_Slider\\Update_Slide'      => array( 'wp_smartslider_update_slide', array( 'unfiltered_html' ) ),
        'Easy_MCP_AI\\Tools\\Smart_Slider\\Duplicate_Slide'   => array( 'wp_smartslider_duplicate_slide', array( 'unfiltered_html' ) ),
        'Easy_MCP_AI\\Tools\\Smart_Slider\\Delete_Slide'      => array( 'wp_smartslider_delete_slide', array( 'unfiltered_html' ) ),
        'Easy_MCP_AI\\Tools\\Smart_Slider\\Reorder_Slides'    => array( 'wp_smartslider_reorder_slides', array( 'unfiltered_html' ) ),
        'Easy_MCP_AI\\Tools\\WP_Mail_SMTP\\Get_Settings'      => array( 'wp_mailsmtp_get_settings', array( 'mailsmtp_store' ) ),
        'Easy_MCP_AI\\Tools\\WP_Mail_SMTP\\Update_Settings'   => array( 'wp_mailsmtp_update_settings', array( 'mailsmtp_store' ) ),
    );

    const REQUIREMENT_STATUS = array(
        'direct_filesystem_plugins'     => 'direct_filesystem',
        'direct_filesystem_themes'      => 'direct_filesystem',
        'direct_filesystem_core'        => 'direct_filesystem',
        'filesystem_access_plugins'     => 'filesystem_access',
        'filesystem_access_theme_roots' => 'filesystem_access',
    );

    public static function enabled(): array {
        return array_values( array_unique( array_merge( self::ALWAYS_ON, self::templates(), self::external_data(), self::plugin_groups(), self::builders(), self::translations() ) ) );
    }

    public static function all(): array {
        return array_values( array_unique( array_merge( self::ALWAYS_ON, array( self::TEMPLATES ), self::EXTERNAL_DATA, array_values( self::PLUGIN_GROUPS ), array( self::BUILDERS, self::TRANSLATIONS ) ) ) );
    }

    public static function external_data(): array {
        $tool_dirs = array();
        if ( (bool) \get_option( 'easy_mcp_ai_ahrefs_enabled', false ) && \Easy_MCP_AI\Provider_Credentials::is_configured( 'ahrefs' ) ) {
            $tool_dirs[] = 'ahrefs';
        }
        if ( \Easy_MCP_AI\Provider_Credentials::is_configured( 'gsc' ) ) {
            $tool_dirs[] = 'gsc';
        }
        if ( \Easy_MCP_AI\Provider_Credentials::is_configured( 'ga' ) ) {
            $tool_dirs[] = 'ga';
        }
        if ( \Easy_MCP_AI\Provider_Credentials::is_configured( 'dfs' ) ) {
            $tool_dirs[] = 'dfs';
        }
        if ( \Easy_MCP_AI\Provider_Credentials::is_configured( 'semrush' ) ) {
            $tool_dirs[] = 'semrush';
        }
        if ( \Easy_MCP_AI\Provider_Credentials::is_configured( 'seranking' ) ) {
            $tool_dirs[] = 'seranking';
        }
        return $tool_dirs;
    }

    public static function builders(): array {
        return \Easy_MCP_AI\Builders\Builder_Registry::any_present() ? array( self::BUILDERS ) : array();
    }

    public static function templates(): array {
        return function_exists( 'wp_is_block_theme' ) && \wp_is_block_theme() ? array( self::TEMPLATES ) : array();
    }

    public static function missing_requirement( string $tool_name ): ?string {
        if ( in_array( $tool_name, self::TEMPLATE_TOOLS, true ) ) {
            return array() === self::templates() ? 'block_theme' : null;
        }
        foreach ( self::PREREQUISITE_GATED as $class => $gated ) {
            if ( $gated[0] !== $tool_name ) {
                continue;
            }
            $dir = self::of( $class );
            if ( in_array( $dir, self::PLUGIN_GROUPS, true ) && ! in_array( $dir, self::plugin_groups(), true ) ) {
                return null;
            }
            return self::first_lacking( $gated[1] );
        }
        return null;
    }

    public static function hidden_tool( string $tool_name ): ?Base_Tool {
        if ( null !== self::missing_requirement( $tool_name ) ) {
            $class = array_search( $tool_name, self::TEMPLATE_TOOLS, true );
            foreach ( self::PREREQUISITE_GATED as $gated_class => $gated ) {
                if ( $gated[0] === $tool_name ) {
                    $class = $gated_class;
                }
            }
            return false === $class ? null : new $class();
        }
        foreach ( self::inactive_groups() as $slug => $group ) {
            if ( in_array( $tool_name, array_column( $group['tools'], 'name' ), true ) ) {
                return self::catalogue( self::PLUGIN_GROUPS[ $slug ] )->get_tool( $tool_name );
            }
        }
        return null;
    }

    private static function inactive_groups(): array {
        return array_filter(
            self::saved_groups(),
            static function ( array $group ): bool {
                return ! Plugin_Integration_Registry::is_installed( $group );
            }
        );
    }

    private static function catalogue( string $dir ): Tool_Registry {
        $registry = new Tool_Registry();
        $registry->auto_discover( true, array( $dir ) );
        return $registry;
    }

    public static function hidden_tools(): array {
        $hidden = array();
        foreach ( array_merge( array_values( self::TEMPLATE_TOOLS ), array_column( self::PREREQUISITE_GATED, 0 ) ) as $name ) {
            $tool = self::hidden_tool( $name );
            if ( null !== $tool ) {
                $hidden[ $name ] = $tool;
            }
        }
        foreach ( self::inactive_groups() as $slug => $group ) {
            $registry = self::catalogue( self::PLUGIN_GROUPS[ $slug ] );
            foreach ( array_column( $group['tools'], 'name' ) as $name ) {
                $tool = $registry->get_tool( $name );
                if ( $tool instanceof Base_Tool ) {
                    $hidden[ $name ] = $tool;
                }
            }
        }
        return $hidden;
    }

    public static function is_prerequisite_gated( string $class ): bool {
        return isset( self::PREREQUISITE_GATED[ $class ] ) && null !== self::first_lacking( self::PREREQUISITE_GATED[ $class ][1] );
    }

    private static function first_lacking( array $requirements ): ?string {
        foreach ( $requirements as $requirement ) {
            try {
                if ( self::lacks( $requirement ) ) {
                    return $requirement;
                }
            } catch ( \Throwable $e ) {
                continue;
            }
        }
        return null;
    }

    private static function lacks( string $requirement ): bool {
        switch ( $requirement ) {
            case 'comment_trash':
                return Trash_State::comment_trash_off();
            case 'direct_filesystem_plugins':
                return Filesystem_State::configured_not_direct( (string) WP_PLUGIN_DIR );
            case 'direct_filesystem_themes':
                return Filesystem_State::configured_not_direct( (string) \get_theme_root() );
            case 'direct_filesystem_core':
                return Filesystem_State::configured_not_direct( (string) ABSPATH );
            case 'filesystem_access_plugins':
                return Filesystem_State::configured_without_access( (string) WP_PLUGIN_DIR );
            case 'filesystem_access_theme_roots':
                return Filesystem_State::every_theme_root_without_access();
            case 'image_editor':
                return false === Image_Editor_State::available();
            case 'builder_version':
                return \Easy_MCP_AI\Builders\Builder_Registry::any_present() && ! \Easy_MCP_AI\Builders\Builder_Registry::any_writes_here();
            case 'bp_groups':
                return Integration_State::buddypress_component_off( 'groups' );
            case 'bp_messages':
                return Integration_State::buddypress_component_off( 'messages' );
            case 'bp_activity':
                return Integration_State::buddypress_component_off( 'activity' );
            case 'rank_math_loaded':
                return Integration_State::rank_math_unloaded();
            case 'rank_math_setup':
                return Integration_State::rank_math_setup_unfinished();
            case 'rank_math_redirections':
                return Integration_State::rank_math_redirections_off();
            case 'rank_math_headless':
                return Integration_State::rank_math_headless_off();
            case 'aioseo_rest_writes':
                return Integration_State::aioseo_rest_writes_unavailable();
            case 'sitekit_analytics':
                return Integration_State::sitekit_module_inactive( 'analytics-4' );
            case 'tec_rest':
                return Integration_State::tec_rest_api_off();
            case 'yoast_head_endpoint':
                return Integration_State::yoast_head_endpoint_off();
            case 'loco_classes':
                return function_exists( 'loco_plugin_version' ) && null !== \Easy_MCP_AI\Loco\Loco_Catalogue::missing_class();
            case 'loco_tested_version':
                return function_exists( 'loco_plugin_version' ) && ! \Easy_MCP_AI\Loco\Loco_Catalogue::writes_here();
            case 'sitekit_connection':
                return Integration_State::sitekit_never_connected();
            case 'redirection_groups':
                return Integration_State::redirection_has_no_groups();
            case 'wc_shipping':
                return Integration_State::woocommerce_shipping_off();
            case 'unfiltered_html':
                return Integration_State::unfiltered_html_disallowed();
            case 'mailsmtp_store':
                return function_exists( 'wp_mail_smtp' ) && null !== \Easy_MCP_AI\Tools\WP_Mail_SMTP\Mail_SMTP_Settings::unavailable();
        }
        if ( 'cloud_salts' === $requirement ) {
            return class_exists( '\\Easy_MCP_AI\\Cloud\\Cloud_Credentials' ) && ! \Easy_MCP_AI\Cloud\Cloud_Credentials::can_encrypt();
        }
        if ( 'widget_areas' === $requirement ) {
            return function_exists( 'current_theme_supports' ) && ! \current_theme_supports( 'widgets' );
        }
        $version = function_exists( 'get_bloginfo' ) ? (string) \get_bloginfo( 'version' ) : '';
        if ( '' === $version || version_compare( $version, '6.1-alpha', '<' ) || version_compare( $version, '7.0-alpha', '>=' ) ) {
            return false;
        }
        if ( function_exists( 'wp_theme_has_theme_json' ) ) {
            return ! \wp_theme_has_theme_json();
        }
        if ( class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'theme_has_support' ) ) {
            return ! \WP_Theme_JSON_Resolver::theme_has_support();
        }
        return false;
    }

    public static function translations(): array {
        return null === \Easy_MCP_AI\Multilingual\Translation_Registry::active() ? array() : array( self::TRANSLATIONS );
    }

    public static function plugin_groups(): array {
        $dirs_on = array();
        foreach ( self::saved_groups() as $group_slug => $group ) {
            if ( Plugin_Integration_Registry::is_installed( $group ) ) {
                $dirs_on[ self::PLUGIN_GROUPS[ $group_slug ] ] = true;
            }
        }
        return array_keys( $dirs_on );
    }

    public static function inactive_integration_of( string $tool_name ): ?string {
        foreach ( self::saved_groups() as $group ) {
            if ( in_array( $tool_name, array_column( $group['tools'], 'name' ), true ) && ! Plugin_Integration_Registry::is_installed( $group ) ) {
                return (string) $group['name'];
            }
        }
        return null;
    }

    private static function saved_groups(): array {
        $manifest = array_column( Plugin_Integration_Registry::get_groups(), null, 'slug' );
        $saved    = array();
        foreach ( (array) \get_option( 'easy_mcp_ai_enabled_plugin_groups', array() ) as $group_slug ) {
            if ( is_string( $group_slug ) && isset( self::PLUGIN_GROUPS[ $group_slug ], $manifest[ $group_slug ] ) ) {
                $saved[ $group_slug ] = $manifest[ $group_slug ];
            }
        }
        return $saved;
    }

    public static function of( string $class ): ?string {
        $file = Autoloader::file_for( $class );
        return null === $file ? null : self::relative_dir( $file, __DIR__ );
    }

    public static function relative_dir( string $file, string $root ): ?string {
        $file = \wp_normalize_path( $file );
        $root = rtrim( \wp_normalize_path( $root ), '/' ) . '/';
        if ( 0 !== strpos( $file, $root ) ) {
            return null;
        }
        return dirname( substr( $file, strlen( $root ) ) );
    }
}
