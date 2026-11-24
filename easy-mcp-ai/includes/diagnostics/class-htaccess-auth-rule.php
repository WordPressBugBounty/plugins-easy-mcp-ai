<?php

namespace Easy_MCP_AI\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Htaccess_Auth_Rule {

    const MARKER = 'Easy MCP AI';

    const WORDPRESS_MARKER = 'WordPress';

    const PLAIN_ROUTES_MARKER = 'Easy MCP AI Plain Permalinks';

    const RULE_MARKER = 'HTTP_AUTHORIZATION';

    const OFFER_BUTTON = 'button';

    const OFFER_PASTE = 'paste';

    const OFFER_PASTE_ABOVE = 'paste_above';

    const OFFER_ALREADY_PRESENT = 'already_present';

    const OFFER_NOT_APPLICABLE = 'not_applicable';

    const OFFER_UNREADABLE = 'unreadable';

    const OFFER_NONE = 'none';

    public static function rule_lines() {
        return array(
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteCond %{HTTP:Authorization} .',
            'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
            '</IfModule>',
        );
    }

    public static function block_text() {
        return implode(
            "\n",
            array_merge(
                array( '# BEGIN ' . self::MARKER ),
                self::rule_lines(),
                array( '# END ' . self::MARKER )
            )
        );
    }

    public static function server_applies( $server_software ) {
        $s = strtolower( (string) $server_software );
        return false !== strpos( $s, 'apache' ) || false !== strpos( $s, 'litespeed' );
    }

    public static function has_active_rule( $contents ) {
        if ( null === $contents || '' === (string) $contents ) {
            return false;
        }
        return 1 === preg_match( self::active_rule_pattern(), (string) preg_replace( '/\\\\\r?\n/', ' ', (string) $contents ) );
    }

    private static function active_rule_pattern() {
        return '/^[ \t]*(?:'
            . 'RewriteRule\b[^\r\n]*\b(?:E|env)=HTTP_AUTHORIZATION\b'
            . '|SetEnvIf(?:NoCase)?\b[^\r\n]*\bHTTP_AUTHORIZATION\b'
            . '|CGIPassAuth[ \t]+On\b'
            . ')/mi';
    }

    public static function has_block( $contents ) {
        return self::has_marker_block( $contents, self::MARKER );
    }

    public static function has_marker_block( ?string $contents, string $marker ): bool {
        return 1 === preg_match( '/^[ \t]*# BEGIN ' . preg_quote( $marker, '/' ) . '[ \t]*\r?$/m', (string) $contents );
    }

    public static function strip_marker_block( ?string $contents, string $marker ): string {
        $contents = (string) $contents;
        if ( ! self::has_marker_block( $contents, $marker ) ) {
            return $contents;
        }
        $pattern = '/^[ \t]*# BEGIN ' . preg_quote( $marker, '/' ) . '[ \t]*\R.*?^[ \t]*# END ' . preg_quote( $marker, '/' ) . '[ \t]*(?:\R|$)/ms';
        $updated = preg_replace( $pattern, '', $contents );
        return null === $updated ? $contents : $updated;
    }

    public static function offer( $a1_status, $server_software, $file_exists, $file_writable, $contents, ?array $site = null ) {
        return self::decide( $a1_status, $server_software, $file_exists, $file_writable, $contents, $site )['offer'];
    }

    private static function decide( $a1_status, $server_software, $file_exists, $file_writable, $contents, ?array $site ): array {
        if ( Diagnostic_Result::STATUS_WARN !== $a1_status ) {
            return array( 'offer' => self::OFFER_NONE, 'line' => null );
        }
        if ( ! self::server_applies( $server_software ) ) {
            return array( 'offer' => self::OFFER_NOT_APPLICABLE, 'line' => null );
        }
        if ( $file_exists && null === $contents ) {
            return array( 'offer' => self::OFFER_UNREADABLE, 'line' => null );
        }
        $site    = $site ?? Htaccess_Rewrite_Reader::default_site();
        $reading = Htaccess_Rewrite_Reader::read( $contents, $site );
        if ( Htaccess_Rewrite_Reader::REACHED === $reading['reach'] ) {
            return array( 'offer' => self::OFFER_ALREADY_PRESENT, 'line' => null );
        }
        $placed = Htaccess_Rewrite_Reader::read( self::place( (string) $contents ), $site );
        if ( Htaccess_Rewrite_Reader::REACHED !== $placed['reach'] ) {
            $line = array() === $reading['lines'] ? self::line_before_placing( (string) $contents, $placed['lines'] ) : $reading['lines'][0];
            return array( 'offer' => self::OFFER_PASTE_ABOVE, 'line' => $line );
        }
        return array( 'offer' => $file_exists && $file_writable ? self::OFFER_BUTTON : self::OFFER_PASTE, 'line' => null );
    }

    private static function line_before_placing( string $contents, array $placed_lines ): ?int {
        $placed = preg_split( '/\r\n|\r|\n/', self::place( $contents ) );
        $start  = array_search( '# BEGIN ' . self::MARKER, array_map( 'trim', $placed ), true );
        $length = count( self::rule_lines() ) + 2;
        foreach ( $placed_lines as $line ) {
            if ( false === $start || $line <= $start ) {
                return $line;
            }
            if ( $line > $start + $length ) {
                return $line - $length;
            }
        }
        return null;
    }

    public static function may_write( $has_manage_options, $is_multisite, $is_super_admin, $has_network_cap ) {
        if ( ! $has_manage_options ) {
            return false;
        }
        if ( $is_multisite ) {
            return $is_super_admin && $has_network_cap;
        }
        return true;
    }

    public static function place( $contents ) {
        $contents = self::strip( (string) $contents );
        $eol      = ( false !== strpos( $contents, "\r\n" ) ) ? "\r\n" : "\n";
        $block    = str_replace( "\n", $eol, self::block_text() ) . $eol;

        foreach ( array( self::PLAIN_ROUTES_MARKER, self::WORDPRESS_MARKER ) as $marker ) {
            if ( preg_match( '/^[ \t]*' . preg_quote( '# BEGIN ' . $marker, '/' ) . '[ \t]*(?:\R|$)/m', $contents, $m, PREG_OFFSET_CAPTURE ) ) {
                $at = (int) $m[0][1];
                return substr( $contents, 0, $at ) . $block . substr( $contents, $at );
            }
        }

        return $block . $contents;
    }

    public static function offer_now( $a1 ) {
        $status   = ( $a1 instanceof Diagnostic_Result ) ? $a1->status() : Diagnostic_Result::STATUS_UNKNOWN;
        $server   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        $path     = self::htaccess_path();
        $exists   = file_exists( $path );
        $decision = Diagnostic_Result::STATUS_WARN === $status && self::server_applies( $server )
            ? self::decide( $status, $server, $exists, $exists && is_writable( $path ), self::read( $path ), array( 'document_root' => dirname( $path ) ) + Htaccess_Rewrite_Reader::site_now() )
            : self::decide( $status, $server, $exists, false, null, null );

        return array(
            'offer' => $decision['offer'],
            'path'  => $path,
            'line'  => $decision['line'],
        );
    }

    public static function htaccess_path() {
        if ( ! function_exists( 'get_home_path' ) ) {
            $file = ABSPATH . 'wp-admin/includes/file.php';
            if ( is_readable( $file ) ) {
                require_once $file;
            }
        }

        $home = ABSPATH;
        if ( function_exists( 'get_home_path' ) ) {
            $resolved = \get_home_path();
            if ( is_string( $resolved ) && '' !== $resolved ) {
                $home = $resolved;
            }
        }

        return rtrim( (string) $home, '/\\' ) . '/.htaccess';
    }

    public static function read( $path ) {
        if ( ! is_readable( $path ) ) {
            return null;
        }
        $contents = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local config read; WP_Filesystem is not initialised here.
        return false === $contents ? null : $contents;
    }

    public static function write( $path ) {
        if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
            return false;
        }
        return self::mutate( $path, array( __CLASS__, 'place' ) );
    }

    public static function remove( $path ) {
        if ( ! file_exists( $path ) ) {
            return true;
        }
        if ( ! is_writable( $path ) ) {
            $contents = self::read( $path );
            return null !== $contents && ! self::has_block( $contents );
        }
        return self::mutate( $path, array( __CLASS__, 'strip' ) );
    }

    public static function strip( $contents ) {
        return self::strip_marker_block( $contents, self::MARKER );
    }

    public static function mutate( $path, $transform, $create = false ) {
        $fp = @fopen( $path, $create ? 'c+' : 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Our own block in .htaccess under flock(); WP_Filesystem is not initialised on uninstall.
        if ( ! $fp ) {
            return false;
        }
        if ( ! flock( $fp, LOCK_EX ) ) {
            fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return false;
        }

        $ok       = false;
        $contents = stream_get_contents( $fp );
        if ( false !== $contents ) {
            $updated = (string) call_user_func( $transform, $contents );
            if ( $updated === $contents ) {
                $ok = true;
            } elseif ( rewind( $fp ) && ftruncate( $fp, 0 ) ) {
                $written = fwrite( $fp, $updated ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                $ok      = ( false !== $written && strlen( $updated ) === $written );
                fflush( $fp );
            }
        }

        flock( $fp, LOCK_UN );
        fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return $ok;
    }
}
