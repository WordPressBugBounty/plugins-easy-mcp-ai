<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Cloud_Cache;
use Easy_MCP_AI\Cloud\Cloud_Client;
use Easy_MCP_AI\Cloud\Cloud_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Atomic_Wind_Context extends Cloud_Block_Tool {

    protected function is_idempotent(): bool {
        return true;
    }

    protected function reaches_cloud(): bool {
        return true;
    }

    public function get_name() {
        return 'atomic_wind_context';
    }

    public function get_title() {
        return 'Atomic Wind context';
    }

    public function get_description() {
        return 'Load the Atomic Wind block definitions and full usage instructions. Use this instead of blocks_schema_get when building only with Atomic Wind blocks.';
    }

    public function get_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => new \stdClass(),
        );
    }

    protected function perform( array $arguments ) {
        $cached = Cloud_Cache::get( '/atomic-wind-context', array() );
        if ( null !== $cached ) {
            return $cached;
        }
        $answer = $this->client()->atomic_wind_context();
        if ( ! isset( $answer['schema'], $answer['usageRules'] ) || ! is_string( $answer['schema'] ) || ! is_string( $answer['usageRules'] ) ) {
            throw new Cloud_Error( Cloud_Client::MESSAGE_LATER );
        }
        $answer = array(
            'schema'     => $answer['schema'],
            'usageRules' => $answer['usageRules'],
        );
        Cloud_Cache::put( '/atomic-wind-context', array(), $answer );
        return $answer;
    }
}
