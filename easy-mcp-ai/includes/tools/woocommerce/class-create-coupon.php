<?php
namespace Easy_MCP_AI\Tools\WooCommerce;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Create_Coupon extends Base_Tool {

    public function get_name() {
        return 'wp_wc_create_coupon';
    }

    public function get_description() {
        return 'Creates a WooCommerce coupon. Required: code, discount_type (percent/fixed_cart/fixed_product), amount. Optional: date_expires, usage_limit, minimum_amount, maximum_amount, free_shipping.';
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
            'type'       => 'object',
            'properties' => array(
                'code'           => array(
                    'type'        => 'string',
                    'description' => 'Coupon code.',
                ),
                'discount_type'  => array(
                    'type'        => 'string',
                    'description' => 'Discount type.',
                    'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
                ),
                'amount'         => array(
                    'type'        => 'string',
                    'description' => 'Coupon amount.',
                ),
                'date_expires'   => array(
                    'type'        => 'string',
                    'description' => 'Coupon expiry date in YYYY-MM-DD format.',
                ),
                'usage_limit'    => array(
                    'type'        => 'integer',
                    'description' => 'How many times the coupon can be used in total.',
                ),
                'minimum_amount' => array(
                    'type'        => 'string',
                    'description' => 'Minimum order amount that needs to be in the cart.',
                ),
                'maximum_amount' => array(
                    'type'        => 'string',
                    'description' => 'Maximum order amount allowed when using the coupon.',
                ),
                'free_shipping'  => array(
                    'type'        => 'boolean',
                    'description' => 'Whether to grant free shipping.',
                ),
            ),
            'required'   => array( 'code', 'discount_type', 'amount' ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            throw new \RuntimeException( 'WooCommerce is not active.' );
        }

        $this->validate_required( $arguments, array( 'code', 'discount_type', 'amount' ) );

        $params = array(
            'code'          => sanitize_text_field( $arguments['code'] ),
            'discount_type' => sanitize_text_field( $arguments['discount_type'] ),
            'amount'        => sanitize_text_field( $arguments['amount'] ),
        );

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

        $data = $this->rest_request( 'POST', '/wc/v3/coupons', $params );

        return array(
            'id'            => $data['id'],
            'code'          => $data['code'],
            'discount_type' => $data['discount_type'],
            'amount'        => $data['amount'],
            'permalink'     => $data['_links']['self'][0]['href'] ?? '',
        );
    }
}
