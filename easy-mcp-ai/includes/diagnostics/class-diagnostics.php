<?php
































namespace Easy_MCP_AI\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Diagnostics {

    



    const CACHE_OPTION = 'easy_mcp_ai_diagnostics_last_run';

    
    const STALE_AFTER = DAY_IN_SECONDS;

    
    private static $checks = array();

    
    private static $results = null;

    
    private static $checks_loaded = false;

    












    const CHECK_CLASS_FILES = array(
        'class-check-transport.php',
        'class-check-schema.php',
        'class-check-tool-visibility.php',
        'class-check-conflicts.php',
        'class-check-config.php',
        'class-check-environment.php',
        'class-check-session.php',
        'class-check-observability.php',
        'class-check-multisite.php',
        'class-check-header-probe.php',
        'class-check-edge-block.php',
    );

    








    public static function load_check_classes() {
        if ( self::$checks_loaded ) {
            return;
        }
        self::$checks_loaded = true;

        
        
        
        foreach ( self::CHECK_CLASS_FILES as $file ) {
            $path = __DIR__ . '/' . $file;
            if ( is_readable( $path ) ) {
                require_once $path;
            }
        }
    }

    









    public static function register( $cb, $id = '', $deep_only = false ) {
        self::$checks[] = array( 'cb' => $cb, 'id' => (string) $id, 'deep_only' => (bool) $deep_only );
    }

    













    public static function register_core_checks( $tool_registry = null ) {
        self::register( array( Check_Transport::class, 'run' ), 'a' );
        self::register( array( Check_Notices::class, 'run' ), 'a' );
        self::register( array( Check_Session::class, 'run' ), 'b' );
        self::register( array( Check_Schema::class, 'run' ), 'c' );
        
        
        
        
        
        
        
        self::register( function () use ( $tool_registry ) {
            return Check_Tool_Visibility::run( $tool_registry );
        }, 'd' );
        self::register( array( Check_Conflicts::class, 'run' ), 'e' );
        self::register( array( Check_Config::class, 'run' ), 'f' );
        self::register( array( Check_Environment::class, 'run' ), 'g' );
        
        
        
        self::register( array( Check_Header_Probe::class, 'run' ), 'a1', true );
        
        
        
        self::register( array( Check_Edge_Block::class, 'run' ), 'a9', true );
        self::register( array( Check_Observability::class, 'run' ), 'h', true );
        self::register( array( Check_Multisite::class, 'run' ), 'i', true );
    }

    




    public static function run( $deep = false ) {
        
        
        
        
        
        
        
        
        
        self::load_check_classes();

        $results = array();

        foreach ( self::$checks as $check ) {
            
            
            
            if ( ! empty( $check['deep_only'] ) && ! $deep ) {
                $results[] = Diagnostic_Result::unknown(
                    '' !== $check['id'] ? $check['id'] : 'unknown',
                    Diagnostic_Result::TIER_INFO,
                    __( 'Detailed check', 'easy-mcp-ai' ),
                    __( 'Not run — press Re-run checks to include the slower checks.', 'easy-mcp-ai' ),
                    
                    
                    
                    
                    array( 'deferred' => true )
                );
                continue;
            }

            try {
                $produced = call_user_func( $check['cb'] );
            } catch ( \Throwable $e ) {
                
                
                $results[] = Diagnostic_Result::unknown(
                    '' !== $check['id'] ? $check['id'] : 'unknown',
                    Diagnostic_Result::TIER_INFO,
                    __( 'Check could not run', 'easy-mcp-ai' ),
                    self::redact_message( $e->getMessage() )
                );
                continue;
            }

            if ( ! is_array( $produced ) ) {
                continue;
            }

            foreach ( $produced as $result ) {
                if ( $result instanceof Diagnostic_Result ) {
                    $results[] = $result;
                }
            }
        }

        self::$results = $results;
        self::persist( $results );

        
        
        
        
        
        
        
        
        
        return self::redact_cross_tenant( $results );
    }

    





    public static function maybe_run() {
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        if ( function_exists( 'current_user_can' ) && ! \current_user_can( 'manage_options' ) ) {
            return self::cached();
        }

        if ( self::is_stale() ) {
            return self::run();
        }
        return self::cached();
    }

    












    public static function redact_message( $message ) {
        $message = (string) $message;

        
        
        $message = preg_replace( '#(?:/[^/\s:()"\']+){2,}/([^/\s:()"\']+)#', '…/$1', $message );
        $message = preg_replace( '#[A-Za-z]:\\\\(?:[^\\\\\s:()"\']+\\\\)+([^\\\\\s:()"\']+)#', '…\\\\$1', $message );

        return $message;
    }

    



    public static function cached() {
        if ( null !== self::$results ) {
            return self::redact_cross_tenant( self::$results );
        }

        $cache = \get_option( self::CACHE_OPTION, array() );
        if ( ! is_array( $cache ) || empty( $cache['results'] ) || ! is_array( $cache['results'] ) ) {
            return array();
        }

        $results = array();
        foreach ( $cache['results'] as $row ) {
            $results[] = Diagnostic_Result::from_array( $row );
        }

        self::$results = $results;
        return self::redact_cross_tenant( $results );
    }

    































    private static function redact_cross_tenant( array $results ) {
        
        
        $is_multisite = function_exists( 'is_multisite' ) && \is_multisite();
        if ( ! $is_multisite ) {
            return $results;
        }

        
        
        $gate = __DIR__ . '/class-check-multisite.php';
        if ( is_readable( $gate ) ) {
            require_once $gate;
        }

        $have_gate = class_exists( __NAMESPACE__ . '\\Check_Multisite' );
        $may_read  = $have_gate ? Check_Multisite::may_read_network() : false;

        if ( ! self::must_withhold_network( $is_multisite, $have_gate, $may_read ) ) {
            return $results;
        }

        return self::withhold_network_rows( $results, $have_gate ? true : null );
    }

    


















    public static function must_withhold_network( $is_multisite, $gate_available, $may_read ) {
        if ( ! $is_multisite ) {
            return false; 
        }
        if ( ! $gate_available ) {
            return true;  
        }
        return ! $may_read;
    }

    









    private static function withhold_network_rows( array $results, $have_gate ) {
        $out = array();
        foreach ( $results as $result ) {
            if ( 'i1' !== $result->id() ) {
                $out[] = $result;
                continue;
            }

            $out[] = ( true === $have_gate )
                ? Check_Multisite::evaluate_per_site_tables(
                    true,
                    null, 
                    0,
                    0,
                    Check_Multisite::REASON_NOT_PERMITTED
                )
                : Diagnostic_Result::unknown(
                    'i1',
                    Diagnostic_Result::TIER_WARNING,
                    __( 'Every site on the network is set up', 'easy-mcp-ai' ),
                    __( 'Only a network administrator can check the other sites on this network.', 'easy-mcp-ai' ),
                    array( 'not_permitted' => true )
                );
        }

        return $out;
    }

    
    public static function last_run_at() {
        $cache = \get_option( self::CACHE_OPTION, array() );
        if ( ! is_array( $cache ) || ! isset( $cache['generated_at'] ) ) {
            return null;
        }
        return (int) $cache['generated_at'];
    }

    public static function is_stale() {
        $at = self::last_run_at();
        if ( null === $at ) {
            return true;
        }
        return ( time() - $at ) >= self::STALE_AFTER;
    }

    















    public static function invalidate() {
        $cache = \get_option( self::CACHE_OPTION, array() );
        if ( ! is_array( $cache ) || empty( $cache ) ) {
            return;
        }

        $cache['generated_at'] = 0;
        \update_option( self::CACHE_OPTION, $cache, false );
    }

    







    const INVALIDATING_OPTIONS = array(
        'permalink_structure',
        'siteurl',
        'home',
    );

    



    public static function register_invalidation() {
        foreach ( self::INVALIDATING_OPTIONS as $option ) {
            \add_action( 'update_option_' . $option, array( __CLASS__, 'invalidate' ), 10, 0 );
            \add_action( 'add_option_' . $option, array( __CLASS__, 'invalidate' ), 10, 0 );
        }
    }

    




    public static function blockers() {
        return array_values( array_filter( self::cached(), function ( $r ) {
            return $r->renders_in_notice();
        } ) );
    }

    
    






    public static function summary() {
        $counts = array( 'total' => 0, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'unknown' => 0, 'deferred' => 0 );

        foreach ( self::cached() as $r ) {
            $counts['total']++;
            $evidence = $r->evidence();
            if ( is_array( $evidence ) && ! empty( $evidence['deferred'] ) ) {
                $counts['deferred']++;
            }
            switch ( $r->status() ) {
                case Diagnostic_Result::STATUS_PASS:
                    $counts['pass']++;
                    break;
                case Diagnostic_Result::STATUS_WARN:
                    $counts['warn']++;
                    break;
                case Diagnostic_Result::STATUS_FAIL:
                    $counts['fail']++;
                    break;
                default:
                    $counts['unknown']++;
            }
        }

        return $counts;
    }

    









    private static function persist( array $results ) {
        $payload = array(
            'generated_at' => time(),
            'results'      => array_map( function ( $r ) { return $r->to_array(); }, $results ),
        );

        
        
        \update_option( self::CACHE_OPTION, $payload, false );

        $stored = \get_option( self::CACHE_OPTION, array() );
        return is_array( $stored ) && isset( $stored['generated_at'] ) && $stored['generated_at'] === $payload['generated_at'];
    }

    

    
    public static function reset_for_tests() {
        self::$checks  = array();
        self::$results = null;
    }

    
    public static function reset_for_tests_preserving_registry() {
        self::$results = null;
    }
}
