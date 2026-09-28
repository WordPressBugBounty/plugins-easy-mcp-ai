<?php
namespace Easy_MCP_AI\MCP;

use Easy_MCP_AI\Auth\Token_Manager;
use Easy_MCP_AI\Auth\Permission_Guard;
use Easy_MCP_AI\Tools\Tool_Registry;
use Easy_MCP_AI\Resources\Resource_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Server {
    const PROTOCOL_VERSION = '2026-07-28';
    
    const LEGACY_PROTOCOL_VERSION = '2025-11-25';
    const SERVER_NAME      = 'easy-mcp-ai';

    













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
    private $disabled_tools;
    private $audit_log_enabled;
    private $allowed_tool_patterns;
    private $last_negotiated_version;

    
    
    
    private $request_auth_source = null;
    private $request_wp_user_id  = 0;
    private $request_client_id   = null;

    
    
    
    private $task_manager        = null;
    private $skip_rate_limit     = false;

    








    private static $active_call = null;

    public function __construct( Tool_Registry $tool_registry, Resource_Registry $resource_registry, Token_Manager $token_manager ) {
        $this->tool_registry     = $tool_registry;
        $this->resource_registry = $resource_registry;
        $this->token_manager     = $token_manager;
        $this->session_manager   = new Session();
        $this->permission_guard  = new Permission_Guard( $token_manager );
        $this->disabled_tools       = (array) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_disabled_tools', array() );
        $this->audit_log_enabled    = (bool)  \Easy_MCP_AI\Config::get( 'easy_mcp_ai_audit_log_enabled', true );
        $this->allowed_tool_patterns = (array) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_allowed_tool_patterns', array() );

        
        
        register_shutdown_function( function () {
            $err = error_get_last();
            if ( ! $err ) { return; }
            if ( ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
                return;
            }
            if ( ! class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) ) { return; }
            if ( ! \Easy_MCP_AI\History\Change_Context::is_active() ) { return; }
            $audit_id = \Easy_MCP_AI\History\Change_Context::get( 'audit_id' );
            if ( $audit_id ) {
                $this->update_audit_status( (int) $audit_id, 'error' );
            }
        } );
    }

    public function set_request_identity( $auth_source, $wp_user_id, $client_id = null ) {
        $this->request_auth_source = $auth_source;
        $this->request_wp_user_id  = (int) $wp_user_id;
        $this->request_client_id   = $client_id;
    }

    public function clear_request_identity() {
        $this->request_auth_source = null;
        $this->request_wp_user_id  = 0;
        $this->request_client_id   = null;
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
            $dir = dirname( __DIR__ ) . '/tasks/';
            require_once $dir . 'class-task-schema.php';
            require_once $dir . 'interface-task-store.php';
            require_once $dir . 'class-wpdb-task-store.php';
            require_once $dir . 'class-task-contract.php';
            require_once $dir . 'class-task-scheduler.php';
            require_once $dir . 'class-task-manager.php';
            $this->task_manager = new \Easy_MCP_AI\Tasks\Task_Manager( $this, $this->tool_registry, new \Easy_MCP_AI\Tasks\Wpdb_Task_Store() );
        }
        return $this->task_manager;
    }

    public function set_task_manager( $task_manager ) {
        $this->task_manager = $task_manager;
    }

    





    public function call_tool_internal( $id, $tool_name, array $arguments, $token_id, $allowed_tools, $count_rate_limit ) {
        $previous              = $this->skip_rate_limit;
        $this->skip_rate_limit = ! $count_rate_limit;
        try {
            return $this->handle_tools_call( $id, array( 'name' => $tool_name, 'arguments' => $arguments ), $token_id, $allowed_tools );
        } finally {
            $this->skip_rate_limit = $previous;
        }
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
                return $this->handle_initialize( $id, $params, $token_id );
            case 'ping':
                return JSON_RPC::success_response( $id, new \stdClass() );
            case 'tools/list':
                return $this->handle_tools_list( $id, $params, $token_id, $allowed_tools );
            case 'tools/call':
                return $this->handle_tools_call( $id, $params, $token_id, $allowed_tools );
            case 'resources/list':
                return $this->handle_resources_list( $id, $params, $token_id );
            case 'resources/read':
                return $this->handle_resources_read( $id, $params, $token_id );
            case 'prompts/list':
                
                
                
                return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, 'Prompts are not supported' );
            case 'prompts/get':
                return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, 'Prompts are not supported' );
            default:
                return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, 'Method not found' );
        }
    }

    
    const LEGACY_PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );
    const SUPPORTED_PROTOCOL_VERSIONS = array( '2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26' );

    




    public function handle_modern_message( $message, $token_id = null, $allowed_tools = null ) {
        $id = isset( $message['id'] ) ? $message['id'] : null;
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        $method = $message['method'];
        if ( 'server/discover' === $method ) {
            if ( ! $this->check_rate_limit( $token_id ) ) {
                return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, 'Rate limit exceeded. Please try again later.' );
            }
            $response = JSON_RPC::success_response( $id, array(
                'supportedVersions' => self::SUPPORTED_PROTOCOL_VERSIONS,
                'capabilities' => array(
                    'tools'      => new \stdClass(),
                    'resources'  => new \stdClass(),
                    'extensions' => array( 'io.modelcontextprotocol/tasks' => new \stdClass() ),
                ),
                'instructions' => 'WordPress MCP Server. Use tools to manage posts, pages, media, comments, users, and site settings. Use resources to read site information.',
            ) );
        } elseif ( in_array( $method, array( 'tasks/get', 'tasks/update', 'tasks/cancel' ), true ) ) {
            $response = $this->handle_task_method( $id, $method, isset( $message['params'] ) ? $message['params'] : array(), $token_id, $allowed_tools );
        } elseif ( 'tools/call' === $method ) {
            $response = $this->handle_modern_tools_call( $message, $token_id, $allowed_tools );
        } elseif ( in_array( $method, array( 'tools/list', 'resources/list', 'resources/read' ), true ) ) {
            $response = $this->handle_message( $message, $token_id, $allowed_tools );
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
        if ( in_array( $method, array( 'server/discover', 'tools/list', 'resources/list', 'resources/read' ), true ) ) {
            
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
            return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, 'Rate limit exceeded. Please try again later.' );
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
            return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, 'Rate limit exceeded. Please try again later.' );
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

    private function handle_initialize( $id, $params, $token_id ) {
        
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, 'Rate limit exceeded. Please try again later.' );
        }

        
        
        
        
        $client_version = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : null;
        if ( $client_version && in_array( $client_version, self::LEGACY_PROTOCOL_VERSIONS, true ) ) {
            $negotiated_version = $client_version;
        } else {
            
            $negotiated_version = self::LEGACY_PROTOCOL_VERSION;
        }

        
        $this->last_negotiated_version = $negotiated_version;

        return JSON_RPC::success_response( $id, array(
            'protocolVersion' => $negotiated_version,
            'capabilities'    => array(
                'tools'     => new \stdClass(),
                'resources' => new \stdClass(),
            ),
            'serverInfo'      => array( 'name' => self::SERVER_NAME, 'version' => EASY_MCP_AI_VERSION ),
            'instructions'    => 'WordPress MCP Server. Use tools to manage posts, pages, media, comments, users, and site settings. Use resources to read site information.',
        ) );
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
        $disabled = (array) \Easy_MCP_AI\Config::get( 'disabled_tools' );
        $patterns = (array) \Easy_MCP_AI\Config::get( 'allowed_tool_patterns' );
        return array_values( array_filter( null === $definitions ? $registry->get_all_definitions() : $definitions, static function ( $definition ) use ( $registry, $disabled, $patterns, $require_instance ) {
            $name = $definition['name'];
            if ( in_array( $name, $disabled, true ) ) {
                return false;
            }
            if ( ! self::matches_tool_patterns( $name, $patterns ) ) {
                return false;
            }
            $tool = $registry->get_tool( $name );
            if ( ! $tool ) { return ! $require_instance; }
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
            return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, 'Rate limit exceeded. Please try again later.' );
        }

        $all_tools = $this->tool_registry->get_all_definitions();
        
        $allowed = null !== $allowed_tools ? $allowed_tools : $this->permission_guard->get_allowed_tools( $token_id );
        if ( ! in_array( '*', $allowed, true ) ) {
            $all_tools = array_values( array_filter( $all_tools, function ( $tool ) use ( $allowed ) {
                $name = $tool['name'];
                if ( in_array( $name, $allowed, true ) ) {
                    return true;
                }
                
                foreach ( $allowed as $pattern ) {
                    if ( false !== strpos( $pattern, '*' ) && fnmatch( $pattern, $name ) ) {
                        return true;
                    }
                }
                return false;
            } ) );
        }
        $all_tools = self::available_tools( $this->tool_registry, false, $all_tools );
        $all_tools = array_map( function ( $tool ) {
            $tool['inputSchema'] = Gemini_Safe_Schema::sanitize( $tool['inputSchema'] )['schema'];
            if ( isset( $tool['outputSchema'] ) ) {
                $tool['outputSchema'] = Gemini_Safe_Schema::sanitize( $tool['outputSchema'] )['schema'];
            }
            return $tool;
        }, $all_tools );

        return JSON_RPC::success_response( $id, array( 'tools' => $all_tools ) );
    }

    private function handle_tools_call( $id, $params, $token_id, $allowed_tools = null ) {
        $tool_name = isset( $params['name'] ) ? $params['name'] : '';
        $arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();

        if ( empty( $tool_name ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Missing tool name' );
        }

        
        
        
        if ( ! is_array( $arguments ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Tool arguments must be a JSON object' );
        }

        
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        
        
        
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
                return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Token does not have permission to use this tool' );
            }
        } elseif ( ! $this->permission_guard->can_use_tool( $token_id, $tool_name ) ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Token does not have permission to use this tool' );
        }

        
        if ( ! $this->skip_rate_limit && ! $this->check_rate_limit( $token_id ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, 'Rate limit exceeded. Please try again later.' );
        }

        $tool = $this->tool_registry->get_tool( $tool_name );
        if ( null === $tool ) {
            
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Unknown tool' );
        }

        $required_cap = self::effective_required_capability( $tool->get_category(), $tool->get_required_capability() );
        if ( $required_cap && ! \current_user_can( $required_cap ) ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Insufficient WordPress permissions for this tool' );
        }

        if ( ! empty( $this->disabled_tools ) && in_array( $tool_name, $this->disabled_tools, true ) ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'This tool has been disabled by the administrator.' );
        }

        if ( ! $this->tool_matches_pattern_filter( $tool_name ) ) {
            $this->log_refusal( $token_id, $tool_name, $arguments );
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'This tool has been disabled by the administrator.' );
        }

        
        
        
        
        
        
        $audit_id     = $this->log_tool_call( $token_id, $tool_name, $arguments, 'pending' );
        $final_status = null;
        $result       = null;
        
        
        
        
        
        $exec_started = null;
        $outer_call   = self::$active_call;

        
        
        
        
        
        
        
        
        
        
        
        
        
        try {
        if ( class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) ) {
            \Easy_MCP_AI\History\Change_Context::set( array(
                'audit_id'        => $audit_id,
                'tool_name'       => $tool_name,
                'token_id'        => $token_id ? (int) $token_id : 0,
                'auth_source'     => $this->request_auth_source,
                'oauth_client_id' => $this->request_client_id,
                'wp_user_id'      => $this->request_wp_user_id,
                'ip_address'      => self::get_client_ip(),
                
                
                
                'tool_args'       => $arguments,
            ) );
            
            \do_action( 'easy_mcp_ai_change_context_armed' );
        }

            
            
            
            
            
            $arguments    = Gemini_Safe_Schema::coerce( $arguments, Gemini_Safe_Schema::sanitize( $tool->get_input_schema() )['map'] );
            
            
            
            
            if ( class_exists( '\\Easy_MCP_AI\\Meta\\Meta_Exposure' ) ) {
                \Easy_MCP_AI\Meta\Meta_Exposure::register_for_request();
            }
            self::$active_call = array(
                'server'        => $this,
                'token_id'      => $token_id,
                'allowed_tools' => $allowed_tools,
            );
            $exec_started = microtime( true );
            $result       = $tool->execute( $arguments );
            $final_status = 'success';
            
            
            
            
            
            
            if ( is_array( $result ) && isset( $result['error'] ) && is_string( $result['error'] ) ) {
                $result['error'] = self::sanitize_error_message( $result['error'] );
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
                        : wp_json_encode( $has_structured ? $structured_value : $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                ) ),
            );
            if ( $has_structured ) {
                $response['structuredContent'] = $structured_value;
            }
            return JSON_RPC::success_response( $id, $response );
        } catch ( \Exception $e ) {
            
            
            
            $final_status = 'error';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 'WP MCP Server tool exception [%s]: %s in %s:%d', $tool_name, $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging
            }
            $content = array( array( 'type' => 'text', 'text' => 'Error: ' . self::sanitize_error_message( $e->getMessage() ) ) );
            
            
            
            if ( $e instanceof Detailed_Tool_Error && '' !== trim( $e->get_details() ) ) {
                $content[] = array( 'type' => 'text', 'text' => self::sanitize_error_message( $e->get_details(), Detailed_Tool_Error::MAX_DETAILS ) );
            }
            return JSON_RPC::success_response( $id, array(
                'content' => $content,
                'isError' => true,
            ) );
        } catch ( \Error $e ) {
            
            
            $final_status = 'error';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 'WP MCP Server tool error [%s]: %s in %s:%d', $tool_name, $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging
            }
            return JSON_RPC::success_response( $id, array(
                'content' => array( array( 'type' => 'text', 'text' => 'Tool execution failed. Check server error logs for details.' ) ),
                'isError' => true,
            ) );
        } finally {
            self::$active_call = $outer_call;
            
            
            
            $duration_ms = null === $exec_started ? null : (int) round( ( microtime( true ) - $exec_started ) * 1000 );
            $this->update_audit_status( $audit_id, null === $final_status ? 'error' : $final_status, $duration_ms );
            if ( class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) ) {
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                
                try {
                    \do_action( 'easy_mcp_ai_change_context_disarming' );
                } finally {
                    \Easy_MCP_AI\History\Change_Context::clear();
                }
            }
            
            
            
            \Easy_MCP_AI\Tools\Base_Tool::flush_deferred_purges();

            
            
            
            
            
            if ( 'success' === $final_status ) {
                $annotations = $tool->get_annotations();
                if ( empty( $annotations['readOnlyHint'] ) ) {
                    










                    \do_action( 'easy_mcp_ai_tool_mutated', $tool_name, $this->redact_for_tool( $tool_name, $arguments ), $result );
                }
            }
        }
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

    








    private function token_may_use( $token_id, ?array $allowed_tools, string $tool_name ): bool {
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
        return array() !== self::available_tools( $this->tool_registry, true, array( $tool->get_definition() ) );
    }

    








    public static function sanitize_error_message( $message, int $max_length = Detailed_Tool_Error::MAX_MESSAGE ) {
        if ( ! is_string( $message ) || '' === $message ) {
            return 'Tool execution failed.';
        }

        $message = \Easy_MCP_AI\Config::brand( $message );

        
        $message = preg_replace( '/\s*Stack trace:.*$/s', '', $message );

        
        $message = preg_replace( '/\s+in\s+\S+\.php(?:\(\d+\)|:\d+| on line \d+)/', '', $message );

        
        $message = preg_replace( '#(?:/|[A-Z]:\\\\)[^\s\'"<>]*\.(?:php|inc|tpl|phtml)\b#', '[file]', $message );

        
        $message = preg_replace_callback(
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
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
        $message = preg_replace( '/\[?::1\]?|\blocalhost\b/i', '[internal]', $message );

        
        $message    = trim( $message );
        $max_length = max( 1, $max_length );
        if ( strlen( $message ) > $max_length ) {
            $message = substr( $message, 0, $max_length ) . '…[truncated]';
        }

        return '' === $message ? 'Tool execution failed.' : $message;
    }

    private function handle_resources_list( $id, $params, $token_id ) {
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        
        
        if ( self::is_paused() ) {
            return JSON_RPC::success_response( $id, array( 'resources' => array() ) );
        }
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, 'Rate limit exceeded. Please try again later.' );
        }
        
        
        
        if ( ! \current_user_can( 'read' ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Insufficient permissions to list resources' );
        }
        return JSON_RPC::success_response( $id, array( 'resources' => $this->resource_registry->get_all_definitions() ) );
    }

    private function handle_resources_read( $id, $params, $token_id ) {
        if ( null === $token_id ) {
            return JSON_RPC::error_response( $id, Error_Codes::UNAUTHORIZED, 'Authentication required' );
        }
        
        if ( self::is_paused() ) {
            return JSON_RPC::error_response( $id, Error_Codes::PAUSED, Error_Codes::PAUSED_MESSAGE );
        }
        if ( ! $this->check_rate_limit( $token_id ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::RATE_LIMITED, 'Rate limit exceeded. Please try again later.' );
        }

        
        if ( ! \current_user_can( 'read' ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::FORBIDDEN, 'Insufficient permissions to read resources' );
        }

        $uri = isset( $params['uri'] ) ? $params['uri'] : '';
        if ( empty( $uri ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Missing resource URI' );
        }
        $resource = $this->resource_registry->get_resource( $uri );
        if ( null === $resource ) {
            return JSON_RPC::error_response( $id, Error_Codes::RESOURCE_NOT_FOUND, 'Resource not found' );
        }
        try {
            $content = $resource->read();
            return JSON_RPC::success_response( $id, array(
                'contents' => array( array(
                    'uri'      => $uri,
                    'mimeType' => $resource->get_mime_type(),
                    'text'     => is_string( $content ) ? $content : wp_json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                ) ),
            ) );
        } catch ( \Throwable $e ) {
            return JSON_RPC::error_response( $id, Error_Codes::INTERNAL_ERROR, 'Failed to read resource' );
        }
    }

    






















    private function log_refusal( $token_id, $tool_name, $arguments ) {
        if ( null === $token_id ) {
            return;
        }

        $limit = (int) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_rate_limit_per_minute', 60 );
        $key   = 'easy_mcp_ai_reflog_' . (int) $token_id;

        if ( \wp_using_ext_object_cache() ) {
            \wp_cache_add( $key, 0, 'easy_mcp_ai', 60 );
            if ( (int) \wp_cache_incr( $key, 1, 'easy_mcp_ai' ) > $limit ) {
                return;
            }
        } else {
            $written = (int) \get_transient( $key );
            if ( $written >= $limit ) {
                return;
            }
            \set_transient( $key, $written + 1, 60 );
        }

        $this->log_tool_call( $token_id, $tool_name, $arguments, self::STATUS_REFUSED );
    }

    private function check_rate_limit( $token_id ) {
        if ( null === $token_id ) {
            return true; 
        }
        $limit     = (int) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_rate_limit_per_minute', 60 );
        $cache_key = 'easy_mcp_ai_rate_' . (int) $token_id;

        
        
        
        if ( \wp_using_ext_object_cache() ) {
            \wp_cache_add( $cache_key, 0, 'easy_mcp_ai', 60 );
            $new_count = \wp_cache_incr( $cache_key, 1, 'easy_mcp_ai' );
            return $new_count <= $limit;
        }

        
        
        
        
        $current = (int) \get_transient( $cache_key );
        if ( $current >= $limit ) {
            return false;
        }
        \set_transient( $cache_key, $current + 1, 60 );
        return true;
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
        return $safe;
    }

    private static function redact_sensitive_args( $args ) {
        if ( ! is_array( $args ) ) {
            return $args;
        }
        
        $sensitive_pattern = '/^(password|pass|secret|token|api[_\-]?key|authorization|content_base64|download_url|file_id|private[_\-]?key|access[_\-]?token|client[_\-]?secret|credential)$/i';
        $result = array();
        foreach ( $args as $key => $value ) {
            if ( preg_match( $sensitive_pattern, $key ) ) {
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
