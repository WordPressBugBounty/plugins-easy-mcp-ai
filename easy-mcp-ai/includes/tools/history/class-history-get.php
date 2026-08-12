<?php
namespace Easy_MCP_AI\Tools\History;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\History\Change_Log_Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class History_Get extends Base_Tool {
    public function get_name() { return 'wp_history_get'; }
    public function get_description() {
        return 'Get one change-history entry by id, including before/after payloads. For post/page entries with a revision_id, also returns the linked revision post_content — but only when you can read the parent post; otherwise that field is omitted. Non-admin callers (without the `easy_mcp_ai_view_all_history` capability) can only fetch entries they originated; entries belonging to another user are reported as "not found" rather than a permission error. The `ip_address` field is included only for callers with `easy_mcp_ai_view_all_history` (admins).';
    }
    public function get_category() { return 'history'; }
    public function get_required_capability() { return 'read'; }

    public function get_annotations() {
        
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array( 'id' => array( 'type' => 'integer' ) ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $args ) {
        $this->validate_required( $args, array( 'id' ) );
        $row = ( new Change_Log_Repository() )->find( (int) $args['id'] );
        if ( ! $row ) {
            throw new \RuntimeException( 'History entry not found' );
        }

        
        
        
        $can_view_all = \current_user_can( 'easy_mcp_ai_view_all_history' );
        if ( ! $can_view_all && (int) ( $row['wp_user_id'] ?? 0 ) !== (int) \get_current_user_id() ) {
            throw new \RuntimeException( 'History entry not found' );
        }

        $out = array(
            'id'              => (int) $row['id'],
            'audit_id'        => isset( $row['audit_id'] ) ? (int) $row['audit_id'] : null,
            'tool_name'       => $row['tool_name'],
            'action'          => $row['action'],
            'object_type'     => $row['object_type'],
            'object_id'       => $row['object_id'],
            'object_subtype'  => $row['object_subtype'] ?? null,
            'before_value'    => ! empty( $row['before_value'] )   ? json_decode( $row['before_value'],   true ) : null,
            'after_value'     => ! empty( $row['after_value'] )    ? json_decode( $row['after_value'],    true ) : null,
            'changed_fields'  => ! empty( $row['changed_fields'] ) ? json_decode( $row['changed_fields'], true ) : null,
            'revision_id'     => isset( $row['revision_id'] ) ? (int) $row['revision_id'] : null,
            'wp_user_id'      => (int) ( $row['wp_user_id'] ?? 0 ),
            'oauth_client_id' => $row['oauth_client_id'] ?? null,
            'auth_source'     => $row['auth_source'] ?? null,
            'created_at'      => $row['created_at'] ?? null,
            'truncated'       => ! empty( $row['truncated'] ),
            
            
            'capture_mode'    => ( isset( $row['capture_mode'] ) && '' !== $row['capture_mode'] ) ? $row['capture_mode'] : 'hooked',
        );
        if ( $can_view_all && isset( $row['ip_address'] ) ) {
            $out['ip_address'] = $row['ip_address'];
        }

        
        
        
        
        $after_payload = is_array( $out['after_value'] ?? null ) ? $out['after_value'] : array();
        if ( ! $out['revision_id'] && isset( $after_payload['content_capture'] ) ) {
            $out['revision_content_unavailable'] = (string) $after_payload['content_capture'];
        }

        if ( $out['revision_id'] && function_exists( '\wp_get_post_revision' ) ) {
            $rev = \wp_get_post_revision( $out['revision_id'] );
            
            
            
            
            
            
            
            
            
            
            
            if ( ! $rev ) {
                $out['revision_content_unavailable'] = __( 'unavailable — the linked revision no longer exists; it was pruned by the revision limit, or removed with its parent post', 'easy-mcp-ai' );
            }

            if ( $rev && isset( $rev->post_content ) ) {
                $parent_id = isset( $rev->post_parent ) ? (int) $rev->post_parent : 0;
                $allowed   = $can_view_all
                    || ( $parent_id > 0 && \current_user_can( 'read_post', $parent_id ) );
                if ( $allowed ) {
                    $out['revision_content'] = $rev->post_content;
                }
            }
        }
        return $out;
    }
}
