<?php

























namespace Easy_MCP_AI\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Check_Conflicts {

    


























    const KNOWN_MCP_PLUGINS = array(
        'wordpress-mcp' => 'WordPress MCP',
        'mcp-adapter'   => 'MCP Adapter',
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

    
    const CACHE_COVERED    = 'covered';
    const CACHE_UNCOVERED  = 'uncovered';
    const CACHE_UNREADABLE = 'unreadable';

    


    public static function run() {
        
        
        $foreign_rest_auth = self::foreign_rest_auth_callbacks( self::rest_auth_callback_names() );

        return array(
            self::evaluate_competing_mcp( self::active_from( 'easy_mcp_ai_diagnostics_known_mcp_plugins', self::KNOWN_MCP_PLUGINS ) ),
            self::evaluate_security_plugins( self::active_from( 'easy_mcp_ai_diagnostics_known_security_plugins', self::KNOWN_SECURITY_PLUGINS ) ),
            self::evaluate_rest_filters( array() !== $foreign_rest_auth, $foreign_rest_auth ),
            self::evaluate_hook_stripping( self::count_hook_reassertions(), self::change_capture_enabled() ),
            self::evaluate_cache_plugins( self::active_from( 'easy_mcp_ai_diagnostics_known_cache_plugins', self::KNOWN_CACHE_PLUGINS ), self::cache_coverage_now() ),
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
                    
                    
                    
                    
                    count( $found ) > 1
                        /* translators: %s: comma-separated plugin names. */
                        ? __( 'Also active: %s. If they also serve MCP or OAuth discovery on this site, an AI client may connect to whichever answers first, so a connection can succeed and still reach the wrong plugin.', 'easy-mcp-ai' )
                        /* translators: %s: a plugin name. */
                        : __( 'Also active: %s. If it also serves MCP or OAuth discovery on this site, an AI client may connect to whichever answers first, so a connection can succeed and still reach the wrong plugin.', 'easy-mcp-ai' ),
                    implode( ', ', $found )
                ),
                __( 'Check which plugin your AI client is actually connected to. If it is the wrong one, keep a single MCP plugin active, deactivate the other, and reconnect.', 'easy-mcp-ai' ),
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
        
        
        
        
        $label = __( 'Firewall and security plugins', 'easy-mcp-ai' );

        
        
        if ( null === $found ) {
            return Diagnostic_Result::unknown( 'e2', Diagnostic_Result::TIER_INFO, $label, __( 'Could not read the list of active plugins.', 'easy-mcp-ai' ) );
        }
        $found = (array) $found;

        if ( ! empty( $found ) ) {
            return Diagnostic_Result::pass(
                'e2',
                Diagnostic_Result::TIER_INFO,
                $label,
                sprintf(
                    count( $found ) > 1
                        /* translators: %s: comma-separated plugin names. */
                        ? __( 'Detected: %s. These plugins work normally on most sites and nothing here indicates a problem. If an AI client cannot connect, a firewall rule refusing requests before WordPress sees them is worth ruling out: allow /wp-json/easy-mcp-ai/ and /.well-known/oauth-*, and check it is not filtering by user agent.', 'easy-mcp-ai' )
                        /* translators: %s: a plugin name. */
                        : __( 'Detected: %s. This plugin works normally on most sites and nothing here indicates a problem. If an AI client cannot connect, a firewall rule refusing requests before WordPress sees them is worth ruling out: allow /wp-json/easy-mcp-ai/ and /.well-known/oauth-*, and check it is not filtering by user agent.', 'easy-mcp-ai' ),
                    implode( ', ', $found )
                ),
                array( 'security_plugins' => $found )
            );
        }

        
        
        
        
        
        return Diagnostic_Result::pass(
            'e2',
            Diagnostic_Result::TIER_INFO,
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
                __( 'Another plugin or theme filters REST API authentication on this site, and this plugin reaches WordPress through that API. Plugins that legitimately add their own authentication — WooCommerce, Jetpack and similar — are already excluded from this check, so what is named here is worth identifying: a "disable REST API" or "restrict REST API" feature works the same way.', 'easy-mcp-ai' ) . $named,
                __( 'Identify the plugin the callback above belongs to. If it offers a "disable REST API" or "restrict REST API" setting, exempt the easy-mcp-ai/v1 namespace so AI clients can authenticate.', 'easy-mcp-ai' ),
                
                
                
                
                
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
                Diagnostic_Result::TIER_INFO,
                $label,
                __( 'Could not read the change history table.', 'easy-mcp-ai' )
            );
        }

        
        if ( ! $capture_enabled ) {
            return Diagnostic_Result::unknown(
                'e4',
                Diagnostic_Result::TIER_INFO,
                $label,
                __( 'Could not verify — change history recording is switched off, so no evidence is collected either way.', 'easy-mcp-ai' )
            );
        }

        if ( (int) $marker_count > 0 ) {
            return Diagnostic_Result::pass(
                'e4',
                Diagnostic_Result::TIER_INFO,
                $label,
                sprintf(
                    /* translators: 1: number of recorded reassertion events, 2: number of days in the reporting window. */
                    __( 'On %1$d occasion(s) in the last %2$d days, another plugin removed this plugin\'s tracking hooks during an AI request. They were re-registered automatically and the change was still recorded, so nothing was lost and no action is needed. If you are investigating missing history entries, the Change History page lists the affected requests.', 'easy-mcp-ai' ),
                    (int) $marker_count,
                    self::REASSERTION_WINDOW_DAYS
                ),
                array( 'hook_reassertions' => (int) $marker_count )
            );
        }

        
        
        
        return Diagnostic_Result::pass(
            'e4',
            Diagnostic_Result::TIER_INFO,
            $label,
            sprintf(
                /* translators: %d: number of days in the reporting window. */
                __( 'No hook removals recorded in the last %d days.', 'easy-mcp-ai' ),
                self::REASSERTION_WINDOW_DAYS
            ),
            array( 'hook_reassertions' => 0 )
        );
    }

    


















    public static function evaluate_cache_plugins( $found, $coverage = null ) {
        $label = __( 'Caching excludes API endpoints', 'easy-mcp-ai' );

        
        
        if ( null === $found ) {
            return Diagnostic_Result::unknown( 'e5', Diagnostic_Result::TIER_WARNING, $label, __( 'Could not read the list of active plugins.', 'easy-mcp-ai' ) );
        }
        $found = (array) $found;

        if ( empty( $found ) ) {
            return Diagnostic_Result::pass(
                'e5',
                Diagnostic_Result::TIER_WARNING,
                $label,
                __( 'No caching plugin found among the active plugins. Caching done by the host or a CDN is not visible from here.', 'easy-mcp-ai' ),
                array( 'cache_plugins' => array() )
            );
        }

        $coverage = is_array( $coverage ) ? $coverage : array();
        $uncovered = array();
        $unreadable = array();
        foreach ( $found as $name ) {
            $verdict = isset( $coverage[ $name ] ) ? $coverage[ $name ] : self::CACHE_UNREADABLE;
            if ( self::CACHE_UNCOVERED === $verdict ) {
                $uncovered[] = $name;
            } elseif ( self::CACHE_COVERED !== $verdict ) {
                $unreadable[] = $name;
            }
        }

        $evidence = array(
            'cache_plugins'  => $found,
            'coverage'       => $coverage,
            'checked_paths'  => array_values( self::protected_paths() ),
        );

        if ( $uncovered ) {
            return Diagnostic_Result::warn(
                'e5',
                Diagnostic_Result::TIER_WARNING,
                $label,
                sprintf(
                    count( $uncovered ) > 1
                        /* translators: %s: comma-separated plugin names. */
                        ? __( '%s are caching without an exclusion for this plugin\'s endpoints. A cached authentication or discovery response can make a connection succeed once and then fail, or keep working after a token is revoked.', 'easy-mcp-ai' )
                        /* translators: %s: a plugin name. */
                        : __( '%s is caching without an exclusion for this plugin\'s endpoints. A cached authentication or discovery response can make a connection succeed once and then fail, or keep working after a token is revoked.', 'easy-mcp-ai' ),
                    implode( ', ', $uncovered )
                ),
                sprintf(
                    count( $uncovered ) > 1
                        /* translators: %s: comma-separated URL paths to exclude. */
                        ? __( 'Exclude %s from page caching in each of those plugins\' settings.', 'easy-mcp-ai' )
                        /* translators: %s: comma-separated URL paths to exclude. */
                        : __( 'Exclude %s from page caching in that plugin\'s settings.', 'easy-mcp-ai' ),
                    self::list_paths( self::exclusion_hints() )
                ),
                $evidence
            );
        }

        if ( $unreadable ) {
            return Diagnostic_Result::unknown(
                'e5',
                Diagnostic_Result::TIER_WARNING,
                $label,
                sprintf(
                    count( $unreadable ) > 1
                        /* translators: 1: comma-separated plugin names, 2: comma-separated URL paths to exclude. */
                        ? __( 'Detected: %1$s. Their exclusion rules could not be read from here, so this was not verified either way. If AI connections drop or behave inconsistently, exclude %2$s from page caching.', 'easy-mcp-ai' )
                        /* translators: 1: a plugin name, 2: comma-separated URL paths to exclude. */
                        : __( 'Detected: %1$s. Its exclusion rules could not be read from here, so this was not verified either way. If AI connections drop or behave inconsistently, exclude %2$s from page caching.', 'easy-mcp-ai' ),
                    implode( ', ', $unreadable ),
                    self::list_paths( self::exclusion_hints() )
                ),
                $evidence
            );
        }

        return Diagnostic_Result::pass(
            'e5',
            Diagnostic_Result::TIER_WARNING,
            $label,
            sprintf(
                count( $found ) > 1
                    /* translators: %s: comma-separated plugin names. */
                    ? __( 'Detected: %s. Their own exclusion rules already cover this plugin\'s endpoints.', 'easy-mcp-ai' )
                    /* translators: %s: a plugin name. */
                    : __( 'Detected: %s. Its own exclusion rules already cover this plugin\'s endpoints.', 'easy-mcp-ai' ),
                implode( ', ', $found )
            ),
            $evidence
        );
    }

    

    











    public static function protected_paths() {
        $paths = array();

        
        
        
        
        
        
        foreach ( array(
            'MCP endpoint'        => array( 'rest_url', 'easy-mcp-ai/v1/mcp' ),
            'OAuth server'        => array( 'home_url', '/.well-known/oauth-authorization-server' ),
            'OAuth resource'      => array( 'home_url', '/.well-known/oauth-protected-resource' ),
            'OpenID discovery'    => array( 'home_url', '/.well-known/openid-configuration' ),
        ) as $label => $spec ) {
            list( $fn, $arg ) = $spec;
            if ( ! function_exists( $fn ) ) {
                continue;
            }
            $parts = \wp_parse_url( (string) $fn( $arg ) );
            if ( ! is_array( $parts ) || ! isset( $parts['path'] ) || '' === $parts['path'] ) {
                continue;
            }
            $subject = $parts['path'];
            if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
                $subject .= '?' . $parts['query'];
            }
            $paths[ $label ] = $subject;
        }

        return $paths;
    }

    












    private static function exclusion_hints() {
        $hints = array();

        foreach ( self::protected_paths() as $subject ) {
            if ( false !== strpos( $subject, 'rest_route=' ) ) {
                $hints[] = 'rest_route=/easy-mcp-ai/';
                continue;
            }
            
            
            $ns = strpos( $subject, '/easy-mcp-ai/' );
            if ( false !== $ns ) {
                $hints[] = substr( $subject, 0, $ns + strlen( '/easy-mcp-ai/' ) );
                continue;
            }
            $oauth = strpos( $subject, '/oauth-' );
            if ( false !== $oauth ) {
                $hints[] = substr( $subject, 0, $oauth + strlen( '/oauth-' ) ) . '*';
                continue;
            }
            $hints[] = $subject;
        }

        return array_values( array_unique( $hints ) );
    }

    



    private static function list_paths( array $hints ) {
        if ( count( $hints ) < 2 ) {
            return implode( '', $hints );
        }
        $last = array_pop( $hints );
        return sprintf(
            /* translators: 1: comma-separated list of all but the last item, 2: the last item. */
            __( '%1$s and %2$s', 'easy-mcp-ai' ),
            implode( ', ', $hints ),
            $last
        );
    }

    







    private static function regex_hits( $pattern, $subject, $insensitive = false ) {
        if ( '' === $pattern || false !== strpos( $pattern, '~' ) ) {
            return false;
        }
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a third party's stored pattern may not compile; false is the answer, not a warning.
        return 1 === @preg_match( '~' . $pattern . '~' . ( $insensitive ? 'i' : '' ), $subject );
    }

    






















    private static function rule_covers( $slug, $rule, $subject ) {
        
        
        
        
        
        
        $candidates = array( $rule );
        if ( 'wp-rocket' === $slug ) {
            $candidates[] = rtrim( (string) $rule, '/' );
        }

        foreach ( array_unique( $candidates ) as $r ) {
            $r = trim( (string) $r );
            if ( '' === $r ) {
                continue;
            }

            if ( 'w3-total-cache' === $slug && self::regex_hits( $r, $subject, true ) ) {
                return true;
            }
            if ( 'wp-super-cache' === $slug && self::regex_hits( $r, $subject ) ) {
                return true;
            }
            if ( 'litespeed-cache' === $slug && self::litespeed_hits( $r, $subject ) ) {
                return true;
            }
            if ( 'wp-rocket' === $slug ) {
                
                
                
                if ( self::regex_hits( $r, $subject, true )
                    || false !== strpos( $subject, $r ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    
    private static function litespeed_hits( $rule, $subject ) {
        $starts = '^' === substr( $rule, 0, 1 );
        $ends   = '$' === substr( $rule, -1 );

        if ( $starts && $ends ) {
            return substr( $rule, 1, -1 ) === $subject;
        }
        if ( $ends ) {
            $needle = substr( $rule, 0, -1 );
            return '' !== $needle && substr( $subject, -strlen( $needle ) ) === $needle;
        }
        if ( $starts ) {
            $needle = substr( $rule, 1 );
            return '' !== $needle && 0 === strpos( $subject, $needle );
        }
        return false !== strpos( $subject, $rule );
    }

    
    private static function wpfc_hits( $entry, $subject ) {
        if ( ! is_object( $entry ) || ! isset( $entry->content ) ) {
            return false;
        }
        if ( isset( $entry->type ) && 'page' !== $entry->type ) {
            return false;
        }
        $content = trim( (string) $entry->content );
        $prefix  = isset( $entry->prefix ) ? (string) $entry->prefix : '';
        if ( '' === $content ) {
            return false;
        }

        switch ( $prefix ) {
            case 'exact':
                return strtolower( trim( $content, '/' ) ) === strtolower( trim( $subject, '/' ) );
            case 'regex':
                return self::regex_hits( $content, $subject, true );
            case 'startwith':
                
                
                
                
                
                return 0 === stripos( ltrim( $subject, '/' ), ltrim( $content, '/' ) );
            case 'contain':
                return false !== stripos( $subject, $content );
        }

        return false;
    }

    











    private static function cache_rules_for( $slug ) {
        try {
            switch ( $slug ) {
                case 'wp-rocket':
                    if ( function_exists( 'get_rocket_option' ) ) {
                        $rules = \get_rocket_option( 'cache_reject_uri', array() );
                        return is_array( $rules ) ? $rules : null;
                    }
                    return null;

                case 'wp-super-cache':
                    
                    
                    return isset( $GLOBALS['cache_rejected_uri'] ) && is_array( $GLOBALS['cache_rejected_uri'] )
                        ? $GLOBALS['cache_rejected_uri']
                        : null;

                case 'w3-total-cache':
                    if ( function_exists( 'w3tc_config' ) ) {
                        $config = \w3tc_config();
                        if ( is_object( $config ) && method_exists( $config, 'get_array' ) ) {
                            $rules = $config->get_array( 'pgcache.reject.uri' );
                            return is_array( $rules ) ? $rules : null;
                        }
                    }
                    return null;

                case 'litespeed-cache':
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    $rules = \apply_filters( 'litespeed_conf', 'cache-exc' );
                    return is_array( $rules ) ? $rules : null;

                case 'wp-fastest-cache':
                    
                    
                    
                    
                    
                    
                    $json = \get_option( 'WpFastestCacheExclude', '' );
                    if ( ! is_string( $json ) || '' === $json ) {
                        return array();
                    }
                    $rules = json_decode( $json );
                    return is_array( $rules ) ? $rules : null;
            }
        } catch ( \Throwable $e ) {
            
            
            return null;
        }

        return null;
    }

    





    public static function cache_exclusion_coverage( array $detected, ?array $paths = null ) {
        $paths = null === $paths ? self::protected_paths() : $paths;
        if ( ! $paths ) {
            
            return array_fill_keys( array_values( $detected ), self::CACHE_UNREADABLE );
        }

        $coverage = array();
        foreach ( $detected as $slug => $name ) {
            $rules = self::cache_rules_for( $slug );
            if ( null === $rules ) {
                $coverage[ $name ] = self::CACHE_UNREADABLE;
                continue;
            }

            $all_covered = true;
            foreach ( $paths as $subject ) {
                $hit = false;
                foreach ( $rules as $rule ) {
                    $hit = ( 'wp-fastest-cache' === $slug )
                        ? self::wpfc_hits( $rule, $subject )
                        : self::rule_covers( $slug, $rule, $subject );
                    if ( $hit ) {
                        break;
                    }
                }
                if ( ! $hit ) {
                    $all_covered = false;
                    break;
                }
            }

            $coverage[ $name ] = $all_covered ? self::CACHE_COVERED : self::CACHE_UNCOVERED;
        }

        return $coverage;
    }

    







    private static function cache_coverage_now() {
        $detected = self::detected_cache_plugins();
        return null === $detected ? null : self::cache_exclusion_coverage( $detected );
    }

    




    private static function detected_cache_plugins() {
        $known = \apply_filters( 'easy_mcp_ai_diagnostics_known_cache_plugins', self::KNOWN_CACHE_PLUGINS );
        if ( ! is_array( $known ) ) {
            $known = self::KNOWN_CACHE_PLUGINS;
        }

        $active = self::active_plugin_paths();
        if ( null === $active ) {
            return null;
        }

        $slugs = array();
        foreach ( $active as $path ) {
            $path = (string) $path;
            $dir  = ( false !== strpos( $path, '/' ) ) ? substr( $path, 0, strpos( $path, '/' ) ) : $path;

            $slugs[ strtolower( $dir ) ] = true;
        }

        $found = array();
        foreach ( $known as $slug => $name ) {
            if ( isset( $slugs[ strtolower( (string) $slug ) ] ) ) {
                $found[ (string) $slug ] = (string) $name;
            }
        }

        return $found;
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
