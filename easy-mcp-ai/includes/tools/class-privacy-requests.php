<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Privacy_Requests {

    const POST_TYPE = 'user_request';

    const CAPABILITIES = array(
        'export_personal_data' => 'export_others_personal_data',
        'remove_personal_data' => 'erase_others_personal_data',
    );

    public static function may_read( object $post ): bool {
        $owner = 'revision' === ( $post->post_type ?? '' ) ? get_post( (int) ( $post->post_parent ?? 0 ) ) : $post;
        if ( ! is_object( $owner ) || self::POST_TYPE !== ( $owner->post_type ?? '' ) ) {
            return true;
        }
        return current_user_can( self::CAPABILITIES[ (string) ( $owner->post_name ?? '' ) ] ?? 'manage_privacy_options' );
    }
}
