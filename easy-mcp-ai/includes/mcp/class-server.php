<?php
namespace Easy_MCP_AI\MCP;

use Easy_MCP_AI\Auth\Token_Manager;
use Easy_MCP_AI\Auth\Permission_Guard;
use Easy_MCP_AI\Tools\Tool_Registry;
use Easy_MCP_AI\Tools\Tool_State;
use Easy_MCP_AI\Resources\Resource_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Server {
    const PROTOCOL_VERSION = '2026-07-28';
    const LEGACY_PROTOCOL_VERSION = '2025-11-25';
    const SERVER_NAME      = 'easy-mcp-ai';

    const RATE_WINDOW_SECONDS = 60;

    const RATE_LIMIT_MESSAGE = 'Rate limit exceeded. Please try again later.';

    const PRIVILEGED_RATE_LIMIT_MESSAGE = 'Privileged tool rate limit exceeded. Please try again in a minute.';

    private const SENSITIVE_ARG_PATTERN = '/^(password|pass|secret|token|api[_\-]?key|authorization|content_base64|download_url|file_id|private[_\-]?key|access[_\-]?token|client[_\-]?secret|credential)$/';

    const INNER_TOOLS_META = \Easy_MCP_AI\Approvals\Approval_Gate::INNER_TOOLS_META;

    const RECEIPT_WITHHELD = 'this user no longer holds a capability the same call that ran needed, so its result is not sent again. Nothing was changed.';

    const STEP_UP_MESSAGE = 'Token does not have permission to use this tool. It needs the OAuth scope %s: ask the user to reconnect this app to the site and approve it.';

    const KEY_REFUSAL_MESSAGE = 'Token does not have permission to use this tool. This API key does not include it: if the user should have it, an administrator can add it to the key in wp-admin under %s.';

    const RESULT_JSON_FLAGS = JSON_UNESCAPED_SLASHES;

    const INSUFFICIENT_PERMISSIONS = 'Insufficient WordPress permissions for this tool';

    public static function effective_required_capability( $category, $tool_default_cap ) {
        if ( in_array( $category, \Easy_MCP_AI\Tools\Base_Tool::EXTERNAL_DATA_CATEGORIES, true ) ) {
            $cap = \Easy_MCP_AI\Config::get( 'easy_mcp_ai_external_data_min_capability', 'manage_options' );
            if ( ! is_string( $cap ) || ! in_array( $cap, array( 'manage_options', 'edit_others_posts', 'publish_posts' ), true ) ) {
                $cap = 'manage_options';
            }
            return $cap;
        }
        return $tool_default_cap;
    }

    private $tool_registry;
    private $resource_registry;
    private $token_manager;
    private $session_manager;
    private $permission_guard;
    private $audit_log_enabled;
    private $allowed_tool_patterns;
    private $last_negotiated_version;

    private $request_auth_source = null;
    private $request_wp_user_id  = 0;
    private $request_client_id   = null;
    private $request_scope       = null;
    private $scope_challenge     = null;

    private $task_manager        = null;
    private $skip_rate_limit     = false;
    private $rate_limit_refusal  = array( 'limit' => 0, 'retry_after' => self::RATE_WINDOW_SECONDS );
    private $rate_limit_message  = self::RATE_LIMIT_MESSAGE;
    private $task_call           = false;
    private $throttle_receipt    = array();
    private $approval_mode       = null;
    private $last_approval       = array( 'kind' => 'run', 'approval_id' => null );
    private $last_audit_id       = null;
    private $policy_refused      = false;

    private $approval_gate       = null;
    private $request_client_caps = null;
    private $request_client_protocol = null;

    private $feedback_queue      = null;

    private static $active_call = null;

    private static $ran_tools = array();

    private static $active_listing = null;

    public function __construct( Tool_Registry $tool_registry, Resource_Registry $resource_registry, Token_Manager $token_manager ) {
        $this->tool_registry     = $tool_registry;
        $this->resource_registry = $resource_registry;
        $this->token_manager     = $token_manager;
        $this->session_manager   = new Session();
        $this->permission_guard  = new Permission_Guard( $token_manager );
        $this->audit_log_enabled    = (bool)  \Easy_MCP_AI\Config::get( 'easy_mcp_ai_audit_log_enabled', true );
        $this->allowed_tool_patterns = (array) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_allowed_tool_patterns', array() );

        register_shutdown_function( array( $this, 'finalise_interrupted_calls' ) );
    }

    private $in_flight = array();

    public function finalise_interrupted_calls() {
        if ( class_exists( '\\Easy_MCP_AI\\Compat\\Compat_Registry' ) ) {
            \Easy_MCP_AI\Compat\Compat_Registry::release( 0 );
        }
        if ( empty( $this->in_flight ) ) {
            return;
        }
        $unfinished      = $this->in_flight;
        $this->in_flight = array();
        foreach ( $unfinished as $audit_id ) {
            $this->update_audit_status( (int) $audit_id, 'error' );
        }
        if ( ! class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) || ! \Easy_MCP_AI\History\Change_Context::is_active() ) {
            return;
        }
        try {
            \do_action( 'easy_mcp_ai_change_context_disarming' );
        } catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        } finally {
            \Easy_MCP_AI\History\Change_Context::clear();
        }
    }

    public function set_request_identity( $auth_source, $wp_user_id, $client_id = null, $scope = null ) {
        $this->request_auth_source = $auth_source;
        $this->request_wp_user_id  = (int) $wp_user_id;
        $this->request_client_id   = $client_id;
        $this->request_scope       = is_string( $scope ) ? $scope : null;
    }

    public function clear_request_identity() {
        $this->request_auth_source = null;
        $this->request_wp_user_id  = 0;
        $this->request_client_id   = null;
        $this->request_scope       = null;
    }

    public function take_scope_challenge() {
        $challenge             = $this->scope_challenge;
        $this->scope_challenge = null;
        return $challenge;
    }

    private function step_up_scope( $tool_name ) {
        if ( 'oauth' !== $this->request_auth_source ) {
            return null;
        }
        $scope = \Easy_MCP_AI\OAuth\Scope_Map::scope_for_tool( (string) $tool_name );
        $tool  = null === $scope ? null : $this->tool_registry->get_tool( $tool_name );
        if ( ! $tool || ! Tool_State::is_enabled( $tool ) || ! $this->tool_matches_pattern_filter( $tool_name ) ) {
            return null;
        }
        if ( self::is_app_only_tool( $tool ) && ! $this->client_declared_apps() ) {
            return null;
        }
        $cap = self::effective_required_capability( $tool->get_category(), $tool->get_required_capability() );
        return ! $cap || \current_user_can( $cap ) ? $scope : null;
    }

    private static function switched_off_ability( string $tool_name ): ?string {
        if ( 0 !== strpos( $tool_name, 'wp_ability_' ) ) {
            return null;
        }
        foreach ( \Easy_MCP_AI\Tools\Switched_Off_Inventory::abilities() as $entry ) {
            if ( $entry['name'] !== $tool_name ) {
                continue;
            }
            if ( ! \current_user_can( \Easy_MCP_AI\Tools\Dynamic_Tool_Registrar::required_capability( (string) $entry['slug'] ) ) ) {
                return null;
            }
            $status = null !== Tool_State::override_of( $tool_name ) ? array( 'status' => 'off_locked' ) : array( 'status' => 'off', 'where' => Tool_Status::switch_path() );
            return 'This tool ' . Tool_Status::predicate( $status );
        }
        return null;
    }

    private static function ability_refusal( ?\Easy_MCP_AI\Tools\Base_Tool $tool, array $arguments ): ?string {
        if ( ! $tool instanceof \Easy_MCP_AI\Tools\Dynamic_Tool ) {
            return null;
        }
        $arguments   = Gemini_Safe_Schema::coerce( $arguments, Gemini_Safe_Schema::sanitize( $tool->get_input_schema() )['map'] );
        $compat_mark = class_exists( '\\Easy_MCP_AI\\Compat\\Compat_Registry' ) ? \Easy_MCP_AI\Compat\Compat_Registry::mark() : null;
        try {
            if ( null !== $compat_mark ) {
                \Easy_MCP_AI\Compat\Compat_Registry::arm_for( $tool, $arguments );
            }
            return $tool->refusal( $arguments );
        } catch ( \Throwable $e ) {
            return null;
        } finally {
            if ( null !== $compat_mark ) {
                \Easy_MCP_AI\Compat\Compat_Registry::release( $compat_mark );
            }
        }
    }

    private function scope_challenge_for( $scope ) {
        $scopes   = preg_split( '/\s+/', trim( (string) $this->request_scope ), -1, PREG_SPLIT_NO_EMPTY );
        $scopes[] = $scope;
        return implode( ' ', array_values( array_unique( $scopes ) ) );
    }

    public function get_request_identity() {
        return array(
            'auth_source' => $this->request_auth_source,
            'wp_user_id'  => (int) $this->request_wp_user_id,
            'client_id'   => $this->request_client_id,
        );
    }

    public function get_token_manager() {
        return $this->token_manager;
    }

    public function get_task_manager() {
        if ( null === $this->task_manager ) {
            $this->task_manager = new \Easy_MCP_AI\Tasks\Task_Manager( $this, $this->tool_registry, new \Easy_MCP_AI\Tasks\Wpdb_Task_Store() );
        }
        return $this->task_manager;
    }

    public function set_task_manager( $task_manager ) {
        $this->task_manager = $task_manager;
    }

    public function get_approval_gate() {
        if ( null === $this->approval_gate ) {
            $manager = $this->token_manager;
            $this->approval_gate = new \Easy_MCP_AI\Approvals\Approval_Gate(
                new \Easy_MCP_AI\Approvals\Wpdb_Approval_Store(),
                static function ( $token_id ) use ( $manager ) {
                    $row = $manager->get_token_by_id( (int) $token_id );
                    return is_array( $row ) && ! empty( $row['unattended'] );
                }
            );
        }
        return $this->approval_gate;
    }

    public function set_approval_gate( $gate ) {
        $this->approval_gate = $gate;
    }

    public function set_feedback_queue( ?\Easy_MCP_AI\Feedback\Feedback_Queue $queue ): void {
        $this->feedback_queue = $queue;
    }

    public function set_request_client_caps( $caps, $protocol = null ) {
        $this->request_client_caps     = is_array( $caps ) ? $caps : ( is_object( $caps ) ? (array) $caps : null );
        $this->request_client_protocol = is_string( $protocol ) && '' !== $protocol ? $protocol : null;
    }

    private function client_declared_apps() {
        $caps = $this->request_client_caps;
        if ( ! is_array( $caps ) || ! isset( $caps['extensions'] ) ) {
            return false;
        }
        $extensions = is_object( $caps['extensions'] ) ? (array) $caps['extensions'] : $caps['extensions'];
        return is_array( $extensions ) && array_key_exists( 'io.modelcontextprotocol/ui', $extensions );
    }

    private static function is_app_only_definition( array $definition ) {
        if ( ! isset( $definition['_meta']['ui']['visibility'] ) || ! is_array( $definition['_meta']['ui']['visibility'] ) ) {
            return false;
        }
        return ! in_array( 'model', $definition['_meta']['ui']['visibility'], true );
    }

    public static function is_app_only_tool( object $tool ): bool {
        if ( ! method_exists( $tool, 'get_ui_meta' ) ) {
            return false;
        }
        try {
            $ui = $tool->get_ui_meta();
        } catch ( \Throwable $e ) {
            return false;
        }
        return is_array( $ui ) && self::is_app_only_definition( array( '_meta' => array( 'ui' => $ui ) ) );
    }

    private function request_identity( $token_id ) {
        return array(
            'auth_source'     => 'oauth' === $this->request_auth_source ? 'oauth' : 'legacy',
            'token_id'        => (int) $token_id,
            'oauth_client_id' => is_string( $this->request_client_id ) ? $this->request_client_id : null,
            'wp_user_id'      => (int) $this->request_wp_user_id,
        );
    }

    public function call_tool_internal( $id, $tool_name, array $arguments, $token_id, $allowed_tools, $count_rate_limit, $approval = null, array $answer = array() ) {
        $previous              = $this->skip_rate_limit;
        $previous_mode         = $this->approval_mode;
        $this->skip_rate_limit = ! $count_rate_limit;
        $this->approval_mode   = $approval;
        $params                = array( 'name' => $tool_name, 'arguments' => $arguments );
        foreach ( array( 'requestState', 'inputResponses' ) as $key ) {
            if ( array_key_exists( $key, $answer ) ) {
                $params[ $key ] = $answer[ $key ];
            }
        }
        try {
            return $this->handle_tools_call( $id, $params, $token_id, $allowed_tools );
        } finally {
            $this->skip_rate_limit = $previous;
            $this->approval_mode   = $previous_mode;
        }
    }

    public function call_tool_for_task( string $tool_name, array $arguments, $token_id, $allowed_tools ): array {
        $previous             = $this->task_call;
        $this->task_call      = true;
        $this->policy_refused = false;
        try {
            $response = $this->call_tool_internal( 0, $tool_name, $arguments, $token_id, $allowed_tools, false, 'skip' );
        } finally {
            $this->task_call = $previous;
        }
        if ( ! $this->policy_refused ) {
            return $response;
        }
        $this->policy_refused = false;
        $text                 = (string) ( $response['result']['content'][0]['text'] ?? '' );
        return JSON_RPC::error_response( $response['id'] ?? 0, Error_Codes::FORBIDDEN, 0 === strpos( $text, 'Error: ' ) ? substr( $text, 7 ) : $text );
    }

    public function get_last_approval() {
        return $this->last_approval;
    }

    public static function call_as_current_caller( string $tool_name, array $arguments, $approval = null, bool $count_rate_limit = true ): ?array {
        if ( null === self::$active_call ) {
            return null;
        }
        $server                 = self::$active_call['server'];
        $server->last_audit_id  = null;
        $server->last_approval  = array( 'kind' => 'run', 'approval_id' => null );
        $server->policy_refused = false;
        $response               = $server->call_tool_internal(
            'composite-' . $tool_name,
            $tool_name,
            $arguments,
            self::$active_call['token_id'],
            self::$active_call['allowed_tools'],
            $count_rate_limit,
            'skip' === $approval ? 'skip' : null
        );
        $refused                = $server->policy_refused;
        $server->policy_refused = false;
        return array(
            'response' => $response,
            'approval' => $server->get_last_approval(),
            'audit_id' => $server->last_audit_id,
            'refused'  => $refused,
        );
    }

    public static function current_call_approval_kind(): ?string {
        if ( null === self::$active_call ) {
            return null;
        }
        return isset( self::$active_call['approval_kind'] ) ? (string) self::$active_call['approval_kind'] : null;
    }

    public function audit_task_event( $token_id, $event, array $details ) {
        $audit_id = $this->log_tool_call( $token_id, $event, $details, 'success' );
        return (int) $audit_id;
    }

    public function handle_message( $message, $token_id = null, $allowed_tools = null ) {
        $method = isset( $message['method'] ) ? $message['method'] : '';
        $params = isset( $message['params'] ) ? $message['params'] : array();
        $id     = isset( $message['id'] ) ? $message['id'] : null;

        if ( JSON_RPC::is_notification( $message ) ) {
            return null;
        }

        switch ( $method ) {
            case 'initialize':
                return $this->handle_initialize( $id, $params, $token_id, $allowed_tools );
            case 'ping':
                return JSON_RPC::success_response( $id, new \stdClass() );
            case 'tools/list':
                return $this->handle_tools_list( $id, $params, $token_id, $allowed_tools );
            case 'tools/call':
                return $this->handle_tools_call( $id, $params, $token_id, $allowed_tools );
            case 'resources/list':
                return $this->handle_resources_list( $id, $params, $token_id, $allowed_tools );
            case 'resources/read':
                return $this->handle_resources_read( $id, $params, $token_id, $allowed_tools );
            case 'prompts/list':
                return $this->handle_prompts_list( $id, $token_id );
            case 'prompts/get':
                return $this->handle_prompts_get( $id, $params, $token_id );
            default:
                return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, 'Method not found' );
        }
    }

    const LEGACY_PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );
    const SUPPORTED_PROTOCOL_VERSIONS = array( '2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26' );
    const REFUSAL_RESULT_PROTOCOL = '2025-11-25';

    public function handle_modern_message( $message, $token_id = null, $allowed_tools = null ) {
        $id = isset( $message['id'] ) ? $message['id'] : null;
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        $caps = isset( $message['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] ) ? $message['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] : null;
        $this->set_request_client_caps( is_object( $caps ) ? (array) $caps : ( is_array( $caps ) ? $caps : array() ), self::PROTOCOL_VERSION );
        try {
            return $this->dispatch_modern_message( $message, $id, $token_id, $allowed_tools );
        } finally {
            $this->request_client_caps     = null;
            $this->request_client_protocol = null;
        }
    }

    private function dispatch_modern_message( $message, $id, $token_id, $allowed_tools ) {
        $method = $message['method'];
        if ( 'server/discover' === $method ) {
            if ( ! $this->check_rate_limit( $token_id ) ) {
                return $this->rate_limited( $id );
            }
            self::arm_skill_sync();
            $response = JSON_RPC::success_response( $id, array(
                'supportedVersions' => self::SUPPORTED_PROTOCOL_VERSIONS,
                'capabilities' => array(
                    'tools'      => new \stdClass(),
                    'resources'  => new \stdClass(),
                    'prompts'    => new \stdClass(),
                    'extensions' => array(
                        'io.modelcontextprotocol/tasks'  => new \stdClass(),
                        'io.modelcontextprotocol/skills' => new \stdClass(),
                    ),
                ),
                'instructions' => $this->instructions( $token_id, $allowed_tools ),
            ) );
        } elseif ( in_array( $method, array( 'tasks/get', 'tasks/update', 'tasks/cancel' ), true ) ) {
            $response = $this->handle_task_method( $id, $method, isset( $message['params'] ) ? $message['params'] : array(), $token_id, $allowed_tools );
        } elseif ( 'tools/call' === $method ) {
            $response = $this->handle_modern_tools_call( $message, $token_id, $allowed_tools );
        } elseif ( in_array( $method, array( 'tools/list', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get' ), true ) ) {
            $response = $this->handle_message( $message, $token_id, $allowed_tools );
        } elseif ( 'skills/list' === $method ) {
            $response = $this->handle_skills_list( $id, $token_id );
        } elseif ( 'skills/get' === $method ) {
            $response = $this->handle_skills_get( $id, isset( $message['params'] ) ? $message['params'] : array(), $token_id );
        } elseif ( 'subscriptions/listen' === $method && self::listens_for_tasks( $message ) && ! self::client_declared_tasks( $message['params'] ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::MISSING_CLIENT_CAPABILITY, 'Missing required client capability', array(
                'requiredCapabilities' => array( 'extensions' => array( 'io.modelcontextprotocol/tasks' => new \stdClass() ) ),
            ) );
        } elseif ( 'subscriptions/listen' === $method ) {
            $response = $this->handle_subscriptions_listen( $id, isset( $message['params'] ) ? $message['params'] : array(), $token_id );
        } else {
            return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, 'Method not found' );
        }
        if ( isset( $response['error'] ) ) {
            if ( 'resources/read' === $method && Error_Codes::RESOURCE_NOT_FOUND === $response['error']['code'] ) {
                $response['error']['code'] = Error_Codes::INVALID_PARAMS;
            }
            return $response;
        }
        if ( ! isset( $response['result']['resultType'] ) ) {
            $response['result']['resultType'] = 'complete';
        }
        $response['result']['_meta']['io.modelcontextprotocol/serverInfo'] = array(
            'name' => self::SERVER_NAME, 'version' => EASY_MCP_AI_VERSION,
        );
        if ( in_array( $method, array( 'server/discover', 'tools/list', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get', 'skills/list', 'skills/get' ), true ) ) {
            $response['result']['ttlMs'] = 0;
            $response['result']['cacheScope'] = 'private';
        }
        if ( 'tools/list' === $method ) {
            usort( $response['result']['tools'], function ( $a, $b ) {
                return strcmp( $a['name'], $b['name'] );
            } );
        }
        return $response;
    }

    private function handle_modern_tools_call( $message, $token_id, $allowed_tools ) {
        $params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
        if ( self::client_declared_tasks( $params ) ) {
            $response = $this->get_task_manager()->call( isset( $message['id'] ) ? $message['id'] : null, $params, $token_id, $allowed_tools );
            if ( null !== $response ) {
                return $response;
            }
        }
        return $this->handle_message( $message, $token_id, $allowed_tools );
    }

    public static function client_declared_tasks( $params ) {
        $key = 'io.modelcontextprotocol/clientCapabilities';
        if ( ! is_array( $params ) || ! isset( $params['_meta'][ $key ]['extensions'] ) || ! is_array( $params['_meta'][ $key ]['extensions'] ) ) {
            return false;
        }
        $extensions = $params['_meta'][ $key ]['extensions'];
        return array_key_exists( 'io.modelcontextprotocol/tasks', $extensions ) && is_array( $extensions['io.modelcontextprotocol/tasks'] );
    }

    private function handle_subscriptions_listen( $id, $params, $token_id ) {
        $params = is_array( $params ) ? $params : array();
        if ( array_key_exists( 'notifications', $params ) && ! is_array( $params['notifications'] ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'notifications must be a JSON object' );
        }
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return $this->rate_limited( $id );
        }
        return JSON_RPC::success_response( $id, array(
            '_meta' => array( self::SUBSCRIPTION_ID_META => $id ),
        ) );
    }

    const SUBSCRIPTION_ID_META = 'io.modelcontextprotocol/subscriptionId';

    public static function subscriptions_acknowledged( $id ) {
        return array(
            'jsonrpc' => '2.0',
            'method'  => 'notifications/subscriptions/acknowledged',
            'params'  => array(
                '_meta'         => array( self::SUBSCRIPTION_ID_META => $id ),
                'notifications' => new \stdClass(),
            ),
        );
    }

    private static function listens_for_tasks( $message ) {
        return isset( $message['params']['notifications'] ) && is_array( $message['params']['notifications'] )
            && array_key_exists( 'taskIds', $message['params']['notifications'] );
    }

    private function handle_task_method( $id, $method, $params, $token_id, $allowed_tools ) {
        $params = is_array( $params ) ? $params : array();
        if ( ! self::client_declared_tasks( $params ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::MISSING_CLIENT_CAPABILITY, 'Missing required client capability', array(
                'requiredCapabilities' => array( 'extensions' => array( 'io.modelcontextprotocol/tasks' => new \stdClass() ) ),
            ) );
        }
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return $this->rate_limited( $id );
        }
        $manager = $this->get_task_manager();
        if ( 'tasks/get' === $method ) {
            return $manager->get( $id, $params, $token_id, $allowed_tools );
        }
        if ( 'tasks/cancel' === $method ) {
            return $manager->cancel( $id, $params, $token_id, $allowed_tools );
        }
        return $manager->update( $id, $params, $token_id );
    }

    private function handle_initialize( $id, $params, $token_id, ?array $allowed_tools = null ) {
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return $this->rate_limited( $id );
        }

        $client_version = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : null;
        if ( $client_version && in_array( $client_version, self::LEGACY_PROTOCOL_VERSIONS, true ) ) {
            $negotiated_version = $client_version;
        } else {
            $negotiated_version = self::LEGACY_PROTOCOL_VERSION;
        }

        $this->last_negotiated_version = $negotiated_version;

        self::arm_skill_sync();
        return JSON_RPC::success_response( $id, array(
            'protocolVersion' => $negotiated_version,
            'capabilities'    => array(
                'tools'     => new \stdClass(),
                'resources' => new \stdClass(),
                'prompts'   => new \stdClass(),
            ),
            'serverInfo'      => array( 'name' => self::SERVER_NAME, 'version' => EASY_MCP_AI_VERSION ),
            'instructions'    => $this->instructions( $token_id, $allowed_tools ),
        ) );
    }

    const INSTRUCTIONS = 'WordPress MCP Server. Use tools to manage posts, pages, media, comments, users, and site settings. Use resources to read site information.';

    const FEEDBACK_INSTRUCTIONS = 'When a tool fails or does not do what it says on a valid request (not a wrong ID, a missing item or a permission refusal), or the user needs something no tool here does, call wp_send_feedback (kind "bug" or "missing_feature", a summary under 200 characters) before you answer: it only queues a report for a person to approve, so do not ask the user first.';

    const DISABLED_TOOLS_INSTRUCTIONS = 'Switched-off tools, plugin integrations and abilities are not in your tool list: when no tool you have does a task, call wp_list_disabled_tools with a few words of it before saying it cannot be done.';

    const CLI_INSTRUCTIONS = 'wp_cli_run is standard WP-CLI: use the commands you know (`option get`, `option patch`, `post meta`) for what no tool does. A command a tool performs needs that tool on. It refuses PHP, SQL, wp-config and commands added by plugins.';

    const CLI_LOOKUP_INSTRUCTIONS = 'If it is not in your tool list, call wp_list_disabled_tools with "wp_cli_run" and tell the user what it says.';

    private function instructions( $token_id, ?array $allowed_tools = null ): string {
        $disabled = $this->sentence_while_available( $token_id, $allowed_tools, 'wp_list_disabled_tools', self::DISABLED_TOOLS_INSTRUCTIONS );
        $cli      = $this->cli_instructions( $token_id, $allowed_tools );
        $parts    = array(
            'disabled' => $disabled,
            'wp_cli'   => $this->cli_sentence( $token_id, '' !== $disabled ),
            'cli'      => '' === $disabled ? $cli : '',
            'feedback' => $this->sentence_while_available( $token_id, $allowed_tools, 'wp_send_feedback', self::FEEDBACK_INSTRUCTIONS ),
            'waiting'  => $this->feedback_line( $token_id ),
        );
        $text = $this->instructions_with( $token_id, $allowed_tools, $parts );
        foreach ( array( 'disabled', 'wp_cli' ) as $part ) {
            if ( strlen( $text ) <= self::instructions_limit() ) {
                break;
            }
            if ( '' !== $parts[ $part ] ) {
                $parts[ $part ] = '';
                $text           = $this->instructions_with( $token_id, $allowed_tools, $parts );
            }
        }
        if ( '' !== $disabled && '' !== $cli && '' === $parts['disabled'] && '' === $parts['wp_cli'] ) {
            $parts['disabled'] = $disabled;
            $text              = $this->instructions_with( $token_id, $allowed_tools, $parts );
        }
        return $text;
    }

    private function cli_sentence( $token_id, bool $lookup ): string {
        if ( null === $token_id || null === $this->tool_registry->get_tool( 'wp_cli_run' ) ) {
            return '';
        }
        return $lookup ? self::CLI_INSTRUCTIONS . ' ' . self::CLI_LOOKUP_INSTRUCTIONS : self::CLI_INSTRUCTIONS;
    }

    private function instructions_with( $token_id, ?array $allowed_tools, array $parts ): string {
        $tail = implode( ' ', array_filter( $parts, 'strlen' ) );
        $base = $this->skill_instructions( $token_id, $allowed_tools, '' === $tail ? 0 : strlen( $tail ) + 1 );
        return '' === $tail ? $base : $base . ' ' . $tail;
    }

    private static function instructions_limit(): int {
        return \Easy_MCP_AI\Skills\Skill_Instructions::MAX_LENGTH;
    }

    private function sentence_while_available( $token_id, ?array $allowed_tools, string $tool_name, string $sentence ): string {
        if ( null === $token_id ) {
            return '';
        }
        try {
            return 'available' === $this->tool_status_for( $token_id, $allowed_tools, $tool_name )['status'] ? $sentence : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    private function feedback_line( $token_id ): string {
        if ( null === $token_id || \Easy_MCP_AI\Config::is_white_labelled() || ! Tool_State::resolve( 'wp_send_feedback', false )[0] ) {
            return '';
        }
        try {
            if ( null === $this->feedback_queue ) {
                $this->feedback_queue = \Easy_MCP_AI\Feedback\Feedback_Queue::create();
            }
            return (string) $this->feedback_queue->instructions_line( $this->request_identity( $token_id ) );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    private function skill_instructions( $token_id, ?array $allowed_tools, int $reserve ): string {
        try {
            $served = \Easy_MCP_AI\Skills\Skills::served();
            if ( $served->is_empty() ) {
                return self::INSTRUCTIONS;
            }
            $can_use = null !== $token_id && $this->token_may_use( $token_id, is_array( $allowed_tools ) ? $allowed_tools : null, 'wp_get_skill' );
            return \Easy_MCP_AI\Skills\Skill_Instructions::build( $served, $can_use, self::INSTRUCTIONS, $reserve );
        } catch ( \Throwable $e ) {
            return self::INSTRUCTIONS;
        }
    }

    private static function arm_skill_sync(): void {
        try {
            \Easy_MCP_AI\Skills\Skill_Sync::arm();
        } catch ( \Throwable $e ) {
            return;
        }
    }

    private function skills_gate( $id, $token_id, bool $is_list ): ?array {
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        if ( self::is_paused() && ! $is_list ) {
            return JSON_RPC::error_response( $id, Error_Codes::PAUSED, Error_Codes::PAUSED_MESSAGE );
        }
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return $this->rate_limited( $id );
        }
        if ( ! \current_user_can( 'read' ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Insufficient permissions to read skills' );
        }
        return null;
    }

    private static function served_skills(): ?\Easy_MCP_AI\Skills\Skill_Set {
        if ( self::is_paused() ) {
            return null;
        }
        return \Easy_MCP_AI\Skills\Skills::served();
    }

    private function handle_skills_list( $id, $token_id ): array {
        $refusal = $this->skills_gate( $id, $token_id, true );
        if ( null !== $refusal ) {
            return $refusal;
        }
        $entries = array();
        $served  = self::served_skills();
        foreach ( null === $served ? array() : $served->skills() as $skill ) {
            $entries[] = \Easy_MCP_AI\Skills\Skill_Set::entry( $skill );
        }
        return JSON_RPC::success_response( $id, array( 'skills' => $entries ) );
    }

    private function handle_skills_get( $id, $params, $token_id ): array {
        $refusal = $this->skills_gate( $id, $token_id, false );
        if ( null !== $refusal ) {
            return $refusal;
        }
        $uri = is_array( $params ) && isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '';
        foreach ( self::served_skills()->skills() as $skill ) {
            if ( \Easy_MCP_AI\Skills\Skill_Set::uri( $skill ) === $uri ) {
                return JSON_RPC::success_response( $id, array( 'skill' => \Easy_MCP_AI\Skills\Skill_Set::entry( $skill ) ) );
            }
        }
        return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, '' === $uri ? 'Missing skill URI' : 'No skill is served at ' . $uri );
    }

    private function handle_prompts_list( $id, $token_id ): array {
        $refusal = $this->skills_gate( $id, $token_id, true );
        if ( null !== $refusal ) {
            return $refusal;
        }
        $prompts = array();
        $served  = self::served_skills();
        foreach ( null === $served ? array() : $served->skills() as $skill ) {
            $prompts[] = array(
                'name'        => $skill['name'],
                'title'       => ucfirst( str_replace( '-', ' ', $skill['name'] ) ),
                'description' => \Easy_MCP_AI\Skills\Skill_Set::description( $skill ),
                'arguments'   => array(),
            );
        }
        return JSON_RPC::success_response( $id, array( 'prompts' => $prompts ) );
    }

    private function handle_prompts_get( $id, $params, $token_id ): array {
        $refusal = $this->skills_gate( $id, $token_id, false );
        if ( null !== $refusal ) {
            return $refusal;
        }
        $name  = is_array( $params ) && isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
        $skill = self::served_skills()->find( $name );
        if ( null === $skill ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Unknown prompt' );
        }
        return JSON_RPC::success_response( $id, array(
            'description' => \Easy_MCP_AI\Skills\Skill_Set::description( $skill ),
            'messages'    => array(
                array(
                    'role'    => 'user',
                    'content' => array(
                        'type' => 'text',
                        'text' => 'Use the "' . $skill['name'] . '" skill below on this site. Follow its steps in order. For a step whose tool is not available to this connection, tell me what would need to change instead of skipping it silently.',
                    ),
                ),
                array(
                    'role'    => 'user',
                    'content' => array(
                        'type'     => 'resource',
                        'resource' => array(
                            'uri'      => \Easy_MCP_AI\Skills\Skill_Set::uri( $skill ),
                            'mimeType' => 'text/markdown',
                            'text'     => $skill['files'][0]['text'],
                        ),
                    ),
                ),
            ),
        ) );
    }

    const CLI_LIFECYCLE_TOOLS = array( 'wp_install_plugin', 'wp_delete_plugin', 'wp_uninstall_plugin', 'wp_install_theme', 'wp_delete_theme', 'wp_update_core' );

    private function cli_instructions( $token_id, ?array $allowed_tools ): string {
        if ( null === $token_id ) {
            return '';
        }
        try {
            $cli = $this->tool_status_for( $token_id, $allowed_tools, 'wp_cli_run' );
            if ( ! in_array( $cli['status'], array( 'off', 'off_locked' ), true ) ) {
                return '';
            }
            $off    = 'off' === $cli['status'] ? array( 'wp_cli_run' ) : array();
            $locked = 'off_locked' === $cli['status'] ? array( 'wp_cli_run' ) : array();
            $needed = array();
            foreach ( self::CLI_LIFECYCLE_TOOLS as $name ) {
                $status = $this->tool_status_for( $token_id, $allowed_tools, $name )['status'];
                if ( 'off' === $status ) {
                    $off[] = $name;
                } elseif ( 'off_locked' === $status ) {
                    $locked[] = $name;
                } else {
                    continue;
                }
                $needed[] = $name;
            }
            $cli_tool = $this->tool_registry->get_tool( 'wp_cli_run' );
            $text     = sprintf( 'WP-CLI (wp_cli_run) is switched off; it covers %s.', $cli_tool instanceof \Easy_MCP_AI\Tools\Base_Tool ? $cli_tool->get_covers() : '' );
            $text .= array() === $needed ? '' : sprintf( ' The tools that install or delete plugins and themes and update core are off too (%s).', implode( ', ', $needed ) );
            return $text . ' Before saying a task cannot be done, tell the user which tool to switch on, by its name, and that ' . Tool_Status::who_switches_on( $off, $locked, Tool_Status::switch_path() ) . '.';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    public function get_last_negotiated_version() {
        return isset( $this->last_negotiated_version ) ? $this->last_negotiated_version : null;
    }

    public static function is_paused() {
        return true === \Easy_MCP_AI\Config::get( 'paused' );
    }

    public static function available_tools( Tool_Registry $registry, $require_instance = false, $definitions = null ) {
        if ( ! \Easy_MCP_AI\Config::tool_policy_valid() ) {
            return array();
        }
        $patterns = (array) \Easy_MCP_AI\Config::get( 'allowed_tool_patterns' );
        return array_values( array_filter( null === $definitions ? $registry->get_all_definitions() : $definitions, static function ( $definition ) use ( $registry, $patterns, $require_instance ) {
            $name = $definition['name'];
            $tool = $registry->get_tool( $name );
            if ( ! ( $tool ? Tool_State::is_enabled( $tool ) : Tool_State::resolve( $name, false )[0] ) ) {
                return false;
            }
            if ( ! self::matches_tool_patterns( $name, $patterns ) ) {
                return false;
            }
            if ( ! $tool ) { return ! $require_instance; }
            if ( null !== self::required_tool_off( $registry, $tool ) ) {
                return false;
            }
            if ( null !== self::privileged_group( $name ) && null !== self::privileged_trail_refusal( (bool) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_audit_log_enabled', true ) ) ) {
                return false;
            }
            $cap = self::effective_required_capability( $tool->get_category(), $tool->get_required_capability() );
            return ! $cap || \current_user_can( $cap );
        } ) );
    }

    private function handle_tools_list( $id, $params, $token_id, $allowed_tools = null ) {
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        if ( ! \Easy_MCP_AI\Config::tool_policy_valid() || self::is_paused() ) {
            return JSON_RPC::success_response( $id, array( 'tools' => array() ) );
        }
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return $this->rate_limited( $id );
        }

        $listing_before       = self::$active_listing;
        self::$active_listing = array(
            'server'        => $this,
            'token_id'      => $token_id,
            'allowed_tools' => $allowed_tools,
        );
        try {
            $all_tools = $this->tool_registry->get_all_definitions();
        } finally {
            self::$active_listing = $listing_before;
        }
        $allowed = null !== $allowed_tools ? $allowed_tools : $this->permission_guard->get_allowed_tools( $token_id );
        $step_up   = 'oauth' === $this->request_auth_source
            ? \Easy_MCP_AI\OAuth\Scope_Map::scopes_for_tools( array_column( $all_tools, 'name' ) )
            : array();
        $all_tools = array_values( array_filter( $all_tools, static function ( $tool ) use ( $allowed, $step_up ) {
            return Permission_Guard::allowlist_permits( (array) $allowed, $tool['name'] ) || isset( $step_up[ $tool['name'] ] );
        } ) );
        $all_tools = self::available_tools( $this->tool_registry, false, $all_tools );
        if ( ! $this->client_declared_apps() ) {
            $all_tools = array_values( array_filter( $all_tools, static function ( $tool ) {
                return ! self::is_app_only_definition( $tool );
            } ) );
        }
        $all_tools = array_map( function ( $tool ) {
            $tool['inputSchema'] = Gemini_Safe_Schema::sanitize( $tool['inputSchema'] )['schema'];
            if ( isset( $tool['outputSchema'] ) ) {
                $tool['outputSchema'] = Gemini_Safe_Schema::sanitize( $tool['outputSchema'] )['schema'];
            }
            return $tool;
        }, $all_tools );

        return JSON_RPC::success_response( $id, array( 'tools' => $all_tools ) );
    }

    private function handle_tools_call( $id, $params, $token_id, ?array $allowed_tools = null ): array {
        Inner_Request_Method::open();
        Status_Field_Guard::open();
        try {
            return $this->run_tools_call( $id, $params, $token_id, $allowed_tools );
        } finally {
            Status_Field_Guard::close();
            Inner_Request_Method::close();
        }
    }

    private function run_tools_call( $id, $params, $token_id, ?array $allowed_tools = null ): array {
        $tool_name = isset( $params['name'] ) ? $params['name'] : '';
        $arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();

        if ( empty( $tool_name ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Missing tool name' );
        }
        if ( ! is_string( $tool_name ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Tool name must be a string' );
        }

        if ( ! is_array( $arguments ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Tool arguments must be a JSON object' );
        }

        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        $this->stamp_last_call( $token_id );
        if ( self::is_paused() ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return JSON_RPC::error_response( $id, Error_Codes::PAUSED, Error_Codes::PAUSED_MESSAGE );
        }
        if ( ! \Easy_MCP_AI\Config::tool_policy_valid() ) {
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Invalid deployment tool policy' );
        }
        if ( null !== $allowed_tools ) {
            if ( ! $this->permission_guard->can_use_tool_with_scope( $allowed_tools, $tool_name ) ) {
                $this->log_refusal( $token_id, $tool_name, $arguments );
                $step_up = $this->step_up_scope( $tool_name );
                if ( null === $step_up ) {
                    return $this->allowlist_refusal( $id, $tool_name, 'Token does not have permission to use this tool' );
                }
                $refused = self::ability_refusal( $this->tool_registry->get_tool( $tool_name ), $arguments );
                if ( null !== $refused ) {
                    return JSON_RPC::success_response( $id, self::error_result( new \RuntimeException( $refused ), $this->masked_values_of( $tool_name, $arguments ) ) );
                }
                if ( null === self::$active_call && 'skip' !== $this->approval_mode ) {
                    $this->scope_challenge = $this->scope_challenge_for( $step_up );
                    return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, sprintf( self::STEP_UP_MESSAGE, $step_up ) );
                }
                return $this->policy_refusal( $id, sprintf( self::STEP_UP_MESSAGE, $step_up ) );
            }
        } elseif ( ! $this->permission_guard->can_use_tool( $token_id, $tool_name ) ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return $this->allowlist_refusal( $id, $tool_name, sprintf( self::KEY_REFUSAL_MESSAGE, \Easy_MCP_AI\Config::brand( Tool_Status::API_TOKENS_PATH ) ) );
        }

        $tool                   = $this->tool_registry->get_tool( $tool_name );
        $throttle               = null === $tool ? array( 'rules' => array(), 'units' => 1 ) : $this->throttle_plan( $tool_name, $tool, $arguments );
        $this->throttle_receipt = array();
        if ( ! $this->skip_rate_limit && ! $this->check_rate_limit( $token_id, $throttle['rules'], $throttle['units'], $tool_name ) ) {
            return $this->rate_limited( $id );
        }

        if ( null === $tool ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            $off = self::switched_off_ability( $tool_name );
            if ( null !== $off ) {
                return $this->policy_refusal( $id, $off );
            }
            $hidden   = \Easy_MCP_AI\Tools\Tool_Directories::hidden_tool( $tool_name );
            $inactive = null !== $hidden ? self::hidden_tool_status( $hidden, $tool_name, true ) : self::missing_prerequisite_status( $tool_name );
            if ( null !== $inactive && in_array( $inactive['status'], array( 'capability', 'privileged' ), true ) ) {
                return $this->policy_refusal( $id, self::INSUFFICIENT_PERMISSIONS );
            }
            if ( null !== $inactive ) {
                $message = 'This tool ' . $this->with_call_caller(
                    $token_id,
                    $allowed_tools,
                    $id,
                    static function () use ( $inactive ): string {
                        return Tool_Status::predicate( $inactive );
                    }
                );
                return in_array( $inactive['status'], array( 'blocked', 'off', 'off_locked', 'pattern' ), true )
                    ? $this->policy_refusal( $id, $message )
                    : JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, $message );
            }
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Unknown tool' );
        }

        $required_cap = self::effective_required_capability( $tool->get_category(), $tool->get_required_capability() );
        if ( $required_cap && ! \Easy_MCP_AI\Drafts\Strict_Ceiling::user_can( $required_cap ) ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            $refusal = self::capability_refusal( $tool, $tool_name, (string) $required_cap );
            return $this->policy_refusal( $id, in_array( $refusal['status'], array( 'capability', 'privileged' ), true ) ? self::INSUFFICIENT_PERMISSIONS : 'This tool ' . Tool_Status::predicate( $refusal ) );
        }

        if ( ! Tool_State::is_enabled( $tool ) ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return $this->policy_refusal( $id, 'This tool ' . Tool_Status::predicate( Tool_Status::off( $tool_name, (bool) $tool->is_disabled_by_default() ) ) );
        }

        if ( ! $this->tool_matches_pattern_filter( $tool_name ) ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return $this->policy_refusal( $id, 'This tool ' . Tool_Status::predicate( array( 'status' => 'pattern' ) ) );
        }

        if ( self::is_app_only_tool( $tool ) && ! $this->client_declared_apps() ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return $this->policy_refusal( $id, 'This tool is only callable from an approval card.' );
        }

        $privileged_group = self::privileged_group( $tool_name );
        if ( null !== $privileged_group ) {
            $refusal = $this->privileged_refusal( $privileged_group );
            if ( null !== $refusal ) {
                $this->log_refusal( $token_id, $tool_name, $arguments );
                return $this->policy_refusal( $id, $refusal );
            }
            if ( ! $this->privileged_limit_replaced( $throttle['rules'] ) && ! $this->check_privileged_rate_limit( $token_id ) ) {
                \Easy_MCP_AI\Throttle\Counters::refund( $this->throttle_receipt );
                $this->throttle_receipt = array();
                return $this->rate_limited( $id );
            }
        }

        try {
            $tool->refuse_undeclared_arguments( $arguments );
        } catch ( \InvalidArgumentException $e ) {
            $refused = self::error_result( $e, $this->masked_values_of( $tool_name, $arguments ) );
            $this->note_audit_error( $this->log_tool_call( $token_id, $tool_name, $arguments, 'error' ), $refused );
            return JSON_RPC::success_response( $id, $refused );
        } catch ( \Throwable $e ) {
            unset( $e );
        }

        $sent_arguments   = $arguments;
        $arguments        = self::normalize_booleans( $tool, $arguments );
        $approval_subject = is_array( $this->approval_mode ) ? self::normalize_booleans( $tool, $this->approval_mode ) : $arguments;
        $off_schema = Argument_Schema_Check::off_schema( $tool, $arguments );
        $observed_id = null !== self::$active_call && isset( self::$active_call['rpc_id'] ) ? self::$active_call['rpc_id'] : $id;

        $off_schema = Off_Schema_Refusal::classify( $tool, $arguments, $off_schema );
        $refusable  = Off_Schema_Refusal::refused( $tool_name, $off_schema );
        if ( array() !== $refusable ) {
            $refused        = self::error_result( Off_Schema_Refusal::refusal( $tool, $refusable, $arguments, (array) $this->redact_for_tool( $tool_name, $arguments ) ), $this->masked_values_of( $tool_name, $arguments ) );
            $refusal_row_id = $this->log_tool_call( $token_id, $tool_name, $sent_arguments, 'error' );
            $this->note_audit_error( $refusal_row_id, $refused );
            self::observe_off_schema( $off_schema, $observed_id, $tool_name, $refusal_row_id );
            return JSON_RPC::success_response( $id, $refused );
        }

        if ( ! $this->charge_tool_call( $token_id, $tool_name, $throttle['rules'], $throttle['units'] ) ) {
            return $this->rate_limited( $id );
        }

        $stored_replay = null;
        if ( 'skip' !== $this->approval_mode && method_exists( $tool, 'precheck' ) ) {
            if ( \Easy_MCP_AI\Approvals\Approval_Gate::requires_approval( $tool, $approval_subject ) ) {
                $thrown  = null;
                $refused = $this->as_planned(
                    $tool,
                    $token_id,
                    $allowed_tools,
                    $observed_id,
                    function () use ( $tool, $tool_name, $arguments, &$thrown ) {
                        return self::precheck_refusal( $tool, $arguments, $this->masked_values_of( $tool_name, $arguments ), $thrown );
                    }
                );
                $stored_replay = $thrown instanceof Plan_Refusal ? $this->stored_replay( $tool, $approval_subject, $token_id, $params ) : null;
                if ( null !== $refused && null === $stored_replay ) {
                    $refused_audit_id = $this->log_tool_call( $token_id, $tool_name, $sent_arguments, 'error' );
                    self::observe_off_schema( $off_schema, $observed_id, $tool_name, $refused_audit_id );
                    return JSON_RPC::success_response( $id, $refused );
                }
            }
        }

        $approval_row        = null;
        $approval_outcome    = null;
        $this->last_approval = array( 'kind' => 'run', 'approval_id' => null );
        if ( 'skip' === $this->approval_mode ) {
            $approval = array( 'kind' => 'run' );
        } elseif ( null !== $stored_replay ) {
            $approval = $stored_replay;
        } elseif ( $tool->previews_as_caller() ) {
            $approval = $this->as_planned(
                $tool,
                $token_id,
                $allowed_tools,
                $observed_id,
                function () use ( $tool, $approval_subject, $token_id, $params ) {
                    return $this->evaluate_approval( $tool, $approval_subject, $token_id, $params );
                }
            );
        } else {
            $approval = $this->evaluate_approval( $tool, $approval_subject, $token_id, $params );
        }
        if ( 'replay' === $approval['kind'] ) {
            $result = $approval['result'];
            $lost   = $this->as_caller(
                $token_id,
                $allowed_tools,
                $observed_id,
                static function () use ( $result ) {
                    return self::receipt_capability_lost( $result );
                }
            );
            if ( $lost ) {
                $approval = array( 'kind' => 'refused', 'reason' => 'receipt', 'message' => self::RECEIPT_WITHHELD );
            }
        }
        if ( 'redeem' === $approval['kind'] ) {
            if ( $this->get_approval_gate()->claim( $approval['row'] ) ) {
                $approval_row        = $approval['row'];
                $this->last_approval = array( 'kind' => 'redeem', 'approval_id' => $approval_row['approval_id'] );
            } else {
                $approval = array( 'kind' => 'refused', 'reason' => 'consumed', 'message' => 'this approval was already used or has lapsed; call the tool again to request a fresh one.' );
            }
        }
        if ( 'refused' === $approval['kind'] ) {
            $this->last_approval = array( 'kind' => 'refused', 'approval_id' => null );
            $refused_audit_id    = $this->log_tool_call( $token_id, $tool_name, $sent_arguments, 'approval_refused' );
            self::observe_off_schema( $off_schema, $observed_id, $tool_name, $refused_audit_id );
            return JSON_RPC::success_response( $id, \Easy_MCP_AI\Approvals\Approval_Gate::refusal_result( $approval['reason'], $approval['message'] ) );
        }
        if ( 'replay' === $approval['kind'] ) {
            $this->last_approval = array( 'kind' => 'replay', 'approval_id' => $approval['row']['approval_id'] );
            $replayed_audit_id   = $this->log_tool_call( $token_id, $tool_name, $sent_arguments, 'approval_replayed' );
            self::observe_off_schema( $off_schema, $observed_id, $tool_name, $replayed_audit_id );
            return JSON_RPC::success_response( $id, self::without_inner_tools( $approval['result'] ) );
        }
        if ( 'ask_form' === $approval['kind'] || 'ask' === $approval['kind'] ) {
            $this->last_approval = array( 'kind' => 'paused', 'approval_id' => $approval['row']['approval_id'] );
            $paused_audit_id = $this->log_tool_call( $token_id, $tool_name, $sent_arguments, 'approval_required' );
            $this->note_audit_approval( $paused_audit_id, $approval['row'] );
            $this->get_approval_gate()->note_audit_id( $approval['row'], $paused_audit_id );
            self::observe_off_schema( $off_schema, $observed_id, $tool_name, $paused_audit_id );
            if ( 'ask_form' === $approval['kind'] ) {
                return $this->get_approval_gate()->build_input_required( $id, $approval['row'] );
            }
            return JSON_RPC::success_response( $id, $this->get_approval_gate()->build_approval_result( $approval['row'], $this->client_declared_apps() ) );
        }

        $audit_id            = $this->log_tool_call( $token_id, $tool_name, $sent_arguments, 'pending' );
        $this->last_audit_id = $audit_id;
        self::observe_off_schema( $off_schema, $observed_id, $tool_name, $audit_id );
        if ( $approval_row ) {
            $this->note_audit_approval( $audit_id, $approval_row );
        }
        $this->in_flight[]   = (int) $audit_id;
        $final_status        = null;
        $result              = null;
        $composite = self::call_is_composite( $tool, $arguments );
        $exec_started = null;
        $outer_call   = self::$active_call;
        $compat_mark  = class_exists( '\\Easy_MCP_AI\\Compat\\Compat_Registry' ) ? \Easy_MCP_AI\Compat\Compat_Registry::mark() : null;
        $option_guard_armed = false;
        $strict_armed       = false;
        $strict_reads       = false;
        $strict_mark        = 0;
        $strict_save_mark   = 0;
        $stack_entered      = false;
        $hook_arguments     = array();
        $call_context       = array();
        $rows_written       = null;

        self::$ran_tools[] = array();
        try {
        if ( ! $composite && class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) ) {
            \Easy_MCP_AI\History\Change_Context::set( array(
                'audit_id'        => $audit_id,
                'tool_name'       => $tool_name,
                'token_id'        => $token_id ? (int) $token_id : 0,
                'auth_source'     => $this->request_auth_source,
                'oauth_client_id' => $this->request_client_id,
                'wp_user_id'      => $this->request_wp_user_id,
                'ip_address'      => self::get_client_ip(),
                'tool_args'       => self::record_redaction( $tool, $sent_arguments ),
                'approval_id'     => $approval_row ? $approval_row['approval_id'] : null,
                'privileged'      => null === $privileged_group ? '' : $privileged_group,
                'own_history'     => method_exists( $tool, 'records_own_history' ) && $tool->records_own_history(),
                'raw_writes'      => method_exists( $tool, 'records_database_writes' ) && $tool->records_database_writes(),
            ) );
            \do_action( 'easy_mcp_ai_change_context_armed' );
        }

            $arguments    = Gemini_Safe_Schema::coerce( $arguments, Gemini_Safe_Schema::sanitize( $tool->get_input_schema() )['map'] );
            if ( method_exists( $tool, 'set_request_identity' ) ) {
                $tool->set_request_identity( $this->request_identity( $token_id ) );
            }
            if ( class_exists( '\\Easy_MCP_AI\\Meta\\Meta_Exposure' ) ) {
                \Easy_MCP_AI\Meta\Meta_Exposure::register_for_request();
            }
            \Easy_MCP_AI\Cache\Page_Cache_Queue::listen();
            if ( null !== $compat_mark ) {
                \Easy_MCP_AI\Compat\Compat_Registry::arm_for( $tool, $arguments );
            }
            Option_Guard::arm();
            $option_guard_armed = true;
            $strict_mark      = \Easy_MCP_AI\Drafts\Strict_Ceiling::mark();
            $strict_save_mark = \Easy_MCP_AI\Drafts\Strict_Ceiling::save_mark();
            $strict_reads     = self::call_is_read_only( $tool, $arguments );
            $strict_armed     = \Easy_MCP_AI\Drafts\Strict_Ceiling::arm();
            self::$active_call = array(
                'server'        => $this,
                'token_id'      => $token_id,
                'allowed_tools' => $allowed_tools,
                'approval_kind' => $this->last_approval['kind'],
                'rpc_id'        => $observed_id,
            );
            $hook_arguments = (array) $this->redact_for_tool( $tool_name, $arguments );
            $call_context   = array(
                'audit_id'    => $audit_id ? (int) $audit_id : null,
                'parent'      => Tool_Call_Stack::current(),
                'composite'   => $composite,
                'read_only'   => self::call_is_read_only( $tool, $arguments ),
                'user_id'     => (int) \get_current_user_id(),
                'auth_source' => 'oauth' === $this->request_auth_source ? 'oauth' : 'api_key',
                'approval_id' => $approval_row ? $approval_row['approval_id'] : null,
            );
            $call_context['depth'] = Tool_Call_Stack::enter( $tool_name );
            $stack_entered         = true;
            $lift = $strict_reads && $strict_armed;
            $run  = static function ( callable $step ) use ( $lift ) {
                return $lift ? \Easy_MCP_AI\Drafts\Strict_Ceiling::with_capabilities( $step ) : $step();
            };
            $run(
                static function () use ( $tool_name, $hook_arguments, $call_context ) {
                    \do_action( 'easy_mcp_ai_before_tool_call', $tool_name, $hook_arguments, $call_context );
                }
            );
            $exec_started = microtime( true );
            $result       = $run(
                static function () use ( $tool, $arguments ) {
                    return $tool->execute( $arguments );
                }
            );
            if ( $strict_armed && ! $composite && \Easy_MCP_AI\Drafts\Strict_Ceiling::save_refused_since( $strict_save_mark ) ) {
                throw \Easy_MCP_AI\Drafts\Strict_Ceiling::last_save_refusal();
            }
            $final_status = 'success';
            $file_block   = null;
            if ( $result instanceof File_Result ) {
                $file_block = $result->content_block();
                $result     = $result->get_data();
            }
            if ( is_array( $result ) && isset( $result['error'] ) && is_string( $result['error'] ) && '' !== trim( $result['error'] ) ) {
                $result['error'] = self::sanitize_error_message( self::written_over( $result['error'], $this->masked_values_of( $tool_name, $arguments ) ) );
            }
            $output_schema  = method_exists( $tool, 'get_output_schema' ) ? $tool->get_output_schema() : null;
            $has_structured = null !== $output_schema && is_array( $result );
            if ( $has_structured ) {
                $output_map = Gemini_Safe_Schema::sanitize( $output_schema )['map'];
                if ( ! empty( $output_map ) ) {
                    $result = Gemini_Safe_Schema::conform( $result, $output_map );
                }
            }
            $structured_value = ( $has_structured && empty( $result ) ) ? new \stdClass() : $result;

            $response = array(
                'content' => array( array(
                    'type' => 'text',
                    'text' => is_string( $result )
                        ? $result
                        : wp_json_encode( $has_structured ? $structured_value : $result, self::RESULT_JSON_FLAGS ),
                ) ),
            );
            if ( null !== $file_block ) {
                $response['content'][] = $file_block;
            }
            if ( $has_structured ) {
                $response['structuredContent'] = $structured_value;
            }
            $approval_outcome = $response;
            return JSON_RPC::success_response( $id, $response );
        } catch ( \Exception $e ) {
            $final_status = 'error';
            if ( $strict_armed && ! $composite && ! $e instanceof \Easy_MCP_AI\Drafts\Strict_Refusal && \Easy_MCP_AI\Drafts\Strict_Ceiling::refused_since( $strict_mark ) && \Easy_MCP_AI\Drafts\Strict_Refusal::is_permission_refusal( $e ) ) {
                $e = \Easy_MCP_AI\Drafts\Strict_Refusal::for_answer( $e->getMessage(), \Easy_MCP_AI\Drafts\Strict_Ceiling::last_post() );
            }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! self::is_argument_refusal( $e ) ) {
                error_log( sprintf( 'WP MCP Server tool exception [%s]: %s in %s:%d', $tool_name, $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging
            }
            $approval_outcome = self::error_result( $e, $this->masked_values_of( $tool_name, $arguments ) );
            return JSON_RPC::success_response( $id, $approval_outcome );
        } catch ( \Error $e ) {
            $final_status = 'error';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 'WP MCP Server tool error [%s]: %s in %s:%d', $tool_name, $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging
            }
            $approval_outcome = array(
                'content' => array( array( 'type' => 'text', 'text' => 'Tool execution failed. Check server error logs for details.' ) ),
                'isError' => true,
            );
            return JSON_RPC::success_response( $id, $approval_outcome );
        } finally {
            $inner_tools = array_keys( (array) array_pop( self::$ran_tools ) );
            if ( array() !== self::$ran_tools ) {
                $caller = count( self::$ran_tools ) - 1;
                if ( null !== $exec_started ) {
                    self::$ran_tools[ $caller ][ $tool_name ] = true;
                }
                foreach ( $inner_tools as $inner_tool ) {
                    self::$ran_tools[ $caller ][ $inner_tool ] = true;
                }
            }
            if ( $option_guard_armed ) {
                Option_Guard::release();
            }
            if ( $strict_armed ) {
                \Easy_MCP_AI\Drafts\Strict_Ceiling::release();
            }
            if ( null !== $compat_mark ) {
                \Easy_MCP_AI\Compat\Compat_Registry::release( $compat_mark );
            }
            self::$active_call = $outer_call;
            if ( $stack_entered ) {
                Tool_Call_Stack::leave();
            }
            $duration_ms = null === $exec_started ? null : (int) round( ( microtime( true ) - $exec_started ) * 1000 );
            $cleanup_finished = false;
            try {
                $this->update_audit_status( $audit_id, null === $final_status ? 'error' : $final_status, $duration_ms );
                if ( 'error' === $final_status ) {
                    $this->note_audit_error( $audit_id, $approval_outcome );
                }
                array_pop( $this->in_flight );
                if ( $approval_row ) {
                    try {
                        $this->get_approval_gate()->finish( $approval_row, self::with_inner_tools( $approval_outcome, $inner_tools ), null === $final_status ? 'error' : $final_status, $audit_id );
                    } catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                    }
                }
                if ( ! $composite && class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) ) {
                    try {
                        \do_action( 'easy_mcp_ai_change_context_disarming' );
                    } finally {
                        $rows_written = \Easy_MCP_AI\History\Change_Context::rows_written();
                        \Easy_MCP_AI\History\Change_Context::clear();
                    }
                }
                \Easy_MCP_AI\Cache\Page_Cache_Queue::flush();

                if ( 'success' === $final_status && ! $composite ) {
                    if ( ! self::call_is_read_only( $tool, $arguments ) ) {
                        try {
                            \do_action( 'easy_mcp_ai_tool_mutated', $tool_name, $this->redact_for_tool( $tool_name, $arguments ), $result );
                        } catch ( \Throwable $e ) {
                            self::log_listener_failure( 'easy_mcp_ai_tool_mutated', $tool_name, $e );
                        }
                    }
                }
                $cleanup_finished = true;
            } finally {
                if ( ! $composite && class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) && \Easy_MCP_AI\History\Change_Context::is_active() && $audit_id === \Easy_MCP_AI\History\Change_Context::get( 'audit_id' ) ) {
                    try {
                        \do_action( 'easy_mcp_ai_change_context_disarming' );
                    } catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                    } finally {
                        $rows_written = \Easy_MCP_AI\History\Change_Context::rows_written();
                        \Easy_MCP_AI\History\Change_Context::clear();
                    }
                }
                if ( $stack_entered ) {
                    self::fire_after_tool_call( $tool_name, $hook_arguments, $call_context, $final_status, $result, $approval_outcome, $duration_ms );
                }
                self::observe_after_call( $cleanup_finished ? $final_status : 'error', $rows_written, $observed_id, $tool_name, $audit_id );
            }
        }
    }

    private static function observe_off_schema( array $off_schema, $rpc_id, $tool_name, $audit_id ): void {
        if ( array() === $off_schema ) {
            return;
        }
        Call_Observations::record(
            array(
                'kind'      => Call_Observations::OFF_SCHEMA,
                'tool'      => $tool_name,
                'rpc_id'    => $rpc_id,
                'audit_id'  => $audit_id,
                'arguments' => $off_schema,
            )
        );
    }

    private static function observe_after_call( $final_status, $rows_written, $rpc_id, $tool_name, $audit_id ): void {
        if ( 'success' !== $final_status && is_int( $rows_written ) && $rows_written > 0 ) {
            Call_Observations::record(
                array(
                    'kind'     => Call_Observations::WROTE_THEN_REFUSED,
                    'tool'     => $tool_name,
                    'rpc_id'   => $rpc_id,
                    'audit_id' => $audit_id,
                    'rows'     => $rows_written,
                )
            );
        }
        Call_Observations::flush();
    }

    private static function fire_after_tool_call( string $tool_name, array $arguments, array $context, ?string $status, $result, ?array $outcome, ?int $duration_ms ): void {
        $succeeded              = 'success' === $status;
        $context['status']      = $succeeded ? 'success' : 'error';
        $context['duration_ms'] = $duration_ms;
        $context['result']      = $succeeded ? $result : null;
        $context['error']       = ! $succeeded && isset( $outcome['content'][0]['text'] ) && is_string( $outcome['content'][0]['text'] ) ? $outcome['content'][0]['text'] : null;
        try {
            \do_action( 'easy_mcp_ai_after_tool_call', $tool_name, $arguments, $context );
        } catch ( \Throwable $e ) {
            self::log_listener_failure( 'easy_mcp_ai_after_tool_call', $tool_name, $e );
        }
    }

    private static function log_listener_failure( string $hook, string $tool_name, \Throwable $e ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'Easy MCP AI %s listener failed [%s]: %s', $hook, $tool_name, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging
        }
    }

    private static function normalize_booleans( \Easy_MCP_AI\Tools\Base_Tool $tool, array $arguments ): array {
        if ( $tool instanceof \Easy_MCP_AI\Tools\Dynamic_Tool ) {
            return $arguments;
        }
        try {
            $schema = $tool->get_input_schema();
        } catch ( \Throwable $e ) {
            return $arguments;
        }
        return is_array( $schema ) ? Boolean_Arguments::normalize( $arguments, $schema ) : $arguments;
    }

    private static function call_is_composite( \Easy_MCP_AI\Tools\Base_Tool $tool, array $arguments ): bool {
        try {
            return (bool) $tool->is_composite( $arguments );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private static function call_is_read_only( \Easy_MCP_AI\Tools\Base_Tool $tool, array $arguments ): bool {
        try {
            return (bool) $tool->is_read_only_call( $arguments );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private static function privileged_group( $tool_name ) {
        return class_exists( '\\Easy_MCP_AI\\Privileged\\Privileged_Groups' )
            ? \Easy_MCP_AI\Privileged\Privileged_Groups::group_of_tool( $tool_name )
            : null;
    }

    private static function capability_refusal( \Easy_MCP_AI\Tools\Base_Tool $tool, string $tool_name, string $capability ): array {
        $switched_off = self::privileged_switch_off( $tool, $tool_name );
        if ( null !== $switched_off ) {
            return $switched_off;
        }
        if ( null !== self::privileged_group( $tool_name ) ) {
            return array(
                'status'  => 'privileged',
                'message' => self::INSUFFICIENT_PERMISSIONS,
            );
        }
        $cause = Blocked_Capability::cause( $capability );
        if ( null !== $cause ) {
            return array(
                'status'     => 'blocked',
                'capability' => $capability,
                'cause'      => $cause,
            );
        }
        return array(
            'status'     => 'capability',
            'capability' => $capability,
        );
    }

    private static function privileged_switch_off( \Easy_MCP_AI\Tools\Base_Tool $tool, string $tool_name ): ?array {
        $group = self::privileged_group( $tool_name );
        if ( null === $group || Tool_State::is_enabled( $tool ) || ! \Easy_MCP_AI\Privileged\Privileged_Groups::floor_passes( $group, (int) \get_current_user_id() ) ) {
            return null;
        }
        return Tool_Status::off( $tool_name, (bool) $tool->is_disabled_by_default() );
    }

    private function privileged_refusal( $group ) {
        if ( ! \Easy_MCP_AI\Privileged\Privileged_Groups::is_enabled( $group ) ) {
            return 'This privileged tool is not enabled on this site.';
        }
        return self::privileged_trail_refusal( $this->audit_log_enabled );
    }

    private static function privileged_trail_refusal( bool $audit_log_enabled ): ?string {
        if ( ! $audit_log_enabled ) {
            return 'Privileged tools run only while the audit log is on. Ask the site administrator to turn it on.';
        }
        if ( ! \Easy_MCP_AI\Config::get( 'easy_mcp_ai_change_log_enabled', true ) ) {
            return 'Privileged tools run only while change history is on. Ask the site administrator to turn it on.';
        }
        return null;
    }

    private function check_privileged_rate_limit( $token_id ) {
        $limit = \Easy_MCP_AI\Privileged\Privileged_Groups::RATE_LIMIT_PER_MINUTE;
        if ( 'oauth' === $this->request_auth_source ) {
            $principal = 'o' . $this->oauth_client_user();
        } else {
            $principal = 'k' . (int) $token_id;
        }
        return $this->within_rate_limit( self::count_rate_hit( 'easy_mcp_ai_priv_rate_' . $principal ), $limit, self::PRIVILEGED_RATE_LIMIT_MESSAGE );
    }

    private function note_audit_error( ?int $audit_id, ?array $outcome ): void {
        if ( ! $this->audit_log_enabled || ! $audit_id || null === $outcome || ! isset( $outcome['content'][0]['text'] ) || ! is_string( $outcome['content'][0]['text'] ) ) {
            return;
        }
        $text = $outcome['content'][0]['text'];
        if ( class_exists( '\\Easy_MCP_AI\\Text_Redactor' ) ) {
            $text = \Easy_MCP_AI\Text_Redactor::redact( $text );
        }
        $text = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, 1000, 'UTF-8' ) : substr( $text, 0, 1000 );
        if ( '' === trim( $text ) ) {
            return;
        }
        global $wpdb;
        $quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update of the plugin's own audit row.
            $wpdb->prefix . 'easy_mcp_ai_audit_log',
            array( 'error_message' => $text ),
            array( 'id' => (int) $audit_id ),
            array( '%s' ),
            array( '%d' )
        );
        if ( null !== $quiet ) {
            $wpdb->suppress_errors( $quiet );
        }
    }

    private function note_audit_approval( $audit_id, array $row ) {
        if ( ! $this->audit_log_enabled || ! $audit_id || empty( $row['approval_id'] ) ) {
            return;
        }
        global $wpdb;
        $data   = array( 'approval_id' => (string) $row['approval_id'] );
        $format = array( '%s' );
        if ( ! empty( $row['decided_by'] ) ) {
            $data['approved_by']  = (int) $row['decided_by'];
            $data['approved_via'] = isset( $row['decision_via'] ) ? substr( (string) $row['decision_via'], 0, 16 ) : null;
            $data['approved_at']  = isset( $row['decided_at'] ) ? (string) $row['decided_at'] : null;
            array_push( $format, '%d', '%s', '%s' );
        }
        $quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update of the plugin's own audit row.
            $wpdb->prefix . 'easy_mcp_ai_audit_log',
            $data,
            array( 'id' => (int) $audit_id ),
            $format,
            array( '%d' )
        );
        if ( null !== $quiet ) {
            $wpdb->suppress_errors( $quiet );
        }
    }

    public static function current_call_holds_capability_of( string $tool_name ): ?bool {
        if ( null === self::$active_call ) {
            return null;
        }
        $tool = self::$active_call['server']->tool_registry->get_tool( $tool_name );
        if ( null === $tool ) {
            return null;
        }
        $cap = self::effective_required_capability( $tool->get_category(), $tool->get_required_capability() );
        return ! $cap || \current_user_can( $cap );
    }

    private static function receipt_capability_lost( array $result ): bool {
        foreach ( (array) ( $result['_meta'][ self::INNER_TOOLS_META ] ?? array() ) as $tool ) {
            if ( is_string( $tool ) && false === self::current_call_holds_capability_of( $tool ) ) {
                return true;
            }
        }
        return false;
    }

    private static function with_inner_tools( $result, array $tools ) {
        if ( array() === $tools || ! is_array( $result ) ) {
            return $result;
        }
        $result['_meta']                           = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
        $result['_meta'][ self::INNER_TOOLS_META ] = $tools;
        return $result;
    }

    private static function without_inner_tools( array $result ): array {
        if ( isset( $result['_meta'] ) && is_array( $result['_meta'] ) && array_key_exists( self::INNER_TOOLS_META, $result['_meta'] ) ) {
            unset( $result['_meta'][ self::INNER_TOOLS_META ] );
            if ( array() === $result['_meta'] ) {
                unset( $result['_meta'] );
            }
        }
        return $result;
    }

    public static function current_call_may_use( string $tool_name ): ?bool {
        if ( null === self::$active_call ) {
            return null;
        }
        return self::$active_call['server']->token_may_use(
            self::$active_call['token_id'],
            self::$active_call['allowed_tools'],
            $tool_name
        );
    }

    public static function current_caller_key(): string {
        if ( null === self::$active_call ) {
            return '';
        }
        return (string) wp_json_encode( array( self::$active_call['token_id'], self::$active_call['allowed_tools'] ) );
    }

    public static function current_caller_may_reach( string $tool_name ): ?bool {
        $caller = null !== self::$active_listing ? self::$active_listing : self::$active_call;
        if ( null === $caller ) {
            return null;
        }
        return $caller['server']->token_may_reach( $caller['token_id'], $caller['allowed_tools'], $tool_name );
    }

    public static function current_call_tool_status( string $tool_name ): ?array {
        if ( null === self::$active_call ) {
            return null;
        }
        return self::$active_call['server']->tool_status_for(
            self::$active_call['token_id'],
            self::$active_call['allowed_tools'],
            $tool_name
        );
    }

    public static function current_call_switched_off( string $tool_name ): ?array {
        if ( null === self::$active_call ) {
            return null;
        }
        $tool = self::$active_call['server']->tool_registry->get_tool( $tool_name ) ?? \Easy_MCP_AI\Tools\Tool_Directories::hidden_tool( $tool_name );
        return null === $tool ? null : self::site_switch_status( $tool, $tool_name );
    }

    private function as_caller( $token_id, ?array $allowed_tools, $rpc_id, callable $run ) {
        $caller_before     = self::$active_call;
        self::$active_call = array(
            'server'        => $this,
            'token_id'      => $token_id,
            'allowed_tools' => $allowed_tools,
            'approval_kind' => null,
            'rpc_id'        => $rpc_id,
        );
        try {
            return $run();
        } finally {
            self::$active_call = $caller_before;
        }
    }

    private function as_planned( \Easy_MCP_AI\Tools\Base_Tool $tool, $token_id, ?array $allowed_tools, $rpc_id, callable $run ) {
        if ( $tool->previews_as_caller() && class_exists( '\\Easy_MCP_AI\\Meta\\Meta_Exposure' ) ) {
            $inner = $run;
            $run   = static function () use ( $inner ) {
                return \Easy_MCP_AI\Meta\Meta_Exposure::while_registered( $inner );
            };
        }
        return $this->as_caller( $token_id, $allowed_tools, $rpc_id, $run );
    }

    private function stored_replay( \Easy_MCP_AI\Tools\Base_Tool $tool, array $arguments, $token_id, array $params ): ?array {
        try {
            return $this->get_approval_gate()->stored_replay( $tool, $arguments, $this->request_identity( $token_id ), $params );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    private static function precheck_refusal( \Easy_MCP_AI\Tools\Base_Tool $tool, array $arguments, array $masked, ?\Exception &$thrown = null ): ?array {
        try {
            $tool->precheck( Gemini_Safe_Schema::coerce( $arguments, Gemini_Safe_Schema::sanitize( $tool->get_input_schema() )['map'] ) );
        } catch ( \Exception $e ) {
            $thrown = $e;
            return self::error_result( $e, $masked );
        } catch ( \Error $e ) {
            return null;
        }
        return null;
    }

    private static function is_argument_refusal( \Exception $e ): bool {
        return $e instanceof \InvalidArgumentException || ( $e instanceof Detailed_Runtime_Error && $e->is_validation() );
    }

    private function policy_refusal( $id, string $message ): array {
        $protocol = $this->request_client_protocol;
        if ( ! in_array( $protocol, self::SUPPORTED_PROTOCOL_VERSIONS, true ) || strcmp( $protocol, self::REFUSAL_RESULT_PROTOCOL ) < 0 ) {
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, $message );
        }
        $this->policy_refused = true;
        return JSON_RPC::success_response(
            $id,
            array(
                'content' => array( array( 'type' => 'text', 'text' => 'Error: ' . $message ) ),
                'isError' => true,
            )
        );
    }

    private function with_call_caller( $token_id, ?array $allowed_tools, $rpc_id, callable $fn ) {
        $caller_before     = self::$active_call;
        self::$active_call = array(
            'server'        => $this,
            'token_id'      => $token_id,
            'allowed_tools' => $allowed_tools,
            'approval_kind' => null,
            'rpc_id'        => $rpc_id,
        );
        try {
            return $fn();
        } finally {
            self::$active_call = $caller_before;
        }
    }

    private function allowlist_refusal( $id, string $tool_name, string $message ): array {
        if ( ! $this->tool_registry->has_tool( $tool_name ) && null === self::missing_prerequisite_status( $tool_name ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, $message );
        }
        return $this->policy_refusal( $id, $message );
    }

    private static function error_result( \Exception $e, array $masked = array() ): array {
        $content = array( array( 'type' => 'text', 'text' => 'Error: ' . self::sanitize_error_message( self::written_over( $e->getMessage(), $masked ) ) ) );
        if ( ( $e instanceof Detailed_Tool_Error || $e instanceof Detailed_Runtime_Error ) && '' !== trim( $e->get_details() ) ) {
            $content[] = array( 'type' => 'text', 'text' => self::sanitize_error_message( self::written_over( $e->get_details(), $masked ), Detailed_Tool_Error::MAX_DETAILS ) );
        }
        if ( $e instanceof Detailed_Tool_Error && '' !== $e->get_approve_url() ) {
            $content[] = array( 'type' => 'text', 'text' => 'Approval link: ' . $e->get_approve_url() );
        }
        return array(
            'content' => $content,
            'isError' => true,
        );
    }

    private function token_may_use( $token_id, ?array $allowed_tools, string $tool_name ): bool {
        if ( self::is_paused() ) {
            return false;
        }
        if ( null !== $allowed_tools ) {
            if ( ! $this->permission_guard->can_use_tool_with_scope( $allowed_tools, $tool_name ) ) {
                return false;
            }
        } elseif ( ! $this->permission_guard->can_use_tool( $token_id, $tool_name ) ) {
            return false;
        }
        $tool = $this->tool_registry->get_tool( $tool_name );
        if ( null === $tool ) {
            return false;
        }
        return array() !== self::available_tools( $this->tool_registry, true, array( array( 'name' => $tool_name ) ) )
            && null === $this->late_call_refusal( $tool, $tool_name );
    }

    private static function unregistered_status( string $tool_name ): array {
        foreach ( \Easy_MCP_AI\Tools\Switched_Off_Inventory::integration_tools() as $entry ) {
            if ( $entry['name'] !== $tool_name ) {
                continue;
            }
            if ( false === Tool_State::override_of( $tool_name ) ) {
                return array( 'status' => 'off_locked' );
            }
            if ( \Easy_MCP_AI\Config::is_locked( 'easy_mcp_ai_disabled_tools' ) ) {
                return array(
                    'status'      => 'integration_off_locked',
                    'integration' => $entry['group_label'],
                );
            }
            return array(
                'status'      => 'integration_off',
                'integration' => $entry['group_label'],
                'where'       => Tool_Status::switch_path(),
            );
        }
        return self::missing_prerequisite_status( $tool_name ) ?? array( 'status' => 'not_registered' );
    }

    private static function missing_prerequisite_status( string $tool_name ): ?array {
        $integration = \Easy_MCP_AI\Tools\Tool_Directories::inactive_integration_of( $tool_name );
        $requirement = null === $integration ? \Easy_MCP_AI\Tools\Tool_Directories::missing_requirement( $tool_name ) : null;
        if ( null === $integration && null === $requirement ) {
            return null;
        }
        if ( false === Tool_State::override_of( $tool_name ) ) {
            return array( 'status' => 'off_locked' );
        }
        return null !== $integration
            ? array(
                'status'      => 'integration_inactive',
                'integration' => $integration,
            )
            : array( 'status' => 'needs_' . ( \Easy_MCP_AI\Tools\Tool_Directories::REQUIREMENT_STATUS[ $requirement ] ?? $requirement ) );
    }

    private function token_may_reach( $token_id, ?array $allowed_tools, string $tool_name ): bool {
        if ( $this->token_may_use( $token_id, $allowed_tools, $tool_name ) ) {
            return true;
        }
        if ( null === $allowed_tools || self::is_paused() || ! \Easy_MCP_AI\Config::tool_policy_valid() ) {
            return false;
        }
        return ! $this->permission_guard->can_use_tool_with_scope( $allowed_tools, $tool_name )
            && null !== $this->step_up_scope( $tool_name );
    }

    private function tool_status_for( $token_id, ?array $allowed_tools, string $tool_name ): array {
        if ( self::is_paused() ) {
            return array( 'status' => 'paused' );
        }
        if ( ! \Easy_MCP_AI\Config::tool_policy_valid() ) {
            return array( 'status' => 'policy' );
        }
        $allowed = null !== $allowed_tools
            ? $this->permission_guard->can_use_tool_with_scope( $allowed_tools, $tool_name )
            : $this->permission_guard->can_use_tool( $token_id, $tool_name );
        if ( ! $allowed ) {
            return array( 'status' => 'not_allowed' );
        }
        $tool = $this->tool_registry->get_tool( $tool_name );
        if ( null === $tool ) {
            $hidden = \Easy_MCP_AI\Tools\Tool_Directories::hidden_tool( $tool_name );
            return null === $hidden ? self::unregistered_status( $tool_name ) : self::hidden_tool_status( $hidden, $tool_name );
        }
        $cap = self::effective_required_capability( $tool->get_category(), $tool->get_required_capability() );
        if ( $cap && ! \current_user_can( $cap ) ) {
            return self::capability_refusal( $tool, $tool_name, (string) $cap );
        }
        $switched_off = self::site_switch_status( $tool, $tool_name ) ?? self::required_tool_off( $this->tool_registry, $tool );
        if ( null !== $switched_off ) {
            return $switched_off;
        }
        $refusal = $this->late_call_refusal( $tool, $tool_name );
        return null !== $refusal ? $refusal : array( 'status' => 'available' );
    }

    private static function hidden_tool_status( \Easy_MCP_AI\Tools\Base_Tool $tool, string $tool_name, bool $admission = false ): array {
        $cap = self::effective_required_capability( $tool->get_category(), $tool->get_required_capability() );
        if ( $cap && ! ( $admission ? \Easy_MCP_AI\Drafts\Strict_Ceiling::user_can( $cap ) : \current_user_can( $cap ) ) ) {
            return self::capability_refusal( $tool, $tool_name, (string) $cap );
        }
        return self::site_switch_status( $tool, $tool_name ) ?? self::missing_prerequisite_status( $tool_name ) ?? array( 'status' => 'not_registered' );
    }

    private static function site_switch_status( \Easy_MCP_AI\Tools\Base_Tool $tool, string $tool_name ): ?array {
        if ( ! Tool_State::is_enabled( $tool ) ) {
            return Tool_Status::off( $tool_name, (bool) $tool->is_disabled_by_default() );
        }
        if ( ! self::matches_tool_patterns( $tool_name, (array) \Easy_MCP_AI\Config::get( 'allowed_tool_patterns' ) ) ) {
            return array( 'status' => 'pattern' );
        }
        return null;
    }

    public static function required_tool_off( Tool_Registry $registry, \Easy_MCP_AI\Tools\Base_Tool $tool ): ?array {
        foreach ( $tool->get_required_tools() as $required ) {
            $needed = $registry->get_tool( $required );
            $status = $needed ? self::site_switch_status( $needed, $required ) : null;
            if ( null !== $status ) {
                return array(
                    'status' => 'needs_tool',
                    'tool'   => $required,
                    'cause'  => $status,
                );
            }
        }
        return null;
    }

    private function late_call_refusal( \Easy_MCP_AI\Tools\Base_Tool $tool, string $tool_name ): ?array {
        if ( self::is_app_only_tool( $tool ) && ! $this->client_declared_apps() ) {
            return array( 'status' => 'app_only' );
        }
        $group = self::privileged_group( $tool_name );
        $why   = null !== $group ? $this->privileged_refusal( $group ) : null;
        return null !== $why ? array( 'status' => 'privileged', 'message' => $why ) : null;
    }

    private function evaluate_approval( $tool, array $arguments, $token_id, array $params ) {
        if ( ! \Easy_MCP_AI\Approvals\Approval_Gate::requires_approval( $tool, $arguments ) ) {
            return array( 'kind' => 'run' );
        }
        try {
            return $this->get_approval_gate()->evaluate( $tool, $arguments, $this->request_identity( $token_id ), $params, $this->request_client_caps, $this->request_client_protocol );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 'Easy MCP AI approval gate failed for %s: %s', $tool->get_name(), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging
            }
            return array( 'kind' => 'refused', 'reason' => 'unavailable', 'message' => 'the approval system could not record this request, so the operation was not run. Try again shortly.' );
        }
    }

    public static function sanitize_error_message( $message, int $max_length = Detailed_Tool_Error::MAX_MESSAGE ) {
        if ( ! is_string( $message ) || '' === $message ) {
            return 'Tool execution failed.';
        }

        $message = \Easy_MCP_AI\Config::brand( $message );

        $message = preg_replace( '/\s*Stack trace:.*$/s', '', $message );

        $message = preg_replace( '/\s+in\s+\S+\.php(?:\(\d+\)|:\d+| on line \d+)/', '', $message );

        $message = preg_replace( '#(?<![A-Za-z0-9_.\-/\\\\])(?:\\\\?/|[A-Z]:\\\\)(?:(?:[^\s\'"<>/\\\\]+(?: +[^\s\'"<>/\\\\]+)*)?[/\\\\])*[^\s\'"<>/\\\\]*\.(?:php|inc|tpl|phtml)(?![A-Za-z0-9_])#', '[file]', $message );

        $message = preg_replace_callback(
            '/(?<![A-Za-z0-9_])(?:\d{1,3}\.){3}\d{1,3}(?![A-Za-z0-9_])/',
            function ( $m ) {
                $ip = $m[0];
                $parts = array_map( 'intval', explode( '.', $ip ) );
                if ( 127 === $parts[0] ) { return '[internal]'; }
                if ( 10 === $parts[0] ) { return '[internal]'; }
                if ( 192 === $parts[0] && 168 === $parts[1] ) { return '[internal]'; }
                if ( 172 === $parts[0] && $parts[1] >= 16 && $parts[1] <= 31 ) { return '[internal]'; }
                if ( 169 === $parts[0] && 254 === $parts[1] ) { return '[internal]'; }
                return $ip;
            },
            $message
        );
        $message = \Easy_MCP_AI\Ascii::replace( '/\[?::1\]?|(?<![a-z0-9_])localhost(?![a-z0-9_])/', '[internal]', (string) $message );

        $message    = trim( $message );
        $max_length = max( 1, $max_length );
        if ( strlen( $message ) > $max_length ) {
            $cut     = function_exists( 'mb_strcut' ) ? mb_strcut( $message, 0, $max_length, 'UTF-8' ) : substr( $message, 0, $max_length );
            $message = $cut . '…[truncated]';
        }

        return '' === $message ? 'Tool execution failed.' : $message;
    }

    private function handle_resources_list( $id, $params, $token_id, ?array $allowed_tools = null ): array {
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        if ( self::is_paused() ) {
            return JSON_RPC::success_response( $id, array( 'resources' => array() ) );
        }
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return $this->rate_limited( $id );
        }
        if ( ! \current_user_can( 'read' ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Insufficient permissions to list resources' );
        }
        $definitions = $this->resource_registry->get_all_definitions(
            function ( $resource ) use ( $token_id, $allowed_tools ) {
                return $this->resource_tool_permitted( $resource, $token_id, $allowed_tools );
            }
        );
        return JSON_RPC::success_response( $id, array( 'resources' => $definitions ) );
    }

    private function handle_resources_read( $id, $params, $token_id, ?array $allowed_tools = null ): array {
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        if ( self::is_paused() ) {
            return JSON_RPC::error_response( $id, Error_Codes::PAUSED, Error_Codes::PAUSED_MESSAGE );
        }
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return $this->rate_limited( $id );
        }

        if ( ! \current_user_can( 'read' ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Insufficient permissions to read resources' );
        }

        $uri = isset( $params['uri'] ) ? $params['uri'] : '';
        if ( empty( $uri ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Missing resource URI' );
        }
        if ( ! is_string( $uri ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Resource URI must be a string' );
        }
        $resource = $this->resource_registry->get_resource( $uri );
        if ( null === $resource ) {
            return JSON_RPC::error_response( $id, Error_Codes::RESOURCE_NOT_FOUND, 'Resource not found' );
        }
        if ( ! $this->resource_tool_permitted( $resource, $token_id, $allowed_tools ) ) {
            return JSON_RPC::error_response(
                $id,
                Error_Codes::FORBIDDEN,
                sprintf( 'Reading %1$s needs the %2$s tool, which this connection\'s tool list does not include.', $uri, $resource->get_required_tool() )
            );
        }
        try {
            $content = $resource->read();
            $entry   = array(
                'uri'      => $uri,
                'mimeType' => $resource->get_mime_type(),
                'text'     => is_string( $content ) ? $content : wp_json_encode( $content, self::RESULT_JSON_FLAGS ),
            );
            if ( method_exists( $resource, 'get_meta' ) ) {
                $meta = $resource->get_meta();
                if ( ! empty( $meta ) ) {
                    $entry['_meta'] = $meta;
                }
            }
            if ( 0 === strpos( (string) $uri, 'skill://' ) ) {
                $this->log_tool_call( $token_id, '_skill_read', array( 'uri' => $uri ), 'success' );
            }
            return JSON_RPC::success_response( $id, array( 'contents' => array( $entry ) ) );
        } catch ( \Throwable $e ) {
            return JSON_RPC::error_response( $id, Error_Codes::INTERNAL_ERROR, 'Failed to read resource' );
        }
    }

    private function resource_tool_permitted( \Easy_MCP_AI\Resources\Base_Resource $resource, $token_id, ?array $allowed_tools ): bool {
        $tool = $resource->get_required_tool();
        if ( null === $tool ) {
            return true;
        }
        return null !== $allowed_tools
            ? $this->permission_guard->can_use_tool_with_scope( $allowed_tools, $tool )
            : $this->permission_guard->can_use_tool( $token_id, $tool );
    }

    private function log_refusal( $token_id, $tool_name, $arguments ) {
        if ( null === $token_id ) {
            return;
        }

        $limit = (int) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_rate_limit_per_minute', \Easy_MCP_AI\Config::RATE_LIMIT_PER_MINUTE );
        if ( self::count_rate_hit( $this->credential_key( 'easy_mcp_ai_reflog_', $token_id ) )['count'] > $limit ) {
            return;
        }

        $this->log_tool_call( $token_id, $tool_name, $arguments, self::STATUS_REFUSED );
    }

    private function check_rate_limit( $token_id, array $call_rules = array(), int $units = 1, ?string $tool_name = null ) {
        if ( null === $token_id ) {
            return true;
        }
        $requests = \Easy_MCP_AI\Throttle\Rules::request_rules( \Easy_MCP_AI\Throttle\Rules::configured() );
        if ( ! $this->admits_readable_rules( array_merge( $requests, $call_rules ) ) ) {
            return false;
        }
        $requests   = \Easy_MCP_AI\Throttle\Rules::readable( $requests );
        $per_minute = \Easy_MCP_AI\Throttle\Rules::in_window( $call_rules, true );
        if ( ! \Easy_MCP_AI\Throttle\Rules::replaces_default( array_merge( $requests, $per_minute ) ) ) {
            $limit = (int) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_rate_limit_per_minute', \Easy_MCP_AI\Config::RATE_LIMIT_PER_MINUTE );
            if ( ! $this->within_rate_limit( self::count_rate_hit( 'easy_mcp_ai_rate_' . $this->credential_principal( $token_id ) ), $limit, self::RATE_LIMIT_MESSAGE ) ) {
                return false;
            }
        }
        $charges = $this->charge_throttle_rules( $token_id, $requests, 1, $tool_name );
        if ( null === $charges ) {
            return false;
        }
        $minute = $this->charge_throttle_rules( $token_id, $per_minute, $units, $tool_name );
        if ( null === $minute ) {
            \Easy_MCP_AI\Throttle\Counters::refund( $charges );
            return false;
        }
        $this->throttle_receipt = array_merge( $charges, $minute );
        return true;
    }

    private function charge_tool_call( $token_id, string $tool_name, array $call_rules, int $units ): bool {
        $receipt                = $this->throttle_receipt;
        $this->throttle_receipt = array();
        $requests               = \Easy_MCP_AI\Throttle\Rules::request_rules( \Easy_MCP_AI\Throttle\Rules::configured() );
        $admitted               = $this->admits_readable_rules( array_merge( $requests, $call_rules ) );
        if ( $admitted && ! $this->task_call ) {
            $rules    = $this->skip_rate_limit ? \Easy_MCP_AI\Throttle\Rules::readable( $call_rules ) : \Easy_MCP_AI\Throttle\Rules::in_window( $call_rules, false );
            $admitted = null !== $this->charge_throttle_rules( $token_id, $rules, $units, $tool_name );
        }
        if ( ! $admitted ) {
            \Easy_MCP_AI\Throttle\Counters::refund( $receipt );
        }
        return $admitted;
    }

    private function privileged_limit_replaced( array $call_rules ): bool {
        if ( $this->skip_rate_limit ) {
            return false;
        }
        $requests = \Easy_MCP_AI\Throttle\Rules::request_rules( \Easy_MCP_AI\Throttle\Rules::configured() );
        return \Easy_MCP_AI\Throttle\Rules::replaces_default( array_merge( $requests, $call_rules ) );
    }

    private function throttle_plan( string $tool_name, \Easy_MCP_AI\Tools\Base_Tool $tool, array $arguments ): array {
        $rules = \Easy_MCP_AI\Throttle\Rules::configured();
        if ( array() === $rules ) {
            return array(
                'rules' => array(),
                'units' => 1,
            );
        }
        $read  = self::normalize_booleans( $tool, $arguments );
        $rules = \Easy_MCP_AI\Throttle\Rules::call_rules( $rules, $tool_name, (string) $tool->get_category(), self::call_is_read_only( $tool, $read ), self::call_is_composite( $tool, $read ) );
        $units    = 1;
        $required = self::effective_required_capability( $tool->get_category(), $tool->get_required_capability() );
        if ( array() !== $rules && ( ! $required || \current_user_can( $required ) ) && Tool_State::is_enabled( $tool ) ) {
            try {
                $units = max( 1, (int) $tool->throttle_units( $read ) );
            } catch ( \Throwable $e ) {
                $units = 1;
            }
        }
        return array(
            'rules' => $rules,
            'units' => $units,
        );
    }

    private function admits_readable_rules( array $rules ): bool {
        foreach ( $rules as $rule ) {
            if ( ! $rule['readable'] ) {
                return $this->refuse_unreadable_rule( $rule );
            }
        }
        return true;
    }

    private function charge_throttle_rules( $token_id, array $rules, int $units, ?string $tool_name ): ?array {
        if ( array() === $rules ) {
            return array();
        }
        $caller  = array(
            'user'       => (int) $this->request_wp_user_id,
            'site'       => (int) \get_current_blog_id(),
            'credential' => $this->credential_principal( $token_id ),
        );
        $charged = \Easy_MCP_AI\Throttle\Counters::charge( $rules, $caller, $units );
        $refusal = $charged['refusal'];
        if ( null === $refusal ) {
            return $charged['charges'];
        }
        $rule                     = $refusal['rule'];
        $this->rate_limit_message = self::throttle_message( $rule, $refusal['retry_after'], $units );
        $this->rate_limit_refusal = array(
            'limit'       => $refusal['limit'],
            'retry_after' => $refusal['retry_after'],
            'rule'        => $rule['entry'],
            'scope'       => $rule['scope'],
        );
        \Easy_MCP_AI\Throttle\Counters::log_once(
            $rule,
            $caller,
            $refusal['ends'],
            function () use ( $token_id, $rule, $tool_name ): bool {
                return $this->log_tool_call( $token_id, '_throttle', array_filter( array( 'rule' => $rule['entry'], 'tool' => $tool_name ) ), self::STATUS_REFUSED ) > 0;
            }
        );
        return null;
    }

    private function refuse_unreadable_rule( array $rule ): bool {
        $this->rate_limit_message = sprintf(
            'Rate limit exceeded: the throttle rule "%1$s" in %2$s cannot be read, so every call it names is refused until it is fixed. A rule is written selector=number/window/scope, for example writes=500/hour/user. Nothing was changed.',
            $rule['entry'],
            \Easy_MCP_AI\Throttle\Rules::OVERRIDE_NAME
        );
        $this->rate_limit_refusal = array(
            'limit'       => 0,
            'retry_after' => 0,
            'rule'        => $rule['entry'],
            'unreadable'  => true,
        );
        return false;
    }

    private static function throttle_message( array $rule, int $retry_after, int $units ): string {
        $per     = array(
            'minute' => 'a minute',
            'hour'   => 'an hour',
            'day'    => 'a day',
        );
        $for     = array(
            'user'       => \is_multisite() ? 'per user, across all their connections and every site of this network' : 'per user, across all their connections',
            'site'       => 'for the whole site',
            'credential' => 'per connection',
        );
        $message = sprintf(
            'Rate limit exceeded: %1$s %2$s limited to %3$d %4$s %5$s (%6$s). Try again in %7$s.',
            \Easy_MCP_AI\Throttle\Rules::subject( $rule ),
            'name' === $rule['kind'] ? 'is' : 'are',
            $rule['limit'],
            $per[ $rule['window'] ],
            $for[ $rule['scope'] ],
            \Easy_MCP_AI\Throttle\Rules::OVERRIDE_NAME,
            self::wait_words( $retry_after )
        );
        if ( $units > 1 ) {
            $message .= sprintf( ' This call counts as %d, one for each item it writes; send fewer items at a time.', $units );
        }
        return $message;
    }

    private static function wait_words( int $seconds ): string {
        if ( $seconds < 60 ) {
            return 1 === $seconds ? '1 second' : $seconds . ' seconds';
        }
        $minutes = (int) ceil( $seconds / 60 );
        if ( $minutes <= 120 ) {
            return 1 === $minutes ? '1 minute' : $minutes . ' minutes';
        }
        return (int) ceil( $minutes / 60 ) . ' hours';
    }

    private function credential_principal( $token_id ): string {
        return 'oauth' === $this->request_auth_source ? 'oauth_' . $this->oauth_client_user() : (string) (int) $token_id;
    }

    private function oauth_client_user(): string {
        return substr( hash( 'sha256', (string) $this->request_client_id . '|' . (int) $this->request_wp_user_id ), 0, 32 );
    }

    private function credential_key( string $prefix, $token_id ): string {
        return $prefix . ( 'oauth' === $this->request_auth_source ? 'oauth_' : '' ) . (int) $token_id;
    }

    private static function count_rate_hit( string $key ): array {
        if ( ! \wp_using_ext_object_cache() ) {
            return \Easy_MCP_AI\Fixed_Window_Counter::hit( $key, self::RATE_WINDOW_SECONDS );
        }
        return \Easy_MCP_AI\Cache_Window_Counter::hit( $key, self::RATE_WINDOW_SECONDS );
    }

    private function within_rate_limit( array $hit, int $limit, string $message ): bool {
        if ( $hit['count'] <= $limit ) {
            return true;
        }
        $this->rate_limit_message = $message;
        $this->rate_limit_refusal = array(
            'limit'       => $limit,
            'retry_after' => max( 1, $hit['ends'] - time() ),
        );
        return false;
    }

    private function rate_limited( $id ): array {
        return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, $this->rate_limit_message, $this->rate_limit_refusal );
    }

    public function log_auth_failure( $ip, $reason, array $identity = array() ) {
        if ( ! $this->audit_log_enabled ) {
            return;
        }
        global $wpdb;
        $source = isset( $identity['auth_source'] ) && in_array( $identity['auth_source'], array( 'legacy', 'oauth' ), true )
            ? $identity['auth_source']
            : null;
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert required for audit logging.
            $wpdb->prefix . 'easy_mcp_ai_audit_log',
            array(
                'token_id'        => ! empty( $identity['token_id'] ) ? (int) $identity['token_id'] : 0,
                'tool_name'       => '_auth_failure',
                'arguments'       => wp_json_encode( array( 'reason' => $reason ) ),
                'result_status'   => 'auth_failure',
                'ip_address'      => $ip,
                'auth_source'     => $source,
                'wp_user_id'      => ! empty( $identity['wp_user_id'] ) ? (int) $identity['wp_user_id'] : null,
                'oauth_client_id' => ! empty( $identity['oauth_client_id'] ) ? (string) $identity['oauth_client_id'] : null,
                'created_at'      => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );
    }

    const STATUS_REFUSED = 'refused';

    const LAST_CALL_OPTION = 'easy_mcp_ai_last_tool_call';

    private function stamp_last_call( $token_id ) {
        if ( $this->audit_log_enabled ) {
            return;
        }
        $oauth = 'oauth' === $this->request_auth_source;
        \update_option(
            self::LAST_CALL_OPTION,
            array(
                'id'              => bin2hex( random_bytes( 8 ) ),
                'at'              => current_time( 'mysql', true ),
                'auth_source'     => $oauth ? 'oauth' : 'legacy',
                'token_id'        => (int) $token_id,
                'oauth_client_id' => $oauth && is_string( $this->request_client_id ) && '' !== $this->request_client_id ? $this->request_client_id : null,
            ),
            false
        );
    }

    private function log_tool_call( $token_id, $tool_name, $arguments, $status ) {
        if ( ! $this->audit_log_enabled ) {
            return 0;
        }
        global $wpdb;
        $safe_args = $this->redact_for_tool( $tool_name, $arguments );
        $source = in_array( $this->request_auth_source, array( 'legacy', 'oauth' ), true ) ? $this->request_auth_source : null;
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert required for audit logging.
            $wpdb->prefix . 'easy_mcp_ai_audit_log',
            array(
                'token_id'        => $token_id ? (int) $token_id : 0,
                'tool_name'       => $tool_name,
                'arguments'       => wp_json_encode( $safe_args ),
                'result_status'   => $status,
                'ip_address'      => self::get_client_ip(),
                'auth_source'     => $source,
                'wp_user_id'      => $this->request_wp_user_id > 0 ? (int) $this->request_wp_user_id : null,
                'oauth_client_id' => ( is_string( $this->request_client_id ) && '' !== $this->request_client_id ) ? $this->request_client_id : null,
                'created_at'      => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );
        return (int) $wpdb->insert_id;
    }

    private function update_audit_status( $audit_id, $status, $duration_ms = null ) {
        if ( ! $this->audit_log_enabled || ! $audit_id ) {
            return;
        }
        global $wpdb;
        $data   = array( 'result_status' => $status );
        $format = array( '%s' );
        if ( null !== $duration_ms ) {
            $data['duration_ms'] = max( 0, (int) $duration_ms );
            $format[]            = '%d';
        }
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update required to finalize audit status.
            $wpdb->prefix . 'easy_mcp_ai_audit_log',
            $data,
            array( 'id' => (int) $audit_id ),
            $format,
            array( '%d' )
        );
    }

    private function redact_for_tool( $tool_name, $args ) {
        $safe = self::redact_sensitive_args( $args );
        if ( ! is_array( $safe ) || ! is_string( $tool_name ) || '' === $tool_name || ! $this->tool_registry ) {
            return $safe;
        }
        $tool = $this->tool_registry->get_tool( $tool_name );
        if ( ! $tool || ! method_exists( $tool, 'get_redacted_arguments' ) ) {
            return $safe;
        }
        foreach ( (array) $tool->get_redacted_arguments() as $key ) {
            if ( is_string( $key ) && array_key_exists( $key, $safe ) ) {
                $safe[ $key ] = '[REDACTED]';
            }
        }
        return self::record_redaction( $tool, $safe );
    }

    private static function record_redaction( \Easy_MCP_AI\Tools\Base_Tool $tool, array $args ): array {
        try {
            $masked = $tool->redact_for_record( $args );
        } catch ( \Throwable $e ) {
            $masked = null;
        }
        if ( is_array( $masked ) ) {
            return $masked;
        }
        $all = array();
        foreach ( array_keys( $args ) as $key ) {
            $all[ $key ] = '[REDACTED]';
        }
        return $all;
    }

    public static function is_sensitive_argument( string $name ): bool {
        return \Easy_MCP_AI\Ascii::matches( self::SENSITIVE_ARG_PATTERN, $name );
    }

    const SHORTEST_MASKED_VALUE = 4;

    const SHORTEST_MASKED_FRAGMENT = 8;

    const MASKED_FRAGMENT_SCAN = 4096;

    const MASKED_FRAGMENT_CHARACTERS = 2000000;

    const FEW_MASKED_WINDOWS = 256;

    const MASKED_STANDALONE_PLACES = 20000;

    const MANY_MASKED_VALUES = 64;

    const MASKED_SEARCH_COST = 134217728;

    private static $search_spent = 0;

    const QUOTED_JSON_FLAGS = array(
        0,
        JSON_UNESCAPED_SLASHES,
        JSON_UNESCAPED_UNICODE,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS,
    );

    private function masked_values_of( $tool_name, $arguments ): array {
        $recorded = $this->redact_for_tool( $tool_name, $arguments );
        $pairs    = array( array( $arguments, $recorded ) );
        $tool     = is_string( $tool_name ) && $this->tool_registry ? $this->tool_registry->get_tool( $tool_name ) : null;
        if ( $tool instanceof \Easy_MCP_AI\Tools\Base_Tool && is_array( $arguments ) && is_array( $recorded ) ) {
            try {
                $pairs = $tool->masked_comparisons( $arguments, $recorded );
            } catch ( \Throwable $e ) {
                $pairs = array( array( $arguments, $recorded ) );
            }
        }
        $all = array(
            'values'     => array(),
            'words'      => array(),
            'standalone' => array(),
            'pieces'     => array(),
        );
        foreach ( $pairs as $pair ) {
            if ( ! is_array( $pair ) || ! array_key_exists( 0, $pair ) || ! array_key_exists( 1, $pair ) ) {
                continue;
            }
            foreach ( self::masked_values( $pair[0], $pair[1] ) as $list => $values ) {
                $all[ $list ] = array_merge( $all[ $list ], $values );
            }
        }
        foreach ( $all as $list => $values ) {
            $all[ $list ] = self::longest_first( array_values( array_unique( $values ) ) );
        }
        return $all;
    }

    private static function longest_first( array $list ): array {
        usort(
            $list,
            static function ( string $a, string $b ): int {
                return strlen( $b ) - strlen( $a );
            }
        );
        return $list;
    }

    private static function written_over( string $text, array $masked ): string {
        if ( '' === $text ) {
            return $text;
        }
        self::$search_spent = 0;
        $pieces = isset( $masked['pieces'] ) ? $masked['pieces'] : array();
        $chars  = array();
        $limit  = strlen( $text );
        if ( array() !== $pieces ) {
            $chars = array_slice( self::characters( $text ), 0, self::MASKED_FRAGMENT_SCAN + self::SHORTEST_MASKED_FRAGMENT - 1 );
            $limit = strlen( implode( '', array_slice( $chars, 0, self::MASKED_FRAGMENT_SCAN ) ) );
        }
        $marked = str_repeat( "\0", strlen( $text ) );
        $many   = 0;
        foreach ( array( 'values', 'words', 'standalone' ) as $list ) {
            $many += isset( $masked[ $list ] ) ? count( $masked[ $list ] ) : 0;
        }
        $grams = $many > self::MANY_MASKED_VALUES ? array() : null;
        foreach ( array( 'values', 'words', 'standalone' ) as $list ) {
            if ( ! isset( $masked[ $list ] ) ) {
                continue;
            }
            $kept = array();
            foreach ( $masked[ $list ] as $value ) {
                if ( null === $grams || self::may_appear( $text, $value, $grams ) ) {
                    $kept[] = $value;
                }
            }
            $masked[ $list ] = $kept;
        }
        foreach ( array( 'values', 'words' ) as $list ) {
            foreach ( isset( $masked[ $list ] ) ? $masked[ $list ] : array() as $value ) {
                self::mark_matches( $text, $value, $marked );
            }
        }
        if ( self::$search_spent > self::MASKED_SEARCH_COST ) {
            return \Easy_MCP_AI\Text_Redactor::MASK;
        }
        if ( array() !== $pieces && ! self::mark_fragments( $chars, $pieces, $marked ) ) {
            return \Easy_MCP_AI\Text_Redactor::MASK;
        }
        if ( ! empty( $masked['standalone'] ) && ( ! self::mark_standalone( $text, $masked['standalone'], $marked ) || self::$search_spent > self::MASKED_SEARCH_COST ) ) {
            return \Easy_MCP_AI\Text_Redactor::MASK;
        }
        $out  = '';
        $from = 0;
        if ( 0 < (int) preg_match_all( '/\x01+/', $marked, $runs, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $runs[0] as $run ) {
                list( $start, $end ) = self::whole_characters( $text, array( $run[1], $run[1] + strlen( $run[0] ) ) );
                $start               = max( $start, $from );
                if ( $start >= $limit ) {
                    break;
                }
                $out .= substr( $text, $from, $start - $from ) . \Easy_MCP_AI\Text_Redactor::MASK;
                $from = max( $from, $end );
            }
        }
        return $from < $limit ? $out . substr( $text, $from, $limit - $from ) : $out;
    }

    private static function mark_standalone( string $text, array $values, string &$marked ): bool {
        $places   = array();
        $by_start = array();
        $by_end   = array();
        $first = strpos( $marked, "\0" );
        $last  = strrpos( $marked, "\0" );
        if ( false === $first || false === $last ) {
            return true;
        }
        foreach ( $values as $value ) {
            $from = max( 0, $first - strlen( $value ) + 1 );
            for ( $at = self::search( $text, $value, $from ); false !== $at && $at <= $last; $at = self::search( $text, $value, $at + 1 ) ) {
                if ( count( $places ) >= self::MASKED_STANDALONE_PLACES ) {
                    return false;
                }
                $index                                  = count( $places );
                $places[]                               = array( $at, $at + strlen( $value ) );
                $by_start[ $at ][]                      = $index;
                $by_end[ $at + strlen( $value ) ][]     = $index;
            }
        }
        $queue = array_keys( $places );
        $done  = array();
        while ( array() !== $queue ) {
            $index = array_pop( $queue );
            if ( isset( $done[ $index ] ) ) {
                continue;
            }
            list( $start, $end ) = $places[ $index ];
            if ( false === strpos( substr( $marked, $start, $end - $start ), "\0" ) ) {
                $done[ $index ] = true;
                continue;
            }
            if ( ! self::stands_alone( $text, $marked, $start, $end - $start ) ) {
                continue;
            }
            self::mark( $marked, $start, $end );
            $done[ $index ] = true;
            for ( $at = $start; $at <= $end; $at++ ) {
                foreach ( isset( $by_start[ $at ] ) && $at > $start ? $by_start[ $at ] : array() as $next ) {
                    $queue[] = $next;
                }
                foreach ( isset( $by_end[ $at ] ) && $at < $end ? $by_end[ $at ] : array() as $next ) {
                    $queue[] = $next;
                }
            }
        }
        return true;
    }

    private static function may_appear( string $text, string $value, array &$grams ): bool {
        $width = min( 4, strlen( $value ) );
        if ( ! isset( $grams[ $width ] ) ) {
            $grams[ $width ] = array();
            for ( $at = 0, $last = strlen( $text ) - $width; $at <= $last; $at++ ) {
                $grams[ $width ][ substr( $text, $at, $width ) ] = true;
            }
        }
        for ( $at = 0, $last = strlen( $value ) - $width; $at <= $last; $at++ ) {
            if ( ! isset( $grams[ $width ][ substr( $value, $at, $width ) ] ) ) {
                return false;
            }
        }
        return true;
    }

    private static function mark_matches( string $text, string $value, string &$marked ): void {
        $length = strlen( $value );
        $at     = self::first_place( $text, $value, $marked, 0 );
        while ( false !== $at ) {
            self::mark( $marked, $at, $at + $length );
            $hole = strpos( $marked, "\0", $at + 1 );
            if ( false === $hole ) {
                return;
            }
            $from                = max( $at + 1, $hole - $length + 1 );
            $window              = substr( $text, $from, $hole - $from + $length );
            $back                = strlen( $window ) >= $length && self::charge( ( strlen( $window ) - $length + 1 ) * $length ) ? strrpos( $window, $value ) : false;
            $at   = false !== $back ? $from + $back : self::first_place( $text, $value, $marked, $hole + 1 );
        }
    }

    private static function first_place( string $text, string $value, string $marked, int $from ) {
        if ( $from >= strlen( $text ) ) {
            return false;
        }
        $hole = strpos( $marked, "\0", $from );
        if ( false === $hole ) {
            return false;
        }
        return self::search( $text, $value, max( $from, $hole - strlen( $value ) + 1 ) );
    }

    private static function search( string $text, string $value, int $from ) {
        $length = strlen( $value );
        $places = strlen( $text ) - $from - $length + 1;
        if ( $places <= 0 ) {
            return false;
        }
        if ( ! self::charge( $places * $length ) ) {
            return false;
        }
        $at = strpos( $text, $value, $from );
        if ( false !== $at ) {
            self::$search_spent -= (int) ( ( $places - ( $at - $from + 1 ) ) * $length );
        }
        return $at;
    }

    private static function charge( $cost ): bool {
        if ( self::$search_spent + $cost > self::MASKED_SEARCH_COST ) {
            self::$search_spent = self::MASKED_SEARCH_COST + 1;
            return false;
        }
        self::$search_spent += (int) $cost;
        return true;
    }

    private static function mark( string &$marked, int $start, int $end ): void {
        for ( $at = $start; $at < $end; $at++ ) {
            $marked[ $at ] = "\1";
        }
    }

    private static function characters( string $text ): array {
        $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( is_array( $chars ) ) {
            return $chars;
        }
        return '' === $text ? array() : str_split( $text );
    }

    private static function stands_alone( string $text, string $marked, int $at, int $length ): bool {
        $size = strlen( $text );
        if ( $at > 0 && "\1" !== $marked[ $at - 1 ] ) {
            $start = $at - 1;
            while ( $start > 0 && 0x80 === ( ord( $text[ $start ] ) & 0xC0 ) ) {
                --$start;
            }
            if ( self::is_word_character( substr( $text, $start, $at - $start ) ) ) {
                return false;
            }
        }
        $end = $at + $length;
        if ( $end < $size && "\1" !== $marked[ $end ] ) {
            $stop = $end + 1;
            while ( $stop < $size && 0x80 === ( ord( $text[ $stop ] ) & 0xC0 ) ) {
                ++$stop;
            }
            if ( self::is_word_character( substr( $text, $end, $stop - $end ) ) ) {
                return false;
            }
        }
        return true;
    }

    private static function is_word_character( string $char ): bool {
        return 1 === preg_match( '/^[\p{L}\p{N}_-]$/u', $char );
    }

    private static function whole_characters( string $text, array $span ): array {
        list( $start, $end ) = $span;
        $size = strlen( $text );
        while ( $start > 0 && 0x80 === ( ord( $text[ $start ] ) & 0xC0 ) ) {
            --$start;
        }
        while ( $end < $size && 0x80 === ( ord( $text[ $end ] ) & 0xC0 ) ) {
            ++$end;
        }
        return array( $start, $end );
    }

    private static function mark_fragments( array $chars, array $values, string &$marked ): bool {
        $width = self::SHORTEST_MASKED_FRAGMENT;
        $count = count( $chars );
        if ( $count < $width ) {
            return true;
        }
        $offsets = self::offsets( $chars );
        $text    = implode( '', $chars );
        $windows = array();
        for ( $at = 0; $at + $width <= $count; $at++ ) {
            $windows[ substr( $text, $offsets[ $at ], $offsets[ $at + $width ] - $offsets[ $at ] ) ][] = $at;
        }
        $budget = self::MASKED_FRAGMENT_CHARACTERS;
        foreach ( $values as $value ) {
            if ( count( $windows ) <= self::FEW_MASKED_WINDOWS ) {
                $budget -= strlen( $value );
                if ( $budget < 0 ) {
                    return false;
                }
                foreach ( $windows as $window => $starts ) {
                    $found = self::search( $value, (string) $window, 0 );
                    if ( self::$search_spent > self::MASKED_SEARCH_COST ) {
                        return false;
                    }
                    if ( false !== $found ) {
                        $reach = 0;
                        foreach ( $starts as $start ) {
                            self::mark( $marked, max( $offsets[ $start ], $reach ), $offsets[ $start + $width ] );
                            $reach = $offsets[ $start + $width ];
                        }
                        unset( $windows[ $window ] );
                    }
                }
                if ( array() === $windows ) {
                    break;
                }
                continue;
            }
            $value_chars = self::characters( $value );
            $length      = count( $value_chars );
            $budget     -= $length;
            if ( $budget < 0 ) {
                return false;
            }
            $value_offsets = self::offsets( $value_chars );
            for ( $at = 0; $at + $width <= $length; $at++ ) {
                $window = substr( $value, $value_offsets[ $at ], $value_offsets[ $at + $width ] - $value_offsets[ $at ] );
                if ( isset( $windows[ $window ] ) ) {
                    $reach = 0;
                    foreach ( $windows[ $window ] as $start ) {
                        self::mark( $marked, max( $offsets[ $start ], $reach ), $offsets[ $start + $width ] );
                        $reach = $offsets[ $start + $width ];
                    }
                    unset( $windows[ $window ] );
                }
            }
            if ( array() === $windows ) {
                break;
            }
        }
        return true;
    }

    private static function offsets( array $chars ): array {
        $offsets = array( 0 );
        $at      = 0;
        foreach ( $chars as $char ) {
            $at       += strlen( $char );
            $offsets[] = $at;
        }
        return $offsets;
    }

    private static function masked_values( $sent, $recorded ): array {
        $values     = array();
        $words      = array();
        $standalone = array();
        $pieces     = array();
        $whole      = array();
        $known      = self::masked_parts( $sent, $recorded, $whole );
        $add        = static function ( string $piece ) use ( &$values, &$standalone ): void {
            $trimmed = trim( $piece );
            if ( ! self::is_secret_text( $trimmed ) ) {
                return;
            }
            $into = strlen( $trimmed ) >= self::SHORTEST_MASKED_VALUE;
            foreach ( self::quoted_forms( $trimmed ) as $form ) {
                if ( $into ) {
                    $values[ $form ] = true;
                } else {
                    $standalone[ $form ] = true;
                }
            }
            if ( $trimmed !== $piece ) {
                foreach ( self::quoted_forms( $piece ) as $form ) {
                    if ( $form === $piece ) {
                        continue;
                    }
                    if ( $into ) {
                        $values[ $form ] = true;
                    } else {
                        $standalone[ $form ] = true;
                    }
                }
            }
        };
        foreach ( $whole as $value ) {
            $add( (string) $value );
        }
        foreach ( $known as $part ) {
            $part = (string) $part;
            $add( $part );
            $lines = preg_split( '/\R/u', $part );
            foreach ( is_array( $lines ) ? $lines : explode( "\n", $part ) as $line ) {
                $add( (string) $line );
            }
            $split = preg_split( '/\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY );
            foreach ( is_array( $split ) ? $split : preg_split( '/[ \t\r\n]+/', $part, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
                if ( self::is_secret_text( (string) $word ) ) {
                    foreach ( self::quoted_forms( (string) $word ) as $form ) {
                        if ( strlen( (string) $word ) >= self::SHORTEST_MASKED_FRAGMENT ) {
                            $words[ $form ] = true;
                        } else {
                            $standalone[ $form ] = true;
                        }
                    }
                }
            }
            if ( strlen( trim( $part ) ) >= self::SHORTEST_MASKED_FRAGMENT && self::is_secret_text( $part ) ) {
                foreach ( self::quoted_forms( $part ) as $form ) {
                    $pieces[ $form ] = true;
                }
            }
        }
        return array(
            'values'     => self::longest_first( array_map( 'strval', array_keys( $values ) ) ),
            'words'      => self::longest_first( array_map( 'strval', array_keys( $words ) ) ),
            'standalone' => self::longest_first( array_map( 'strval', array_keys( $standalone ) ) ),
            'pieces'     => self::longest_first( array_map( 'strval', array_keys( $pieces ) ) ),
        );
    }

    private static function quoted_forms( string $text ): array {
        $forms = array( $text => true );
        foreach ( self::QUOTED_JSON_FLAGS as $flags ) {
            $json = wp_json_encode( $text, $flags );
            if ( is_string( $json ) && strlen( $json ) > 2 ) {
                $forms[ substr( $json, 1, -1 ) ] = true;
            }
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- the form an error quotes the value in.
        $forms[ substr( var_export( $text, true ), 1, -1 ) ] = true;
        $printable = preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static function ( array $byte ): string {
                $named = array(
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    "\0" => '\\0',
                );
                return isset( $named[ $byte[0] ] ) ? $named[ $byte[0] ] : sprintf( '\\x%02X', ord( $byte[0] ) );
            },
            $text
        );
        if ( is_string( $printable ) ) {
            $forms[ $printable ] = true;
        }
        return array_map( 'strval', array_keys( $forms ) );
    }

    private static function is_secret_text( string $text ): bool {
        $text  = str_replace( \Easy_MCP_AI\Text_Redactor::MASK, '', $text );
        $found = preg_match( '/[\p{L}\p{N}]/u', $text );
        return false === $found ? 1 === preg_match( '/[A-Za-z0-9\x80-\xff]/', $text ) : 1 === $found;
    }

    private static function masked_parts( $sent, $recorded, array &$whole ): array {
        if ( $sent === $recorded ) {
            return array();
        }
        if ( is_array( $sent ) ) {
            $parts = array();
            foreach ( $sent as $key => $value ) {
                $parts = array_merge( $parts, self::masked_parts( $value, is_array( $recorded ) && array_key_exists( $key, $recorded ) ? $recorded[ $key ] : null, $whole ) );
            }
            return $parts;
        }
        if ( is_string( $sent ) && is_array( $recorded ) ) {
            $decoded = json_decode( $sent, true );
            if ( is_array( $decoded ) ) {
                return self::masked_parts( $decoded, $recorded, $whole );
            }
        }
        if ( ! is_scalar( $sent ) ) {
            return array();
        }
        if ( \Easy_MCP_AI\Text_Redactor::MASK === $recorded ) {
            return array( (string) $sent );
        }
        if ( is_string( $sent ) && is_string( $recorded ) ) {
            $words = self::masked_words( $sent, $recorded );
            $words = null === $words ? self::masked_word_run( self::shell_words( $sent ), self::shell_words( $recorded ) ) : $words;
            $spans = false === strpos( $recorded, \Easy_MCP_AI\Text_Redactor::MASK ) ? null : self::masked_spans( $sent, $recorded );
            if ( null !== $words || null !== $spans ) {
                return array_merge( null === $spans ? array() : $spans, null === $words ? array() : $words );
            }
        }
        $whole[] = (string) $sent;
        return array();
    }

    private static function masked_words( string $sent, string $recorded ): ?array {
        $sent_words     = self::shell_words( $sent );
        $recorded_words = self::shell_words( $recorded );
        if ( count( $sent_words ) !== count( $recorded_words ) ) {
            return null;
        }
        $parts = array();
        foreach ( $sent_words as $index => $word ) {
            if ( $word !== $recorded_words[ $index ] ) {
                $spans = self::masked_spans( $word, $recorded_words[ $index ] );
                $parts = array_merge( $parts, null === $spans ? array( $word ) : $spans );
            }
        }
        return $parts;
    }

    private static function masked_word_run( array $sent, array $recorded ): ?array {
        $at = array_search( \Easy_MCP_AI\Text_Redactor::MASK, $recorded, true );
        if ( false === $at ) {
            return null;
        }
        $before = array_slice( $recorded, 0, (int) $at );
        $after  = array_slice( $recorded, (int) $at + 1 );
        if ( in_array( \Easy_MCP_AI\Text_Redactor::MASK, $after, true ) ) {
            return null;
        }
        $end = count( $sent ) - count( $after );
        if ( array_slice( $sent, max( 0, $end ) ) !== $after ) {
            return null;
        }
        for ( $start = 0; $start + count( $before ) <= $end; $start++ ) {
            if ( array_slice( $sent, $start, count( $before ) ) === $before ) {
                $from = $start + count( $before );
                return $from < $end ? array( implode( ' ', array_slice( $sent, $from, $end - $from ) ) ) : array();
            }
        }
        return null;
    }

    private static function shell_words( string $text ): array {
        $words  = array();
        $word   = '';
        $inside = false;
        $quote  = '';
        $length = strlen( $text );
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $text[ $i ];
            if ( "'" === $quote ) {
                if ( "'" === $char ) {
                    $quote = '';
                } else {
                    $word .= $char;
                }
                continue;
            }
            if ( '"' === $quote ) {
                if ( '\\' === $char && $i + 1 < $length && "\n" === $text[ $i + 1 ] ) {
                    ++$i;
                } elseif ( '\\' === $char && $i + 1 < $length && false !== strpos( '"\\$`', $text[ $i + 1 ] ) ) {
                    $word .= $text[ ++$i ];
                } elseif ( '"' === $char ) {
                    $quote = '';
                } else {
                    $word .= $char;
                }
                continue;
            }
            if ( '\\' === $char && $i + 1 < $length && "\n" === $text[ $i + 1 ] ) {
                ++$i;
            } elseif ( '\\' === $char && $i + 1 < $length ) {
                $word  .= $text[ ++$i ];
                $inside = true;
            } elseif ( "'" === $char || '"' === $char ) {
                $quote  = $char;
                $inside = true;
            } elseif ( ctype_space( $char ) ) {
                if ( $inside ) {
                    $words[] = $word;
                    $word    = '';
                    $inside  = false;
                }
            } else {
                $word  .= $char;
                $inside = true;
            }
        }
        if ( $inside ) {
            $words[] = $word;
        }
        return $words;
    }

    private static function masked_spans( string $sent, string $recorded ): ?array {
        $pieces = explode( \Easy_MCP_AI\Text_Redactor::MASK, $recorded );
        $last   = count( $pieces ) - 1;
        if ( 0 === $last || 0 !== strncmp( $sent, $pieces[0], strlen( $pieces[0] ) ) ) {
            return null;
        }
        $spans = array();
        $at    = strlen( $pieces[0] );
        for ( $i = 1; $i <= $last; $i++ ) {
            $after = $pieces[ $i ];
            if ( $i === $last ) {
                $end = strlen( $sent ) - strlen( $after );
                if ( $end < $at || substr( $sent, $end ) !== $after ) {
                    return null;
                }
            } else {
                $end = '' === $after ? false : strpos( $sent, $after, $at );
                if ( false === $end ) {
                    return null;
                }
            }
            $spans[] = substr( $sent, $at, $end - $at );
            $at      = $end + strlen( $after );
        }
        return $spans;
    }

    private static function redact_sensitive_args( $args ) {
        if ( ! is_array( $args ) ) {
            return $args;
        }
        $result = array();
        foreach ( $args as $key => $value ) {
            if ( self::is_sensitive_argument( (string) $key ) ) {
                $result[ $key ] = '[REDACTED]';
            } elseif ( is_array( $value ) ) {
                $result[ $key ] = self::redact_sensitive_args( $value );
            } else {
                $result[ $key ] = $value;
            }
        }
        return $result;
    }

    private static function get_client_ip() {
        if ( class_exists( '\Easy_MCP_AI\Client_IP' ) ) {
            $ip = (string) \Easy_MCP_AI\Client_IP::get();
            return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
        }
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        } else {
            $ip = '';
        }
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    public static function matches_tool_patterns( $tool_name, array $patterns ) {
        if ( empty( $patterns ) ) {
            return true;
        }
        foreach ( $patterns as $pattern ) {
            $pattern = trim( (string) $pattern );
            if ( '' === $pattern ) {
                continue;
            }
            if ( false === strpos( $pattern, '*' ) && false === strpos( $pattern, '?' ) ) {
                $pattern = '*' . $pattern . '*';
            }
            if ( fnmatch( $pattern, $tool_name ) ) {
                return true;
            }
        }
        return false;
    }

    private function tool_matches_pattern_filter( $tool_name ) {
        return self::matches_tool_patterns( $tool_name, (array) $this->allowed_tool_patterns );
    }

    public function get_session_manager() {
        return $this->session_manager;
    }
}
