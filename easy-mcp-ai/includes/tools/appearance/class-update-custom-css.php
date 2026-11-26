<?php
namespace Easy_MCP_AI\Tools\Appearance;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Custom_Css extends Base_Tool {

    public function get_name() {
        return 'wp_update_custom_css';
    }

    public function get_description() {
        return 'Changes the active theme\'s Additional CSS through the Customizer\'s own checks. `mode`: `replace` (default) overwrites it all, `append` adds to the end. Only the active theme\'s CSS can be changed (read any theme\'s with wp_get_custom_css). CSS containing part of a `</style>` tag is refused and nothing is saved. Requires the edit_css capability (on multisite, super admins only). Every save keeps a revision. Returns { changed, stylesheet, mode, post_id, revision_id, length }; `changed` is false when WordPress stored the same CSS (and a preprocessor plugin\'s source) as before. `dry_run: true` runs the same checks and saves nothing: { dry_run: true, saved: false, stylesheet, mode, post_id, changed, length }, where `changed` says whether saving would change the CSS and `length` is that of the CSS it would save.';
    }

    public function get_category() {
        return 'appearance';
    }

    public function get_required_capability() {
        return 'edit_css';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'css'        => array(
                    'type'        => 'string',
                    'description' => 'CSS to store (replace) or to add after the existing CSS (append).',
                ),
                'mode'       => array(
                    'type'        => 'string',
                    'description' => '`replace` (default) overwrites all Additional CSS; `append` adds to the end.',
                    'enum'        => array( 'replace', 'append' ),
                    'default'     => 'replace',
                ),
                'stylesheet' => array(
                    'type'        => 'string',
                    'description' => 'Optional. Must be the active theme\'s folder name if given; other themes are refused.',
                ),
                'dry_run'    => array(
                    'type'        => 'boolean',
                    'description' => 'When true, check the CSS as a save would and report the result without saving. Default false.',
                    'default'     => false,
                ),
            ),
            'required'   => array( 'css' ),
        );
    }

    public function get_approval_policy( array $arguments ) {
        return self::is_dry_run( $arguments ) ? 'never' : 'inherit';
    }

    public function is_read_only_call( array $arguments ) {
        return self::is_dry_run( $arguments );
    }

    private static function is_dry_run( array $arguments ): bool {
        return isset( $arguments['dry_run'] ) && rest_is_boolean( $arguments['dry_run'] ) && rest_sanitize_boolean( $arguments['dry_run'] );
    }

    public function approval_details( array $arguments ): ?array {
        $css  = $arguments['css'] ?? null;
        $mode = isset( $arguments['mode'] ) && is_string( $arguments['mode'] ) && '' !== $arguments['mode'] ? strtolower( trim( $arguments['mode'] ) ) : 'replace';
        $sent = isset( $arguments['stylesheet'] ) && is_string( $arguments['stylesheet'] ) ? trim( $arguments['stylesheet'] ) : '';
        if ( ! is_string( $css ) || ! in_array( $mode, array( 'replace', 'append' ), true ) || ( '' !== $sent && get_stylesheet() !== $sent ) ) {
            return null;
        }
        $before = (string) wp_get_custom_css( get_stylesheet() );
        $after  = 'append' === $mode && '' !== $before ? $before . "\n" . $css : $css;
        return array(
            array(
                'label'  => __( 'Additional CSS', 'easy-mcp-ai' ),
                'after'  => $after,
                'before' => $before,
            ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! array_key_exists( 'css', $arguments ) || ! is_string( $arguments['css'] ) ) {
            throw new \InvalidArgumentException( 'Missing required parameter: css (a string).' );
        }
        $css = $arguments['css'];

        $mode = 'replace';
        if ( isset( $arguments['mode'] ) && '' !== $arguments['mode'] ) {
            $mode = is_string( $arguments['mode'] ) ? strtolower( trim( $arguments['mode'] ) ) : '';
            if ( ! in_array( $mode, array( 'replace', 'append' ), true ) ) {
                throw new \InvalidArgumentException( 'Invalid mode. Use "replace" or "append".' );
            }
        }

        $active = get_stylesheet();
        if ( isset( $arguments['stylesheet'] ) && '' !== $arguments['stylesheet'] ) {
            $requested = is_string( $arguments['stylesheet'] ) ? trim( $arguments['stylesheet'] ) : '';
            if ( $requested !== $active ) {
                throw new \InvalidArgumentException( sprintf( 'Only the active theme\'s Additional CSS can be changed ("%s"), as in the Customizer. "%s" is not the active theme.', $active, $requested ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }

        $dry_run = self::boolean_arg( $arguments, 'dry_run', false, 'Nothing was changed.' );

        Customizer_Settings::assert_can_customize();

        return Customizer_Settings::with_manager(
            static function ( $manager ) use ( $css, $mode, $active, $dry_run ) {
                $id      = sprintf( 'custom_css[%s]', $active );
                $setting = $manager->get_setting( $id );
                if ( ! $setting ) {
                    throw new \RuntimeException( 'Additional CSS is not available on this site: the Customizer setting "' . $id . '" is not registered (a plugin may have removed it).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                if ( $setting->capability && ! current_user_can( $setting->capability ) ) {
                    throw new \RuntimeException( sprintf( 'You do not have permission to edit Additional CSS on this site (it requires the "%s" capability).', is_array( $setting->capability ) ? implode( ', ', $setting->capability ) : $setting->capability ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                if ( ! $setting->check_capabilities() ) {
                    throw new \RuntimeException( 'Additional CSS belongs to a theme feature the active theme does not support, so the Customizer does not offer it.' );
                }

                $existing = 'append' === $mode || $dry_run ? (string) $setting->value() : '';
                $value    = 'append' === $mode && '' !== $existing ? $existing . "\n" . $css : $css;

                if ( $dry_run ) {
                    Customizer_Settings::validate_value( $manager, $setting, $value );
                    $sanitized = $setting->sanitize( $value );
                    $stored    = empty( $sanitized ) ? '' : (string) $sanitized;
                    $post   = wp_get_custom_css_post( $active );
                    return array(
                        'dry_run'    => true,
                        'saved'      => false,
                        'stylesheet' => $active,
                        'mode'       => $mode,
                        'post_id'    => $post ? (int) $post->ID : null,
                        'changed'    => $stored !== $existing,
                        'length'     => strlen( $stored ),
                    );
                }

                $before        = self::stored_css( wp_get_custom_css_post( $active ) );
                $saved_post_id = 0;
                $listener      = static function ( $post_id ) use ( &$saved_post_id ) {
                    $saved_post_id = (int) $post_id;
                };
                add_action( 'save_post_custom_css', $listener, 10, 1 );
                try {
                    Customizer_Settings::validate_and_save( $manager, $setting, $value );
                } finally {
                    remove_action( 'save_post_custom_css', $listener, 10 );
                }
                if ( $saved_post_id <= 0 ) {
                    throw new \RuntimeException( 'WordPress did not save the Additional CSS.' );
                }

                Customizer_Settings::after_save( $manager );

                $revision_id = null;
                $revisions   = wp_get_latest_revision_id_and_total_count( $saved_post_id );
                if ( is_array( $revisions ) && ! empty( $revisions['latest_id'] ) ) {
                    $revision_id = (int) $revisions['latest_id'];
                }
                $post = get_post( $saved_post_id );

                return array(
                    'changed'     => self::stored_css( $post ) !== $before,
                    'stylesheet'  => $active,
                    'mode'        => $mode,
                    'post_id'     => $saved_post_id,
                    'revision_id' => $revision_id,
                    'length'      => $post ? strlen( (string) $post->post_content ) : 0,
                );
            }
        );
    }

    private static function stored_css( $post ): array {
        if ( ! is_object( $post ) ) {
            return array( '', '' );
        }
        return array(
            isset( $post->post_content ) ? (string) $post->post_content : '',
            isset( $post->post_content_filtered ) ? (string) $post->post_content_filtered : '',
        );
    }
}
