<?php

namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seo_Text {

	public static function clean( $value ) {
		$filtered = is_object( $value ) || is_array( $value ) ? '' : self::text( (string) $value );

		return apply_filters( 'sanitize_text_field', $filtered, $value );
	}

	private static function text( string $text ): string {
		$filtered = wp_check_invalid_utf8( $text );

		if ( false !== strpos( $filtered, '<' ) ) {
			$filtered = wp_pre_kses_less_than( $filtered );
			$filtered = wp_strip_all_tags( $filtered, false );
			$filtered = str_replace( "<\n", "&lt;\n", $filtered );
		}

		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) );
	}
}
