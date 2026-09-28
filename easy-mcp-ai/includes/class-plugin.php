<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-config.php';
require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-config-admin.php';
require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-activator.php';
require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-deactivator.php';

class Plugin {

    





    const CLEANUP_MAX_ITERATIONS = 20;

    
















    const DEFAULT_OAUTH_CLIENT_RETENTION = 7;

    private static $instance = null;
    private $server;
    private $token_manager;
    private $tool_registry;
    private $resource_registry;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        Config_Admin::register();
        
        
        
        \add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        \add_action( 'init', array( $this, 'handle_well_known' ), 0 );
        
        
        
        
        
        
        
        
        
        
        \add_action( 'init', array( $this, 'handle_oauth_authorize_request' ), PHP_INT_MAX );
        \add_action( 'easy_mcp_ai_cleanup_audit_log', array( $this, 'cleanup_audit_log' ) );
        \add_action( 'easy_mcp_ai_cleanup_oauth', array( $this, 'cleanup_oauth_storage' ) );
        \add_action( 'easy_mcp_ai_cleanup_new_token_meta', array( $this, 'cleanup_new_token_meta' ) );
        \add_action( 'easy_mcp_ai_cleanup_change_log', array( __CLASS__, 'cleanup_change_log' ) );
        \add_action( 'easy_mcp_ai_cleanup_tasks', array( $this, 'cleanup_tasks' ) );
        \add_action( 'easy_mcp_ai_task_tick', array( $this, 'run_task_tick' ), 10, 1 );
        
        \add_action( 'plugins_loaded', array( 'Easy_MCP_AI\Activator', 'maybe_upgrade' ) );
        \add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_oauth' ) );
        
        
        \add_filter( 'application_password_is_api_request', array( __CLASS__, 'exempt_oauth_client_credentials_from_application_passwords' ) );
        
        if ( \is_multisite() ) {
            \add_action( 'wp_initialize_site', array( $this, 'on_new_site' ), 10, 1 );
            
            
            \add_filter( 'wpmu_drop_tables', array( $this, 'on_drop_subsite_tables' ), 10, 1 );
        }
        
        if ( \is_admin() && ! \wp_doing_cron() && ! \wp_doing_ajax() ) {
            \add_action( 'init', array( $this, 'init_admin' ) );
        }
    }

    


    private function load_mcp_includes() {
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/mcp/class-error-codes.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/mcp/class-detailed-tool-error.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/mcp/class-json-rpc.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/mcp/class-session.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/mcp/class-gemini-safe-schema.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/mcp/class-transport.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/mcp/class-rest-auth-override.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/mcp/class-server.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/auth/class-token-manager.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-auth-header.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/auth/class-token-auth.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/auth/class-permission-guard.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/class-base-tool.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/class-tool-registry.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/class-dynamic-tool.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/class-dynamic-tool-registrar.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-abstract-google-client.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/gsc/class-gsc-client.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/ga/class-ga-client.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/dfs/class-dataforseo-client.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/semrush/class-semrush-client.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/semrush/class-semrush-validators.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/seranking/class-seranking-client.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/seranking/class-seranking-validators.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/ahrefs/class-ahrefs-client.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/resources/class-base-resource.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/resources/class-resource-registry.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-log-schema.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-redactor.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-context.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-log-repository.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-audit-log-repository.php';
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-text-redactor.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-recorder.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-db-interceptor.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-external-intent.php';
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/meta/class-meta-field-provider.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/meta/class-meta-exposure.php';
    }

    public function init_admin() {
        $this->load_mcp_includes();
        $this->token_manager = new Auth\Token_Manager();
        $this->tool_registry = new Tools\Tool_Registry();
        
        
        
        $this->tool_registry->set_lazy_loader( function () { $this->register_tools(); } );
        
        
        
        
        if ( $this->is_plugin_admin_screen() ) {
            $this->register_tools();
        }
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/class-admin-page.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/class-app-page.php';
        new Admin\App_Page();

        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/class-self-service-keys.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-authorization-endpoint.php';
        new Admin\Self_Service_Keys( $this->token_manager, $this->tool_registry );
        $this->register_diagnostics();
    }

    














    private function register_diagnostics() {
        $dir = EASY_MCP_AI_PLUGIN_DIR . 'includes/diagnostics/';

        
        
        
        
        
        
        
        
        
        require_once $dir . 'class-diagnostic-result.php';
        require_once $dir . 'class-diagnostics.php';
        require_once $dir . 'class-check-notices.php';
        require_once $dir . 'class-diagnostics-site-health.php';

        
        
        
        Diagnostics\Diagnostics::register_core_checks( $this->tool_registry );
        Diagnostics\Diagnostics_Site_Health::register();

        
        
        
        
        
        Diagnostics\Diagnostics::register_invalidation();

        
        
        
        if ( $this->is_plugin_admin_screen() ) {
            \add_action( 'admin_init', array( Diagnostics\Diagnostics::class, 'maybe_run' ) );
        }
    }

    







    private function is_plugin_admin_screen() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing; mutates nothing.
        $page = isset( $_GET['page'] ) ? \sanitize_key( \wp_unslash( $_GET['page'] ) ) : '';
        return '' !== $page && 0 === strpos( $page, 'easy-mcp-ai' );
    }

    









    public function handle_well_known() {
        
        
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public routing check on REQUEST_URI; no state is mutated here.
        $request_uri_raw = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $is_well_known   = false !== strpos( $request_uri_raw, '/.well-known/' );
        if ( ! $is_well_known ) {
            
            
            
            return;
        }

        if ( ! \apply_filters( 'easy_mcp_ai_oauth_enabled', \Easy_MCP_AI\Config::get( 'oauth_enabled' ) ) ) {
            return;
        }

        
        $request_uri = \wp_parse_url( $request_uri_raw, PHP_URL_PATH );

        
        $home_path = trim( \wp_parse_url( \home_url(), PHP_URL_PATH ) ?? '', '/' );
        if ( $home_path ) {
            $request_uri = preg_replace( '#^/' . preg_quote( $home_path, '#' ) . '#', '', $request_uri );
        }

        $is_protected_resource = ( '/.well-known/oauth-protected-resource' === $request_uri );
        $is_auth_server        = ( '/.well-known/oauth-authorization-server' === $request_uri
                                || '/.well-known/openid-configuration' === $request_uri );

        
        
        
        if ( ! $is_protected_resource && ! $is_auth_server ) {
            $is_protected_resource = $this->is_path_inserted_resource_metadata( $request_uri_raw, $home_path );
        }

        
        
        if ( ! $is_protected_resource && ! $is_auth_server ) {
            $is_auth_server = $this->is_path_inserted_auth_server_metadata( $request_uri_raw, $home_path );
        }

        if ( ! $is_protected_resource && ! $is_auth_server ) {
            return;
        }

        
        
        
        
        
        
        
        
        
        ob_start();

        
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/class-dynamic-tool-registrar.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-scope-map.php';
        
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-client-ip.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-token-endpoint.php';
        
        
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-client-registry.php';
        
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-device-authorization.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-discovery.php';
        $discovery = new OAuth\Discovery();
        $rest_req  = new \WP_REST_Request( 'GET' );
        $response  = $is_protected_resource
            ? $discovery->get_protected_resource_metadata( $rest_req )
            : $discovery->get_authorization_server_metadata( $rest_req );

        
        
        
        if ( is_wp_error( $response ) ) {
            $err_data = $response->get_error_data();
            $status   = is_array( $err_data ) && isset( $err_data['status'] ) ? (int) $err_data['status'] : 400;
            $body     = array(
                'error'             => $response->get_error_code(),
                'error_description' => $response->get_error_message(),
            );
        } elseif ( $response instanceof \WP_REST_Response ) {
            $status = $response->get_status();
            $body   = $response->get_data();
        } else {
            $status = 200;
            $body   = $response;
        }

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        while ( ob_get_level() > 0 ) {
            if ( ! ob_end_clean() ) {
                break;
            }
        }

        
        
        
        if ( ! headers_sent() ) {
            \status_header( $status );
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Cache-Control: no-store' );
            header( 'Pragma: no-cache' );
        }
        echo \wp_json_encode( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded.
        exit;
    }

    




































    private function is_path_inserted_resource_metadata( $request_uri_raw, $home_path ) {
        $path = \wp_parse_url( $request_uri_raw, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            return false;
        }
        
        
        $path = rtrim( $path, '/' );

        
        
        
        
        
        
        $resource_path = \wp_parse_url(
            \rest_url( 'easy-mcp-ai/v1/mcp' ),
            PHP_URL_PATH
        );
        if ( ! is_string( $resource_path ) || '' === $resource_path ) {
            return false;
        }
        $resource_path = '/' . ltrim( rtrim( $resource_path, '/' ), '/' );

        $canonical = '/.well-known/oauth-protected-resource' . $resource_path;
        if ( $path === $canonical ) {
            return true;
        }

        
        
        if ( '' !== $home_path && $path === '/' . $home_path . $canonical ) {
            return true;
        }

        return false;
    }

    






















    private function is_path_inserted_auth_server_metadata( $request_uri_raw, $home_path ) {
        $home_path = trim( (string) $home_path, '/' );
        if ( '' === $home_path ) {
            return false;
        }

        $path = \wp_parse_url( $request_uri_raw, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            return false;
        }
        $path = rtrim( $path, '/' );

        foreach ( array( '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration' ) as $suffix ) {
            if ( $path === $suffix . '/' . $home_path ) {
                return true;
            }
        }

        return false;
    }

    









    public function handle_oauth_authorize_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public routing check; authorize handler enforces its own nonce downstream.
        $oauth_param = isset( $_GET['easy_mcp_ai_oauth'] ) ? sanitize_text_field( wp_unslash( $_GET['easy_mcp_ai_oauth'] ) ) : '';
        
        
        
        
        
        if ( 'authorize' !== $oauth_param && 'device' !== $oauth_param ) {
            return;
        }
        if ( ! \apply_filters( 'easy_mcp_ai_oauth_enabled', \Easy_MCP_AI\Config::get( 'oauth_enabled' ) ) ) {
            return;
        }
        
        
        
        Activator::maybe_upgrade_core_tables();
        $this->handle_oauth_authorize( $oauth_param );
    }

    











    private function handle_oauth_authorize( $flow = 'authorize' ) {
        
        
        
        
        
        
        
        
        
        
        
        
        
        ob_start();

        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-schema.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-scope-map.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-consent-screen.php';
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-client-ip.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-client-registry.php';
        
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-token-endpoint.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-authorization-endpoint.php';
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-device-authorization.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-device-verification.php';

        $method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        $request = new \WP_REST_Request( $method );

        if ( 'POST' === $method ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside Authorization_Endpoint::handle_post() / Device_Verification::handle_post().
            $request->set_body_params( isset( $_POST ) ? \wp_unslash( $_POST ) : array() );
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth params, no state change.
            $request->set_query_params( isset( $_GET ) ? \wp_unslash( $_GET ) : array() );
        }

        $endpoint = ( 'device' === $flow ) ? new OAuth\Device_Verification() : new OAuth\Authorization_Endpoint();
        $response = 'POST' === $method ? $endpoint->handle_post( $request ) : $endpoint->handle_get( $request );

        
        
        
        
        while ( ob_get_level() > 0 ) {
            if ( ! ob_end_clean() ) {
                break;
            }
        }

        $this->send_authorize_response( $response );
        exit;
    }

    









    private function send_authorize_response( $response ) {
        if ( \is_wp_error( $response ) ) {
            $status = 400;
            $data   = $response->get_error_data();
            if ( is_array( $data ) && ! empty( $data['status'] ) ) {
                $status = (int) $data['status'];
            }
            
            
            
            
            if ( ! headers_sent() ) {
                \status_header( $status );
                header( 'Content-Type: text/html; charset=utf-8' );
                header( 'X-Frame-Options: DENY' );
                header( "Content-Security-Policy: frame-ancestors 'none'" );
                header( 'X-Content-Type-Options: nosniff' );
            }
            $code    = \esc_html( $response->get_error_code() );
            $message = \esc_html( $response->get_error_message() );
            $title   = \esc_html__( 'Authorization Error', 'easy-mcp-ai' );

            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-console-styles.php';
            $site = \esc_html( Console_Styles::site_label() );

            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every interpolated value is escaped above; the stylesheet is plugin-owned (Console_Styles::inline()).
            echo '<!DOCTYPE html><html lang="' . \esc_attr( \get_locale() ) . '"><head>'
                . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<meta name="robots" content="noindex, nofollow">'
                . '<title>' . $title . ' &mdash; ' . $site . '</title>'
                . Console_Styles::inline()
                . '</head><body class="emcp-page">'
                . '<div class="emcp-shell emcp-shell--narrow">'
                . '<div class="emcp-topbar">' . Console_Styles::logo()
                . '<h1 class="emcp-topbar__title">' . $title . '</h1>'
                . Console_Styles::site_name() . '</div>'
                . '<div class="emcp-body"><div class="emcp-notice emcp-notice--error">'
                . '<p class="emcp-notice__title"><code class="emcp-mono">' . $code . '</code></p>'
                . '<p>' . $message . '</p>'
                . '</div></div></div>'
                . '<p class="emcp-pagenote"><span class="emcp-dot"></span>' . \esc_html__( 'Powered by Easy MCP AI', 'easy-mcp-ai' ) . '</p>'
                . '</body></html>';
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        
        
        
        
        $can_send_headers = ! headers_sent();

        if ( $can_send_headers ) {
            \status_header( $response->get_status() );

            
            
            header_remove( 'Content-Security-Policy' );
        }

        $headers      = $response->get_headers();
        $content_type = '';
        foreach ( $headers as $name => $value ) {
            if ( $can_send_headers ) {
                header( $name . ': ' . $value );
            }
            if ( 0 === strcasecmp( $name, 'Content-Type' ) ) {
                $content_type = (string) $value;
            }
        }

        $data = $response->get_data();
        if ( null === $data ) {
            return;
        }

        
        
        
        if ( is_string( $data ) && 0 === strpos( strtolower( $content_type ), 'text/html' ) ) {
            echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in template.
            return;
        }

        
        if ( '' === $content_type && $can_send_headers ) {
            header( 'Content-Type: application/json; charset=utf-8' );
        }
        echo \wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded.
    }

    public function register_rest_routes() {
        
        $this->load_mcp_includes();

        
        
        \Easy_MCP_AI\History\Change_Log_Schema::maybe_upgrade();
        
        
        
        
        Activator::maybe_upgrade_core_tables();
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tasks/class-task-schema.php';
        \Easy_MCP_AI\Tasks\Task_Schema::maybe_upgrade();


        $this->token_manager     = new Auth\Token_Manager();
        $this->tool_registry     = new Tools\Tool_Registry();
        $this->resource_registry = new Resources\Resource_Registry();
        $this->server            = new MCP\Server( $this->tool_registry, $this->resource_registry, $this->token_manager );

        $this->register_tools();
        $this->register_resources();

        
        
        
        
        
        

        
        
        
        if ( \Easy_MCP_AI\Config::get( 'easy_mcp_ai_change_log_enabled', true ) ) {
            $change_recorder = new \Easy_MCP_AI\History\Change_Recorder(
                new \Easy_MCP_AI\History\Change_Log_Repository()
            );
            $change_recorder->register();

            
            
            
            \add_action( 'easy_mcp_ai_change_context_disarming', array( $change_recorder, 'reassert_hooks' ), 5 );

            
            
            if ( \Easy_MCP_AI\Config::get( 'easy_mcp_ai_change_log_capture_db', false ) ) {
                $db_interceptor = new \Easy_MCP_AI\History\Change_DB_Interceptor(
                    new \Easy_MCP_AI\History\Change_Log_Repository()
                );
                \add_action( 'easy_mcp_ai_change_context_armed', array( $db_interceptor, 'arm' ) );
                \add_action( 'easy_mcp_ai_change_context_disarming', array( $db_interceptor, 'disarm' ), 20 );
            }

            
            
            if ( \Easy_MCP_AI\Config::get( 'easy_mcp_ai_change_log_external_intent', true ) ) {
                ( new \Easy_MCP_AI\History\Change_External_Intent(
                    new \Easy_MCP_AI\History\Change_Log_Repository()
                ) )->register();
            }
        }

        $transport = new MCP\Transport( $this->server, $this->token_manager );
        $transport->register_routes();

        
        
        
        
        
        MCP\Rest_Auth_Override::register();

        
        if ( \apply_filters( 'easy_mcp_ai_oauth_enabled', \Easy_MCP_AI\Config::get( 'oauth_enabled' ) ) ) {
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-schema.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-scope-map.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-discovery.php';
            
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-client-ip.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-client-registry.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-token-manager.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-token-validator.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-authorization-endpoint.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-token-endpoint.php';
            
            
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-device-authorization.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-consent-screen.php';
            require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-routes.php';

            
            
            
            
            
            
            
            
            
            
            
            
            
            OAuth\OAuth_Schema::maybe_upgrade();

            $oauth_routes = new OAuth\OAuth_Routes();
            $oauth_routes->register_routes();
        }

        
        
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/rest/class-admin-routes.php';
        ( new Admin\Rest\Admin_Routes( $this->tool_registry ) )->register();
    }

    private function register_tools() {
        $tool_dirs = array(
            'posts', 'pages', 'media', 'taxonomy', 'comments',
            'users', 'site', 'menus', 'plugins', 'themes',
            'revisions', 'meta', 'search', 'blocks', 'cpt', 'templates', 'styles',
            'appearance', 'widgets', 'history', 'audit', 'site-health',
        );

        
        
        
        
        if ( \get_option( 'easy_mcp_ai_ahrefs_enabled', false ) ) {
            $tool_dirs[] = 'ahrefs';
        }
        
        if ( ! empty( \get_option( \Easy_MCP_AI\GSC\GSC_Client::OPTION_JSON, '' ) ) ) {
            $tool_dirs[] = 'gsc';
        }
        
        if ( ! empty( \get_option( \Easy_MCP_AI\GA\GA_Client::OPTION_JSON, '' ) ) ) {
            $tool_dirs[] = 'ga';
        }
        
        $dfs_login    = \get_option( \Easy_MCP_AI\DFS\DataforSEO_Client::OPTION_LOGIN, '' );
        $dfs_api_pwd  = \get_option( \Easy_MCP_AI\DFS\DataforSEO_Client::OPTION_API_PASSWORD, '' );
        if ( ! empty( $dfs_login ) && ! empty( $dfs_api_pwd ) ) {
            $tool_dirs[] = 'dfs';
        }
        
        if ( ! empty( \get_option( \Easy_MCP_AI\Semrush\Semrush_Client::OPTION_API_KEY, '' ) ) ) {
            $tool_dirs[] = 'semrush';
        }
        
        if ( ! empty( \get_option( \Easy_MCP_AI\SeRanking\SeRanking_Client::OPTION_API_KEY, '' ) ) ) {
            $tool_dirs[] = 'seranking';
        }
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/users/trait-user-meta-auth-guard.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/taxonomy/trait-term-meta-auth-guard.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/blocks/trait-block-tree.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/acf/trait-acf-rest-tool.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/comments/trait-comment-moderation.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/widgets/trait-widget-rest.php';
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/plugins/trait-lifecycle-guard.php';

        foreach ( $tool_dirs as $dir ) {
            $tool_path = EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/' . $dir . '/';
            if ( is_dir( $tool_path ) ) {
                $files = glob( $tool_path . 'class-*.php' );
                if ( $files ) {
                    foreach ( $files as $file ) {
                        require_once $file;
                    }
                }
            }
        }

        
        $enabled_plugin_groups = (array) \get_option( 'easy_mcp_ai_enabled_plugin_groups', array() );
        if ( ! empty( $enabled_plugin_groups ) ) {
            $group_dir_map = array(
                'woocommerce'         => 'woocommerce',
                'acf'                 => 'acf',
                'the-events-calendar' => 'events-calendar',
                'buddypress'          => 'buddypress',
                'yoast-seo'           => 'seo/yoast',
                'rank-math'           => 'seo/rank-math',
                'aioseo'              => 'seo/aioseo',
                'seopress'            => 'seo/seopress',
                'slim-seo'            => 'seo/slim-seo',
                'the-seo-framework'   => 'seo/the-seo-framework',
            );
            $dirs_to_load = array();
            foreach ( $enabled_plugin_groups as $group_slug ) {
                if ( isset( $group_dir_map[ $group_slug ] ) ) {
                    $dirs_to_load[ $group_dir_map[ $group_slug ] ] = true;
                }
            }
            foreach ( array_keys( $dirs_to_load ) as $plugin_dir ) {
                $plugin_tool_path = EASY_MCP_AI_PLUGIN_DIR . 'includes/tools/' . $plugin_dir . '/';
                if ( is_dir( $plugin_tool_path ) ) {
                    $plugin_files = glob( $plugin_tool_path . 'class-*.php' );
                    if ( $plugin_files ) {
                        foreach ( $plugin_files as $file ) {
                            require_once $file;
                        }
                    }
                }
            }
        }

        $this->tool_registry->auto_discover();

        
        
        
        
        
        
        
        
        
        
        
        
        
        $registry          = $this->tool_registry;
        $dynamic_registrar = new Tools\Dynamic_Tool_Registrar();
        if ( \doing_action( 'init' ) ) {
            \add_action(
                'wp_loaded',
                static function () use ( $dynamic_registrar, $registry ) {
                    $dynamic_registrar->register_to( $registry );
                }
            );
        } else {
            $dynamic_registrar->register_to( $registry );
        }
    }

    private function register_resources() {
        $files = glob( EASY_MCP_AI_PLUGIN_DIR . 'includes/resources/class-*-resource.php' );
        if ( $files ) {
            foreach ( $files as $file ) {
                require_once $file;
            }
        }
        $this->resource_registry->auto_discover();
    }

    




































    public function cleanup_oauth_storage() {
        if ( ! \wp_doing_cron() ) {
            return;
        }
        global $wpdb;
        $codes_table    = $wpdb->prefix . 'easy_mcp_ai_oauth_codes';
        $tokens_table   = $wpdb->prefix . 'easy_mcp_ai_oauth_access_tokens';
        $clients_table  = $wpdb->prefix . 'easy_mcp_ai_oauth_clients';
        $consents_table = $wpdb->prefix . 'easy_mcp_ai_oauth_consents';
        $device_table   = $wpdb->prefix . 'easy_mcp_ai_oauth_device_codes';

        
        
        
        
        
        
        require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-site-move.php';
        OAuth\OAuth_Site_Move::sweep();

        $i = 0;
        do {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables; names prefixed by $wpdb->prefix.
            $deleted = $wpdb->query(
                "DELETE FROM `{$codes_table}` WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT 500"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } while ( $deleted > 0 && ++$i < self::CLEANUP_MAX_ITERATIONS );

        $i = 0;
        do {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables; names prefixed by $wpdb->prefix.
            $deleted = $wpdb->query(
                "DELETE FROM `{$tokens_table}` WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) AND COALESCE(refresh_expires_at, expires_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) LIMIT 500"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } while ( $deleted > 0 && ++$i < self::CLEANUP_MAX_ITERATIONS );

        
        
        
        
        
        
        $i = 0;
        do {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables; names prefixed by $wpdb->prefix.
            $deleted = $wpdb->query(
                "DELETE FROM `{$device_table}` WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT 500"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } while ( $deleted > 0 && ++$i < self::CLEANUP_MAX_ITERATIONS );

        $client_retention = self::oauth_client_retention_days();
        if ( $client_retention < 1 ) {
            return; 
        }

        $i = 0;
        do {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables; names prefixed by $wpdb->prefix. Retention days is bound via prepare().
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `{$clients_table}`
                     WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
                       AND NOT EXISTS (SELECT 1 FROM `{$tokens_table}` t   WHERE t.client_id = `{$clients_table}`.client_id)
                       AND NOT EXISTS (SELECT 1 FROM `{$consents_table}` s WHERE s.client_id = `{$clients_table}`.client_id)
                       AND NOT EXISTS (SELECT 1 FROM `{$codes_table}` k    WHERE k.client_id = `{$clients_table}`.client_id)
                       AND NOT EXISTS (SELECT 1 FROM `{$device_table}` d   WHERE d.client_id = `{$clients_table}`.client_id)
                     LIMIT 500",
                    $client_retention
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } while ( $deleted > 0 && ++$i < self::CLEANUP_MAX_ITERATIONS );
    }

    











    public static function oauth_client_retention_days() {
        $stored = \Easy_MCP_AI\Config::get( 'easy_mcp_ai_oauth_client_retention', self::DEFAULT_OAUTH_CLIENT_RETENTION );
        if ( ! is_numeric( $stored ) ) {
            $days = self::DEFAULT_OAUTH_CLIENT_RETENTION;
        } else {
            $days = (int) $stored;
        }
        if ( $days < 0 ) {
            $days = self::DEFAULT_OAUTH_CLIENT_RETENTION;
        }
        if ( $days > 3650 ) {
            $days = 3650;
        }

        




        $days = (int) \apply_filters( 'easy_mcp_ai_oauth_client_retention', $days );

        return ( $days < 0 ) ? 0 : $days;
    }

    public function cleanup_new_token_meta() {
        if ( ! \wp_doing_cron() ) {
            return;
        }
        global $wpdb;

        
        
        
        
        
        
        
        
        
        
        
        
        $draft_cutoff = time() - DAY_IN_SECONDS;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct DB required for batched usermeta cleanup.
        $drafts = $wpdb->get_results( $wpdb->prepare(
            "SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 500",
            '_easy_mcp_ai_token_form_draft'
        ), ARRAY_A );
        if ( $drafts ) {
            $expired = array();
            foreach ( $drafts as $row ) {
                $val = \maybe_unserialize( $row['meta_value'] );
                $at  = is_array( $val ) && isset( $val['saved_at'] ) ? (int) $val['saved_at'] : 0;
                if ( $at < $draft_cutoff ) {
                    $expired[] = (int) $row['umeta_id'];
                }
            }
            if ( $expired ) {
                
                
                
                
                
                
                $placeholders = implode( ',', array_fill( 0, count( $expired ), '%d' ) );
                $wpdb->query(
                    $wpdb->prepare(
                        
                        
                        
                        
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a generated list of %d and $wpdb->usermeta is a core table name; neither is input.
                        "DELETE FROM {$wpdb->usermeta} WHERE umeta_id IN ({$placeholders})",
                        $expired
                    )
                );
            }
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $cutoff = time() - DAY_IN_SECONDS;
        $i = 0;
        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct DB required for batched usermeta cleanup.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 500",
                $wpdb->esc_like( '_easy_mcp_ai_new_token_' ) . '%'
            ), ARRAY_A );
            $deleted = 0;
            if ( $rows ) {
                $expired_ids = array();
                foreach ( $rows as $row ) {
                    $val = \maybe_unserialize( $row['meta_value'] );
                    $exp = is_array( $val ) && isset( $val['expires'] ) ? (int) $val['expires'] : 0;
                    if ( $exp < $cutoff ) {
                        $expired_ids[] = (int) $row['umeta_id'];
                    }
                }
                if ( $expired_ids ) {
                    $placeholders = implode( ',', array_fill( 0, count( $expired_ids ), '%d' ) );
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated above; $expired_ids spread as bound args.
                    $deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE umeta_id IN ({$placeholders})", $expired_ids ) );
                }
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        } while ( $deleted > 0 && ++$i < self::CLEANUP_MAX_ITERATIONS );

        
        
        
        
        $tokens_table   = $wpdb->prefix . 'easy_mcp_ai_tokens';
        $inactive_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $i              = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table prefixed by $wpdb->prefix; batched daily sweep.
            $deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$tokens_table}` WHERE is_active = 0 AND updated_at < %s LIMIT 500", $inactive_cutoff ) );
        } while ( $deleted > 0 && ++$i < self::CLEANUP_MAX_ITERATIONS );
    }

    




    



















    public static function change_log_retention_days() {
        $stored = \Easy_MCP_AI\Config::get( 'easy_mcp_ai_change_log_retention', 30 );
        if ( ! is_numeric( $stored ) ) {
            return 30;
        }
        $days = (int) $stored;
        return $days < 0 ? 30 : $days;
    }

    public static function cleanup_change_log() {
        if ( ! \wp_doing_cron() ) {
            return;
        }
        $retention = self::change_log_retention_days();
        
        
        
        
        
        
        
        

        if ( ! class_exists( '\\Easy_MCP_AI\\History\\Change_Log_Repository' ) ) {
            $f = EASY_MCP_AI_PLUGIN_DIR . 'includes/history/class-change-log-repository.php';
            if ( ! file_exists( $f ) ) {
                return;
            }
            require_once $f;
        }
        $repo = new \Easy_MCP_AI\History\Change_Log_Repository();

        
        
        
        $budget = self::CLEANUP_MAX_ITERATIONS;

        
        
        
        
        
        
        
        
        $stored_db_retention = \Easy_MCP_AI\Config::get( 'easy_mcp_ai_change_log_db_retention', 7 );
        $db_retention        = is_numeric( $stored_db_retention ) ? (int) $stored_db_retention : 7;
        if ( $db_retention < 0 ) {
            $db_retention = 7;
        }
        if ( $db_retention > 0 ) {
            $db_cutoff = \gmdate( 'Y-m-d H:i:s', time() - ( $db_retention * DAY_IN_SECONDS ) );
            while ( $budget > 0 ) {
                $budget--;
                $n = $repo->delete_db_rows_older_than( $db_cutoff, 500 );
                if ( $n < 500 ) {
                    break;
                }
            }
        }

        
        
        
        if ( $retention > 0 ) {
            $cutoff = \gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );
            while ( $budget > 0 ) {
                $budget--;
                $n = $repo->delete_older_than( $cutoff, 500 );
                if ( $n < 500 ) {
                    break;
                }
            }
        }
    }

    public function cleanup_audit_log() {
        if ( ! \wp_doing_cron() ) {
            return;
        }
        global $wpdb;
        $retention = max( 1, (int) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_audit_log_retention', 30 ) );
        
        $i = 0;
        do {
            $table   = \esc_sql( $wpdb->prefix . 'easy_mcp_ai_audit_log' );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is not user input, direct DB required for batch cleanup.
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 500",
                $retention
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        } while ( $deleted > 0 && ++$i < self::CLEANUP_MAX_ITERATIONS );
    }

    public function on_new_site( $site ) {
        \switch_to_blog( $site->id );
        try {
            
            
            
            Activator::activate( false, false );
            \delete_option( 'rewrite_rules' );
            
            if ( \apply_filters( 'easy_mcp_ai_oauth_enabled', \Easy_MCP_AI\Config::get( 'oauth_enabled' ) ) ) {
                require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-schema.php';
                OAuth\OAuth_Schema::create_tables();
            }
        } finally {
            \restore_current_blog();
        }
    }

    








    public function on_drop_subsite_tables( $tables ) {
        global $wpdb;
        foreach ( array(
            'easy_mcp_ai_tokens',
            'easy_mcp_ai_audit_log',
            'easy_mcp_ai_oauth_clients',
            'easy_mcp_ai_oauth_codes',
            'easy_mcp_ai_oauth_access_tokens',
            'easy_mcp_ai_oauth_consents',
            'easy_mcp_ai_oauth_device_codes',
            'easy_mcp_ai_change_log',
            'easy_mcp_ai_tasks',
        ) as $suffix ) {
            $tables[] = $wpdb->prefix . $suffix;
        }
        return $tables;
    }

    


































    public static function exempt_oauth_client_credentials_from_application_passwords( $is_api_request ) {
        if ( ! $is_api_request ) {
            return $is_api_request;
        }
        if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) || ! is_string( $_SERVER['PHP_AUTH_USER'] ) ) {
            return $is_api_request;
        }
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read-only path match; not stored or output.

        
        if ( preg_match( '/^[a-f0-9]{32}$/', $_SERVER['PHP_AUTH_USER'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Shape test only; the value is neither stored nor output.
            && self::is_oauth_client_auth_path( $request_uri ) ) {
            return false;
        }

        
        
        
        
        
        
        
        
        
        if ( self::is_api_key_basic_path( $request_uri ) ) {
            if ( ! class_exists( '\\Easy_MCP_AI\\Auth_Header' ) ) {
                require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-auth-header.php';
            }
            $pw = isset( $_SERVER['PHP_AUTH_PW'] ) && is_string( $_SERVER['PHP_AUTH_PW'] ) ? $_SERVER['PHP_AUTH_PW'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Shape test only; the value is neither stored nor output.
            
            
            if ( Auth_Header::pair_has_plugin_credential_shape( $_SERVER['PHP_AUTH_USER'], $pw ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Shape test only.
                return false;
            }
        }
        return $is_api_request;
    }

    









    public static function is_api_key_basic_path( $request_uri ) {
        $request_uri = (string) $request_uri;
        if ( '' === $request_uri ) {
            return false;
        }
        $candidates = array( $request_uri );
        if ( false !== strpos( $request_uri, 'rest_route=' ) ) {
            $candidates[] = rawurldecode( $request_uri );
        }
        foreach ( $candidates as $uri ) {
            $path = (string) wp_parse_url( $uri, PHP_URL_PATH );
            $rest = '';
            $qs   = (string) wp_parse_url( $uri, PHP_URL_QUERY );
            if ( '' !== $qs ) {
                parse_str( $qs, $q );
                $rest = isset( $q['rest_route'] ) && is_string( $q['rest_route'] ) ? $q['rest_route'] : '';
            }
            foreach ( array( $path, $rest ) as $p ) {
                if ( '' === $p ) {
                    continue;
                }
                if ( preg_match( '#/easy-mcp-ai/v1/(mcp|header-probe)(/|$)#', $p ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    













    public static function is_oauth_client_auth_path( $request_uri ) {
        $request_uri = (string) $request_uri;
        if ( '' === $request_uri ) {
            return false;
        }
        $candidates = array( $request_uri );
        if ( false !== strpos( $request_uri, 'rest_route=' ) ) {
            $candidates[] = rawurldecode( $request_uri );
        }
        foreach ( $candidates as $uri ) {
            $path = (string) wp_parse_url( $uri, PHP_URL_PATH );
            $rest = '';
            $qs   = (string) wp_parse_url( $uri, PHP_URL_QUERY );
            if ( '' !== $qs ) {
                parse_str( $qs, $q );
                $rest = isset( $q['rest_route'] ) && is_string( $q['rest_route'] ) ? $q['rest_route'] : '';
            }
            foreach ( array( $path, $rest ) as $p ) {
                $p = rtrim( $p, '/' );
                if ( '' === $p ) {
                    continue;
                }
                foreach ( array( '/easy-mcp-ai/v1/oauth/token', '/easy-mcp-ai/v1/oauth/revoke' ) as $suffix ) {
                    if ( substr( $p, -strlen( $suffix ) ) === $suffix ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    


    public function maybe_upgrade_oauth() {
        
        
        if ( ! \is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        if ( ! \apply_filters( 'easy_mcp_ai_oauth_enabled', \Easy_MCP_AI\Config::get( 'oauth_enabled' ) ) ) {
            return;
        }
        $oauth_schema_file = EASY_MCP_AI_PLUGIN_DIR . 'includes/oauth/class-oauth-schema.php';
        if ( file_exists( $oauth_schema_file ) ) {
            require_once $oauth_schema_file;
            OAuth\OAuth_Schema::maybe_upgrade();
        }
    }

    



    private function server_for_background_work() {
        if ( null === $this->server && function_exists( 'rest_get_server' ) ) {
            \rest_get_server();
        }
        return $this->server;
    }

    








    public function run_task_tick( $task_id = null ) {
        $this->guarded_background_work( function ( $server ) use ( $task_id ) {
            if ( is_string( $task_id ) && '' !== $task_id ) {
                $server->get_task_manager()->run_background_tick( $task_id );
            }
        } );
    }

    
    public function cleanup_tasks() {
        $this->guarded_background_work( function ( $server ) {
            $server->get_task_manager()->cleanup();
        } );
    }

    private function guarded_background_work( callable $work ) {
        if ( function_exists( 'wp_installing' ) && \wp_installing() ) {
            return;
        }
        try {
            $server = $this->server_for_background_work();
            if ( $server ) {
                $work( $server );
            }
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 'Easy MCP AI background task work failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging
            }
        }
    }

    public function get_server() { return $this->server; }
    public function get_token_manager() { return $this->token_manager; }
    public function get_tool_registry() { return $this->tool_registry; }
}
