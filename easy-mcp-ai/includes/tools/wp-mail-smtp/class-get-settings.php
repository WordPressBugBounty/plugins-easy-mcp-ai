<?php

namespace Easy_MCP_AI\Tools\WP_Mail_SMTP;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Settings extends Base_Tool {

    use Mail_SMTP_Tool;

    public function get_name() {
        return 'wp_mailsmtp_get_settings';
    }

    public function get_description() {
        return 'Reads WP Mail SMTP\'s settings and setup state, the ones its own `wp wp-mail-smtp option` command lists. Optional: `mailer` (a mailer name such as smtp, sendgrid or brevo) to show that mailer\'s settings instead of the selected one\'s. Returns { plugin: { version, edition }, mailer: { name, title, editable }, mailers: [{ name, title }], shown_mailer, settings: { <group>: { <key>: { value, allowed?, from_constant } } }, setup: { mailer_configured, test_email_sent, domain_check, wizard, constants_enabled }, network_wide }. Settings are the mail group (from_email, from_name, mailer, return_path, from_email_force, from_name_force), the general group (do_not_send stops every email the site sends) and the shown mailer\'s group, e.g. settings.smtp.host. A password or API key is never returned: it shows { set: true|false } instead of a value. `from_constant` means wp-config.php sets it and it cannot be changed here. `editable` is false for a mailer set up in wp-admin that these tools cannot change (Gmail, Outlook, Zoho, Amazon SES).' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_describe( 'wp_mailsmtp_update_settings' ) ? ' Change settings with wp_mailsmtp_update_settings.' : '' ) . ' Requires WP Mail SMTP 4.9 or later and the capability WP Mail SMTP needs to save its settings (manage_options; manage_network_options when its settings are network-wide).';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'mailer' => array(
                    'type'        => 'string',
                    'description' => 'A mailer whose settings to show, by its name in `mailers` (e.g. smtp, sendgrid, brevo). Default: the selected mailer.',
                ),
            ),
            'required'   => array(),
        );
    }

    public function execute( array $arguments ) {
        Mail_SMTP_Settings::require_available();
        $mailer = $arguments['mailer'] ?? null;
        if ( null !== $mailer && ( ! is_string( $mailer ) || '' === $mailer ) ) {
            throw new \InvalidArgumentException( 'mailer must be a mailer name such as smtp, or left out for the selected mailer.' );
        }
        return Mail_SMTP_Settings::read( $mailer );
    }
}
