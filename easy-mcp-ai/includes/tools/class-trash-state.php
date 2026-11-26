<?php

namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Trash_State {

    public static function empty_trash_days() {
        return defined( 'EMPTY_TRASH_DAYS' ) ? EMPTY_TRASH_DAYS : 30;
    }

    public static function comment_trash_off(): bool {
        return ! self::empty_trash_days();
    }
}
