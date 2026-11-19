<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Block_Checks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks_List_Posts extends Local_Block_Tool {

    protected function is_idempotent(): bool {
        return true;
    }

    public function get_name() {
        return 'blocks_list_posts';
    }

    public function get_title() {
        return 'List posts';
    }

    public function get_description() {
        return 'List the pages, posts or WooCommerce products of this site (10 per page), with their ids for blocks_get, blocks_edit and blocks_publish. Default: published pages.';
    }

    public function get_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'type'   => self::type_property(),
                'search' => array(
                    'type'      => 'string',
                    'maxLength' => 200,
                ),
                'status' => array(
                    'type'        => 'string',
                    'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ),
                    'description' => 'Default: published only. any includes drafts. future is for pages and posts, not products.',
                ),
                'page'   => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
            ),
        );
    }

    protected function perform( array $arguments ) {
        $type   = self::enum_arg( $arguments, 'type', self::POST_KINDS ) ?? 'page';
        $search = self::string_arg( $arguments, 'search', 0, 200 );
        $status = self::enum_arg( $arguments, 'status', array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ) );
        $page   = self::int_arg( $arguments, 'page', 1 );
        if ( 'product' === $type && 'future' === $status ) {
            throw self::refusal( 'status "future" applies to pages and posts only. For products use publish, draft, pending, private or any.' );
        }

        $name = 'page' === $type ? 'wp_list_pages' : ( 'post' === $type ? 'wp_list_posts' : 'wp_wc_list_products' );
        $args = array();
        if ( null !== $search && '' !== $search ) {
            $args['search'] = $search;
        }
        if ( null !== $status ) {
            $args['status'] = $status;
        }
        if ( null !== $page ) {
            $args['page'] = $page;
        }
        $data = $this->ask( $name, $args );

        $rows  = $data['page' === $type ? 'pages' : ( 'post' === $type ? 'posts' : 'products' )] ?? array();
        $items = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            if ( ! is_array( $row ) || ! self::is_positive_int( $row['id'] ?? null ) ) {
                continue;
            }
            $title   = 'product' === $type ? ( $row['name'] ?? null ) : ( $row['title'] ?? null );
            $link    = 'product' === $type ? ( $row['permalink'] ?? null ) : ( $row['link'] ?? null );
            $items[] = array(
                'id'       => $row['id'],
                'title'    => is_string( $title ) ? $title : '',
                'status'   => isset( $row['status'] ) && is_string( $row['status'] ) ? $row['status'] : '',
                'link'     => is_string( $link ) ? $link : '',
                'modified' => 'product' !== $type && isset( $row['modified'] ) && is_string( $row['modified'] ) ? $row['modified'] : null,
            );
        }
        $current = isset( $data['page'] ) && is_int( $data['page'] ) ? $data['page'] : ( $page ?? 1 );
        $output  = array(
            'content_notice' => Block_Checks::SITE_CONTENT_NOTICE,
            'items'          => $items,
            'page'           => $current,
        );
        if ( isset( $data['total'] ) && is_int( $data['total'] ) ) {
            $output['total'] = $data['total'];
        }
        $output['message'] = array() === $items
            ? sprintf( 'No %1$ss on this site match (page %2$d).', $type, $current )
            : sprintf( '%1$ss on this site, page %2$d%3$s:', $type, $current, isset( $output['total'] ) ? ' of ' . $output['total'] . ' in all' : '' );
        return $output;
    }
}
