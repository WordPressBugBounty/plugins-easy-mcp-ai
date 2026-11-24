<?php
namespace Easy_MCP_AI\Tools\Taxonomy;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Tag extends Base_Tool {

    public function get_name() {
        return 'wp_update_tag';
    }

    public function get_description() {
        return 'Updates an existing WordPress tag (PATCH semantics). Required: `tag_id`. Optional: `name`, `slug`, `description`. Returns { id, name, slug }. Tags have no hierarchy — `parent` is not a valid field. Changing the slug updates permalinks for the tag archive page. Passing an empty string for `description` preserves the existing value (it is not cleared) — omit the field, or edit in wp-admin, to blank it. An empty string for `slug` is also silently ignored (the existing slug is preserved), unlike `wp_update_term`, which rejects an empty slug with an error. A call that sends no field to change (only empty values) is refused, with nothing saved.';
    }

    public function get_category() {
        return 'taxonomy';
    }

    public function get_required_capability() {
        return 'manage_categories';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'tag_id'      => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the tag to update.',
                ),
                'name'        => array(
                    'type'        => 'string',
                    'description' => 'The new name for the tag.',
                ),
                'slug'        => array(
                    'type'        => 'string',
                    'description' => 'The new slug for the tag.',
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'The new description for the tag. WordPress keeps the HTML it allows in a term description, such as links and bold text.',
                ),
            ),
            'required'   => array( 'tag_id' ),
        );
    }

    public function precheck( array $arguments ): void {
        $this->precheck_other_taxonomy( $arguments, 'tag_id', 'post_tag', 'update' );
        try {
            $this->parse_required_id( $arguments['tag_id'] ?? null, 'tag_id' );
        } catch ( \InvalidArgumentException $e ) {
            return;
        }
        $this->update_params( $arguments );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'tag_id' ) );

        $tag_id = $this->parse_required_id( $arguments['tag_id'], 'tag_id' );
        $this->refuse_other_taxonomy( $tag_id, 'post_tag', 'update' );
        $params = $this->update_params( $arguments );

        $data = $this->rest_request( 'POST', '/wp/v2/tags/' . $tag_id, $params );

        return array(
            'id'   => $data['id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
        );
    }

    private function update_params( array $arguments ): array {
        $params = array();

        if ( isset( $arguments['name'] ) ) {
            $params['name'] = sanitize_text_field( $arguments['name'] );
        }

        if ( ! empty( $arguments['slug'] ) ) {
            $params['slug'] = sanitize_title( $arguments['slug'] );
        }

        if ( isset( $arguments['description'] ) && '' !== $arguments['description'] ) {
            $params['description'] = self::markup_arg( $arguments, 'description' );
        }

        if ( array() === $params ) {
            throw new \InvalidArgumentException( 'At least one updateable field (name, slug, description) must be provided; an empty slug or description is ignored. Nothing was changed.' );
        }
        return $params;
    }
}
