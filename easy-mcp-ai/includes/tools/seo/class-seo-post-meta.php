<?php

namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seo_Post_Meta {

	public static function update( int $post_id, string $key, $value ): bool {
		$meta_id = (int) ( wp_is_post_revision( $post_id ) ?: $post_id );
		$before  = get_post_meta( $meta_id, $key, true );
		update_post_meta( $post_id, $key, $value );
		return get_post_meta( $meta_id, $key, true ) !== $before;
	}
}
