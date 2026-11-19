<?php
namespace Easy_MCP_AI\Tools\Pages;

use Easy_MCP_AI\Multilingual\Translation_Read;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Content_Hash;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Page extends Base_Tool {

    public function get_name() {
        return 'wp_get_page';
    }

    public function get_description() {
        return 'Gets a single WordPress page by ID. Returns { id, title, content, excerpt, status, date, modified, slug, link, parent (0 if top-level), menu_order, template, author, meta, content_sha256, content_bytes }. Content returned as raw Gutenberg/HTML markup. A page builder\'s stored layout (Elementor\'s `_elementor_data`) is left out of `meta` and named in `meta_left_out`: read it with `wp_get_page_layout`. For CPT items use `wp_get_cpt_item` instead.' . ' While WPML or Polylang is active the reply also carries `language` and `translations` ({ code: post ID }, only translations you can read).' . Content_Hash::DESCRIPTION;
    }

    public function get_category() {
        return 'pages';
    }

    public function get_required_capability() {
        return 'edit_pages';
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
                'page_id' => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the page to retrieve.',
                ),
            ),
            'required'   => array( 'page_id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'page_id' ) );

        $page_id = $this->parse_required_id( $arguments['page_id'], 'page_id' );
        $this->refuse_other_type( $page_id, 'page', 'get' );

        $data = $this->rest_request( 'GET', '/wp/v2/pages/' . $page_id, array( 'context' => 'edit' ) );

        return array_merge(
            array(
                'id'         => $data['id'],
                'title'      => $data['title']['raw'],
                'content'    => $data['content']['raw'],
                'excerpt'    => $data['excerpt']['raw'],
                'status'     => $data['status'],
                'date'       => $data['date'],
                'modified'   => $data['modified'],
                'slug'       => $data['slug'],
                'parent'     => $data['parent'],
                'menu_order' => $data['menu_order'],
                'template'   => $data['template'],
                'author'     => $data['author'],
                'link'       => $data['link'],
            ),
            self::rest_meta_without_layout( (int) $data['id'], is_array( $data['meta'] ?? null ) ? $data['meta'] : array(), 'pages' )
        ) + Translation_Read::fields_for( $page_id ) + Content_Hash::of_post( $page_id, false );
    }
}
