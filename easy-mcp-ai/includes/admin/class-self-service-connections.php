<?php
namespace Easy_MCP_AI\Admin;

use Easy_MCP_AI\Admin\Rest\OAuth_Controller;
use Easy_MCP_AI\Auth\Connect_Policy;
use Easy_MCP_AI\Config;
use Easy_MCP_AI\OAuth\OAuth_Audit_Log;
use Easy_MCP_AI\Tools\Tool_Registry;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Self_Service_Connections {

    const PER_PAGE = 20;

    const AUDIT_EVENT = '_oauth_grant_revoke';

    private $registry;

    private $forms = array();

    public function __construct( Tool_Registry $registry ) {
        $this->registry = $registry;
        \add_action( 'show_user_profile', array( $this, 'render' ), 11 );
        \add_action( 'admin_footer', array( $this, 'render_forms' ) );
        \add_action( 'admin_post_easy_mcp_ai_self_revoke_connection', array( $this, 'handle_revoke' ) );
    }

    public static function switched_on(): bool {
        return Config::get( 'self_service_keys' ) && \get_current_user_id() > 0;
    }

    private function grants( int $page ): array {
        global $wpdb;
        $controller = new OAuth_Controller( $wpdb, new Access_Presets( $this->registry ), $this->registry );
        return $controller->user_grants( \get_current_user_id(), $page, self::PER_PAGE );
    }

    public function revoke( int $consent_id ) {
        if ( ! self::switched_on() ) {
            return new \WP_Error( 'forbidden', __( 'Managing connected AI apps is not available for your account.', 'easy-mcp-ai' ) );
        }
        $client_id = OAuth_Admin::revoke_own_grant( $consent_id );
        if ( null === $client_id ) {
            return new \WP_Error( 'forbidden', __( 'You can only revoke your own connections.', 'easy-mcp-ai' ) );
        }
        OAuth_Audit_Log::write( self::AUDIT_EVENT, array( 'consent_id' => $consent_id, 'stage' => 'profile' ), 'success', $client_id, \get_current_user_id() );
        return true;
    }

    public function handle_revoke(): void {
        $raw = $_POST['consent_id'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce named after this id is checked next.
        $id  = is_string( $raw ) && ctype_digit( $raw ) ? (int) $raw : 0;
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::switched_on() ) {
            \wp_die( \esc_html__( 'Managing connected AI apps is not available for this request.', 'easy-mcp-ai' ), '', array( 'response' => 403 ) );
        }
        if ( false === \check_admin_referer( 'easy_mcp_ai_self_revoke_connection_' . $id ) ) {
            \wp_die( \esc_html__( 'The security token for this request is invalid or has expired.', 'easy-mcp-ai' ), '', array( 'response' => 403 ) );
        }
        $result = $this->revoke( $id );
        if ( \is_wp_error( $result ) ) {
            \wp_die( \esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
        }
        \wp_safe_redirect( \admin_url( 'profile.php#easy-mcp-connections' ) );
        exit;
    }

    public function render( $user ): void {
        if ( ! self::switched_on() || (int) $user->ID !== \get_current_user_id() ) {
            return;
        }
        $page   = isset( $_GET['easy_mcp_connections_page'] ) && is_scalar( $_GET['easy_mcp_connections_page'] ) ? max( 1, min( self::last_page(), (int) $_GET['easy_mcp_connections_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A page number for a read.
        $result = $this->grants( $page );
        $grants = $result['grants'];
        $more   = $result['more'];
        if ( ! $grants && ! Connect_Policy::current_user_can_connect( Connect_Policy::CHANNEL_OAUTH ) ) {
            return;
        }
        foreach ( $grants as $grant ) {
            $this->forms[ 'easy-mcp-self-revoke-connection-' . (int) $grant['id'] ] = array(
                'action' => 'easy_mcp_ai_self_revoke_connection',
                'nonce'  => 'easy_mcp_ai_self_revoke_connection_' . (int) $grant['id'],
                'fields' => array( 'consent_id' => (int) $grant['id'] ),
            );
        }
        require __DIR__ . '/views/self-service-connections.php';
    }

    private static function last_page(): int {
        return intdiv( PHP_INT_MAX, self::PER_PAGE ) + 1;
    }

    public function render_forms(): void {
        Self_Service_Keys::print_forms( $this->forms );
    }

    public static function access_label( array $grant ): string {
        $privileged      = ! empty( $grant['privilegedScopes'] );
        $preset          = (string) ( $grant['preset'] ?? Access_Presets::CUSTOM );
        $count           = (int) ( $grant['toolCount'] ?? 0 );
        $uncounted       = array_values( array_filter( array_map( 'strval', (array) ( $grant['uncounted'] ?? array() ) ) ) );
        $privileged_only = $privileged && Access_Presets::CUSTOM === $preset && 0 === $count && array() === $uncounted;
        if ( $privileged_only ) {
            $scope = __( 'Privileged only', 'easy-mcp-ai' );
        } elseif ( Access_Presets::CUSTOM !== $preset ) {
            $scope = self::preset_label( $preset );
        } elseif ( array() !== $uncounted ) {
            $scope = sprintf(
                /* translators: 1: number of tools, 2: comma-separated plugin names, e.g. "Elementor". */
                _n( 'Custom (%1$d tool, not counting %2$s abilities)', 'Custom (%1$d tools, not counting %2$s abilities)', $count, 'easy-mcp-ai' ),
                $count,
                implode( ', ', $uncounted )
            );
        } else {
            /* translators: %d: number of tools */
            $scope = sprintf( _n( 'Custom (%d tool)', 'Custom (%d tools)', $count, 'easy-mcp-ai' ), $count );
        }
        $label = $scope;
        if ( is_array( $grant['roleLimit'] ?? null ) ) {
            $role = array() === $grant['roleLimit']
                ? __( 'Role limit unreadable: every call refused', 'easy-mcp-ai' )
                /* translators: %s: comma-separated role names, e.g. "Editor" */
                : sprintf( __( 'Match a role: %s', 'easy-mcp-ai' ), implode( ', ', array_column( $grant['roleLimit'], 'label' ) ) );
            /* translators: 1: "Match a role: Editor", 2: the scope's access level, e.g. "Read-only" */
            $label = ! empty( $grant['fullAccess'] ) ? $role : sprintf( __( '%1$s (%2$s)', 'easy-mcp-ai' ), $role, $scope );
        }
        /* translators: %s: the access level, e.g. "Full access" */
        return $privileged && ! $privileged_only ? sprintf( __( '%s + privileged', 'easy-mcp-ai' ), $label ) : $label;
    }

    private static function preset_label( string $preset ): string {
        switch ( $preset ) {
            case Access_Presets::READ:
                return __( 'Read-only', 'easy-mcp-ai' );
            case Access_Presets::WRITE:
                return __( 'Read + write content', 'easy-mcp-ai' );
            case Access_Presets::FULL:
                return __( 'Full access', 'easy-mcp-ai' );
            case 'role:editor':
                return __( 'Editor role', 'easy-mcp-ai' );
            case 'role:author':
                return __( 'Author role', 'easy-mcp-ai' );
            default:
                return __( 'Custom', 'easy-mcp-ai' );
        }
    }

    public static function utc( $iso ): string {
        $ts = is_string( $iso ) ? strtotime( $iso ) : false;
        return false === $ts ? '—' : gmdate( 'Y-m-d H:i:s', $ts );
    }
}
