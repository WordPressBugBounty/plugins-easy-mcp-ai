<?php

namespace Easy_MCP_AI\Meta\Providers;

use Easy_MCP_AI\Meta\Meta_Field_Provider;
use Easy_MCP_AI\Tools\Post_Type_Tool_Hint;
use Easy_MCP_AI\Tools\SEO\Image_Attachment_Id;
use Easy_MCP_AI\Tools\SEO\Rankmath_Get_Post_Seo;
use Easy_MCP_AI\Tools\SEO\Rankmath_Update_Post_Seo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rank_Math_Meta_Provider extends Meta_Field_Provider {

    const X_CARD_KEYS = array( 'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_twitter_image' );

    public function get_group_slug() {
        return 'rank-math';
    }

    public function get_label() {
        return 'Rank Math SEO';
    }

    public function get_key_prefixes() {
        return array( 'rank_math_' );
    }

    public function is_active() {
        return function_exists( 'rank_math' );
    }

    public function get_dedicated_tool() {
        return 'wp_rm_update_post_seo';
    }

    public function get_dedicated_tool_class(): string {
        return Rankmath_Update_Post_Seo::class;
    }

    public function after_write( $post_id, array $keys ) {
        foreach ( Rankmath_Update_Post_Seo::SOCIAL_IMAGES as $image_keys ) {
            if ( in_array( $image_keys['url'], $keys, true ) ) {
                Image_Attachment_Id::store_for_post( (int) $post_id, $image_keys['id'], (string) get_post_meta( (int) $post_id, $image_keys['url'], true ) );
            }
        }
    }

    public function value_refusal( $key, $value, $post_id ) {
        if ( in_array( $key, self::X_CARD_KEYS, true ) && ( (int) $post_id <= 0 || current_user_can( 'edit_post', (int) $post_id ) ) && Rankmath_Update_Post_Seo::copies_facebook( (int) $post_id ) ) {
            return sprintf( '"%s" is not shown while the X card copies Facebook (Rank Math\'s default).', $key ) . ( Post_Type_Tool_Hint::may_reach( 'wp_rm_update_post_seo' ) ? ' Set it with wp_rm_update_post_seo and twitter_use_facebook: false.' : '' );
        }
        if ( ! in_array( $key, array_column( Rankmath_Update_Post_Seo::SOCIAL_IMAGES, 'url' ), true ) || null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
            return '';
        }
        $url = $this->sanitize( $key, $value );
        $id  = '' === $url ? 0 : Image_Attachment_Id::for_url( $url );
        if ( Rankmath_Update_Post_Seo::is_readable_image( $id ) ) {
            return '';
        }
        return sprintf( '"%1$s" is not a Media Library image, and Rank Math shows no other. Upload it %2$s first.', $key, Post_Type_Tool_Hint::may_reach( 'wp_upload_media_from_url' ) ? 'with wp_upload_media_from_url' : 'to the Media Library' );
    }

    public function reads_alike( $key, $stored, $other ) {
        if ( 'rank_math_advanced_robots' !== $key ) {
            return parent::reads_alike( $key, $stored, $other );
        }
        $directives = maybe_unserialize( $stored );
        $others     = maybe_unserialize( $other );
        if ( ! is_array( $directives ) || ! is_array( $others ) || array_keys( $directives ) !== array_keys( $others ) ) {
            return parent::reads_alike( $key, $stored, $other );
        }
        foreach ( $directives as $directive => $value ) {
            if ( $value !== $others[ $directive ] && ! self::print_alike( $value, $others[ $directive ] ) ) {
                return false;
            }
        }
        return true;
    }

    private static function print_alike( $value, $other ): bool {
        return self::is_number( $value ) && self::is_number( $other ) && (bool) $value === (bool) $other && (string) $value === (string) $other;
    }

    private static function is_number( $value ): bool {
        return is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) );
    }

    public function read_tool_for( $key ) {
        return in_array( (string) $key, Rankmath_Get_Post_Seo::META_KEYS, true ) ? 'wp_rm_get_post_seo' : '';
    }

    public function get_fields() {
        return array(
            'rank_math_title'                => array( 'auth' => 'rank_math_onpage_general', 'type' => 'string', 'sanitize' => 'text', 'description' => 'Rank Math SEO title.' ),
            'rank_math_description'          => array( 'auth' => 'rank_math_onpage_general', 'type' => 'string', 'sanitize' => 'text', 'description' => 'Rank Math meta description.' ),
            'rank_math_focus_keyword'        => array( 'auth' => 'rank_math_onpage_general', 'type' => 'string', 'sanitize' => 'text', 'description' => 'Rank Math focus keyword(s), comma-separated.' ),
            'rank_math_canonical_url'        => array( 'auth' => 'rank_math_onpage_advanced', 'type' => 'string', 'sanitize' => 'url', 'description' => 'Rank Math canonical URL.' ),
            'rank_math_facebook_title'       => array( 'auth' => 'rank_math_onpage_social', 'type' => 'string', 'sanitize' => 'text', 'description' => 'Rank Math Facebook / Open Graph title.' ),
            'rank_math_facebook_description' => array( 'auth' => 'rank_math_onpage_social', 'type' => 'string', 'sanitize' => 'text', 'description' => 'Rank Math Facebook / Open Graph description.' ),
            'rank_math_facebook_image'       => array( 'auth' => 'rank_math_onpage_social', 'type' => 'string', 'sanitize' => 'url', 'description' => 'Rank Math Facebook / Open Graph image URL.' ),
            'rank_math_twitter_title'        => array( 'auth' => 'rank_math_onpage_social', 'type' => 'string', 'sanitize' => 'text', 'description' => 'Rank Math Twitter card title.' ),
            'rank_math_twitter_description'  => array( 'auth' => 'rank_math_onpage_social', 'type' => 'string', 'sanitize' => 'text', 'description' => 'Rank Math Twitter card description.' ),
            'rank_math_twitter_image'        => array( 'auth' => 'rank_math_onpage_social', 'type' => 'string', 'sanitize' => 'url', 'description' => 'Rank Math Twitter card image URL.' ),
            'rank_math_robots'               => array( 'auth' => 'rank_math_onpage_advanced',
                'type'        => 'array',
                'sanitize'    => 'string_list',
                'enum'        => array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ),
                'items'       => array( 'type' => 'string', 'enum' => array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ) ),
                'description' => 'Rank Math robots directives as an array of tokens: index, noindex, nofollow, noarchive, noimageindex, nosnippet.',
            ),
            'rank_math_advanced_robots'      => array( 'auth' => 'rank_math_onpage_advanced',
                'type'        => 'object',
                'sanitize'    => 'object_keys',
                'properties'  => array(
                    'max-snippet'       => array( 'type' => array( 'integer', 'boolean' ) ),
                    'max-video-preview' => array( 'type' => array( 'integer', 'boolean' ) ),
                    'max-image-preview' => array( 'type' => array( 'string', 'boolean' ) ),
                ),
                'description' => 'Rank Math advanced robots: max-snippet, max-video-preview (integers) and max-image-preview (large, standard, none); false disables a directive, as Rank Math stores it.',
            ),
            'rank_math_breadcrumb_title'     => array( 'auth' => 'rank_math_onpage_advanced', 'type' => 'string', 'sanitize' => 'text', 'description' => 'Rank Math breadcrumb title.' ),
            'rank_math_pillar_content'       => array( 'auth' => 'rank_math_onpage_general', 'type' => 'string', 'sanitize' => 'flag:on', 'enum' => array( 'on', '' ), 'enum_is_hint' => true, 'description' => 'Rank Math pillar content flag: "on" to set, "" to clear.' ),
            'rank_math_primary_category'     => array( 'auth' => 'rank_math_onpage_general', 'type' => 'string', 'sanitize' => 'int_string', 'description' => 'Rank Math primary category: the term id as a string, "" to clear.' ),
        );
    }
}
