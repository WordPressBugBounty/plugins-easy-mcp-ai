<?php
namespace Easy_MCP_AI\Meta;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Meta_Key {

    const READS_EVERY_ROW = 'The meta key "0" is read by WordPress as no key, which returns every meta row unfiltered. Omit `key` to read the keys this tool may show, or name the key itself. Nothing was read.';

    public static function reads_every_row( string $key ): bool {
        return '' === $key || '0' === $key;
    }
}
