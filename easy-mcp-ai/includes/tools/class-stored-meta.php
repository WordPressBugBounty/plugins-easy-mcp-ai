<?php

namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Stored_Meta {

    public static function form( $value ): ?string {
        $value = maybe_serialize( $value );
        if ( null === $value ) {
            return null;
        }
        if ( is_bool( $value ) ) {
            return $value ? '1' : '';
        }
        return (string) $value;
    }
}
