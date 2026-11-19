<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Block_Checks;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks_Get extends Local_Block_Tool {

    protected function is_idempotent(): bool {
        return true;
    }

    public function get_name() {
        return 'blocks_get';
    }

    public function get_title() {
        return 'Get blocks';
    }

    public function get_description() {
        return "Read the block tree of a page, post or product on this site: each block's path, name, attributes and a preview of its HTML, and the post's modified value. Use the paths and modified with blocks_edit (a path is a position and moves when the post changes). If modified is null it could not be read, and edits that use paths are refused.";
    }

    public function get_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'post_id' => self::post_id_property(),
                'type'    => self::type_property(),
                'path'    => self::path_property( 'Only this block and its children, e.g. "0.2".' ),
                'depth'   => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'description' => 'Levels of nested blocks to expand (default 2).',
                ),
            ),
            'required'             => array( 'post_id' ),
        );
    }

    protected function perform( array $arguments ) {
        $post_id = self::int_arg( $arguments, 'post_id', 1 );
        $type    = self::enum_arg( $arguments, 'type', self::POST_KINDS ) ?? 'page';
        $path    = self::path_arg( $arguments, 'path' );
        $depth   = self::int_arg( $arguments, 'depth', 1, 10 ) ?? 2;
        if ( null === $post_id ) {
            throw new Detailed_Tool_Error( 'Missing required parameters: post_id' );
        }
        $modified = $this->read_modified_quietly( $type, $post_id );
        $args     = array(
            'post_id'   => $post_id,
            'post_type' => self::REST_BASE[ $type ],
            'depth'     => $depth,
        );
        if ( null !== $path ) {
            $args['path'] = $path;
        }
        $got    = $this->ask( 'wp_get_post_blocks', $args, false, self::target( 'blocks_get', $post_id, $type ) );
        $output = array_merge( array( 'content_notice' => Block_Checks::SITE_CONTENT_NOTICE ), $got, array( 'modified' => $modified ) );
        if ( null === $modified ) {
            $output['message'] = 'modified could not be read, so blocks_edit ops with a path will be refused until it can.';
            $predicate         = self::unavailable_predicate( self::read_tool( $type ) );
            if ( null !== $predicate ) {
                $output['message'] = sprintf( 'modified could not be read: %1$s %2$s Until it can, blocks_edit ops with a path will be refused.', self::read_tool( $type ), $predicate );
            }
        }
        return $output;
    }
}
