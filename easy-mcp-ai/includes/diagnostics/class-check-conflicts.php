<?php

























namespace Easy_MCP_AI\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Check_Conflicts {

    







    const KNOWN_MCP_PLUGINS = array(
        'wordpress-mcp' => 'WordPress MCP',
        'mcp-adapter'   => 'MCP Adapter',
        'ai-services'   => 'AI Services',
        'wp-mcp-server' => 'WP MCP Server',
    );

    
    const KNOWN_SECURITY_PLUGINS = array(
        'wordfence'                          => 'Wordfence Security',
        'better-wp-security'                 => 'Solid Security',
        'all-in-one-wp-security-and-firewall' => 'All-In-One Security',
        'ninjafirewall'                      => 'NinjaFirewall',
        'sg-security'                        => 'SiteGround Security',
        'wp-defender'                        => 'Defender',
        'sucuri-scanner'                     => 'Sucuri Security',
    );

    
    const KNOWN_CACHE_PLUGINS = array(
        'litespeed-cache'  => 'LiteSpeed Cache',
        'wp-rocket'        => 'WP Rocket',
        'w3-total-cache'   => 'W3 Total Cache',
        'wp-super-cache'   => 'WP Super Cache',
        'wp-fastest-cache' => 'WP Fastest Cache',
    );

    


    public static function run() {
        
        
        $foreign_rest_auth = self::foreign_rest_auth_callbacks( self::rest_auth_callback_names() );

        return array(
            self::evaluate_competing_mcp( self::active_from( 'easy_mcp_ai_diagnostics_known_mcp_plugins', self::KNOWN_MCP_PLUGINS ) ),
            self::evaluate_security_plugins( self::active_from( 'easy_mcp_ai_diagnostics_known_security_plugins', self::KNOWN_SECURITY_PLUGINS ) ),
            self::evaluate_rest_filters( array() !== $foreign_rest_auth, $foreign_rest_auth ),
            self::evaluate_hook_stripping( self::count_hook_reassertions(), self::change_capture_enabled() ),
            self::evaluate_cache_plugins( self::active_from( 'easy_mcp_ai_diagnostics_known_cache_plugins', self::KNOWN_CACHE_PLUGINS ) ),
        );
    }

    



    public static function evaluate_competing_mcp( $found ) {
        
        
        if ( null === $found ) {
            return Diagnostic_Result::unknown( 'e1', Diagnostic_Result::TIER_WARNING, __( 'No competing MCP plugin', 'easy-mcp-ai' ), __( 'Could not read the list of active plugins.', 'easy-mcp-ai' ) );
        }
        $found = (array) $found;
        $label = __( 'No competing MCP plugin', 'easy-mcp-ai' );

        if ( ! empty( $found ) ) {
            return Diagnostic_Result::warn(
                'e1',
                Diagnostic_Result::TIER_WARNING,
                $label,
                sprintf(
                    /* translators: %s: comma-separated plugin names. */
                    __( 'Also active: %s. Two plugins answering the same OAuth discovery addresses means an AI client may connect to whichever responds first, so a connection can succeed and still reach the wrong plugin.', 'easy-mcp-ai' ),
                    implode( ', ', $found )
                ),
                __( 'Keep one MCP plugin active and deactivate the other, then reconnect your AI client.', 'easy-mcp-ai' ),
                array( 'competing_mcp_plugins' => $found )
            );
        }

        return Diagnostic_Result::pass(
            'e1',
            Diagnostic_Result::TIER_WARNING,
            $label,
            __( 'None found among the active plugins.', 'easy-mcp-ai' ),
            array( 'competing_mcp_plugins' => array() )
        );
    }

    




    public static function evaluate_security_plugins( $found ) {
        
        
        if ( null === $found ) {
            return Diagnostic_Result::unknown( 'e2', Diagnostic_Result::TIER_WARNING, __( 'No firewall is filtering API traffic', 'easy-mcp-ai' ), __( 'Could not read the list of active plugins.', 'easy-mcp-ai' ) );
        }
        $found = (array) $found;
        
        
        
        $label = __( 'No firewall is filtering API traffic', 'easy-mcp-ai' );

        if ( ! empty( $found ) ) {
            return Diagnostic_Result::warn(
                'e2',
                Diagnostic_Result::TIER_WARNING,
                $label,
                sprintf(
                    /* translators: %s: comma-separated plugin names. */
                    __( 'Detected: %s. Nothing here shows a problem — these plugins are working normally on most sites. But if an AI client cannot connect, a firewall rule refusing requests before WordPress sees them is a common cause worth ruling out.', 'easy-mcp-ai' ),
                    implode( ', ', $found )
                ),
                __( 'If connections are failing, allow requests to /wp-json/easy-mcp-ai/ and /.well-known/oauth-* in that plugin\'s firewall settings, and confirm it is not filtering by user agent.', 'easy-mcp-ai' ),
                array( 'security_plugins' => $found )
            );
        }

        
        
        
        
        
        return Diagnostic_Result::pass(
            'e2',
            Diagnostic_Result::TIER_WARNING,
            $label,
            __( 'No firewall plugin found among the active plugins. This cannot see protection that runs outside WordPress — a host-level firewall, a must-use plugin, or a CDN rule.', 'easy-mcp-ai' ),
            array( 'security_plugins' => array() )
        );
    }

    




    public static function evaluate_rest_filters( $hooked, array $foreign = array() ) {
        $label = __( 'REST API authentication unmodified', 'easy-mcp-ai' );

        if ( $hooked ) {
            















            $shown   = array_slice( $foreign, 0, 3 );
            $named   = '';
            if ( $shown ) {
                $named = ' ' . sprintf(
                    /* translators: %s: comma-separated list of PHP callback names. */
                    __( 'Found: %s.', 'easy-mcp-ai' ),
                    implode( ', ', $shown )
                );
                $remaining = count( $foreign ) - count( $shown );
                if ( $remaining > 0 ) {
                    $named .= ' ' . sprintf(
                        /* translators: %d: number of additional callbacks not listed. */
                        _n( 'And %d more.', 'And %d more.', $remaining, 'easy-mcp-ai' ),
                        $remaining
                    );
                }
            }

            return Diagnostic_Result::warn(
                'e3',
                Diagnostic_Result::TIER_WARNING,
                $label,
                __( 'Another plugin or theme is modifying REST API authentication on this site. That is often legitimate, but plugins that restrict or disable the REST API also work this way, and this plugin reaches WordPress through it.', 'easy-mcp-ai' ) . $named,
                __( 'If AI clients receive authentication errors, look for a "disable REST API" or "restrict REST API" setting and exempt the easy-mcp-ai/v1 namespace.', 'easy-mcp-ai' ),
                
                
                
                
                
                array(
                    'rest_authentication_hooked' => true,
                    'callbacks'                  => array_values( $foreign ),
                )
            );
        }

        
        
        
        
        return Diagnostic_Result::pass(
            'e3',
            Diagnostic_Result::TIER_WARNING,
            $label,
            __( 'Nothing was modifying REST authentication at the time this check ran. A plugin that only hooks in during a REST request would not be visible from the admin screen.', 'easy-mcp-ai' ),
            array( 'rest_authentication_hooked' => false )
        );
    }

    





    public static function evaluate_hook_stripping( $marker_count, $capture_enabled ) {
        $label = __( 'Change tracking hooks stay registered', 'easy-mcp-ai' );

        
        if ( null === $marker_count ) {
            return Diagnostic_Result::unknown(
                'e4',
                Diagnostic_Result::TIER_WARNING,
                $label,
                __( 'Could not read the change history table.', 'easy-mcp-ai' )
            );
        }

        
        if ( ! $capture_enabled ) {
            return Diagnostic_Result::unknown(
                'e4',
                Diagnostic_Result::TIER_WARNING,
                $label,
                __( 'Could not verify — change history recording is switched off, so no evidence is collected either way.', 'easy-mcp-ai' )
            );
        }

        if ( (int) $marker_count > 0 ) {
            return Diagnostic_Result::warn(
                'e4',
                Diagnostic_Result::TIER_WARNING,
                $label,
                sprintf(
                    /* translators: 1: number of recorded reassertion events, 2: number of days in the reporting window. */
                    __( 'On %1$d occasion(s) in the last %2$d days, another plugin removed this plugin\'s tracking hooks during an AI request. They were re-registered automatically and the change was still recorded, so nothing was lost.', 'easy-mcp-ai' ),
                    (int) $marker_count,
                    self::REASSERTION_WINDOW_DAYS
                ),
                __( 'No action needed. If you are investigating missing history entries, the Change History page lists the affected requests.', 'easy-mcp-ai' ),
                array( 'hook_reassertions' => (int) $marker_count )
            );
        }

        return Diagnostic_Result::pass( 'e4', Diagnostic_Result::TIER_WARNING, $label, '', array( 'hook_reassertions' => 0 ) );
    }

    
    public static function evaluate_cache_plugins( $found ) {
        
        
        if ( null === $found ) {
            return Diagnostic_Result::unknown( 'e5', Diagnostic_Result::TIER_WARNING, __( 'Caching excludes API endpoints', 'easy-mcp-ai' ), __( 'Could not read the list of active plugins.', 'easy-mcp-ai' ) );
        }
        $found = (array) $found;
        $label = __( 'Caching excludes API endpoints', 'easy-mcp-ai' );

        if ( ! empty( $found ) ) {
            return Diagnostic_Result::warn(
                'e5',
                Diagnostic_Result::TIER_WARNING,
                $label,
                sprintf(
                    /* translators: %s: comma-separated plugin names. */
                    __( 'Detected: %s. Page caches normally leave API requests alone, but a cached authentication or discovery response can make a connection succeed once and then fail, or keep working after a token is revoked.', 'easy-mcp-ai' ),
                    implode( ', ', $found )
                ),
                __( 'Exclude /wp-json/easy-mcp-ai/ and /.well-known/oauth-* from page caching in that plugin\'s settings.', 'easy-mcp-ai' ),
                array( 'cache_plugins' => $found )
            );
        }

        return Diagnostic_Result::pass(
            'e5',
            Diagnostic_Result::TIER_WARNING,
            $label,
            __( 'No caching plugin found among the active plugins. Caching done by the host or a CDN is not visible from here.', 'easy-mcp-ai' ),
            array( 'cache_plugins' => array() )
        );
    }

    

    






    private static function active_from( $filter, array $known ) {
        
        
        
        
        
        
        
        
        
        
        
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- active_from() is private with three call sites, each passing a literal 'easy_mcp_ai_diagnostics_known_*' name; the sniff only sees the variable.
        $filtered = \apply_filters( $filter, $known );
        if ( is_array( $filtered ) ) {
            $known = $filtered;
        }

        $active = self::active_plugin_paths();
        if ( null === $active ) {
            return null;
        }

        return self::match_known( $active, $known );
    }

    










    private static function active_plugin_paths() {
        $active = \get_option( 'active_plugins', null );
        if ( ! is_array( $active ) ) {
            return null;
        }

        
        
        
        if ( function_exists( 'is_multisite' ) && \is_multisite() && function_exists( 'get_site_option' ) ) {
            $network = \get_site_option( 'active_sitewide_plugins', array() );
            if ( is_array( $network ) ) {
                $active = array_merge( $active, array_keys( $network ) );
            }
        }

        return $active;
    }

    









    public static function match_known( array $active, array $known ) {
        $slugs = array();
        foreach ( $active as $path ) {
            $path = (string) $path;
            $dir  = ( false !== strpos( $path, '/' ) ) ? substr( $path, 0, strpos( $path, '/' ) ) : $path;
            $slugs[ strtolower( $dir ) ] = true;
        }

        $found = array();
        foreach ( $known as $slug => $name ) {
            if ( isset( $slugs[ strtolower( (string) $slug ) ] ) ) {
                $found[] = (string) $name;
            }
        }

        return $found;
    }

    














    



    const CORE_REST_AUTH_CALLBACKS = array(
        'rest_cookie_check_errors',
        'rest_application_password_check_errors',
        'wp_is_application_passwords_available',
    );

    













    






















    const BENIGN_REST_AUTH_CLASSES = array(
        
        'WC_REST_Authentication',
        
        'Automattic\\WooCommerce\\StoreApi\\Authentication',
        
        'Automattic\\Jetpack\\Connection\\Rest_Authentication',
        
        'Ai1wm_Rest_Controller',
    );

    




    private static function benign_rest_auth_classes() {
        $list = self::BENIGN_REST_AUTH_CLASSES;
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = \apply_filters( 'easy_mcp_ai_diagnostics_benign_rest_auth_classes', $list );
            if ( is_array( $filtered ) ) {
                $list = $filtered;
            }
        }
        return array_map( 'strval', $list );
    }

    
    private static function is_benign_rest_auth_callback( $name ) {
        $sep = strpos( $name, '::' );
        if ( false === $sep ) {
            return false;
        }
        $class = ltrim( substr( $name, 0, $sep ), '\\' );
        foreach ( self::benign_rest_auth_classes() as $benign ) {
            if ( 0 === strcasecmp( $class, ltrim( $benign, '\\' ) ) ) {
                return true;
            }
        }
        return false;
    }

    










    public static function foreign_rest_auth_callbacks( array $callback_names ) {
        $foreign = array();
        foreach ( $callback_names as $name ) {
            $name = (string) $name;
            if ( '' === $name ) {
                continue;
            }
            if ( in_array( $name, self::CORE_REST_AUTH_CALLBACKS, true ) ) {
                continue;
            }
            if ( self::is_benign_rest_auth_callback( $name ) ) {
                continue;
            }
            if ( self::is_core_defined_function( $name ) ) {
                continue;
            }
            $foreign[] = $name;
        }
        return array_values( array_unique( $foreign ) );
    }

    


























    private static function is_core_defined_function( $name ) {
        if ( false !== strpos( $name, '::' ) || ! function_exists( $name ) ) {
            return false;
        }
        if ( ! defined( 'ABSPATH' ) || ! class_exists( '\ReflectionFunction' ) ) {
            return false;
        }
        try {
            $file = ( new \ReflectionFunction( $name ) )->getFileName();
        } catch ( \Throwable $e ) {
            return false;
        }
        if ( ! is_string( $file ) || '' === $file ) {
            return false; 
        }

        $core = \wp_normalize_path( ABSPATH . 'wp-includes/' );
        return 0 === strpos( \wp_normalize_path( $file ), $core );
    }

    


    public static function has_third_party_rest_auth_hook( array $callback_names ) {
        
        
        return array() !== self::foreign_rest_auth_callbacks( $callback_names );
    }

    





    private static function rest_auth_callback_names() {
        global $wp_filter;

        if ( ! isset( $wp_filter['rest_authentication_errors'] ) ) {
            return array();
        }

        $hook = $wp_filter['rest_authentication_errors'];
        if ( ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
            return array();
        }

        $names = array();
        foreach ( $hook->callbacks as $priority_group ) {
            if ( ! is_array( $priority_group ) ) {
                continue;
            }
            foreach ( $priority_group as $entry ) {
                $callback = isset( $entry['function'] ) ? $entry['function'] : null;

                if ( is_string( $callback ) ) {
                    $names[] = $callback;
                } elseif ( is_array( $callback ) && isset( $callback[1] ) ) {
                    $owner   = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
                    $names[] = $owner . '::' . $callback[1];
                } elseif ( $callback instanceof \Closure ) {
                    
                    
                    $names[] = 'closure';
                }
            }
        }

        return $names;
    }

    private static function change_capture_enabled() {
        return (bool) \get_option( 'easy_mcp_ai_change_log_enabled', true );
    }

    



    
    const REASSERTION_WINDOW_DAYS = 30;

    









    private static function count_hook_reassertions() {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return null; 
        }

        $table = $wpdb->prefix . 'easy_mcp_ai_change_log';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table; diagnostics must read live state.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}`
                 WHERE object_type = %s AND object_id = %s
                   AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )",
                'system',
                '_hooks_reasserted',
                self::REASSERTION_WINDOW_DAYS
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return null === $count ? null : (int) $count;
    }
}
