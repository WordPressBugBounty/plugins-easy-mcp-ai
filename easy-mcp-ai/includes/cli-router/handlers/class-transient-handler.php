<?php
namespace Easy_MCP_AI\Cli_Router\Handlers;

use Easy_MCP_AI\Cli_Router\Command_Handler;
use Easy_MCP_AI\Cli_Router\Context;
use Easy_MCP_AI\Cli_Router\Invocation;
use Easy_MCP_AI\Cli_Router\Meta_Command;
use Easy_MCP_AI\Cli_Router\Read_Value;
use Easy_MCP_AI\Cli_Router\Self_Protection;
use Easy_MCP_AI\Cli_Router\Values;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Transient_Handler extends Command_Handler {

    const ROW_CAP = 1000;

    const NAMESPACE_REGEXP = '^_(site_)?transient_(timeout_)?_?easy[_-]mcp[_-]ai([_-]|$)';

    const OVER_ROW_CAP   = "wp_cli_run lists at most %1\$d transients in one 'transient list', and %2\$d match. Narrow it with --search or --exclude (wildcards * and ?), or ask for --format=count.";
    const KEY_WITH_BULK  = "wp_cli_run refuses <key> together with --%s: WP-CLI would ignore '%s' and delete every %stransient. Run `transient delete <key>` or `transient delete --%s` on its own.";
    const ALL_AND_EXPIRED = 'wp_cli_run refuses --all together with --expired: WP-CLI would ignore --expired and delete every transient. Run one of them.';
    const TIMEOUT_UNKNOWN = 'WordPress stored the value, but which expiry its filter gave this write is not known: a plugin left the hook stack unbalanced, so the %1$s expiry row could not be checked. %2$s';
    const SKIPPED        = 'Self-protection: wp_cli_run left out %1$d %2$s it never deletes: %3$s.';
    const NOTE_NO_PATH   = "WP-CLI exits 1 without output here: the transient '%s' has no value at that key path.";

    const NOT_RECORDED = 'Transients: change history does not record transients, by design.';
    const RAW_SQL      = 'Raw SQL: change history records these deletions only while database capture is on.';

    public function preflight_read( Invocation $inv, Context $ctx ): void {
        $this->guard( (string) $this->arg( $inv, 0 ), false, $ctx );
    }

    public function preflight_write( Invocation $inv, Context $ctx ): void {
        $this->guard( (string) $this->arg( $inv, 0 ), true, $ctx );
    }

    public function preflight_patch( Invocation $inv, Context $ctx ): void {
        $this->guard( (string) $this->arg( $inv, 1 ), true, $ctx );
    }

    public function preflight_delete( Invocation $inv, Context $ctx ): void {
        $key = ! empty( $inv->args ) ? $this->arg( $inv, 0 ) : null;
        self::refuse_bulk_mix( $key, true === Read_Value::flag( $inv->assoc, 'all' ), true === Read_Value::flag( $inv->assoc, 'expired' ), $ctx );
        if ( null !== $key && '' !== $key ) {
            $this->guard( $key, true, $ctx );
        }
    }

    private static function refuse_bulk_mix( ?string $key, bool $all, bool $expired, Context $ctx ): void {
        if ( $all && $expired ) {
            $ctx->out->error( self::ALL_AND_EXPIRED );
        }
        if ( null !== $key && ( $all || $expired ) ) {
            $flag = $all ? 'all' : 'expired';
            $ctx->out->error( sprintf( self::KEY_WITH_BULK, $flag, $key, $all ? '' : 'expired ', $flag ) );
        }
    }

    public function get( Invocation $inv, Context $ctx ): void {
        $key = (string) $this->arg( $inv, 0 );
        $this->guard( $key, false, $ctx );

        $value = $this->get_transient( $key, (bool) Read_Value::flag( $inv->assoc, 'network' ) );

        if ( false === $value ) {
            $ctx->out->warning( 'Transient with key "' . $key . '" is not set.' );
            return;
        }
        if ( Option_Handler::key_is_secret( $key ) ) {
            $value = Option_Handler::MASK;
            $ctx->redacted( "value of transient '{$key}'" );
        }

        Values::print_value( $ctx->out, $value, $inv->assoc );
    }

    public function set( Invocation $inv, Context $ctx ): void {
        $args       = $inv->args;
        $key        = (string) $this->arg( $inv, 0 );
        $value      = (string) $this->arg( $inv, 1 );
        $expiration = isset( $args[2] ) ? $args[2] : 0;
        $network    = (bool) Read_Value::flag( $inv->assoc, 'network' );
        $this->guard( $key, true, $ctx );

        $ctx->not_captured( self::NOT_RECORDED );
        $stored  = $this->stored_before( $ctx, $key, $network );
        $timeout = $this->timeout_before( $ctx, $key, $network );
        $from    = $this->now();
        if ( $this->set_observed( $key, $value, $expiration, $network ) ) {
            Option_Handler::refuse_unstored( $ctx, Option_Handler::write_refusal( $this->stored_value( $key, $network ), $stored, $value ), 'transient', "Transient: {$key}" );
            $this->refuse_unstored_timeout( $ctx, $key, $network, (int) $expiration, $from, $value );
            $ctx->mark_changed();
            $ctx->out->success( 'Transient added.' );
        } else {
            $this->refuse_false_write( $ctx, $key, $network, $stored, $timeout );
            $ctx->out->error( 'Transient could not be set.' );
        }
    }

    public function delete( Invocation $inv, Context $ctx ): void {
        $args = $inv->args;
        $key  = ( ! empty( $args ) ) ? (string) $this->arg( $inv, 0 ) : null;

        $all     = Read_Value::flag( $inv->assoc, 'all' );
        $expired = Read_Value::flag( $inv->assoc, 'expired' );
        $network = (bool) Read_Value::flag( $inv->assoc, 'network' );

        if ( true === $all ) {
            $this->delete_all( $network, $ctx );
            return;
        } elseif ( true === $expired ) {
            $this->delete_expired( $network, $ctx );
            return;
        }

        if ( ! $key ) {
            $ctx->out->error( 'Please specify transient key, or use --all or --expired.' );
        }
        $key = (string) $key;
        $this->guard( $key, true, $ctx );

        $ctx->not_captured( self::NOT_RECORDED );
        if ( $this->delete_transient( $key, $network ) ) {
            $ctx->mark_changed();
            $refusal = Option_Handler::delete_refusal( $this->stored_value( $key, $network ) );
            if ( null !== $refusal ) {
                $ctx->out->error( sprintf( $refusal, 'transient', "Transient: {$key}" ) );
            }
            $ctx->out->success( 'Transient deleted.' );
        } elseif ( $this->get_transient( $key, $network ) ) {
            $ctx->out->error( 'Transient was not deleted even though the transient appears to exist.' );
        } else {
            $ctx->out->warning( 'Transient was not deleted; however, the transient does not appear to exist.' );
            $ctx->fail_missing_target( 'Transient was not deleted; however, the transient does not appear to exist.' );
        }
    }

    public function type( Invocation $inv, Context $ctx ): void {
        if ( $this->ext_object_cache() ) {
            $message = 'Transients are saved to the object cache.';
        } else {
            $message = 'Transients are saved to the database.';
        }
        $ctx->out->line( $message );
    }

    public function list_( Invocation $inv, Context $ctx ): void {
        $assoc_args = $inv->assoc;
        $db         = $this->db();

        if ( $this->ext_object_cache() ) {
            $ctx->out->warning( 'Transients are stored in an external object cache, and this command only shows those stored in the database.' );
        }

        $fields = isset( $assoc_args['fields'] ) ? explode( ',', (string) $assoc_args['fields'] ) : array( 'name', 'value', 'expiration' );

        $pattern = isset( $assoc_args['search'] ) ? Option_Handler::like_pattern( $db, (string) $assoc_args['search'] ) : '%';
        $exclude = isset( $assoc_args['exclude'] ) ? Option_Handler::like_pattern( $db, (string) $assoc_args['exclude'] ) : '';

        $s     = $this->storage( (bool) Read_Value::flag( $assoc_args, 'network', false ) );
        $table = Option_Handler::ident( $s['table'] );
        $where = self::list_where( $db, $s, $pattern, $exclude );

        $this->check_list_names( $db, $table, $s['column'], $where, $assoc_args, $ctx );
        $results = $this->list_rows( $db, $table, $s, $where, $assoc_args, $ctx );

        $formatter = $this->formatter( $ctx, $assoc_args, $fields );
        $formatter->display_items( $results );
    }

    private static function list_where( object $db, array $s, string $pattern, string $exclude ): string {
        $column = $s['column'];
        $where  = $db->prepare(
            'WHERE `' . $column . '` LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a fixed column name.
            Option_Handler::esc_like( $db, $s['prefix'] ) . $pattern
        );
        $where .= $db->prepare(
            ' AND ' . $column . ' NOT LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a fixed column name.
            Option_Handler::esc_like( $db, $s['prefix'] . 'timeout_' ) . '%'
        );
        if ( $exclude ) {
            $where .= $db->prepare(
                ' AND ' . $column . ' NOT LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a fixed column name.
                Option_Handler::esc_like( $db, $s['prefix'] ) . $exclude
            );
        }
        if ( null !== $s['site_id'] ) {
            $where .= $db->prepare( ' AND `site_id` = %d', $s['site_id'] );
        }
        return $where;
    }

    private function check_list_names( object $db, string $table, string $column, string $where, array $assoc_args, Context $ctx ): void {
        $names                  = (array) $db->get_col( "SELECT `{$column}` FROM {$table} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every value in $where went through prepare(); the table name is quoted by ident().
        list( $kept, $omitted ) = Option_Handler::count_kept( $names );
        if ( $kept > self::ROW_CAP && 'count' !== Read_Value::flag( $assoc_args, 'format' ) ) {
            $ctx->out->error( sprintf( self::OVER_ROW_CAP, self::ROW_CAP, $kept ) );
        }
        if ( $omitted ) {
            $ctx->out->warning( Option_Handler::omitted_warning( $omitted, 'transient', 'transients' ) );
        }
    }

    private function list_rows( object $db, string $table, array $s, string $where, array $assoc_args, Context $ctx ): array {
        $network        = Read_Value::flag( $assoc_args, 'network', false );
        $unserialize    = Read_Value::flag( $assoc_args, 'unserialize', false );
        $human_readable = Read_Value::flag( $assoc_args, 'human-readable', false );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every value in $where went through prepare(); the table name is quoted by ident().
        $rows    = (array) $db->get_results( "SELECT `{$s['column']}` as `name`, `{$s['value']}` as `value` FROM {$table} {$where}" );
        $results = array();
        foreach ( $rows as $result ) {
            if ( null !== Option_Handler::omission( (string) $result->name, false ) ) {
                continue;
            }
            $result->name       = str_replace( array( '_site_transient_', '_transient_' ), '', $result->name );
            $result->expiration = $this->transient_expiration( $result->name, (bool) $network, (bool) $human_readable );

            if ( $unserialize ) {
                $result->value = $this->maybe_unserialize( $result->value );
            }
            if ( Option_Handler::key_is_secret( (string) $result->name ) ) {
                $result->value = Option_Handler::MASK;
                $ctx->redacted( "value of transient '{$result->name}'" );
            }
            $results[] = $result;
        }
        return $results;
    }

    public function pluck( Invocation $inv, Context $ctx ): void {
        $args = array_values( $inv->args );
        $key  = (string) $args[0];
        $this->guard( $key, false, $ctx );

        $value = $this->get_transient( $key, (bool) Read_Value::flag( $inv->assoc, 'network' ) );

        if ( false === $value ) {
            $ctx->out->warning( 'Transient with key "' . $key . '" is not set.' );
            return;
        }

        $key_path = Meta_Command::key_path( array_slice( $args, 1 ) );

        try {
            $value = Meta_Command::pluck_value( $value, $key_path );
        } catch ( \OutOfBoundsException $e ) {
            $ctx->note( sprintf( self::NOTE_NO_PATH, $key ) );
            $ctx->out->halt( 1 );
        }
        if ( Option_Handler::key_is_secret( $key ) ) {
            $value = Option_Handler::MASK;
            $ctx->redacted( "value of transient '{$key}'" );
        }

        Values::print_value( $ctx->out, $value, $inv->assoc );
    }

    public function patch( Invocation $inv, Context $ctx ): void {
        $args       = array_values( $inv->args );
        $action     = (string) $args[0];
        $key        = (string) $args[1];
        $expiration = (int) Read_Value::flag( $inv->assoc, 'expiration', 0 );
        $network    = (bool) Read_Value::flag( $inv->assoc, 'network' );
        $this->guard( $key, true, $ctx );

        $key_path = Meta_Command::key_path( array_slice( $args, 2 ) );

        if ( 'delete' === $action ) {
            $patch_value = null;
        } else {
            if ( count( $key_path ) > 1 ) {
                $patch_value = Read_Value::read( $ctx->out, array_pop( $key_path ), $inv->assoc );
            } else {
                $patch_value = null;
            }
            if ( null === $patch_value ) {
                $ctx->out->error( 'Please provide value to update.' );
            }
        }

        $old_value     = $this->get_transient( $key, $network );
        $current_value = $old_value;
        if ( is_object( $old_value ) ) {
            $current_value = clone $old_value;
        }

        try {
            $patched_value = Meta_Command::patch_value( $action, $current_value, $key_path, $patch_value );
        } catch ( \RuntimeException $e ) {
            $ctx->out->error( $e->getMessage() );
            return;
        }

        $ctx->not_captured( self::NOT_RECORDED );
        $named  = "Transient: {$key}";
        $stored = $this->stored_before( $ctx, $key, $network );
        if ( $patched_value === $old_value ) {
            Option_Handler::note_unless_held( $ctx, $patched_value, $stored[0] ? \maybe_unserialize( $stored[1] ) : false, 'transient', $named );
            $ctx->out->success( "Value passed for transient '$key' is unchanged." );
        } else {
            $timeout = $this->timeout_before( $ctx, $key, $network );
            $from    = $this->now();
            $success = $this->set_observed( $key, $patched_value, $expiration, $network );
            if ( $success ) {
                Option_Handler::refuse_unstored( $ctx, Option_Handler::path_refusal( $this->stored_value( $key, $network ), $stored, $action, $key_path, $patched_value ), 'transient', $named );
                $this->refuse_unstored_timeout( $ctx, $key, $network, $expiration, $from, $patched_value );
                $ctx->mark_changed();
                $ctx->out->success( "Updated transient '$key'." );
            } else {
                $this->refuse_false_write( $ctx, $key, $network, $stored, $timeout );
                $ctx->out->error( "Could not update transient '$key'." );
            }
        }
    }

    public function describe_set( Invocation $inv ): array {
        $key        = (string) $this->arg( $inv, 0 );
        $network    = (bool) Read_Value::flag( $inv->assoc, 'network' );
        $expiration = (int) ( isset( $inv->args[2] ) ? $inv->args[2] : 0 );
        return array(
            sprintf(
                "%s '%s': %s → %s, %s",
                $network ? 'Site transient' : 'Transient',
                $key,
                $this->preview_current( $key, $network ),
                Option_Handler::preview( $key, (string) $this->arg( $inv, 1 ) ),
                self::expiry_text( $expiration )
            ),
        );
    }

    public function describe_patch( Invocation $inv ): array {
        $args    = array_values( $inv->args );
        $action  = (string) $args[0];
        $key     = (string) $args[1];
        $network = (bool) Read_Value::flag( $inv->assoc, 'network' );
        $path    = array_slice( $args, 2 );
        $value   = 'delete' === $action ? null : ( count( $path ) > 1 ? array_pop( $path ) : null );
        if ( null !== $value && 'json' === Read_Value::flag( $inv->assoc, 'format' ) ) {
            $decoded = json_decode( (string) $value, true );
            $value   = null === $decoded ? $value : $decoded;
        }
        $effect = 'delete' === $action ? 'delete ' . implode( '.', $path ) : $action . ' ' . implode( '.', $path ) . ' = ' . Option_Handler::preview( $key, $value );
        return array(
            sprintf(
                "%s '%s': %s → %s, %s",
                $network ? 'Site transient' : 'Transient',
                $key,
                $this->preview_current( $key, $network ),
                $effect,
                self::expiry_text( (int) Read_Value::flag( $inv->assoc, 'expiration', 0 ) )
            ),
        );
    }

    public function describe_delete( Invocation $inv ): array {
        $network = (bool) Read_Value::flag( $inv->assoc, 'network' );
        $where   = $network ? ( \is_multisite() ? "the network's sitemeta table" : 'the options table (site transients)' ) : 'the options table';
        if ( true === Read_Value::flag( $inv->assoc, 'all' ) ) {
            return array( "Every transient stored in {$where}, except those wp_cli_run never deletes → delete (raw SQL)" );
        }
        if ( true === Read_Value::flag( $inv->assoc, 'expired' ) ) {
            return array( "Every expired transient stored in {$where}, except those wp_cli_run never deletes → delete (raw SQL)" );
        }
        $key = (string) $this->arg( $inv, 0 );
        return array( sprintf( "%s '%s': %s → delete", $network ? 'Site transient' : 'Transient', $key, $this->preview_current( $key, $network ) ) );
    }

    protected function get_transient( string $key, bool $network ) {
        return $network ? \get_site_transient( $key ) : \get_transient( $key );
    }

    private function set_observed( string $key, $value, $expiration, bool $network ): bool {
        $hook             = $network ? "expiration_of_site_transient_{$key}" : "expiration_of_transient_{$key}";
        $this->applied    = null;
        $this->observed   = false;
        $depth            = \Easy_MCP_AI\Tools\Delete_Outcome::hook_depth() + 1;
        $this->filtered   = false;
        $observer         = function ( $applied ) use ( $depth ) {
            $this->filtered = true;
            if ( ! $this->observed && \Easy_MCP_AI\Tools\Delete_Outcome::hook_depth() === $depth ) {
                $this->observed = true;
                $this->applied  = $applied;
            }
            return $applied;
        };
        \add_filter( $hook, $observer, PHP_INT_MAX, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- core's own filter, watched.
        try {
            return $this->set_transient( $key, $value, $expiration, $network );
        } finally {
            \remove_filter( $hook, $observer, PHP_INT_MAX );
        }
    }

    private $applied = null;

    private $observed = false;

    private $filtered = false;

    protected function effective_expiration( string $key, $value, int $expiration, bool $network ): int {
        return (int) \apply_filters( $network ? "expiration_of_site_transient_{$key}" : "expiration_of_transient_{$key}", $expiration, $value, $key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- core's own filter, read as set_transient() reads it.
    }

    protected function set_transient( string $key, $value, $expiration, bool $network ): bool {
        return (bool) ( $network ? \set_site_transient( $key, $value, $expiration ) : \set_transient( $key, $value, $expiration ) );
    }

    protected function delete_transient( string $key, bool $network ): bool {
        return (bool) ( $network ? \delete_site_transient( $key ) : \delete_transient( $key ) );
    }

    protected function ext_object_cache(): bool {
        return (bool) \wp_using_ext_object_cache();
    }

    protected function get_option( string $key ) {
        return \get_option( $key );
    }

    protected function get_site_option( string $key ) {
        return \get_site_option( $key );
    }

    protected function cache_get( string $key, string $group ) {
        return \wp_cache_get( $key, $group );
    }

    protected function cache_read( string $key, string $group ): array {
        $found = false;
        $value = \wp_cache_get( $key, $group, true, $found );
        return array( (bool) $found, $value );
    }

    protected function db(): object {
        global $wpdb;
        return $wpdb;
    }

    protected function now(): int {
        return time();
    }

    protected function current_network_id(): int {
        return function_exists( 'get_current_network_id' ) ? (int) \get_current_network_id() : 1;
    }

    protected function time_diff( int $from, int $to ): string {
        return (string) \human_time_diff( $from, $to );
    }

    protected function maybe_unserialize( $value ) {
        return \maybe_unserialize( $value );
    }

    private function guard( string $key, bool $write, Context $ctx ): void {
        $refusal = Self_Protection::transient( $key, $write );
        if ( null !== $refusal ) {
            $ctx->out->error( $refusal );
        }
    }

    private function arg( Invocation $inv, int $index ): string {
        $args = array_values( $inv->args );
        return isset( $args[ $index ] ) ? $args[ $index ] : '';
    }

    private function transient_expiration( string $name, bool $is_site_transient, bool $human_readable ) {
        if ( $is_site_transient ) {
            if ( \is_multisite() ) {
                $expiration = (int) $this->get_site_option( '_site_transient_timeout_' . $name );
            } else {
                $expiration = (int) $this->get_option( '_site_transient_timeout_' . $name );
            }
        } else {
            $expiration = (int) $this->get_option( '_transient_timeout_' . $name );
        }

        if ( 0 === $expiration ) {
            return $human_readable ? 'never expires' : 'false';
        }

        if ( ! $human_readable ) {
            return $expiration;
        }

        $now = $this->now();

        if ( $now > $expiration ) {
            return 'expired';
        }

        return $this->time_diff( $now, $expiration );
    }

    protected function stored_value( string $key, bool $network ): ?array {
        if ( $this->ext_object_cache() ) {
            list( $found, $value ) = $this->cache_read( $key, $network ? 'site-transient' : 'transient' );
            return $found ? array( true, Option_Handler::stored_text( $value ) ) : array( false, '' );
        }
        return $this->stored_row( ( $network ? '_site_transient_' : '_transient_' ) . $key, $network );
    }

    protected function stored_timeout( string $key, bool $network ): ?array {
        if ( $this->ext_object_cache() ) {
            return array( false, '' );
        }
        return $this->stored_row( ( $network ? '_site_transient_timeout_' : '_transient_timeout_' ) . $key, $network );
    }

    private function stored_row( string $name, bool $network ): ?array {
        $sitemeta = $network && \is_multisite();
        return Option_Handler::raw_row( $this->db(), $name, $sitemeta, $sitemeta ? $this->current_network_id() : 0 );
    }

    private function stored_before( Context $ctx, string $key, bool $network ): array {
        $before = $this->stored_value( $key, $network );
        if ( null === $before ) {
            $ctx->out->error( sprintf( Option_Handler::WRITE_UNBASED, 'transient', "Transient: {$key}" ) );
        }
        return $before;
    }

    private function timeout_before( Context $ctx, string $key, bool $network ): array {
        $before = $this->stored_timeout( $key, $network );
        if ( null === $before ) {
            $ctx->out->error( sprintf( Option_Handler::WRITE_UNBASED, 'transient', "Transient: {$key}" ) );
        }
        return $before;
    }

    private function refuse_unstored_timeout( Context $ctx, string $key, bool $network, int $expiration, int $from, $value ): void {
        if ( ! $this->observed && $this->filtered ) {
            Option_Handler::refuse_unstored( $ctx, array( self::TIMEOUT_UNKNOWN, true ), 'transient', "Transient: {$key}" );
        }
        $expiration = $this->observed ? (int) $this->applied : $this->effective_expiration( $key, $value, $expiration, $network );
        if ( 0 === $expiration || $this->ext_object_cache() ) {
            return;
        }
        $row = $this->stored_timeout( $key, $network );
        if ( null === $row ) {
            Option_Handler::refuse_unstored( $ctx, array( Option_Handler::WRITE_UNREAD, true ), 'transient', "Transient: {$key}" );
        }
        $to = $this->now();
        if ( $row[0] && 1 === preg_match( '/^-?\d+$/', $row[1] ) && (int) $row[1] >= $from + $expiration && (int) $row[1] <= $to + $expiration ) {
            return;
        }
        Option_Handler::refuse_unstored( $ctx, array( Option_Handler::WRITE_TIMEOUT, true ), 'transient', "Transient: {$key}" );
    }

    private function refuse_false_write( Context $ctx, string $key, bool $network, array $stored, array $timeout ): void {
        $named = "Transient: {$key}";
        Option_Handler::refuse_changed_after_false( $ctx, $this->stored_value( $key, $network ), $stored, 'transient', $named );
        $after = $this->stored_timeout( $key, $network );
        if ( null === $after ) {
            $ctx->not_known( sprintf( Option_Handler::FALSE_UNREAD, 'transient', $named ) );
        }
        if ( $after !== $timeout ) {
            $ctx->mark_changed();
        }
    }

    private function preview_current( string $key, bool $network ): string {
        if ( $this->ext_object_cache() ) {
            $value = $this->cache_get( $key, $network ? 'site-transient' : 'transient' );
        } elseif ( $network ) {
            $value = \is_multisite() ? $this->get_site_option( '_site_transient_' . $key ) : $this->get_option( '_site_transient_' . $key );
        } else {
            $value = $this->get_option( '_transient_' . $key );
        }
        return false === $value ? '(not set)' : Option_Handler::preview( $key, $value );
    }

    private static function expiry_text( int $expiration ): string {
        return 0 === $expiration ? 'no expiry set' : sprintf( 'expires %d seconds after the write', $expiration );
    }

    private function storage( bool $network ): array {
        $db = $this->db();
        if ( $network && \is_multisite() ) {
            return array(
                'table'   => (string) $db->sitemeta,
                'column'  => 'meta_key',
                'value'   => 'meta_value',
                'prefix'  => '_site_transient_',
                'offset'  => 17,
                'site_id' => $this->current_network_id(),
            );
        }
        return array(
            'table'   => (string) $db->options,
            'column'  => 'option_name',
            'value'   => 'option_value',
            'prefix'  => $network ? '_site_transient_' : '_transient_',
            'offset'  => $network ? 17 : 12,
            'site_id' => null,
        );
    }

    private static function network_scope( array $s, array $aliases = array( '' ) ): array {
        if ( null === $s['site_id'] ) {
            return array( '', array() );
        }
        $sql    = '';
        $values = array();
        foreach ( $aliases as $alias ) {
            $sql     .= ' AND ' . ( '' === $alias ? '' : $alias . '.' ) . '`site_id` = %d';
            $values[] = (int) $s['site_id'];
        }
        return array( $sql, $values );
    }

    private function protected_rows( array $s ): array {
        $db                       = $this->db();
        list( $scope, $in_scope ) = self::network_scope( $s );
        $names                    = (array) $db->get_col(
            $db->prepare(
                'SELECT `' . $s['column'] . '` FROM ' . Option_Handler::ident( $s['table'] ) . ' WHERE `' . $s['column'] . '` LIKE %s' . $scope, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name is quoted by ident(); the column and the network scope are fixed.
                array_merge( array( Option_Handler::esc_like( $db, $s['prefix'] ) . '%' ), $in_scope )
            )
        );
        $rows = array();
        foreach ( $names as $name ) {
            $category = Option_Handler::omission( (string) $name, true );
            if ( null !== $category ) {
                $rows[ (string) $name ] = $category;
            }
        }
        return $rows;
    }

    private static function exclusion( string $column_ref, array $protected ): array {
        $sql    = " AND {$column_ref} NOT REGEXP %s";
        $values = array( self::NAMESPACE_REGEXP );
        $others = array();
        foreach ( array_keys( $protected ) as $name ) {
            if ( ! preg_match( '#' . self::NAMESPACE_REGEXP . '#i', (string) $name ) ) {
                $others[] = (string) $name;
            }
        }
        if ( $others ) {
            $sql   .= " AND {$column_ref} NOT IN (" . implode( ',', array_fill( 0, count( $others ), '%s' ) ) . ')';
            $values = array_merge( $values, $others );
        }
        return array( $sql, $values );
    }

    private static function count_transients( array $rows, string $prefix ): array {
        $timeout = $prefix . 'timeout_';
        $values  = array();
        foreach ( array_keys( $rows ) as $name ) {
            if ( 0 !== stripos( (string) $name, $timeout ) ) {
                $values[ strtolower( (string) substr( (string) $name, strlen( $prefix ) ) ) ] = true;
            }
        }
        $counts = array();
        foreach ( $rows as $name => $category ) {
            $name = (string) $name;
            if ( 0 === stripos( $name, $timeout ) && isset( $values[ strtolower( (string) substr( $name, strlen( $timeout ) ) ) ] ) ) {
                continue;
            }
            $counts[ $category ] = ( $counts[ $category ] ?? 0 ) + 1;
        }
        return $counts;
    }

    private static function skipped_warning( array $counts ): string {
        $total = array_sum( $counts );
        return sprintf( self::SKIPPED, $total, 1 === $total ? 'transient' : 'transients', Option_Handler::omission_summary( $counts ) );
    }

    private function delete_all( bool $network, Context $ctx ): void {
        $db        = $this->db();
        $s         = $this->storage( $network );
        $table     = Option_Handler::ident( $s['table'] );
        $col       = $s['column'];
        $protected = $this->protected_rows( $s );

        $ctx->not_captured( self::NOT_RECORDED );
        $ctx->not_captured( self::RAW_SQL );

        $count                          = 0;
        list( $excl_a, $values_a )      = self::exclusion( "a.`{$col}`", $protected );
        list( $scope_ab, $in_scope_ab ) = self::network_scope( $s, array( 'a', 'b' ) );
        $deleted                        = $db->query(
            $db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is quoted by ident(); the column, the network scope and the exclusion's placeholders are fixed.
                "DELETE a, b FROM {$table} a, {$table} b
                    WHERE a.`{$col}` LIKE %s
                    AND a.`{$col}` NOT LIKE %s
                    AND b.`{$col}` = CONCAT( '{$s['prefix']}timeout_', SUBSTRING( a.`{$col}`, {$s['offset']} ) ){$scope_ab}{$excl_a}",
                array_merge(
                    array(
                        Option_Handler::esc_like( $db, $s['prefix'] ) . '%',
                        Option_Handler::esc_like( $db, $s['prefix'] . 'timeout_' ) . '%',
                    ),
                    $in_scope_ab,
                    $values_a
                )
            )
        );
        $count                   += (int) $deleted / 2;
        list( $excl, $values )    = self::exclusion( "`{$col}`", $protected );
        list( $scope, $in_scope ) = self::network_scope( $s );
        $count                   += (int) $db->query(
            $db->prepare(
                "DELETE FROM {$table} WHERE `{$col}` LIKE %s{$scope}{$excl}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is quoted by ident(); the column, the network scope and the exclusion's placeholders are fixed.
                array_merge( array( Option_Handler::esc_like( $db, $s['prefix'] ) . '%' ), $in_scope, $values )
            )
        );

        if ( $protected ) {
            $ctx->out->warning( self::skipped_warning( self::count_transients( $protected, $s['prefix'] ) ) );
        }
        if ( $count > 0 ) {
            $ctx->mark_changed();
            $ctx->out->success(
                sprintf(
                    '%d %s deleted from the database.',
                    $count,
                    1 === $count ? 'transient' : 'transients'
                )
            );
        } else {
            $ctx->out->success( 'No transients found.' );
        }

        if ( $this->ext_object_cache() ) {
            $ctx->out->warning( 'Transients are stored in an external object cache, and this command only deletes those stored in the database. You must flush the cache to delete all transients.' );
        }
    }

    private function delete_expired( bool $network, Context $ctx ): void {
        $db        = $this->db();
        $s         = $this->storage( $network );
        $table     = Option_Handler::ident( $s['table'] );
        $col       = $s['column'];
        $val       = $s['value'];
        $now       = $this->now();
        $protected = $this->protected_rows( $s );

        $expired_protected = $protected ? $this->expired_protected( $db, $s, $protected, $now ) : array();

        $ctx->not_captured( self::NOT_RECORDED );
        $ctx->not_captured( self::RAW_SQL );

        $count                          = 0;
        list( $excl_a, $values_a )      = self::exclusion( "a.`{$col}`", $protected );
        list( $scope_ab, $in_scope_ab ) = self::network_scope( $s, array( 'a', 'b' ) );
        $count                         += (int) $db->query(
            $db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is quoted by ident(); the columns, the network scope and the exclusion's placeholders are fixed.
                "DELETE a, b FROM {$table} a, {$table} b
                    WHERE a.`{$col}` LIKE %s
                    AND a.`{$col}` NOT LIKE %s
                    AND b.`{$col}` = CONCAT( '{$s['prefix']}timeout_', SUBSTRING( a.`{$col}`, {$s['offset']} ) )
                    AND b.`{$val}` < %d{$scope_ab}{$excl_a}",
                array_merge(
                    array(
                        Option_Handler::esc_like( $db, $s['prefix'] ) . '%',
                        Option_Handler::esc_like( $db, $s['prefix'] . 'timeout_' ) . '%',
                        $now,
                    ),
                    $in_scope_ab,
                    $values_a
                )
            )
        );

        $count = $count / 2;

        if ( $expired_protected ) {
            $ctx->out->warning( self::skipped_warning( self::count_transients( $expired_protected, $s['prefix'] ) ) );
        }
        if ( $count > 0 ) {
            $ctx->mark_changed();
            $ctx->out->success(
                sprintf(
                    '%d expired %s deleted from the database.',
                    $count,
                    1 === $count ? 'transient' : 'transients'
                )
            );
        } else {
            $ctx->out->success( 'No expired transients found.' );
        }

        if ( $this->ext_object_cache() ) {
            $ctx->out->warning( 'Transients are stored in an external object cache, and this command only deletes those stored in the database. You must flush the cache to delete all transients.' );
        }
    }

    private function expired_protected( object $db, array $s, array $protected, int $now ): array {
        $table                    = Option_Handler::ident( $s['table'] );
        $col                      = $s['column'];
        $val                      = $s['value'];
        list( $scope, $in_scope ) = self::network_scope( $s );
        $timeouts                 = (array) $db->get_results(
            $db->prepare(
                "SELECT `{$col}` AS `name`, `{$val}` AS `value` FROM {$table} WHERE `{$col}` LIKE %s{$scope}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is quoted by ident(); the columns and the network scope are fixed.
                array_merge( array( Option_Handler::esc_like( $db, $s['prefix'] . 'timeout_' ) . '%' ), $in_scope )
            )
        );
        $expiry = array();
        foreach ( $timeouts as $row ) {
            $expiry[ strtolower( (string) substr( (string) $row->name, strlen( $s['prefix'] . 'timeout_' ) ) ) ] = $row->value;
        }
        $expired_protected = array();
        foreach ( $protected as $name => $category ) {
            if ( 0 === stripos( (string) $name, $s['prefix'] . 'timeout_' ) ) {
                continue;
            }
            $base = strtolower( (string) substr( (string) $name, strlen( $s['prefix'] ) ) );
            if ( isset( $expiry[ $base ] ) && is_numeric( $expiry[ $base ] ) && $expiry[ $base ] < $now ) {
                $expired_protected[ (string) $name ] = $category;
            }
        }
        return $expired_protected;
    }
}
