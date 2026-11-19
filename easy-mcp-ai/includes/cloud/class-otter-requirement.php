<?php

namespace Easy_MCP_AI\Cloud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Otter_Requirement {

    const SLUG = 'otter-blocks';

    const MAIN_FILE = 'otter-blocks.php';

    const MIN_VERSION = '3.2';

    const MODULE_OPTION = 'themeisle_blocks_settings_atomic_wind_blocks';

    const REDIRECT_OPTION = 'themeisle_blocks_settings_redirect';

    const READY         = 'ready';
    const INSTALL       = 'install';
    const ACTIVATE      = 'activate';
    const UPDATE        = 'update';
    const ENABLE_MODULE = 'enable_module';

    public static function state(): array {
        $need = self::need();
        return array(
            'state'  => $need,
            'canFix' => self::can_fix( $need ),
        );
    }

    public static function fix() {
        $need = self::need();
        if ( self::READY === $need ) {
            return self::state();
        }
        if ( self::UPDATE === $need ) {
            return new \WP_Error( 'easy_mcp_ai_otter_update_needed', \__( 'Update Otter Blocks on the Plugins screen, then try again.', 'easy-mcp-ai' ), array( 'status' => 409 ) );
        }
        if ( ! self::can_fix( $need ) ) {
            return new \WP_Error( 'easy_mcp_ai_otter_forbidden', \__( 'Sorry, you are not allowed to install or activate plugins on this site.', 'easy-mcp-ai' ), array( 'status' => 403 ) );
        }
        if ( self::ENABLE_MODULE !== $need ) {
            $result = self::INSTALL === $need ? self::install() : \activate_plugin( (string) self::plugin_file() );
            if ( \is_wp_error( $result ) ) {
                return $result;
            }
            \add_option( self::REDIRECT_OPTION, false );
        }
        \update_option( self::MODULE_OPTION, true );
        return self::state();
    }

    private static function need(): string {
        $otter = self::has_blocks( 'themeisle-blocks' );
        if ( $otter && self::has_blocks( 'atomic-wind' ) ) {
            return self::READY;
        }
        $file    = self::plugin_file();
        $version = null !== $file ? (string) ( \get_plugins()[ $file ]['Version'] ?? '' ) : '';
        if ( '' !== $version && version_compare( $version, self::MIN_VERSION, '<' ) ) {
            return self::UPDATE;
        }
        if ( ! $otter && ( null === $file || ! \is_plugin_active( $file ) ) ) {
            return null === $file ? self::INSTALL : self::ACTIVATE;
        }
        return \get_option( self::MODULE_OPTION, false ) ? self::READY : self::ENABLE_MODULE;
    }

    private static function can_fix( string $need ): bool {
        if ( self::ENABLE_MODULE === $need ) {
            return true;
        }
        if ( self::ACTIVATE === $need ) {
            return \current_user_can( 'activate_plugin', (string) self::plugin_file() );
        }
        if ( self::INSTALL !== $need ) {
            return false;
        }
        if ( \is_multisite() && ( ! \is_super_admin() || ! \current_user_can( 'manage_network_plugins' ) ) ) {
            return false;
        }
        if ( ! \current_user_can( 'install_plugins' ) || ! \current_user_can( 'activate_plugins' ) || ! \wp_is_file_mod_allowed( 'capability_update_core' ) ) {
            return false;
        }
        if ( ! function_exists( 'get_filesystem_method' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return 'direct' === \get_filesystem_method( array(), WP_PLUGIN_DIR );
    }

    private static function install(): ?\WP_Error {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- may be disabled by the host; a failure only leaves the default limit.
        }
        $request = new \WP_REST_Request( 'POST', '/wp/v2/plugins' );
        $request->set_param( 'slug', self::SLUG );
        $request->set_param( 'status', 'active' );
        $response = \rest_do_request( $request );
        return $response->is_error() ? $response->as_error() : null;
    }

    private static function has_blocks( string $namespace ): bool {
        foreach ( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $name ) {
            if ( 0 === strpos( (string) $name, $namespace . '/' ) ) {
                return true;
            }
        }
        return false;
    }

    private static function plugin_file(): ?string {
        if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) || ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $found = null;
        foreach ( array_keys( \get_plugins() ) as $file ) {
            if ( self::MAIN_FILE !== basename( (string) $file ) ) {
                continue;
            }
            if ( \is_plugin_active( $file ) ) {
                return (string) $file;
            }
            $found = $found ?? (string) $file;
        }
        return $found;
    }
}
