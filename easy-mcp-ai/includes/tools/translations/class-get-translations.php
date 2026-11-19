<?php

namespace Easy_MCP_AI\Tools\Translations;

use Easy_MCP_AI\Multilingual\Translation_Read;
use Easy_MCP_AI\Multilingual\Translation_Registry;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Translations extends Base_Tool {

    public function get_name() {
        return 'wp_get_translations';
    }

    public function get_description() {
        return 'Gets a post\'s language and its translation group in the site\'s multilingual plugin (WPML or Polylang): every translation with its ID, title and status, and the site languages that have no translation yet. Works for posts, pages and translated custom post types. Required: `post_id`. Returns { post_id, post_type, language, translations: [{ lang, id, title, status }], missing_languages }. Translations this account cannot read are left out.';
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
            'properties' => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'ID of the post, page or custom post type item.',
                ),
            ),
            'required'   => array( 'post_id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'post_id' ) );
        $post_id = $this->parse_required_id( $arguments['post_id'], 'post_id' );
        $adapter = Translation_Registry::require_active();
        $post = get_post( $post_id );
        if ( ! is_object( $post ) || ! current_user_can( 'read_post', $post_id ) ) {
            throw new \InvalidArgumentException( sprintf( 'There is no post with ID %d that this account can read.', $post_id ) );
        }
        if ( ! $adapter->is_translated_type( (string) $post->post_type ) ) {
            throw new \InvalidArgumentException( sprintf( '%1$s does not translate the post type "%2$s".', $adapter->label(), $post->post_type ) );
        }
        $group = Translation_Read::readable( $adapter->translations_of( $post_id ) );
        $rows  = array();
        foreach ( $group as $code => $id ) {
            $translation = get_post( $id );
            $rows[]      = array(
                'lang'   => (string) $code,
                'id'     => $id,
                'title'  => is_object( $translation ) ? (string) $translation->post_title : '',
                'status' => is_object( $translation ) ? (string) $translation->post_status : '',
            );
        }
        return array(
            'post_id'           => $post_id,
            'post_type'         => (string) $post->post_type,
            'language'          => $adapter->language_of( $post_id ),
            'translations'      => $rows,
            'missing_languages' => array_values( array_diff( $adapter->codes(), array_keys( $adapter->translations_of( $post_id ) ) ) ),
        );
    }
}
