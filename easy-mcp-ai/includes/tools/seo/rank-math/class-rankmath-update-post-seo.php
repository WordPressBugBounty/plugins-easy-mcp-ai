<?php

namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Easy_MCP_AI\MCP\Detailed_Tool_Error;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Post_Type_Tool_Hint;

class Rankmath_Update_Post_Seo extends Base_Tool {

	const SOCIAL_IMAGES = array(
		'facebook' => array(
			'url' => 'rank_math_facebook_image',
			'id'  => 'rank_math_facebook_image_id',
		),
		'twitter'  => array(
			'url' => 'rank_math_twitter_image',
			'id'  => 'rank_math_twitter_image_id',
		),
	);

	const TWITTER_OWN_FIELDS = array( 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_id', 'twitter_enable_image_overlay', 'twitter_image_overlay' );

	const SWITCHES = array(
		'twitter_use_facebook'          => 'rank_math_twitter_use_facebook',
		'facebook_enable_image_overlay' => 'rank_math_facebook_enable_image_overlay',
		'twitter_enable_image_overlay'  => 'rank_math_twitter_enable_image_overlay',
	);

	const OVERLAYS = array(
		'facebook_image_overlay' => 'rank_math_facebook_image_overlay',
		'twitter_image_overlay'  => 'rank_math_twitter_image_overlay',
	);

	const ADVANCED_ROBOTS = array( 'max-snippet', 'max-video-preview', 'max-image-preview' );

	public function get_name() {
		return 'wp_rm_update_post_seo';
	}

	public function get_description() {
		return 'Updates Rank Math SEO fields on a post by writing to the Rank Math postmeta keys. Fields: title (rank_math_title), description (rank_math_description), focus_keyword (rank_math_focus_keyword), canonical_url (rank_math_canonical_url), facebook_title, facebook_description, facebook_image or facebook_image_id, twitter_title, twitter_description, twitter_image or twitter_image_id, twitter_use_facebook, facebook_enable_image_overlay, facebook_image_overlay, twitter_enable_image_overlay, twitter_image_overlay, robots (array of index/noindex/nofollow/noarchive/noimageindex/nosnippet), advanced_robots (array), breadcrumb_title, primary_term + taxonomy, pillar_content (boolean). Rank Math builds a post\'s social image from a Media Library attachment and never prints an image URL, so an image URL must be a Media Library image (its attachment ID is stored with it) and is refused otherwise. While twitter_use_facebook is on (Rank Math\'s default), the X card copies the Facebook title, description, image and overlay, so a twitter_* field is refused unless the call also sends twitter_use_facebook: false or the post already has it off. Returns { post_id, updated_fields, fields }: `fields` is the stored value of every field sent, read back after the write (robots and advanced_robots as arrays, pillar_content, twitter_use_facebook and the overlay switches as booleans, the image IDs as integers), so a value re-sent unchanged, which updated_fields leaves out, is confirmed too.';
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
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'openWorldHint'   => false,
		);
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'              => array(
					'type'        => 'integer',
					'description' => 'The ID of the post.',
				),
				'title'                => array(
					'type'        => 'string',
					'description' => 'The SEO title (rank_math_title).',
				),
				'description'          => array(
					'type'        => 'string',
					'description' => 'The meta description (rank_math_description).',
				),
				'focus_keyword'        => array(
					'type'        => 'string',
					'description' => 'The focus keyword (rank_math_focus_keyword).',
				),
				'canonical_url'        => array(
					'type'        => 'string',
					'description' => 'The canonical URL (rank_math_canonical_url).',
				),
				'facebook_title'       => array(
					'type'        => 'string',
					'description' => 'The Facebook/Open Graph title (rank_math_facebook_title).',
				),
				'facebook_description' => array(
					'type'        => 'string',
					'description' => 'The Facebook/Open Graph description (rank_math_facebook_description).',
				),
				'twitter_title'        => array(
					'type'        => 'string',
					'description' => 'The Twitter card title (rank_math_twitter_title).',
				),
				'twitter_description'  => array(
					'type'        => 'string',
					'description' => 'The Twitter card description (rank_math_twitter_description).',
				),
				'facebook_image'       => array(
					'type'        => 'string',
					'description' => 'The Facebook/Open Graph image: the URL of a Media Library image, or of one of its sizes (rank_math_facebook_image, with its attachment ID in rank_math_facebook_image_id). "" removes the image. A URL that is not a Media Library image is refused.',
				),
				'facebook_image_id'    => array(
					'type'        => 'integer',
					'description' => 'The Facebook/Open Graph image as a Media Library attachment ID (rank_math_facebook_image_id, with its URL in rank_math_facebook_image). 0 removes the image.',
				),
				'twitter_image'        => array(
					'type'        => 'string',
					'description' => 'The X card image: the URL of a Media Library image, or of one of its sizes (rank_math_twitter_image, with its attachment ID in rank_math_twitter_image_id). "" removes the image. Needs twitter_use_facebook off.',
				),
				'twitter_image_id'     => array(
					'type'        => 'integer',
					'description' => 'The X card image as a Media Library attachment ID (rank_math_twitter_image_id). 0 removes the image. Needs twitter_use_facebook off.',
				),
				'twitter_use_facebook' => array(
					'type'        => 'boolean',
					'description' => 'Whether the X card copies the Facebook title, description, image and overlay (rank_math_twitter_use_facebook). Rank Math copies them until this is false.',
				),
				'facebook_enable_image_overlay' => array(
					'type'        => 'boolean',
					'description' => 'Whether Rank Math draws an icon over the Facebook image (rank_math_facebook_enable_image_overlay).',
				),
				'facebook_image_overlay' => array(
					'type'        => 'string',
					'description' => 'The icon drawn over the Facebook image while the overlay is on (rank_math_facebook_image_overlay): one of Rank Math\'s overlay images, "play" and "gif" by default.',
				),
				'twitter_enable_image_overlay' => array(
					'type'        => 'boolean',
					'description' => 'Whether Rank Math draws an icon over the X card image (rank_math_twitter_enable_image_overlay). Needs twitter_use_facebook off.',
				),
				'twitter_image_overlay' => array(
					'type'        => 'string',
					'description' => 'The icon drawn over the X card image while its overlay is on (rank_math_twitter_image_overlay): "play", "gif" or another of Rank Math\'s overlay images. Needs twitter_use_facebook off.',
				),
				'robots'               => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Robots meta (rank_math_robots) as an array of tokens. Allowed: index, noindex, nofollow, noarchive, noimageindex, nosnippet. A comma-separated string is also accepted and split on ",".',
				),
				'advanced_robots'      => array(
					'type'        => 'object',
					'description' => 'Advanced robots (rank_math_advanced_robots): an object with any of max-snippet, max-video-preview and max-image-preview, each a number, text or false (off). Any other key is refused.',
				),
				'breadcrumb_title'     => array(
					'type'        => 'string',
					'description' => 'The breadcrumb title (rank_math_breadcrumb_title).',
				),
				'primary_term'         => array(
					'type'        => 'integer',
					'description' => 'The primary term ID (rank_math_primary_{taxonomy}). Requires taxonomy.',
				),
				'taxonomy'             => array(
					'type'        => 'string',
					'description' => 'The taxonomy slug for primary_term (e.g. category). Required when primary_term is set.',
				),
				'pillar_content'       => array(
					'type'        => 'boolean',
					'description' => 'Whether this is pillar content (rank_math_pillar_content).',
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	public function precheck( array $arguments ): void {
		if ( ! function_exists( 'rank_math' ) ) {
			return;
		}
		self::robots_tokens( $arguments );
		self::advanced_robots( $arguments );
		$this->primary_term( $arguments );
		self::social_images( $arguments );
		self::overlays( $arguments );
		if ( isset( $arguments['canonical_url'] ) ) {
			Seo_Url::to_send( 'canonical_url', $arguments['canonical_url'] );
		}
		$post_id = isset( $arguments['post_id'] ) && is_scalar( $arguments['post_id'] ) ? (int) $arguments['post_id'] : 0;
		if ( $post_id > 0 && get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
			self::refuse_copied_twitter_fields( $post_id, $arguments );
		}
	}

	public function execute( array $arguments ) {
		if ( ! function_exists( 'rank_math' ) ) {
			throw new \RuntimeException( 'Rank Math SEO is not active on this site. Please install and activate Rank Math SEO to use this tool.' );
		}

		$post_id = $this->parse_required_id( $arguments['post_id'] ?? null, 'post_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new \InvalidArgumentException( 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new \RuntimeException( 'You do not have permission to edit this post.' );
		}

		$robots       = self::robots_tokens( $arguments );
		$advanced     = self::advanced_robots( $arguments );
		$primary_term = $this->primary_term( $arguments );
		$images       = self::social_images( $arguments );
		$overlays     = self::overlays( $arguments );
		self::refuse_copied_twitter_fields( $post_id, $arguments );
		$canonical = isset( $arguments['canonical_url'] ) ? Seo_Url::to_send( 'canonical_url', $arguments['canonical_url'] ) : null;

		$meta_map = array(
			'title'                => 'rank_math_title',
			'description'          => 'rank_math_description',
			'focus_keyword'        => 'rank_math_focus_keyword',
			'canonical_url'        => 'rank_math_canonical_url',
			'facebook_title'       => 'rank_math_facebook_title',
			'facebook_description' => 'rank_math_facebook_description',
			'twitter_title'        => 'rank_math_twitter_title',
			'twitter_description'  => 'rank_math_twitter_description',
		);

		$updated = array();

		foreach ( $meta_map as $arg_key => $meta_key ) {
			if ( isset( $arguments[ $arg_key ] ) ) {
				$value = 'canonical_url' === $arg_key ? $canonical : Seo_Text::clean( $arguments[ $arg_key ] );
				if ( Seo_Post_Meta::update( $post_id, $meta_key, wp_slash( $value ) ) ) {
					$updated[] = $arg_key;
				}
			}
		}

		foreach ( $images as $network => $image ) {
			if ( self::write_image( $post_id, self::SOCIAL_IMAGES[ $network ], $image ) ) {
				array_push( $updated, ...$image['fields'] );
			}
		}

		$writes = array();
		if ( null !== $robots ) {
			$writes['robots'] = array( 'rank_math_robots', $robots );
		}
		if ( null !== $advanced ) {
			$writes['advanced_robots'] = array( 'rank_math_advanced_robots', wp_slash( $advanced ) );
		}
		if ( isset( $arguments['breadcrumb_title'] ) ) {
			$writes['breadcrumb_title'] = array( 'rank_math_breadcrumb_title', wp_slash( Seo_Text::clean( $arguments['breadcrumb_title'] ) ) );
		}
		if ( isset( $arguments['pillar_content'] ) ) {
			$writes['pillar_content'] = array( 'rank_math_pillar_content', (bool) $arguments['pillar_content'] ? 'on' : '' );
		}
		if ( null !== $primary_term ) {
			$writes['primary_term'] = array( 'rank_math_primary_' . $primary_term['taxonomy'], (string) $primary_term['term'] );
		}
		foreach ( self::SWITCHES as $field => $meta_key ) {
			if ( isset( $arguments[ $field ] ) ) {
				$writes[ $field ] = array( $meta_key, (bool) $arguments[ $field ] ? 'on' : 'off' );
			}
		}
		foreach ( $overlays as $field => $overlay ) {
			$writes[ $field ] = array( self::OVERLAYS[ $field ], $overlay );
		}
		foreach ( $writes as $field => $write ) {
			if ( Seo_Post_Meta::update( $post_id, $write[0], $write[1] ) ) {
				$updated[] = $field;
			}
		}

		if ( array() !== $updated ) {
			$this->clear_page_cache( array( $post_id ) );
		}

		return array(
			'post_id'        => $post_id,
			'updated_fields' => $updated,
			'fields'         => self::stored_fields( $post_id, $arguments, $meta_map ),
		);
	}

	private static function robots_tokens( array $arguments ): ?array {
		if ( ! isset( $arguments['robots'] ) ) {
			return null;
		}
		$allowed_robots = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
		$robots_in      = $arguments['robots'];
		if ( is_string( $robots_in ) ) {
			$robots_in = explode( ',', $robots_in );
		}
		if ( ! is_array( $robots_in ) ) {
			throw new \InvalidArgumentException( 'robots must be an array of tokens or a comma-separated string.' );
		}
		$robots = array();
		foreach ( $robots_in as $token ) {
			$token = sanitize_text_field( trim( (string) $token ) );
			if ( '' === $token ) {
				continue;
			}
			if ( ! in_array( $token, $allowed_robots, true ) ) {
				throw new \InvalidArgumentException( 'robots tokens must be among: ' . implode( ', ', $allowed_robots ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( ! in_array( $token, $robots, true ) ) {
				$robots[] = $token;
			}
		}
		return $robots;
	}

	private static function advanced_robots( array $arguments ): ?array {
		if ( ! isset( $arguments['advanced_robots'] ) ) {
			return null;
		}
		$advanced = $arguments['advanced_robots'];
		if ( ! is_array( $advanced ) ) {
			throw new \InvalidArgumentException( 'advanced_robots must be an object/array.' );
		}
		foreach ( $advanced as $directive => $value ) {
			if ( ! in_array( (string) $directive, self::ADVANCED_ROBOTS, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'advanced_robots keys must be among: %s, not "%s".', implode( ', ', self::ADVANCED_ROBOTS ), $directive ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'advanced_robots %s must be a number, text or false, not a list or an object.', $directive ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		return $advanced;
	}

	private function primary_term( array $arguments ): ?array {
		if ( ! isset( $arguments['primary_term'] ) ) {
			return null;
		}
		if ( empty( $arguments['taxonomy'] ) ) {
			throw new \InvalidArgumentException( 'taxonomy is required when primary_term is provided.' );
		}
		$taxonomy = $this->validate_rest_route_segment( $arguments['taxonomy'], 'taxonomy' );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			throw new \InvalidArgumentException( 'Unknown taxonomy: ' . $taxonomy ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$primary_term = (int) $arguments['primary_term'];
		if ( ! term_exists( $primary_term, $taxonomy ) ) {
			throw new \InvalidArgumentException( 'primary_term does not exist in taxonomy ' . $taxonomy . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return array(
			'taxonomy' => $taxonomy,
			'term'     => $primary_term,
		);
	}

	private static function social_images( array $arguments ): array {
		$images = array();
		foreach ( array_keys( self::SOCIAL_IMAGES ) as $network ) {
			$url_field = $network . '_image';
			$id_field  = $network . '_image_id';
			$fields    = self::sent( array( $url_field, $id_field ), $arguments );
			if ( array() === $fields ) {
				continue;
			}
			$from_url = isset( $arguments[ $url_field ] ) ? self::image_from_url( $arguments[ $url_field ], $url_field, $id_field ) : null;
			$id       = isset( $arguments[ $id_field ] ) ? self::image_id( $arguments[ $id_field ], $id_field ) : null;
			if ( null !== $from_url && null !== $id && $from_url['id'] !== $id ) {
				throw new \InvalidArgumentException( sprintf( '%1$s and %2$s name different images. Send one of them. Nothing was changed.', $url_field, $id_field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$attachment         = null !== $from_url ? $from_url['id'] : (int) $id;
			$images[ $network ] = array(
				'fields' => $fields,
				'id'     => $attachment,
				'url'    => null !== $from_url ? $from_url['url'] : ( $attachment > 0 ? (string) wp_get_attachment_url( $attachment ) : '' ),
			);
		}
		return $images;
	}

	private static function image_from_url( $value, string $field, string $id_field ): array {
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%s must be the URL of a Media Library image, or "" to remove it. Nothing was changed.', $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( '' === trim( $value ) ) {
			return array(
				'url' => '',
				'id'  => 0,
			);
		}
		$url = esc_url_raw( $value );
		$id  = '' === $url ? 0 : Image_Attachment_Id::for_url( $url );
		if ( ! self::is_readable_image( $id ) ) {
			throw new Detailed_Tool_Error(
				sprintf( '%1$s is not a Media Library image, and Rank Math shows no other. Nothing was changed. Upload it %2$s, or send %3$s.', $field, Post_Type_Tool_Hint::may_reach( 'wp_upload_media_from_url' ) ? 'with wp_upload_media_from_url' : 'to the Media Library', $id_field ),
				sprintf( '%1$s: %2$s is not an image in this site\'s Media Library that you can read. Rank Math builds the social image from the attachment and never prints an image URL, so the page would keep its current image.', $field, '' === $url ? substr( $value, 0, 100 ) : $url )
			);
		}
		return array(
			'url' => $url,
			'id'  => $id,
		);
	}

	private static function image_id( $value, string $field ): int {
		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			throw new \InvalidArgumentException( sprintf( '%s must be the attachment ID of an image in the Media Library (an integer), or 0 to remove it. Nothing was changed.', $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 0 === $value ) {
			return 0;
		}
		if ( ! self::is_readable_image( $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%1$s: %2$d is not the ID of an image in the Media Library. Nothing was changed.', $field, $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $value;
	}

	public static function is_readable_image( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		$attachment = get_post( $id );
		return $attachment && 'attachment' === $attachment->post_type && wp_attachment_is_image( $id ) && current_user_can( 'read_post', $id );
	}

	private static function overlays( array $arguments ): array {
		$overlays = array();
		foreach ( array_keys( self::OVERLAYS ) as $field ) {
			if ( ! isset( $arguments[ $field ] ) ) {
				continue;
			}
			$names = self::overlay_names();
			if ( array() === $names ) {
				throw new \InvalidArgumentException( sprintf( '%s: Rank Math offers no overlay images on this site. Nothing was changed.', $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( ! is_string( $arguments[ $field ] ) || ! in_array( $arguments[ $field ], $names, true ) ) {
				throw new \InvalidArgumentException( sprintf( '%1$s must be one of Rank Math\'s overlay images: %2$s. Nothing was changed.', $field, implode( ', ', $names ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$overlays[ $field ] = $arguments[ $field ];
		}
		return $overlays;
	}

	private static function overlay_names(): array {
		if ( ! is_callable( array( '\RankMath\Helper', 'choices_overlay_images' ) ) ) {
			return array( 'play', 'gif' );
		}
		$choices = \RankMath\Helper::choices_overlay_images( 'names' );
		return is_array( $choices ) ? array_values( array_filter( array_map( 'strval', array_keys( $choices ) ) ) ) : array();
	}

	public static function copies_facebook( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return true;
		}
		$meta_id = (int) ( wp_is_post_revision( $post_id ) ?: $post_id );
		return ! in_array( get_post_meta( $meta_id, 'rank_math_twitter_use_facebook', true ), array( 'off', 'false' ), true );
	}

	private static function refuse_copied_twitter_fields( int $post_id, array $arguments ): void {
		$sent = self::sent( self::TWITTER_OWN_FIELDS, $arguments );
		if ( array() === $sent ) {
			return;
		}
		$copies = isset( $arguments['twitter_use_facebook'] ) ? (bool) $arguments['twitter_use_facebook'] : self::copies_facebook( $post_id );
		if ( $copies ) {
			throw new Detailed_Tool_Error(
				'The X card copies the Facebook fields while twitter_use_facebook is on, Rank Math\'s default. Nothing was changed. Send twitter_use_facebook: false with the X card fields.',
				sprintf( 'Refused: %s. While rank_math_twitter_use_facebook is on (empty, "on", or anything but "off" or "false"), Rank Math builds the X card from the Facebook title, description, image and overlay, so these would not reach the page.', implode( ', ', $sent ) )
			);
		}
	}

	private static function sent( array $fields, array $arguments ): array {
		return array_values(
			array_filter(
				$fields,
				static function ( string $field ) use ( $arguments ): bool {
					return isset( $arguments[ $field ] );
				}
			)
		);
	}

	private static function write_image( int $post_id, array $keys, array $image ): bool {
		$before = self::stored_image( $post_id, $keys );
		if ( 0 === $image['id'] ) {
			delete_post_meta( $post_id, $keys['url'] );
			delete_post_meta( $post_id, $keys['id'] );
		} else {
			update_post_meta( $post_id, $keys['url'], $image['url'] );
			update_post_meta( $post_id, $keys['id'], (string) $image['id'] );
		}
		return self::stored_image( $post_id, $keys ) !== $before;
	}

	private static function stored_image( int $post_id, array $keys ): array {
		$meta_id = (int) ( wp_is_post_revision( $post_id ) ?: $post_id );
		return array( get_post_meta( $meta_id, $keys['url'], true ), get_post_meta( $meta_id, $keys['id'], true ) );
	}

	private static function stored_fields( int $post_id, array $arguments, array $text_keys ): array {
		$keys = array_merge(
			$text_keys,
			array(
				'robots'           => 'rank_math_robots',
				'advanced_robots'  => 'rank_math_advanced_robots',
				'breadcrumb_title' => 'rank_math_breadcrumb_title',
				'pillar_content'   => 'rank_math_pillar_content',
			),
			self::SWITCHES,
			self::OVERLAYS
		);
		foreach ( self::SOCIAL_IMAGES as $network => $image_keys ) {
			$keys[ $network . '_image' ]    = $image_keys['url'];
			$keys[ $network . '_image_id' ] = $image_keys['id'];
		}
		if ( isset( $arguments['primary_term'], $arguments['taxonomy'] ) ) {
			$keys['primary_term'] = 'rank_math_primary_' . trim( (string) $arguments['taxonomy'] );
		}
		$meta_id = (int) ( wp_is_post_revision( $post_id ) ?: $post_id );
		$fields  = array();
		foreach ( $keys as $field => $meta_key ) {
			if ( ! isset( $arguments[ $field ] ) ) {
				continue;
			}
			$value = get_post_meta( $meta_id, $meta_key, true );
			if ( 'pillar_content' === $field || isset( self::SWITCHES[ $field ] ) ) {
				$value = 'on' === $value;
			} elseif ( in_array( $field, array( 'robots', 'advanced_robots' ), true ) ) {
				$value = is_array( $value ) ? $value : array();
			} elseif ( in_array( $field, array( 'facebook_image_id', 'twitter_image_id' ), true ) ) {
				$value = (int) $value;
			} else {
				$value = (string) $value;
			}
			$fields[ $field ] = $value;
		}
		return $fields;
	}
}
