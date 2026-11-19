<?php

namespace Easy_MCP_AI\Tools\Translations;

use Easy_MCP_AI\Multilingual\Translation_Registry;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Hidden_Post_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class List_Languages extends Base_Tool {

    public function get_name() {
        return 'wp_list_languages';
    }

    public function get_description() {
        return 'Lists the languages this site\'s multilingual plugin (WPML or Polylang) has set up, the default first, and the post types it translates. Use the codes as `lang` on wp_create_post, wp_create_page, wp_create_cpt_item and their update tools, and on wp_list_posts, wp_list_pages, wp_list_cpt_items and wp_search_posts. Returns { plugin, version, default_language, languages: [{ code, name, locale, default }], translated_post_types }.';
    }

    public function get_category() {
        return 'translations';
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
            'properties' => new \stdClass(),
        );
    }

    public function execute( array $arguments ) {
        unset( $arguments );
        $adapter = Translation_Registry::require_active();
        $types = array();
        $reached = array_merge( array_keys( get_post_types( array( 'show_in_rest' => true ), 'names' ) ), array_keys( Hidden_Post_Types::reachable_types() ) );
        foreach ( array_unique( $reached ) as $type ) {
            if ( $adapter->is_translated_type( (string) $type ) ) {
                $types[] = (string) $type;
            }
        }
        sort( $types );
        return array(
            'plugin'                => $adapter->label(),
            'version'               => $adapter->version(),
            'default_language'      => $adapter->default_code(),
            'languages'             => $adapter->languages(),
            'translated_post_types' => $types,
        );
    }
}
