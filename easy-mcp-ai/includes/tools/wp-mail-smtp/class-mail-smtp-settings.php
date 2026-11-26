<?php

namespace Easy_MCP_AI\Tools\WP_Mail_SMTP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Mail_SMTP_Settings {

    const MINIMUM_VERSION = '4.9.0';

    const MASK = \Easy_MCP_AI\History\Change_Redactor::MASK;

    const REGISTRY = '\\WPMailSMTP\\WPCLI\\Options\\Registry';

    const STORE = '\\WPMailSMTP\\Options';

    public static function unavailable(): ?string {
        if ( ! \function_exists( 'wp_mail_smtp' ) || ! \class_exists( self::STORE ) ) {
            return 'WP Mail SMTP is not active on this site.';
        }
        foreach ( array( 'init', 'get', 'get_all_raw', 'set', 'is_const_defined', 'is_const_enabled' ) as $method ) {
            if ( ! \method_exists( self::STORE, $method ) ) {
                return self::too_old();
            }
        }
        if ( ! \class_exists( self::REGISTRY ) || ! \method_exists( self::REGISTRY, 'get_args' ) ) {
            return self::too_old();
        }
        return null;
    }

    public static function require_available(): void {
        $reason = self::unavailable();
        if ( null !== $reason ) {
            throw new \RuntimeException( $reason );
        }
    }

    public static function capability(): string {
        if ( null !== self::unavailable() ) {
            return 'manage_options';
        }
        $core = \wp_mail_smtp();
        if ( ! \is_object( $core ) ) {
            return 'manage_options';
        }
        if ( \method_exists( $core, 'get_capability_manage_global_options' ) ) {
            $capability = $core->get_capability_manage_global_options();
        } elseif ( \method_exists( $core, 'get_capability_manage_options' ) ) {
            $capability = $core->get_capability_manage_options();
        } else {
            $capability = 'manage_options';
        }
        return \is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
    }

    public static function network_wide(): bool {
        return \is_multisite()
            && \class_exists( '\\WPMailSMTP\\WP' )
            && \method_exists( '\\WPMailSMTP\\WP', 'use_global_plugin_settings' )
            && (bool) \WPMailSMTP\WP::use_global_plugin_settings();
    }

    public static function require_network_admin_for_network_settings(): void {
        if ( self::network_wide() && ( ! \is_super_admin() || ! \current_user_can( 'manage_network_options' ) ) ) {
            throw new \RuntimeException( 'WP Mail SMTP keeps one set of settings for the whole network on this site, so changing them needs a network administrator (a super admin with manage_network_options). Nothing was changed.' );
        }
    }

    public static function settings(): array {
        $registry = self::REGISTRY;
        $listed   = ( new $registry() )->get_args();
        $settings = array();
        foreach ( \is_array( $listed ) ? $listed : array() as $arg ) {
            if ( ! \is_array( $arg ) || ! isset( $arg['flag'] ) || ! \is_string( $arg['flag'] ) ) {
                continue;
            }
            $path = isset( $arg['storage_path'] ) && \is_string( $arg['storage_path'] ) && '' !== $arg['storage_path'] ? $arg['storage_path'] : $arg['flag'];
            $name = \explode( '.', $arg['flag'] );
            $at   = \explode( '.', $path );
            if ( 2 !== \count( $name ) || 2 !== \count( $at ) || \in_array( '', \array_merge( $name, $at ), true ) ) {
                continue;
            }
            $settings[ $arg['flag'] ] = array(
                'group'         => $name[0],
                'storage_group' => $at[0],
                'key'           => $at[1],
                'type'          => isset( $arg['type'] ) && \is_string( $arg['type'] ) ? $arg['type'] : 'string',
                'enum'          => isset( $arg['enum'] ) && \is_array( $arg['enum'] ) ? \array_values( \array_map( 'strval', $arg['enum'] ) ) : array(),
                'storage_map'   => isset( $arg['enum_storage_map'] ) && \is_array( $arg['enum_storage_map'] ) ? \array_map( 'strval', $arg['enum_storage_map'] ) : array(),
                'secret'        => ! empty( $arg['sensitive'] ) || self::is_secret_name( $name[1] ) || self::is_secret_name( $at[1] ),
            );
        }
        return $settings;
    }

    public static function is_secret_name( string $key ): bool {
        return \Easy_MCP_AI\Text_Redactor::is_sensitive_key( $key );
    }

    public static function secret_keys(): array {
        try {
            if ( null !== self::unavailable() ) {
                return array();
            }
            $keys = array();
            foreach ( self::settings() as $name => $setting ) {
                if ( $setting['secret'] ) {
                    $keys[ $setting['group'] ][] = \substr( $name, \strlen( $setting['group'] ) + 1 );
                }
            }
            return $keys;
        } catch ( \Throwable $e ) {
            return array();
        }
    }

    public static function read( ?string $mailer = null ): array {
        $settings = self::settings();
        $options  = self::options();
        $mailers  = self::mailers( $settings );
        $selected = self::mailer_name( (string) $options->get( 'mail', 'mailer' ), $settings );
        if ( null !== $mailer && ! isset( $mailers[ $mailer ] ) ) {
            throw new \InvalidArgumentException( \sprintf( 'mailer must be one of %s, not "%s".', \implode( ', ', \array_keys( $mailers ) ), $mailer ) );
        }
        $shown = null === $mailer ? $selected : $mailer;
        $rows  = array();
        foreach ( $settings as $name => $setting ) {
            if ( \in_array( $setting['group'], array( 'mail', 'general', $shown ), true ) ) {
                $key = \substr( $name, \strlen( $setting['group'] ) + 1 );

                $rows[ $setting['group'] ][ $key ] = self::row( $name, $setting, $options, $settings );
            }
        }
        return array(
            'plugin'       => array(
                'version' => \defined( 'WPMS_PLUGIN_VER' ) ? (string) \constant( 'WPMS_PLUGIN_VER' ) : '',
                'edition' => self::is_pro() ? 'pro' : 'lite',
            ),
            'mailer'       => array(
                'name'     => $selected,
                'title'    => self::mailer_title( $selected, $settings ),
                'editable' => isset( $mailers[ $selected ] ),
            ),
            'mailers'      => \array_values( $mailers ),
            'shown_mailer' => $shown,
            'settings'     => $rows,
            'setup'        => self::setup_state(),
            'network_wide' => self::network_wide(),
        );
    }

    public static function plan( array $groups ): array {
        $settings  = self::settings();
        $options   = self::options();
        $errors    = array();
        $sent      = array();
        $changes   = array();
        $unchanged = array();
        foreach ( $groups as $group => $values ) {
            foreach ( $values as $key => $value ) {
                $name = $group . '.' . $key;
                if ( ! isset( $settings[ $name ] ) ) {
                    $errors[] = \sprintf( '%s is not a setting WP Mail SMTP lists (its settings in %s: %s)', $name, $group, self::names_in( $group, $settings ) );
                    continue;
                }
                $setting = $settings[ $name ];
                $error   = self::value_error( $name, $setting, $value );
                if ( null === $error && $options->is_const_defined( $setting['storage_group'], $setting['key'] ) ) {
                    $error = \sprintf( '%s is set by a WP Mail SMTP constant in wp-config.php; remove the constant to change it here', $name );
                }
                if ( null !== $error ) {
                    $errors[] = $error;
                    continue;
                }
                $stored        = self::stored_value( $setting, 'int' === $setting['type'] ? self::integer( $value ) : $value );
                $sent[ $name ] = $stored;
                if ( self::same( $stored, $options->get( $setting['storage_group'], $setting['key'] ) ) ) {
                    $unchanged[] = $name;
                } else {
                    $changes[ $name ] = $stored;
                }
            }
        }
        if ( array() !== $errors ) {
            throw new \InvalidArgumentException( \implode( '; ', $errors ) . '. Nothing was changed.' );
        }
        return array(
            'sent'      => $sent,
            'changes'   => $changes,
            'unchanged' => $unchanged,
        );
    }

    public static function write( array $changes, array $sent ): void {
        $settings = self::settings();
        $options  = self::options();
        $new      = $options->get_all_raw();
        $new      = \is_array( $new ) ? $new : array();
        foreach ( $changes as $name => $value ) {
            $setting = $settings[ $name ];
            if ( ! isset( $new[ $setting['storage_group'] ] ) || ! \is_array( $new[ $setting['storage_group'] ] ) ) {
                $new[ $setting['storage_group'] ] = array();
            }
            $new[ $setting['storage_group'] ][ $setting['key'] ] = \is_string( $value ) && 'email' !== $setting['type'] ? \wp_slash( $value ) : $value;
        }
        foreach ( $new as $group => $keys ) {
            if ( \in_array( $group, array( 'mail', 'general' ), true ) || ! \is_array( $keys ) ) {
                continue;
            }
            foreach ( \array_keys( $keys ) as $key ) {
                if ( $options->is_const_defined( (string) $group, (string) $key ) ) {
                    $new[ $group ][ $key ] = '';
                }
            }
        }
        $encrypt = self::password_encryption( $new, $options );
        if ( null === $encrypt ) {
            $options->set( $new );
        } else {
            \add_filter( 'wp_mail_smtp_options_set', $encrypt, PHP_INT_MAX );
            try {
                $options->set( $new );
            } finally {
                \remove_filter( 'wp_mail_smtp_options_set', $encrypt, PHP_INT_MAX );
            }
        }

        $differs = array();
        foreach ( $sent as $name => $value ) {
            $setting = $settings[ $name ];
            if ( ! self::same( $value, $options->get( $setting['storage_group'], $setting['key'] ) ) ) {
                $differs[] = $name;
            }
        }
        if ( array() !== $differs ) {
            throw new \RuntimeException( \sprintf(
                'WP Mail SMTP holds %s differently from the value sent (a filter on its settings changed it); the other settings sent were saved.%s',
                \implode( ', ', $differs ),
                \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_mailsmtp_get_settings' ) ? ' Read them with wp_mailsmtp_get_settings.' : ''
            ) );
        }
    }

    private static function options() {
        $class = self::STORE;
        return $class::init();
    }

    private static function too_old(): string {
        return \sprintf( 'This WP Mail SMTP is too old for these tools: they read its list of settings, added in WP Mail SMTP %s. Update WP Mail SMTP.', self::MINIMUM_VERSION );
    }

    private static function mailers( array $settings ): array {
        $mailers = array();
        foreach ( isset( $settings['mail.mailer'] ) ? $settings['mail.mailer']['enum'] : array() as $name ) {
            $mailers[ $name ] = array(
                'name'  => $name,
                'title' => self::mailer_title( $name, $settings ),
            );
        }
        return $mailers;
    }

    private static function mailer_name( string $stored, array $settings ): string {
        $map  = isset( $settings['mail.mailer'] ) ? $settings['mail.mailer']['storage_map'] : array();
        $name = \array_search( $stored, $map, true );
        return false === $name ? $stored : (string) $name;
    }

    private static function mailer_title( string $name, array $settings ): string {
        $map    = isset( $settings['mail.mailer'] ) ? $settings['mail.mailer']['storage_map'] : array();
        $stored = $map[ $name ] ?? $name;
        try {
            $core      = \wp_mail_smtp();
            $providers = \is_object( $core ) && \method_exists( $core, 'get_providers' ) ? $core->get_providers() : null;
            $provider  = \is_object( $providers ) && \method_exists( $providers, 'get_options' ) ? $providers->get_options( $stored ) : null;
            $title     = \is_object( $provider ) && \method_exists( $provider, 'get_title' ) ? $provider->get_title() : '';
        } catch ( \Throwable $e ) {
            $title = '';
        }
        return \is_string( $title ) && '' !== $title ? $title : $name;
    }

    private static function row( string $name, array $setting, $options, array $settings ): array {
        $value = $options->get( $setting['storage_group'], $setting['key'] );
        $row   = array();
        if ( $setting['secret'] ) {
            $row['set'] = \is_string( $value ) ? '' !== $value : ! empty( $value );
        } elseif ( 'mail.mailer' === $name ) {
            $row['value'] = self::mailer_name( (string) $value, $settings );
        } else {
            $row['value'] = self::shown( $setting['type'], $value );
        }
        if ( array() !== $setting['enum'] ) {
            $row['allowed'] = $setting['enum'];
        }
        $row['from_constant'] = (bool) $options->is_const_defined( $setting['storage_group'], $setting['key'] );
        return $row;
    }

    private static function shown( string $type, $value ) {
        if ( null === $value ) {
            return null;
        }
        if ( 'bool' === $type ) {
            return (bool) $value;
        }
        if ( 'int' === $type ) {
            return \is_numeric( $value ) ? (int) $value : null;
        }
        return \is_scalar( $value ) ? (string) $value : null;
    }

    private static function value_error( string $name, array $setting, $value ): ?string {
        if ( self::MASK === $value ) {
            return \sprintf( '%s is the mark the change history shows for a hidden value (%s), not a value: send the real value, or leave %s out to keep the one stored', $name, self::MASK, $name );
        }
        $not = $setting['secret'] ? '' : ', not ' . self::quoted( $value );
        switch ( $setting['type'] ) {
            case 'bool':
                return \is_bool( $value ) ? null : $name . ' must be true or false' . $not;
            case 'int':
                $number = self::integer( $value );
                if ( null === $number ) {
                    return $name . ' must be a whole number' . $not;
                }
                return 'port' === $setting['key'] && ( $number < 1 || $number > 65535 ) ? \sprintf( '%s must be a port number from 1 to 65535, not %d', $name, $number ) : null;
            case 'enum':
                return \is_string( $value ) && \in_array( $value, $setting['enum'], true ) ? null : \sprintf( '%s must be one of %s', $name, \implode( ', ', $setting['enum'] ) ) . $not;
        }
        if ( ! \is_string( $value ) ) {
            return $name . ' must be text' . $not;
        }
        if ( 'email' === $setting['type'] && ( ! \is_email( $value ) || false === \filter_var( $value, FILTER_VALIDATE_EMAIL ) || \sanitize_email( $value ) !== $value ) ) {
            return $name . ' must be an email address' . $not;
        }
        $kept = 'smtp' === $setting['storage_group'] && 'pass' === $setting['key'] ? \trim( $value ) : \sanitize_text_field( $value );
        if ( $kept !== $value ) {
            return $setting['secret']
                ? \sprintf( '%s would not be stored as sent: WP Mail SMTP removes spaces at its ends, line breaks or tags from it', $name )
                : \sprintf( '%s would be stored as %s, not as sent: WP Mail SMTP removes spaces at its ends, line breaks and tags from text', $name, self::quoted( $kept ) );
        }
        return null;
    }

    private static function integer( $value ): ?int {
        if ( \is_int( $value ) ) {
            return $value;
        }
        return \is_string( $value ) && 1 === \preg_match( '/^[0-9]{1,9}$/', $value ) ? (int) $value : null;
    }

    private static function stored_value( array $setting, $value ) {
        return \is_string( $value ) && isset( $setting['storage_map'][ $value ] ) ? $setting['storage_map'][ $value ] : $value;
    }

    private static function same( $sent, $stored ): bool {
        if ( \is_bool( $sent ) ) {
            return \is_scalar( $stored ) && $sent === (bool) $stored;
        }
        if ( \is_int( $sent ) ) {
            return \is_numeric( $stored ) && (float) $sent === (float) $stored;
        }
        return \is_scalar( $stored ) && $sent === (string) $stored;
    }

    private static function quoted( $value ): string {
        if ( \is_string( $value ) ) {
            return '"' . $value . '"';
        }
        if ( \is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        return \is_scalar( $value ) ? (string) $value : ( \is_array( $value ) ? 'a list or object' : 'null' );
    }

    private static function names_in( string $group, array $settings ): string {
        $keys = array();
        foreach ( $settings as $name => $setting ) {
            if ( $setting['group'] === $group ) {
                $keys[] = \substr( $name, \strlen( $group ) + 1 );
            }
        }
        return array() === $keys ? 'none' : \implode( ', ', $keys );
    }

    private static function password_encryption( array $new, $options ) {
        $pass = $new['smtp']['pass'] ?? '';
        if ( ! \is_string( $pass ) || '' === $pass || $options->is_const_defined( 'smtp', 'pass' ) ) {
            return null;
        }
        $encrypted = array();
        try {
            if ( ! \class_exists( '\\WPMailSMTP\\Helpers\\Crypto' ) || ! \method_exists( '\\WPMailSMTP\\Helpers\\Crypto', 'encrypt' ) ) {
                throw new \RuntimeException( 'no Crypto::encrypt()' );
            }
            foreach ( \array_unique( array( $pass, \trim( $pass ) ) ) as $plain ) {
                if ( '' !== $plain ) {
                    $encrypted[ $plain ] = \WPMailSMTP\Helpers\Crypto::encrypt( $plain );
                }
            }
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( 'WP Mail SMTP could not encrypt the stored SMTP password, which it would otherwise keep in clear while another mailer is selected. Nothing was changed.' );
        }
        return static function ( $stored ) use ( $encrypted ) {
            if ( ! \is_array( $stored ) || ! isset( $stored['smtp'] ) || ! \is_array( $stored['smtp'] ) ) {
                return $stored;
            }
            $value = $stored['smtp']['pass'] ?? null;
            if ( \is_string( $value ) && isset( $encrypted[ $value ] ) ) {
                $stored['smtp']['pass'] = $encrypted[ $value ];
            }
            return $stored;
        };
    }

    private static function is_pro(): bool {
        $core = \wp_mail_smtp();
        return \is_object( $core ) && \method_exists( $core, 'is_pro' ) && (bool) $core->is_pro();
    }

    private static function mailer_configured(): bool {
        try {
            $core       = \wp_mail_smtp();
            $connection = $core->get_connections_manager()->get_primary_connection();
            if ( \in_array( $connection->get_mailer_slug(), array( '', 'mail' ), true ) ) {
                return false;
            }
            $mailer = $connection->get_mailer();
            return ! empty( $mailer ) && (bool) $mailer->is_mailer_complete();
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private static function setup_state(): array {
        $configured = self::mailer_configured();
        return array(
            'mailer_configured' => $configured,
            'test_email_sent'   => self::test_email_sent( $configured ),
            'domain_check'      => self::domain_check(),
            'wizard'            => self::wizard(),
            'constants_enabled' => (bool) self::options()->is_const_enabled(),
        );
    }

    private static function test_email_sent( bool $configured ): ?bool {
        try {
            $core = \wp_mail_smtp();
            if ( ! \method_exists( $core, 'get_setup_checklist' ) ) {
                return null;
            }
            $checklist = $core->get_setup_checklist();
            if ( ! \is_object( $checklist ) || ! \method_exists( $checklist, 'get_state' ) ) {
                return null;
            }
            return $configured && (bool) $checklist->get_state()->is_test_email_sent();
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    private static function domain_check(): ?string {
        if ( ! \class_exists( '\\WPMailSMTP\\DomainCheckState' ) || ! \method_exists( '\\WPMailSMTP\\DomainCheckState', 'get_state' ) ) {
            return null;
        }
        $state = \WPMailSMTP\DomainCheckState::get_state();
        return \is_string( $state ) ? $state : null;
    }

    private static function wizard(): ?array {
        if ( ! \class_exists( '\\WPMailSMTP\\Admin\\SetupWizard\\Stats' ) || ! \method_exists( '\\WPMailSMTP\\Admin\\SetupWizard\\Stats', 'get' ) ) {
            return null;
        }
        $stats     = (array) \WPMailSMTP\Admin\SetupWizard\Stats::get();
        $completed = isset( $stats['completed_time'] ) ? (int) $stats['completed_time'] : 0;
        return array(
            'launched'     => ! empty( $stats['launched_time'] ),
            'completed'    => $completed > 0,
            'successful'   => ! empty( $stats['was_successful'] ),
            'completed_at' => $completed > 0 ? \gmdate( 'c', $completed ) : null,
        );
    }
}
