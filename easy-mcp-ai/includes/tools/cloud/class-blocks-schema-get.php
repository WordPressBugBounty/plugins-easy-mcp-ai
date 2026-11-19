<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Cloud_Cache;
use Easy_MCP_AI\Cloud\Cloud_Client;
use Easy_MCP_AI\Cloud\Cloud_Error;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks_Schema_Get extends Cloud_Block_Tool {

    const MAX_SCHEMA_BLOCKS = 100;

    protected function is_idempotent(): bool {
        return true;
    }

    protected function reaches_cloud(): bool {
        return true;
    }

    public function get_name() {
        return 'blocks_schema_get';
    }

    public function get_title() {
        return 'Block schema';
    }

    public function get_description() {
        return "Discover the WordPress block builders and load their TypeScript definitions. Call this before blocks_execute.\naction \"list\": every block you can build, by family. action \"get\" with blocks: the definitions and usage rules for those blocks. Without action, blocks means get, none means list.";
    }

    public function get_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'list', 'get' ),
                    'description' => 'list: the blocks you can build, by family. get: TypeScript definitions for the named blocks. Default: get when blocks is given, list otherwise.',
                ),
                'blocks' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_SCHEMA_BLOCKS,
                    'description' => 'Block names from the list (e.g. "heading", "otter.button", "atomicWind.box"). Required with action get.',
                ),
            ),
        );
    }

    protected function perform( array $arguments ) {
        $blocks = null;
        if ( isset( $arguments['blocks'] ) ) {
            $blocks = $arguments['blocks'];
            if ( ! is_array( $blocks ) || $blocks !== array_values( $blocks ) || count( $blocks ) < 1 || count( $blocks ) > self::MAX_SCHEMA_BLOCKS ) {
                throw new Detailed_Tool_Error( sprintf( 'blocks must be a list of 1 to %d block names.', self::MAX_SCHEMA_BLOCKS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            foreach ( $blocks as $name ) {
                if ( ! is_string( $name ) ) {
                    throw new Detailed_Tool_Error( 'blocks must be a list of block names (strings).' );
                }
            }
        }
        $action = self::enum_arg( $arguments, 'action', array( 'list', 'get' ) ) ?? ( null === $blocks ? 'list' : 'get' );
        if ( 'get' === $action && null === $blocks ) {
            throw self::refusal( sprintf( 'blocks is required with action "get": 1 to %d block names from blocks_schema_get({ action: "list" }).', self::MAX_SCHEMA_BLOCKS ) );
        }
        $request = array( 'action' => $action );
        if ( null !== $blocks ) {
            $request['blocks'] = $blocks;
        }
        $cached = Cloud_Cache::get( '/schema', $request );
        if ( null !== $cached ) {
            return $cached;
        }
        $answer = $this->client()->schema( $request );
        unset( $answer['site_id'] );
        $listed = isset( $answer['blocks'] ) && is_array( $answer['blocks'] );
        $loaded = isset( $answer['schema'], $answer['usageRules'] ) && is_string( $answer['schema'] ) && is_string( $answer['usageRules'] );
        if ( ( 'list' === $action && ! $listed ) || ( 'get' === $action && ! $loaded ) ) {
            throw new Cloud_Error( Cloud_Client::MESSAGE_LATER );
        }
        Cloud_Cache::put( '/schema', $request, $answer );
        return $answer;
    }
}
