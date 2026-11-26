<?php
namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Rank_Math_Setup {

	protected function require_rank_math_ready( string $purpose ): void {
		if ( \Easy_MCP_AI\Tools\Integration_State::rank_math_unloaded() ) {
			throw new \RuntimeException( sprintf( 'Rank Math SEO is not active on this site. Install and activate Rank Math SEO to %s.', $purpose ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( \Easy_MCP_AI\Tools\Integration_State::rank_math_setup_unfinished() ) {
			throw new \RuntimeException( 'Rank Math is installed but its setup is not finished, so it loads none of its modules. Connect or skip the account step of its setup wizard under Rank Math in wp-admin.' );
		}
	}
}
