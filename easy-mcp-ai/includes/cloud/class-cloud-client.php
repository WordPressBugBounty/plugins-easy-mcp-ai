<?php

namespace Easy_MCP_AI\Cloud;

use Easy_MCP_AI\Option_Lock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cloud_Client {

    const DEFAULT_BASE = 'https://mcp.easymcpai.com/mcp-plugin/v1';

    const REGISTER_LOCK = 'easy_mcp_ai_cloud_register';

    const MESSAGE_OFF     = 'EasyMCP Cloud tools are turned off on this site.';
    const MESSAGE_REFUSED = 'EasyMCP Cloud refused the request.';
    const MESSAGE_NO_SALTS = 'EasyMCP Cloud tools can\'t run: wp-config.php needs unique SECURE_AUTH_KEY and SECURE_AUTH_SALT (neither empty nor the placeholder, 64 characters or more together) and PHP needs the openssl extension.';
    const MESSAGE_LATER   = 'EasyMCP Cloud can\'t build right now. Try again later.';

    const MAX_RESPONSE_BYTES = 8388608;

    const BUDGET_SECONDS = 28;
    const MIN_SECONDS    = 2;
    const LOCK_WAIT      = 5;

    private $clock;

    public function __construct( ?callable $clock = null ) {
        $this->clock = $clock ?? 'microtime';
    }

    public function base_url(): string {
        $base = defined( 'EASY_MCP_AI_CLOUD_URL' ) && is_string( EASY_MCP_AI_CLOUD_URL ) && '' !== EASY_MCP_AI_CLOUD_URL
            ? EASY_MCP_AI_CLOUD_URL
            : self::DEFAULT_BASE;
        return rtrim( $base, '/' );
    }

    public function execute( string $code ): array {
        return $this->call(
            'POST',
            '/execute',
            array(
                'code'           => $code,
                'site_url'       => \home_url(),
                'proof_url'      => self::proof_url(),
                'plugin_version' => EASY_MCP_AI_VERSION,
            ),
            25
        );
    }

    public function schema( array $args ): array {
        return $this->call( 'POST', '/schema', $args, 10 );
    }

    public function atomic_wind_context(): array {
        return $this->call( 'GET', '/atomic-wind-context', null, 10 );
    }

    private function call( string $method, string $path, ?array $body, int $timeout ): array {
        if ( ! Cloud_Tools::any_enabled() ) {
            throw new Cloud_Error( self::MESSAGE_OFF );
        }
        if ( ! Cloud_Credentials::can_encrypt() ) {
            throw new Cloud_Error( self::MESSAGE_NO_SALTS );
        }
        $started = (float) call_user_func( $this->clock, true );
        $key     = Cloud_Credentials::key();
        if ( '' === $key ) {
            $key = $this->obtain_key( '', $started );
        }
        $answer = $this->send( $method, $path, $body, $this->timeout_within_budget( $started, $timeout ), $key );
        if ( $this->asks_to_register_again( $answer ) ) {
            $key    = $this->obtain_key( $key, $started );
            $answer = $this->send( $method, $path, $body, $this->timeout_within_budget( $started, $timeout ), $key );
        }
        return $this->decide( $answer );
    }

    private function timeout_within_budget( float $started, int $nominal ): int {
        $left = self::BUDGET_SECONDS - ( (float) call_user_func( $this->clock, true ) - $started );
        if ( $left < self::MIN_SECONDS ) {
            throw new Cloud_Error( self::MESSAGE_LATER );
        }
        return min( $nominal, (int) floor( $left ) );
    }

    private function decide( array $answer ): array {
        $json   = $answer['json'];
        $status = $answer['status'];
        $code   = is_array( $json ) && isset( $json['error'] ) && is_string( $json['error'] ) ? $json['error'] : '';
        if ( 200 === $status && is_array( $json ) ) {
            if ( isset( $json['site_id'] ) && is_string( $json['site_id'] ) && 1 === preg_match( Cloud_Credentials::SITE_ID_PATTERN, $json['site_id'] ) && Cloud_Credentials::site_id() !== $json['site_id'] ) {
                Cloud_Credentials::set_site_id( $json['site_id'] );
            }
            return $json;
        }
        if ( 400 === $status && 'invalid_request' === $code ) {
            throw new Cloud_Error( isset( $json['message'] ) && is_string( $json['message'] ) && '' !== $json['message'] ? $json['message'] : self::MESSAGE_REFUSED );
        }
        if ( 502 === $status && 'build_failed' === $code && isset( $json['message'] ) && is_string( $json['message'] ) && '' !== $json['message'] ) {
            throw new Cloud_Error( $json['message'] );
        }
        throw new Cloud_Error( self::MESSAGE_LATER );
    }

    private function asks_to_register_again( array $answer ): bool {
        $json = $answer['json'];
        $code = is_array( $json ) && isset( $json['error'] ) && is_string( $json['error'] ) ? $json['error'] : '';
        return ( 401 === $answer['status'] && 'unauthorized' === $code ) || ( 409 === $answer['status'] && 'register_again' === $code );
    }

    private function obtain_key( string $refused, float $started ): string {
        $wait = min( self::LOCK_WAIT, (int) floor( self::BUDGET_SECONDS - ( (float) call_user_func( $this->clock, true ) - $started ) ) );
        if ( $wait < self::MIN_SECONDS ) {
            throw new Cloud_Error( self::MESSAGE_LATER );
        }
        try {
            $lock = Option_Lock::acquire_waiting( self::REGISTER_LOCK, 30, $wait, 'EasyMCP Cloud registration' );
        } catch ( \RuntimeException $e ) {
            throw new Cloud_Error( self::MESSAGE_LATER );
        }
        try {
            $stored = Cloud_Credentials::fresh_key();
            if ( '' !== $stored && $stored !== $refused ) {
                return $stored;
            }
            $timeout = $this->timeout_within_budget( $started, 10 );
            if ( '' !== $stored ) {
                Cloud_Credentials::forget();
            }
            $answer = $this->send(
                'POST',
                '/register',
                array(
                    'site_url'       => \home_url(),
                    'proof_url'      => self::proof_url(),
                    'plugin_version' => EASY_MCP_AI_VERSION,
                    'wp_version'     => \get_bloginfo( 'version' ),
                ),
                $timeout,
                ''
            );
            $json = $answer['json'];
            if (
                201 !== $answer['status']
                || ! is_array( $json )
                || ! isset( $json['site_id'], $json['key'] )
                || ! is_string( $json['site_id'] )
                || 1 !== preg_match( Cloud_Credentials::SITE_ID_PATTERN, $json['site_id'] )
                || ! is_string( $json['key'] )
                || 1 !== preg_match( Cloud_Credentials::KEY_PATTERN, $json['key'] )
            ) {
                throw new Cloud_Error( self::MESSAGE_LATER );
            }
            Cloud_Credentials::store( $json['site_id'], $json['key'] );
            if ( Cloud_Credentials::fresh_key() !== $json['key'] ) {
                throw new Cloud_Error( self::MESSAGE_LATER );
            }
            return $json['key'];
        } finally {
            Option_Lock::release( self::REGISTER_LOCK, $lock );
        }
    }

    private function send( string $method, string $path, ?array $body, int $timeout, string $key ): array {
        $headers = array( 'Accept' => 'application/json' );
        if ( 'POST' === $method ) {
            $headers['Content-Type'] = 'application/json';
        }
        if ( '' !== $key ) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        $args = array(
            'method'              => $method,
            'timeout'             => $timeout,
            'redirection'         => 0,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'user-agent'          => 'EasyMCP-Plugin/' . EASY_MCP_AI_VERSION,
            'headers'             => $headers,
        );
        if ( null !== $body ) {
            $args['body'] = \wp_json_encode( (object) $body );
        }
        $response = \wp_remote_request( $this->base_url() . $path, $args );
        if ( \is_wp_error( $response ) ) {
            throw new Cloud_Error( self::MESSAGE_LATER );
        }
        $decoded = json_decode( (string) \wp_remote_retrieve_body( $response ), true );
        return array(
            'status' => (int) \wp_remote_retrieve_response_code( $response ),
            'json'   => is_array( $decoded ) ? $decoded : null,
        );
    }

    private static function proof_url(): string {
        return \rest_url( Cloud_Proof_Route::NAMESPACE_V1 . Cloud_Proof_Route::PATH );
    }
}
