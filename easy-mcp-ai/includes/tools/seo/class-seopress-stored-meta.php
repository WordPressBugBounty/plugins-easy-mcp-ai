<?php

namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seopress_Stored_Meta {

	const NOTHING_SENT = 'No field was sent, so nothing was changed.';

	const ALREADY_STORED = 'SEOPress already stored these values, so nothing was changed.';

	public static function read( int $post_id, array $keys ): array {
		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = get_post_meta( $post_id, $key, true );
		}
		return $values;
	}

	public static function reply( int $post_id, array $before ): array {
		$reason = null;
		if ( array() === $before ) {
			$reason = self::NOTHING_SENT;
		} elseif ( self::read( $post_id, array_keys( $before ) ) === $before ) {
			$reason = self::ALREADY_STORED;
		}
		$reply = array(
			'post_id' => $post_id,
			'updated' => null === $reason,
		);
		if ( null !== $reason ) {
			$reply['reason'] = $reason;
		}
		return $reply;
	}

	public static function refuse_values_stored_as_nothing( array $readings, array $keys, array $body, array $arguments, string $route = 'metas' ): void {
		$emptied = array();
		foreach ( $readings as $argument => $reading ) {
			if ( ! array_key_exists( $keys[ $argument ], $body ) || '' === trim( self::as_text( $arguments[ $argument ] ?? '' ) ) ) {
				continue;
			}
			$kept = self::stored_form( $reading, $body[ $keys[ $argument ] ] );
			if ( 'title' !== $route ) {
				$kept = wp_unslash( $kept );
			}
			if ( '' !== $kept && ( 'metas' !== $route || ! empty( $kept ) ) ) {
				continue;
			}
			if ( 'term_id' === $reading ) {
				$why = 'not a category ID above 0';
			} else {
				$why = '' === $kept ? 'nothing left after cleaning' : '"0" reads as empty';
			}
			$emptied[ $why ][] = $argument;
		}
		if ( array() === $emptied ) {
			return;
		}
		$parts = array();
		foreach ( $emptied as $why => $names ) {
			$parts[] = implode( ', ', $names ) . ' (' . $why . ')';
		}
		throw new \InvalidArgumentException( sprintf( 'SEOPress would store nothing for: %s. Send "" to clear. Nothing was changed.', implode( '; ', $parts ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	private static function as_text( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ',', array_map( array( self::class, 'as_text' ), $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function stored_form( string $reading, $value ): string {
		$value = (string) $value;
		if ( 'text' === $reading ) {
			return _sanitize_text_fields( $value, false );
		}
		if ( 'term_id' === $reading ) {
			$value = trim( _sanitize_text_fields( $value, false ) );
			return ctype_digit( $value ) && 0 < (int) $value ? (string) (int) $value : '';
		}
		return _sanitize_text_fields( $value, true );
	}
}
