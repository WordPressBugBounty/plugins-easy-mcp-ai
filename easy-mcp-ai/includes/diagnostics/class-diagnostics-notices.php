<?php






















namespace Easy_MCP_AI\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Diagnostics_Notices {

    



    public static function register() {
        \add_action( 'admin_notices', array( __CLASS__, 'render' ) );
    }

    public static function render() {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! \function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = \get_current_screen();
        if ( ! $screen || false === strpos( (string) $screen->id, 'easy-mcp-ai' ) ) {
            return;
        }

        $blockers = self::current_blockers();
        if ( empty( $blockers ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>'
            . \esc_html(
                \_n(
                    'Easy MCP AI found a problem that stops AI clients connecting.',
                    'Easy MCP AI found problems that stop AI clients connecting.',
                    count( $blockers ),
                    'easy-mcp-ai'
                )
            )
            . '</strong></p><ul style="margin-left:1.5em;list-style:disc;">';

        foreach ( $blockers as $blocker ) {
            echo '<li><strong>' . \esc_html( $blocker->label() ) . '</strong> — '
                . \esc_html( $blocker->detail() );
            if ( '' !== $blocker->fix() ) {
                echo '<br><em>' . \esc_html( $blocker->fix() ) . '</em>';
            }
            echo '</li>';
        }

        echo '</ul></div>';
    }

    















    const LIVE_CHECK_IDS = array( 'a7', 'a8' );

    


























    public static function live_results( $runner = null ) {
        if ( ! is_callable( $runner ) ) {
            $runner = array( Check_Notices::class, 'run' );
        }

        try {
            $results = call_user_func( $runner );
        } catch ( \Throwable $e ) {
            return array();
        }

        
        
        
        return is_array( $results ) ? $results : array();
    }

    


    private static function current_blockers() {
        $live = array();
        foreach ( self::live_results() as $result ) {
            $live[ $result->id() ] = $result;
        }

        $out  = array();
        $seen = array();

        
        
        foreach ( Diagnostics::cached() as $result ) {
            $id = $result->id();
            if ( isset( $live[ $id ] ) ) {
                if ( $live[ $id ]->renders_in_notice() ) {
                    $out[] = $live[ $id ];
                }
                $seen[ $id ] = true;
                continue;
            }
            if ( $result->renders_in_notice() ) {
                $out[] = $result;
            }
        }

        
        
        foreach ( self::LIVE_CHECK_IDS as $id ) {
            if ( isset( $seen[ $id ] ) || ! isset( $live[ $id ] ) ) {
                continue;
            }
            if ( $live[ $id ]->renders_in_notice() ) {
                $out[] = $live[ $id ];
            }
        }

        return $out;
    }
}
