<?php
namespace Easy_MCP_AI\Cli_Router\Handlers;

use Easy_MCP_AI\Cli_Router\Command_Handler;
use Easy_MCP_AI\Cli_Router\Context;
use Easy_MCP_AI\Cli_Router\Fetchers;
use Easy_MCP_AI\Cli_Router\Invocation;
use Easy_MCP_AI\Cli_Router\Read_Value;
use Easy_MCP_AI\Cli_Router\Values;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Theme_Auto_Updates_Handler extends Command_Handler {

    const SITE_OPTION = 'auto_update_themes';

    const REFUSE_FILE_MODS = 'File modifications are disabled on this site (DISALLOW_FILE_MODS in wp-config.php, or a file_mod_allowed filter), so WordPress updates no theme automatically and offers no auto-update setting, in wp-admin or here.';
    const REFUSE_TYPE_OFF  = 'Automatic updates for themes are turned off on this site (the auto_update_theme filter, or AUTOMATIC_UPDATER_DISABLED, makes wp_is_auto_update_enabled_for_type( \'theme\' ) false), so WordPress offers no auto-update toggle on Appearance › Themes and wp_cli_run does not change the list either (plan Q15).';

    const DISABLE_KEPT = 'Auto-updates were not disabled: the stored auto_update_themes option still lists them. A plugin or theme may have stopped the write. Themes: %s';

    const DISABLE_PART = 'Auto-updates were disabled only in part: auto_update_themes changed but still lists some. A plugin or theme may have stopped the rest. Still listed: %s';

    const DISABLE_UNKNOWN = 'Auto-updates may not have been disabled: a database error stopped the auto_update_themes option being read back, so whether it changed is not known. Themes: %s';

    const ENABLE_KEPT = 'Auto-updates were not enabled: the stored auto_update_themes option does not list them. A plugin or theme may have stopped the write. Themes: %s';

    const ENABLE_PART = 'Auto-updates were enabled only in part: auto_update_themes changed but does not list some. A plugin or theme may have stopped the rest. Not listed: %s';

    const BEFORE_UNREAD = 'Nothing was written: a database error stopped the auto_update_themes option being read before the write, so its state is not known. Themes: %s';

    const ENABLE_UNKNOWN = 'Auto-updates may not have been enabled: a database error stopped the auto_update_themes option being read back, so whether it changed is not known. Themes: %s';

    const NOT_CAPTURED_SINGLE  = 'The auto_update_themes option is recorded by change history only while it records every option (not in allowlist mode); wp_history_apply cannot restore it.';
    const NOT_CAPTURED_NETWORK = 'The auto_update_themes network option (wp_sitemeta) is recorded by change history only while its meta capture setting is on (the default), and a deleted network option is recorded without its previous value; wp_history_apply cannot restore it.';

    public function preflight( Invocation $inv, Context $ctx ): void {
        if ( ! Theme_Handler::file_mods_allowed() ) {
            $ctx->out->error( self::REFUSE_FILE_MODS );
        }
        $ctx->require_cap( 'update_themes' );
    }

    public function enable( Invocation $inv, Context $ctx ): void {
        $this->refuse_when_type_off( $ctx );
        $all           = (bool) Read_Value::flag( $inv->assoc, 'all', false );
        $disabled_only = (bool) Read_Value::flag( $inv->assoc, 'disabled-only', false );

        $args = Theme_Handler::check_optional_args_and_all( array_values( $inv->args ), $all, $ctx );
        if ( ! $args ) {
            return;
        }

        $themes       = Fetchers::of( 'theme' )->get_many( $args, $ctx->out );
        $auto_updates = self::stored_list();

        $count     = 0;
        $requested = array();
        foreach ( $themes as $theme ) {
            $stylesheet = (string) $theme->get_stylesheet();
            $enabled    = in_array( $stylesheet, $auto_updates, true );
            if ( $disabled_only && $enabled ) {
                continue;
            }
            ++$count;
            $requested[] = $stylesheet;
            if ( $enabled ) {
                $ctx->out->warning( "Auto-updates already enabled for theme {$stylesheet}." );
            } else {
                $auto_updates[] = $stylesheet;
            }
        }

        if ( 0 === $count ) {
            $ctx->out->error( 'No themes provided to enable auto-updates for.' );
        }

        $ctx->not_captured( $this->not_captured_phrase() );
        $before = \Easy_MCP_AI\Tools\Delete_Outcome::stored_option( self::SITE_OPTION, true );
        if ( null === $before ) {
            $ctx->out->error( \Easy_MCP_AI\MCP\Refusal_Text::fit( self::BEFORE_UNREAD, array( implode( ', ', $requested ) ) ) );
        }
        Theme_Handler::wp( 'update_site_option', array( self::SITE_OPTION, $auto_updates ) );
        $after     = self::require_listed( $requested, $before, $ctx );
        $earlier   = self::listed( $before );
        $successes = count( array_diff( array_unique( $requested ), $earlier ) );
        if ( $before !== $after ) {
            $ctx->mark_changed();
        }

        Values::report_batch( $ctx->out, 'theme auto-update', 'enable', $count, $successes, $count - $successes );
    }

    public function disable( Invocation $inv, Context $ctx ): void {
        $this->refuse_when_type_off( $ctx );
        $all          = (bool) Read_Value::flag( $inv->assoc, 'all', false );
        $enabled_only = (bool) Read_Value::flag( $inv->assoc, 'enabled-only', false );

        $args = Theme_Handler::check_optional_args_and_all( array_values( $inv->args ), $all, $ctx );
        if ( ! $args ) {
            return;
        }

        $themes       = Fetchers::of( 'theme' )->get_many( $args, $ctx->out );
        $auto_updates = self::stored_list();

        $count    = 0;
        $disabled = array();
        $not_read = array();
        foreach ( $themes as $theme ) {
            $stylesheet = (string) $theme->get_stylesheet();
            $enabled    = in_array( $stylesheet, $auto_updates, true );
            if ( $enabled_only && ! $enabled ) {
                continue;
            }
            ++$count;
            if ( ! $enabled ) {
                $ctx->out->warning( "Auto-updates already disabled for theme {$stylesheet}." );
                $not_read[] = $stylesheet;
            } else {
                $auto_updates = array_diff( $auto_updates, array( $stylesheet ) );
                $disabled[]   = $stylesheet;
            }
        }

        if ( 0 === $count ) {
            $ctx->out->error( 'No themes provided to disable auto-updates for.' );
        }

        $ctx->not_captured( $this->not_captured_phrase() );
        $before = \Easy_MCP_AI\Tools\Delete_Outcome::stored_option( self::SITE_OPTION, true );
        if ( null === $before ) {
            $ctx->out->error( \Easy_MCP_AI\MCP\Refusal_Text::fit( self::BEFORE_UNREAD, array( implode( ', ', array_merge( $disabled, $not_read ) ) ) ) );
        }
        if ( count( $auto_updates ) > 0 ) {
            Theme_Handler::wp( 'update_site_option', array( self::SITE_OPTION, $auto_updates ) );
        } else {
            Theme_Handler::wp( 'delete_site_option', array( self::SITE_OPTION ) );
        }
        $earlier   = self::listed( $before );
        $hidden    = array_values( array_intersect( $not_read, $earlier ) );
        $after     = self::require_disabled( array_merge( $disabled, $hidden ), $before, $ctx );
        $successes = count( array_intersect( array_unique( array_merge( $disabled, $not_read ) ), $earlier ) );
        if ( $before !== $after ) {
            $ctx->mark_changed();
        }

        Values::report_batch( $ctx->out, 'theme auto-update', 'disable', $count, $successes, $count - $successes );
    }

    public function status( Invocation $inv, Context $ctx ): void {
        $all           = (bool) Read_Value::flag( $inv->assoc, 'all', false );
        $enabled_only  = (bool) Read_Value::flag( $inv->assoc, 'enabled-only', false );
        $disabled_only = (bool) Read_Value::flag( $inv->assoc, 'disabled-only', false );

        if ( $enabled_only && $disabled_only ) {
            $ctx->out->error( '--enabled-only and --disabled-only are mutually exclusive and cannot be used at the same time.' );
        }

        $args = Theme_Handler::check_optional_args_and_all( array_values( $inv->args ), $all, $ctx );
        if ( ! $args ) {
            return;
        }

        $themes       = Fetchers::of( 'theme' )->get_many( $args, $ctx->out );
        $auto_updates = self::stored_list();

        $results = self::status_rows( $themes, $auto_updates, $enabled_only, $disabled_only );

        $assoc = $inv->assoc;
        $this->formatter( $ctx, $assoc, array( 'name', 'status' ), 'theme' )->display_items( $results );
    }

    private static function status_rows( array $themes, array $auto_updates, bool $enabled_only, bool $disabled_only ): array {
        $results = array();
        foreach ( $themes as $theme ) {
            $stylesheet = (string) $theme->get_stylesheet();
            $enabled    = in_array( $stylesheet, $auto_updates, true );
            if ( $enabled_only && ! $enabled ) {
                continue;
            }
            if ( $disabled_only && $enabled ) {
                continue;
            }
            $results[] = array(
                'name'   => $stylesheet,
                'status' => $enabled ? 'enabled' : 'disabled',
            );
        }
        return $results;
    }

    public function describe( Invocation $inv ): array {
        $enable = 'enable' === (string) $inv->leaf['name'];
        $names  = array_values( array_map( 'strval', $inv->args ) );
        if ( Read_Value::flag( $inv->assoc, 'all', false ) ) {
            $names = array();
            foreach ( array_keys( (array) Theme_Handler::wp( 'themes' ) ) as $stylesheet ) {
                $names[] = basename( (string) $stylesheet );
            }
        }
        $auto_updates = self::stored_list();
        $lines        = array();
        foreach ( $names as $name ) {
            $lines[] = self::describe_line( $name, in_array( $name, $auto_updates, true ), $enable );
        }
        return $lines ? $lines : array( 'No theme named → nothing changes' );
    }

    private static function describe_line( string $name, bool $on, bool $enable ): string {
        if ( $enable ) {
            return sprintf( "Theme '%s': auto-updates %s → %s", $name, $on ? 'on' : 'off', $on ? 'on (no change; WP-CLI counts it as a failure)' : 'on' );
        }
        return sprintf( "Theme '%s': auto-updates %s → %s", $name, $on ? 'on' : 'off', $on ? 'off' : 'off (no change; WP-CLI counts it as a failure)' );
    }

    private static function require_disabled( array $disabled, ?array $before, Context $ctx ): array {
        $row = \Easy_MCP_AI\Tools\Delete_Outcome::stored_option( self::SITE_OPTION, true );
        if ( null === $row ) {
            $ctx->not_known( \Easy_MCP_AI\MCP\Refusal_Text::fit( self::DISABLE_UNKNOWN, array( implode( ', ', $disabled ) ) ) );
        }
        $kept = $row[0] && is_array( $row[1] ) ? array_values( array_intersect( $disabled, $row[1] ) ) : array();
        if ( $kept ) {
            if ( null !== $before && $before !== $row ) {
                $ctx->mark_changed();
                $listed = $before[0] && is_array( $before[1] ) ? $before[1] : array();
                if ( array_diff( array_intersect( $disabled, $listed ), $kept ) ) {
                    $ctx->out->error( \Easy_MCP_AI\MCP\Refusal_Text::fit( self::DISABLE_PART, array( implode( ', ', $kept ) ) ) );
                }
            }
            $ctx->out->error( \Easy_MCP_AI\MCP\Refusal_Text::fit( self::DISABLE_KEPT, array( implode( ', ', $kept ) ) ) );
        }
        return $row;
    }

    private static function require_listed( array $enabled, ?array $before, Context $ctx ): array {
        $row = \Easy_MCP_AI\Tools\Delete_Outcome::stored_option( self::SITE_OPTION, true );
        if ( null === $row ) {
            $ctx->not_known( \Easy_MCP_AI\MCP\Refusal_Text::fit( self::ENABLE_UNKNOWN, array( implode( ', ', $enabled ) ) ) );
        }
        $listed  = $row[0] && is_array( $row[1] ) ? $row[1] : array();
        $missing = array_values( array_diff( $enabled, $listed ) );
        if ( $missing ) {
            $earlier = null !== $before && $before[0] && is_array( $before[1] ) ? $before[1] : array();
            if ( null !== $before && array_diff( array_intersect( $enabled, $listed ), $earlier ) ) {
                $ctx->mark_changed();
                $ctx->out->error( \Easy_MCP_AI\MCP\Refusal_Text::fit( self::ENABLE_PART, array( implode( ', ', $missing ) ) ) );
            }
            if ( null !== $before && $before !== $row ) {
                $ctx->mark_changed();
            }
            $ctx->out->error( \Easy_MCP_AI\MCP\Refusal_Text::fit( self::ENABLE_KEPT, array( implode( ', ', $missing ) ) ) );
        }
        return $row;
    }

    private static function listed( ?array $row ): array {
        return null !== $row && $row[0] && is_array( $row[1] ) ? array_values( array_map( 'strval', $row[1] ) ) : array();
    }

    private static function stored_list(): array {
        $auto_updates = Theme_Handler::wp( 'site_option', array( self::SITE_OPTION ) );
        return is_array( $auto_updates ) ? $auto_updates : array();
    }

    private function refuse_when_type_off( Context $ctx ): void {
        if ( ! Theme_Handler::wp( 'auto_update_enabled' ) ) {
            $ctx->out->error( self::REFUSE_TYPE_OFF );
        }
    }

    private function not_captured_phrase(): string {
        return Theme_Handler::wp( 'is_multisite' ) ? self::NOT_CAPTURED_NETWORK : self::NOT_CAPTURED_SINGLE;
    }
}
