<?php

namespace Easy_MCP_AI\Multilingual;

use Easy_MCP_AI\Option_Lock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Translation_Write {

    const LOCK = 'easy_mcp_ai_translation_lock';

    const LOCK_TTL = 30;

    const LOCK_WAIT = 10;

    private ?Translation_Adapter $adapter;

    private string $post_type;

    private string $code;

    private array $others;

    private ?string $code_before;

    private array $group_before;

    private string $lock = '';

    private function __construct( ?Translation_Adapter $adapter, string $post_type, string $code = '', array $others = array(), ?string $code_before = null, array $group_before = array(), string $lock = '' ) {
        $this->adapter      = $adapter;
        $this->post_type    = $post_type;
        $this->code         = $code;
        $this->others       = $others;
        $this->code_before  = $code_before;
        $this->group_before = $group_before;
        $this->lock         = $lock;
    }

    public function __destruct() {
        $this->release();
    }

    public static function properties(): array {
        $adapter = Translation_Registry::active();
        if ( null === $adapter ) {
            return array();
        }
        return array(
            'lang'           => array(
                'type'        => 'string',
                'enum'        => $adapter->codes(),
                'description' => sprintf( 'Language of this post, one of the site\'s %1$s languages (%2$s; default %3$s). On create without it %1$s gives the post its default language.', $adapter->label(), implode( ', ', $adapter->codes() ), $adapter->default_code() ),
            ),
            'translation_of' => array(
                'type'        => 'integer',
                'description' => 'ID of an existing post of the same type that this post translates: the post joins that post\'s translation group, in the language `lang` names (required on create). Refused when the group already has a post in that language.',
            ),
        );
    }

    public static function description(): string {
        $adapter = Translation_Registry::active();
        return null === $adapter ? '' : sprintf( ' This site keeps translations with %s: send `lang` to set the post\'s language and `translation_of` to link it as the translation of another post (wp_get_translations shows a post\'s group, wp_link_translations links existing posts). The reply then carries `translation`: { language, translations, changed }.', $adapter->label() );
    }

    public static function asked( array $arguments ): bool {
        return self::sent( $arguments, 'lang' ) || self::sent( $arguments, 'translation_of' );
    }

    public static function plan( array $arguments, string $post_type, ?int $post_id, bool $hold = false ): self {
        if ( ! self::asked( $arguments ) ) {
            return new self( null, $post_type );
        }
        $sends_lang = self::sent( $arguments, 'lang' );
        $sends_of   = self::sent( $arguments, 'translation_of' );
        $adapter = Translation_Registry::active();
        if ( null === $adapter ) {
            throw new \InvalidArgumentException( 'lang and translation_of need WPML or Polylang, and neither is active on this site. Nothing was changed.' );
        }
        if ( ! $adapter->is_translated_type( $post_type ) ) {
            throw new \InvalidArgumentException( sprintf( '%s does not translate the post type "%s", so it takes no lang or translation_of. Nothing was changed.', $adapter->label(), $post_type ) );
        }
        $code = $sends_lang ? self::code( $adapter, $arguments['lang'] ) : null;
        $lock = $hold ? self::lock() : '';
        try {
            if ( '' !== $lock ) {
                $adapter->forget( self::read_ids( $arguments, $post_id ) );
            }
            $plan = self::checked( $adapter, $arguments, $post_type, $post_id, $code, $sends_of, $lock );
            $lock = '';
            return $plan;
        } finally {
            if ( '' !== $lock ) {
                Option_Lock::release( self::LOCK, $lock );
            }
        }
    }

    private static function read_ids( array $arguments, ?int $post_id ): array {
        $ids = null === $post_id ? array() : array( $post_id );
        $of  = $arguments['translation_of'] ?? null;
        if ( is_int( $of ) || ( is_string( $of ) && ctype_digit( $of ) ) ) {
            $ids[] = (int) $of;
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    private static function checked( Translation_Adapter $adapter, array $arguments, string $post_type, ?int $post_id, ?string $code, bool $sends_of, string $lock ): self {
        $code_before  = null === $post_id ? null : $adapter->language_of( $post_id );
        $group_before = null === $post_id ? array() : $adapter->translations_of( $post_id );
        $others       = self::others_of( $group_before, $post_id );
        if ( $sends_of ) {
            list( $code, $others ) = self::join( $adapter, $arguments['translation_of'], $post_type, $post_id, $code ?? $code_before, $others );
        }
        $code = (string) $code;
        if ( isset( $others[ $code ] ) ) {
            throw new \InvalidArgumentException( sprintf( 'Post %1$d is already the %2$s translation in this group, so this post cannot be %2$s too: change post %1$d\'s language first, or link the posts with wp_link_translations. Nothing was changed.', $others[ $code ], $code ) );
        }
        $refusal = $adapter->refusal( $code );
        if ( null !== $refusal ) {
            throw new \InvalidArgumentException( $refusal . ' Nothing was changed.' );
        }
        self::require_editable( array_merge( array_values( $others ), array_values( $group_before ), null === $post_id ? array() : array( $post_id ) ) );
        return new self( $adapter, $post_type, $code, $others, $code_before, $group_before, $lock );
    }

    public function alone( array $params, array $arguments ): bool {
        return null !== $this->adapter && array() === $params && empty( $arguments['resave'] );
    }

    public function create_params(): array {
        return null === $this->adapter ? array() : $this->adapter->create_params( $this->code );
    }

    public function save( callable $save ) {
        return null === $this->adapter ? $save() : $this->adapter->while_creating( $this->code, $save );
    }

    public function finish( int $post_id ): array {
        if ( null === $this->adapter ) {
            return array();
        }
        try {
            if ( '' !== $this->lock ) {
                $kept = self::keep( $this->lock, $cause );
                if ( '' === $kept ) {
                    throw new \RuntimeException( sprintf( 'Post %1$d was saved, but it was not linked: %2$s. Check its group with wp_get_translations, then link it with wp_link_translations.', $post_id, $cause ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                $this->lock = $kept;
            }
            $recorded = $this->record( $post_id );
            $overlap  = '' === $this->lock ? null : self::overlap( $this->lock );
            if ( null !== $overlap ) {
                throw new \RuntimeException( sprintf( 'Post %1$d was saved and linked, but %2$s', $post_id, $overlap ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            return $recorded;
        } finally {
            $this->release();
        }
    }

    private function release(): void {
        if ( '' !== $this->lock ) {
            Option_Lock::release( self::LOCK, $this->lock );
            $this->lock = '';
        }
    }

    public static function overlap( string $lock ): ?string {
        global $wpdb;
        if ( Option_Lock::holds( self::LOCK, $lock ) ) {
            return null;
        }
        $error = is_object( $wpdb ) ? (string) ( $wpdb->last_error ?? '' ) : '';
        if ( '' !== $error ) {
            return sprintf( 'the database refused the check that this call still held the translation lock (%s), so whether another call changed the group at the same time is unknown. Check the group with wp_get_translations.', $error );
        }
        return sprintf( 'the write took longer than the translation lock lasts (%d seconds) and another call took the lock over meanwhile, so that call may have changed the group at the same time. Check the group with wp_get_translations.', self::LOCK_TTL );
    }

    public static function keep( string $lock, ?string &$cause = null ): string {
        $cause = null;
        if ( time() - (int) $lock >= self::LOCK_TTL ) {
            $cause = sprintf( 'this call held the translation lock longer than it lasts (%d seconds), so another call may have changed the group since it was read', self::LOCK_TTL );
            return '';
        }
        $kept = Option_Lock::renew( self::LOCK, $lock );
        if ( '' === $kept ) {
            $cause = lcfirst( Option_Lock::lost_cause( 'translation write', self::LOCK_TTL ) );
        }
        return $kept;
    }

    public static function lock(): string {
        return Option_Lock::acquire_waiting( self::LOCK, self::LOCK_TTL, self::LOCK_WAIT, 'translation write' );
    }

    private function record( int $post_id ): array {
        $group   = $this->others + array( $this->code => $post_id );
        $changed = $this->code_before !== $this->code || ! self::same_group( $this->group_before, $group );
        if ( $changed ) {
            $this->adapter->assign( $group, $this->post_type );
        }
        $language = $this->adapter->language_of( $post_id );
        $recorded = $this->adapter->translations_of( $post_id );
        if ( $language !== $this->code || ! self::same_group( $recorded, $group ) ) {
            throw new \RuntimeException(
                sprintf(
                    'Post %1$d was saved, but %2$s recorded it as %3$s in the group %4$s instead of %5$s in %6$s. Check the post\'s language in %2$s before trying again.',
                    $post_id,
                    $this->adapter->label(),
                    $language ?? 'no language',
                    (string) wp_json_encode( (object) $recorded ),
                    $this->code,
                    (string) wp_json_encode( (object) $group )
                )
            );
        }
        ksort( $recorded );
        $out = array(
            'translation' => array(
                'language'     => $this->code,
                'translations' => $recorded,
                'changed'      => $changed,
            ),
        );
        $link = $this->code_before !== $this->code ? get_permalink( $post_id ) : false;
        if ( is_string( $link ) && '' !== $link ) {
            $out['link'] = $link;
        }
        return $out;
    }

    private static function sent( array $arguments, string $key ): bool {
        return isset( $arguments[ $key ] ) && '' !== $arguments[ $key ];
    }

    public static function code( Translation_Adapter $adapter, $value ): string {
        $codes = $adapter->codes();
        if ( ! is_string( $value ) || ! in_array( $value, $codes, true ) ) {
            throw new \InvalidArgumentException( sprintf( 'lang must be one of %1$s (the languages %2$s has on this site), not %3$s. Nothing was changed.', implode( ', ', $codes ), $adapter->label(), is_string( $value ) ? '"' . $value . '"' : 'that value' ) );
        }
        return $value;
    }

    private static function others_of( array $group, ?int $post_id ): array {
        return array_filter(
            $group,
            static function ( int $id ) use ( $post_id ): bool {
                return $id !== $post_id;
            }
        );
    }

    private static function join( Translation_Adapter $adapter, $value, string $post_type, ?int $post_id, ?string $code, array $others ): array {
        $of = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? (int) $value : 0;
        if ( $of <= 0 ) {
            throw new \InvalidArgumentException( 'translation_of must be the ID of an existing post. Nothing was changed.' );
        }
        if ( $of === $post_id ) {
            throw new \InvalidArgumentException( 'translation_of names the post being updated; it must name the post this one translates. Nothing was changed.' );
        }
        $source = get_post( $of );
        if ( ! is_object( $source ) ) {
            throw new \InvalidArgumentException( sprintf( 'translation_of: there is no post with ID %d. Nothing was changed.', $of ) );
        }
        if ( $source->post_type !== $post_type ) {
            throw new \InvalidArgumentException( sprintf( 'translation_of: post %1$d is a %2$s, and a translation must be of the same type (%3$s). Nothing was changed.', $of, $source->post_type, $post_type ) );
        }
        $source_code = $adapter->language_of( $of );
        if ( null === $source_code ) {
            throw new \InvalidArgumentException( sprintf( 'translation_of: post %1$d has no language in %2$s yet. Give it one first (update it with lang), then link this post to it. Nothing was changed.', $of, $adapter->label() ) );
        }
        if ( null === $code ) {
            throw new \InvalidArgumentException( 'translation_of needs lang: the language this post is written in. Nothing was changed.' );
        }
        if ( $code === $source_code ) {
            throw new \InvalidArgumentException( sprintf( 'translation_of: post %1$d is already in %2$s, and a translation must be in another language. Nothing was changed.', $of, $code ) );
        }
        $target = $adapter->translations_of( $of );
        $target = array() === $target ? array( $source_code => $of ) : $target;
        if ( null !== $post_id && ! in_array( $post_id, $target, true ) && array() !== $others ) {
            throw new \InvalidArgumentException( sprintf( 'Post %1$d is already linked with %2$s. Linking it to post %3$d would unlink those, so move it with wp_link_translations, which takes the whole group. Nothing was changed.', $post_id, (string) wp_json_encode( (object) $others ), $of ) );
        }
        return array( $code, self::others_of( $target, $post_id ) );
    }

    public static function require_editable( array $post_ids ): void {
        foreach ( array_unique( $post_ids ) as $post_id ) {
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                throw new \InvalidArgumentException( sprintf( 'This changes the language or translation group of post %d, which this account cannot edit. Nothing was changed.', $post_id ) );
            }
        }
    }

    public static function same_group( array $a, array $b ): bool {
        ksort( $a );
        ksort( $b );
        return $a === $b;
    }
}
