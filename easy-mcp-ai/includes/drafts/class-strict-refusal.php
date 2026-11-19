<?php

namespace Easy_MCP_AI\Drafts;

use Easy_MCP_AI\MCP\Detailed_Runtime_Error;
use Easy_MCP_AI\Tools\Posts\Duplicate_Post;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Strict_Refusal extends Detailed_Runtime_Error {

    const LEAD = 'Draft mode is Strict on this site, so the AI cannot publish or change published content.';

    const SCOPE = 'Under Strict, a tool call cannot publish, schedule or make private any post, page, product or other content, or edit or delete content that is published, scheduled or private. A person publishes in WordPress. An administrator can change Draft mode in the plugin\'s settings.';

    public static function for_save( int $post_id, string $from, string $to ): self {
        $cause = Strict_Ceiling::is_live_status( $from )
            ? sprintf( 'Post %1$d is %2$s, and this call would save it.', $post_id, self::status_words( $from ) )
            : sprintf( 'Post %1$d is %2$s, and this call would save it as %3$s.', $post_id, self::status_words( $from ), self::status_words( $to, 'status "%s"' ) );
        return self::with_cause( $cause, $post_id );
    }

    public static function for_delete( int $post_id, string $status ): self {
        return self::with_cause( sprintf( 'Post %1$d is %2$s, and this call would delete it.', $post_id, self::status_words( $status ) ), $post_id );
    }

    public static function for_child( int $post_id, int $parent_id ): self {
        return self::with_cause(
            sprintf( 'This call would delete variation %1$d of published product %2$d, which customers see at once.', $post_id, $parent_id ),
            0,
            'A person changes the published product in WordPress.'
        );
    }

    public static function for_user( int $user_id, int $post_id ): self {
        return self::with_cause(
            sprintf( 'User %1$d is the author of live content (post %2$d), which removing the user would delete or give to another author.', $user_id, $post_id ),
            0,
            'A person removes this user in WordPress.'
        );
    }

    public static function for_answer( string $answer, int $post_id ): self {
        return self::with_cause( sprintf( 'WordPress answered: "%s"', $answer ), $post_id );
    }

    private static function with_cause( string $cause, int $post_id, ?string $next = null ): self {
        if ( null === $next ) {
            $post = $post_id > 0 ? \get_post( $post_id ) : null;
            $live = is_object( $post ) && isset( $post->post_status ) && Strict_Ceiling::is_live_status( (string) $post->post_status );
            $copyable = is_object( $post ) && ! isset( Duplicate_Post::REFUSED_TYPES[ (string) ( $post->post_type ?? '' ) ] );
            if ( $live && ! $copyable ) {
                $next = sprintf( 'A person changes post %d in WordPress.', $post_id );
            } elseif ( $live ) {
                $next = sprintf( 'Copy post %d with wp_duplicate_post and edit the copy.', $post_id );
            } else {
                $next = 'Keep it a draft; a person publishes it.';
            }
        }
        return new self( self::LEAD . ' ' . $next, $cause . ' ' . self::SCOPE );
    }

    public static function is_permission_refusal( \Throwable $failure ): bool {
        return in_array( $failure->getCode(), array( 401, 403 ), true )
            || 1 === preg_match( '/answered HTTP 40[13]\b|not allowed|permission|access denied|forbidden|rest_cannot|you cannot (?:edit|publish|delete|duplicate|republish|create)|needs the \w+ capability/i', $failure->getMessage() );
    }

    private static function status_words( string $status, string $other = 'in status "%s"' ): string {
        $words = array(
            'publish' => 'published',
            'future'  => 'scheduled',
            'private' => 'private',
            'pending' => 'pending review',
            'draft'   => 'a draft',
        );
        return isset( $words[ $status ] ) ? $words[ $status ] : sprintf( $other, $status );
    }
}
