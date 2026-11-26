<?php

namespace Easy_MCP_AI\Tools\WP_Mail_SMTP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Mail_SMTP_Tool {

    public function get_category() {
        return 'wp-mail-smtp';
    }

    public function get_required_capability() {
        return Mail_SMTP_Settings::capability();
    }
}
