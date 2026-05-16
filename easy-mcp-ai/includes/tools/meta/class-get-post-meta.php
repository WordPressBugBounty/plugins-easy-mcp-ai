<?php
namespace Easy_MCP_AI\Tools\Meta;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Post_Meta extends Base_Tool {

    public function get_name() {
        return 'wp_get_post_meta';
    }

    public function get_description() {
        return 'Gets all REST-API-visible meta fields for a post. Only meta fields registered with show_in_rest are returned.';
    }

    public function get_category() {
        return 'meta';
    }

    public function get_required_capability() {
        return 'read';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_description(),
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'post_id'   => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the post to retrieve meta for.',
                ),
                'post_type' => array(
                    'type'        => 'string',
                    'description' => 'The REST base for the post type (e.g. posts, pages). Default: posts.',
                    'default'     => 'posts',
                ),
            ),
            'required'   => array( 'post_id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'post_id' ) );

        $post_id   = $this->parse_required_id( $arguments['post_id'], 'post_id' );
        $post_type = ! empty( $arguments['post_type'] )
            ? $this->validate_rest_route_segment( $arguments['post_type'], 'post_type' )
            : 'posts';

        $data = $this->rest_request( 'GET', '/wp/v2/' . $post_type . '/' . $post_id, array( 'context' => 'edit' ) );

        
        $meta   = isset( $data['meta'] ) ? $data['meta'] : array();
        $result = array(
            'post_id' => $post_id,
            'meta'    => ! empty( $meta ) ? $meta : new \stdClass(),
        );

        if ( empty( $meta ) || ( is_array( $meta ) && count( $meta ) === 0 ) ) {
            $result['notice'] = 'No meta fields returned. This usually means no custom fields are registered with show_in_rest=true for this post type. Meta fields must be explicitly registered by the theme or a plugin (e.g., ACF with "Show in REST" enabled) to be visible via the REST API.';
        }

        return $result;
    }
}
