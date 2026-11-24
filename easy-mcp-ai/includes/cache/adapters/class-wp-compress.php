<?php

namespace Easy_MCP_AI\Cache\Adapters;

use Easy_MCP_AI\Cache\Cache_Adapter;
use Easy_MCP_AI\Cache\Layer_Result;
use Easy_MCP_AI\Cache\Purge_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Wp_Compress extends Cache_Adapter {

    const INTEGRATIONS = 'wps_ic_cache_integrations';

    const URL_KEY = 'wps_ic_url_key';

    const PURGE_ALL = 'wpc_purge_all_html_cache';

    const OWN_CACHE_ONLY_FILTERS = array( 'wpc_foreign_purge_enabled', 'wpc_host_cache_purge' );

    const MIN_VERSION = '7.30.00';

    public function id(): string {
        return 'wp-compress';
    }

    public function label(): string {
        return 'WP Compress';
    }

    public function kind(): string {
        return self::KIND_PAGE;
    }

    public function tier(): int {
        return self::TIER_PAGE;
    }

    public function scopes(): array {
        return array( Purge_Request::SCOPE_SITE, Purge_Request::SCOPE_URLS, Purge_Request::SCOPE_POSTS );
    }

    public function is_present(): bool {
        $calls = $this->calls();
        return $calls->defined( 'WPC_PLUGIN_VERSION' ) && $calls->class_exists( self::INTEGRATIONS );
    }

    public function reaches_network( Purge_Request $request ): bool {
        return Purge_Request::SCOPE_SITE === $request->scope && $this->calls()->is_multisite();
    }

    public function noise_options(): array {
        return array( 'wpc_purge_all_at10', 'wpc_stale43_ver' );
    }

    public function purge( Purge_Request $request ): Layer_Result {
        $version = $this->calls()->constant( 'WPC_PLUGIN_VERSION' );
        $version = is_string( $version ) ? $version : '0';
        if ( version_compare( $version, self::MIN_VERSION, '<' ) ) {
            return $this->skip( 'api_missing', sprintf( 'WP Compress %s is older than 7.30.00, the first release this tool can clear, so nothing was called. Update WP Compress, or clear its cache from its own admin menu.', $version ) );
        }
        return parent::purge( $request );
    }

    protected function purge_site( Purge_Request $request ): Layer_Result {
        $calls = $this->calls();
        if ( ! $calls->function_exists( self::PURGE_ALL ) ) {
            return $this->helper_missing( self::PURGE_ALL . '()' );
        }
        $own_cache_only = static function (): bool {
            return false;
        };
        foreach ( self::OWN_CACHE_ONLY_FILTERS as $filter ) {
            $calls->add_filter( $filter, $own_cache_only, PHP_INT_MAX );
        }
        try {
            $calls->call( self::PURGE_ALL, array( 'hard' ) );
        } finally {
            foreach ( self::OWN_CACHE_ONLY_FILTERS as $filter ) {
                $calls->remove_filter( $filter, $own_cache_only, PHP_INT_MAX );
            }
        }
        return $this->sent( 'WP Compress cleared its whole HTML page cache.' );
    }

    protected function purge_urls( Purge_Request $request ): Layer_Result {
        $calls = $this->calls();
        if ( ! $calls->method_exists( self::URL_KEY, 'setup' ) ) {
            return $this->helper_missing( self::URL_KEY . '::setup()' );
        }
        if ( ! $calls->method_exists( self::INTEGRATIONS, 'purgeCacheFiles' ) ) {
            return $this->helper_missing( self::INTEGRATIONS . '::purgeCacheFiles()' );
        }
        $keys = $calls->instantiate( self::URL_KEY );
        foreach ( $request->urls as $url ) {
            $key = $calls->call( array( $keys, 'setup' ), array( $url ) );
            if ( is_string( $key ) && '' !== $key ) {
                $calls->call( array( self::INTEGRATIONS, 'purgeCacheFiles' ), array( $key ) );
            }
        }
        return $this->sent( sprintf( 'WP Compress cleared %d page(s).', count( $request->urls ) ) );
    }
}
