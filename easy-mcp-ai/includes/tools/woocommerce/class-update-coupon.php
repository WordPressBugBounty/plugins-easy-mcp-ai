<?php
namespace Easy_MCP_AI\Tools\WooCommerce;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Coupon extends Base_Tool {

    public function get_name() {
        return 'wp_wc_update_coupon';
    }

    public function get_description() {
        return 'Updates an existing WooCommerce coupon (PATCH semantics). Required: `id`. Optional: `code`, `discount_type` (percent/fixed_cart/fixed_product), `amount` (string, e.g. "10" = 10% or $10), `date_expires` (ISO 8601 or null to remove expiry), `usage_limit` (max total uses, null = unlimited), `usage_limit_per_user`, `minimum_amount`, `maximum_amount`, `free_shipping` (boolean), `individual_use`, `exclude_sale_items`, `product_ids`, `excluded_product_ids`. Returns the updated coupon object.';
    }

    public function get_category() {
        return 'woocommerce';
    }

    public function get_required_capability() {
        return 'manage_woocommerce';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_description(),
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'           => 'object',
            'properties'     => array(
                'id'            => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the coupon to update.',
                ),
                'code'          => array(
                    'type'        => 'string',
                    'description' => 'Coupon code.',
                ),
                'discount_type' => array(
                    'type'        => 'string',
                    'description' => 'Discount type.',
                ),
                'amount'        => array(
                    'type'        => 'string',
                    'description' => 'Coupon amount.',
                ),
                'date_expires'  => array(
                    'type'        => 'string',
                    'description' => 'Coupon expiry date in YYYY-MM-DD format.',
                ),
                'usage_limit'   => array(
                    'type'        => 'integer',
                    'description' => 'How many times the coupon can be used in total.',
                ),
                'minimum_amount' => array(
                    'type'        => 'string',
                    'description' => 'Minimum order amount.',
                ),
                'maximum_amount' => array(
                    'type'        => 'string',
                    'description' => 'Maximum order amount.',
                ),
                'free_shipping' => array(
                    'type'        => 'boolean',
                    'description' => 'Whether to grant free shipping.',
                ),
            ),
            'required'       => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            throw new \RuntimeException( 'WooCommerce is not active.' );
        }

        $this->validate_required( $arguments, array( 'id' ) );
        $id     = $this->parse_required_id( $arguments['id'], 'id' );
        $params = array();

        if ( isset( $arguments['code'] ) ) {
            $params['code'] = sanitize_text_field( $arguments['code'] );
        }
        if ( isset( $arguments['discount_type'] ) ) {
            $params['discount_type'] = sanitize_text_field( $arguments['discount_type'] );
        }
        if ( isset( $arguments['amount'] ) ) {
            $params['amount'] = sanitize_text_field( $arguments['amount'] );
        }
        if ( isset( $arguments['date_expires'] ) ) {
            $params['date_expires'] = sanitize_text_field( $arguments['date_expires'] );
        }
        if ( isset( $arguments['usage_limit'] ) ) {
            $params['usage_limit'] = absint( $arguments['usage_limit'] );
        }
        if ( isset( $arguments['minimum_amount'] ) ) {
            $params['minimum_amount'] = sanitize_text_field( $arguments['minimum_amount'] );
        }
        if ( isset( $arguments['maximum_amount'] ) ) {
            $params['maximum_amount'] = sanitize_text_field( $arguments['maximum_amount'] );
        }
        if ( isset( $arguments['free_shipping'] ) ) {
            $params['free_shipping'] = (bool) $arguments['free_shipping'];
        }

        $data = $this->rest_request( 'PUT', '/wc/v3/coupons/' . $id, $params );

        return array(
            'id'            => $data['id'],
            'code'          => $data['code'],
            'discount_type' => $data['discount_type'],
            'amount'        => $data['amount'],
        );
    }
}
