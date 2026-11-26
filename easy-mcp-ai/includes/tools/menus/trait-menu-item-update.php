<?php

namespace Easy_MCP_AI\Tools\Menus;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Menu_Item_Update {

    protected function update_menu_item( int $id, array $item, array $params ) {
        $lent    = Menu_Item_Stored_Fields::lend( $item, $params );
        $request = self::build_rest_request( 'POST', '/wp/v2/menu-items/' . $id, $params + $lent, array( 'id' ) );
        return Menu_Item_Stored_Fields::around(
            function () use ( $request ) {
                return $this->dispatch_rest_request( $request );
            },
            $request,
            array_keys( $lent )
        );
    }
}
