<?php

namespace Easy_MCP_AI\Tools\WP_Mail_SMTP;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Settings extends Base_Tool {

    use Mail_SMTP_Tool;

    const GROUPS = array(
        'mail'         => array(
            'from_email'       => array( 'string', array(), 'From email address.' ),
            'from_name'        => array( 'string', array(), 'From name.' ),
            'mailer'           => array( 'string', array( 'mail', 'smtp', 'sendgrid', 'mailgun', 'postmark', 'sendlayer', 'resend', 'smtpcom', 'smtp2go', 'sparkpost', 'mailjet', 'mailersend', 'brevo', 'elasticemail', 'mandrill' ), 'The mailer that sends the site\'s email; mail is PHP\'s own. Send its settings in its group in the same call.' ),
            'return_path'      => array( 'boolean', array(), 'Set the Return-Path to the From email, so bounces come back to it.' ),
            'from_email_force' => array( 'boolean', array(), 'Use the From email for every email, whatever a plugin sets.' ),
            'from_name_force'  => array( 'boolean', array(), 'Use the From name for every email, whatever a plugin sets.' ),
        ),
        'general'      => array(
            'do_not_send'           => array( 'boolean', array(), 'true stops every email the site sends.' ),
            'hide_am_notifications' => array( 'boolean', array(), 'Hide WP Mail SMTP\'s product notifications.' ),
        ),
        'smtp'         => array(
            'host'       => array( 'string', array(), 'SMTP server host name.' ),
            'port'       => array( 'integer', array(), 'SMTP port, e.g. 587, 465 or 25.' ),
            'encryption' => array( 'string', array( 'none', 'ssl', 'tls' ), 'Encryption.' ),
            'autotls'    => array( 'boolean', array(), 'Use TLS when the server offers it.' ),
            'auth'       => array( 'boolean', array(), 'Whether the server needs a user name and password.' ),
            'user'       => array( 'string', array(), 'SMTP user name.' ),
            'pass'       => array( 'string', array(), 'SMTP password. Write-only: never returned.' ),
        ),
        'sendgrid'     => array(
            'api_key' => array( 'string', array(), 'SendGrid API key. Write-only.' ),
            'domain'  => array( 'string', array(), 'Optional sending domain.' ),
        ),
        'mailgun'      => array(
            'api_key' => array( 'string', array(), 'Mailgun API key. Write-only.' ),
            'domain'  => array( 'string', array(), 'Mailgun sending domain.' ),
            'region'  => array( 'string', array( 'US', 'EU' ), 'Mailgun region.' ),
        ),
        'postmark'     => array(
            'api_key'        => array( 'string', array(), 'Postmark server API token. Write-only.' ),
            'message_stream' => array( 'string', array(), 'Optional message stream.' ),
        ),
        'sendlayer'    => array(
            'api_key' => array( 'string', array(), 'SendLayer API key. Write-only.' ),
        ),
        'resend'       => array(
            'api_key' => array( 'string', array(), 'Resend API key. Write-only.' ),
        ),
        'smtpcom'      => array(
            'api_key' => array( 'string', array(), 'SMTP.com API key. Write-only.' ),
            'channel' => array( 'string', array(), 'SMTP.com sender channel name.' ),
        ),
        'smtp2go'      => array(
            'api_key' => array( 'string', array(), 'SMTP2GO API key. Write-only.' ),
        ),
        'sparkpost'    => array(
            'api_key' => array( 'string', array(), 'SparkPost API key. Write-only.' ),
            'region'  => array( 'string', array( 'US', 'EU' ), 'SparkPost region.' ),
        ),
        'mailjet'      => array(
            'api_key'    => array( 'string', array(), 'Mailjet API key. Write-only.' ),
            'secret_key' => array( 'string', array(), 'Mailjet secret key. Write-only.' ),
        ),
        'mailersend'   => array(
            'api_key'  => array( 'string', array(), 'MailerSend API key. Write-only.' ),
            'pro_plan' => array( 'boolean', array(), 'Whether the MailerSend account is on a paid plan.' ),
        ),
        'brevo'        => array(
            'api_key' => array( 'string', array(), 'Brevo (formerly Sendinblue) API key. Write-only.' ),
            'domain'  => array( 'string', array(), 'Optional sending domain.' ),
        ),
        'elasticemail' => array(
            'api_key' => array( 'string', array(), 'Elastic Email API key. Write-only.' ),
        ),
        'mandrill'     => array(
            'api_key' => array( 'string', array(), 'Mandrill API key. Write-only.' ),
        ),
    );

    public function get_name() {
        return 'wp_mailsmtp_update_settings';
    }

    public function get_description() {
        return 'Changes WP Mail SMTP\'s settings, the ones its own `wp wp-mail-smtp option set` command changes, stored the same way. Send only what changes, as groups: `mail` (from_email, from_name, mailer, return_path, from_email_force, from_name_force), `general` (do_not_send: true stops every email the site sends) and one object per mailer with its settings, e.g. { "mail": { "mailer": "smtp" }, "smtp": { "host": "smtp.example.com", "port": 587, "encryption": "tls", "auth": true, "user": "me", "pass": "…" } }. A password or API key is write-only: it is never returned or logged. Every setting sent is checked first, and a call with one refused changes nothing: a setting WP Mail SMTP does not list, a value of the wrong type or outside its allowed values, text WP Mail SMTP would store differently (spaces at the ends, tags), or a setting a constant in wp-config.php sets. Gmail, Outlook, Zoho and Amazon SES sign in through wp-admin and cannot be set up here. Returns { updated, changed, unchanged, settings } where `settings` is what wp_mailsmtp_get_settings returns; updated is false, with a notice, when every value sent was already stored. Requires WP Mail SMTP 4.9 or later and the capability WP Mail SMTP needs to save its settings; on a network whose WP Mail SMTP settings are network-wide, a super admin.';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        $properties = array();
        foreach ( self::GROUPS as $group => $keys ) {
            $fields = array();
            foreach ( $keys as $key => $field ) {
                $fields[ $key ] = array(
                    'type'        => $field[0],
                    'description' => $field[2],
                );
                if ( array() !== $field[1] ) {
                    $fields[ $key ]['enum'] = $field[1];
                }
            }
            $properties[ $group ] = array(
                'type'        => 'object',
                'description' => sprintf( 'WP Mail SMTP\'s %s settings to change.', $group ),
                'properties'  => $fields,
            );
        }
        return array(
            'type'       => 'object',
            'properties' => $properties,
            'required'   => array(),
        );
    }

    public function redact_for_record( array $arguments ) {
        $secret_keys = Mail_SMTP_Settings::secret_keys();
        foreach ( $arguments as $group => $values ) {
            if ( is_string( $values ) ) {
                $decoded = json_decode( $values, true );
                if ( ! is_array( $decoded ) ) {
                    $arguments[ $group ] = Mail_SMTP_Settings::MASK;
                    continue;
                }
                $values = $decoded;
            }
            if ( is_array( $values ) ) {
                $arguments[ $group ] = self::masked( $values, $secret_keys[ $group ] ?? array() );
            }
        }
        return $arguments;
    }

    private static function masked( array $values, array $secret_keys ): array {
        foreach ( $values as $key => $value ) {
            if ( is_string( $key ) && ( in_array( $key, $secret_keys, true ) || Mail_SMTP_Settings::is_secret_name( $key ) ) ) {
                $values[ $key ] = Mail_SMTP_Settings::MASK;
            } elseif ( is_array( $value ) ) {
                $values[ $key ] = self::masked( $value, array() );
            }
        }
        return $values;
    }

    public function describe( array $arguments ) {
        return self::generic_description( $this, $this->redact_for_record( $arguments ) );
    }

    public function approval_details( array $arguments ): ?array {
        return self::generic_details( $this, $this->redact_for_record( $arguments ) );
    }

    public function execute( array $arguments ) {
        Mail_SMTP_Settings::require_available();
        Mail_SMTP_Settings::require_network_admin_for_network_settings();
        $groups = array();
        foreach ( $arguments as $group => $values ) {
            if ( is_string( $values ) ) {
                $values = $this->parse_json_param( $values, (string) $group );
            }
            if ( ! is_array( $values ) || ( array() !== $values && array_values( $values ) === $values ) ) {
                throw new \InvalidArgumentException( sprintf( '%s must be an object of WP Mail SMTP settings, e.g. { "host": "smtp.example.com" }. Nothing was changed.', $group ) );
            }
            if ( array() !== $values ) {
                $groups[ (string) $group ] = $values;
            }
        }
        if ( array() === $groups ) {
            throw new \InvalidArgumentException( 'Send at least one setting to change, e.g. { "mail": { "from_name": "Example" } }. Nothing was changed.' );
        }
        $plan = Mail_SMTP_Settings::plan( $groups );
        if ( array() === $plan['changes'] ) {
            return array(
                'updated'   => false,
                'changed'   => array(),
                'unchanged' => $plan['unchanged'],
                'notice'    => 'Every value sent was already stored. Nothing was changed.',
                'settings'  => Mail_SMTP_Settings::read(),
            );
        }
        Mail_SMTP_Settings::write( $plan['changes'], $plan['sent'] );
        return array(
            'updated'   => true,
            'changed'   => array_keys( $plan['changes'] ),
            'unchanged' => $plan['unchanged'],
            'settings'  => Mail_SMTP_Settings::read(),
        );
    }
}
