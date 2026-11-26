<?php
namespace Easy_MCP_AI\Tools\GSC;

use Easy_MCP_AI\GSC\Edit_Refusal;
use Easy_MCP_AI\GSC\GSC_Client;
use Easy_MCP_AI\MCP\Detailed_Runtime_Error;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;
use Easy_MCP_AI\MCP\Refusal_Text;
use Easy_MCP_AI\Tools\Post_Type_Tool_Hint;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Sitemap_Batch {

    const MAX_SITEMAPS = 100;

    const URL_PATTERN = '#^https?://\S+\z#i';

    public static function feed_paths( array $arguments ): array {
        $sent = $arguments['feed_paths'] ?? null;
        if ( ! is_array( $sent ) || array() === $sent || array_keys( $sent ) !== range( 0, count( $sent ) - 1 ) ) {
            throw new \InvalidArgumentException( 'feed_paths must be a list of sitemap URLs, e.g. ["https://example.com/sitemap.xml"]. Nothing was changed.' );
        }
        $paths = array();
        foreach ( $sent as $value ) {
            $path = is_string( $value ) ? trim( $value ) : '';
            if ( 1 !== preg_match( self::URL_PATTERN, $path ) ) {
                throw new \InvalidArgumentException( 'Each feed_paths entry must be an absolute http(s) URL to a sitemap, e.g. https://example.com/sitemap.xml. Nothing was changed.' );
            }
            $key = self::key( $path );
            if ( ! isset( $paths[ $key ] ) ) {
                $paths[ $key ] = $path;
            }
        }
        if ( count( $paths ) > self::MAX_SITEMAPS ) {
            throw new \InvalidArgumentException( sprintf( 'At most %d sitemaps per call. Nothing was changed.', self::MAX_SITEMAPS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return array_values( $paths );
    }

    public static function key( string $path ): string {
        return (string) preg_replace_callback(
            '#^https?://[^/?\#]*#i',
            static function ( array $m ): string {
                return strtolower( $m[0] );
            },
            $path
        );
    }

    public static function submitted( string $site_url ): array {
        $data    = GSC_Client::edit( 'GET', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/sitemaps' );
        $entries = array();
        foreach ( (array) ( $data['sitemap'] ?? array() ) as $entry ) {
            if ( is_array( $entry ) && isset( $entry['path'] ) && is_string( $entry['path'] ) ) {
                $entries[ self::key( $entry['path'] ) ] = $entry;
            }
        }
        return $entries;
    }

    public static function url( string $site_url, string $path ): string {
        return 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/sitemaps/' . rawurlencode( $path );
    }

    const SHOWN_URL_BYTES = 300;

    const REASON_BYTES = 800;

    public static function stopped( Sitemap_Change $change, string $path, array $done, array $untried, \RuntimeException $error ): \RuntimeException {
        $refused = $error instanceof Edit_Refusal;
        if ( $refused && array() === $done && array() === $untried ) {
            return $error;
        }
        $verb = $change->verb;
        $at   = 'Stopped at ' . self::shown( $path );
        if ( ! $refused ) {
            $lead = "{$at}: whether it was {$verb} is not known.";
        } elseif ( array() === $done ) {
            $lead = "{$at}: nothing was changed.";
        } else {
            $lead = "{$at}, which was not {$verb}.";
        }
        $reason   = self::whole_text( $error );
        $sections = array();
        if ( array() !== $done ) {
            try {
                list( $applied, $contradicted ) = self::split( $change, $done, self::submitted( $change->site_url ) );
                $sections[] = array( "{$verb} before it", $applied );
                $sections[] = array( "answered success before it, but {$change->contradiction}", $contradicted );
            } catch ( \RuntimeException $read ) {
                $half       = intdiv( self::REASON_BYTES - 40, 2 );
                $sections[] = array( 'answered success before it, not confirmed', $done );
                $reason     = rtrim( Refusal_Text::fit( '%s', array( $reason ), 0, $half ), '.' ) . '. Reading the sitemaps back failed: ' . Refusal_Text::fit( '%s', array( self::whole_text( $read ) ), 0, $half );
            }
        }
        $sections[] = array( 'not sent', $untried );
        return self::receipt( $lead, $sections, $reason );
    }

    public static function split( Sitemap_Change $change, array $sent, array $now ): array {
        $applied      = array();
        $contradicted = array();
        foreach ( $sent as $path ) {
            if ( isset( $now[ self::key( $path ) ] ) === $change->listed_when_applied ) {
                $applied[] = $path;
            } else {
                $contradicted[] = $path;
            }
        }
        return array( $applied, $contradicted );
    }

    public static function unconfirmed( array $sent, \RuntimeException $error ): \RuntimeException {
        return self::receipt(
            'Google Search Console answered success for every sitemap, but reading the sitemaps back failed, so none is confirmed.',
            array( array( 'answered success, not confirmed', $sent ) ),
            self::whole_text( $error )
        );
    }

    public static function contradicted( Sitemap_Change $change, array $applied, array $contradicted ): \RuntimeException {
        return self::receipt(
            'Google Search Console answered success for every sitemap, but the list for ' . self::shown( $change->site_url ) . " does not show all of them {$change->verb}.",
            array(
                array( $change->verb, $applied ),
                array( "answered success, but {$change->contradiction}", $contradicted ),
            )
        );
    }

    private static function whole_text( \RuntimeException $error ): string {
        $message = $error->getMessage();
        $details = $error instanceof Detailed_Runtime_Error ? $error->get_details() : '';
        $head    = (string) preg_replace( '/…\z/u', '', $message );
        return '' !== $details && 0 === strpos( $details, $head ) ? $details : trim( $message . ' ' . $details );
    }

    public static function shown( string $path ): string {
        if ( strlen( $path ) <= self::SHOWN_URL_BYTES ) {
            return $path;
        }
        return ( function_exists( 'mb_strcut' ) ? mb_strcut( $path, 0, self::SHOWN_URL_BYTES, 'UTF-8' ) : substr( $path, 0, self::SHOWN_URL_BYTES ) ) . '…';
    }

    public static function receipt( string $lead, array $sections, string $reason = '' ): \RuntimeException {
        if ( '' !== $reason ) {
            $reason = Refusal_Text::fit( '%s', array( $reason ), 0, self::REASON_BYTES );
        }
        $shown  = array_map(
            static function ( array $section ): int {
                return count( $section[1] );
            },
            $sections
        );
        while ( true ) {
            $text = $lead;
            foreach ( $sections as $i => $section ) {
                list( $label, $paths ) = $section;
                if ( array() === $paths ) {
                    continue;
                }
                $text  .= ' ' . count( $paths ) . ' ' . $label;
                $listed = array_slice( $paths, 0, $shown[ $i ] );
                if ( array() !== $listed ) {
                    $more  = count( $paths ) - count( $listed );
                    $text .= ': ' . implode( ', ', $listed ) . ( $more > 0 ? sprintf( ' and %d more', $more ) : '' );
                }
                $text .= '.';
            }
            if ( Post_Type_Tool_Hint::may_reach( 'wp_gsc_list_sitemaps' ) ) {
                $text .= ' wp_gsc_list_sitemaps lists the sitemaps submitted now.';
            }
            $whole = '' === $reason ? $text : $text . ' ' . $reason;
            $last  = max( array_keys( array_filter( $shown ) ) ?: array( -1 ) );
            if ( -1 === $last || Refusal_Text::length( $whole ) <= Detailed_Tool_Error::MAX_DETAILS ) {
                return Detailed_Runtime_Error::fitted( $whole );
            }
            --$shown[ $last ];
        }
    }
}
