<?php

namespace Easy_MCP_AI\Multilingual;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Polylang_Adapter extends Translation_Adapter {

    public function id(): string {
        return 'polylang';
    }

    public function label(): string {
        return 'Polylang';
    }

    public function is_present(): bool {
        return function_exists( 'pll_languages_list' )
            && function_exists( 'pll_save_post_translations' )
            && function_exists( 'PLL' )
            && array() !== (array) \pll_languages_list();
    }

    public function version(): string {
        return defined( 'POLYLANG_VERSION' ) ? (string) constant( 'POLYLANG_VERSION' ) : '';
    }

    public function languages(): array {
        $codes   = array_values( array_map( 'strval', (array) \pll_languages_list( array( 'fields' => 'slug' ) ) ) );
        $names   = array_values( (array) \pll_languages_list( array( 'fields' => 'name' ) ) );
        $locales = array_values( (array) \pll_languages_list( array( 'fields' => 'locale' ) ) );
        $default = function_exists( 'pll_default_language' ) ? (string) \pll_default_language() : '';
        $list    = array();
        foreach ( $codes as $i => $code ) {
            $list[] = array(
                'code'    => $code,
                'name'    => (string) ( $names[ $i ] ?? $code ),
                'locale'  => (string) ( $locales[ $i ] ?? '' ),
                'default' => $code === $default,
            );
        }
        return self::default_first( $list );
    }

    public function is_translated_type( string $post_type ): bool {
        return function_exists( 'pll_is_translated_post_type' ) && (bool) \pll_is_translated_post_type( $post_type );
    }

    public function language_of( int $post_id ): ?string {
        $code = function_exists( 'pll_get_post_language' ) ? \pll_get_post_language( $post_id ) : false;
        return is_string( $code ) && '' !== $code ? $code : null;
    }

    public function translations_of( int $post_id ): array {
        $group = function_exists( 'pll_get_post_translations' ) ? (array) \pll_get_post_translations( $post_id ) : array();
        $out   = array();
        foreach ( $group as $code => $id ) {
            if ( is_string( $code ) && (int) $id > 0 ) {
                $out[ $code ] = (int) $id;
            }
        }
        return $out;
    }

    public function create_params( string $code ): array {
        return array( 'lang' => $code );
    }

    public function while_creating( string $code, callable $save ) {
        unset( $code );
        $polylang = \PLL();
        $had      = is_object( $polylang ) && property_exists( $polylang, 'curlang' );
        $before   = $had ? $polylang->curlang : null;
        try {
            return $save();
        } finally {
            if ( $had ) {
                $polylang->curlang = $before;
            }
        }
    }

    public function assign( array $group, string $post_type ): void {
        unset( $post_type );
        foreach ( $group as $code => $post_id ) {
            if ( $this->language_of( $post_id ) !== $code ) {
                \pll_set_post_language( $post_id, $code );
            }
        }
        \pll_save_post_translations( $group );
    }

    public function forget( array $post_ids ): void {
        foreach ( $post_ids as $post_id ) {
            $type = get_post_type( $post_id );
            if ( is_string( $type ) && '' !== $type ) {
                clean_object_term_cache( $post_id, $type );
            }
        }
        wp_cache_delete( 'last_changed', 'terms' );
        $groups = array();
        foreach ( $post_ids as $post_id ) {
            $ids    = wp_get_object_terms( $post_id, 'post_translations', array( 'fields' => 'ids' ) );
            $groups = array_merge( $groups, is_array( $ids ) ? array_map( 'intval', $ids ) : array() );
        }
        if ( array() !== $groups ) {
            clean_term_cache( array_values( array_unique( $groups ) ), 'post_translations' );
        }
    }

    public function read_query_vars( string $code ): array {
        return 'all' === $code ? array() : array( 'lang' => $code );
    }

    public function while_reading( string $code, callable $read ) {
        unset( $code );
        return $read();
    }

    public function refusal( string $code ): ?string {
        $capabilities = '\\WP_Syntex\\Polylang\\Capabilities\\Capabilities';
        if ( ! class_exists( $capabilities ) || ! method_exists( $capabilities, 'get_user' ) ) {
            return null;
        }
        $polylang = \PLL();
        $model    = is_object( $polylang ) && isset( $polylang->model ) ? $polylang->model : null;
        $language = is_object( $model ) && method_exists( $model, 'get_language' ) ? $model->get_language( $code ) : null;
        $user     = $capabilities::get_user();
        if ( ! is_object( $language ) || ! is_object( $user ) || ! method_exists( $user, 'can_translate' ) ) {
            return null;
        }
        return $user->can_translate( $language )
            ? null
            : sprintf( 'Polylang does not let this account translate into %s.', $code );
    }
}
