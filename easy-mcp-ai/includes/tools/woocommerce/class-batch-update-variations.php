<?php
namespace Easy_MCP_AI\Tools\WooCommerce;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Delete_Outcome;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Batch_Update_Variations extends Base_Tool {

    use Batch_Reply;

    public function get_name() {
        return 'wp_wc_batch_update_variations';
    }

    public function get_description() {
        return 'Create, update, and/or delete up to 100 WooCommerce product variations under a parent product in one REST batch call. Required: `product_id` (parent product ID). Plus any combination of `create`, `update`, `delete`. Default soft cap is 25 items per branch (raise via `easy_mcp_ai_wc_batch_soft_cap` filter). WooCommerce caps the combined total of create+update+delete at 100 items per request (not per branch) via `woocommerce_rest_batch_items_limit` filter. Returns { create, update, delete }, one row per item: its `id`, `status`, WooCommerce\'s `error` for an item it refused, and each field the item sent, as WooCommerce saved it (read the whole variation with `wp_wc_get_product_variation`); a deleted item is { id, status: "deleted", deleted: true }, and a delete WooCommerce refused { id, deleted: false, error }, and so is a delete WooCommerce answered as done while a plugin kept the variation. If **Easy MCP AI → Settings → Draft mode** saves new content as drafts (New content or Strict), every `create` item has its status silently overridden to `draft` regardless of the value supplied; `update` items are unaffected.';
    }

    public function get_category() {
        return 'woocommerce';
    }

    public function get_required_capability() {
        return 'edit_products';
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
                'product_id' => array(
                    'type'        => 'integer',
                    'description' => 'Parent product ID.',
                ),
                'create'     => array(
                    'type'        => 'array',
                    'description' => 'Array of variation objects to create (any field accepted by the WC REST variation schema, e.g. regular_price, sku, attributes; extra/unrecognized keys are passed through).',
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'update'     => array(
                    'type'        => 'array',
                    'description' => 'Array of variation objects to update, each with `id` (any field accepted by the WC REST variation schema; extra/unrecognized keys are passed through).',
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'delete'     => array(
                    'type'        => 'array',
                    'description' => 'Array of variation IDs to delete.',
                    'items'       => array( 'type' => 'integer' ),
                ),
            ),
            'required'   => array( 'product_id' ),
        );
    }

    public function throttle_units( array $arguments ) {
        return self::count_items( $arguments, array( 'create', 'update', 'delete' ) );
    }

    public function execute( array $arguments ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            throw new \RuntimeException( 'WooCommerce is not active.' );
        }

        $this->validate_required( $arguments, array( 'product_id' ) );
        $product_id = $this->parse_required_id( $arguments['product_id'], 'product_id' );

        $soft_cap = (int) apply_filters( 'easy_mcp_ai_wc_batch_soft_cap', 25 );

        $body = array();
        if ( ! empty( $arguments['create'] ) ) {
            if ( count( $arguments['create'] ) > $soft_cap ) {
                throw new \InvalidArgumentException( sprintf( 'create exceeds the %d-item soft cap. Use the easy_mcp_ai_wc_batch_soft_cap filter to raise the limit.', $soft_cap ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $create_items = array();
            foreach ( $arguments['create'] as $item ) {
                if ( is_array( $item ) ) {
                    $this->maybe_force_draft( $item );
                }
                $create_items[] = $item;
            }
            $body['create'] = $create_items;
        }
        if ( ! empty( $arguments['update'] ) ) {
            if ( count( $arguments['update'] ) > $soft_cap ) {
                throw new \InvalidArgumentException( sprintf( 'update exceeds the %d-item soft cap. Use the easy_mcp_ai_wc_batch_soft_cap filter to raise the limit.', $soft_cap ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $body['update'] = $arguments['update'];
        }
        if ( ! empty( $arguments['delete'] ) ) {
            if ( count( $arguments['delete'] ) > $soft_cap ) {
                throw new \InvalidArgumentException( sprintf( 'delete exceeds the %d-item soft cap. Use the easy_mcp_ai_wc_batch_soft_cap filter to raise the limit.', $soft_cap ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $body['delete'] = array_map( 'absint', $arguments['delete'] );
        }

        if ( empty( $body ) ) {
            throw new \InvalidArgumentException( 'At least one of `create`, `update`, or `delete` must be provided and non-empty.' );
        }

        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        $reply = self::short_batch_reply( $this->strip_response_noise( $this->rest_request( 'POST', '/wc/v3/products/' . $product_id . '/variations/batch', $body ) ), $body );
        return self::refuse_kept_deletes(
            $reply,
            static function ( int $id ): ?string {
                return Delete_Outcome::post_kept( $id, 'variation ' . $id );
            }
        );
    }
}
