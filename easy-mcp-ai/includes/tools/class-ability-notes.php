<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ability_Notes {

    const NOTES = array(
        'elementor/create-preview-link' => 'Its url expires 5 minutes after it is made. For a link to give a person, use wp_create_preview_link where this connection has it: it previews a draft, pending, scheduled or private item for 60 minutes by default, up to 24 hours.',
        'wpforms/add-field'             => 'It adds the field after the last one. To put it elsewhere, use wp_wpforms_move_field where this connection has it.',
        'wpforms/update-field'          => 'It does not move or delete a field: wp_wpforms_move_field and wp_wpforms_delete_field do, where this connection has them.',
        'rank-math/get-post-seo-meta'   => 'Its description is the one Rank Math prints: when none is saved it falls back to the excerpt and then the post type\'s description template, so it does not show whether a description was written. wp_rm_get_post_seo, where this connection has it, returns the saved values, rank_math_description "" when none is saved.',
    );

    public static function describe( string $slug ): string {
        return self::NOTES[ $slug ] ?? '';
    }
}
