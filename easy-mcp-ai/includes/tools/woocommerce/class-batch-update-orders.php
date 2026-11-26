<?php
namespace Easy_MCP_AI\Tools\WooCommerce;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Delete_Outcome;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Batch_Update_Orders extends Base_Tool {

    use Batch_Reply;
    use Product_Terms;

    public function get_name() {
        return 'wp_wc_batch_update_orders';
    }

    public function get_description() {
        return 'Create, update, and/or delete up to 100 WooCommerce orders in a single REST batch call. Provide any combination of `create`, `update` (each item needs `id`), `delete` (integer IDs). Default soft cap is 25 items per branch (raise via `easy_mcp_ai_wc_batch_soft_cap` filter). WooCommerce caps the combined total of create+update+delete at 100 items per request (not per branch) via `woocommerce_rest_batch_items_limit` filter. Returns { create, update, delete }, one row per item: its `id`, `status`, WooCommerce\'s `error` for an item it refused, and each field the item sent, as WooCommerce saved it (read the whole order with `wp_wc_get_order`); a deleted item is { id, status: "deleted", deleted: true }, and a delete WooCommerce refused { id, deleted: false, error }, and so is a delete WooCommerce answered as done while a plugin kept the order (posts store; with HPOS a delete cannot be stopped that way). ' . Update_Order::REFUNDED_STATUS_NOTE;
    }

    public function get_category() {
        return 'woocommerce';
    }

    public function get_required_capability() {
        return 'edit_shop_orders';
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
                'create' => array(
                    'type'        => 'array',
                    'description' => 'Array of order objects to create (any field accepted by the WC REST order schema, e.g. line_items, billing, shipping, status; extra/unrecognized keys are passed through).',
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'update' => array(
                    'type'        => 'array',
                    'description' => 'Array of order objects to update, each with `id` (any field accepted by the WC REST order schema; extra/unrecognized keys are passed through).',
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'delete' => array(
                    'type'        => 'array',
                    'description' => 'Array of order IDs to delete.',
                    'items'       => array( 'type' => 'integer' ),
                ),
            ),
        );
    }

    public function precheck( array $arguments ): void {
        if ( class_exists( 'WooCommerce' ) && ! empty( $arguments['create'] ) && is_array( $arguments['create'] ) ) {
            $this->refuse_unknown_created_lines( $arguments['create'] );
        }
    }

    public function throttle_units( array $arguments ) {
        return self::count_items( $arguments, array( 'create', 'update', 'delete' ) );
    }

    public function execute( array $arguments ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            throw new \RuntimeException( 'WooCommerce is not active.' );
        }

        $soft_cap = (int) apply_filters( 'easy_mcp_ai_wc_batch_soft_cap', 25 );

        $body = array();
        if ( ! empty( $arguments['create'] ) ) {
            if ( count( $arguments['create'] ) > $soft_cap ) {
                throw new \InvalidArgumentException( sprintf( 'create exceeds the %d-item soft cap. Use the easy_mcp_ai_wc_batch_soft_cap filter to raise the limit.', $soft_cap ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $this->refuse_unknown_created_lines( $arguments['create'] );
            $body['create'] = $arguments['create'];
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

        $reply = self::short_batch_reply( $this->strip_response_noise( $this->rest_request( 'POST', '/wc/v3/orders/batch', $body ) ), $body );
        if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return self::refuse_kept_deletes(
                $reply,
                static function ( int $id ): ?string {
                    return Delete_Outcome::hpos_order_kept( $id, 'order ' . $id );
                }
            );
        }
        return self::refuse_kept_deletes(
            $reply,
            static function ( int $id ): ?string {
                return Delete_Outcome::post_kept( $id, 'order ' . $id );
            }
        );
    }

    private function refuse_unknown_created_lines( array $create ): void {
        foreach ( $create as $key => $item ) {
            if ( is_array( $item ) && isset( $item['line_items'] ) && is_array( $item['line_items'] ) ) {
                $this->refuse_unknown_line_item_products( $item['line_items'], 'create[' . $key . '].line_items', false, 'Nothing was changed.' );
            }
        }
    }
}
