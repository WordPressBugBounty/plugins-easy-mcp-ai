<?php
namespace Easy_MCP_AI\Tools\Templates;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Post_Type_Tool_Hint;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Template extends Base_Tool {

    public function get_name() {
        return 'wp_update_template';
    }

    public function get_description() {
        return 'Updates the content of a block template or template part. `type` selects `template` (default) or `template_part` (header, footer and other reusable areas); the ID format is `theme-slug//slug` for both. '
            . ( Post_Type_Tool_Hint::may_describe( 'wp_get_template' ) ? 'Read the current content with wp_get_template first — the new content replaces it entirely.' : 'The new content replaces the current content entirely.' )
            . ' Requires an active block theme (Full Site Editing).';
    }

    public function get_category() {
        return 'templates';
    }

    public function get_required_capability() {
        return 'edit_theme_options';
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
                'template_id' => array(
                    'type'        => 'string',
                    'description' => 'The template or template part ID (e.g. theme-slug//template-slug).',
                ),
                'type'        => array(
                    'type'        => 'string',
                    'description' => 'Whether `template_id` names a `template` (default) or a `template_part`.',
                    'enum'        => array( 'template', 'template_part' ),
                    'default'     => 'template',
                ),
                'content'     => array(
                    'type'        => 'string',
                    'description' => 'New block markup content for the template or template part.',
                ),
            ),
            'required'   => array( 'template_id', 'content' ),
        );
    }

    public function execute( array $arguments ) {
        $type  = Template_Type::resolve( $arguments );
        $label = Template_Type::label( $type );

        if ( ! wp_is_block_theme() ) {
            throw new \RuntimeException( sprintf( '%s are not available. This requires an active block theme (Full Site Editing). The current theme is a classic theme.', $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $this->validate_required( $arguments, array( 'template_id' ) );
        if ( ! isset( $arguments['content'] ) ) {
            throw new \InvalidArgumentException( 'Missing required parameters: content' );
        }

        $template_id = Template_Id::validate( $arguments['template_id'] );

        $request = new \WP_REST_Request( 'POST', '/wp/v2/' . Template_Type::rest_base( $type ) . '/' . $template_id );
        $request->set_param( 'content', $arguments['content'] );

        $saved     = array();
        $post_type = Template_Type::TEMPLATE_PART === $type ? 'wp_template_part' : 'wp_template';
        $record    = static function ( $post_id ) use ( &$saved ) {
            $saved[] = (int) $post_id;
        };
        add_action( 'save_post_' . $post_type, $record, PHP_INT_MAX, 1 );
        try {
            $response = rest_do_request( $request );
        } finally {
            remove_action( 'save_post_' . $post_type, $record, PHP_INT_MAX );
        }

        if ( $response->is_error() ) {
            $wp_error          = $response->as_error();
            $block_theme_codes = array( 'rest_no_route', 'rest_cannot_manage_templates' );
            if ( in_array( $wp_error->get_error_code(), $block_theme_codes, true ) ) {
                throw new \RuntimeException(
                    sprintf( '%s endpoint is not available. This requires an active block theme (Full Site Editing). The current theme appears to be a classic theme.', $label ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                );
            }
            throw new \RuntimeException( $wp_error->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $data = $response->get_data();
        if ( empty( $data['wp_id'] ) || ! in_array( (int) $data['wp_id'], $saved, true ) ) {
            throw new \RuntimeException( sprintf( 'WordPress did not save this %s, so its content is unchanged. It keeps no template whose title, description and content are all empty after cleaning; otherwise the save failed.', Template_Type::TEMPLATE_PART === $type ? 'template part' : 'template' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return array(
            'id'     => $data['id'],
            'slug'   => $data['slug'],
            'title'  => $data['title']['raw'] ?? wp_strip_all_tags( $data['title']['rendered'] ?? '' ),
            'status' => $data['status'],
        );
    }
}
