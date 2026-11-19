<?php

namespace Easy_MCP_AI\Multilingual;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Translation_Registry {

    private static ?array $adapters = null;

    public static function all(): array {
        if ( null === self::$adapters ) {
            self::$adapters = array( new WPML_Adapter(), new Polylang_Adapter() );
        }
        return self::$adapters;
    }

    public static function active(): ?Translation_Adapter {
        $present = array_values(
            array_filter(
                self::all(),
                static function ( Translation_Adapter $adapter ): bool {
                    return $adapter->is_present();
                }
            )
        );
        return 1 === count( $present ) ? $present[0] : null;
    }

    public static function require_active(): Translation_Adapter {
        $adapter = self::active();
        if ( null === $adapter ) {
            throw new \RuntimeException( 'No multilingual plugin (WPML or Polylang) is active on this site, or both are.' );
        }
        return $adapter;
    }

    public static function set_adapters( ?array $adapters ): void {
        self::$adapters = $adapters;
    }
}
