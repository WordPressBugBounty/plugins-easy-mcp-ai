<?php

namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seo_Url {

	public static function to_send( string $argument, $value ): string {
		$url = is_scalar( $value ) ? trim( (string) $value ) : null;
		if ( '' === $url ) {
			return '';
		}
		$kept = null !== $url && self::is_sendable( $url ) ? esc_url_raw( $url ) : '';
		if ( '' !== $kept && self::is_sendable( $kept ) ) {
			return $kept;
		}
		$shown = null !== $url ? wp_json_encode( (string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : false;
		throw new \InvalidArgumentException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			\Easy_MCP_AI\MCP\Refusal_Text::fit(
				'%1$s must be an http or https URL or a path starting with "/", not %2$s. Send "" to clear. Nothing was changed.',
				array( $argument, is_string( $shown ) ? $shown : 'that text' ),
				1
			)
		);
	}

	private static function is_sendable( string $url ): bool {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'http:' . $url;
		} elseif ( '/' === $url[0] ) {
			return true;
		}
		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : '';
		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host && 1 !== preg_match( '/\s/u', $host );
	}
}
