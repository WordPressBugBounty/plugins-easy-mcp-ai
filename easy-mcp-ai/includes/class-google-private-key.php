<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Google_Private_Key {

    const REMEDY = ' Use the service account key file downloaded from Google Cloud Console, unedited.';

    const SELF_TEST_INPUT = 'easy-mcp-ai.google-key-check';

    const MAX_OPENSSL_ERRORS = 3;

    const OPENSSL_ERROR_LINE = '/\Aerror:[0-9A-Fa-f]{1,16}:[A-Za-z0-9 _\-]{0,80}:[A-Za-z0-9 _\-()]{0,80}:[A-Za-z0-9 _\-(),.]{0,120}\z/';

    public static function problem( string $pem ): ?string {
        try {
            self::sign_with( self::SELF_TEST_INPUT, self::read( $pem ) );
        } catch ( \RuntimeException $e ) {
            return $e->getMessage();
        }
        return null;
    }

    public static function sign( string $input, string $pem ): string {
        try {
            return self::sign_with( $input, self::read( $pem ) );
        } catch ( \RuntimeException $e ) {
            throw new \RuntimeException( 'Failed to sign JWT. ' . $e->getMessage() );
        }
    }

    private static function sign_with( string $input, $key ): string {
        $signature = '';
        if ( ! openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
            throw new \RuntimeException(
                'OpenSSL read the private_key but could not sign with it' . self::openssl_errors() . '. The key was read, so signing itself failed: if this is the key file Google Cloud Console issued, ask the host to check that this server\'s PHP OpenSSL can sign with RSA and SHA-256.'
            );
        }
        return $signature;
    }

    private static function read( string $pem ) {
        if ( ! function_exists( 'openssl_pkey_get_private' ) ) {
            throw new \RuntimeException( 'The PHP OpenSSL extension, which signs Google requests, is not available on this server: ask the host to enable it.' );
        }
        while ( false !== openssl_error_string() ) {
        }
        $text = trim( $pem );
        if ( 0 === strpos( $text, 'file://' ) || false === strpos( $text, '-----BEGIN ' ) ) {
            throw new \RuntimeException( 'The private_key is not PEM text: a private key starts with -----BEGIN PRIVATE KEY-----.' . self::REMEDY );
        }
        try {
            $key = openssl_pkey_get_private( $text );
        } catch ( \Throwable $e ) {
            $key = false;
        }
        if ( false === $key ) {
            throw new \RuntimeException( self::unreadable( $text ) . self::openssl_errors() . '.' . self::REMEDY );
        }
        $details = openssl_pkey_get_details( $key );
        if ( ! is_array( $details ) || OPENSSL_KEYTYPE_RSA !== ( $details['type'] ?? null ) ) {
            throw new \RuntimeException( 'The private_key is not an RSA key, and Google signs service account requests with RSA (RS256) only.' . self::REMEDY );
        }
        return $key;
    }

    private static function unreadable( string $text ): string {
        if ( false === strpos( $text, "\n" ) && false !== strpos( $text, '\n' ) ) {
            return 'The private_key\'s line breaks were saved as the two characters \n rather than as line breaks, so OpenSSL cannot read it';
        }
        preg_match_all( '/^-----BEGIN ([A-Z0-9 ]{1,40})-----\r?$/m', $text, $match );
        $labels = $match[1];
        if ( in_array( 'ENCRYPTED PRIVATE KEY', $labels, true ) || preg_match( '/^-----BEGIN RSA PRIVATE KEY-----\r?\nProc-Type: 4,ENCRYPTED\r?$/m', $text ) ) {
            return 'The private_key is protected by a passphrase, and Google issues service account keys without one';
        }
        $public = array_values( array_intersect( $labels, array( 'PUBLIC KEY', 'RSA PUBLIC KEY', 'CERTIFICATE' ) ) );
        if ( array() !== $public && ! preg_grep( '/PRIVATE KEY\z/', $labels ) ) {
            return sprintf( 'The private_key holds a %s, not a private key', strtolower( $public[0] ) );
        }
        return 'OpenSSL cannot read the private_key, which may have been cut short or changed on its way into WordPress';
    }

    private static function openssl_errors(): string {
        $lines = array();
        while ( false !== ( $line = openssl_error_string() ) ) {
            if ( count( $lines ) < self::MAX_OPENSSL_ERRORS && preg_match( self::OPENSSL_ERROR_LINE, $line ) && ! in_array( $line, $lines, true ) ) {
                $lines[] = $line;
            }
        }
        return array() === $lines ? '' : ' (OpenSSL: ' . implode( '; ', $lines ) . ')';
    }
}
