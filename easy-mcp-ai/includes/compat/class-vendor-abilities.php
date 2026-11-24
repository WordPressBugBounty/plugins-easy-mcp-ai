<?php

namespace Easy_MCP_AI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Vendor_Abilities {

    const PREFIX = '';

    const VENDOR = '';

    const CAPABILITY = '';

    const CLASSES = array( Learnpress_Abilities::class, Elementor_Abilities::class, Breakdance_Abilities::class, Latepoint_Abilities::class );

    const TICKS_SETTLED_OPTION = 'easy_mcp_ai_vendor_ability_ticks_settled';

    const TICKS_SETTLED_VERSION = 2;

    const SETTLED_IN = 1;

    private static $ours = false;

    private static $failed = array();

    public static function serve( bool $ours = true ): void {
        self::$ours = $ours;
    }

    public static function serve_background_tick(): bool {
        if ( self::$ours ) {
            return true;
        }
        if ( \did_action( 'wp_abilities_api_categories_init' ) || \did_action( 'wp_abilities_api_init' ) ) {
            return false;
        }
        self::serve();
        return true;
    }

    public static function plugin_loaded(): bool {
        return true;
    }

    public static function served(): bool {
        return self::$ours;
    }

    public static function is_our_rest_route( string $route ): bool {
        return 1 === preg_match( '#^/?easy-mcp-ai(-admin|-profile)?/v1(/|$)#i', $route );
    }

    public static function vendor_of( string $prefix ): ?string {
        foreach ( self::CLASSES as $class ) {
            if ( $class::PREFIX === $prefix . '/' ) {
                return $class::VENDOR;
            }
        }
        return null;
    }

    public static function capability_of( string $slug ): ?string {
        $class = self::class_of( $slug );
        return null !== $class && '' !== $class::CAPABILITY ? $class::CAPABILITY : null;
    }

    public static function settle_ticks(): void {
        $done = self::settled_pass();
        if ( $done >= self::TICKS_SETTLED_VERSION ) {
            return;
        }
        $enabled = \get_option( 'easy_mcp_ai_enabled_abilities', array() );
        if ( is_array( $enabled ) && count( self::untick( $enabled, $done ) ) !== count( $enabled ) ) {
            \Easy_MCP_AI\Tools\Tool_Policy::locked( static function () {
                \Easy_MCP_AI\Config::read_uncached( self::TICKS_SETTLED_OPTION );
                $done = self::settled_pass();
                if ( $done >= self::TICKS_SETTLED_VERSION ) {
                    return;
                }
                $current = \Easy_MCP_AI\Config::read_uncached( 'easy_mcp_ai_enabled_abilities' );
                $keep    = is_array( $current ) ? self::untick( $current, $done ) : array();
                if ( is_array( $current ) && count( $keep ) !== count( $current ) ) {
                    \update_option( 'easy_mcp_ai_enabled_abilities', $keep );
                    if ( \Easy_MCP_AI\Config::read_uncached( 'easy_mcp_ai_enabled_abilities' ) !== $keep ) {
                        return;
                    }
                }
                \update_option( self::TICKS_SETTLED_OPTION, self::TICKS_SETTLED_VERSION );
            } );
            return;
        }
        \update_option( self::TICKS_SETTLED_OPTION, self::TICKS_SETTLED_VERSION );
    }

    public static function settled_pass(): int {
        $stored = \get_option( self::TICKS_SETTLED_OPTION );
        if ( is_int( $stored ) ) {
            return max( 0, $stored );
        }
        return is_string( $stored ) && ctype_digit( $stored ) ? (int) $stored : 0;
    }

    private static function untick( array $enabled, int $done ): array {
        $keep = array();
        foreach ( $enabled as $slug ) {
            $class = is_string( $slug ) ? self::class_of( $slug ) : null;
            if ( null === $class || $class::SETTLED_IN <= $done || $class::ran_before_switches_dropped( $slug ) ) {
                $keep[] = $slug;
            }
        }
        return $keep;
    }

    abstract protected static function ran_before_switches_dropped( string $slug ): bool;

    private static function class_of( string $slug ): ?string {
        foreach ( self::CLASSES as $class ) {
            if ( 0 === strpos( $slug, $class::PREFIX ) ) {
                return $class;
            }
        }
        return null;
    }

    public static function reset(): void {
        self::$ours = false;
        self::$failed = array();
    }

    protected static function active(): bool {
        return self::$ours && ! isset( self::$failed[ static::class ] );
    }

    public static function own_abilities_registered(): bool {
        return static::any_registered();
    }

    protected static function any_registered(): bool {
        foreach ( (array) wp_get_abilities() as $ability ) {
            $name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
            if ( 0 === strpos( $name, static::PREFIX ) && static::from_own_registration( $ability ) ) {
                return true;
            }
        }
        return false;
    }

    protected static function from_own_registration( object $ability ): bool {
        unset( $ability );
        return true;
    }

    protected static function run( callable $registration, string $what ): void {
        try {
            $registration();
        } catch ( \Throwable $e ) {
            self::$failed[ static::class ] = true;
            error_log( sprintf( 'Easy MCP AI: %s %s failed, %s abilities not registered: %s', static::VENDOR, $what, static::VENDOR, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: a vendor change must be visible
        }
    }

    protected static function has_method( string $class, string $method, bool $static ): bool {
        if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
            return false;
        }
        $reflection = new \ReflectionMethod( $class, $method );
        return $reflection->isPublic() && $static === $reflection->isStatic() && 0 === $reflection->getNumberOfRequiredParameters();
    }
}
