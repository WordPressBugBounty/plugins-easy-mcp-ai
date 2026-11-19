<?php

namespace Easy_MCP_AI\Drafts;

use Easy_MCP_AI\Config;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Draft_Mode {

    const OFF         = 'off';
    const NEW_CONTENT = 'new_content';
    const STRICT      = 'strict';

    const VALUES = array( self::OFF, self::NEW_CONTENT, self::STRICT );

    public static function mode(): string {
        $mode = Config::get( 'draft_mode' );
        return in_array( $mode, self::VALUES, true ) ? $mode : self::STRICT;
    }

    public static function drafts_new(): bool {
        return self::OFF !== self::mode();
    }

    public static function strict(): bool {
        return self::STRICT === self::mode();
    }
}
