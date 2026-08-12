<?php


































namespace Easy_MCP_AI\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Check_Header_Probe {

    
    const SECRET_OPTION = 'easy_mcp_ai_header_probe_secret';

    
    const ROUTE = '/header-probe';

    
    const TIMEOUT = 5;

    


    public static function run() {
        
        
        
        
        
        
        
        
        
        $secret = '';

        try {
            $secret = self::arm();

            if ( '' === $secret ) {
                return array( self::unknown( __( 'Could not prepare the connection test on this site.', 'easy-mcp-ai' ) ) );
            }

            try {
                $body = self::send_probe( $secret );
            } catch ( \Throwable $e ) {
                $body = null;
            }
        } finally {
            self::disarm();
        }

        return array( self::evaluate( $body, $secret ) );
    }

    







    public static function expected_proof( $secret ) {
        return \wp_hash( 'easy_mcp_ai_header_probe|' . (string) $secret );
    }

    







    public static function authorize_probe( $supplied ) {
        $expected = (string) \get_option( self::SECRET_OPTION, '' );

        if ( '' === $expected || '' === (string) $supplied ) {
            return false;
        }

        return hash_equals( $expected, (string) $supplied );
    }

    






    public static function probe_response( array $server, array $headers, $secret ) {
        $found_in_headers = false;
        foreach ( array_keys( $headers ) as $name ) {
            if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
                $found_in_headers = true;
                break;
            }
        }

        
        
        
        
        
        
        
        
        $server_delivered = isset( $GLOBALS['easy_mcp_ai_server_had_auth_header'] )
            ? (bool) $GLOBALS['easy_mcp_ai_server_had_auth_header']
            : ! empty( $server['HTTP_AUTHORIZATION'] );

        return array(
            'proof'         => self::expected_proof( $secret ),
            'server_var'    => $server_delivered,
            'getallheaders' => $found_in_headers,
        );
    }

    



    public static function evaluate( $body, $secret ) {
        
        
        if ( ! is_array( $body ) || empty( $body['proof'] )
            || ! hash_equals( self::expected_proof( $secret ), (string) $body['proof'] ) ) {
            return self::unknown(
                __( 'The test request did not reach this site\'s own code, so the result would not mean anything. Some hosts block a site from calling itself; that on its own is not a fault.', 'easy-mcp-ai' )
            );
        }

        $label      = self::label();
        $in_server  = ! empty( $body['server_var'] );
        $in_headers = ! empty( $body['getallheaders'] );
        $evidence   = array( 'server_var' => $in_server, 'getallheaders' => $in_headers );

        if ( $in_server ) {
            return Diagnostic_Result::pass( 'a1', Diagnostic_Result::TIER_WARNING, $label, __( 'Confirmed: an Authorization header sent to this site reaches WordPress.', 'easy-mcp-ai' ), $evidence );
        }

        if ( $in_headers ) {
            return Diagnostic_Result::warn(
                'a1',
                Diagnostic_Result::TIER_WARNING,
                $label,
                __( 'The web server is not passing the Authorization header to PHP in the normal way. This plugin recovers it from a fallback source, so AI clients still work today — but a site relying on that fallback breaks as soon as anything about the server changes.', 'easy-mcp-ai' ),
                __( 'Open Settings → Permalinks and press Save Changes so WordPress writes the rewrite that passes the header to PHP properly.', 'easy-mcp-ai' ),
                $evidence
            );
        }

        return Diagnostic_Result::warn(
            'a1',
            Diagnostic_Result::TIER_WARNING,
            $label,
            __( 'An Authorization header sent to this site never reaches WordPress. This is the usual reason an AI client that worked yesterday suddenly reports an invalid token: the credential is fine, the web server is removing it before WordPress sees it.', 'easy-mcp-ai' ),
            __( 'On Apache with mod_php, re-save Settings → Permalinks to restore the rewrite. On FastCGI or PHP-FPM the web server needs CGIPassAuth On, or the nginx equivalent, which usually only the host can set — quote this check when asking them.', 'easy-mcp-ai' ),
            $evidence
        );
    }

    

    


    private static function arm() {
        $secret = self::generate_secret();

        
        \update_option( self::SECRET_OPTION, $secret, false );

        return hash_equals( (string) \get_option( self::SECRET_OPTION, '' ), $secret ) ? $secret : '';
    }

    private static function disarm() {
        \delete_option( self::SECRET_OPTION );
    }

    












    public static function generate_secret() {
        if ( function_exists( 'random_bytes' ) ) {
            try {
                return bin2hex( random_bytes( 32 ) );
            } catch ( \Throwable $e ) {
                
                
                unset( $e );
            }
        }

        return (string) \wp_generate_password( 64, false, false );
    }

    


    private static function send_probe( $secret ) {
        if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'rest_url' ) ) {
            return null;
        }

        $url = \add_query_arg(
            'probe',
            rawurlencode( $secret ),
            \rest_url( 'easy-mcp-ai/v1' . self::ROUTE )
        );

        $response = \wp_remote_get(
            $url,
            array(
                'timeout'     => self::TIMEOUT,
                'redirection' => 0,
                'headers'     => array( 'Authorization' => 'Bearer ' . $secret ),
                
                
                
                'sslverify'   => false,
            )
        );

        if ( \is_wp_error( $response ) ) {
            return null;
        }

        $decoded = json_decode( (string) \wp_remote_retrieve_body( $response ), true );

        return is_array( $decoded ) ? $decoded : null;
    }

    private static function label() {
        return __( 'Login credentials reach WordPress', 'easy-mcp-ai' );
    }

    private static function unknown( $reason ) {
        return Diagnostic_Result::unknown( 'a1', Diagnostic_Result::TIER_WARNING, self::label(), $reason );
    }
}
