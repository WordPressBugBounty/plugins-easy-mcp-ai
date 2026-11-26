<?php
namespace Easy_MCP_AI\GSC;

use Easy_MCP_AI\Abstract_Google_Client;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GSC_Client extends Abstract_Google_Client {

    const OPTION_JSON     = 'easy_mcp_ai_gsc_service_account_json';
    const PROVIDER        = 'gsc';
    const OPTION_SITE_URL = 'easy_mcp_ai_gsc_default_site_url';
    const TRANSIENT_TOKEN = 'easy_mcp_ai_gsc_token';
    const SCOPE           = 'https://www.googleapis.com/auth/webmasters.readonly';
    const SCOPE_EDIT      = 'https://www.googleapis.com/auth/webmasters';
    const HKDF_INFO       = 'easy_mcp_ai_gsc_creds_v1';

    protected static function product_name(): string {
        return 'Google Search Console';
    }

    protected static function admin_access_remedy( string $client_email ): string {
        return "Add the service account ({$client_email}) as a user in Search Console → Settings → Users & permissions.";
    }

    protected static function token_transient_key( string $client_email, string $scope = '' ): string {
        return static::TRANSIENT_TOKEN . '_' . md5( $client_email ) . '_' . md5( '' === $scope ? static::SCOPE : $scope );
    }

    public static function validate_site_url( string $value ): string {
        $trimmed = trim( $value );
        if ( '' === $trimmed ) {
            throw new \InvalidArgumentException( 'site_url is required.' );
        }
        if ( ! preg_match( '#^(https?://\S+|sc-domain:[A-Za-z0-9.\-]+)$#i', $trimmed ) ) {
            throw new \InvalidArgumentException( 'site_url must be a full http(s) URL or "sc-domain:example.com".' );
        }
        return $trimmed;
    }

    public static function request( string $method, string $url, ?array $body = null, bool $admin_context = false, bool $edit = false ): array {
        $scheme = \wp_parse_url( $url, PHP_URL_SCHEME );
        $host   = \wp_parse_url( $url, PHP_URL_HOST );
        $allowed_hosts = array(
            'www.googleapis.com',
            'oauth2.googleapis.com',
            'searchconsole.googleapis.com',
        );
        if ( 'https' !== $scheme || ! in_array( $host, $allowed_hosts, true ) ) {
            throw new \RuntimeException( 'GSC client: refusing to attach bearer token to non-Google host.' );
        }

        $token = self::token_for( $edit ? self::SCOPE_EDIT : '', $admin_context );

        $args = array(
            'method'              => strtoupper( $method ),
            'headers'             => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'timeout'             => 20,
            'limit_response_size' => 8388608,
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = \wp_remote_request( $url, $args );

        if ( \is_wp_error( $response ) ) {
            throw new \RuntimeException(
                'GSC API request failed (transport error): ' . $response->get_error_message() // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }

        $code    = (int) \wp_remote_retrieve_response_code( $response );
        $raw     = \wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $edit && $code >= 400 ) {
            self::throw_edit_error( $code, $decoded );
        }
        if ( 403 === $code ) {
            self::throw_read_refusal( $decoded, $admin_context );
        }
        if ( 404 === $code ) {
            throw self::refusal(
                'Google Search Console refused the request: ',
                self::google_reason( $code, $decoded, $admin_context ),
                preg_match( '#/sitemaps/[^/?]+#', (string) \wp_parse_url( $url, PHP_URL_PATH ) )
                    ? 'Check the sitemap URL: wp_gsc_list_sitemaps lists the sitemaps submitted for this site.'
                    : 'Verify the site_url matches a verified property in your Google Search Console account, and that the service account has been granted access.',
                $admin_context
            );
        }
        if ( 429 === $code ) {
            throw self::quota_refusal( $code, $decoded, 'Google API quota exceeded. searchAnalytics: 1,200 req/min. URL Inspection: 2,000 req/day, 600 req/min.', $admin_context );
        }
        if ( $code < 200 || $code >= 300 ) {
            throw self::refusal( 'GSC API error: ', self::google_reason( $code, $decoded, $admin_context ), '', $admin_context );
        }

        return is_array( $decoded ) ? $decoded : array();
    }

    public static function get( string $url, bool $admin_context = false ): array {
        return self::request( 'GET', $url, null, $admin_context );
    }

    public static function post( string $url, array $body, bool $admin_context = false ): array {
        return self::request( 'POST', $url, $body, $admin_context );
    }

    private static function throw_edit_error( int $code, $decoded ): void {
        $prefix = $code < 500 ? 'Google Search Console refused the request: ' : 'Google Search Console could not complete the request: ';
        $detail = self::google_reason( $code, $decoded, false );
        if ( 401 === $code || 403 === $code ) {
            $error = self::refusal(
                $prefix,
                $detail,
                403 !== $code || self::is_platform_refusal( $decoded ) ? '' : 'Changing a property\'s sitemaps needs the service account to be an Owner or a Full user of the property (Search Console → Settings → Users and permissions); a Restricted user can only read them.'
            );
        } elseif ( 404 === $code ) {
            $error = self::refusal( 'Google Search Console found nothing to change: ', $detail, \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_gsc_list_sitemaps' ) ? 'Check the site_url and the sitemap URL: wp_gsc_list_sitemaps lists the sitemaps submitted for this site.' : 'Check the site_url and the sitemap URL.' );
        } elseif ( 429 === $code ) {
            $error = '' === self::google_message( $decoded )
                ? new \RuntimeException( 'Google Search Console API quota exceeded; retry later.' )
                : self::refusal( $prefix, $detail, 'Retry after the quota resets.' );
        } else {
            $error = self::refusal( $prefix, $detail );
        }
        throw $code < 500 ? Edit_Refusal::of( $error ) : $error;
    }

    public static function edit( string $method, string $url ): array {
        return self::request( $method, $url, null, false, true );
    }

    public static function write_site_url( array $arguments ): string {
        if ( ! array_key_exists( 'site_url', $arguments ) || null === $arguments['site_url'] ) {
            return self::default_site_url();
        }
        $value = $arguments['site_url'];
        if ( is_string( $value ) && '' === trim( $value ) ) {
            throw new \InvalidArgumentException( 'site_url was sent empty, which names no property. Omit it to use the default property, or pass a full http(s) URL or "sc-domain:example.com". Nothing was changed.' );
        }
        try {
            return self::validate_site_url( is_string( $value ) ? $value : '' );
        } catch ( \InvalidArgumentException $e ) {
            throw new \InvalidArgumentException( 'site_url must be a full http(s) URL or "sc-domain:example.com". Nothing was changed.' );
        }
    }

    public static function default_site_url(): string {
        $url = (string) \Easy_MCP_AI\Config::get( self::OPTION_SITE_URL, '' );
        if ( empty( $url ) && in_array( \Easy_MCP_AI\Config::constant_name( self::OPTION_SITE_URL ), \Easy_MCP_AI\Config_Admin::invalid_settings(), true ) ) {
            throw new \RuntimeException(
                'No site_url provided, and EASY_MCP_AI_GSC_DEFAULT_SITE_URL is not a valid Search Console property. Set it to a URL-prefix property (https://example.com/) or a domain property (sc-domain:example.com), or pass site_url.'
            );
        }
        if ( empty( $url ) ) {
            throw new \RuntimeException(
                'No site_url provided and no default property configured. Set one in Easy MCP AI → Tools → Connections, or define EASY_MCP_AI_GSC_DEFAULT_SITE_URL in wp-config.php.'
            );
        }
        return self::validate_site_url( $url );
    }
}
