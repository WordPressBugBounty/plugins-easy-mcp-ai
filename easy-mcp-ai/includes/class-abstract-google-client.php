<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Abstract_Google_Client {

    const CIPHER_VERSION = "v2\x00";
    const CIPHER_PREFIX_LEN = 3;

    abstract protected static function product_name(): string;

    abstract protected static function admin_access_remedy( string $client_email ): string;

    protected static function derive_key_v2(): string {
        return hash_hkdf( 'sha256', SECURE_AUTH_KEY . SECURE_AUTH_SALT, 32, static::HKDF_INFO );
    }

    public static function encrypt( string $plaintext ): string {
        $key = static::derive_key_v2();
        $iv  = random_bytes( 12 );
        $tag = '';
        $ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        return base64_encode( static::CIPHER_VERSION . $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- AES-256-GCM ciphertext encoding for safe storage in wp_options.
    }

    public static function decrypt( string $encoded ) {
        $raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes AES-256-GCM ciphertext stored in wp_options.
        if ( false === $raw ) {
            return false;
        }
        if ( strlen( $raw ) <= self::CIPHER_PREFIX_LEN + 28 ) {
            return false;
        }
        $prefix = substr( $raw, 0, self::CIPHER_PREFIX_LEN );
        if ( static::CIPHER_VERSION !== $prefix ) {
            return false;
        }
        $key = static::derive_key_v2();
        $raw = substr( $raw, self::CIPHER_PREFIX_LEN );
        $iv  = substr( $raw, 0, 12 );
        $tag = substr( $raw, 12, 16 );
        $ct  = substr( $raw, 28 );
        return openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
    }

    protected static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64URL encoding required by JWT (RFC 7515) for header/payload/signature segments.
    }

    protected static function token_transient_key( string $client_email, string $scope = '' ): string {
        $key = static::TRANSIENT_TOKEN . '_' . md5( $client_email );
        if ( '' !== $scope && static::SCOPE !== $scope ) {
            $key .= '_' . md5( $scope );
        }
        return $key;
    }

    public static function get_credentials(): array {
        $deployed = Provider_Credentials::google_key( static::PROVIDER );
        if ( null !== $deployed ) {
            return $deployed;
        }
        $stored = \get_option( static::OPTION_JSON, '' );
        if ( empty( $stored ) ) {
            throw new \RuntimeException(
                static::product_name() . ' credentials not configured. Go to Easy MCP AI → Tools → Connections.' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }
        $json = static::decrypt( $stored );
        if ( false === $json ) {
            throw new \RuntimeException(
                'Failed to decrypt ' . static::product_name() . ' credentials. This usually means your WordPress security salts (SECURE_AUTH_KEY/SECURE_AUTH_SALT) have been rotated since the key was saved. Re-save the service account JSON in Easy MCP AI → Tools → Connections.' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }
        $creds = json_decode( $json, true );
        if ( ! is_array( $creds ) ) {
            throw new \RuntimeException(
                'Service account JSON is invalid. Re-paste the JSON from Google Cloud Console.'
            );
        }
        foreach ( array( 'private_key', 'client_email', 'token_uri' ) as $field ) {
            if ( empty( $creds[ $field ] ) ) {
                throw new \RuntimeException(
                    "Service account JSON is missing required field: {$field}." // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                );
            }
        }
        if ( 'https://oauth2.googleapis.com/token' !== $creds['token_uri'] ) {
            throw new \RuntimeException(
                'Service account JSON contains an unexpected token_uri. Only https://oauth2.googleapis.com/token is accepted.'
            );
        }
        return $creds;
    }

    public static function get_access_token( string $scope = '' ): string {
        $scope         = '' === $scope ? static::SCOPE : $scope;
        $creds         = static::get_credentials();
        $transient_key = static::token_transient_key( $creds['client_email'], $scope );

        $cached = \get_transient( $transient_key );
        if ( ! empty( $cached ) ) {
            $plain = static::decrypt( $cached );
            if ( false !== $plain && '' !== $plain ) {
                return $plain;
            }
            \delete_transient( $transient_key );
        }

        $now = time();
        $exp = $now + 3600;

        $header  = static::base64url_encode( json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- See comment above.
        $payload = static::base64url_encode( json_encode( array( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- See comment above.
            'iss'   => $creds['client_email'],
            'scope' => $scope,
            'aud'   => $creds['token_uri'],
            'exp'   => $exp,
            'iat'   => $now,
        ) ) );
        $signing_input = $header . '.' . $payload;

        $signature = '';
        $ok = openssl_sign( $signing_input, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256 );
        if ( ! $ok ) {
            throw new \RuntimeException(
                'Failed to sign JWT. Check that the private_key in your service account JSON is valid.'
            );
        }
        $jwt = $signing_input . '.' . static::base64url_encode( $signature );

        $response = \wp_remote_post( $creds['token_uri'], array(
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
            'timeout' => 15,
        ) );

        if ( \is_wp_error( $response ) ) {
            throw new \RuntimeException(
                'Google token request failed (transport error): ' . $response->get_error_message() // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }

        $body = json_decode( \wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            $err = $body['error_description'] ?? $body['error'] ?? 'unknown error';
            throw new \RuntimeException( 'Failed to obtain Google access token: ' . rtrim( (string) $err, '.' ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $ttl = max( 60, ( $body['expires_in'] ?? 3600 ) - 60 );
        \set_transient( $transient_key, static::encrypt( $body['access_token'] ), $ttl );

        return $body['access_token'];
    }

    protected static function token_for( string $scope, bool $admin_context ): string {
        try {
            return static::get_access_token( $scope );
        } catch ( \RuntimeException $e ) {
            if ( $admin_context ) {
                throw $e;
            }
            throw static::refusal( '', static::redact( $e->getMessage() ) ?? 'Google refused the access token. ' . self::WITHHELD );
        }
    }

    const GRANT_REMEDY = 'A site administrator can grant access in Easy MCP AI → Tools → Connections → Test connection.';

    const HIDDEN = '[hidden]';

    private const REDACT_LIMIT = 4000;

    private const EMAIL_DOMAIN = '/\A[\p{L}\p{N}](?:[\p{L}\p{N}\p{M}\-]*[\p{L}\p{N}\p{M}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}\p{M}\-]*[\p{L}\p{N}\p{M}])?)++/u';

    private const EMAIL_RUN = '/[\p{L}\p{N}\p{M}.!#$%&\'*+^_`{|}~\-@]++/u';

    private const LOCAL_LEAD = ".!#$%&'*+^`{|}~";

    const WITHHELD = "Google's message is not shown: it could not be checked for account details.";

    protected static function throw_read_refusal( $decoded, bool $admin_context ): void {
        $access = ! static::is_platform_refusal( $decoded );
        $prefix = static::product_name() . ' refused the request: ';
        $reason = static::google_reason( 403, $decoded, $admin_context );

        if ( ! $access ) {
            throw static::refusal( $prefix, $reason, '', $admin_context );
        }
        if ( ! $admin_context ) {
            throw static::refusal( $prefix, $reason, self::GRANT_REMEDY );
        }
        $creds = static::get_credentials();
        throw static::refusal( $prefix, $reason, static::admin_access_remedy( (string) $creds['client_email'] ), true );
    }

    protected static function google_reason( int $code, $decoded, bool $admin_context ): string {
        $google = static::google_message( $decoded );
        if ( '' === $google ) {
            return "HTTP {$code}.";
        }
        if ( $admin_context ) {
            return $google;
        }
        $reason = static::redact( $google );
        return null === $reason ? "HTTP {$code}. " . self::WITHHELD : $reason;
    }

    protected static function refusal( string $prefix, string $reason, string $remedy = '', bool $admin_context = false ): \RuntimeException {
        if ( $admin_context ) {
            return new \RuntimeException( rtrim( $prefix . $reason . ' ' . $remedy ) );
        }
        if ( \Easy_MCP_AI\MCP\Refusal_Text::fits( $prefix . $reason ) ) {
            return new \Easy_MCP_AI\MCP\Detailed_Runtime_Error( $prefix . $reason, $remedy );
        }
        return new \Easy_MCP_AI\MCP\Detailed_Runtime_Error(
            \Easy_MCP_AI\MCP\Refusal_Text::fit( $prefix . '%s', array( $reason ) ),
            \Easy_MCP_AI\MCP\Refusal_Text::fit( rtrim( $prefix . '%s ' . $remedy ), array( $reason ), 0, \Easy_MCP_AI\MCP\Detailed_Tool_Error::MAX_DETAILS )
        );
    }

    protected static function quota_refusal( int $code, $decoded, string $fallback, bool $admin_context ): \RuntimeException {
        if ( '' === static::google_message( $decoded ) ) {
            return new \RuntimeException( $fallback );
        }
        return static::refusal( static::product_name() . ' refused the request: ', static::google_reason( $code, $decoded, $admin_context ), 'Retry after the quota resets.', $admin_context );
    }

    protected static function google_message( $decoded ): string {
        if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
            return trim( $decoded['error']['message'] );
        }
        return '';
    }

    const ACCESS_REASONS = array( 'forbidden', 'insufficientPermissions' );

    protected static function is_platform_refusal( $decoded ): bool {
        if ( ! is_array( $decoded ) || ! isset( $decoded['error'] ) || ! is_array( $decoded['error'] ) ) {
            return false;
        }
        foreach ( (array) ( $decoded['error']['details'] ?? array() ) as $detail ) {
            if ( is_array( $detail )
                && isset( $detail['@type'], $detail['domain'] )
                && 'type.googleapis.com/google.rpc.ErrorInfo' === $detail['@type']
                && 'googleapis.com' === $detail['domain'] ) {
                return true;
            }
        }
        foreach ( (array) ( $decoded['error']['errors'] ?? array() ) as $error ) {
            if ( is_array( $error ) && isset( $error['reason'] ) && is_string( $error['reason'] ) && ! in_array( $error['reason'], self::ACCESS_REASONS, true ) ) {
                return true;
            }
        }
        return false;
    }

    protected static function redact( string $text ): ?string {
        if ( strlen( $text ) > self::REDACT_LIMIT ) {
            $space = strrpos( substr( $text, 0, self::REDACT_LIMIT ), ' ' );
            if ( false === $space ) {
                return null;
            }
            $text = rtrim( substr( $text, 0, $space ) ) . ' …';
        }
        $text = static::hide_emails( $text );
        if ( null === $text ) {
            return null;
        }
        $rules = array(
            '/\?project=[^\s&#"\'<>?]*+&/i'      => '?',
            '/[?&]project=[^\s&#"\'<>?]*+/i'      => '',
            '#\bprojects/(?:[\p{L}\p{N}\-]++(?:\.[\p{L}\p{N}\-]++)++:)?+[\p{L}\p{N}_\-]++#iu' => 'projects/' . self::HIDDEN,
            '/\b(projects?(?:[\s_-]++(?:number|id))?[\s#:=(\[{"\'`]++)\d{3,}/i' => '${1}' . self::HIDDEN,
        );
        try {
            $creds = static::get_credentials();
        } catch ( \RuntimeException $e ) {
            $creds = array();
        }
        foreach ( array( 'project_id', 'client_id', 'private_key_id' ) as $field ) {
            if ( isset( $creds[ $field ] ) && is_string( $creds[ $field ] ) && strlen( $creds[ $field ] ) >= 3 ) {
                $rules[ '/(?<![\p{L}\p{N}_-])' . preg_quote( $creds[ $field ], '/' ) . '(?![\p{L}\p{N}_-])/iu' ] = self::HIDDEN;
            }
        }
        return preg_replace( array_keys( $rules ), array_values( $rules ), $text );
    }

    protected static function hide_emails( string $text ): ?string {
        $failed = false;
        $text   = preg_replace_callback(
            self::EMAIL_RUN,
            static function ( array $run ) use ( &$failed ): string {
                $parts = explode( '@', $run[0] );
                $out   = '';
                $rest  = $parts[0];
                for ( $i = 1, $n = count( $parts ); $i < $n; $i++ ) {
                    $local = ltrim( $rest, self::LOCAL_LEAD );
                    if ( '' === $local ) {
                        $local = ltrim( $rest, ".'`" );
                    }
                    if ( '' === $local && '' !== trim( $rest, '.' ) ) {
                        $local = ltrim( $rest, '.' );
                    }
                    $found = preg_match( self::EMAIL_DOMAIN, $parts[ $i ], $match );
                    if ( false === $found ) {
                        $failed = true;
                        return '';
                    }
                    $domain = $found ? $match[0] : '';
                    if ( '' === $local || '' === $domain ) {
                        $out .= $rest . '@';
                        $rest = $parts[ $i ];
                        continue;
                    }
                    $out .= substr( $rest, 0, strlen( $rest ) - strlen( $local ) ) . self::HIDDEN;
                    $rest = (string) substr( $parts[ $i ], strlen( $domain ) );
                }
                return $out . $rest;
            },
            $text
        );
        return $failed ? null : $text;
    }
}
