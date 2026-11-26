<?php
namespace Easy_MCP_AI\Tools\Posts;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\CPT\Status_Taxonomy;
use Easy_MCP_AI\Tools\Delete_Outcome;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delete_Post extends Base_Tool {

    public function get_name() {
        return 'wp_delete_post';
    }

    public function get_description() {
        return 'Deletes a WordPress post by ID. Required: `post_id`. Optional: `force` (default false) — false moves the post to trash (recoverable via `wp_update_post` setting status="publish"/"draft"); true permanently deletes the post and its meta/revisions (irreversible). Returns { deleted: true, status, id, title }: `status` is "trash" when the post was moved to the Trash and "deleted" when it no longer exists, read from what WordPress did, not from `force`; if a plugin stops the trash or the delete, the call is an error. Prefer false (trash) unless permanent deletion is explicitly requested. For CPT items use `wp_delete_cpt_item` instead.';
    }

    public function get_category() {
        return 'posts';
    }

    public function get_required_capability() {
        return 'delete_posts';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the post to delete.',
                ),
                'force'   => array(
                    'type'        => 'boolean',
                    'description' => 'Whether to bypass the trash and force deletion. Default is false.',
                    'default'     => false,
                ),
            ),
            'required'   => array( 'post_id' ),
        );
    }

    public function describe( array $arguments ) {
        try {
            $force = self::boolean_arg( $arguments, 'force', false, '' );
        } catch ( \InvalidArgumentException $e ) {
            return '';
        }
        $id = isset( $arguments['post_id'] ) ? (int) $arguments['post_id'] : 0;
        $post = $id > 0 ? $this->peek( '/wp/v2/posts/' . $id, 'id,title,status' ) : null;
        if ( ! $post ) {
            return '';
        }
        return sprintf(
            /* translators: 1: post title, 2: post id, 3: post status, 4: what happens */
            __( "Delete post '%1\$s' (ID %2\$d, %3\$s) — %4\$s.", 'easy-mcp-ai' ),
            self::rest_title( $post ), $id, isset( $post['status'] ) ? Status_Taxonomy::status_of( $post + array( 'id' => $id ) ) : 'unknown',
            $force ? __( 'permanently, bypassing the trash', 'easy-mcp-ai' ) : __( 'moves it to the trash', 'easy-mcp-ai' )
        );
    }

    public function precheck( array $arguments ): void {
        $this->precheck_other_type( $arguments, 'post_id', 'post', 'delete' );
        self::boolean_arg( $arguments, 'force', false, 'Nothing was deleted.' );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'post_id' ) );

        $post_id = $this->parse_required_id( $arguments['post_id'], 'post_id' );
        $this->refuse_other_type( $post_id, 'post', 'delete' );

        $force  = self::boolean_arg( $arguments, 'force', false, 'Nothing was deleted.' );
        $params = array();

        if ( isset( $arguments['force'] ) ) {
            $params['force'] = $force;
        }

        $data    = $this->rest_request( 'DELETE', '/wp/v2/posts/' . $post_id, $params );
        $outcome = Delete_Outcome::of_post( $data, $post_id, 'post ' . $post_id );
        $post    = $outcome['item'];

        return array(
            'deleted' => true,
            'status'  => $outcome['status'],
            'id'      => $post['id'] ?? null,
            'title'   => $post['title']['raw'] ?? ( $post['title']['rendered'] ?? '' ),
        );
    }
}
