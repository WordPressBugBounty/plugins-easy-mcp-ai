<?php

namespace Easy_MCP_AI\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Latepoint_Abilities extends Vendor_Abilities {

    const ABILITIES_CLASS = 'LatePointAbilities';

    const SETTINGS_CLASS = 'OsSettingsHelper';

    const AUTH_CLASS = 'OsAuthHelper';

    const ABILITIES_FILE = 'lib/abilities/class-latepoint-abilities.php';

    const ROOT_CONSTANT = 'LATEPOINT_ABSPATH';

    const ON_CONSTANT = 'LATEPOINT_VALUE_ON';

    const OFF = 'off';

    const SWITCHES = array( 'latepoint_abilities_api', 'latepoint_abilities_api_edit', 'latepoint_abilities_api_delete' );

    const CATEGORY = 'latepoint';

    const PREFIX = 'latepoint/';

    const VENDOR = 'LatePoint';

    const CAPABILITY = 'manage_options';

    const SETTLED_IN = 2;

    public static function load( ?string $root = null, string $abilities = self::ABILITIES_CLASS, string $settings = self::SETTINGS_CLASS ): void {
        if ( ! self::active() || ! self::settled() || ! function_exists( 'wp_register_ability' ) || ! self::switches_available( $settings ) ) {
            return;
        }
        if ( null === $root ) {
            if ( ! defined( self::ROOT_CONSTANT ) ) {
                return;
            }
            $root = (string) constant( self::ROOT_CONSTANT );
        }
        self::run(
            static function () use ( $root, $abilities ) {
                if ( ! class_exists( $abilities, false ) ) {
                    $file = $root . self::ABILITIES_FILE;
                    if ( ! is_readable( $file ) ) {
                        throw new \RuntimeException( sprintf( 'its %s is not where LatePoint 5.7.8 has it, or cannot be read', self::ABILITIES_FILE ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- written to the error log only.
                    }
                    include_once $file;
                }
                foreach ( array( 'include_modules', 'register_category', 'register_all' ) as $method ) {
                    if ( ! self::has_method( $abilities, $method, true ) ) {
                        throw new \RuntimeException( sprintf( '%s::%s() is not public, static and argument-free', $abilities, $method ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- written to the error log only.
                    }
                }
                call_user_func( array( $abilities, 'include_modules' ) );
                remove_action( 'wp_abilities_api_init', array( $abilities, 'register_all' ) );
            },
            'loading its abilities file'
        );
    }

    public static function register_category( string $abilities = self::ABILITIES_CLASS ): void {
        if ( ! self::active() || ! self::has_method( $abilities, 'register_category', true ) || ! function_exists( 'wp_has_ability_category' ) ) {
            return;
        }
        if ( wp_has_ability_category( self::CATEGORY ) ) {
            return;
        }
        self::run(
            static function () use ( $abilities ) {
                call_user_func( array( $abilities, 'register_category' ) );
            },
            $abilities . '::register_category()'
        );
    }

    public static function register_abilities( string $abilities = self::ABILITIES_CLASS, string $settings = self::SETTINGS_CLASS ): void {
        if ( ! self::active() || ! self::settled() || ! self::switches_available( $settings ) || ! self::has_method( $abilities, 'register_all', true ) ) {
            return;
        }
        if ( ! function_exists( 'wp_has_ability_category' ) || ! function_exists( 'wp_get_abilities' ) ) {
            return;
        }
        if ( ! wp_has_ability_category( self::CATEGORY ) || self::any_registered() ) {
            return;
        }
        self::run(
            static function () use ( $abilities, $settings ) {
                $prior = self::set_switches( self::SWITCHES, $settings );
                try {
                    call_user_func( array( $abilities, 'register_all' ) );
                } finally {
                    self::restore_switches( $prior, $settings );
                }
            },
            $abilities . '::register_all()'
        );
    }

    public static function settled(): bool {
        return self::settled_pass() >= self::SETTLED_IN;
    }

    public static function switches_for( ?string $kind ): array {
        if ( 'write' === $kind ) {
            return array( self::SWITCHES[0], self::SWITCHES[1] );
        }
        if ( 'delete' === $kind ) {
            return array( self::SWITCHES[0], self::SWITCHES[2] );
        }
        return array( self::SWITCHES[0] );
    }

    public static function switches_available( string $settings = self::SETTINGS_CLASS ): bool {
        if ( ! defined( self::ON_CONSTANT ) || ! class_exists( $settings, false ) || ! property_exists( $settings, 'loaded_values' ) || ! method_exists( $settings, 'is_on' ) ) {
            return false;
        }
        $property = new \ReflectionProperty( $settings, 'loaded_values' );
        $method   = new \ReflectionMethod( $settings, 'is_on' );
        return $property->isPublic() && $property->isStatic() && $method->isPublic() && $method->isStatic();
    }

    public static function switches_read_on( string $settings = self::SETTINGS_CLASS ): bool {
        foreach ( self::SWITCHES as $switch ) {
            if ( true !== call_user_func( array( $settings, 'is_on' ), $switch ) ) {
                return false;
            }
        }
        return true;
    }

    public static function set_switches( array $on, string $settings = self::SETTINGS_CLASS ): array {
        $cache = is_array( $settings::$loaded_values ) ? $settings::$loaded_values : array();
        $prior = array();
        foreach ( self::SWITCHES as $switch ) {
            $prior[ $switch ]                    = array( array_key_exists( $switch, $cache ), $cache[ $switch ] ?? null );
            $settings::$loaded_values[ $switch ] = in_array( $switch, $on, true ) ? constant( self::ON_CONSTANT ) : self::OFF;
        }
        return $prior;
    }

    public static function restore_switches( array $prior, string $settings = self::SETTINGS_CLASS ): void {
        foreach ( $prior as $switch => $entry ) {
            if ( $entry[0] ) {
                $settings::$loaded_values[ $switch ] = $entry[1];
            } elseif ( is_array( $settings::$loaded_values ) ) {
                unset( $settings::$loaded_values[ $switch ] );
            }
        }
    }

    public static function plugin_loaded(): bool {
        return defined( self::ROOT_CONSTANT );
    }

    protected static function ran_before_switches_dropped( string $slug ): bool {
        if ( ! self::switches_available() || true !== call_user_func( array( self::SETTINGS_CLASS, 'is_on' ), self::SWITCHES[0] ) ) {
            return false;
        }
        $kind = self::kind_of( $slug );
        if ( 'read' === $kind ) {
            return true;
        }
        $edit   = call_user_func( array( self::SETTINGS_CLASS, 'is_on' ), self::SWITCHES[1] );
        $delete = call_user_func( array( self::SETTINGS_CLASS, 'is_on' ), self::SWITCHES[2] );
        if ( 'write' === $kind ) {
            return true === $edit;
        }
        if ( 'delete' === $kind ) {
            return true === $delete;
        }
        return true === $edit && true === $delete;
    }

    public static function kind_of( string $slug ): ?string {
        return self::kinds()[ $slug ] ?? null;
    }

    private static $kinds = null;

    private static function kinds(): array {
        if ( null !== self::$kinds ) {
            return self::$kinds;
        }
        self::$kinds = array();
        try {
            if ( ! class_exists( self::ABILITIES_CLASS, false ) || ! property_exists( self::ABILITIES_CLASS, 'config_modules' ) ) {
                return self::$kinds;
            }
            $property = new \ReflectionProperty( self::ABILITIES_CLASS, 'config_modules' );
            if ( PHP_VERSION_ID < 80100 ) {
                $property->setAccessible( true );
            }
            $modules = apply_filters( 'latepoint_ability_config_modules', $property->getValue() );
            foreach ( is_array( $modules ) ? $modules : array() as $module ) {
                $class = is_array( $module ) ? (string) ( $module['class'] ?? '' ) : (string) $module;
                if ( '' === $class || ! class_exists( $class, false ) || ! self::has_method( $class, 'get_abilities', true ) ) {
                    continue;
                }
                foreach ( (array) call_user_func( array( $class, 'get_abilities' ) ) as $ability ) {
                    if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_id' ) || ! method_exists( $ability, 'is_read_only' ) || ! method_exists( $ability, 'is_destructive' ) ) {
                        continue;
                    }
                    $destructive                                = true === $ability->is_destructive();
                    self::$kinds[ (string) $ability->get_id() ] = $destructive ? 'delete' : ( true === $ability->is_read_only() ? 'read' : 'write' );
                }
            }
        } catch ( \Throwable $e ) {
            self::$kinds = array();
        }
        return self::$kinds;
    }

    public static function reset_kinds(): void {
        self::$kinds = null;
    }
}
