<?php

namespace Easy_MCP_AI\Loco;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Loco_Access {

    public static function serve(): void {
        if ( class_exists( 'Loco_data_Permissions' ) && method_exists( 'Loco_data_Permissions', 'isSuperAdmin' ) ) {
            add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 4 );
        }
    }

    public static function grant( $allcaps, $caps, $args, $user ): array {
        unset( $args );
        $allcaps = is_array( $allcaps ) ? $allcaps : array();
        if ( $user instanceof \WP_User && is_array( $caps ) && in_array( 'loco_admin', $caps, true ) && empty( $allcaps['loco_admin'] ) && \Loco_data_Permissions::isSuperAdmin( $user ) ) {
            $allcaps['loco_admin'] = true;
        }
        return $allcaps;
    }
}
