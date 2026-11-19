<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Content_Hash {

    const DESCRIPTION = ' `content_sha256` and `content_bytes` are the SHA-256 (hex) and byte length of post_content as stored: compare them with a later reply to tell whether the content changed without reading it again.';

    const WRITE_FIELDS = 'content_sha256, content_bytes (of post_content as stored)';

    public static function of_post( int $post_id, bool $fresh ): array {
        if ( $fresh ) {
            wp_cache_delete( $post_id, 'posts' );
        }
        $post = get_post( $post_id );
        return is_object( $post ) ? self::of_object( $post ) : self::none();
    }

    public static function of_object( object $post ): array {
        return isset( $post->post_content ) ? self::of( (string) $post->post_content ) : self::none();
    }

    public static function of( string $content ): array {
        return array(
            'content_sha256' => hash( 'sha256', $content ),
            'content_bytes'  => strlen( $content ),
        );
    }

    public static function none(): array {
        return array(
            'content_sha256' => null,
            'content_bytes'  => null,
        );
    }
}
