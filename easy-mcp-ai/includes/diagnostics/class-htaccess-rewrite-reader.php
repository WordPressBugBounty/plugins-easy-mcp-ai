<?php

namespace Easy_MCP_AI\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Htaccess_Rewrite_Reader {

    const ABSENT = 'absent';

    const REACHED = 'reached';

    const NOT_REACHED = 'not_reached';

    const MAYBE = 'maybe';

    const REFUSED = 'refused';

    const PROBE = 'probe';

    private const MAX_PASSES = 10;

    private const MAX_COURSES = 256;

    private const MAX_FORKS = 512;

    private const PROBE_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const OPAQUE_PATTERNS = array(
        '.*'     => array( 'all', 'whole' ),
        '^.*$'   => array( 'all', 'whole' ),
        '^.*'    => array( 'all', 'whole' ),
        '.*$'    => array( 'all', 'whole' ),
        '(.*)'   => array( 'all', 'whole' ),
        '^(.*)$' => array( 'all', 'whole' ),
        '^(.*)'  => array( 'all', 'whole' ),
        '(.*)$'  => array( 'all', 'whole' ),
        '^'      => array( 'all', 'empty' ),
        '.+'     => array( 'nonempty', 'whole' ),
        '^.+$'   => array( 'nonempty', 'whole' ),
        '^.+'    => array( 'nonempty', 'whole' ),
        '.+$'    => array( 'nonempty', 'whole' ),
        '(.+)'   => array( 'nonempty', 'whole' ),
        '^(.+)$' => array( 'nonempty', 'whole' ),
        '^(.+)'  => array( 'nonempty', 'whole' ),
        '(.+)$'  => array( 'nonempty', 'whole' ),
        '.'      => array( 'nonempty', 'part' ),
        '^.'     => array( 'nonempty', 'part' ),
    );

    private const HEADER_VARIABLES = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );

    private const DELIVERED = 'delivered';
    private const LOST      = 'lost';
    private const BEFORE    = 'before';
    private const UNSURE    = 'unsure';

    private const LOADED_MODULES = array( 'mod_rewrite.c', 'rewrite_module', 'mod_setenvif.c', 'setenvif_module' );

    private $rules;

    private $set_env_if = array();

    private $engine;

    private $rewrite_base;

    private $site;

    private $request;

    private $outcomes = array();

    private $forks = 0;

    private $connection_unknown = false;

    private $cgi_pass = false;

    private $truncated = false;

    public static function read( ?string $contents, array $site ): array {
        $contents = (string) $contents;
        $ai       = array();
        $probe    = null;
        foreach ( (array) ( $site['requests'] ?? array() ) as $key => $request ) {
            $answer = self::reach( $contents, $site, (array) $request );
            if ( self::PROBE === $key ) {
                $probe = $answer;
            } else {
                $ai[] = $answer;
            }
        }
        $joined = self::join( $ai );

        return array(
            'reach'       => $joined['reach'],
            'lines'       => $joined['lines'],
            'client'      => $joined['client'],
            'probe_reach' => null === $probe ? null : $probe['reach'],
            'probe_lines' => null === $probe ? array() : $probe['lines'],
        );
    }

    public static function reach( string $contents, array $site, array $request ): array {
        if ( ! Htaccess_Auth_Rule::has_active_rule( $contents ) ) {
            return array( 'reach' => self::ABSENT, 'lines' => array(), 'client' => false );
        }
        $reader  = new self( $contents, $site, $request );
        $outside = $reader->delivers_outside_rewriting( $contents );
        $reader->cgi_pass = true === $outside['delivers'];
        $answer           = $reader->run();
        if ( null === $outside['delivers'] && in_array( $answer['reach'], array( self::NOT_REACHED, self::MAYBE ), true ) ) {
            $answer['reach']  = self::MAYBE;
            $answer['client'] = $answer['client'] || $outside['client'];
        }
        return $answer;
    }

    public static function site_now(): array {
        $home  = (string) \home_url( '/' );
        $parts = (array) wp_parse_url( $home );
        $base  = self::base_of( array( 'base' => (string) ( $parts['path'] ?? '' ) ) );
        $rest  = function_exists( 'rest_url' ) ? (string) \rest_url( 'easy-mcp-ai/v1/mcp' ) : $home . 'wp-json/easy-mcp-ai/v1/mcp';
        $agent = function_exists( 'get_bloginfo' ) ? 'WordPress/' . \get_bloginfo( 'version' ) . '; ' . \get_bloginfo( 'url' ) : 'WordPress';
        if ( function_exists( 'apply_filters' ) ) {
            $agent = (string) \apply_filters( 'http_headers_useragent', $agent, $rest );
        }

        $site = array(
            'host'          => strtolower( (string) ( $parts['host'] ?? 'localhost' ) ),
            'port'          => isset( $parts['port'] ) ? (int) $parts['port'] : null,
            'https'         => self::apache_https(),
            'url_https'     => 'https' === strtolower( (string) wp_parse_url( $rest, PHP_URL_SCHEME ) ),
            'server'        => isset( $_SERVER['SERVER_SOFTWARE'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
            'document_root' => dirname( Htaccess_Auth_Rule::htaccess_path() ),
            'base'          => $base,
            'requests'      => array(
                'mcp'       => self::request_for( 'POST', 'easy-mcp-ai/v1/mcp', $base ),
                'token'     => self::request_for( 'POST', 'easy-mcp-ai/v1/oauth/token', $base ),
                self::PROBE => self::request_for( 'GET', 'easy-mcp-ai/v1/header-probe', $base, 'probe=' . self::PROBE_SECRET, self::probe_headers( $agent ) ),
            ),
        );
        if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
            $site['server_document_root'] = rtrim( \sanitize_text_field( \wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ), '/\\' );
        }
        return $site;
    }

    private static function apache_https(): ?bool {
        if ( function_exists( 'apache_getenv' ) ) {
            return 'on' === strtolower( (string) apache_getenv( 'HTTPS' ) );
        }
        if ( in_array( PHP_SAPI, array( 'cgi-fcgi', 'fpm-fcgi', 'litespeed' ), true ) && false !== getenv( 'REQUEST_METHOD' ) ) {
            return 'on' === strtolower( (string) getenv( 'HTTPS' ) );
        }
        return null;
    }

    public static function default_site(): array {
        return array(
            'host'          => 'localhost',
            'port'          => null,
            'https'         => true,
            'server'        => 'Apache',
            'document_root' => '/',
            'base'          => '/',
            'files'         => array( 'index.php' ),
            'dirs'          => array(),
            'requests'      => array(
                'mcp'       => array( 'method' => 'POST', 'path' => 'wp-json/easy-mcp-ai/v1/mcp', 'query' => '' ),
                'token'     => array( 'method' => 'POST', 'path' => 'wp-json/easy-mcp-ai/v1/oauth/token', 'query' => '' ),
                self::PROBE => array( 'method' => 'GET', 'path' => 'wp-json/easy-mcp-ai/v1/header-probe', 'query' => 'probe=' . self::PROBE_SECRET, 'headers' => self::probe_headers( 'WordPress/7.1.4; https://localhost' ) ),
            ),
        );
    }

    private static function probe_headers( string $agent ): array {
        return array(
            'cookie'     => '',
            'referer'    => '',
            'user-agent' => $agent,
        );
    }

    private static function request_for( string $method, string $route, string $base, string $query = '', array $headers = array() ): array {
        $url   = function_exists( 'rest_url' ) ? (string) \rest_url( $route ) : $base . 'wp-json/' . $route;
        $path  = (string) wp_parse_url( $url, PHP_URL_PATH );
        $path  = 0 === strpos( $path, $base ) ? (string) substr( $path, strlen( $base ) ) : ltrim( $path, '/' );
        $own   = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $query = '' === $own ? $query : ( '' === $query ? $own : $own . '&' . $query );

        return array(
            'method'  => $method,
            'path'    => $path,
            'query'   => $query,
            'headers' => $headers,
        );
    }

    private static function join( array $answers ): array {
        foreach ( array( self::ABSENT, self::NOT_REACHED, self::REFUSED, self::MAYBE ) as $worst ) {
            $lines = array();
            $found = false;
            foreach ( $answers as $answer ) {
                if ( $worst === $answer['reach'] ) {
                    $found = true;
                    $lines = array_merge( $lines, $answer['lines'] );
                }
            }
            if ( $found ) {
                return array( 'reach' => $worst, 'lines' => self::sorted( $lines ), 'client' => self::any_client( $answers ) );
            }
        }
        return array( 'reach' => self::REACHED, 'lines' => array(), 'client' => self::any_client( $answers ) );
    }

    private static function any_client( array $answers ): bool {
        foreach ( $answers as $answer ) {
            if ( $answer['client'] ) {
                return true;
            }
        }
        return false;
    }

    private static function sorted( array $lines ): array {
        $lines = array_values( array_unique( $lines ) );
        sort( $lines );
        return $lines;
    }

    private function __construct( string $contents, array $site, array $request ) {
        $this->site         = $site;
        $this->request      = $request;
        $this->rewrite_base = self::base_of( $site );
        $this->parse( $contents );
    }

    private static function base_of( array $site ): string {
        $base = trim( (string) ( $site['base'] ?? '/' ), '/' );
        return '' === $base ? '/' : '/' . $base . '/';
    }

    private function parse( string $contents ): void {
        $this->rules  = array();
        $engine       = null;
        $engine_known = true;
        $conditions   = array();

        foreach ( $this->directives( $contents ) as $directive ) {
            $guard = $directive['guard'];
            $args  = $directive['args'];
            $name  = strtolower( (string) array_shift( $args ) );

            if ( 'rewriteengine' === $name ) {
                $on = 'on' === strtolower( (string) ( $args[0] ?? '' ) );
                if ( true === $guard ) {
                    $engine       = $on;
                    $engine_known = true;
                } elseif ( $on !== $engine ) {
                    $engine_known = false;
                }
            } elseif ( 'rewritebase' === $name && true === $guard && isset( $args[0] ) ) {
                $this->rewrite_base = self::base_of( array( 'base' => $args[0] ) );
            } elseif ( 'rewritecond' === $name && count( $args ) >= 2 ) {
                $conditions[] = self::condition( $args, $guard );
            } elseif ( 'rewriterule' === $name && count( $args ) >= 2 ) {
                $this->rules[] = self::rule( $directive['line'], $args, $conditions, $guard );
                $conditions    = array();
            } elseif ( ( 'setenvif' === $name || 'setenvifnocase' === $name ) && count( $args ) >= 3 ) {
                $this->set_env_if[] = array(
                    'guard'       => $guard,
                    'attribute'   => $args[0],
                    'regex'       => self::regex( $args[1], 'setenvifnocase' === $name ),
                    'pattern'     => $args[1],
                    'assignments' => array_slice( $args, 2 ),
                );
            }
        }

        $this->engine = $engine_known ? $engine : null;
    }

    private function directives( string $contents ): array {
        $out   = array();
        $stack = array();
        foreach ( self::logical_lines( $contents ) as $number => $line ) {
            if ( '' === $line || '#' === $line[0] ) {
                continue;
            }
            if ( preg_match( '#^</\s*([A-Za-z]+)#', $line, $close ) ) {
                self::close_container( $stack, $close[1] );
                continue;
            }
            if ( preg_match( '#^<\s*([A-Za-z]+)\s*([^>]*)>?#', $line, $open ) ) {
                $stack[] = array( 'name' => strtolower( $open[1] ), 'open' => $this->container_open( $open[1], trim( $open[2] ) ) );
                continue;
            }
            $guard = self::guard( $stack );
            if ( false !== $guard ) {
                $out[] = array( 'line' => $number, 'args' => self::arguments( $line ), 'guard' => $guard );
            }
        }
        return $out;
    }

    private static function logical_lines( string $contents ): array {
        $out     = array();
        $pending = '';
        $start   = 0;
        foreach ( preg_split( '/\r\n|\r|\n/', $contents ) as $index => $physical ) {
            if ( '' === $pending ) {
                $start = $index + 1;
            }
            if ( '\\' === substr( rtrim( $physical ), -1 ) ) {
                $pending .= substr( rtrim( $physical ), 0, -1 ) . ' ';
                continue;
            }
            $out[ $start ] = trim( $pending . $physical );
            $pending       = '';
        }
        if ( '' !== $pending ) {
            $out[ $start ] = trim( $pending );
        }
        return $out;
    }

    private function container_open( string $name, string $argument ): ?bool {
        if ( 'ifmodule' !== strtolower( $name ) ) {
            return null;
        }
        $argument = trim( $argument, " \t\"'" );
        $negated  = '' !== $argument && '!' === $argument[0];
        $module   = strtolower( ltrim( $argument, '!' ) );

        if ( in_array( $module, self::LOADED_MODULES, true ) ) {
            $open = true;
        } elseif ( 'litespeed' === $module ) {
            $open = false !== stripos( (string) ( $this->site['server'] ?? '' ), 'litespeed' );
        } else {
            return null;
        }
        return $negated ? ! $open : $open;
    }

    private static function close_container( array &$stack, string $name ): void {
        $name = strtolower( $name );
        for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
            if ( $name === $stack[ $i ]['name'] ) {
                array_splice( $stack, $i );
                return;
            }
        }
    }

    private static function guard( array $stack ): ?bool {
        $guard = true;
        foreach ( $stack as $container ) {
            if ( false === $container['open'] ) {
                return false;
            }
            if ( null === $container['open'] ) {
                $guard = null;
            }
        }
        return $guard;
    }

    private static function arguments( string $line ): array {
        preg_match_all( '/"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|(\S+)/', $line, $matches, PREG_SET_ORDER );
        $args = array();
        foreach ( $matches as $match ) {
            if ( isset( $match[3] ) && '' !== $match[3] ) {
                $args[] = $match[3];
            } elseif ( isset( $match[2] ) && '' !== $match[2] ) {
                $args[] = str_replace( "\\'", "'", $match[2] );
            } else {
                $args[] = str_replace( '\\"', '"', $match[1] );
            }
        }
        return $args;
    }

    private static function condition( array $args, ?bool $guard ): array {
        $flags = self::flags( $args[2] ?? '' );
        return array(
            'test'    => $args[0],
            'pattern' => $args[1],
            'nocase'  => isset( $flags['nc'] ),
            'or'      => isset( $flags['or'] ),
            'guard'   => $guard,
        );
    }

    private static function rule( int $line, array $args, array $conditions, ?bool $guard ): array {
        return array(
            'line'         => $line,
            'pattern'      => $args[0],
            'substitution' => $args[1],
            'flags'        => self::flags( $args[2] ?? '' ),
            'conditions'   => $conditions,
            'guard'        => $guard,
        );
    }

    private const FLAG_ALIASES = array(
        'last'        => 'l',
        'forbidden'   => 'f',
        'gone'        => 'g',
        'redirect'    => 'r',
        'proxy'       => 'p',
        'passthrough' => 'pt',
        'skip'        => 's',
        'chain'       => 'c',
        'nocase'      => 'nc',
        'env'         => 'e',
        'next'        => 'n',
        'ornext'      => 'or',
    );

    private static function flags( string $text ): array {
        $text  = trim( $text );
        $flags = array();
        if ( '' === $text || '[' !== $text[0] ) {
            return $flags;
        }
        foreach ( explode( ',', trim( $text, '[]' ) ) as $flag ) {
            $flag  = trim( $flag );
            $equal = strpos( $flag, '=' );
            $name  = strtolower( false === $equal ? $flag : substr( $flag, 0, $equal ) );
            $name  = self::FLAG_ALIASES[ $name ] ?? $name;
            if ( '' !== $name ) {
                $flags[ $name ][] = false === $equal ? '' : substr( $flag, $equal + 1 );
            }
        }
        return $flags;
    }

    private function delivers_outside_rewriting( string $contents ): array {
        $delivers = false;
        foreach ( $this->directives( $contents ) as $directive ) {
            $args = $directive['args'];
            if ( 'cgipassauth' !== strtolower( (string) ( $args[0] ?? '' ) ) ) {
                continue;
            }
            $on = 'on' === strtolower( (string) ( $args[1] ?? '' ) );
            if ( true === $directive['guard'] ) {
                $delivers = $on;
            } elseif ( $on !== $delivers ) {
                $delivers = null;
            }
        }
        return array( 'delivers' => $delivers, 'client' => false );
    }

    private function apply_set_env_if( array $state ): array {
        foreach ( $this->set_env_if as $directive ) {
            $value = $this->set_env_if_attribute( (string) $directive['attribute'], $state );
            $match = self::set_env_if_match( $directive, $value );
            if ( null === $directive['guard'] && false !== $match['result'] ) {
                $match['result'] = null;
            }
            if ( false === $match['result'] ) {
                continue;
            }
            $client = self::is_opaque( $value ) ? $value['client'] : false;
            foreach ( $directive['assignments'] as $assignment ) {
                $unset = '' !== $assignment && '!' === $assignment[0];
                $equal = strpos( $assignment, '=' );
                $name  = $unset ? substr( $assignment, 1 ) : ( false === $equal ? $assignment : substr( $assignment, 0, $equal ) );
                if ( null === $match['result'] ) {
                    $before                = $state['env'][ $name ] ?? '';
                    $state['env'][ $name ] = self::opaque( null, $client || null === $match['groups'] || ( self::is_opaque( $before ) && $before['client'] ), null );
                } elseif ( $unset ) {
                    unset( $state['env'][ $name ] );
                } else {
                    $state['env'][ $name ] = false === $equal ? '1' : self::combine( self::set_env_if_value( substr( $assignment, $equal + 1 ), (array) $match['groups'] ) );
                }
            }
        }
        return $state;
    }

    private function set_env_if_attribute( string $attribute, array $state ) {
        switch ( strtolower( $attribute ) ) {
            case 'request_uri':
                return (string) $state['uri'];
            case 'request_method':
                return strtoupper( (string) ( $this->request['method'] ?? '' ) );
            case 'remote_addr':
            case 'remote_host':
            case 'server_addr':
            case 'request_protocol':
                return self::opaque( null, true, false );
        }
        if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $attribute ) ) {
            return self::opaque( null, true, null );
        }
        $header = $this->header( $attribute );
        if ( ! self::is_opaque( $header ) || 'authorization' === strtolower( $attribute ) || ! array_key_exists( $attribute, $state['env'] ) ) {
            return $header;
        }
        $value = $state['env'][ $attribute ];
        return self::opaque( null, true, true === self::is_header( $value ) ? null : false );
    }

    private static function set_env_if_match( array $directive, $value ): array {
        if ( self::is_opaque( $value ) ) {
            $known = self::OPAQUE_PATTERNS[ (string) $directive['pattern'] ] ?? null;
            $match = null === $known ? null : ( 'all' === $known[0] ? true : $value['nonempty'] );
            if ( true !== $match ) {
                return array( 'result' => $match, 'groups' => null );
            }
            return array( 'result' => true, 'groups' => self::opaque_groups( (string) $directive['pattern'], $known[1], $value ) );
        }
        if ( null === $directive['regex'] ) {
            return array( 'result' => null, 'groups' => array() );
        }
        $found = preg_match( (string) $directive['regex'], $value, $groups );
        return array( 'result' => 1 === $found, 'groups' => array_map( 'strval', $groups ) );
    }

    private static function set_env_if_value( string $value, array $groups ): array {
        $pieces = array();
        foreach ( (array) preg_split( '/(\$\d)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $part ) {
            $pieces[] = 1 === preg_match( '/^\$(\d)$/', $part, $n ) ? ( $groups[ (int) $n[1] ] ?? '' ) : $part;
        }
        return $pieces;
    }

    private static function opaque_groups( string $pattern, string $kind, array $value ): array {
        if ( 'whole' === $kind ) {
            $group = $value;
        } elseif ( 'empty' === $kind ) {
            $group = '';
        } else {
            $group = self::opaque( true, $value['client'], false );
        }
        return false !== strpos( $pattern, '(' ) ? array( $group, $group ) : array( $group );
    }

    private function run(): array {
        $path  = ltrim( (string) ( $this->request['path'] ?? '' ), '/' );
        $start = array(
            'path'     => $path,
            'subject'  => $path,
            'query'    => (string) ( $this->request['query'] ?? '' ),
            'uri'      => self::base_of( $this->site ) . $path,
            'env'      => array(),
            'wild'     => false,
            'redirect' => null,
            'passes'   => 1,
            'stops'    => array(),
            'forks'    => array(),
            'client'   => false,
        );
        $start = $this->apply_set_env_if( $start );
        if ( false === $this->engine ) {
            $this->finish( $start );
        } elseif ( null === $this->engine ) {
            $this->finish( $start );
            $this->walk( $start, 0 );
        } else {
            $this->walk( $start, 0 );
        }
        return $this->answer();
    }

    private function answer(): array {
        $ends    = array();
        $client  = $this->connection_unknown;
        $any_php = false;
        foreach ( $this->outcomes as $outcome ) {
            $any_php = $any_php || self::BEFORE !== $outcome['end'];
        }
        foreach ( $this->outcomes as $outcome ) {
            $end = $outcome['end'];
            if ( self::BEFORE === $end && $outcome['forked'] && ! $outcome['client'] && $any_php ) {
                $end = self::UNSURE;
            }
            $ends[ $end ][] = $outcome['lines'];
            if ( self::LOST === $end || self::UNSURE === $end ) {
                $client = $client || $outcome['client'];
            }
        }
        $lines_of = static function ( array $groups ): array {
            return $groups ? array_merge( ...$groups ) : array();
        };
        $php = array_values( array_diff( array_keys( $ends ), array( self::BEFORE ) ) );
        sort( $php );

        if ( ! $this->truncated && array() === $php ) {
            return array( 'reach' => self::REFUSED, 'lines' => self::sorted( $lines_of( $ends[ self::BEFORE ] ?? array() ) ), 'client' => $this->connection_unknown );
        }
        if ( ! $this->truncated && array( self::DELIVERED ) === $php ) {
            return array( 'reach' => self::REACHED, 'lines' => array(), 'client' => $this->connection_unknown );
        }
        if ( ! $this->truncated && array( self::LOST ) === $php ) {
            return array( 'reach' => self::NOT_REACHED, 'lines' => self::sorted( $lines_of( $ends[ self::LOST ] ) ), 'client' => $client );
        }
        return array(
            'reach'  => self::MAYBE,
            'lines'  => self::sorted( array_merge( $lines_of( $ends[ self::LOST ] ?? array() ), $lines_of( $ends[ self::UNSURE ] ?? array() ) ) ),
            'client' => $client || $this->truncated,
        );
    }

    private function walk( array $state, int $index ): void {
        $count = count( $this->rules );
        $skip  = 0;
        $chain = false;

        for ( $i = $index; $i < $count; $i++ ) {
            if ( $this->truncated ) {
                return;
            }
            $rule = $this->rules[ $i ];
            if ( $skip > 0 || $chain ) {
                $skip  = max( 0, $skip - 1 );
                $chain = $chain && isset( $rule['flags']['c'] );
                continue;
            }

            $matched = $this->matches( $rule, $state );
            if ( null === $matched['applies'] ) {
                if ( ++$this->forks > self::MAX_FORKS ) {
                    $this->truncated = true;
                    return;
                }
                $state['forks'][] = $rule['line'];
                $state['client']  = $state['client'] || $matched['client'];
                $this->walk_after_skip( $state, $i + 1, isset( $rule['flags']['c'] ) );
                $matched['applies'] = true;
            }
            if ( false === $matched['applies'] ) {
                $chain = isset( $rule['flags']['c'] );
                continue;
            }

            $applied = $this->apply( $rule, $state, $matched['captures'], $matched['condition_captures'] );
            if ( null === $applied ) {
                return;
            }
            $state = $applied['state'];
            if ( $applied['ends_pass'] ) {
                $this->next_pass( $state, $applied['end_flag'], $rule['line'] );
                return;
            }
            $skip = $applied['skip'];
        }

        $this->next_pass( $state, false, null );
    }

    private function walk_after_skip( array $state, int $index, bool $chained ): void {
        while ( $chained && $index < count( $this->rules ) ) {
            $chained = isset( $this->rules[ $index ]['flags']['c'] );
            $index++;
        }
        $this->walk( $state, $index );
    }

    private function next_pass( array $state, bool $end, ?int $line ): void {
        if ( null !== $line ) {
            $state['stops'][] = $line;
        }
        if ( null !== $state['redirect'] ) {
            $this->record( self::BEFORE, array( $state['redirect'] ), $state['client'], array() !== $state['forks'] );
            return;
        }
        $subject = (string) $state['subject'];
        if ( $subject === $state['path'] ) {
            $this->finish( $state );
            return;
        }
        $url_path = 0 === strpos( $subject, '/' ) ? $subject : $this->rewrite_base . $subject;
        $base     = self::base_of( $this->site );
        if ( 0 !== strpos( $url_path, $base ) ) {
            $this->record( self::UNSURE, array_merge( $state['stops'], $state['forks'] ), $state['client'] );
            return;
        }
        if ( $state['passes'] > self::MAX_PASSES ) {
            $this->record( self::BEFORE, self::sorted( $state['stops'] ), $state['client'], array() !== $state['forks'] );
            return;
        }
        $from             = (string) $state['uri'];
        $state['path']    = (string) substr( $url_path, strlen( $base ) );
        $state['subject'] = $state['path'];
        $state['uri']     = $base . $state['path'];
        $state['env']     = self::redirected( $state['env'] );
        $state['env']['REDIRECT_STATUS'] = '200';
        $state['env']['REDIRECT_URL']    = $from;
        $state['passes']++;
        $state = $this->apply_set_env_if( $state );
        if ( $end ) {
            $this->finish( $state );
            return;
        }
        $this->walk( $state, 0 );
    }

    private static function redirected( array $variables ): array {
        $out = array();
        foreach ( $variables as $name => $value ) {
            $out[ 'REDIRECT_' . $name ] = $value;
        }
        return $out;
    }

    private function finish( array $state ): void {
        $path  = (string) $state['path'];
        $lines = array_merge( $state['stops'], $state['forks'] );
        $set   = $this->cgi_pass ? true : self::delivered( $state['env'] );
        $file  = $this->exists( $this->filename( $path ), 'f' );

        if ( null === $set ) {
            $this->record( self::UNSURE, $lines, true );
        } elseif ( true === $file && '.php' === strtolower( substr( $path, -4 ) ) ) {
            $this->record( $set ? self::DELIVERED : self::LOST, $set ? array() : $lines, $state['client'] );
        } elseif ( true === $file ) {
            $this->record( self::BEFORE, $lines, $state['client'], array() !== $state['forks'] );
        } elseif ( $set ) {
            $this->record( self::DELIVERED, array(), $state['client'] );
        } else {
            $this->record( self::UNSURE, $lines, $state['client'] );
        }
    }

    private static function delivered( array $env ): ?bool {
        $fallback = array_key_exists( 'REDIRECT_HTTP_AUTHORIZATION', $env ) ? self::is_header( $env['REDIRECT_HTTP_AUTHORIZATION'] ) : false;
        if ( ! array_key_exists( 'HTTP_AUTHORIZATION', $env ) ) {
            return $fallback;
        }
        $primary = $env['HTTP_AUTHORIZATION'];
        $empty   = self::is_opaque( $primary ) ? ( null === $primary['nonempty'] ? null : ! $primary['nonempty'] ) : ( '' === $primary || '0' === $primary );
        $own     = self::is_header( $primary );
        if ( false === $empty ) {
            return $own;
        }
        if ( true === $empty ) {
            return false === $fallback ? false : null;
        }
        return $own === $fallback ? $own : null;
    }

    private function record( string $end, array $lines, bool $client, bool $forked = false ): void {
        if ( count( $this->outcomes ) >= self::MAX_COURSES ) {
            $this->truncated = true;
            return;
        }
        $this->outcomes[] = array( 'end' => $end, 'lines' => $lines, 'client' => $client, 'forked' => $forked );
    }

    private function matches( array $rule, array $state ): array {
        $no = array( 'applies' => false, 'client' => false, 'captures' => array(), 'condition_captures' => array() );

        $pattern = (string) $rule['pattern'];
        $negated = '' !== $pattern && '!' === $pattern[0];
        $regex   = self::regex( $negated ? substr( $pattern, 1 ) : $pattern, isset( $rule['flags']['nc'] ) );
        $found   = null === $regex ? false : @preg_match( $regex, (string) $state['subject'], $captures ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A pattern PHP cannot compile is an unknown outcome, not a warning.
        if ( false === $found ) {
            return array( 'applies' => null, 'client' => false, 'captures' => array(), 'condition_captures' => null );
        }
        if ( ( 1 === $found ) === $negated ) {
            return $no;
        }
        $captures = $negated ? array() : array_map( 'strval', $captures );

        $conditions = $this->conditions( $rule['conditions'], $state, $captures );
        if ( false === $conditions['result'] ) {
            return $no;
        }
        $applies = null === $rule['guard'] ? null : $conditions['result'];
        return array(
            'applies'            => $applies,
            'client'             => null === $conditions['result'] && $conditions['client'],
            'captures'           => $captures,
            'condition_captures' => $conditions['captures'],
        );
    }

    private function conditions( array $conditions, array $state, array $captures ): array {
        $result         = true;
        $group          = false;
        $client         = false;
        $last_captures  = array();
        $captures_known = true;
        $final          = count( $conditions ) - 1;

        foreach ( $conditions as $index => $condition ) {
            if ( true !== $group ) {
                $value = $this->condition_value( $condition, $state, $captures, $captures_known ? $last_captures : null );
                if ( null === $condition['guard'] ) {
                    $captures_known = false;
                    if ( true !== $value['result'] ) {
                        $value = array( 'result' => null, 'captures' => null, 'client' => $value['client'] );
                    }
                }
                if ( true === $value['result'] && null !== $value['captures'] ) {
                    $last_captures = $value['captures'];
                } elseif ( null === $value['result'] ) {
                    $captures_known = false;
                    $client         = $client || $value['client'];
                }
                $group = self::either( $group, $value['result'] );
            }
            if ( $condition['or'] && $index < $final ) {
                continue;
            }
            $result = self::both( $result, $group );
            $group  = false;
            if ( false === $result ) {
                break;
            }
        }
        return array( 'result' => $result, 'client' => $client, 'captures' => $captures_known ? $last_captures : null );
    }

    private static function either( ?bool $a, ?bool $b ): ?bool {
        if ( true === $a || true === $b ) {
            return true;
        }
        return ( null === $a || null === $b ) ? null : false;
    }

    private static function both( ?bool $a, ?bool $b ): ?bool {
        if ( false === $a || false === $b ) {
            return false;
        }
        return ( null === $a || null === $b ) ? null : true;
    }

    private function condition_value( array $condition, array $state, array $captures, ?array $condition_captures ): array {
        $test    = $this->expand( $condition['test'], $state, $captures, $condition_captures );
        $pattern = $condition['pattern'];
        $negated = '' !== $pattern && '!' === $pattern[0];
        $pattern = $negated ? substr( $pattern, 1 ) : $pattern;
        $groups  = null;

        if ( 'expr' === strtolower( (string) $condition['test'] ) ) {
            return array( 'result' => null, 'captures' => null, 'client' => true );
        }
        if ( self::is_opaque( $test ) ) {
            $known  = self::OPAQUE_PATTERNS[ $pattern ] ?? null;
            $result = null === $known ? null : ( 'all' === $known[0] ? true : $test['nonempty'] );
            if ( null === $result ) {
                return array( 'result' => null, 'captures' => null, 'client' => $test['client'] );
            }
            $groups = self::opaque_groups( $pattern, $known[1], $test );
        } elseif ( in_array( $pattern, array( '-f', '-d', '-s' ), true ) ) {
            $result = $this->exists( $test, $pattern[1] );
        } elseif ( '' !== $pattern && '-' === $pattern[0] ) {
            $result = null;
        } elseif ( preg_match( '/^(<=|>=|=|<|>)(.*)$/s', $pattern, $lexical ) ) {
            $result = self::compare( $test, $lexical[1], trim( $lexical[2], '"' ), $condition['nocase'] );
        } else {
            $regex  = self::regex( $pattern, $condition['nocase'] );
            $found  = null === $regex ? false : @preg_match( $regex, $test, $groups ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A pattern PHP cannot compile is an unknown outcome, not a warning.
            $result = false === $found ? null : 1 === $found;
            $groups = is_array( $groups ) ? array_map( 'strval', $groups ) : null;
        }
        if ( null === $result ) {
            return array( 'result' => null, 'captures' => null, 'client' => false );
        }
        return array(
            'result'   => $negated ? ! $result : $result,
            'captures' => ( $result && ! $negated ) ? $groups : null,
            'client'   => false,
        );
    }

    private static function compare( string $test, string $operator, string $value, bool $nocase ): bool {
        $order = $nocase ? strcasecmp( $test, $value ) : strcmp( $test, $value );
        switch ( $operator ) {
            case '=':
                return 0 === $order;
            case '<':
                return $order < 0;
            case '>':
                return $order > 0;
            case '<=':
                return $order <= 0;
            default:
                return $order >= 0;
        }
    }

    private static function regex( string $pattern, bool $nocase ): ?string {
        $regex = "\x01" . $pattern . "\x01" . ( $nocase ? 'i' : '' );
        return false === @preg_match( $regex, '' ) ? null : $regex; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing whether the pattern compiles.
    }

    private function apply( array $rule, array $state, array $captures, ?array $condition_captures ): ?array {
        $flags = $rule['flags'];
        $line  = (int) $rule['line'];

        foreach ( $flags['e'] ?? array() as $assignment ) {
            $state = $this->assign( $state, $assignment, $captures, $condition_captures );
        }

        if ( isset( $flags['f'] ) || isset( $flags['g'] ) || isset( $flags['p'] ) ) {
            $this->record( self::BEFORE, array( $line ), $state['client'], array() !== $state['forks'] );
            return null;
        }
        if ( isset( $flags['n'] ) ) {
            $this->record( self::UNSURE, array_merge( $state['stops'], $state['forks'], array( $line ) ), $state['client'] );
            return null;
        }
        $redirect = isset( $flags['r'] );
        if ( $redirect ) {
            $code = '' === $flags['r'][0] ? 302 : (int) $flags['r'][0];
            if ( $code < 300 || $code > 399 ) {
                $this->record( self::BEFORE, array( $line ), $state['client'], array() !== $state['forks'] );
                return null;
            }
            $state['redirect'] = $line;
        }

        $substitution = (string) $rule['substitution'];
        if ( '-' !== $substitution && ! $redirect ) {
            $target = $this->expand( $substitution, $state, $captures, $condition_captures );
            $moved  = self::is_opaque( $target ) ? null : $this->move( $state, $target, isset( $flags['qsa'] ) );
            if ( null === $moved ) {
                $client = $state['client'] || ( self::is_opaque( $target ) && $target['client'] );
                $this->record( self::UNSURE, array_merge( $state['stops'], $state['forks'], array( $line ) ), $client );
                return null;
            }
            if ( 'redirect' === $moved ) {
                $state['redirect'] = $line;
            } else {
                $state = $moved;
            }
        }

        return array(
            'state'     => $state,
            'ends_pass' => isset( $flags['l'] ) || isset( $flags['end'] ) || isset( $flags['pt'] ),
            'end_flag'  => isset( $flags['end'] ) && ! isset( $flags['pt'] ),
            'skip'      => isset( $flags['s'] ) ? max( 0, (int) $flags['s'][0] ) : 0,
        );
    }

    private function assign( array $state, string $assignment, array $captures, ?array $condition_captures ): array {
        $pieces = $this->expand_pieces( $assignment, $state, $captures, $condition_captures );
        $client = false;
        foreach ( $pieces as $piece ) {
            $client = $client || ( self::is_opaque( $piece ) && $piece['client'] );
        }
        $name = '';
        $rest = null;
        foreach ( $pieces as $piece ) {
            if ( self::is_opaque( $piece ) ) {
                if ( null === $rest ) {
                    return self::assign_unknown( $state, $client );
                }
                $rest[] = $piece;
            } elseif ( null !== $rest ) {
                $rest[] = $piece;
            } else {
                $colon = strpos( $piece, ':' );
                $name .= false === $colon ? $piece : substr( $piece, 0, $colon );
                if ( false !== $colon ) {
                    $rest = array( (string) substr( $piece, $colon + 1 ) );
                }
            }
        }
        if ( '' !== $name && '!' === $name[0] ) {
            unset( $state['env'][ substr( $name, 1 ) . ( null === $rest ? '' : ':' . self::text_of( $rest ) ) ] );
            return $state;
        }
        $state['env'][ $name ] = null === $rest ? '' : self::combine( $rest );
        return $state;
    }

    private static function assign_unknown( array $state, bool $client ): array {
        foreach ( array_unique( array_merge( array_keys( $state['env'] ), self::HEADER_VARIABLES ) ) as $variable ) {
            $before                    = $state['env'][ $variable ] ?? '';
            $state['env'][ $variable ] = self::opaque( null, $client || ( self::is_opaque( $before ) && $before['client'] ), null );
        }
        $state['wild'] = ! empty( $state['wild'] ) || $client;
        return $state;
    }

    private static function text_of( array $pieces ): string {
        $text = '';
        foreach ( $pieces as $piece ) {
            $text .= self::is_opaque( $piece ) ? '' : $piece;
        }
        return $text;
    }

    private function move( array $state, string $target, bool $append_query ) {
        if ( preg_match( '#^([a-z][a-z0-9+.-]*)://([^/?\#]*)(.*)$#i', $target, $url ) ) {
            if ( null === ( $this->site['https'] ?? null ) && strtolower( $url[2] ) === strtolower( $this->http_host() ) ) {
                $this->connection_unknown = true;
                return null;
            }
            $scheme = ! empty( $this->site['https'] ) ? 'https' : 'http';
            if ( strtolower( $url[1] ) !== $scheme || strtolower( $url[2] ) !== strtolower( $this->http_host() ) ) {
                return 'redirect';
            }
            $url = array( $url[0], $url[2], $url[3] );
            $target = '' === $url[2] ? '/' : $url[2];
        }
        $query = null;
        $mark  = strpos( $target, '?' );
        if ( false !== $mark ) {
            $query  = substr( $target, $mark + 1 );
            $target = substr( $target, 0, $mark );
        }
        $state['subject'] = $target;
        if ( null !== $query ) {
            $state['query'] = $append_query && '' !== $state['query'] ? $query . '&' . $state['query'] : $query;
        }
        return $state;
    }

    private static function opaque( ?bool $nonempty, bool $client, ?bool $whole = false ): array {
        return array( 'opaque' => true, 'nonempty' => $nonempty, 'client' => $client, 'whole' => $whole );
    }

    private static function is_opaque( $value ): bool {
        return is_array( $value );
    }

    private static function is_header( $value ): ?bool {
        return self::is_opaque( $value ) ? $value['whole'] : false;
    }

    private function expand( string $text, array $state, array $captures, ?array $condition_captures ) {
        return self::combine( $this->expand_pieces( $text, $state, $captures, $condition_captures ) );
    }

    private function expand_pieces( string $text, array $state, array $captures, ?array $condition_captures ): array {
        $pieces = array();
        $parts  = preg_split( '/(\\\\[$%]|\$\d|%\d|%\{[^}]+\}|\$\{[^}]*\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
        foreach ( false === $parts ? array( $text ) : $parts as $part ) {
            if ( '\\' === $part[0] && 2 === strlen( $part ) ) {
                $value = $part[1];
            } elseif ( '$' === $part[0] && isset( $part[1] ) && ctype_digit( $part[1] ) && 2 === strlen( $part ) ) {
                $value = $captures[ (int) $part[1] ] ?? '';
            } elseif ( '%' === $part[0] && isset( $part[1] ) && ctype_digit( $part[1] ) && 2 === strlen( $part ) ) {
                $value = null === $condition_captures ? self::opaque( null, true, null ) : ( $condition_captures[ (int) $part[1] ] ?? '' );
            } elseif ( 0 === strpos( $part, '%{' ) && '}' === substr( $part, -1 ) ) {
                $value = $this->variable( substr( $part, 2, -1 ), $state );
            } elseif ( 0 === strpos( $part, '${' ) && '}' === substr( $part, -1 ) ) {
                $value = self::opaque( null, true, null );
            } else {
                $value = $part;
            }
            $last = count( $pieces ) - 1;
            if ( ! self::is_opaque( $value ) && $last >= 0 && ! self::is_opaque( $pieces[ $last ] ) ) {
                $pieces[ $last ] .= $value;
            } else {
                $pieces[] = $value;
            }
        }
        return $pieces;
    }

    private static function combine( array $pieces ) {
        $pieces = array_values(
            array_filter(
                $pieces,
                static function ( $piece ): bool {
                    return self::is_opaque( $piece ) || '' !== $piece;
                }
            )
        );
        $text   = '';
        $opaque = array();
        foreach ( $pieces as $piece ) {
            if ( self::is_opaque( $piece ) ) {
                $opaque[] = $piece;
            } else {
                $text .= $piece;
            }
        }
        if ( array() === $opaque ) {
            return $text;
        }
        if ( 1 === count( $pieces ) ) {
            return $opaque[0];
        }
        $nonempty = '' !== $text;
        $client = '' !== $text;
        foreach ( $opaque as $piece ) {
            $nonempty = self::either( $nonempty, $piece['nonempty'] );
            $client   = $client || $piece['client'];
        }
        return self::opaque( $nonempty, $client, self::whole_of( $text, $opaque ) );
    }

    private static function whole_of( string $text, array $opaque ): ?bool {
        $headers = array();
        $others  = array();
        foreach ( $opaque as $piece ) {
            if ( true === $piece['whole'] ) {
                $headers[] = $piece;
            } else {
                $others[] = $piece;
            }
        }
        if ( array() !== $headers ) {
            if ( '' !== $text || count( $headers ) > 1 ) {
                return false;
            }
            $beside = false;
            foreach ( $others as $piece ) {
                $beside = self::either( $beside, $piece['nonempty'] );
            }
            return null === $beside ? null : ! $beside;
        }
        foreach ( $opaque as $piece ) {
            if ( null === $piece['whole'] ) {
                return null;
            }
        }
        return false;
    }

    private function variable( string $name, array $state ) {
        $https = ! empty( $this->site['https'] );
        $upper = strtoupper( $name );
        if ( 0 === strpos( $upper, 'HTTP:' ) ) {
            return $this->header( substr( $name, 5 ) );
        }
        if ( 0 === strpos( $upper, 'ENV:' ) ) {
            $env = substr( $name, 4 );
            if ( ! array_key_exists( $env, $state['env'] ) && in_array( $env, array( 'REDIRECT_STATUS', 'REDIRECT_URL' ), true ) && empty( $state['wild'] ) ) {
                return '';
            }
            return array_key_exists( $env, $state['env'] ) ? $state['env'][ $env ] : self::opaque( null, ! empty( $state['wild'] ), null );
        }
        $original = self::base_of( $this->site ) . ltrim( (string) ( $this->request['path'] ?? '' ), '/' );
        $query    = (string) ( $this->request['query'] ?? '' );

        switch ( $upper ) {
            case 'REQUEST_METHOD':
                return strtoupper( (string) ( $this->request['method'] ?? '' ) );
            case 'REQUEST_URI':
                return (string) $state['uri'];
            case 'QUERY_STRING':
                return (string) $state['query'];
            case 'HTTPS':
            case 'REQUEST_SCHEME':
                if ( null === ( $this->site['https'] ?? null ) ) {
                    $this->connection_unknown = true;
                    return self::opaque( null, false, false );
                }
                if ( 'HTTPS' === $upper ) {
                    return $https ? 'on' : 'off';
                }
                return $https ? 'https' : 'http';
            case 'SERVER_PORT':
                if ( null === ( $this->site['port'] ?? null ) && null === ( $this->site['https'] ?? null ) ) {
                    $this->connection_unknown = true;
                    return self::opaque( null, false, false );
                }
                return (string) $this->port();
            case 'HTTP_HOST':
                return $this->http_host();
            case 'SERVER_NAME':
                return (string) $this->site['host'];
            case 'REQUEST_FILENAME':
            case 'SCRIPT_FILENAME':
                $subject = (string) $state['subject'];
                return '/' === ( $subject[0] ?? '' ) ? $subject : $this->filename( $subject );
            case 'DOCUMENT_ROOT':
                return $this->document_root() ?? self::opaque( null, false, false );
            case 'THE_REQUEST':
                return strtoupper( (string) ( $this->request['method'] ?? '' ) ) . ' ' . $original . ( '' === $query ? '' : '?' . $query ) . ' HTTP/1.1';
            case 'IS_SUBREQ':
                return 'false';
            case 'HTTP_COOKIE':
                return $this->header( 'cookie' );
            case 'HTTP_USER_AGENT':
                return $this->header( 'user-agent' );
            case 'HTTP_REFERER':
                return $this->header( 'referer' );
            case 'HTTP_ACCEPT':
                return $this->header( 'accept' );
            default:
                return self::opaque( null, true, false );
        }
    }

    private function header( string $name ) {
        $name = strtolower( trim( $name ) );
        if ( 'authorization' === $name ) {
            return self::opaque( true, true, true );
        }
        $headers = (array) ( $this->request['headers'] ?? array() );
        return array_key_exists( $name, $headers ) ? (string) $headers[ $name ] : self::opaque( null, true, false );
    }

    private function port(): int {
        if ( isset( $this->site['port'] ) && null !== $this->site['port'] ) {
            return (int) $this->site['port'];
        }
        return ! empty( $this->site['https'] ) ? 443 : 80;
    }

    private function http_host(): string {
        $default = ! empty( $this->site['url_https'] ?? $this->site['https'] ) ? 443 : 80;
        $port    = isset( $this->site['port'] ) ? (int) $this->site['port'] : $default;
        return (string) $this->site['host'] . ( $default === $port ? '' : ':' . $port );
    }

    private function folder(): string {
        return rtrim( (string) ( $this->site['document_root'] ?? '' ), '/\\' );
    }

    private function filename( string $path ): string {
        return $this->folder() . '/' . $path;
    }

    private function document_root(): ?string {
        if ( isset( $this->site['server_document_root'] ) ) {
            return rtrim( (string) $this->site['server_document_root'], '/\\' );
        }
        return '/' === self::base_of( $this->site ) ? $this->folder() : null;
    }

    private function exists( string $absolute, string $kind ): ?bool {
        $folder = $this->folder();
        if ( 'd' !== $kind && $absolute === $folder . '/index.php' ) {
            return true;
        }
        if ( isset( $this->site['files'] ) || isset( $this->site['dirs'] ) ) {
            if ( rtrim( $absolute, '/' ) === $folder ) {
                return 'd' === $kind;
            }
            if ( 0 !== strpos( $absolute, $folder . '/' ) ) {
                return false;
            }
            $relative = trim( substr( $absolute, strlen( $folder ) + 1 ), '/' );
            if ( 'd' === $kind ) {
                return in_array( $relative, (array) ( $this->site['dirs'] ?? array() ), true );
            }
            $listed = in_array( $relative, (array) ( $this->site['files'] ?? array() ), true );
            return ( 's' === $kind && $listed ) ? null : $listed;
        }
        return self::stat(
            static function () use ( $absolute, $kind ): bool {
                if ( 'd' === $kind ) {
                    return is_dir( $absolute );
                }
                return is_file( $absolute ) && ( 's' !== $kind || filesize( $absolute ) > 0 );
            }
        );
    }

    private static function stat( callable $check ): ?bool {
        $refused = false;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped to one stat call and restored at once; it reads PHP's open_basedir refusal.
        set_error_handler(
            static function ( int $level, string $message ) use ( &$refused ): bool {
                $refused = $refused || false !== stripos( $message, 'open_basedir' );
                return true;
            }
        );
        try {
            $result = $check();
        } finally {
            restore_error_handler();
        }
        return $refused ? null : $result;
    }
}
