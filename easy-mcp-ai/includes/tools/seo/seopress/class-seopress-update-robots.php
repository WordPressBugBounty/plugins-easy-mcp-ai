<?php

namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Easy_MCP_AI\Tools\Base_Tool;

class Seopress_Update_Robots extends Base_Tool {

	const META_KEYS = array(
		'noindex'        => '_seopress_robots_index',
		'nofollow'       => '_seopress_robots_follow',
		'no_image_index' => '_seopress_robots_imageindex',
		'no_snippet'     => '_seopress_robots_snippet',
		'canonical'      => '_seopress_robots_canonical',
		'primary_cat'    => '_seopress_robots_primary_cat',
		'breadcrumbs'    => '_seopress_robots_breadcrumbs',
	);

	public function get_name() {
		return 'wp_seopress_update_post_robots';
	}

	public function get_description() {
		return 'Updates the SEOPress robots / indexing settings for a post or page via the seopress/v1 REST API (noindex, nofollow, no image index, no snippet, canonical URL, primary category and breadcrumbs). Only the fields you provide are changed. A field SEOPress does not offer for the post is refused and nothing is changed: free SEOPress 10.4 offers no breadcrumbs title. A value SEOPress would delete instead of storing (primary_cat that is not a category ID, breadcrumbs "0") is refused too, and canonical must be an http or https URL or a path starting with "/". Returns { post_id, updated }; updated is false, with a reason, when no field was sent or SEOPress already stored these values.';
	}

	public function get_category() {
		return 'seopress';
	}

	public function get_required_capability() {
		return 'edit_posts';
	}

	public function get_annotations() {
		return array(
			'title'           => $this->get_title(),
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'openWorldHint'   => false,
		);
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'        => array(
					'type'        => 'integer',
					'description' => 'The ID of the post or page.',
				),
				'noindex'        => array(
					'type'        => 'boolean',
					'description' => 'Set true to add this post to the noindex robots directive.',
				),
				'nofollow'       => array(
					'type'        => 'boolean',
					'description' => 'Set true to add this post to the nofollow robots directive.',
				),
				'no_image_index' => array(
					'type'        => 'boolean',
					'description' => 'Set true to add the noimageindex robots directive.',
				),
				'no_snippet'     => array(
					'type'        => 'boolean',
					'description' => 'Set true to add the nosnippet robots directive.',
				),
				'canonical'      => array(
					'type'        => 'string',
					'description' => 'The canonical URL for this post.',
				),
				'primary_cat'    => array(
					'type'        => 'string',
					'description' => 'The primary category for this post.',
				),
				'breadcrumbs'    => array(
					'type'        => 'string',
					'description' => 'The custom breadcrumbs label for this post.',
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	public function execute( array $arguments ) {
		if ( ! function_exists( 'seopress_get_service' ) ) {
			throw new \RuntimeException( 'SEOPress is not active on this site. Please install and activate SEOPress to use this tool.' );
		}

		$post_id = $this->parse_required_id( $arguments['post_id'] ?? null, 'post_id' );
		$this->refuse_missing_post( $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new \RuntimeException( 'You do not have permission to edit this post.' );
		}

		$body = array();

		foreach ( array( 'noindex', 'nofollow', 'no_image_index', 'no_snippet' ) as $arg ) {
			if ( isset( $arguments[ $arg ] ) ) {
				$body[ self::META_KEYS[ $arg ] ] = rest_sanitize_boolean( $arguments[ $arg ] ) ? 'yes' : '';
			}
		}

		if ( isset( $arguments['canonical'] ) ) {
			$body[ self::META_KEYS['canonical'] ] = Seo_Url::to_send( 'canonical', $arguments['canonical'] );
		}
		if ( isset( $arguments['primary_cat'] ) ) {
			$body[ self::META_KEYS['primary_cat'] ] = sanitize_text_field( (string) $arguments['primary_cat'] );
		}
		if ( isset( $arguments['breadcrumbs'] ) ) {
			$body[ self::META_KEYS['breadcrumbs'] ] = Seo_Text::clean( (string) $arguments['breadcrumbs'] );
		}

		self::refuse_fields_seopress_drops( $post_id, array_keys( $body ) );
		Seopress_Stored_Meta::refuse_values_stored_as_nothing(
			array(
				'primary_cat' => 'term_id',
				'breadcrumbs' => 'text',
			),
			self::META_KEYS,
			$body,
			$arguments
		);
		$before = Seopress_Stored_Meta::read( $post_id, array_keys( $body ) );

		$this->rest_request( 'PUT', '/seopress/v1/posts/' . $post_id . '/meta-robot-settings', $body );
		if ( array() !== $body ) {
			$this->clear_page_cache( array( $post_id ) );
		}

		return Seopress_Stored_Meta::reply( $post_id, $before );
	}

	private static function refuse_fields_seopress_drops( int $post_id, array $keys ): void {
		if ( array() === $keys ) {
			return;
		}
		$helper = array( '\SEOPress\Helpers\Metas\RobotSettings', 'getMetaKeys' );
		if ( ! is_callable( $helper ) ) {
			throw new \InvalidArgumentException( 'This SEOPress version does not say which robots fields it saves (SEOPress\Helpers\Metas\RobotSettings::getMetaKeys() is missing), so nothing was sent. Nothing was changed.' );
		}
		$saved = array();
		foreach ( (array) call_user_func( $helper, $post_id ) as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
				$saved[] = $field['key'];
			}
		}
		$dropped = array();
		foreach ( $keys as $key ) {
			if ( ! in_array( $key, $saved, true ) ) {
				$dropped[] = (string) array_search( $key, self::META_KEYS, true );
			}
		}
		if ( array() !== $dropped ) {
			throw new \InvalidArgumentException( sprintf( 'SEOPress does not offer %s for this post, so its robots settings route would not save it. Nothing was changed.', implode( ', ', $dropped ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
