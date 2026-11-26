<?php

namespace Easy_MCP_AI\MCP;

use Easy_MCP_AI\Config;
use Easy_MCP_AI\Tools\Tool_State;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tool_Status {

    const TOOLS_PATH = 'Easy MCP AI › Tools';

    const EXTERNAL_PATH = 'Easy MCP AI › Tools › Connections';

    const API_TOKENS_PATH = 'Easy MCP AI › Connections › API tokens';

    const IN_CONFIGURATION = 'switched off in this site\'s configuration (wp-config.php or the server environment), where only the site owner can switch %s on';

    const BUDDYPRESS_COMPONENT_OFF = 'needs the BuddyPress %s component, which is switched off on this site. An administrator can switch it on in wp-admin under Settings › BuddyPress › Components.';

    const CONFIGURATION_PLACE = 'in this site\'s configuration, where only the site owner can switch it on';

    public static function switch_locked( string $tool_name ): bool {
        return null !== Tool_State::override_of( $tool_name ) || Config::is_locked( 'easy_mcp_ai_disabled_tools' );
    }

    public static function switch_path(): string {
        return Config::brand( self::TOOLS_PATH );
    }

    public static function place( string $path, bool $locked = false ): string {
        return $locked ? self::CONFIGURATION_PLACE : self::in_admin( Config::brand( $path ) );
    }

    public static function in_admin( string $branded_path ): string {
        return 'wp-admin: ' . $branded_path;
    }

    public static function off( string $tool_name, bool $default_off ): array {
        if ( self::switch_locked( $tool_name ) ) {
            return array( 'status' => 'off_locked' );
        }
        return array(
            'status' => 'off',
            'where'  => self::switch_path(),
        );
    }

    public static function predicate( array $status ): string {
        switch ( $status['status'] ) {
            case 'paused':
                return 'is unavailable, like every tool, while this site has paused AI access; only the site owner can resume it.';
            case 'policy':
                return 'is unavailable, like every tool, while the tool policy set in this site\'s configuration (EASY_MCP_AI_DISABLED_TOOLS or EASY_MCP_AI_ALLOWED_TOOL_PATTERNS, in wp-config.php or the server environment) cannot be read; only the site owner can fix it.';
            case 'not_allowed':
                return 'is not included in this connection\'s allowed tools (the site owner can widen the API key or the OAuth grant).';
            case 'not_registered':
                return 'is not available on this site.';
            case 'capability':
                return sprintf( 'needs the %s capability, which your WordPress account does not have.', $status['capability'] );
            case 'blocked':
                return sprintf( 'needs the %s capability, which %s.', $status['capability'], $status['cause'] );
            case 'off':
                return sprintf( 'is switched off on this site. An administrator can switch it on in wp-admin under %s.', $status['where'] );
            case 'off_locked':
                return sprintf( 'is ' . self::IN_CONFIGURATION . '.', 'it' );
            case 'integration_off':
                return sprintf( 'is switched off on this site with the rest of the %1$s integration. An administrator can switch the %1$s integration on in wp-admin under %2$s.', $status['integration'], $status['where'] );
            case 'integration_off_locked':
                return sprintf( 'belongs to the %s integration, ' . sprintf( self::IN_CONFIGURATION, 'it' ) . '.', $status['integration'] );
            case 'integration_inactive':
                return sprintf( 'needs %1$s, which is not active on this site. An administrator can activate %1$s in wp-admin.', $status['integration'] );
            case 'needs_block_theme':
                return 'needs an active block theme (Full Site Editing), and this site\'s theme is a classic theme.';
            case 'needs_widget_areas':
                return 'needs a theme with widget areas, and this site\'s theme has none.'
                    . ( function_exists( 'wp_is_block_theme' ) && \wp_is_block_theme() && ! ( class_exists( '\\Easy_MCP_AI\\MCP\\Server' ) && false === \Easy_MCP_AI\MCP\Server::current_caller_may_reach( 'wp_list_templates' ) ) ? ' On a block theme, headers, footers and sidebars are template parts: find them with wp_list_templates (type "template_part").' : '' );
            case 'needs_cloud_salts':
                return 'needs unique SECURE_AUTH_KEY and SECURE_AUTH_SALT values in wp-config.php (neither empty nor the placeholder, 64 characters or more together) and the openssl PHP extension, which this site lacks.';
            case 'needs_direct_filesystem':
                return 'needs WordPress to write the files directly, and this site\'s filesystem method for them is not "direct" (FS_METHOD in wp-config.php, or a filesystem_method filter; stored FTP or SSH credentials are not enough for it).';
            case 'needs_filesystem_access':
                return 'needs WordPress to write the files directly, or FTP or SSH credentials stored in wp-config.php (FTP_HOST, FTP_USER, FTP_PASS), and this site has neither.';
            case 'needs_comment_trash':
                return 'needs the comment Trash, which is off on this site: EMPTY_TRASH_DAYS is 0 in wp-config.php, so WordPress would delete the comment permanently instead.'
                    . ( class_exists( '\\Easy_MCP_AI\\MCP\\Server' ) && false === \Easy_MCP_AI\MCP\Server::current_caller_may_reach( 'wp_delete_comment' ) ? '' : ' To delete it permanently, call wp_delete_comment with force=true.' );
            case 'needs_theme_json':
                return 'needs a theme with a theme.json file on this WordPress version, and this site\'s theme has none.';
            case 'needs_bp_groups':
                return sprintf( self::BUDDYPRESS_COMPONENT_OFF, 'Groups' );
            case 'needs_bp_messages':
                return sprintf( self::BUDDYPRESS_COMPONENT_OFF, 'Private Messaging' );
            case 'needs_bp_activity':
                return sprintf( self::BUDDYPRESS_COMPONENT_OFF, 'Activity Streams' );
            case 'needs_rank_math_loaded':
                return 'needs Rank Math\'s own code, which Rank Math has not loaded on this site: it loads only on the WordPress and PHP versions it supports, and otherwise deactivates itself on the next wp-admin page. An administrator can see its notice in wp-admin.';
            case 'needs_rank_math_setup':
                return 'needs Rank Math\'s setup to be finished: until the account step of its setup wizard is connected or skipped, Rank Math loads none of its modules. An administrator can finish it under Rank Math in wp-admin.';
            case 'needs_rank_math_redirections':
                return 'needs Rank Math\'s Redirections module, which is switched off on this site. An administrator can switch it on in wp-admin under Rank Math › Dashboard › Modules.';
            case 'needs_rank_math_headless':
                return 'needs Rank Math\'s Headless CMS Support, which is switched off on this site. An administrator can switch it on in wp-admin under Rank Math › General Settings › Others.';
            case 'needs_sitekit_analytics':
                return 'needs Site Kit\'s Analytics module, which is not active on this site. An administrator can set up Analytics in wp-admin under Site Kit › Settings › Connected Services.';
            case 'needs_tec_rest':
                return 'needs The Events Calendar\'s REST API, which is switched off on this site: The Events Calendar registers its tribe/events/v1 routes only while its rest-v1-disabled option is unset and the tribe_events_rest_api_enabled filter allows them, and neither has a screen in wp-admin.';
            case 'needs_yoast_head_endpoint':
                return 'needs Yoast SEO\'s REST API head endpoint, which is switched off on this site. An administrator can switch it on in wp-admin under Yoast SEO › Settings › Site features.';
            case 'needs_sitekit_connection':
                return 'needs Site Kit to be connected to Google, and no one on this site has connected a Google account to Site Kit yet. An administrator can set up Site Kit from its menu in wp-admin.'
                    . ( class_exists( '\\Easy_MCP_AI\\MCP\\Server' ) && false === \Easy_MCP_AI\MCP\Server::current_caller_may_reach( 'wp_sitekit_get_status' ) ? '' : ' wp_sitekit_get_status shows each module\'s state.' );
            case 'needs_redirection_groups':
                return 'needs a Redirection group to store the redirect in, and Redirection has none on this site. An administrator can finish Redirection\'s setup under Tools › Redirection in wp-admin, which creates its default groups, or add a group there under Groups.';
            case 'needs_wc_shipping':
                return 'needs WooCommerce shipping, which is switched off on this site. An administrator can switch it on in wp-admin under WooCommerce › Settings › General › Shipping location(s).';
            case 'needs_unfiltered_html':
                return 'needs the unfiltered_html capability, which Smart Slider 3 requires for every action and which DISALLOW_UNFILTERED_HTML in wp-config.php denies to every account on this site, so its sliders cannot be opened in wp-admin either.';
            case 'needs_mailsmtp_store':
                return sprintf( 'needs WP Mail SMTP %s or later: this version lacks the list of settings these tools read. An administrator can update WP Mail SMTP in wp-admin.', \Easy_MCP_AI\Tools\WP_Mail_SMTP\Mail_SMTP_Settings::MINIMUM_VERSION );
            case 'needs_image_editor':
                return 'needs an image editor in PHP (the GD or Imagick extension), and this server has neither, so WordPress cannot change image files here.';
            case 'needs_builder_version':
                return 'needs a page builder version Easy MCP AI can save layouts on, and every page builder active on this site runs a version it has not been tested with. Make the change in the builder itself, or check for an Easy MCP AI update.'
                    . ( class_exists( '\\Easy_MCP_AI\\MCP\\Server' ) && false === \Easy_MCP_AI\MCP\Server::current_caller_may_reach( 'wp_get_page_layout' ) ? '' : ' wp_get_page_layout still reads the layouts.' );
            case 'needs_loco_classes':
                return 'needs classes of Loco Translate that this version of Loco Translate does not have. Update Easy MCP AI, or edit the file in Loco Translate.';
            case 'needs_loco_tested_version':
                return sprintf( 'needs Loco Translate %s or a later patch release of it, the versions writing translations was tested with, and this site runs another. Edit the file in Loco Translate, or update Easy MCP AI.', implode( ', ', \Easy_MCP_AI\Loco\Loco_Catalogue::TESTED_VERSIONS ) );
            case 'needs_aioseo_rest_writes':
                return 'needs AIOSEO 4.9.8 or later, or AIOSEO\'s REST API addon on an older version, and this site has neither.'
                    . ( class_exists( '\\Easy_MCP_AI\\MCP\\Server' ) && false === \Easy_MCP_AI\MCP\Server::current_caller_may_reach( 'wp_aioseo_get_post_seo' ) ? '' : ' wp_aioseo_get_post_seo still reads a post\'s AIOSEO fields.' );
            case 'pattern':
                return Config::is_locked( 'easy_mcp_ai_allowed_tool_patterns' )
                    ? 'is excluded by the allowed tool patterns set in this site\'s configuration (wp-config.php or the server environment), where only the site owner can change them.'
                    : 'is excluded by this site\'s allowed tool patterns, which have no wp-admin screen: the site owner changes them with the easy_mcp_ai_allowed_tool_patterns option or EASY_MCP_AI_ALLOWED_TOOL_PATTERNS.';
            case 'app_only':
                return 'can only be called from an approval card, in a client that supports MCP Apps.';
            case 'needs_tool':
                return sprintf( 'needs the %s tool, which %s', $status['tool'], self::predicate( $status['cause'] ) );
            case 'privileged':
                return 'is refused right now: ' . rtrim( $status['message'], '.' ) . '.';
            default:
                return '';
        }
    }

    public static function who_switches_on( array $off, array $locked, string $path ): string {
        $parts = array();
        if ( array() !== $off ) {
            $parts[] = array() === $locked
                ? sprintf( 'an administrator can switch %s on in wp-admin under %s', count( $off ) > 1 ? 'these' : 'it', $path )
                : sprintf( 'an administrator can switch on %s in wp-admin under %s', self::names( $off ), $path );
        }
        if ( array() !== $locked ) {
            $parts[] = self::names( $locked ) . ( count( $locked ) > 1 ? ' are ' : ' is ' ) . sprintf( self::IN_CONFIGURATION, count( $locked ) > 1 ? 'them' : 'it' );
        }
        return implode( '; ', $parts );
    }

    public static function names( array $names ): string {
        $last = array_pop( $names );
        return array() === $names ? (string) $last : implode( ', ', $names ) . ' and ' . $last;
    }
}
