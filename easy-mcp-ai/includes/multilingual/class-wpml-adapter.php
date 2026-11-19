<?php

namespace Easy_MCP_AI\Multilingual;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPML_Adapter extends Translation_Adapter {

    public function id(): string {
        return 'wpml';
    }

    public function label(): string {
        return 'WPML';
    }

    public function is_present(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' )
            && is_object( $this->sitepress() )
            && array() !== $this->active_languages();
    }

    public function version(): string {
        return defined( 'ICL_SITEPRESS_VERSION' ) ? (string) constant( 'ICL_SITEPRESS_VERSION' ) : '';
    }

    public function languages(): array {
        $default = (string) \apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
        $list    = array();
        foreach ( $this->active_languages() as $code => $language ) {
            $list[] = array(
                'code'    => (string) $code,
                'name'    => (string) ( $language['translated_name'] ?? $language['native_name'] ?? $code ),
                'locale'  => (string) ( $language['default_locale'] ?? '' ),
                'default' => (string) $code === $default,
            );
        }
        return self::default_first( $list );
    }

    public function is_translated_type( string $post_type ): bool {
        return (bool) \apply_filters( 'wpml_is_translated_post_type', false, $post_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
    }

    public function language_of( int $post_id ): ?string {
        $details = $this->details( $post_id );
        $code    = is_object( $details ) && isset( $details->language_code ) ? (string) $details->language_code : '';
        return '' === $code ? null : $code;
    }

    public function translations_of( int $post_id ): array {
        $type = $this->element_type( (string) \get_post_type( $post_id ) );
        $trid = (int) \apply_filters( 'wpml_element_trid', null, $post_id, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
        if ( $trid <= 0 ) {
            return array();
        }
        $elements = \apply_filters( 'wpml_get_element_translations', null, $trid, $type, false, true, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
        $out      = array();
        foreach ( (array) $elements as $code => $element ) {
            if ( is_object( $element ) && isset( $element->element_id ) && (int) $element->element_id > 0 ) {
                $out[ (string) $code ] = (int) $element->element_id;
            }
        }
        return $out;
    }

    public function create_params( string $code ): array {
        unset( $code );
        return array();
    }

    public function while_creating( string $code, callable $save ) {
        $filter = static function () use ( $code ): string {
            return $code;
        };
        \add_filter( 'wpml_save_post_lang', $filter, PHP_INT_MAX ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
        try {
            return $save();
        } finally {
            \remove_filter( 'wpml_save_post_lang', $filter, PHP_INT_MAX ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
        }
    }

    public function assign( array $group, string $post_type ): void {
        $type = $this->element_type( $post_type );
        foreach ( $group as $post_id ) {
            foreach ( $this->translations_of( $post_id ) as $code => $other ) {
                if ( ! in_array( $other, $group, true ) ) {
                    $this->set_details( $other, $type, null, $code, null );
                }
            }
        }
        $source_code = $this->original_code( $group );
        $source_id   = (int) $group[ $source_code ];
        $trid        = $this->trid_of( $source_id, $type );
        $this->set_details( $source_id, $type, $trid > 0 ? $trid : null, $source_code, null );
        $trid = $this->trid_of( $source_id, $type );
        foreach ( $group as $code => $post_id ) {
            if ( $post_id !== $source_id ) {
                $this->set_details( $post_id, $type, $trid, (string) $code, $source_code );
                $this->set_details( $post_id, $type, $trid, (string) $code, $source_code );
            }
        }
    }

    public function forget( array $post_ids ): void {
        unset( $post_ids );
        $translations = $GLOBALS['wpml_post_translations'] ?? null;
        if ( is_object( $translations ) && method_exists( $translations, 'reload' ) ) {
            $translations->reload();
        }
    }

    public function read_query_vars( string $code ): array {
        unset( $code );
        return array();
    }

    public function while_reading( string $code, callable $read ) {
        $sitepress = $this->sitepress();
        $before    = (string) \apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
        $sitepress->switch_lang( $code );
        try {
            return $read();
        } finally {
            $sitepress->switch_lang( '' === $before ? null : $before );
        }
    }

    private function active_languages(): array {
        $languages = \apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
        return is_array( $languages ) ? $languages : array();
    }

    private function sitepress(): ?object {
        $sitepress = $GLOBALS['sitepress'] ?? null;
        return is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) ? $sitepress : null;
    }

    private function element_type( string $post_type ): string {
        return (string) \apply_filters( 'wpml_element_type', $post_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
    }

    private function details( int $post_id ): ?object {
        $details = \apply_filters( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
            'wpml_element_language_details',
            null,
            array(
                'element_id'   => $post_id,
                'element_type' => (string) \get_post_type( $post_id ),
            )
        );
        return is_object( $details ) ? $details : null;
    }

    private function original_code( array $group ): string {
        foreach ( $group as $code => $post_id ) {
            $details = $this->details( $post_id );
            if ( is_object( $details ) && empty( $details->source_language_code ) && count( $this->translations_of( $post_id ) ) > 1 ) {
                return (string) $code;
            }
        }
        return (string) array_key_first( $group );
    }

    private function trid_of( int $post_id, string $type ): int {
        return (int) \apply_filters( 'wpml_element_trid', null, $post_id, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
    }

    private function set_details( int $post_id, string $type, ?int $trid, string $code, ?string $source_code ): void {
        \do_action( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own action.
            'wpml_set_element_language_details',
            array(
                'element_id'           => $post_id,
                'element_type'         => $type,
                'trid'                 => $trid,
                'language_code'        => $code,
                'source_language_code' => $source_code,
            )
        );
    }
}
