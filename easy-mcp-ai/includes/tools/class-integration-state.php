<?php

namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Integration_State {

    public static function buddypress_component_off( string $component ): bool {
        return function_exists( 'bp_is_active' ) && ! \bp_is_active( $component );
    }

    public static function rank_math_unloaded(): bool {
        return ! function_exists( 'rank_math' ) || ! class_exists( '\\RankMath\\Helper' );
    }

    public static function rank_math_setup_unfinished(): bool {
        return is_callable( array( '\\RankMath\\Helper', 'is_invalid_registration' ) ) && \RankMath\Helper::is_invalid_registration();
    }

    public static function rank_math_redirections_off(): bool {
        return ! is_callable( array( '\\RankMath\\Helper', 'is_module_active' ) )
            || ! \RankMath\Helper::is_module_active( 'redirections' )
            || ! class_exists( '\\RankMath\\Redirections\\Redirection' )
            || ! class_exists( '\\RankMath\\Redirections\\DB' );
    }

    public static function rank_math_headless_off(): bool {
        return class_exists( '\\RankMath\\Helper' )
            && method_exists( '\\RankMath\\Helper', 'get_settings' )
            && empty( \RankMath\Helper::get_settings( 'general.headless_support' ) );
    }

    public static function aioseo_rest_writes_unavailable(): bool {
        if ( ! function_exists( 'aioseo' ) ) {
            return false;
        }
        $aioseo = \aioseo();
        if ( defined( 'AIOSEO_VERSION' ) && version_compare( (string) AIOSEO_VERSION, '4.9.8', '>=' ) ) {
            return false;
        }
        if ( is_object( $aioseo ) && isset( $aioseo->version ) && version_compare( (string) $aioseo->version, '4.9.8', '>=' ) ) {
            return false;
        }
        return ! ( is_object( $aioseo )
            && isset( $aioseo->addons )
            && method_exists( $aioseo->addons, 'isAddonActive' )
            && $aioseo->addons->isAddonActive( 'aioseo-rest-api' ) );
    }

    public static function sitekit_module_inactive( string $slug ): bool {
        if ( ! class_exists( '\\Google\\Site_Kit\\Plugin' ) || ! class_exists( '\\Google\\Site_Kit\\Core\\Modules\\Modules' ) ) {
            return false;
        }
        try {
            $plugin = \Google\Site_Kit\Plugin::instance();
            if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'context' ) ) {
                return false;
            }
            $modules = new \Google\Site_Kit\Core\Modules\Modules( $plugin->context() );
            return ! $modules->is_module_active( $slug );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public static function tec_rest_api_off(): bool {
        if ( ! function_exists( 'tribe' ) ) {
            return false;
        }
        try {
            $system = \tribe( 'tec.rest-v1.system' );
            if ( ! is_object( $system ) || ! method_exists( $system, 'supports_tec_rest_api' ) || ! method_exists( $system, 'tec_rest_api_is_enabled' ) ) {
                return false;
            }
            return ! $system->supports_tec_rest_api() || ! $system->tec_rest_api_is_enabled();
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public static function yoast_head_endpoint_off(): bool {
        return class_exists( '\\WPSEO_Options' ) && method_exists( '\\WPSEO_Options', 'get' ) && ! \WPSEO_Options::get( 'enable_headless_rest_endpoints' );
    }

    public static function sitekit_never_connected(): bool {
        foreach ( array( '\\Google\\Site_Kit\\Plugin', '\\Google\\Site_Kit\\Core\\Storage\\User_Options', '\\Google\\Site_Kit\\Core\\Authentication\\Clients\\OAuth_Client_Base' ) as $class ) {
            if ( ! class_exists( $class ) ) {
                return false;
            }
        }
        try {
            $plugin = \Google\Site_Kit\Plugin::instance();
            if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'context' ) ) {
                return false;
            }
            $option   = \Google\Site_Kit\Core\Authentication\Clients\OAuth_Client_Base::OPTION_ACCESS_TOKEN;
            $meta_key = ( new \Google\Site_Kit\Core\Storage\User_Options( $plugin->context() ) )->get_meta_key( $option );
            $keys     = array_values( array_unique( array( $meta_key, $option ) ) );
            return array() === \get_users(
                array(
                    'blog_id'    => 0,
                    'meta_query' => array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one row at most, as Site Kit's own query.
                        array( 'relation' => 'OR' ),
                        array_map(
                            static function ( string $key ): array {
                                return array(
                                    'key'     => $key,
                                    'compare' => 'EXISTS',
                                );
                            },
                            $keys
                        )
                    ),
                    'number'     => 1,
                    'fields'     => 'ID',
                )
            );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public static function redirection_has_no_groups(): bool {
        global $wpdb;
        if ( ! class_exists( '\\Red_Group' ) || ! is_object( $wpdb ) ) {
            return false;
        }
        try {
            $suppressed = $wpdb->suppress_errors( true );
            $count      = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_groups" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count of Redirection's own table, read as its group route reads it.
            $wpdb->suppress_errors( $suppressed );
        } catch ( \Throwable $e ) {
            return false;
        }
        return 0 === (int) $count;
    }

    public static function woocommerce_shipping_off(): bool {
        if ( ! function_exists( 'wc_shipping_enabled' ) ) {
            return false;
        }
        try {
            return ! \wc_shipping_enabled();
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public static function unfiltered_html_disallowed(): bool {
        return defined( 'DISALLOW_UNFILTERED_HTML' ) && (bool) constant( 'DISALLOW_UNFILTERED_HTML' );
    }
}
