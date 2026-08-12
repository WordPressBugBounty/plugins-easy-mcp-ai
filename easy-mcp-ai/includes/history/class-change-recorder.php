<?php
namespace Easy_MCP_AI\History;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Change_Recorder {

    




















    const EXPLANATION_FIELDS = array( 'content_capture' );

    
    private $repo;

    
    private $post_before = array();

    
    private $meta_before = array();

    
    private $typed_meta_before = array();

    
    private $meta_delete_before = array();

    
    private $option_delete_before = array();

    
    private $product_delete_before = array();

    
    private $order_delete_before = array();

    
    private $bp_activity_before = array();

    
    private $term_before = array();

    
    private $comment_before = array();

    
    private $wc_product_before = array();

    
    private $wc_order_before = array();

    







    private $pending_revision_links = array();

    









    private $post_content_hash_before = array();

    




    private static $option_allowlist = array(
        'blogname', 'blogdescription', 'admin_email', 'siteurl', 'home',
        'default_category', 'default_comment_status', 'default_ping_status',
        'posts_per_page', 'date_format', 'time_format', 'start_of_week',
        'timezone_string', 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts',
        'easy_mcp_ai_force_draft_on_create', 'easy_mcp_ai_disabled_tools', 'easy_mcp_ai_allowed_tool_patterns',
    );

    







    private static $option_churn_denylist = array(
        '_transient_', '_site_transient_', 'cron', '_wp_session_',
        'action_scheduler_', 'easy_mcp_ai_change_log_', 'easy_mcp_ai_audit_',
    );

    








    private static $user_meta_blocked_exact  = array( 'session_tokens' );
    private static $user_meta_blocked_prefix = array( '_transient_', '_site_transient_' );

    










    public static function user_meta_is_blocked( $key ) {
        $key = (string) $key;
        if ( in_array( $key, self::$user_meta_blocked_exact, true ) ) {
            return true;
        }
        foreach ( self::$user_meta_blocked_prefix as $prefix ) {
            if ( 0 === strpos( $key, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    




    public static function option_allowlist() {
        return (array) \apply_filters( 'easy_mcp_ai_change_log_option_allowlist', self::$option_allowlist );
    }

    








    private function option_should_record( $name ) {
        return self::option_is_recordable( $name );
    }

    









    public static function option_is_recordable( $name ) {
        if ( in_array( $name, self::option_allowlist(), true ) ) {
            return true;
        }
        if ( 'allowlist' === \get_option( 'easy_mcp_ai_change_log_option_mode', 'all_except_denylist' ) ) {
            return false;
        }
        $deny = (array) \apply_filters( 'easy_mcp_ai_change_log_option_denylist', self::$option_churn_denylist );
        foreach ( $deny as $entry ) {
            $entry = (string) $entry;
            if ( '' === $entry ) {
                continue;
            }
            if ( '_' === substr( $entry, -1 ) ) {
                if ( 0 === strpos( $name, $entry ) ) {
                    return false;
                }
            } elseif ( $name === $entry ) {
                return false;
            }
        }
        return true;
    }

    








    private function option_name_seen_as( $name ) {
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        if ( function_exists( '\is_multisite' ) && \is_multisite() ) {
            return null;
        }
        $seen = Change_Context::get( '_option_rows_this_call', array() );
        return isset( $seen[ $name ] ) ? $seen[ $name ] : null;
    }

    private function mark_option_name_seen( $name, $kind ) {
        $seen = Change_Context::get( '_option_rows_this_call', array() );
        if ( ! isset( $seen[ $name ] ) ) {
            $seen[ $name ] = $kind;
            Change_Context::set( array( '_option_rows_this_call' => $seen ) );
        }
    }

    public function __construct( Change_Log_Repository $repo ) {
        $this->repo = $repo;
    }

    public function register() {
        
        $this->track( 'pre_post_update', 'on_pre_post_update', 10, 2 );
        $this->track( 'save_post', 'on_save_post', 10, 3 );
        
        
        
        $this->track( 'wp_after_insert_post', 'on_wp_after_insert_post', 10, 4 );
        $this->track( 'before_delete_post', 'on_before_delete_post', 10, 1 );
        $this->track( 'deleted_post', 'on_deleted_post', 10, 1 );

        
        $this->track( 'update_post_meta', 'on_update_post_meta', 10, 4 );
        $this->track( 'added_post_meta', 'on_added_post_meta', 10, 4 );
        $this->track( 'updated_post_meta', 'on_updated_post_meta', 10, 4 );
        
        
        $this->track( 'delete_post_meta', 'on_delete_post_meta', 10, 4 );
        $this->track( 'deleted_post_meta', 'on_deleted_post_meta', 10, 4 );

        
        
        
        
        
        $capture_meta = (bool) \get_option( 'easy_mcp_ai_change_log_capture_meta', true );
        if ( $capture_meta ) {
            $this->track( 'update_term_meta', 'on_update_term_meta', 10, 4 );
            $this->track( 'added_term_meta', 'on_added_term_meta', 10, 4 );
            $this->track( 'updated_term_meta', 'on_updated_term_meta', 10, 4 );
            $this->track( 'delete_term_meta', 'on_delete_term_meta', 10, 4 );
            $this->track( 'deleted_term_meta', 'on_deleted_term_meta', 10, 4 );
            $this->track( 'update_user_meta', 'on_update_user_meta', 10, 4 );
            $this->track( 'added_user_meta', 'on_added_user_meta', 10, 4 );
            $this->track( 'updated_user_meta', 'on_updated_user_meta', 10, 4 );
            $this->track( 'delete_user_meta', 'on_delete_user_meta', 10, 4 );
            $this->track( 'deleted_user_meta', 'on_deleted_user_meta', 10, 4 );
            $this->track( 'update_comment_meta', 'on_update_comment_meta', 10, 4 );
            $this->track( 'added_comment_meta', 'on_added_comment_meta', 10, 4 );
            $this->track( 'updated_comment_meta', 'on_updated_comment_meta', 10, 4 );
            $this->track( 'delete_comment_meta', 'on_delete_comment_meta', 10, 4 );
            $this->track( 'deleted_comment_meta', 'on_deleted_comment_meta', 10, 4 );
        }

        
        $this->track( 'pre_insert_term', 'on_pre_insert_term', 10, 2 );
        $this->track( 'created_term', 'on_created_term', 10, 3 );
        $this->track( 'edit_terms', 'on_edit_terms', 10, 2 );
        $this->track( 'edited_term', 'on_edited_term', 10, 3 );
        $this->track( 'pre_delete_term', 'on_pre_delete_term', 10, 2 );
        $this->track( 'delete_term', 'on_delete_term', 10, 4 );

        
        $this->track( 'user_register', 'on_user_register', 10, 1 );
        $this->track( 'profile_update', 'on_profile_update', 10, 2 );
        
        
        
        
        
        
        
        
        
        
        
        
        $this->track( 'add_user_role', 'on_add_user_role', 10, 2 );
        
        
        
        
        
        
        
        $this->track( 'remove_user_role', 'on_remove_user_role', 10, 2 );
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        $this->track( 'update_user_meta', 'on_capabilities_pre_write', 1, 4 );
        $this->track( 'add_user_meta', 'on_capabilities_pre_add', 1, 3 );
        
        
        
        $this->track( 'deleted_user', 'on_deleted_user', 10, 3 );

        
        $this->track( 'updated_option', 'on_updated_option', 10, 3 );
        $this->track( 'added_option', 'on_added_option', 10, 2 );
        
        
        $this->track( 'delete_option', 'on_delete_option', 10, 1 );
        $this->track( 'deleted_option', 'on_deleted_option', 10, 1 );

        
        
        
        
        if ( $capture_meta ) {
            $this->track( 'add_site_option', 'on_add_site_option', 10, 3 );
            $this->track( 'update_site_option', 'on_update_site_option', 10, 4 );
            $this->track( 'delete_site_option', 'on_delete_site_option', 10, 2 );
        }

        
        $this->track( 'wp_insert_comment', 'on_wp_insert_comment', 10, 2 );
        $this->track( 'wp_update_comment_data', 'on_update_comment_data', 1, 3, true );
        $this->track( 'edit_comment', 'on_edit_comment', 10, 2 );
        
        
        $this->track( 'deleted_comment', 'on_deleted_comment', 10, 2 );

        
        
        
        
        
        
        
        $this->track( 'trashed_comment', 'on_trashed_comment', 10, 2 );

        
        if ( class_exists( 'WooCommerce' ) ) {
            $this->track( 'woocommerce_new_product', 'on_woocommerce_new_product', 10, 1 );
            $this->track( 'woocommerce_before_product_object_save', 'on_woocommerce_before_product_save', 10, 1 );
            $this->track( 'woocommerce_update_product', 'on_woocommerce_update_product', 10, 2 );
            
            
            
            $this->track( 'woocommerce_before_delete_product', 'on_woocommerce_before_delete_product', 10, 1 );
            $this->track( 'woocommerce_delete_product', 'on_woocommerce_delete_product', 10, 1 );

            
            
            
            
            
            
            
            $this->track( 'woocommerce_before_delete_product_variation', 'on_woocommerce_before_delete_product', 10, 1 );
            $this->track( 'woocommerce_delete_product_variation', 'on_woocommerce_delete_product', 10, 1 );
            $this->track( 'woocommerce_new_order', 'on_woocommerce_new_order', 10, 2 );
            $this->track( 'woocommerce_before_order_object_save', 'on_woocommerce_before_order_save', 10, 1 );
            $this->track( 'woocommerce_update_order', 'on_woocommerce_update_order', 10, 2 );
            $this->track( 'woocommerce_order_status_changed', 'on_woocommerce_order_status_changed', 10, 4 );

            
            
            
            
            
            
            
            
            
            
            $this->track( 'woocommerce_before_delete_order', 'on_woocommerce_before_delete_order', 10, 2 );
            $this->track( 'woocommerce_delete_order', 'on_woocommerce_delete_order', 10, 1 );
            $this->track( 'woocommerce_before_trash_order', 'on_woocommerce_before_trash_order', 10, 2 );
            $this->track( 'woocommerce_trash_order', 'on_woocommerce_trash_order', 10, 1 );
        }

        
        if ( function_exists( 'buddypress' ) ) {
            
            
            
            
            
            $this->track( 'bp_activity_before_save', 'on_bp_activity_before_save', 10, 1 );
            $this->track( 'bp_activity_add', 'on_bp_activity_add', 10, 2 );
            $this->track( 'bp_activity_deleted_activities', 'on_bp_activity_deleted', 10, 1 );
        }
    }

    



    private $tracked_hooks = array();

    
    private function track( $hook, $method, $prio = 10, $args = 1, $is_filter = false ) {
        $this->tracked_hooks[] = array( 'hook' => $hook, 'method' => $method, 'prio' => $prio, 'args' => $args, 'is_filter' => $is_filter );
        if ( $is_filter ) {
            \add_filter( $hook, array( $this, $method ), $prio, $args );
        } else {
            \add_action( $hook, array( $this, $method ), $prio, $args );
        }
    }

    









    public function reassert_hooks() {
        $readded = array();
        foreach ( $this->tracked_hooks as $t ) {
            $cb      = array( $this, $t['method'] );
            $present = $t['is_filter'] ? \has_filter( $t['hook'], $cb ) : \has_action( $t['hook'], $cb );
            if ( false === $present ) {
                if ( $t['is_filter'] ) {
                    \add_filter( $t['hook'], $cb, $t['prio'], $t['args'] );
                } else {
                    \add_action( $t['hook'], $cb, $t['prio'], $t['args'] );
                }
                $readded[] = $t['hook'];
            }
        }
        if ( $readded && Change_Context::is_active() ) {
            $this->write_row( array(
                'action'         => 'update',
                'object_type'    => 'system',
                'object_id'      => '_hooks_reasserted',
                'object_subtype' => 'hook_self_defense',
                'after_value'    => array(
                    'reasserted' => array_values( $readded ),
                    'note'       => 'a third-party ability removed change-history hooks mid-request; re-armed after execute — writes between removal and this point may be missing from Layers 1/2',
                ),
                'truncated'      => 1,
            ) );
        }
        return $readded;
    }

    

    public function on_pre_post_update( $post_id, $data = array() ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $existing = \get_post( $post_id );
        if ( $existing ) {
            $this->post_before[ $post_id ]              = $this->snapshot_post( $existing );
            $this->post_content_hash_before[ $post_id ] = md5( (string) $existing->post_content );
        }
    }

    public function on_save_post( $post_id, $post, $update ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        if ( (int) $post_id <= 0 ) {
            return;
        }
        if ( isset( $post->post_type ) && 'revision' === $post->post_type ) {
            return;
        }
        
        
        
        
        
        
        
        if ( isset( $post->post_status ) && 'trash' === $post->post_status
            && $this->order_delete_in_progress( $post_id )
        ) {
            unset( $this->post_before[ $post_id ], $this->post_content_hash_before[ $post_id ] );
            return;
        }
        $after  = $this->snapshot_post( $post );
        $before = isset( $this->post_before[ $post_id ] ) ? $this->post_before[ $post_id ] : null;
        $action = ( $update && $before ) ? 'update' : 'create';
        $changed = $before ? $this->diff_keys( $before, $after ) : array_keys( $after );

        
        
        
        
        if ( $update && isset( $this->post_content_hash_before[ $post_id ] ) ) {
            $after_hash = md5( (string) ( $post->post_content ?? '' ) );
            if ( $after_hash !== $this->post_content_hash_before[ $post_id ] && ! in_array( 'post_content', $changed, true ) ) {
                $changed[] = 'post_content';
            }
            unset( $this->post_content_hash_before[ $post_id ] );
        }

        if ( 'update' === $action && empty( $changed ) ) {
            unset( $this->post_before[ $post_id ] );
            return;
        }

        
















        if ( is_array( $changed ) && in_array( 'post_content', $changed, true ) && ! $this->revisions_available( $post ) ) {
            $after = is_array( $after ) ? $after : array();
            $after['content_capture'] = __( 'unavailable — this content type does not keep revisions, or revisions are switched off, so the previous body text was not stored anywhere', 'easy-mcp-ai' );
        }
        
        
        

        $row_id = $this->write_row( array(
            'action'         => $action,
            'object_type'    => 'post',
            'object_id'      => (string) $post_id,
            'object_subtype' => isset( $post->post_type ) ? $post->post_type : null,
            'before_value'   => $before,
            'after_value'    => $after,
            'changed_fields' => $changed,
            
            
            
            
            'revision_id'    => $this->latest_revision_id( $post_id ),
        ) );

        if ( $row_id ) {
            $this->pending_revision_links[ $post_id ] = (int) $row_id;
        }

        unset( $this->post_before[ $post_id ] );
    }

    













    private function revisions_available( $post ) {
        $post_type = is_object( $post ) && isset( $post->post_type ) ? $post->post_type : '';
        if ( '' !== $post_type && \function_exists( '\post_type_supports' ) && ! \post_type_supports( $post_type, 'revisions' ) ) {
            return false;
        }
        if ( \function_exists( '\wp_revisions_enabled' ) && is_object( $post ) && ! \wp_revisions_enabled( $post ) ) {
            return false;
        }
        return true;
    }

    






    public function on_wp_after_insert_post( $post_id, $post, $update, $post_before ) {
        if ( ! isset( $this->pending_revision_links[ $post_id ] ) ) {
            return;
        }
        $row_id = $this->pending_revision_links[ $post_id ];
        unset( $this->pending_revision_links[ $post_id ] );
        $rev_id = $this->latest_revision_id( $post_id );
        if ( $rev_id ) {
            $this->repo->update_revision_id( $row_id, $rev_id );
        }
    }

    public function on_before_delete_post( $post_id ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $existing = \get_post( $post_id );
        if ( $existing ) {
            $this->post_before[ $post_id ] = $this->snapshot_post( $existing );
        }
    }

    public function on_deleted_post( $post_id ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        if ( $this->order_delete_in_progress( $post_id ) ) {
            unset( $this->post_before[ $post_id ] );
            return;
        }
        $before = isset( $this->post_before[ $post_id ] ) ? $this->post_before[ $post_id ] : null;
        $this->write_row( array(
            'action'         => 'delete',
            'object_type'    => 'post',
            'object_id'      => (string) $post_id,
            'object_subtype' => $before ? ( $before['post_type'] ?? null ) : null,
            'before_value'   => $before,
            'after_value'    => null,
            'changed_fields' => $before ? array_keys( $before ) : null,
        ) );
        unset( $this->post_before[ $post_id ] );
    }

    

    public function on_update_post_meta( $meta_id, $post_id, $key, $value ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->meta_before[ $post_id . ':' . $key ] = \get_post_meta( $post_id, $key, true );
    }

    public function on_updated_post_meta( $meta_id, $post_id, $key, $value ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $cache_key = $post_id . ':' . $key;
        $before    = $this->meta_before[ $cache_key ] ?? null;
        unset( $this->meta_before[ $cache_key ] );
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'meta',
            'object_id'      => $post_id . ':' . $key,
            'object_subtype' => 'post',
            'before_value'   => array( 'value' => $before ),
            'after_value'    => array( 'value' => $value ),
            'changed_fields' => array( 'value' ),
        ) );
    }

    public function on_added_post_meta( $meta_id, $post_id, $key, $value ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'create',
            'object_type'    => 'meta',
            'object_id'      => $post_id . ':' . $key,
            'object_subtype' => 'post',
            'after_value'    => array( 'value' => $value ),
            'changed_fields' => array( 'value' ),
        ) );
    }

    public function on_delete_post_meta( $meta_ids, $post_id, $key, $value ) {
        $this->meta_delete_capture( 'post', $post_id, $key, $value );
    }

    public function on_deleted_post_meta( $meta_ids, $post_id, $key, $value ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'delete',
            'object_type'    => 'meta',
            'object_id'      => $post_id . ':' . $key,
            'object_subtype' => 'post',
            'before_value'   => $this->meta_delete_resolve( 'post', $post_id, $key, $value ),
            'changed_fields' => array( 'value' ),
        ) );
    }

    













    private function meta_delete_capture( $kind, $object_id, $key, $hook_value ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        if ( 'post' !== $kind && $this->typed_meta_blocked( $kind, $key ) ) {
            return;
        }
        
        
        
        if ( '' !== $hook_value && null !== $hook_value && false !== $hook_value ) {
            $this->meta_delete_before[ $kind . ':' . $object_id . ':' . $key ] = array( 'value' => $hook_value );
            return;
        }

        $fn = '\\get_' . $kind . '_meta';
        if ( ! \function_exists( $fn ) ) {
            return;
        }
        $all = $fn( $object_id, $key, false );
        if ( ! is_array( $all ) || ! $all ) {
            return;
        }
        $all = array_values( $all );

        
        
        
        $this->meta_delete_before[ $kind . ':' . $object_id . ':' . $key ] = ( 1 === count( $all ) )
            ? array( 'value' => $all[0] )
            : array( 'value' => $all, 'multiple' => true );
    }

    






    private function meta_delete_resolve( $kind, $object_id, $key, $hook_value ) {
        $cache_key = $kind . ':' . $object_id . ':' . $key;
        if ( isset( $this->meta_delete_before[ $cache_key ] ) ) {
            $before = $this->meta_delete_before[ $cache_key ];
            unset( $this->meta_delete_before[ $cache_key ] );
            return $before;
        }
        
        
        if ( '' !== $hook_value && null !== $hook_value && false !== $hook_value ) {
            return array( 'value' => $hook_value );
        }
        return array(
            'value'             => null,
            'value_unavailable' => 'not captured before deletion',
        );
    }

    

    public function on_update_term_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_before_capture( 'term', $object_id, $key );
    }
    public function on_added_term_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_added( 'term', $object_id, $key, $value );
    }
    public function on_updated_term_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_updated( 'term', $object_id, $key, $value );
    }
    public function on_delete_term_meta( $meta_ids, $object_id, $key, $value ) {
        $this->meta_delete_capture( 'term', $object_id, $key, $value );
    }

    public function on_deleted_term_meta( $meta_ids, $object_id, $key, $value ) {
        $this->typed_meta_deleted( 'term', $object_id, $key, $value );
    }

    public function on_update_user_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_before_capture( 'user', $object_id, $key );
    }
    public function on_added_user_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_added( 'user', $object_id, $key, $value );
    }
    public function on_updated_user_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_updated( 'user', $object_id, $key, $value );
    }
    public function on_delete_user_meta( $meta_ids, $object_id, $key, $value ) {
        $this->meta_delete_capture( 'user', $object_id, $key, $value );
    }

    public function on_deleted_user_meta( $meta_ids, $object_id, $key, $value ) {
        $this->typed_meta_deleted( 'user', $object_id, $key, $value );
    }

    public function on_update_comment_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_before_capture( 'comment', $object_id, $key );
    }
    public function on_added_comment_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_added( 'comment', $object_id, $key, $value );
    }
    public function on_updated_comment_meta( $meta_id, $object_id, $key, $value ) {
        $this->typed_meta_updated( 'comment', $object_id, $key, $value );
    }
    public function on_delete_comment_meta( $meta_ids, $object_id, $key, $value ) {
        $this->meta_delete_capture( 'comment', $object_id, $key, $value );
    }

    public function on_deleted_comment_meta( $meta_ids, $object_id, $key, $value ) {
        $this->typed_meta_deleted( 'comment', $object_id, $key, $value );
    }

    
    private function typed_meta_blocked( $kind, $key ) {
        if ( 'user' !== $kind ) {
            return false;
        }
        if ( in_array( $key, self::$user_meta_blocked_exact, true ) ) {
            return true;
        }
        foreach ( self::$user_meta_blocked_prefix as $prefix ) {
            if ( 0 === strpos( $key, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    private function typed_meta_get_current( $kind, $object_id, $key ) {
        $fn = '\\get_' . $kind . '_meta';
        if ( ! \function_exists( $fn ) ) {
            return null;
        }
        return $fn( $object_id, $key, true );
    }

    
    private function typed_meta_before_capture( $kind, $object_id, $key ) {
        if ( ! Change_Context::is_active() || $this->typed_meta_blocked( $kind, $key ) ) {
            return;
        }
        $this->typed_meta_before[ $kind . ':' . $object_id . ':' . $key ] = $this->typed_meta_get_current( $kind, $object_id, $key );
    }

    private function typed_meta_added( $kind, $object_id, $key, $value ) {
        if ( ! Change_Context::is_active() || $this->typed_meta_blocked( $kind, $key ) ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'create',
            'object_type'    => $kind . '_meta',
            'object_id'      => $object_id . ':' . $key,
            'object_subtype' => $kind,
            'after_value'    => array( 'value' => $value ),
            'changed_fields' => array( 'value' ),
        ) );
    }

    private function typed_meta_updated( $kind, $object_id, $key, $value ) {
        if ( ! Change_Context::is_active() || $this->typed_meta_blocked( $kind, $key ) ) {
            return;
        }
        $cache_key = $kind . ':' . $object_id . ':' . $key;
        $before    = $this->typed_meta_before[ $cache_key ] ?? null;
        unset( $this->typed_meta_before[ $cache_key ] );
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => $kind . '_meta',
            'object_id'      => $object_id . ':' . $key,
            'object_subtype' => $kind,
            'before_value'   => array( 'value' => $before ),
            'after_value'    => array( 'value' => $value ),
            'changed_fields' => array( 'value' ),
        ) );
    }

    private function typed_meta_deleted( $kind, $object_id, $key, $value ) {
        if ( ! Change_Context::is_active() || $this->typed_meta_blocked( $kind, $key ) ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'delete',
            'object_type'    => $kind . '_meta',
            'object_id'      => $object_id . ':' . $key,
            'object_subtype' => $kind,
            'before_value'   => $this->meta_delete_resolve( $kind, $object_id, $key, $value ),
        ) );
    }

    

    





    public function on_pre_insert_term( $term, $taxonomy ) {
        
        
        
        
        
        return $term;
    }

    public function on_created_term( $term_id, $tt_id, $taxonomy ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $term = function_exists( '\get_term' ) ? \get_term( $term_id, $taxonomy, ARRAY_A ) : null;
        $this->write_row( array(
            'action'         => 'create',
            'object_type'    => 'term',
            'object_id'      => (string) $term_id,
            'object_subtype' => $taxonomy,
            'after_value'    => is_array( $term ) ? $term : null,
        ) );
    }

    public function on_edit_terms( $term_id, $taxonomy ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        if ( function_exists( '\get_term' ) ) {
            $this->term_before[ $term_id ] = \get_term( $term_id, $taxonomy, ARRAY_A );
        }
    }

    public function on_edited_term( $term_id, $tt_id, $taxonomy ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $before = $this->term_before[ $term_id ] ?? null;
        $after  = function_exists( '\get_term' ) ? \get_term( $term_id, $taxonomy, ARRAY_A ) : null;
        unset( $this->term_before[ $term_id ] );
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'term',
            'object_id'      => (string) $term_id,
            'object_subtype' => $taxonomy,
            'before_value'   => is_array( $before ) ? $before : null,
            'after_value'    => is_array( $after )  ? $after  : null,
            'changed_fields' => is_array( $before ) && is_array( $after ) ? $this->diff_keys( $before, $after ) : null,
        ) );
    }

    public function on_pre_delete_term( $term_id, $taxonomy ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        if ( function_exists( '\get_term' ) ) {
            $this->term_before[ $term_id ] = \get_term( $term_id, $taxonomy, ARRAY_A );
        }
    }

    public function on_delete_term( $term_id, $tt_id, $taxonomy, $deleted_term ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $before = $this->term_before[ $term_id ] ?? ( is_object( $deleted_term ) ? get_object_vars( $deleted_term ) : null );
        unset( $this->term_before[ $term_id ] );
        $this->write_row( array(
            'action'         => 'delete',
            'object_type'    => 'term',
            'object_id'      => (string) $term_id,
            'object_subtype' => $taxonomy,
            'before_value'   => $before,
        ) );
    }

    

    public function on_user_register( $user_id ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'      => 'create',
            'object_type' => 'user',
            'object_id'   => (string) $user_id,
            'after_value' => $this->snapshot_user( $user_id ),
        ) );
    }

    









    public function on_add_user_role( $user_id, $role = '' ) {
        $this->write_role_change_row( $user_id, (string) $role, 'add' );
    }

    







    public function on_remove_user_role( $user_id, $role = '' ) {
        $this->write_role_change_row( $user_id, (string) $role, 'remove' );
    }

    











    public function on_capabilities_pre_write( $meta_id, $user_id, $meta_key, $value = null ) {
        $this->note_prior_roles( (string) $meta_key, (int) $user_id );
    }

    



    public function on_capabilities_pre_add( $user_id, $meta_key, $value = null ) {
        $this->note_prior_roles( (string) $meta_key, (int) $user_id );
    }

    


    private function note_prior_roles( $meta_key, $user_id ) {
        if ( ! Change_Context::is_active() || ! $this->is_capabilities_key( $meta_key ) ) {
            return;
        }
        Change_Context::note_prior_roles( $user_id, $this->read_roles_from_meta( $user_id, $meta_key ) );
    }

    





    private function is_capabilities_key( $meta_key ) {
        global $wpdb;
        if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ) {
            return $meta_key === $wpdb->get_blog_prefix() . 'capabilities';
        }
        return (bool) preg_match( '/^[A-Za-z0-9_]*?_(?:\d+_)?capabilities$/', $meta_key );
    }

    








    private function read_roles_from_meta( $user_id, $meta_key ) {
        if ( ! function_exists( '\get_user_meta' ) ) {
            return array();
        }
        $caps = \get_user_meta( $user_id, $meta_key, true );
        if ( ! is_array( $caps ) ) {
            return array();
        }
        $roles = array();
        foreach ( $caps as $cap => $granted ) {
            if ( ! $granted ) {
                continue;
            }
            
            
            
            if ( ! function_exists( '\wp_roles' ) ) {
                return array();
            }
            $wp_roles = \wp_roles();
            if ( is_object( $wp_roles ) && method_exists( $wp_roles, 'is_role' ) && $wp_roles->is_role( $cap ) ) {
                $roles[] = (string) $cap;
            }
        }
        return $roles;
    }

    public function on_profile_update( $user_id, $old_user_data ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        
        
        
        
        
        
        
        $before = is_object( $old_user_data ) ? array(
            'ID'           => $old_user_data->ID           ?? null,
            'user_login'   => $old_user_data->user_login   ?? null,
            'user_email'   => $old_user_data->user_email   ?? null,
            'display_name' => $old_user_data->display_name ?? null,
            'roles'        => isset( $old_user_data->roles ) ? (array) $old_user_data->roles : array(),
        ) : null;
        $after = $this->snapshot_user( $user_id );
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'user',
            'object_id'      => (string) $user_id,
            'before_value'   => $before,
            'after_value'    => $after,
            'changed_fields' => is_array( $before ) && is_array( $after ) ? $this->diff_keys( $before, $after ) : null,
        ) );
    }

    public function on_deleted_user( $user_id, $reassign = null, $user = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'       => 'delete',
            'object_type'  => 'user',
            'object_id'    => (string) $user_id,
            
            
            
            'before_value' => $this->delete_before( $this->snapshot_user_object( $user ) ),
        ) );
    }

    





    private function snapshot_user_object( $user ) {
        if ( ! is_object( $user ) ) {
            return null;
        }
        return array(
            'ID'           => $user->ID           ?? null,
            'user_login'   => $user->user_login   ?? null,
            'user_email'   => $user->user_email   ?? null,
            'display_name' => $user->display_name ?? null,
            'roles'        => isset( $user->roles ) ? $user->roles : array(),
        );
    }

    






    private function delete_before( $snapshot ) {
        if ( is_array( $snapshot ) && $snapshot ) {
            return $snapshot;
        }
        return array( 'value_unavailable' => 'not captured before deletion' );
    }

    



















    private function write_role_change_row( $user_id, $role, $operation ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $after = $this->snapshot_user( $user_id );
        if ( null === $after ) {
            return;
        }

        $after_roles = isset( $after['roles'] ) ? array_values( (array) $after['roles'] ) : array();

        
        
        
        
        
        
        
        
        
        
        $observed = Change_Context::prior_roles( $user_id );
        if ( null !== $observed ) {
            $before_roles = $observed;
        } else {
            $before_roles = $after_roles;
            if ( '' !== $role ) {
                if ( 'add' === $operation ) {
                    $before_roles = array_values( array_diff( $after_roles, array( $role ) ) );
                } else {
                    if ( ! in_array( $role, $before_roles, true ) ) {
                        $before_roles[] = $role;
                    }
                    $before_roles = array_values( $before_roles );
                }
            }
        }

        $before          = $after;
        $before['roles'] = $before_roles;

        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'user',
            'object_id'      => (string) $user_id,
            'object_subtype' => '' !== $role ? $role : null,
            'before_value'   => $before,
            'after_value'    => $after,
            'changed_fields' => array( 'roles' ),
        ) );
    }

    private function snapshot_user( $user_id ) {
        if ( ! function_exists( '\get_userdata' ) ) {
            return null;
        }
        $u = \get_userdata( $user_id );
        if ( ! $u ) {
            return null;
        }
        return array(
            'ID'           => $u->ID           ?? null,
            'user_login'   => $u->user_login   ?? null,
            'user_email'   => $u->user_email   ?? null,
            'display_name' => $u->display_name ?? null,
            'roles'        => isset( $u->roles ) ? $u->roles : array(),
        );
    }

    

    public function on_added_option( $name, $value ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $name = (string) $name;
        if ( ! $this->option_should_record( $name ) || 'site_option' === $this->option_name_seen_as( $name ) ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'create',
            'object_type'    => 'option',
            'object_id'      => $name,
            'object_subtype' => $name,
            'after_value'    => array( 'name' => $name, 'value' => $value ),
        ) );
        $this->mark_option_name_seen( $name, 'option' );
    }

    public function on_updated_option( $name, $old, $new ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $name = (string) $name;
        if ( ! $this->option_should_record( $name ) || 'site_option' === $this->option_name_seen_as( $name ) ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'option',
            'object_id'      => $name,
            'object_subtype' => $name,
            'before_value'   => array( 'name' => $name, 'value' => $old ),
            'after_value'    => array( 'name' => $name, 'value' => $new ),
            'changed_fields' => array( 'value' ),
        ) );
        $this->mark_option_name_seen( $name, 'option' );
    }

    
    public function on_delete_option( $name ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $name = (string) $name;
        if ( ! $this->option_should_record( $name ) ) {
            return;
        }
        if ( ! \function_exists( '\get_option' ) ) {
            return;
        }
        $this->option_delete_before[ $name ] = array( 'name' => $name, 'value' => \get_option( $name ) );
    }

    public function on_deleted_option( $name ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $name = (string) $name;
        if ( ! $this->option_should_record( $name ) || 'site_option' === $this->option_name_seen_as( $name ) ) {
            unset( $this->option_delete_before[ $name ] );
            return;
        }
        $before = $this->option_delete_before[ $name ] ?? null;
        unset( $this->option_delete_before[ $name ] );
        $this->write_row( array(
            'action'         => 'delete',
            'object_type'    => 'option',
            'object_id'      => $name,
            'object_subtype' => $name,
            'before_value'   => $this->delete_before( $before ),
        ) );
        $this->mark_option_name_seen( $name, 'option' );
    }

    
    
    
    
    
    
    
    
    

    public function on_add_site_option( $name, $value, $network_id = 0 ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $name = (string) $name;
        if ( ! $this->option_should_record( $name ) || 'option' === $this->option_name_seen_as( $name ) ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'create',
            'object_type'    => 'site_option',
            'object_id'      => $name,
            'object_subtype' => $name,
            'after_value'    => array( 'name' => $name, 'value' => $value ),
        ) );
        $this->mark_option_name_seen( $name, 'site_option' );
    }

    public function on_update_site_option( $name, $value, $old_value = null, $network_id = 0 ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $name = (string) $name;
        if ( ! $this->option_should_record( $name ) || 'option' === $this->option_name_seen_as( $name ) ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'site_option',
            'object_id'      => $name,
            'object_subtype' => $name,
            'before_value'   => array( 'name' => $name, 'value' => $old_value ),
            'after_value'    => array( 'name' => $name, 'value' => $value ),
            'changed_fields' => array( 'value' ),
        ) );
        $this->mark_option_name_seen( $name, 'site_option' );
    }

    public function on_delete_site_option( $name, $network_id = 0 ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $name = (string) $name;
        if ( ! $this->option_should_record( $name ) || 'option' === $this->option_name_seen_as( $name ) ) {
            return;
        }
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        $this->write_row( array(
            'action'         => 'delete',
            'object_type'    => 'site_option',
            'object_id'      => $name,
            'object_subtype' => $name,
            'before_value'   => $this->delete_before( null ),
        ) );
        $this->mark_option_name_seen( $name, 'site_option' );
    }

    

    private function snapshot_comment( $comment ) {
        if ( ! is_object( $comment ) ) {
            return null;
        }
        return array(
            'comment_ID'       => $comment->comment_ID       ?? null,
            'comment_post_ID'  => $comment->comment_post_ID  ?? null,
            'comment_author'   => $comment->comment_author   ?? null,
            'comment_content'  => $comment->comment_content  ?? null,
            'comment_approved' => $comment->comment_approved ?? null,
        );
    }

    public function on_wp_insert_comment( $comment_id, $comment ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'      => 'create',
            'object_type' => 'comment',
            'object_id'   => (string) $comment_id,
            'after_value' => $this->snapshot_comment( $comment ),
        ) );
    }

    















    public function on_update_comment_data( $data, $comment, $commentarr ) {
        if ( ! Change_Context::is_active() ) {
            return $data;
        }
        $comment_id = isset( $data['comment_ID'] ) ? (int) $data['comment_ID']
                    : ( isset( $comment['comment_ID'] ) ? (int) $comment['comment_ID'] : 0 );
        if ( $comment_id <= 0 ) {
            return $data;
        }
        
        
        $before_obj = is_array( $comment ) ? (object) $comment : null;
        if ( ! isset( $this->comment_before[ $comment_id ] ) ) {
            $this->comment_before[ $comment_id ] = array(
                'snapshot' => $this->snapshot_comment( $before_obj ),
                'context'  => Change_Context::all(),
            );
        }
        return $data;
    }

    public function on_edit_comment( $comment_id, $data = null ) {
        
        
        
        
        
        
        
        
        if ( ! isset( $this->comment_before[ $comment_id ] ) ) {
            return;
        }
        $entry   = $this->comment_before[ $comment_id ];
        $before  = is_array( $entry ) && isset( $entry['snapshot'] ) ? $entry['snapshot'] : $entry;
        $context = is_array( $entry ) && isset( $entry['context'] )  ? $entry['context']  : null;
        unset( $this->comment_before[ $comment_id ] );

        
        if ( function_exists( '\wp_cache_delete' ) ) {
            \wp_cache_delete( $comment_id, 'comment' );
        }
        $after = function_exists( '\get_comment' ) ? \get_comment( $comment_id ) : null;

        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'comment',
            'object_id'      => (string) $comment_id,
            'before_value'   => $before,
            'after_value'    => $this->snapshot_comment( $after ),
            'changed_fields' => is_array( $before ) && $after
                ? $this->diff_keys( $before, $this->snapshot_comment( $after ) )
                : null,
        ), $context );
    }

    




    public function flush_comment_update( $comment_id ) {
        
    }

    public function on_deleted_comment( $comment_id, $comment = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'       => 'delete',
            'object_type'  => 'comment',
            'object_id'    => (string) $comment_id,
            
            'before_value' => $this->delete_before( $this->snapshot_comment( $comment ) ),
        ) );
    }

    

    public function on_woocommerce_new_product( $product_id ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'      => 'create',
            'object_type' => 'wc_product',
            'object_id'   => (string) $product_id,
            'after_value' => $this->snapshot_wc_product( $product_id ),
        ) );
    }

    public function on_woocommerce_before_product_save( $product ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return;
        }
        $pid = (int) $product->get_id();
        if ( $pid <= 0 ) {
            return;
        }
        
        
        
        $this->wc_product_before[ $pid ] = $this->snapshot_wc_product( $pid );
    }

    public function on_woocommerce_update_product( $product_id, $product = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        
        
        
        
        
        
        $snapshot = $this->snapshot_wc_product( $product_id );
        $before   = $this->wc_product_before[ (int) $product_id ] ?? null;
        unset( $this->wc_product_before[ (int) $product_id ] );
        $changed = ( is_array( $before ) && is_array( $snapshot ) )
            ? $this->diff_keys( $before, $snapshot )
            : null;
        
        
        
        
        
        
        
        if ( is_array( $changed ) && ! $changed ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'wc_product',
            'object_id'      => (string) $product_id,
            'before_value'   => $before,
            'after_value'    => $snapshot,
            'changed_fields' => $changed,
        ) );
    }

    



    public function on_trashed_comment( $comment_id, $comment = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'delete',
            'object_type'    => 'comment',
            'object_id'      => (string) $comment_id,
            'object_subtype' => 'trash',
            'before_value'   => $this->delete_before( $this->snapshot_comment( $comment ) ),
        ) );
    }

    

    public function on_woocommerce_before_delete_order( $order_id, $order = null ) {
        $this->capture_order_pre_delete( $order_id, $order );
    }

    public function on_woocommerce_delete_order( $order_id ) {
        $this->write_order_delete_row( $order_id, null );
    }

    public function on_woocommerce_before_trash_order( $order_id, $order = null ) {
        $this->capture_order_pre_delete( $order_id, $order );
    }

    public function on_woocommerce_trash_order( $order_id ) {
        $this->write_order_delete_row( $order_id, 'trash' );
    }

    



















    private function order_delete_in_progress( $post_id ) {
        return array_key_exists( (int) $post_id, $this->order_delete_before );
    }

    
    private function capture_order_pre_delete( $order_id, $order = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->order_delete_before[ (int) $order_id ] = $this->snapshot_wc_order( $order_id, $order );
    }

    private function write_order_delete_row( $order_id, $subtype ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $key    = (int) $order_id;
        $before = $this->order_delete_before[ $key ] ?? null;
        unset( $this->order_delete_before[ $key ] );
        $this->write_row( array(
            'action'         => 'delete',
            'object_type'    => 'wc_order',
            'object_id'      => (string) $order_id,
            'object_subtype' => $subtype,
            'before_value'   => $this->delete_before( $before ),
        ) );
    }

    
    public function on_woocommerce_before_delete_product( $product_id ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->product_delete_before[ (int) $product_id ] = $this->snapshot_wc_product( $product_id );
    }

    public function on_woocommerce_delete_product( $product_id ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $key    = (int) $product_id;
        $before = $this->product_delete_before[ $key ] ?? null;
        unset( $this->product_delete_before[ $key ] );
        $this->write_row( array(
            'action'       => 'delete',
            'object_type'  => 'wc_product',
            'object_id'    => (string) $product_id,
            'before_value' => $this->delete_before( $before ),
        ) );
    }

    private function snapshot_wc_product( $product_id ) {
        if ( ! function_exists( '\wc_get_product' ) ) {
            return null;
        }
        $product = \wc_get_product( $product_id );
        if ( ! $product || ! method_exists( $product, 'get_data' ) ) {
            return null;
        }
        return $product->get_data();
    }

    public function on_woocommerce_new_order( $order_id, $order = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'      => 'create',
            'object_type' => 'wc_order',
            'object_id'   => (string) $order_id,
            'after_value' => $this->snapshot_wc_order( $order_id, $order ),
        ) );
    }

    public function on_woocommerce_before_order_save( $order ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
            return;
        }
        $oid = (int) $order->get_id();
        if ( $oid <= 0 ) {
            return;
        }
        $this->wc_order_before[ $oid ] = $this->snapshot_wc_order( $oid );
    }

    public function on_woocommerce_update_order( $order_id, $order = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $after   = $this->snapshot_wc_order( $order_id, $order );
        $before  = $this->wc_order_before[ (int) $order_id ] ?? null;
        unset( $this->wc_order_before[ (int) $order_id ] );
        $changed = ( is_array( $before ) && is_array( $after ) )
            ? $this->diff_keys( $before, $after )
            : null;
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'wc_order',
            'object_id'      => (string) $order_id,
            'before_value'   => $before,
            'after_value'    => $after,
            'changed_fields' => $changed,
        ) );
    }

    public function on_woocommerce_order_status_changed( $order_id, $from, $to, $order = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $this->write_row( array(
            'action'         => 'update',
            'object_type'    => 'wc_order',
            'object_id'      => (string) $order_id,
            'before_value'   => array( 'status' => $from ),
            'after_value'    => array( 'status' => $to ),
            'changed_fields' => array( 'status' ),
        ) );
    }

    private function snapshot_wc_order( $order_id, $order = null ) {
        
        if ( ! is_object( $order ) && function_exists( '\wc_get_order' ) ) {
            $order = \wc_get_order( $order_id );
        }
        if ( is_object( $order ) && method_exists( $order, 'get_data' ) ) {
            return self::drop_unsaved_line_items( $order->get_data() );
        }
        return null;
    }

    






























    private static function drop_unsaved_line_items( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }
        foreach ( array( 'line_items', 'tax_lines', 'shipping_lines', 'fee_lines', 'coupon_lines' ) as $group ) {
            if ( empty( $data[ $group ] ) || ! is_array( $data[ $group ] ) ) {
                continue;
            }
            $kept    = array();
            $dropped = 0;
            foreach ( $data[ $group ] as $key => $item ) {
                if ( self::is_unsaved_order_item( $key, $item ) ) {
                    ++$dropped;
                    continue;
                }
                $kept[ $key ] = $item;
            }
            if ( 0 === $dropped ) {
                continue;
            }
            $data[ $group ] = $kept;
            if ( ! $kept ) {
                $data[ $group ] = array(
                    'value_unavailable' => 'not captured — WooCommerce had not saved these yet when this row was written; the update row from the same call carries them',
                );
            }
        }
        return $data;
    }

    










    private static function is_unsaved_order_item( $key, $item ) {
        if ( is_string( $key ) && 0 === strpos( $key, 'new:' ) ) {
            return true;
        }
        if ( is_object( $item ) && method_exists( $item, 'get_id' ) ) {
            return 0 === (int) $item->get_id();
        }
        if ( is_array( $item ) && array_key_exists( 'id', $item ) ) {
            return 0 === (int) $item['id'];
        }
        return false;
    }

    

    public function on_bp_activity_add( $args, $activity_id = null ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $id = $activity_id ?: ( is_array( $args ) && isset( $args['id'] ) ? $args['id'] : null );
        if ( ! $id ) {
            return;
        }

        










        $supplied_id = is_array( $args ) && ! empty( $args['id'] ) ? (int) $args['id'] : 0;
        $is_update   = $supplied_id > 0;

        
        
        
        
        
        $before = $this->bp_activity_before[ $supplied_id ] ?? null;
        unset( $this->bp_activity_before[ $supplied_id ] );

        
        
        
        
        $after = is_array( $args ) ? $args : array();
        $after['id'] = (int) $id;

        $this->write_row( array(
            'action'       => $is_update ? 'update' : 'create',
            'object_type'  => 'bp_activity',
            'object_id'    => (string) $id,
            'before_value' => $is_update ? $this->delete_before( $before ) : null,
            'after_value'  => $after,
        ) );
    }

    





    public function on_bp_activity_before_save( $activity ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $id = is_object( $activity ) && ! empty( $activity->id ) ? (int) $activity->id : 0;
        if ( $id <= 0 || ! \function_exists( '\bp_activity_get_specific' ) ) {
            return;
        }
        $existing = \bp_activity_get_specific( array( 'activity_ids' => array( $id ) ) );
        if ( ! empty( $existing['activities'][0] ) ) {
            $this->bp_activity_before[ $id ] = $this->snapshot_bp_activity( $existing['activities'][0] );
        }
    }

    
    private function snapshot_bp_activity( $activity ) {
        if ( ! is_object( $activity ) ) {
            return null;
        }
        return array(
            'id'            => isset( $activity->id )            ? (int) $activity->id : null,
            'user_id'       => isset( $activity->user_id )       ? (int) $activity->user_id : null,
            'component'     => isset( $activity->component )     ? (string) $activity->component : null,
            'type'          => isset( $activity->type )          ? (string) $activity->type : null,
            'action'        => isset( $activity->action )        ? (string) $activity->action : null,
            'content'       => isset( $activity->content )       ? (string) $activity->content : null,
            'item_id'       => isset( $activity->item_id )       ? (int) $activity->item_id : null,
            'hide_sitewide' => isset( $activity->hide_sitewide ) ? (int) $activity->hide_sitewide : null,
        );
    }

    public function on_bp_activity_deleted( $activity_ids ) {
        if ( ! Change_Context::is_active() ) {
            return;
        }
        $ids   = array_values( array_filter( array_map( 'intval', (array) $activity_ids ) ) );
        $cap   = (int) apply_filters( 'easy_mcp_ai_bp_activity_delete_log_cap', 100 );
        $total = count( $ids );
        $write = $cap > 0 ? array_slice( $ids, 0, $cap ) : $ids;
        foreach ( $write as $id ) {
            $this->write_row( array(
                'action'      => 'delete',
                'object_type' => 'bp_activity',
                'object_id'   => (string) $id,
            ) );
        }
        if ( $cap > 0 && $total > $cap ) {
            
            
            $this->write_row( array(
                'action'         => 'delete',
                'object_type'    => 'bp_activity',
                'object_id'      => 'bulk',
                'after_value'    => array(
                    'total_deleted'  => $total,
                    'logged_count'   => count( $write ),
                    'omitted_count'  => $total - count( $write ),
                ),
                'changed_fields' => array( 'bulk_delete_summary' ),
            ) );
        }
    }

    

    private function snapshot_post( $post ) {
        $fields = array(
            'post_title', 'post_status', 'post_type', 'post_excerpt',
            'post_parent', 'menu_order', 'comment_status', 'ping_status',
            'post_password', 'post_name', 'post_author',
        );
        $out = array();
        foreach ( $fields as $f ) {
            $out[ $f ] = isset( $post->$f ) ? $post->$f : null;
        }
        return $out;
    }

    

























    public static function values_match( $a, $b ) {
        if ( ! is_object( $a ) && ! is_array( $a ) && ! is_object( $b ) && ! is_array( $b ) ) {
            return $a === $b;
        }
        if ( is_object( $a ) !== is_object( $b ) || is_array( $a ) !== is_array( $b ) ) {
            return false;
        }
        $ea = self::canonical_value( $a );
        $eb = self::canonical_value( $b );
        if ( null === $ea || null === $eb ) {
            return false; 
        }
        return $ea === $eb;
    }

    








    private static function canonical_value( $v ) {
        $encoded = function_exists( '\wp_json_encode' ) ? \wp_json_encode( self::unwrap_for_encoding( $v ) ) : \json_encode( self::unwrap_for_encoding( $v ) );
        return is_string( $encoded ) ? $encoded : null;
    }

    




















    private static function unwrap_for_encoding( $v ) {
        if ( is_array( $v ) ) {
            return array_map( array( __CLASS__, 'unwrap_for_encoding' ), $v );
        }
        if ( is_object( $v ) && ! $v instanceof \JsonSerializable && method_exists( $v, 'get_data' ) ) {
            $data = $v->get_data();
            
            return is_array( $data ) ? array_map( array( __CLASS__, 'unwrap_for_encoding' ), $data ) : $v;
        }
        return $v;
    }

    



































    
    const EXPAND_MAX_DEPTH = 12;

    public static function expand_for_storage( $v, $depth = 0 ) {
        
        
        
        
        if ( $depth > self::EXPAND_MAX_DEPTH ) {
            return $v;
        }
        if ( is_array( $v ) ) {
            $out = array();
            foreach ( $v as $k => $item ) {
                $out[ $k ] = self::expand_for_storage( $item, $depth + 1 );
            }
            return $out;
        }
        if ( ! is_object( $v ) ) {
            return $v;
        }

        


















        try {
            if ( $v instanceof \JsonSerializable ) {
                $data = $v->jsonSerialize();
                if ( is_object( $data ) ) {
                    $data = get_object_vars( $data );
                }
                return is_array( $data ) ? self::expand_for_storage( $data, $depth + 1 ) : $data;
            }
            if ( is_callable( array( $v, 'get_data' ) ) ) {
                $data = $v->get_data();
                
                
                return is_array( $data ) ? self::expand_for_storage( $data, $depth + 1 ) : $v;
            }
        } catch ( \Throwable $e ) {
            return $v;
        }
        return $v;
    }

    private function diff_keys( array $before, array $after ) {
        $changed = array();
        foreach ( $after as $k => $v ) {
            $b = isset( $before[ $k ] ) ? $before[ $k ] : null;
            if ( ! self::values_match( $b, $v ) ) {
                $changed[] = $k;
            }
        }
        foreach ( $before as $k => $_v ) {
            if ( ! array_key_exists( $k, $after ) ) {
                $changed[] = $k;
            }
        }
        return array_values( array_unique( $changed ) );
    }

    private function latest_revision_id( $post_id ) {
        if ( ! function_exists( '\wp_get_post_revisions' ) ) {
            return null;
        }
        $rev = \wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
        if ( empty( $rev ) ) {
            return null;
        }
        $first = reset( $rev );
        return isset( $first->ID ) ? (int) $first->ID : null;
    }

    








    private function write_row( array $partial, ?array $context_override = null ) {
        $ctx     = null !== $context_override ? $context_override : Change_Context::all();
        $payload = array_merge( array(
            'audit_id'        => $ctx['audit_id']        ?? null,
            'auth_source'     => $ctx['auth_source']     ?? 'legacy',
            'token_id'        => $ctx['token_id']        ?? 0,
            'oauth_client_id' => $ctx['oauth_client_id'] ?? null,
            'wp_user_id'      => $ctx['wp_user_id']      ?? 0,
            'tool_name'       => $ctx['tool_name']       ?? '',
            'ip_address'      => $ctx['ip_address']      ?? null,
            
            
            
            
            'capture_mode'    => 'hooked',
        ), $partial );

        
        
        
        
        
        
        
        
        
        
        
        
        
        $identifier_sensitive = false;
        $object_type          = $payload['object_type'] ?? '';
        $oid                  = (string) ( $payload['object_id'] ?? '' );
        if ( in_array( $object_type, array( 'meta', 'term_meta', 'user_meta', 'comment_meta' ), true ) ) {
            $parts = explode( ':', $oid, 2 );
            if ( isset( $parts[1] ) && Change_Redactor::key_is_sensitive( $parts[1] ) ) {
                $identifier_sensitive = true;
            }
        } elseif ( in_array( $object_type, array( 'option', 'site_option' ), true ) ) {
            $identifier_sensitive = Change_Redactor::option_name_is_sensitive( $oid );
        }

        foreach ( array( 'before_value', 'after_value' ) as $f ) {
            if ( is_array( $payload[ $f ] ?? null ) ) {
                
                
                
                $payload[ $f ] = self::expand_for_storage( $payload[ $f ] );
                if ( $identifier_sensitive ) {
                    
                    
                    $payload[ $f ] = array_key_exists( 'name', $payload[ $f ] )
                        ? array( 'name' => $payload[ $f ]['name'], 'value' => '[REDACTED]' )
                        : array( 'value' => '[REDACTED]' );
                    continue;
                }
                $redacted      = Change_Redactor::redact( $payload[ $f ] );
                $payload[ $f ] = $redacted['value'];
                if ( $redacted['truncated'] ) {
                    $payload['truncated'] = 1;
                }
            }
        }

        
        
        
        
        
        if ( ! empty( $payload['object_type'] ) && isset( $payload['object_id'] ) ) {
            $noted_fields = is_array( $partial['changed_fields'] ?? null ) ? $partial['changed_fields'] : array();
            Change_Context::note_recorded_fields( $payload['object_type'], (string) $payload['object_id'], $noted_fields );
        }

        
        Change_Context::increment_rows_written();

        return $this->repo->insert( $payload );
    }
}
