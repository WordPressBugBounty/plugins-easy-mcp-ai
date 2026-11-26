<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ability_Notes {

    const SLUGS = array( 'elementor/create-preview-link', 'wpforms/add-field', 'wpforms/update-field', 'rank-math/get-post-seo-meta' );

    public static function describe( string $slug ): string {
        switch ( $slug ) {
            case 'elementor/create-preview-link':
                return Post_Type_Tool_Hint::may_describe( 'wp_create_preview_link' )
                    ? 'Its url expires 5 minutes after it is made. For a link to give a person, use wp_create_preview_link where this connection has it: it previews a draft, pending, scheduled or private item for 60 minutes by default, up to 24 hours.'
                    : 'Its url expires 5 minutes after it is made.';
            case 'wpforms/add-field':
                return Post_Type_Tool_Hint::may_describe( 'wp_wpforms_move_field' )
                    ? 'It adds the field after the last one. To put it elsewhere, use wp_wpforms_move_field where this connection has it.'
                    : 'It adds the field after the last one.';
            case 'wpforms/update-field':
                return Post_Type_Tool_Hint::may_describe( 'wp_wpforms_move_field' ) && Post_Type_Tool_Hint::may_describe( 'wp_wpforms_delete_field' )
                    ? 'It does not move or delete a field: wp_wpforms_move_field and wp_wpforms_delete_field do, where this connection has them.'
                    : 'It does not move or delete a field.';
            case 'rank-math/get-post-seo-meta':
                return Post_Type_Tool_Hint::may_describe( 'wp_rm_get_post_seo' )
                    ? 'Its description is the one Rank Math prints: when none is saved it falls back to the excerpt and then the post type\'s description template, so it does not show whether a description was written. wp_rm_get_post_seo, where this connection has it, returns the saved values, rank_math_description "" when none is saved.'
                    : 'Its description is the one Rank Math prints: when none is saved it falls back to the excerpt and then the post type\'s description template, so it does not show whether a description was written.';
        }
        return '';
    }
}
