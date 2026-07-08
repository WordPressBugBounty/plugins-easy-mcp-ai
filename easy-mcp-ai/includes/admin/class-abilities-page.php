<?php
namespace Easy_MCP_AI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Abilities_Page {

	public function __construct() {
		\add_action( 'admin_menu', array( $this, 'register_menu' ) );
		\add_action( 'admin_init', array( $this, 'handle_form_actions' ) );
	}

	public function register_menu() {
		\add_submenu_page(
			'easy-mcp-ai',
			__( 'Abilities', 'easy-mcp-ai' ),
			__( 'Abilities', 'easy-mcp-ai' ),
			'manage_options',
			'easy-mcp-ai-abilities',
			array( $this, 'render_page' )
		);
	}

	public function handle_form_actions() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['easy_mcp_ai_save_abilities'] ) && \check_admin_referer( 'easy_mcp_ai_save_abilities' ) ) {
			
			
			$rendered_raw      = isset( $_POST['abilities_rendered'] ) ? wp_unslash( $_POST['abilities_rendered'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded + sanitized in parse_rendered_field().
			$abilities_on_page = self::parse_rendered_field( $rendered_raw );
			$checked_on_page   = self::resolve_checked_set( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed + sanitized inside resolve_checked_set().
			$this->handle_save_abilities( $abilities_on_page, $checked_on_page );
		}
	}

	private function handle_save_abilities( array $abilities_on_page, array $checked_on_page ) {
		$enabled_abilities = (array) \get_option( 'easy_mcp_ai_enabled_abilities', array() );
		$new_enabled       = self::compute_enabled( $enabled_abilities, $abilities_on_page, $checked_on_page );
		\update_option( 'easy_mcp_ai_enabled_abilities', $new_enabled );

		$redirect_url = \add_query_arg(
			array(
				'page'    => 'easy-mcp-ai-abilities',
				'message' => 'saved',
			),
			\admin_url( 'admin.php' )
		);

		\wp_safe_redirect( $redirect_url );
		exit;
	}

	







	public static function parse_rendered_field( $raw ) {
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_text_field', array_filter( $decoded, 'is_string' ) ) ) );
	}

	










	public static function resolve_checked_set( array $post ) {
		if ( isset( $post['enabled_abilities_json'] ) && '' !== $post['enabled_abilities_json'] ) {
			return self::parse_rendered_field( wp_unslash( $post['enabled_abilities_json'] ) );
		}
		if ( isset( $post['enabled_abilities'] ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( (array) $post['enabled_abilities'] ) ) ) );
		}
		return array();
	}

	







	public static function ability_annotations( $ability ) {
		if ( method_exists( $ability, 'get_meta_item' ) ) {
			return (array) $ability->get_meta_item( 'annotations' );
		}
		if ( method_exists( $ability, 'get_annotations' ) ) {
			return (array) $ability->get_annotations();
		}
		return array();
	}

	










	public static function compute_enabled( array $current_enabled, array $rendered, array $checked ) {
		$new = array_diff( $current_enabled, $rendered );
		$new = array_merge( $new, $checked );
		return array_values( array_unique( $new ) );
	}

	public function render_page() {
		$has_abilities_api = \function_exists( 'wp_get_abilities' );
		$enabled_abilities = (array) \get_option( 'easy_mcp_ai_enabled_abilities', array() );
		$message           = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		
		
		$flat_abilities = array();

		if ( $has_abilities_api ) {
			$all_abilities = \wp_get_abilities();
			foreach ( $all_abilities as $ability ) {
				$name   = $ability->get_name();
				$parts  = explode( '/', $name, 2 );
				$prefix = count( $parts ) > 1 ? $parts[0] : 'core';

				$flat_abilities[] = array(
					'prefix'  => $prefix,
					'ability' => $ability,
				);
			}
		}

		require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/admin/views/abilities.php';
	}
}
