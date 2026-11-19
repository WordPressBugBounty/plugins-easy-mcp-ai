<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ability_Notes {

    const NOTES = array(
        'elementor/create-preview-link' => 'Its url expires 5 minutes after it is made. For a link to give a person, use wp_create_preview_link where this connection has it: it previews a draft, pending, scheduled or private item for 60 minutes by default, up to 24 hours.',
        'rank-math/get-post-seo-meta'   => 'Its description is the one Rank Math prints: when none is saved it falls back to the excerpt and then the post type\'s description template, so it does not show whether a description was written. wp_rm_get_post_seo, where this connection has it, returns the saved values, rank_math_description "" when none is saved.',
    );

    public static function describe( string $slug ): string {
        return self::NOTES[ $slug ] ?? '';
    }
}
