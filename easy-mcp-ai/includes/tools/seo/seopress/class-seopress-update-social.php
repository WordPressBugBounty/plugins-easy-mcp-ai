<?php

namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Easy_MCP_AI\Tools\Base_Tool;

class Seopress_Update_Social extends Base_Tool {

	const IMAGE_URLS = array(
		'og_image'      => '_seopress_social_fb_img',
		'twitter_image' => '_seopress_social_twitter_img',
	);

	const IMAGE_IDS = array(
		'og_image'      => '_seopress_social_fb_img_attachment_id',
		'twitter_image' => '_seopress_social_twitter_img_attachment_id',
	);

	public function get_name() {
		return 'wp_seopress_update_post_social';
	}

	public function get_description() {
		return 'Updates the SEOPress social (Open Graph / Twitter) settings for a post or page via the seopress/v1 REST API. Only the fields you provide are changed; an image URL is stored with the id of its Media Library image, as SEOPress\'s own editor stores it. A text field sent as "0", which SEOPress would delete instead of storing, is refused, and an image URL must be an http or https URL or a path starting with "/"; send "" to clear one. Returns { post_id, updated }; updated is false, with a reason, when no field was sent or SEOPress already stored these values.';
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
				'post_id'             => array(
					'type'        => 'integer',
					'description' => 'The ID of the post or page.',
				),
				'og_title'            => array(
					'type'        => 'string',
					'description' => 'The Open Graph (Facebook) title.',
				),
				'og_description'      => array(
					'type'        => 'string',
					'description' => 'The Open Graph (Facebook) description.',
				),
				'og_image'            => array(
					'type'        => 'string',
					'description' => 'The Open Graph (Facebook) image URL.',
				),
				'twitter_title'       => array(
					'type'        => 'string',
					'description' => 'The Twitter card title.',
				),
				'twitter_description' => array(
					'type'        => 'string',
					'description' => 'The Twitter card description.',
				),
				'twitter_image'       => array(
					'type'        => 'string',
					'description' => 'The Twitter card image URL.',
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

		$text_map = array(
			'og_title'            => '_seopress_social_fb_title',
			'og_description'      => '_seopress_social_fb_desc',
			'twitter_title'       => '_seopress_social_twitter_title',
			'twitter_description' => '_seopress_social_twitter_desc',
		);
		foreach ( $text_map as $arg => $key ) {
			if ( isset( $arguments[ $arg ] ) ) {
				$body[ $key ] = Seo_Text::clean( (string) $arguments[ $arg ] );
			}
		}

		foreach ( self::IMAGE_URLS as $arg => $key ) {
			if ( isset( $arguments[ $arg ] ) ) {
				$url          = Seo_Url::to_send( $arg, $arguments[ $arg ] );
				$body[ $key ] = $url;
				$attachment_id                   = Image_Attachment_Id::for_url( $url );
				$body[ self::IMAGE_IDS[ $arg ] ] = $attachment_id > 0 ? (string) $attachment_id : '';
			}
		}

		self::refuse_fields_seopress_drops( $post_id, $body );
		Seopress_Stored_Meta::refuse_values_stored_as_nothing(
			array(
				'og_title'            => 'text',
				'og_description'      => 'textarea',
				'twitter_title'       => 'text',
				'twitter_description' => 'textarea',
			),
			$text_map,
			$body,
			$arguments
		);
		$before = Seopress_Stored_Meta::read( $post_id, array_keys( $body ) );

		$this->rest_request( 'PUT', '/seopress/v1/posts/' . $post_id . '/social-settings', $body );
		if ( array() !== $body ) {
			$this->clear_page_cache( array( $post_id ) );
		}

		return Seopress_Stored_Meta::reply( $post_id, $before );
	}

	private static function refuse_fields_seopress_drops( int $post_id, array $body ): void {
		if ( array() === $body ) {
			return;
		}
		$helper = array( '\SEOPress\Helpers\Metas\SocialSettings', 'getMetaKeys' );
		if ( ! is_callable( $helper ) ) {
			throw new \InvalidArgumentException( 'This SEOPress version does not say which social fields it saves (SEOPress\Helpers\Metas\SocialSettings::getMetaKeys() is missing), so nothing was sent. Nothing was changed.' );
		}
		$saved = array();
		foreach ( (array) call_user_func( $helper, $post_id ) as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
				$saved[] = $field['key'];
			}
		}
		$arguments = array(
			'_seopress_social_fb_title'       => 'og_title',
			'_seopress_social_fb_desc'        => 'og_description',
			'_seopress_social_twitter_title'  => 'twitter_title',
			'_seopress_social_twitter_desc'   => 'twitter_description',
		);
		foreach ( self::IMAGE_URLS as $arg => $key ) {
			$arguments[ $key ]                    = $arg;
			$arguments[ self::IMAGE_IDS[ $arg ] ] = $arg . ' (its Media Library image id)';
		}
		$dropped = array();
		foreach ( array_keys( $body ) as $key ) {
			if ( ! in_array( $key, $saved, true ) ) {
				$dropped[] = $arguments[ $key ] ?? (string) $key;
			}
		}
		if ( array() !== $dropped ) {
			throw new \InvalidArgumentException( sprintf( 'SEOPress does not offer %s for this post, so its social settings route would not save it. Nothing was changed.', implode( ', ', $dropped ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
