<?php
namespace Easy_MCP_AI\Cli_Router;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Redactor {

    const MASK = '[REDACTED]';

    const OPAQUE_COMMANDS = array( 'db query', 'db cli', 'db export', 'db import', 'eval', 'eval-file', 'shell' );

    public static function may_be_opaque( string $command, int $offset ): bool {
        $before = (string) substr( $command, 0, $offset );
        try {
            $words = Shell_Lexer::split( $before );
            if ( $words && count( Shell_Lexer::split( $before . 'x' ) ) === count( $words ) ) {
                array_pop( $words );
            }
            $typed = self::typed_command_word( $words );
            if ( null === $typed || self::starts_opaque( $typed ) ) {
                return true;
            }
            $positional = Argv::parse( $words, Catalog::global_parameters() )['positional'];
        } catch ( Cli_Parse_Error $e ) {
            return true;
        } catch ( \RuntimeException $e ) {
            return true;
        }
        $positional = array_values( array_filter( array_map( 'strval', $positional ), static function ( string $word ): bool {
            return 'help' !== $word;
        } ) );
        return ! isset( $positional[0] ) || self::starts_opaque( $positional[0] );
    }

    private static function typed_command_word( array $words ): ?string {
        foreach ( $words as $i => $word ) {
            if ( ( 0 === $i && 'wp' === $word ) || 'help' === $word || 0 === strpos( $word, '-' ) ) {
                continue;
            }
            return 1 === preg_match( '/^[a-z][a-z0-9-]*\z/', $word ) ? $word : null;
        }
        return null;
    }

    private static function starts_opaque( string $word ): bool {
        $word = Argv::TOP_LEVEL_ALIASES[ $word ] ?? $word;
        foreach ( self::OPAQUE_COMMANDS as $opaque ) {
            if ( strtok( $opaque, ' ' ) === $word ) {
                return true;
            }
        }
        return false;
    }

    const SECRET_POSITIONALS = array(
        'user check-password'  => array( 1 ),
        'user session destroy' => array( 1 ),
    );

    const KEYED_VALUES = array(
        'option add'          => array( 0, 1 ),
        'option update'       => array( 0, 1 ),
        'option patch'        => array( 1, 2 ),
        'site option add'     => array( 0, 1 ),
        'site option update'  => array( 0, 1 ),
        'site option patch'   => array( 1, 2 ),
        'transient set'       => array( 0, 1 ),
        'transient patch'     => array( 1, 2 ),
        'theme mod set'       => array( 0, 1 ),
        'cache add'           => array( 0, 1 ),
        'cache set'           => array( 0, 1 ),
        'cache replace'       => array( 0, 1 ),
        'cache patch'         => array( 1, 2 ),
        'config set'          => array( 0, 1 ),
        'post meta add'       => array( 1, 2 ),
        'post meta update'    => array( 1, 2 ),
        'post meta delete'    => array( 1, 2 ),
        'post meta patch'     => array( 2, 3 ),
        'user meta add'       => array( 1, 2 ),
        'user meta update'    => array( 1, 2 ),
        'user meta delete'    => array( 1, 2 ),
        'user meta patch'     => array( 2, 3 ),
        'term meta add'       => array( 1, 2 ),
        'term meta update'    => array( 1, 2 ),
        'term meta delete'    => array( 1, 2 ),
        'term meta patch'     => array( 2, 3 ),
        'comment meta add'    => array( 1, 2 ),
        'comment meta update' => array( 1, 2 ),
        'comment meta delete' => array( 1, 2 ),
        'comment meta patch'  => array( 2, 3 ),
        'network meta add'    => array( 1, 2 ),
        'network meta update' => array( 1, 2 ),
        'network meta delete' => array( 1, 2 ),
        'network meta patch'  => array( 2, 3 ),
        'site meta add'       => array( 1, 2 ),
        'site meta update'    => array( 1, 2 ),
        'site meta delete'    => array( 1, 2 ),
        'site meta patch'     => array( 2, 3 ),
    );

    const URL_POSITIONALS = array(
        'media import'         => 0,
        'embed fetch'          => 0,
        'embed provider match' => 0,
        'theme install'        => 0,
        'plugin install'       => 0,
    );

    public static function readable_parts( string $command ): ?array {
        try {
            Shell_Lexer::split( $command );
            return null;
        } catch ( Cli_Parse_Error $e ) {
            unset( $e );
        }
        $parts = array();
        $rest  = $command;
        for ( $round = 0; '' !== trim( $rest ) && $round < 64; $round++ ) {
            $at = null;
            try {
                Shell_Lexer::split( $rest );
            } catch ( Cli_Parse_Error $e ) {
                $at = $e->get_offset();
                if ( null === $at || $at < 0 || $at > strlen( $rest ) ) {
                    break;
                }
            }
            $part = null === $at ? $rest : substr( $rest, 0, $at );
            if ( '' !== trim( $part ) ) {
                list( $masked, $names ) = self::mask_command( $part );
                if ( ! in_array( 'unparseable', $names, true ) ) {
                    $parts[] = array( $part, $masked );
                    if ( null !== $at && ! in_array( substr( $part, -1 ), array( '"', "'" ), true ) && self::cut_word_is_secret( $part ) ) {
                        $parts[] = array( substr( $rest, self::cut_word_start( $part ) ), self::MASK );
                        break;
                    }
                }
            }
            if ( null === $at ) {
                break;
            }
            $rest = (string) substr( $rest, $at + 1 );
        }
        return $parts;
    }

    private static function cut_word_is_secret( string $part ): bool {
        $probes = ctype_space( substr( $part, -1 ) ) ? array( $part . 'x' ) : array( $part, $part . 'x' );
        foreach ( $probes as $probe ) {
            try {
                Shell_Lexer::split( $probe );
            } catch ( Cli_Parse_Error $e ) {
                continue;
            }
            $masked = self::mask_command( $probe )[0];
            $space  = strrpos( $masked, ' ' );
            $last   = false === $space ? $masked : substr( $masked, $space + 1 );
            if ( self::MASK === substr( $last, -strlen( self::MASK ) ) ) {
                return true;
            }
        }
        return false;
    }

    private static function cut_word_start( string $part ): int {
        if ( ctype_space( substr( $part, -1 ) ) ) {
            return strlen( $part );
        }
        $start = 0;
        foreach ( array( ' ', "\t", "\n" ) as $space ) {
            $found = strrpos( $part, $space );
            if ( false !== $found && $found + 1 > $start ) {
                $start = $found + 1;
            }
        }
        $equals = strpos( $part, '=', $start );
        return '-' === substr( $part, $start, 1 ) && false !== $equals ? $equals + 1 : $start;
    }

    public static function mask_command( string $command ): array {
        try {
            $words = Shell_Lexer::split( $command );
        } catch ( Cli_Parse_Error $e ) {
            return self::fail_closed( $command );
        }

        try {
            $found = self::find_leaf( $words );
        } catch ( Cli_Parse_Error $e ) {
            return self::fail_closed_refused( $words );
        }
        if ( null !== $found && in_array( (string) $found['leaf']['command'], self::OPAQUE_COMMANDS, true ) ) {
            return self::opaque( $words, $found );
        }

        $names   = array();
        $replace = self::positional_replacements( $found, $names );

        $out = array();
        foreach ( $words as $word ) {
            $out[] = self::mask_word( (string) $word, $replace, $names );
        }

        $text = \Easy_MCP_AI\Text_Redactor::redact( implode( ' ', $out ) );
        return array( $text, array_values( array_unique( $names ) ) );
    }

    private static function mask_word( string $word, array $replace, array &$names ): string {
        if ( preg_match( '|^--([^=]+)=(.*)\z|s', $word, $m ) ) {
            return self::mask_flag_value( $word, $m[1], $m[2], $names );
        }
        if ( ! self::is_assoc( $word ) && isset( $replace[ $word ] ) ) {
            return $replace[ $word ];
        }
        if ( ! self::is_assoc( $word ) && self::json_has_secret_key( $word ) ) {
            $names[] = 'JSON with a secret-named key';
            return self::MASK;
        }
        return self::quote( $word );
    }

    private static function mask_flag_value( string $word, string $name, string $value, array &$names ): string {
        if ( '' !== $value && self::key_is_secret( $name ) ) {
            $names[] = '--' . $name;
            return self::quote( '--' . $name . '=' ) . self::MASK;
        }
        if ( self::json_has_secret_key( $value ) ) {
            $names[] = '--' . $name . ' (JSON with a secret-named key)';
            return self::quote( '--' . $name . '=' ) . self::MASK;
        }
        return self::quote( $word );
    }

    private static function opaque( array $words, array $found ): array {
        $parsed = Argv::parse( $words, Catalog::global_parameters() );
        $helps  = 0;
        while ( isset( $parsed['positional'][ $helps ] ) && 'help' === $parsed['positional'][ $helps ] ) {
            ++$helps;
        }
        $path = implode( ' ', $found['path'] );
        $kept = ( isset( $words[0] ) && 'wp' === $words[0] ? 'wp ' : '' ) . str_repeat( 'help ', $helps ) . $path;
        if ( array() === $found['rest'] && array() === $parsed['assoc'] ) {
            return array( $kept, array() );
        }
        return array( $kept . ' ' . self::MASK, array( 'arguments of ' . $path ) );
    }

    private static function positional_replacements( ?array $found, array &$names ): array {
        if ( null === $found ) {
            return array();
        }
        $command = (string) $found['leaf']['command'];
        $rest    = array_values( array_map( 'strval', $found['rest'] ) );

        return array_replace(
            self::secret_positionals( $command, $found['leaf'], $rest, $names ),
            self::keyed_value_secrets( $command, $rest, $names ),
            self::url_secrets( $command, $rest, $names )
        );
    }

    private static function find_leaf( array $words ): ?array {
        try {
            $globals = Catalog::global_parameters();
        } catch ( \RuntimeException $e ) {
            return null;
        }
        $parsed     = Argv::parse( $words, $globals );
        $positional = array_map( 'strval', $parsed['positional'] );
        while ( count( $positional ) > 1 && 'help' === $positional[0] ) {
            array_shift( $positional );
        }
        try {
            $found = Catalog::find( $positional );
        } catch ( \RuntimeException $e ) {
            return null;
        }
        return null === $found['leaf'] ? null : $found;
    }

    private static function secret_positionals( string $command, array $leaf, array $rest, array &$names ): array {
        if ( ! isset( self::SECRET_POSITIONALS[ $command ] ) ) {
            return array();
        }
        $params  = self::positional_names( $leaf );
        $replace = array();
        foreach ( self::SECRET_POSITIONALS[ $command ] as $index ) {
            if ( isset( $rest[ $index ] ) && '' !== $rest[ $index ] ) {
                $replace[ $rest[ $index ] ] = self::MASK;
                $names[]                    = '<' . ( isset( $params[ $index ] ) ? $params[ $index ] : 'secret' ) . '>';
            }
        }
        return $replace;
    }

    private static function keyed_value_secrets( string $command, array $rest, array &$names ): array {
        if ( ! isset( self::KEYED_VALUES[ $command ] ) ) {
            return array();
        }
        list( $key_index, $value_index ) = self::KEYED_VALUES[ $command ];
        if ( ! isset( $rest[ $key_index ] ) ) {
            return array();
        }
        if ( self::key_is_secret( $rest[ $key_index ] ) ) {
            return self::secret_key_values( $rest, $key_index, $value_index, $names );
        }
        return self::is_patch( $command ) ? self::secret_path_value( $rest, $key_index, $value_index, $names ) : array();
    }

    private static function secret_key_values( array $rest, int $key_index, int $value_index, array &$names ): array {
        $replace = array();
        foreach ( array_slice( $rest, $value_index ) as $value ) {
            if ( '' !== $value && $value !== $rest[ $key_index ] ) {
                $replace[ $value ] = self::MASK;
            }
        }
        if ( $replace ) {
            $names[] = "value of '" . $rest[ $key_index ] . "'";
        }
        return $replace;
    }

    private static function secret_path_value( array $rest, int $key_index, int $value_index, array &$names ): array {
        $tail  = array_slice( $rest, $value_index );
        $value = 'delete' !== $rest[0] && count( $tail ) >= 2 ? (string) array_pop( $tail ) : '';
        if ( '' === $value ) {
            return array();
        }
        foreach ( $tail as $element ) {
            if ( self::key_is_secret( $element ) ) {
                $names[] = "value of '" . implode( ' ', array_merge( array( $rest[ $key_index ] ), $tail ) ) . "'";
                return array( $value => self::MASK );
            }
        }
        return array();
    }

    private static function url_secrets( string $command, array $rest, array &$names ): array {
        if ( ! isset( self::URL_POSITIONALS[ $command ] ) ) {
            return array();
        }
        $replace = array();
        foreach ( array_slice( $rest, self::URL_POSITIONALS[ $command ] ) as $value ) {
            $stripped = self::strip_url_secrets( $value );
            if ( null !== $stripped ) {
                $replace[ $value ] = $stripped;
                $names[]           = 'url query';
            }
        }
        return $replace;
    }

    private static function key_is_secret( string $key ): bool {
        return Handlers\Option_Handler::key_is_secret( $key );
    }

    private static function is_patch( string $command ): bool {
        return ' patch' === substr( $command, -6 );
    }

    private static function json_has_secret_key( string $word ): bool {
        if ( '' === $word || ( '{' !== $word[0] && '[' !== $word[0] ) ) {
            return false;
        }
        $decoded = json_decode( $word, true );
        return is_array( $decoded ) && self::has_secret_key( $decoded );
    }

    private static function has_secret_key( array $data ): bool {
        foreach ( $data as $key => $value ) {
            if ( is_string( $key ) && self::key_is_secret( $key ) ) {
                return true;
            }
            if ( is_array( $value ) && self::has_secret_key( $value ) ) {
                return true;
            }
        }
        return false;
    }

    private static function strip_url_secrets( string $value ): ?string {
        if ( ! \Easy_MCP_AI\Ascii::matches( '#^[a-z][a-z0-9+.\-]*://#', $value ) ) {
            return null;
        }
        $cut = strcspn( $value, '?#' );
        if ( $cut >= strlen( $value ) ) {
            return null;
        }
        return self::quote( substr( $value, 0, $cut + 1 ) ) . self::MASK;
    }

    private static function fail_closed( string $command ): array {
        $plain = array();
        foreach ( preg_split( '/[ \t]+/', trim( $command ) ) as $word ) {
            if ( ! preg_match( '/^[a-z][a-z0-9-]*\z/', (string) $word ) || count( $plain ) >= 6 ) {
                break;
            }
            $plain[] = (string) $word;
        }
        if ( isset( $plain[0] ) && 'wp' === $plain[0] ) {
            array_shift( $plain );
        }
        $path = array();
        try {
            $found = Catalog::find( $plain );
            $path  = $found['path'];
        } catch ( \RuntimeException $e ) {
            $path = array();
        }
        $kept = $path ? implode( ' ', $path ) . ' ' : '';
        return array( $kept . self::MASK, array( 'unparseable' ) );
    }

    private static function fail_closed_refused( array $words ): array {
        $kept  = array();
        $names = array();
        $at    = 0;
        foreach ( $words as $word ) {
            $word = (string) $word;
            if ( preg_match( Argv::ENV_ASSIGNMENT, $word, $m ) ) {
                $kept[]  = self::quote( $m[0] ) . self::MASK;
                $names[] = 'environment assignment';
            } elseif ( 'wp' === $word || preg_match( '#' . Argv::ALIAS_REGEX . '#', $word ) ) {
                $kept[] = self::quote( $word );
            } else {
                break;
            }
            ++$at;
        }
        list( $rest ) = self::fail_closed( implode( ' ', array_slice( $words, $at ) ) );
        $kept[]       = $rest;
        $names[]      = 'unparseable';
        return array( \Easy_MCP_AI\Text_Redactor::redact( implode( ' ', $kept ) ), array_values( array_unique( $names ) ) );
    }

    private static function positional_names( array $leaf ): array {
        $names = array();
        foreach ( isset( $leaf['params'] ) ? (array) $leaf['params'] : array() as $param ) {
            if ( isset( $param['type'], $param['name'] ) && 'positional' === $param['type'] ) {
                $names[] = (string) $param['name'];
            }
        }
        return $names;
    }

    private static function is_assoc( string $word ): bool {
        return 1 === preg_match( '|^--[^=]|', $word );
    }

    private static function quote( string $word ): string {
        if ( '' === $word ) {
            return "''";
        }
        if ( preg_match( '#^[A-Za-z0-9_\-.,:/@%+=]+\z#', $word ) ) {
            return $word;
        }
        return "'" . str_replace( "'", "'\\''", $word ) . "'";
    }
}
