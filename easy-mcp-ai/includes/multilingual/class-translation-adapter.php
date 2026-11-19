<?php

namespace Easy_MCP_AI\Multilingual;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Translation_Adapter {

    abstract public function id(): string;

    abstract public function label(): string;

    abstract public function is_present(): bool;

    abstract public function version(): string;

    abstract public function languages(): array;

    abstract public function is_translated_type( string $post_type ): bool;

    abstract public function language_of( int $post_id ): ?string;

    abstract public function translations_of( int $post_id ): array;

    abstract public function create_params( string $code ): array;

    abstract public function while_creating( string $code, callable $save );

    abstract public function assign( array $group, string $post_type ): void;

    abstract public function read_query_vars( string $code ): array;

    abstract public function while_reading( string $code, callable $read );

    public function forget( array $post_ids ): void {
        unset( $post_ids );
    }

    public function refusal( string $code ): ?string {
        unset( $code );
        return null;
    }

    public function codes(): array {
        return array_column( $this->languages(), 'code' );
    }

    protected static function default_first( array $languages ): array {
        usort(
            $languages,
            static function ( array $a, array $b ): int {
                return (int) $b['default'] - (int) $a['default'];
            }
        );
        return $languages;
    }

    public function default_code(): string {
        foreach ( $this->languages() as $language ) {
            if ( $language['default'] ) {
                return $language['code'];
            }
        }
        return '';
    }
}
