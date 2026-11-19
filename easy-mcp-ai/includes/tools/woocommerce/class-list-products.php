<?php
namespace Easy_MCP_AI\Tools\WooCommerce;

use Easy_MCP_AI\MCP\Off_Schema_Refusal;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class List_Products extends Base_Tool {

    const SEARCH_FIELDS = array( 'name', 'sku', 'description', 'short_description' );

    public function get_name() {
        return 'wp_wc_list_products';
    }

    public function get_description() {
        return 'Lists WooCommerce products with optional filtering by status, category, price range, stock status, and search. Returns { products: [ { id, name, sku, status, price, regular_price, sale_price, stock_status, stock_quantity, type, permalink } ], total, total_pages, page, per_page }, where total and total_pages count every product that matches the filters.';
    }

    public function get_category() {
        return 'woocommerce';
    }

    public function get_required_capability() {
        return 'manage_woocommerce';
    }

    public function get_annotations() {
        return array( 'title' => $this->get_title(), 'readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'per_page'     => array( 'type' => 'integer', 'description' => 'Number of products per page (1–100).', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ),
                'page'         => array( 'type' => 'integer', 'description' => 'Page number.', 'default' => 1 ),
                'search'       => array( 'type' => 'string',  'description' => 'Words to find in the product name, SKU, description or short description, in full or in part: every word must appear in one of them. Words are read as WordPress\'s search reads them (stopwords such as "the", single letters a to z and 0 are skipped), but a leading minus stays part of the word. Matching variations are listed too. A "phrase" in double quotes, a text with no word left or more than nine words, `sku` sent as well, or WooCommerce before 10.1 searches the name, short description and description only.' ),
                'status'       => array( 'type' => 'string',  'description' => 'Filter by product status.', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'any' ) ),
                'category'     => array( 'type' => 'string',  'description' => 'Filter by product category term ID (numeric, not the slug) — WooCommerce\'s REST layer resolves this value as a term ID; a slug string does not match any product. Use wp_list_categories or wp_get_category to look up the ID.' ),
                'stock_status' => array( 'type' => 'string',  'description' => 'Filter by stock status.', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
                'orderby'      => array( 'type' => 'string',  'description' => 'Sort products by field.', 'enum' => array( 'date', 'id', 'title', 'slug', 'price', 'popularity', 'rating' ), 'default' => 'date' ),
                'order'        => array( 'type' => 'string',  'description' => 'Sort direction.', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
                'type'         => array( 'type' => 'string',  'description' => 'Filter by product type.', 'enum' => array( 'simple', 'variable', 'grouped', 'external' ) ),
                'sku'          => array( 'type' => 'string',  'description' => 'Filter by product SKU (exact match).' ),
                'on_sale'      => array( 'type' => 'boolean', 'description' => 'Filter to only products currently on sale.' ),
                'min_price'    => array( 'type' => 'string',  'description' => 'Filter products with price greater than or equal to this value.' ),
                'max_price'    => array( 'type' => 'string',  'description' => 'Filter products with price less than or equal to this value.' ),
                'after'        => array( 'type' => 'string',  'description' => 'Filter products created after this date (ISO 8601 format).' ),
                'before'       => array( 'type' => 'string',  'description' => 'Filter products created before this date (ISO 8601 format).' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            throw new \RuntimeException( 'WooCommerce is not active on this site. Please install and activate WooCommerce to use this tool.' );
        }
        $params = array(
            'per_page' => isset( $arguments['per_page'] ) ? min( 100, max( 1, absint( $arguments['per_page'] ) ) ) : 10,
            'page'     => isset( $arguments['page'] )     ? absint( $arguments['page'] )     : 1,
        );
        foreach ( array( 'search', 'status', 'category', 'stock_status', 'orderby', 'order', 'type', 'sku', 'min_price', 'max_price', 'after', 'before' ) as $key ) {
            if ( ! empty( $arguments[ $key ] ) ) {
                $params[ $key ] = sanitize_text_field( $arguments[ $key ] );
            }
        }
        if ( in_array( $arguments['sku'] ?? null, array( '0', 0 ), true ) ) {
            $params['sku'] = '0';
        }
        if ( isset( $arguments['on_sale'] ) ) {
            $params['on_sale'] = (bool) $arguments['on_sale'];
        }
        $words  = isset( $params['search'] ) && ! isset( $params['sku'] ) ? self::search_words( $params['search'] ) : array();
        $fields = array() !== $words ? self::search_fields() : array();
        if ( array() !== $fields ) {
            $params['search']        = implode( ' ', $words );
            $params['search_fields'] = $fields;
        }
        $response = $this->rest_response( 'GET', '/wc/v3/products', $params );
        $data     = $response->get_data();
        $result   = array();
        foreach ( $data as $p ) {
            $result[] = array(
                'id'             => $p['id'],
                'name'           => $p['name'],
                'sku'            => $p['sku'],
                'status'         => $p['status'],
                'price'          => $p['price'],
                'regular_price'  => $p['regular_price'],
                'sale_price'     => $p['sale_price'],
                'stock_status'   => $p['stock_status'],
                'stock_quantity' => $p['stock_quantity'],
                'type'           => $p['type'],
                'permalink'      => $p['permalink'],
            );
        }
        return array_merge(
            array( 'products' => $result ),
            $this->pagination_meta( $response, $params['page'], $params['per_page'], count( $data ) )
        );
    }

    private static function search_words( string $search ): array {
        if ( false !== strpos( $search, '"' ) ) {
            return array();
        }
        return array_values( array_filter( array_map( 'trim', self::wp_search_terms( stripslashes( $search ) ) ) ) );
    }

    private static function search_fields(): array {
        if ( ! defined( 'WC_VERSION' ) || version_compare( (string) WC_VERSION, '10.1.0', '<' ) ) {
            return array();
        }
        $endpoints = rest_get_server()->get_routes()['/wc/v3/products'] ?? null;
        $parameter = is_array( $endpoints ) ? Off_Schema_Refusal::served_parameter( $endpoints, 'GET', 'search_fields' ) : null;
        $offered   = $parameter['items']['enum'] ?? array();
        return is_array( $offered ) ? array_values( array_intersect( self::SEARCH_FIELDS, $offered ) ) : array();
    }
}
