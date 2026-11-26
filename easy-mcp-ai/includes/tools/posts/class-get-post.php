<?php
namespace Easy_MCP_AI\Tools\Posts;

use Easy_MCP_AI\Multilingual\Translation_Read;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Content_Hash;
use Easy_MCP_AI\Tools\CPT\Status_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Post extends Base_Tool {

    public function get_name() {
        return 'wp_get_post';
    }

    public function get_description() {
        return 'Gets a single WordPress post by ID. Returns { id, title, content, excerpt, status, date, modified, slug, link, format, categories (array of IDs), tags (array of IDs), featured_media (attachment ID), author (user ID), sticky, comment_status, ping_status, meta (object of registered post meta), content_sha256, content_bytes }. Content is returned as raw Gutenberg/HTML markup (not rendered). A page builder\'s stored layout (Elementor\'s `_elementor_data`) is left out of `meta` and named in `meta_left_out`: read it with `wp_get_page_layout`. For CPT items use `wp_get_cpt_item` instead.' . ' While WPML or Polylang is active the reply also carries `language` and `translations` ({ code: post ID }, only translations you can read).' . Content_Hash::DESCRIPTION;
    }

    public function get_category() {
        return 'posts';
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

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
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the post to retrieve.',
                ),
            ),
            'required'   => array( 'post_id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'post_id' ) );

        $post_id = $this->parse_required_id( $arguments['post_id'], 'post_id' );
        $this->refuse_other_type( $post_id, 'post', 'get' );

        $data = $this->rest_request( 'GET', '/wp/v2/posts/' . $post_id, array( 'context' => 'edit' ) );

        return array_merge(
            array(
                'id'             => $data['id'],
                'title'          => $data['title']['raw'],
                'content'        => $data['content']['raw'],
                'excerpt'        => $data['excerpt']['raw'],
                'status'         => Status_Taxonomy::status_of( $data ),
                'date'           => $data['date'],
                'modified'       => $data['modified'],
                'slug'           => $data['slug'],
                'author'         => $data['author'],
                'categories'     => $data['categories'],
                'tags'           => $data['tags'],
                'featured_media' => $data['featured_media'],
                'format'         => $data['format'],
                'link'           => $data['link'],
                'comment_status' => $data['comment_status'],
                'ping_status'    => $data['ping_status'],
                'sticky'         => $data['sticky'],
            ),
            self::rest_meta_without_layout( (int) $data['id'], is_array( $data['meta'] ?? null ) ? $data['meta'] : array(), 'posts' )
        ) + Translation_Read::fields_for( $post_id ) + Content_Hash::of_post( $post_id, false );
    }
}
