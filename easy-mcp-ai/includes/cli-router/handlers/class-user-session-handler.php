<?php
namespace Easy_MCP_AI\Cli_Router\Handlers;

use Easy_MCP_AI\Cli_Router\Command_Handler;
use Easy_MCP_AI\Cli_Router\Context;
use Easy_MCP_AI\Cli_Router\Fetchers;
use Easy_MCP_AI\Cli_Router\Formatter;
use Easy_MCP_AI\Cli_Router\Invocation;
use Easy_MCP_AI\Cli_Router\Output;
use Easy_MCP_AI\Cli_Router\Read_Value;
use Easy_MCP_AI\Cli_Router\Redactor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class User_Session_Handler extends Command_Handler {

    const FIELDS = array( 'token', 'login_time', 'expiration_time', 'ip', 'ua' );

    const NOT_CAPTURED = 'Login sessions are never recorded by change history, so a destroyed session cannot be restored.';

    const MSG_CANNOT_EDIT = 'Sorry, you are not allowed to edit this user.';

    const MSG_SESSION_KEPT     = 'WordPress did not destroy the session: it is still stored. A plugin or theme on this site may have stopped the write.';
    const MSG_SESSIONS_KEPT    = 'WordPress did not destroy every session: %d still stored. A plugin or theme on this site may have stopped the write.';
    const MSG_SESSIONS_PART    = 'WordPress destroyed only some of the sessions: %1$d destroyed, %2$d still stored. A plugin or theme on this site may have stopped the write.';
    const MSG_SESSION_UNREAD   = 'WordPress answered that the session was destroyed, but a database error stopped the sessions being read back, so whether it was destroyed is not known.';
    const MSG_SESSIONS_UNREAD  = 'WordPress answered that the sessions were destroyed, but a database error stopped them being read back, so whether they were destroyed is not known.';

    public function list_( Invocation $inv, Context $ctx ): void {
        list( , $manager ) = $this->manager( $inv, $ctx );
        $assoc_args        = $inv->assoc;
        $shown             = ! empty( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) : self::FIELDS;
        $formatter         = new Formatter( $ctx->out, $assoc_args, self::FIELDS );
        $sessions          = self::get_all_sessions( $manager );

        if ( 'ids' === $formatter->format ) {
            if ( $sessions ) {
                $ctx->redacted( 'token' );
            }
            $ctx->out->out( implode( ' ', array_fill( 0, count( $sessions ), Redactor::MASK ) ) );
            return;
        }
        if ( $sessions && in_array( 'token', $shown, true ) ) {
            $ctx->redacted( 'token' );
        }
        foreach ( $sessions as $token => $session ) {
            $sessions[ $token ]['token'] = Redactor::MASK;
        }
        $formatter->display_items( $sessions );
    }

    public function destroy( Invocation $inv, Context $ctx ): void {
        list( $user, $manager ) = $this->manager( $inv, $ctx );
        $token                  = isset( $inv->args[1] ) ? $inv->args[1] : null;
        $all                    = Read_Value::flag( $inv->assoc, 'all', false );

        if ( $token && $all ) {
            $ctx->out->error( 'The --all flag cannot be specified along with a session token.' );
        }
        $ctx->not_captured( self::NOT_CAPTURED );

        if ( $all ) {
            self::destroy_all( (int) $user->ID, $manager, $ctx );
            return;
        }

        $sessions = self::get_all_sessions( $manager );

        if ( ! $token ) {
            $token = self::latest_token( $sessions, $ctx );
        }

        if ( null === $token || ! isset( $sessions[ $token ] ) ) {
            $ctx->out->error( 'Session not found.' );
        }

        self::destroy_session( $manager, (string) $token );
        $stored = self::stored_sessions( (int) $user->ID, $manager );
        if ( null === $stored ) {
            $ctx->not_known( self::MSG_SESSION_UNREAD );
        }
        if ( isset( $stored[ $token ] ) ) {
            $ctx->out->error( self::MSG_SESSION_KEPT );
        }
        $ctx->mark_changed();
        $remaining = count( $manager->get_all() );

        $ctx->out->success( "Destroyed session. {$remaining} remaining." );
    }

    private static function destroy_all( int $user_id, \WP_Session_Tokens $manager, Context $ctx ): void {
        $count = count( $manager->get_all() );
        $held  = self::stored_sessions( $user_id, $manager );
        $core      = 'WP_User_Meta_Session_Tokens' === get_class( $manager );
        $row_held  = $core ? User_Handler::stored_user_meta( $user_id, 'session_tokens' ) : null;
        $manager->destroy_all();
        $after     = self::stored_sessions( $user_id, $manager );
        $row_after = $core ? User_Handler::stored_user_meta( $user_id, 'session_tokens' ) : null;
        if ( null === $after || ( $core && null === $row_after ) ) {
            $ctx->not_known( self::MSG_SESSIONS_UNREAD );
        }
        $left = count( $after );
        if ( $left > 0 ) {
            if ( null !== $held && $left < count( $held ) ) {
                $ctx->mark_changed();
                $ctx->out->error( sprintf( self::MSG_SESSIONS_PART, count( $held ) - $left, $left ) );
            }
            $ctx->out->error( sprintf( self::MSG_SESSIONS_KEPT, $left ) );
        }
        if ( null !== $row_held && null !== $row_after ? $row_held !== $row_after : ( null === $held ? $count > 0 : $held !== $after ) ) {
            $ctx->mark_changed();
        }
        $ctx->out->success( 'Destroyed all sessions.' );
    }

    private static function stored_sessions( int $user_id, \WP_Session_Tokens $manager ): ?array {
        if ( 'WP_User_Meta_Session_Tokens' !== get_class( $manager ) ) {
            return (array) self::protected_method( $manager, 'get_sessions' )->invoke( $manager );
        }
        $row = User_Handler::stored_user_meta( $user_id, 'session_tokens' );
        if ( null === $row ) {
            return null;
        }
        return is_array( $row[1] ) ? $row[1] : array();
    }

    private static function latest_token( array $sessions, Context $ctx ): ?string {
        if ( empty( $sessions ) ) {
            $ctx->out->success( 'No sessions to destroy.' );
            return null;
        }
        $last = end( $sessions );
        return $last['token'];
    }

    public function describe_destroy( Invocation $inv ): array {
        $user = Fetchers::user( (string) ( isset( $inv->args[0] ) ? $inv->args[0] : '' ), new Output() );
        if ( ! $user ) {
            return array( sprintf( "User '%s': not found → nothing to destroy", isset( $inv->args[0] ) ? $inv->args[0] : '' ) );
        }
        $label    = sprintf( 'User %s (%d)', $user->user_login, (int) $user->ID );
        $sessions = self::get_all_sessions( \WP_Session_Tokens::get_instance( (int) $user->ID ) );
        if ( Read_Value::flag( $inv->assoc, 'all', false ) ) {
            return array( sprintf( '%s: %d sessions → destroy all', $label, count( $sessions ) ) );
        }
        if ( ! $sessions ) {
            return array( $label . ': no sessions → nothing to destroy' );
        }
        $token = isset( $inv->args[1] ) ? (string) $inv->args[1] : null;
        if ( null !== $token && ! isset( $sessions[ $token ] ) ) {
            return array( sprintf( '%s: session %s not found → nothing to destroy', $label, Redactor::MASK ) );
        }
        return array( self::describe_session( $label, $sessions, $token ) );
    }

    private static function describe_session( string $label, array $sessions, ?string $token ): string {
        if ( null === $token ) {
            $session = end( $sessions );
            $which   = 'the most recent session';
        } else {
            $session = $sessions[ $token ];
            $which   = 'session ' . Redactor::MASK;
        }
        return sprintf(
            '%s: %s of %d (logged in %s UTC from %s) → destroy',
            $label,
            $which,
            count( $sessions ),
            isset( $session['login_time'] ) ? $session['login_time'] : '?',
            isset( $session['ip'] ) && '' !== (string) $session['ip'] ? $session['ip'] : 'an unknown IP'
        );
    }

    private function manager( Invocation $inv, Context $ctx ): array {
        $user = Fetchers::of( 'user' )->get_check( isset( $inv->args[0] ) ? $inv->args[0] : '', $ctx->out );
        if ( ! \current_user_can( 'edit_user', (int) $user->ID ) ) {
            $ctx->out->error( self::MSG_CANNOT_EDIT );
        }
        return array( $user, \WP_Session_Tokens::get_instance( (int) $user->ID ) );
    }

    private static function get_all_sessions( \WP_Session_Tokens $manager ): array {
        $sessions = (array) self::protected_method( $manager, 'get_sessions' )->invoke( $manager );

        array_walk(
            $sessions,
            static function ( array &$session, string $token ): void {
                $session['token']           = $token;
                $session['login_time']      = date( 'Y-m-d H:i:s', $session['login'] ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- WP-CLI's own call.
                $session['expiration_time'] = date( 'Y-m-d H:i:s', $session['expiration'] ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- WP-CLI's own call.
            }
        );

        return $sessions;
    }

    private static function destroy_session( \WP_Session_Tokens $manager, string $token ): void {
        self::protected_method( $manager, 'update_session' )->invoke( $manager, $token, null );
    }

    private static function protected_method( \WP_Session_Tokens $manager, string $name ): \ReflectionMethod {
        $method = new \ReflectionMethod( $manager, $name );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }
        return $method;
    }
}
