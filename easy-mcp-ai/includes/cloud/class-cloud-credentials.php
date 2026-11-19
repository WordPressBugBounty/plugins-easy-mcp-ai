<?php

namespace Easy_MCP_AI\Cloud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cloud_Credentials {

    const OPTION_SITE_ID = 'easy_mcp_ai_cloud_site_id';
    const OPTION_KEY     = 'easy_mcp_ai_cloud_key';
    const KEY_PATTERN    = '/^emcps_[0-9A-Za-z]{40}$/D';
    const SITE_ID_PATTERN = '/^site_[0-9a-z]{26}$/D';

    const CIPHER_VERSION = "v2\x00";
    const HKDF_INFO      = 'easy_mcp_ai_cloud_v2';

    public static function can_encrypt(): bool {
        if ( ! defined( 'SECURE_AUTH_KEY' ) || ! defined( 'SECURE_AUTH_SALT' ) || ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return false;
        }
        $key  = SECURE_AUTH_KEY;
        $salt = SECURE_AUTH_SALT;
        if ( ! is_string( $key ) || ! is_string( $salt ) || '' === $key || '' === $salt ) {
            return false;
        }
        $material = $key . $salt;
        return strlen( $material ) >= 64 && false === strpos( $material, 'put your unique phrase here' );
    }

    public static function key(): string {
        $stored = \get_option( self::OPTION_KEY, '' );
        if ( ! is_string( $stored ) || '' === $stored || ! self::can_encrypt() ) {
            return '';
        }
        $plain = self::decrypt( $stored );
        return false !== $plain && 1 === preg_match( self::KEY_PATTERN, $plain ) ? $plain : '';
    }

    public static function fresh_key(): string {
        \wp_cache_delete( self::OPTION_KEY, 'options' );
        \wp_cache_delete( 'notoptions', 'options' );
        return self::key();
    }

    public static function site_id(): string {
        $id = \get_option( self::OPTION_SITE_ID, '' );
        return is_string( $id ) && 1 === preg_match( self::SITE_ID_PATTERN, $id ) ? $id : '';
    }

    public static function store( string $site_id, string $key ): void {
        if ( ! self::can_encrypt() ) {
            return;
        }
        \update_option( self::OPTION_SITE_ID, $site_id, false );
        \update_option( self::OPTION_KEY, self::encrypt( $key ), false );
    }

    public static function set_site_id( string $site_id ): void {
        \update_option( self::OPTION_SITE_ID, $site_id, false );
    }

    public static function forget(): void {
        \delete_option( self::OPTION_KEY );
        \delete_option( self::OPTION_SITE_ID );
    }

    private static function encrypt( string $plaintext ): string {
        $iv         = random_bytes( 12 );
        $tag        = '';
        $ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', self::derive_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        return false === $ciphertext ? '' : base64_encode( self::CIPHER_VERSION . $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- AES-256-GCM ciphertext stored in wp_options.
    }

    private static function decrypt( string $encoded ) {
        $raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the stored AES-256-GCM ciphertext.
        $len = strlen( self::CIPHER_VERSION );
        if ( false === $raw || strlen( $raw ) <= $len + 28 || substr( $raw, 0, $len ) !== self::CIPHER_VERSION ) {
            return false;
        }
        $raw = substr( $raw, $len );
        return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::derive_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
    }

    private static function derive_key(): string {
        return hash_hkdf( 'sha256', SECURE_AUTH_KEY . SECURE_AUTH_SALT, 32, self::HKDF_INFO );
    }
}
