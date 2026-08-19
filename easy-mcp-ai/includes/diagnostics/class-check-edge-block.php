<?php



































namespace Easy_MCP_AI\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Check_Edge_Block {

    
    const TIMEOUT = 5;

    




















    const LEG_MCP       = 'mcp';
    const LEG_HANDSHAKE = 'handshake';

    const CLIENT_AGENTS = array(
        'Claude-User'         => self::LEG_MCP,
        'ClaudeBot/1.0'       => self::LEG_MCP,
        'python-httpx/0.28.1' => self::LEG_HANDSHAKE,
    );

    


    public static function run() {
        try {
            $control = self::probe( self::control_agent() );

            $clients = array();
            foreach ( array_keys( self::CLIENT_AGENTS ) as $agent ) {
                $clients[ $agent ] = self::probe( $agent );
            }
        } catch ( \Throwable $e ) {
            return array( self::unknown( __( 'The connection test could not run on this site.', 'easy-mcp-ai' ) ) );
        }

        return array( self::evaluate( $control, $clients ) );
    }

    


    public static function control_agent() {
        $version = defined( 'EASY_MCP_AI_VERSION' ) ? EASY_MCP_AI_VERSION : '0';

        return 'EasyMCPAI/' . $version;
    }

    






    public static function expected_challenge_marker() {
        return 'resource_metadata="' . \home_url( '/.well-known/oauth-protected-resource' ) . '"';
    }

    





    public static function is_our_challenge( $result ) {
        if ( ! is_array( $result ) || 401 !== (int) $result['status'] ) {
            return false;
        }

        
        
        return false !== strpos( (string) $result['challenge'], self::expected_challenge_marker() );
    }

    



    public static function evaluate( $control, array $clients ) {
        $label = self::label();

        
        if ( ! is_array( $control ) ) {
            return self::unknown(
                __( 'The test request could not leave this site, so there is nothing to compare. Some hosts stop a site from calling its own address; on its own that is not a fault.', 'easy-mcp-ai' )
            );
        }

        if ( ! self::is_our_challenge( $control ) ) {
            return self::unknown(
                __( 'The test request did not reach this site\'s own code, so the result would not mean anything. Some hosts stop a site from calling its own address; on its own that is not a fault.', 'easy-mcp-ai' ),
                array( 'control_status' => $control['status'], 'control_server' => $control['server'] )
            );
        }

        $blocked      = array();
        $inconclusive = array();
        $evidence     = array( 'control_status' => $control['status'] );

        foreach ( $clients as $agent => $result ) {
            if ( ! is_array( $result ) ) {
                
                
                
                
                $inconclusive[]                     = $agent;
                $evidence[ 'agent_' . $agent ]      = 'no response';
                continue;
            }

            $evidence[ 'agent_' . $agent ] = (int) $result['status']
                . ( '' !== $result['server'] ? ' via ' . $result['server'] : '' );

            if ( ! self::is_our_challenge( $result ) ) {
                $blocked[] = $agent;
            }
        }

        if ( ! empty( $blocked ) ) {
            return Diagnostic_Result::fail(
                'a9',
                Diagnostic_Result::TIER_BLOCKER,
                $label,
                self::blocked_detail( $blocked ),
                __( 'This is a setting on your CDN, firewall or security plugin, not in WordPress. If you use Cloudflare, look for the AI bot or AI Scrapers and Crawlers blocking option and allow this site\'s AI endpoint through; on other providers look for a bot-filtering rule that blocks AI assistants or unusual user agents. A robots.txt on this site that disallows ClaudeBot is a sign the same rule set is switched on.', 'easy-mcp-ai' ),
                $evidence
            );
        }

        if ( ! empty( $inconclusive ) ) {
            return Diagnostic_Result::warn(
                'a9',
                Diagnostic_Result::TIER_WARNING,
                $label,
                __( 'A test request carrying the AI assistant\'s name got no reply, while the same request from this plugin was answered normally. That may be a passing network problem, or something in front of WordPress dropping the assistant\'s requests.', 'easy-mcp-ai' ),
                __( 'Run the checks again. If it keeps happening, check your CDN or firewall for a bot-filtering rule naming Claude.', 'easy-mcp-ai' ),
                $evidence
            );
        }

        return Diagnostic_Result::pass(
            'a9',
            Diagnostic_Result::TIER_BLOCKER,
            $label,
            __( 'Requests carrying the AI assistant\'s name reach WordPress normally.', 'easy-mcp-ai' ),
            $evidence
        );
    }

    












    public static function blocked_detail( array $blocked ) {
        $legs = array();
        foreach ( $blocked as $agent ) {
            if ( isset( self::CLIENT_AGENTS[ $agent ] ) ) {
                $legs[ self::CLIENT_AGENTS[ $agent ] ] = true;
            }
        }

        $intro = __( 'Something in front of WordPress is refusing the AI assistant. An identical request to this site\'s AI endpoint is answered normally for this plugin but rejected when it carries the assistant\'s name, so the request never reaches WordPress and nothing is recorded here to explain it.', 'easy-mcp-ai' );

        $handshake = isset( $legs[ self::LEG_HANDSHAKE ] );
        $mcp       = isset( $legs[ self::LEG_MCP ] );

        if ( $handshake && $mcp ) {
            return $intro . ' ' . __( 'Both stages are affected: adding this site as a connector will fail straight away, and even if one were already set up it would stop working.', 'easy-mcp-ai' );
        }

        if ( $handshake ) {
            return $intro . ' ' . __( 'The sign-in stage is the one being blocked, so adding this site as a connector fails immediately — the assistant cannot register with the site at all. Anyone already connected is unaffected.', 'easy-mcp-ai' );
        }

        return $intro . ' ' . __( 'The sign-in stage still works, which is what makes this confusing: connecting appears to succeed, access is approved, and only then does the assistant report that it could not connect.', 'easy-mcp-ai' );
    }

    

    










    private static function probe( $agent ) {
        if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'rest_url' ) ) {
            return null;
        }

        $response = \wp_remote_get(
            \rest_url( 'easy-mcp-ai/v1/mcp' ),
            array(
                'timeout'     => self::TIMEOUT,
                'redirection' => 0,
                'user-agent'  => $agent,
                
                
                
                'sslverify'   => false,
            )
        );

        if ( \is_wp_error( $response ) ) {
            return null;
        }

        return array(
            'status'    => (int) \wp_remote_retrieve_response_code( $response ),
            'server'    => (string) \wp_remote_retrieve_header( $response, 'server' ),
            'challenge' => (string) \wp_remote_retrieve_header( $response, 'www-authenticate' ),
        );
    }

    private static function label() {
        return __( 'AI assistant can reach this site', 'easy-mcp-ai' );
    }

    private static function unknown( $reason, array $evidence = array() ) {
        return Diagnostic_Result::unknown( 'a9', Diagnostic_Result::TIER_BLOCKER, self::label(), $reason, $evidence );
    }
}
