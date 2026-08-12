<?php
namespace Easy_MCP_AI\Tools\History;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\History\Change_Log_Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class History_Diff extends Base_Tool {
    public function get_name() { return 'wp_history_diff'; }
    public function get_description() {
        return 'Diff two change-history entries (or one entry against current live state if "id_b" is omitted). Returns per-field before/after for keys that differ. Both entries must refer to the same object_type and object_id, or the call fails with an error. Non-admin callers (without the `easy_mcp_ai_view_all_history` capability) can only diff entries they originated. Sensitive fields (e.g. passwords, emails) are shown as the literal string "[REDACTED]" on both sides rather than their real values. When comparing against live state, the current value cannot always be read — the object type may have no live reader, the plugin that owns it may be inactive, or reading it may need a capability the caller lacks. In that case the response carries "live_state": "unreadable" with an empty "diff" and a "note" explaining why: an empty diff there means no comparison was made, NOT that nothing changed. An "explanations" object may also be present: these are notes the log recorded about the entry itself (e.g. why a post\'s previous body text is not recoverable), not fields of the object, so they are reported separately rather than compared. A "not_compared" object may also be present, on either kind of comparison: it maps each field that appears on only ONE side to the reason why — recorded but not returned by the live reader, present now but never captured, or recorded in only one of the two entries. Those fields were not compared at all, so their absence from "diff" is a limit of the comparison, not evidence that the object did or did not change.';
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
            'properties' => array(
                'id_a' => array( 'type' => 'integer', 'description' => 'Older history entry id' ),
                'id_b' => array( 'type' => 'integer', 'description' => 'Newer history entry id (optional; defaults to live state)' ),
            ),
            'required' => array( 'id_a' ),
        );
    }

    public function execute( array $args ) {
        $this->validate_required( $args, array( 'id_a' ) );
        $repo         = new Change_Log_Repository();
        $can_view_all = \current_user_can( 'easy_mcp_ai_view_all_history' );
        $current_uid  = (int) \get_current_user_id();

        $row_a = $repo->find( (int) $args['id_a'] );
        if ( ! $row_a ) {
            throw new \RuntimeException( 'id_a not found' );
        }
        if ( ! $can_view_all && (int) ( $row_a['wp_user_id'] ?? 0 ) !== $current_uid ) {
            throw new \RuntimeException( 'id_a not found' );
        }
        $a_after = ! empty( $row_a['after_value'] ) ? json_decode( $row_a['after_value'], true ) : array();

        
        
        
        
        
        
        
        
        $explanations = $this->take_explanations( $a_after );

        if ( isset( $args['id_b'] ) ) {
            $row_b = $repo->find( (int) $args['id_b'] );
            if ( ! $row_b ) {
                throw new \RuntimeException( 'id_b not found' );
            }
            if ( ! $can_view_all && (int) ( $row_b['wp_user_id'] ?? 0 ) !== $current_uid ) {
                throw new \RuntimeException( 'id_b not found' );
            }
            
            
            
            
            if ( $row_a['object_type'] !== $row_b['object_type']
                || (string) $row_a['object_id'] !== (string) $row_b['object_id']
            ) {
                throw new \RuntimeException( 'id_a and id_b refer to different objects; diff is only meaningful between history entries of the same object_type and object_id' );
            }
            $b_after = ! empty( $row_b['after_value'] ) ? json_decode( $row_b['after_value'], true ) : array();
            $b_label = (int) $row_b['id'];
        } else {
            $b_after = $this->live_state( $row_a['object_type'], $row_a['object_id'], $can_view_all, $row_a['object_subtype'] ?? null );
            
            
            
            
            
            
            
            
            if ( null === $b_after ) {
                $out = array(
                    'a'           => (int) $row_a['id'],
                    'b'           => 'live',
                    'object_type' => $row_a['object_type'],
                    'object_id'   => $row_a['object_id'],
                    'diff'        => array(),
                    'live_state'  => 'unreadable',
                    'note'        => __( 'The current value could not be read — either this object type has no live reader, the plugin that owns it is not active, or reading it needs a capability you do not hold. No comparison was made; the absence of differences below does not mean nothing changed.', 'easy-mcp-ai' ),
                );
                if ( $explanations ) {
                    $out['explanations'] = $explanations;
                }
                return $out;
            }
            
            
            
            
            
            
            
            
            
            if ( is_array( $b_after ) ) {
                $redacted = \Easy_MCP_AI\History\Change_Redactor::redact( $b_after );
                $b_after  = $redacted['value'];
            }
            $b_label = 'live';
        }

        
        
        $explanations = array_merge( $explanations, $this->take_explanations( $b_after ) );

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        $uncomparable = array();
        $a_keys       = array_keys( (array) $a_after );
        $b_keys       = array_keys( (array) $b_after );

        if ( empty( $a_after ) || empty( $b_after ) ) {
            $keys = array_unique( array_merge( $a_keys, $b_keys ) );
        } else {
            $keys = array_values( array_intersect( $a_keys, $b_keys ) );
            foreach ( array_diff( $a_keys, $b_keys ) as $only_a ) {
                $uncomparable[ $only_a ] = ( 'live' === $b_label )
                    ? __( 'recorded, but this object type\'s live reader does not return it', 'easy-mcp-ai' )
                    : __( 'recorded in the older entry only', 'easy-mcp-ai' );
            }
            foreach ( array_diff( $b_keys, $a_keys ) as $only_b ) {
                $uncomparable[ $only_b ] = ( 'live' === $b_label )
                    ? __( 'present now, but the recorded entry did not capture it', 'easy-mcp-ai' )
                    : __( 'recorded in the newer entry only', 'easy-mcp-ai' );
            }
        }

        $diff = array();
        foreach ( $keys as $k ) {
            $av = $a_after[ $k ] ?? null;
            $bv = $b_after[ $k ] ?? null;
            if ( $av !== $bv ) {
                $diff[ $k ] = array( 'a' => $av, 'b' => $bv );
            }
        }
        $out = array(
            'a'           => (int) $row_a['id'],
            'b'           => $b_label,
            'object_type' => $row_a['object_type'],
            'object_id'   => $row_a['object_id'],
            'diff'        => $diff,
        );
        
        
        
        if ( $uncomparable ) {
            $out['not_compared'] = $uncomparable;
            $out['not_compared_note'] = __( 'These fields appear on only one side of the comparison, so they were not compared. Each entry says why. Their absence from "diff" is a limit of the comparison, not evidence that the object changed or did not change.', 'easy-mcp-ai' );
        }
        if ( $explanations ) {
            $out['explanations'] = $explanations;
        }
        return $out;
    }

    








    private function take_explanations( &$payload ) {
        $found = array();
        if ( ! is_array( $payload ) ) {
            return $found;
        }
        foreach ( \Easy_MCP_AI\History\Change_Recorder::EXPLANATION_FIELDS as $field ) {
            if ( array_key_exists( $field, $payload ) ) {
                $found[ $field ] = $payload[ $field ];
                unset( $payload[ $field ] );
            }
        }
        return $found;
    }

    























    private function live_state( $type, $id, $can_view_all = false, $object_subtype = null ) {
        switch ( $type ) {
            case 'post':
                if ( ! function_exists( '\get_post' ) ) {
                    return null;
                }
                $p = \get_post( (int) $id );
                if ( ! $p ) {
                    return array();
                }
                
                
                
                if ( ! $can_view_all && ! \current_user_can( 'read_post', (int) $id ) ) {
                    return null;
                }
                
                
                
                
                
                
                
                return array(
                    'post_title'     => $p->post_title     ?? null,
                    'post_status'    => $p->post_status    ?? null,
                    'post_type'      => $p->post_type      ?? null,
                    'post_excerpt'   => $p->post_excerpt   ?? null,
                    'post_parent'    => $p->post_parent    ?? null,
                    'menu_order'     => $p->menu_order     ?? null,
                    'comment_status' => $p->comment_status ?? null,
                    'ping_status'    => $p->ping_status    ?? null,
                    
                    
                    
                    
                    'post_password'  => '[REDACTED]',
                    'post_name'      => $p->post_name      ?? null,
                    'post_author'    => $p->post_author    ?? null,
                );

            case 'option':
                
                
                
                
                $allowlist = \Easy_MCP_AI\History\Change_Recorder::option_allowlist();
                if ( ! in_array( (string) $id, $allowlist, true ) ) {
                    return null;
                }
                
                
                
                if ( ! $can_view_all && ! \current_user_can( 'manage_options' ) ) {
                    return null;
                }
                if ( ! function_exists( '\get_option' ) ) {
                    return null;
                }
                return array( 'value' => \get_option( $id ) );

            case 'term':
                if ( ! function_exists( '\get_term' ) ) {
                    return null;
                }
                
                
                
                
                
                
                
                if ( ! $can_view_all && ! \current_user_can( 'manage_categories' ) ) {
                    return null;
                }
                
                
                $term = $object_subtype
                    ? \get_term( (int) $id, (string) $object_subtype )
                    : \get_term( (int) $id );
                if ( ! $term || \is_wp_error( $term ) ) {
                    return array();
                }
                return array(
                    'term_id'     => isset( $term->term_id )     ? (int) $term->term_id     : null,
                    'name'        => isset( $term->name )        ? (string) $term->name     : null,
                    'slug'        => isset( $term->slug )        ? (string) $term->slug     : null,
                    'description' => isset( $term->description ) ? (string) $term->description : null,
                    'parent'      => isset( $term->parent )      ? (int) $term->parent      : null,
                    'count'       => isset( $term->count )       ? (int) $term->count       : null,
                    'taxonomy'    => isset( $term->taxonomy )    ? (string) $term->taxonomy : null,
                );

            case 'user':
                if ( ! function_exists( '\get_userdata' ) ) {
                    return null;
                }
                
                
                
                
                $can_read = $can_view_all
                    || ( (int) \get_current_user_id() === (int) $id )
                    || \current_user_can( 'list_users' );
                if ( ! $can_read ) {
                    return null;
                }
                $u = \get_userdata( (int) $id );
                if ( ! $u ) {
                    return array();
                }
                
                
                
                
                
                
                
                return array(
                    'ID'           => isset( $u->ID )           ? (int) $u->ID           : null,
                    'user_login'   => isset( $u->user_login )   ? (string) $u->user_login : null,
                    'user_email'   => '[REDACTED]',
                    'display_name' => isset( $u->display_name ) ? (string) $u->display_name : null,
                    'roles'        => isset( $u->roles )        ? $u->roles : array(),
                );

            case 'comment':
                if ( ! function_exists( '\get_comment' ) ) {
                    return null;
                }
                $c = \get_comment( (int) $id );
                if ( ! $c ) {
                    return array();
                }
                
                
                $parent = isset( $c->comment_post_ID ) ? (int) $c->comment_post_ID : 0;
                if ( ! $can_view_all && $parent > 0 && ! \current_user_can( 'read_post', $parent ) ) {
                    return null;
                }
                return array(
                    'comment_ID'       => isset( $c->comment_ID )       ? (string) $c->comment_ID       : null,
                    'comment_post_ID'  => isset( $c->comment_post_ID )  ? (string) $c->comment_post_ID  : null,
                    'comment_author'   => isset( $c->comment_author )   ? (string) $c->comment_author   : null,
                    'comment_content'  => isset( $c->comment_content )  ? (string) $c->comment_content  : null,
                    'comment_approved' => isset( $c->comment_approved ) ? (string) $c->comment_approved : null,
                );

            case 'wc_product':
                if ( ! function_exists( '\wc_get_product' ) ) {
                    return null;
                }
                if ( ! $can_view_all && ! \current_user_can( 'edit_products' ) && ! \current_user_can( 'manage_woocommerce' ) ) {
                    return null;
                }
                $product = \wc_get_product( (int) $id );
                if ( ! $product || ! method_exists( $product, 'get_data' ) ) {
                    return array();
                }
                
                
                
                
                
                
                return json_decode( wp_json_encode(
                    \Easy_MCP_AI\History\Change_Recorder::expand_for_storage( $product->get_data() )
                ), true ) ?: array();

            case 'wc_order':
                if ( ! function_exists( '\wc_get_order' ) ) {
                    return null;
                }
                if ( ! $can_view_all && ! \current_user_can( 'edit_shop_orders' ) && ! \current_user_can( 'manage_woocommerce' ) ) {
                    return null;
                }
                $order = \wc_get_order( (int) $id );
                if ( ! $order || ! method_exists( $order, 'get_data' ) ) {
                    return array();
                }
                
                
                
                return json_decode( wp_json_encode(
                    \Easy_MCP_AI\History\Change_Recorder::expand_for_storage( $order->get_data() )
                ), true ) ?: array();

            case 'bp_activity':
                if ( ! function_exists( '\bp_activity_get_specific' ) ) {
                    return null;
                }
                
                
                
                
                if ( ! $can_view_all && ! \current_user_can( 'bp_moderate' ) ) {
                    return null;
                }
                $resp = \bp_activity_get_specific( array( 'activity_ids' => array( (int) $id ) ) );
                if ( empty( $resp['activities'][0] ) ) {
                    return array();
                }
                $a = $resp['activities'][0];
                return array(
                    'id'        => isset( $a->id )        ? (int) $a->id        : null,
                    'user_id'   => isset( $a->user_id )   ? (int) $a->user_id   : null,
                    'component' => isset( $a->component ) ? (string) $a->component : null,
                    'type'      => isset( $a->type )      ? (string) $a->type   : null,
                    'action'    => isset( $a->action )    ? (string) $a->action : null,
                    'content'   => isset( $a->content )   ? (string) $a->content : null,
                    'item_id'   => isset( $a->item_id )   ? (int) $a->item_id   : null,
                    'hide_sitewide' => isset( $a->hide_sitewide ) ? (int) $a->hide_sitewide : null,
                );

            case 'meta':
                
                
                $parts = explode( ':', (string) $id, 2 );
                if ( count( $parts ) !== 2 || ! function_exists( '\get_post_meta' ) ) {
                    return null;
                }
                $post_id  = (int) $parts[0];
                $meta_key = $parts[1];
                
                
                
                if ( ! $can_view_all && ! \current_user_can( 'edit_post', $post_id ) ) {
                    return null;
                }
                
                
                
                if ( function_exists( '\is_protected_meta' ) && \is_protected_meta( $meta_key, 'post' ) ) {
                    return null;
                }
                return array( 'value' => \get_post_meta( $post_id, $meta_key, true ) );

            case 'term_meta':
            case 'user_meta':
            case 'comment_meta':
                
                
                
                
                
                
                
                
                
                return $this->meta_live_state( $type, (string) $id, $can_view_all );

            case 'site_option':
                
                
                
                
                $allowlist = \Easy_MCP_AI\History\Change_Recorder::option_allowlist();
                if ( ! in_array( (string) $id, $allowlist, true ) ) {
                    return null;
                }
                $is_network = function_exists( '\is_multisite' ) && \is_multisite();
                $network_ok = $is_network
                    ? \current_user_can( 'manage_network_options' )
                    : \current_user_can( 'manage_options' );
                
                
                
                
                
                
                
                
                
                
                
                if ( ! $network_ok && ( $is_network || ! $can_view_all ) ) {
                    return null;
                }
                if ( function_exists( '\get_site_option' ) ) {
                    return array( 'value' => \get_site_option( (string) $id ) );
                }
                return function_exists( '\get_option' )
                    ? array( 'value' => \get_option( (string) $id ) )
                    : null;

            default:
                
                
                return null;
        }
    }

    










    private function meta_live_state( $type, $id, $can_view_all ) {
        $parts = explode( ':', $id, 2 );
        if ( count( $parts ) !== 2 ) {
            return null;
        }
        $parent_id = (int) $parts[0];
        $meta_key  = $parts[1];

        switch ( $type ) {
            case 'term_meta':
                if ( ! $can_view_all && ! \current_user_can( 'manage_categories' ) ) {
                    return null;
                }
                return function_exists( '\get_term_meta' )
                    ? array( 'value' => \get_term_meta( $parent_id, $meta_key, true ) )
                    : null;

            case 'user_meta':
                
                
                if ( \Easy_MCP_AI\History\Change_Recorder::user_meta_is_blocked( $meta_key ) ) {
                    return null;
                }
                $can_read = $can_view_all
                    || ( (int) \get_current_user_id() === $parent_id )
                    || \current_user_can( 'list_users' );
                if ( ! $can_read ) {
                    return null;
                }
                return function_exists( '\get_user_meta' )
                    ? array( 'value' => \get_user_meta( $parent_id, $meta_key, true ) )
                    : null;

            case 'comment_meta':
                if ( ! $can_view_all && ! \current_user_can( 'moderate_comments' ) ) {
                    return null;
                }
                return function_exists( '\get_comment_meta' )
                    ? array( 'value' => \get_comment_meta( $parent_id, $meta_key, true ) )
                    : null;
        }

        return null;
    }
}
