<?php

namespace Easy_MCP_AI\Tools\Translations;

use Easy_MCP_AI\MCP\Detailed_Runtime_Error;
use Easy_MCP_AI\Multilingual\Translation_Adapter;
use Easy_MCP_AI\Multilingual\Translation_Registry;
use Easy_MCP_AI\Multilingual\Translation_Write;
use Easy_MCP_AI\Option_Lock;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Link_Translations extends Base_Tool {

    public function get_name() {
        return 'wp_link_translations';
    }

    public function get_description() {
        return 'Links existing posts as translations of each other in the site\'s multilingual plugin (WPML or Polylang). Required: `translations`, the whole group as { language code: post ID }, e.g. { "en": 12, "de": 48 }. Each post takes the language it is listed under; all must be of one post type that the plugin translates, and this account must be able to edit each. A post that was linked with one of them and is not listed is unlinked and named in `unlinked`; if the plugin keeps it in the group, the call is an error naming it. To create a new translation, use wp_create_post (or wp_create_page, wp_create_cpt_item) with `lang` and `translation_of` instead. Returns { post_type, translations, unlinked, changed }; changed is false when the posts were already linked exactly so.';
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
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'translations' => array(
                    'type'        => 'object',
                    'description' => 'The whole translation group as { language code: post ID }, e.g. { "en": 12, "de": 48 }. Codes are those wp_list_languages returns.',
                ),
            ),
            'required'   => array( 'translations' ),
        );
    }

    public function precheck( array $arguments ): void {
        $this->plan( $arguments );
    }

    public function execute( array $arguments ) {
        $lock = Translation_Write::lock();
        try {
            $sent = is_string( $arguments['translations'] ?? null ) ? json_decode( (string) $arguments['translations'], true ) : ( $arguments['translations'] ?? array() );
            Translation_Registry::require_active()->forget( array_values( array_filter( array_map( 'intval', is_array( $sent ) ? $sent : array() ) ) ) );
            return $this->link( $arguments, $lock );
        } finally {
            Option_Lock::release( Translation_Write::LOCK, $lock );
        }
    }

    private function link( array $arguments, string &$lock ): array {
        list( $adapter, $group, $post_type, $before ) = $this->plan( $arguments );
        $unlinked = array();
        foreach ( $before as $members ) {
            foreach ( $members as $id ) {
                if ( ! in_array( $id, $group, true ) ) {
                    $unlinked[] = $id;
                }
            }
        }
        $unlinked = array_values( array_unique( $unlinked ) );
        $changed  = array() !== $unlinked || ! $this->already_linked( $adapter, $group );
        if ( $changed ) {
            $kept = Translation_Write::keep( $lock, $cause );
            if ( '' === $kept ) {
                throw new \RuntimeException( sprintf( 'The group was not changed: %s. Call again.', $cause ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $lock = $kept;
            $adapter->assign( $group, $post_type );
            $overlap = Translation_Write::overlap( $lock );
            if ( null !== $overlap ) {
                throw new \RuntimeException( 'The group was written, but ' . $overlap ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
        if ( ! $this->already_linked( $adapter, $group ) ) {
            throw new \RuntimeException( sprintf( '%1$s did not record the group %2$s. Check the posts\' languages in %1$s before trying again.', $adapter->label(), (string) wp_json_encode( (object) $group ) ) );
        }
        $this->require_unlinked( $adapter, $group, $unlinked );
        ksort( $group );
        sort( $unlinked );
        return array(
            'post_type'    => $post_type,
            'translations' => $group,
            'unlinked'     => $unlinked,
            'changed'      => $changed,
        );
    }

    private function plan( array $arguments ): array {
        $this->validate_required( $arguments, array( 'translations' ) );
        $adapter = Translation_Registry::require_active();
        $group     = $this->group_of( $adapter, $arguments['translations'] );
        $post_type = $this->post_type_of( $adapter, $group );
        $before    = array();
        $touched   = array_values( $group );
        foreach ( $group as $code => $post_id ) {
            $refusal = $adapter->refusal( $code );
            if ( null !== $refusal ) {
                throw new \InvalidArgumentException( $refusal . ' Nothing was changed.' );
            }
            $before[ $post_id ] = $adapter->translations_of( $post_id );
            $touched            = array_merge( $touched, array_values( $before[ $post_id ] ) );
        }
        Translation_Write::require_editable( $touched );
        return array( $adapter, $group, $post_type, $before );
    }

    private function group_of( Translation_Adapter $adapter, $value ): array {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value   = is_array( $decoded ) ? $decoded : $value;
        }
        if ( ! is_array( $value ) || array() === $value ) {
            throw new \InvalidArgumentException( 'translations must be an object of language codes to post IDs, e.g. { "en": 12, "de": 48 }. Nothing was changed.' );
        }
        $group = array();
        foreach ( $value as $code => $post_id ) {
            $code = Translation_Write::code( $adapter, (string) $code );
            $id   = $this->parse_required_id( $post_id, 'translations.' . $code );
            if ( in_array( $id, $group, true ) ) {
                throw new \InvalidArgumentException( sprintf( 'Post %d is listed under two languages; a post has one language. Nothing was changed.', $id ) );
            }
            $group[ $code ] = $id;
        }
        return $group;
    }

    private function post_type_of( Translation_Adapter $adapter, array $group ): string {
        $type = null;
        foreach ( $group as $post_id ) {
            $post = get_post( $post_id );
            if ( ! is_object( $post ) ) {
                throw new \InvalidArgumentException( sprintf( 'There is no post with ID %d. Nothing was changed.', $post_id ) );
            }
            if ( null !== $type && $post->post_type !== $type ) {
                throw new \InvalidArgumentException( sprintf( 'Post %1$d is a %2$s and the others are %3$s: translations are of one post type. Nothing was changed.', $post_id, $post->post_type, $type ) );
            }
            $type = (string) $post->post_type;
        }
        if ( ! $adapter->is_translated_type( (string) $type ) ) {
            throw new \InvalidArgumentException( sprintf( '%1$s does not translate the post type "%2$s". Nothing was changed.', $adapter->label(), $type ) );
        }
        return (string) $type;
    }

    private function require_unlinked( Translation_Adapter $adapter, array $group, array $unlinked ): void {
        if ( array() === $unlinked ) {
            return;
        }
        $kept = array();
        foreach ( $unlinked as $post_id ) {
            $reads = $adapter->translations_of( $post_id );
            if ( array() !== array_intersect( array_diff( $reads, array( $post_id ) ), $group ) ) {
                $kept[] = sprintf( 'post %1$d still reads %2$s', $post_id, (string) wp_json_encode( (object) $reads ) );
            }
        }
        if ( array() !== $kept ) {
            throw Detailed_Runtime_Error::fitted( sprintf( 'The group %1$s was saved, but %2$s did not unlink every post left out of it: %3$s. Removing such a post\'s translations in %2$s\'s editor can unlink the others too, so check the group there and send this call again afterwards.', (string) wp_json_encode( (object) $group ), $adapter->label(), implode( '; ', $kept ) ) );
        }
    }

    private function already_linked( Translation_Adapter $adapter, array $group ): bool {
        foreach ( $group as $code => $post_id ) {
            if ( $adapter->language_of( $post_id ) !== $code || ! Translation_Write::same_group( $adapter->translations_of( $post_id ), $group ) ) {
                return false;
            }
        }
        return true;
    }
}
