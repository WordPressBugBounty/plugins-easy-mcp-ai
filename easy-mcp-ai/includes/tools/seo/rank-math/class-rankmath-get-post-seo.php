<?php

namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Easy_MCP_AI\Tools\Base_Tool;

class Rankmath_Get_Post_Seo extends Base_Tool {

	const META_KEYS = array(
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'rank_math_robots',
		'rank_math_canonical_url',
		'rank_math_facebook_title',
		'rank_math_facebook_description',
		'rank_math_facebook_image',
		'rank_math_facebook_image_id',
		'rank_math_facebook_enable_image_overlay',
		'rank_math_facebook_image_overlay',
		'rank_math_twitter_use_facebook',
		'rank_math_twitter_title',
		'rank_math_twitter_description',
		'rank_math_twitter_image',
		'rank_math_twitter_image_id',
		'rank_math_twitter_enable_image_overlay',
		'rank_math_twitter_image_overlay',
		'rank_math_pillar_content',
	);

	public function get_name() {
		return 'wp_rm_get_post_seo';
	}

	public function get_description() {
		return 'Gets all Rank Math SEO meta fields for a post: title, description, focus keyword, robots settings, canonical URL, Open Graph fields, and Twitter card fields, including the social image attachment IDs (Rank Math shows the image by its ID), the image overlay settings and rank_math_twitter_use_facebook (empty or "on": the X card copies the Facebook fields). Reads directly from postmeta, so a field never saved is "". Rank Math\'s own rank-math/get-post-seo-meta ability returns the description Rank Math prints instead, which falls back to the excerpt and then a template when none is saved: use this tool to find posts without a description.';
	}

	public function get_category() {
		return 'rank-math';
	}

	public function get_required_capability() {
		return 'edit_posts';
	}

	public function get_annotations() {
		return array(
			'title'           => $this->get_title(),
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'openWorldHint'   => false,
		);
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The ID of the post.',
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	public function execute( array $arguments ) {
		if ( ! function_exists( 'rank_math' ) ) {
			throw new \RuntimeException( 'Rank Math SEO is not active on this site. Please install and activate Rank Math SEO to use this tool.' );
		}

		$post_id = $this->parse_required_id( $arguments['post_id'] ?? null, 'post_id' );

		if ( ! get_post( $post_id ) ) {
			throw new \RuntimeException( 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new \RuntimeException( sprintf( 'Sorry, you are not allowed to edit post %d, so its stored Rank Math SEO values cannot be read.', $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$data = array( 'post_id' => $post_id );

		foreach ( self::META_KEYS as $key ) {
			$data[ $key ] = get_post_meta( $post_id, $key, true );
		}

		return $data;
	}
}
