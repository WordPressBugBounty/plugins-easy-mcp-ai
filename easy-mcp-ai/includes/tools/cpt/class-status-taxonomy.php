<?php
namespace Easy_MCP_AI\Tools\CPT;

use Easy_MCP_AI\MCP\Detailed_Tool_Error;
use Easy_MCP_AI\MCP\Status_Field_Guard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Status_Taxonomy {

    const FIELD = 'status';

    const DETAILS = 'WordPress names a REST taxonomy\'s field after its rest_base, or its name when it has none, and on this post type that field replaces the post\'s own status: the REST API reads a status as term IDs, so it cannot filter items by status or set one. Here items are listed with the default status only (publish, or inherit for media), created as drafts, and keep their status on update; their terms in that taxonomy are set with tax_input, after WordPress saves the item. Giving the taxonomy another rest_base (register_taxonomy()) lets the status through again.';

    public static function of( string $post_type ): string {
        return self::all( $post_type )[0] ?? '';
    }

    public static function all( string $post_type ): array {
        return array_map( 'strval', array_keys( Create_CPT_Item::rest_taxonomies( $post_type ), self::FIELD, true ) );
    }

    public static function status_of( array $item ) {
        $status = $item['status'] ?? null;
        if ( ! is_array( $status ) ) {
            return $status;
        }
        $post = get_post( (int) ( $item['id'] ?? 0 ) );
        return is_object( $post ) ? (string) $post->post_status : '';
    }

    public static function take_status( array &$params, string $post_type, string $kept, string $lead, string $nothing ): string {
        $taxonomy = self::of( $post_type );
        if ( '' === $taxonomy || ! isset( $params['status'] ) ) {
            return $taxonomy;
        }
        if ( $kept !== $params['status'] ) {
            throw self::refusal( $lead, $taxonomy, $nothing );
        }
        unset( $params['status'] );
        return $taxonomy;
    }

    public static function refuse_unlistable( string $post_type, $status ): void {
        $refusal = Status_Field_Guard::list_refusal( $post_type, $status );
        if ( null !== $refusal ) {
            throw new Detailed_Tool_Error( $refusal, self::DETAILS );
        }
    }

    public static function refuse_unwritable( string $post_type, $item_id, $status ): void {
        if ( null !== $item_id ) {
            $item_id = is_int( $item_id ) || ( is_string( $item_id ) && '' !== $item_id && ctype_digit( $item_id ) ) ? (int) $item_id : 0;
            if ( $item_id < 1 ) {
                return;
            }
        }
        $refusal = Status_Field_Guard::write_refusal( $post_type, $item_id, $status );
        if ( null !== $refusal ) {
            throw new Detailed_Tool_Error( $refusal, self::DETAILS );
        }
    }

    public static function refusal( string $lead, string $taxonomy, string $nothing ): Detailed_Tool_Error {
        return new Detailed_Tool_Error( self::message( $lead, $taxonomy, $nothing ), self::DETAILS );
    }

    public static function message( string $lead, string $taxonomy, string $nothing ): string {
        return sprintf( '%1$s: taxonomy "%2$s" takes the REST field "status". %3$s', $lead, $taxonomy, $nothing );
    }
}
