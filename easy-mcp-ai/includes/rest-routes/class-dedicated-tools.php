<?php

namespace Easy_MCP_AI\Rest_Routes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dedicated_Tools {

    const MAP = array(
        'GET /wp/v2/posts'                           => 'wp_list_posts',
        'GET /wp/v2/posts/{id}'                      => 'wp_get_post',
        'POST /wp/v2/posts'                          => 'wp_create_post',
        'WRITE /wp/v2/posts/{id}'                    => 'wp_update_post',
        'DELETE /wp/v2/posts/{id}'                   => 'wp_delete_post',
        'GET /wp/v2/posts/{parent}/revisions'        => 'wp_list_revisions',
        'GET /wp/v2/posts/{parent}/revisions/{id}'   => 'wp_get_revision',
        'DELETE /wp/v2/posts/{parent}/revisions/{id}' => 'wp_delete_revision',
        'GET /wp/v2/pages'                           => 'wp_list_pages',
        'GET /wp/v2/pages/{id}'                      => 'wp_get_page',
        'POST /wp/v2/pages'                          => 'wp_create_page',
        'WRITE /wp/v2/pages/{id}'                    => 'wp_update_page',
        'DELETE /wp/v2/pages/{id}'                   => 'wp_delete_page',
        'GET /wp/v2/pages/{parent}/revisions'        => 'wp_list_revisions',
        'GET /wp/v2/pages/{parent}/revisions/{id}'   => 'wp_get_revision',
        'DELETE /wp/v2/pages/{parent}/revisions/{id}' => 'wp_delete_revision',
        'GET /wp/v2/navigation/{parent}/revisions'   => 'wp_list_revisions',
        'GET /wp/v2/navigation/{parent}/revisions/{id}' => 'wp_get_revision',
        'DELETE /wp/v2/navigation/{parent}/revisions/{id}' => 'wp_delete_revision',
        'GET /wp/v2/posts/{id}/autosaves'            => 'wp_list_revisions',
        'GET /wp/v2/posts/{parent}/autosaves/{id}'   => 'wp_get_revision',
        'GET /wp/v2/pages/{id}/autosaves'            => 'wp_list_revisions',
        'GET /wp/v2/pages/{parent}/autosaves/{id}'   => 'wp_get_revision',
        'GET /wp/v2/blocks/{id}/autosaves'           => 'wp_list_revisions',
        'GET /wp/v2/blocks/{parent}/autosaves/{id}'  => 'wp_get_revision',
        'GET /wp/v2/navigation/{id}/autosaves'       => 'wp_list_revisions',
        'GET /wp/v2/navigation/{parent}/autosaves/{id}' => 'wp_get_revision',
        'GET /wp/v2/menu-items/{id}/autosaves'       => 'wp_list_revisions',
        'GET /wp/v2/menu-items/{parent}/autosaves/{id}' => 'wp_get_revision',
        'GET /wp/v2/media'                           => 'wp_list_media',
        'GET /wp/v2/media/{id}'                      => 'wp_get_media',
        'POST /wp/v2/media'                          => 'wp_upload_media',
        'WRITE /wp/v2/media/{id}'                    => 'wp_update_media',
        'DELETE /wp/v2/media/{id}'                   => 'wp_delete_media',
        'POST /wp/v2/media/{id}/edit'                => 'wp_edit_media_image',
        'POST /wp/v2/media/{id}/post-process'        => 'wp_regenerate_media_sizes',
        'GET /wp/v2/categories'                      => 'wp_list_categories',
        'GET /wp/v2/categories/{id}'                 => 'wp_get_category',
        'POST /wp/v2/categories'                     => 'wp_create_category',
        'WRITE /wp/v2/categories/{id}'               => 'wp_update_category',
        'DELETE /wp/v2/categories/{id}'              => 'wp_delete_category',
        'GET /wp/v2/tags'                            => 'wp_list_tags',
        'GET /wp/v2/tags/{id}'                       => 'wp_get_tag',
        'POST /wp/v2/tags'                           => 'wp_create_tag',
        'WRITE /wp/v2/tags/{id}'                     => 'wp_update_tag',
        'DELETE /wp/v2/tags/{id}'                    => 'wp_delete_tag',
        'GET /wp/v2/comments'                        => 'wp_list_comments',
        'GET /wp/v2/comments/{id}'                   => 'wp_get_comment',
        'POST /wp/v2/comments'                       => 'wp_create_comment',
        'WRITE /wp/v2/comments/{id}'                 => 'wp_update_comment',
        'DELETE /wp/v2/comments/{id}'                => 'wp_delete_comment',
        'GET /wp/v2/users'                           => 'wp_list_users',
        'GET /wp/v2/users/{id}'                      => 'wp_get_user',
        'POST /wp/v2/users'                          => 'wp_create_user',
        'WRITE /wp/v2/users/{id}'                    => 'wp_update_user',
        'DELETE /wp/v2/users/{id}'                   => 'wp_delete_user',
        'GET /wp/v2/users/me'                        => 'wp_get_user',
        'WRITE /wp/v2/users/me'                      => 'wp_update_user',
        'DELETE /wp/v2/users/me'                     => 'wp_delete_user',
        'GET /wp/v2/menus'                           => 'wp_list_menus',
        'GET /wp/v2/menus/{id}'                      => 'wp_get_menu',
        'POST /wp/v2/menus'                          => 'wp_create_menu',
        'WRITE /wp/v2/menus/{id}'                    => 'wp_update_menu',
        'DELETE /wp/v2/menus/{id}'                   => 'wp_delete_menu',
        'GET /wp/v2/menu-items'                      => 'wp_list_menu_items',
        'POST /wp/v2/menu-items'                     => 'wp_create_menu_item',
        'WRITE /wp/v2/menu-items/{id}'               => 'wp_update_menu_item',
        'DELETE /wp/v2/menu-items/{id}'              => 'wp_delete_menu_item',
        'GET /wp/v2/blocks'                          => 'wp_list_blocks',
        'GET /wp/v2/blocks/{id}'                     => 'wp_get_block',
        'POST /wp/v2/blocks'                         => 'wp_create_block',
        'WRITE /wp/v2/blocks/{id}'                   => 'wp_update_block',
        'DELETE /wp/v2/blocks/{id}'                  => 'wp_delete_block',
        'GET /wp/v2/blocks/{parent}/revisions'       => 'wp_list_revisions',
        'GET /wp/v2/blocks/{parent}/revisions/{id}'  => 'wp_get_revision',
        'DELETE /wp/v2/blocks/{parent}/revisions/{id}' => 'wp_delete_revision',
        'GET /wp/v2/templates'                       => 'wp_list_templates',
        'GET /wp/v2/templates/{id}'                  => 'wp_get_template',
        'WRITE /wp/v2/templates/{id}'                => 'wp_update_template',
        'POST /wp/v2/templates'                      => 'wp_update_template',
        'DELETE /wp/v2/templates/{id}'               => 'wp_update_template',
        'GET /wp/v2/template-parts'                  => 'wp_list_templates',
        'GET /wp/v2/template-parts/{id}'             => 'wp_get_template',
        'WRITE /wp/v2/template-parts/{id}'           => 'wp_update_template',
        'POST /wp/v2/template-parts'                 => 'wp_update_template',
        'DELETE /wp/v2/template-parts/{id}'          => 'wp_update_template',
        'GET /wp/v2/global-styles/{id}'              => 'wp_get_global_styles',
        'WRITE /wp/v2/global-styles/{id}'            => 'wp_update_global_styles',
        'GET /wp/v2/widgets'                         => 'wp_list_widgets',
        'GET /wp/v2/widgets/{id}'                    => 'wp_get_widget',
        'POST /wp/v2/widgets'                        => 'wp_create_widget',
        'WRITE /wp/v2/widgets/{id}'                  => 'wp_update_widget',
        'DELETE /wp/v2/widgets/{id}'                 => 'wp_delete_widget',
        'GET /wp/v2/sidebars'                        => 'wp_list_sidebars',
        'WRITE /wp/v2/sidebars/{id}'                 => 'wp_update_widget',
        'GET /wp/v2/widget-types'                    => 'wp_list_widget_types',
        'GET /wp/v2/settings'                        => 'wp_get_site_settings',
        'WRITE /wp/v2/settings'                      => 'wp_update_site_settings',
        'GET /wp/v2/plugins'                         => 'wp_list_plugins',
        'POST /wp/v2/plugins'                        => 'wp_install_plugin',
        'DELETE /wp/v2/plugins/{plugin}'             => 'wp_uninstall_plugin',
        'GET /wp/v2/themes'                          => 'wp_list_themes',
        'GET /wp/v2/search'                          => 'wp_search',
        'GET /wp/v2/types'                           => 'wp_get_post_types',
        'GET /wp/v2/statuses'                        => 'wp_get_post_statuses',
        'GET /wp/v2/taxonomies'                      => 'wp_get_taxonomies',
        'GET /wp/v2/block-types'                     => 'wp_list_block_types',
        'GET /wp/v2/block-types/{namespace}'         => 'wp_list_block_types',
        'GET /wp/v2/block-types/{namespace}/{name}'  => 'wp_list_block_types',
        'GET /wp/v2/templates/lookup'                => 'wp_get_template',
        'GET /wp/v2/template-parts/lookup'           => 'wp_get_template',
        'GET /wp/v2/block-patterns/patterns'         => 'wp_list_patterns',
        'GET /contact-form-7/v1/contact-forms'       => 'wp_cf7_list_forms',
        'POST /contact-form-7/v1/contact-forms'      => 'wp_cf7_create_form',
        'GET /contact-form-7/v1/contact-forms/{id}'  => 'wp_cf7_get_form',
        'WRITE /contact-form-7/v1/contact-forms/{id}' => 'wp_cf7_update_form',
        'DELETE /contact-form-7/v1/contact-forms/{id}' => 'wp_cf7_delete_form',
    );

    const PLUGIN_STATUS_TOOLS = array(
        'active'         => 'wp_activate_plugin',
        'network-active' => 'wp_activate_plugin',
        'inactive'       => 'wp_deactivate_plugin',
    );

    const COMMENT_STATUS_TOOLS = array(
        'approved' => 'wp_approve_comment',
        'approve'  => 'wp_approve_comment',
        '1'        => 'wp_approve_comment',
        'hold'     => 'wp_unapprove_comment',
        '0'        => 'wp_unapprove_comment',
        'spam'     => 'wp_spam_comment',
        'unspam'   => 'wp_unspam_comment',
        'trash'    => 'wp_trash_comment',
        'untrash'  => 'wp_untrash_comment',
    );

    const POST_UPDATE_TOOLS = array(
        'post'                => 'wp_update_post',
        'page'                => 'wp_update_page',
        'attachment'          => 'wp_update_media',
        'wp_block'            => 'wp_update_block',
        'nav_menu_item'       => 'wp_update_menu_item',
        'wp_template'         => 'wp_update_template',
        'wp_template_part'    => 'wp_update_template',
        'customize_changeset' => 'wp_update_theme_mod',
        'wp_global_styles'    => 'wp_update_global_styles',
        'custom_css'          => 'wp_update_custom_css',
    );

    const SITE_CSS_TYPES = array( 'wp_global_styles', 'custom_css' );

    const TERM_TOOLS = array(
        'category' => array(
            'get'    => 'wp_get_category',
            'create' => 'wp_create_category',
        ),
        'post_tag' => array(
            'get'    => 'wp_get_tag',
            'create' => 'wp_create_tag',
        ),
        'nav_menu' => array(
            'get'    => 'wp_get_menu',
            'create' => 'wp_create_menu',
        ),
    );

    const CREATE_MODERATION_TOOLS = array( 'wp_approve_comment', 'wp_spam_comment', 'wp_trash_comment' );

    const MODERATION_TOOLS = array(
        'approve'   => 'wp_approve_comment',
        'unapprove' => 'wp_unapprove_comment',
        'spam'      => 'wp_spam_comment',
        'unspam'    => 'wp_unspam_comment',
        'trash'     => 'wp_trash_comment',
        'untrash'   => 'wp_untrash_comment',
    );

    const META_TOOLS = array(
        'post' => array( 'wp_update_post_meta', 'wp_delete_post_meta' ),
        'term' => array( 'wp_update_term_meta', 'wp_delete_term_meta' ),
        'user' => array( 'wp_update_user_meta', 'wp_delete_user_meta' ),
    );

    const META_WRITES = array(
        'wp_upload_media'     => 'post',
        'wp_update_media'     => 'post',
        'wp_update_block'     => 'post',
        'wp_create_menu_item' => 'post',
        'wp_update_menu_item' => 'post',
        'wp_create_category'  => 'term',
        'wp_update_category'  => 'term',
        'wp_create_tag'       => 'term',
        'wp_update_tag'       => 'term',
        'wp_create_term'      => 'term',
        'wp_update_term'      => 'term',
        'wp_create_menu'      => 'term',
        'wp_update_menu'      => 'term',
        'wp_create_user'      => 'user',
        'wp_update_user'      => 'user',
    );

    const CONTENT_ROUTES = array(
        'post_type' => array(
            'GET {base}'                          => 'wp_list_cpt_items',
            'POST {base}'                         => 'wp_create_cpt_item',
            'GET {base}/{id}'                     => 'wp_get_cpt_item',
            'WRITE {base}/{id}'                   => 'wp_update_cpt_item',
            'DELETE {base}/{id}'                  => 'wp_delete_cpt_item',
            'GET {base}/{parent}/revisions'       => 'wp_list_revisions',
            'GET {base}/{parent}/revisions/{id}'  => 'wp_get_revision',
            'DELETE {base}/{parent}/revisions/{id}' => 'wp_delete_revision',
            'GET {base}/{id}/autosaves'           => 'wp_list_revisions',
            'GET {base}/{parent}/autosaves/{id}'  => 'wp_get_revision',
        ),
        'taxonomy'  => array(
            'GET {base}'         => 'wp_list_terms',
            'POST {base}'        => 'wp_create_term',
            'GET {base}/{id}'    => 'wp_get_term',
            'WRITE {base}/{id}'  => 'wp_update_term',
            'DELETE {base}/{id}' => 'wp_delete_term',
        ),
    );

    const BUILTIN_UNREACHED = array(
        'wp_template'      => null,
        'wp_template_part' => null,
        'wp_font_family'   => array( 'WRITE {base}/{id}' ),
    );

    const AUTOSAVE_UPDATES = array(
        '/wp/v2/posts/{id}/autosaves'          => 'wp_update_post',
        '/wp/v2/pages/{id}/autosaves'          => 'wp_update_page',
        '/wp/v2/blocks/{id}/autosaves'         => 'wp_update_block',
        '/wp/v2/menu-items/{id}/autosaves'     => 'wp_update_menu_item',
        '/wp/v2/templates/{id}/autosaves'      => 'wp_update_template',
        '/wp/v2/template-parts/{id}/autosaves' => 'wp_update_template',
    );

    const TEMPLATE_AUTOSAVES = array(
        '/wp/v2/templates/{id}/autosaves'      => 'wp_template',
        '/wp/v2/template-parts/{id}/autosaves' => 'wp_template_part',
    );

    public static function refusal( array $tools ): ?string {
        foreach ( $tools as $tool ) {
            $status = \Easy_MCP_AI\MCP\Server::current_call_switched_off( $tool );
            if ( null !== $status ) {
                return sprintf( 'This call does what the %1$s tool does, and %1$s %2$s', $tool, \Easy_MCP_AI\MCP\Tool_Status::predicate( $status ) );
            }
        }
        return null;
    }

    public static function route_refusal( string $method, string $route ): ?string {
        $tool = self::tool_for( $method, $route );
        return null === $tool ? null : self::refusal( array( $tool ) );
    }

    public static function post_update_tool( string $post_type, object $type ): ?string {
        if ( isset( self::POST_UPDATE_TOOLS[ $post_type ] ) ) {
            return self::POST_UPDATE_TOOLS[ $post_type ];
        }
        $found = \Easy_MCP_AI\Tools\Post_Type_Rest_Base::inspect( $post_type, $type );
        return in_array( $found['reason'], array( \Easy_MCP_AI\Tools\Post_Type_Rest_Base::OK, \Easy_MCP_AI\Tools\Post_Type_Rest_Base::HIDDEN ), true ) ? 'wp_update_cpt_item' : null;
    }

    public static function term_tool( string $taxonomy, object $object, string $action ): ?string {
        if ( isset( self::TERM_TOOLS[ $taxonomy ] ) ) {
            return self::TERM_TOOLS[ $taxonomy ][ $action ];
        }
        if ( empty( $object->show_in_rest ) && ! \Easy_MCP_AI\Tools\Hidden_Post_Types::reaches_taxonomy( $taxonomy, $object ) ) {
            return null;
        }
        return 'get' === $action ? 'wp_get_term' : 'wp_create_term';
    }

    public static function cpt_refusal( string $caller, string $rest_base ): ?string {
        $template = array_search( $caller, self::CONTENT_ROUTES['post_type'], true );
        if ( false === $template || 'wp/v2' !== \Easy_MCP_AI\Tools\Post_Type_Rest_Base::route_namespace( $rest_base ) ) {
            return null;
        }
        $key = str_replace( '{base}', '/wp/v2/' . strtolower( $rest_base ), $template );
        return isset( self::MAP[ $key ] ) ? self::refusal( array( self::MAP[ $key ] ) ) : null;
    }

    public static function for_call( string $method, string $pattern ): ?string {
        $tool = self::tool_for( $method, $pattern );
        if ( null === $tool ) {
            return null;
        }
        if ( class_exists( '\\Easy_MCP_AI\\MCP\\Server' ) && method_exists( '\\Easy_MCP_AI\\MCP\\Server', 'current_call_may_use' ) ) {
            return true === \Easy_MCP_AI\MCP\Server::current_call_may_use( $tool ) ? $tool : null;
        }
        return null;
    }

    public static function tools_for_action( string $method, string $pattern, array $params, array $url_params = array() ): array {
        $tools  = array();
        $mapped = self::tool_for( $method, $pattern );
        if ( null !== $mapped ) {
            $tools[] = $mapped;
        }
        $route  = strtolower( Route_Catalogue::display( $pattern ) );
        $status = isset( $params['status'] ) && is_string( $params['status'] ) ? $params['status'] : '';
        $writes = in_array( strtoupper( $method ), array( 'POST', 'PUT', 'PATCH' ), true );
        if ( $writes && isset( self::PLUGIN_STATUS_TOOLS[ $status ] ) ) {
            $installs_active = 'POST' === strtoupper( $method ) && '/wp/v2/plugins' === $route && 'inactive' !== $status;
            if ( $installs_active || '/wp/v2/plugins/{plugin}' === $route ) {
                $tools[] = self::PLUGIN_STATUS_TOOLS[ $status ];
            }
        }
        $comment_status = isset( $params['status'] ) && is_scalar( $params['status'] ) ? \sanitize_key( (string) $params['status'] ) : '';
        if ( $writes && '/wp/v2/comments/{id}' === $route && isset( self::COMMENT_STATUS_TOOLS[ $comment_status ] ) ) {
            $tools[] = self::COMMENT_STATUS_TOOLS[ $comment_status ];
        }
        if ( 'POST' === strtoupper( $method ) && '/wp/v2/comments' === $route && isset( self::COMMENT_STATUS_TOOLS[ $comment_status ] ) && in_array( self::COMMENT_STATUS_TOOLS[ $comment_status ], self::CREATE_MODERATION_TOOLS, true ) ) {
            $tools[] = self::COMMENT_STATUS_TOOLS[ $comment_status ];
        }
        if ( 'DELETE' === strtoupper( $method ) && '/wp/v2/comments/{id}' === $route && ( ! isset( $params['force'] ) || ! \rest_sanitize_boolean( $params['force'] ) ) ) {
            $tools[] = 'wp_trash_comment';
        }
        if ( isset( $params['meta'] ) && is_array( $params['meta'] ) && null !== $mapped && isset( self::META_WRITES[ $mapped ] ) ) {
            foreach ( $params['meta'] as $value ) {
                $pair = self::META_TOOLS[ self::META_WRITES[ $mapped ] ];
                $tools = array_merge( $tools, null === $value ? array( $pair[1] ) : ( array() === $value ? $pair : array( $pair[0] ) ) );
            }
        }
        $autosaves = self::AUTOSAVE_UPDATES;
        if ( 'POST' === strtoupper( $method ) && ! isset( $autosaves[ $route ] ) && preg_match( '#^(.+)/autosaves$#', $route, $parent ) && 'wp_update_cpt_item' === self::tool_for( 'PUT', $parent[1] ) ) {
            $autosaves[ $route ] = 'wp_update_cpt_item';
        }
        if ( 'POST' === strtoupper( $method ) && isset( $autosaves[ $route ], $url_params['id'] ) && self::autosave_updates_post( self::autosave_post_id( $route, $url_params['id'] ) ) ) {
            $tools[] = $autosaves[ $route ];
        }
        return array_values( array_unique( $tools ) );
    }

    private static function autosave_post_id( string $route, string $id ): int {
        if ( ! isset( self::TEMPLATE_AUTOSAVES[ $route ] ) ) {
            return (int) $id;
        }
        $template = \get_block_template( self::template_id( $id ), self::TEMPLATE_AUTOSAVES[ $route ] );
        return is_object( $template ) && ! empty( $template->wp_id ) ? (int) $template->wp_id : 0;
    }

    private static function template_id( string $id ): string {
        $id   = urldecode( $id );
        $last = strrpos( $id, '/' );
        if ( false === $last || '/' === substr( $id, $last - 1, 1 ) ) {
            return $id;
        }
        return substr( $id, 0, $last ) . '/' . substr( $id, $last );
    }

    private static function autosave_updates_post( int $post_id ): bool {
        $post = \get_post( $post_id );
        if ( ! is_object( $post ) || ! in_array( $post->post_status, array( 'draft', 'auto-draft' ), true ) || (int) $post->post_author !== \get_current_user_id() ) {
            return false;
        }
        if ( ! function_exists( 'wp_check_post_lock' ) ) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
        return ! \wp_check_post_lock( $post->ID );
    }

    public static function tool_for( string $method, string $pattern ): ?string {
        $route = strtolower( Route_Catalogue::display( $pattern ) );
        $keys  = array( strtoupper( $method ) . ' ' . $route );
        if ( in_array( strtoupper( $method ), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $keys[] = 'WRITE ' . $route;
        }
        $content = self::content_map();
        foreach ( $keys as $key ) {
            if ( isset( self::MAP[ $key ] ) ) {
                return self::MAP[ $key ];
            }
            if ( isset( $content[ $key ] ) ) {
                return $content[ $key ];
            }
        }
        return null;
    }

    private static function content_map(): array {
        $map     = array();
        $claimed = array();
        foreach ( array_keys( self::MAP ) as $key ) {
            $claimed += array_fill_keys( self::method_keys( $key ), true );
        }
        $objects = array(
            'post_type' => function_exists( 'get_post_types' ) ? (array) \get_post_types( array(), 'objects' ) : array(),
            'taxonomy'  => function_exists( 'get_taxonomies' ) ? (array) \get_taxonomies( array(), 'objects' ) : array(),
        );
        foreach ( $objects as $kind => $registered ) {
            foreach ( $registered as $name => $object ) {
                if ( ! is_object( $object ) || empty( $object->show_in_rest ) || ( method_exists( $object, 'get_rest_controller' ) && ! is_object( $object->get_rest_controller() ) ) ) {
                    continue;
                }
                $namespace = ! empty( $object->rest_namespace ) && is_string( $object->rest_namespace ) ? trim( $object->rest_namespace, '/' ) : 'wp/v2';
                $base      = ! empty( $object->rest_base ) && is_string( $object->rest_base ) ? $object->rest_base : (string) $name;
                $route     = strtolower( Route_Catalogue::display( '/' . $namespace . '/' . $base ) );
                foreach ( self::CONTENT_ROUTES[ $kind ] as $template => $tool ) {
                    $reaches = 'taxonomy' === $kind || self::post_type_reaches( (string) $name, $namespace, $base, $template );
                    foreach ( self::method_keys( str_replace( '{base}', $route, $template ) ) as $key ) {
                        if ( $reaches && ! isset( $claimed[ $key ] ) ) {
                            $map[ $key ] = $tool;
                        }
                        $claimed[ $key ] = true;
                    }
                }
            }
        }
        return $map;
    }

    private static function method_keys( string $key ): array {
        list( $method, $route ) = explode( ' ', $key, 2 );
        if ( 'WRITE' !== $method ) {
            return array( $key );
        }
        return array( 'POST ' . $route, 'PUT ' . $route, 'PATCH ' . $route );
    }

    private static function post_type_reaches( string $name, string $namespace, string $base, string $template ): bool {
        if ( 'wp/v2' !== $namespace || 1 !== preg_match( '/^[A-Za-z0-9_-]+\z/', $base ) ) {
            return false;
        }
        if ( ! \Easy_MCP_AI\Tools\Post_Type_Rest_Base::is_builtin( $name ) ) {
            return true;
        }
        if ( 'POST {base}' === $template ) {
            return false;
        }
        return ! array_key_exists( $name, self::BUILTIN_UNREACHED ) || ( null !== self::BUILTIN_UNREACHED[ $name ] && ! in_array( $template, self::BUILTIN_UNREACHED[ $name ], true ) );
    }
}
