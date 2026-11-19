<?php

namespace Easy_MCP_AI\Cloud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cloud_Tools {

    const CATEGORY = 'cloud';

    const NAMES = array(
        'blocks_execute',
        'blocks_schema_get',
        'atomic_wind_context',
        'blocks_publish',
        'blocks_edit',
        'blocks_get',
        'blocks_list_posts',
        'blocks_site_styles',
    );

    public static function any_enabled(): bool {
        $patterns = (array) \Easy_MCP_AI\Config::get( 'allowed_tool_patterns' );
        foreach ( self::NAMES as $name ) {
            if ( \Easy_MCP_AI\Tools\Tool_State::resolve( $name, true )[0] && \Easy_MCP_AI\MCP\Server::matches_tool_patterns( $name, $patterns ) ) {
                return true;
            }
        }
        return false;
    }
}
