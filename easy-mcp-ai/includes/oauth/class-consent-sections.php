<?php
namespace Easy_MCP_AI\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Consent_Sections {

    const MIN_ROWS_FOR_HEADING = 3;

    const PRIVILEGED_SECTION = 'powerful';

    const SECTIONS = array(
        'content'      => array( 'posts', 'cpt', 'media', 'taxonomies', 'term_meta', 'comments' ),
        'users'        => array( 'users', 'user_meta', 'user_create', 'user_update', 'user_delete' ),
        'design'       => array( 'menus', 'blocks', 'appearance', 'widgets' ),
        'site'         => array( 'settings', 'plugins', 'plugin_files', 'themes', 'theme_files', 'site_health', 'core_update', 'cache', 'history', 'audit' ),
        'integrations' => array(
            'woocommerce', 'wc_webhooks', 'wc_store', 'acf', 'acf_posts', 'buddypress', 'events', 'redirection', 'sitekit', 'divi', 'cf7', 'smartslider', 'wpforms', 'loco', 'wordfence',
            'yoast', 'aioseo', 'rankmath', 'seopress', 'slimseo', 'tsf',
            'gsc', 'ga', 'dfs', 'semrush', 'ahrefs', 'seranking',
        ),
        'powerful'     => array( 'rest', 'rest_delete', 'cli', 'mail' ),
    );

    const ABILITY_SECTION = 'integrations';

    public static function headings(): array {
        return array(
            'content'      => __( 'Content', 'easy-mcp-ai' ),
            'users'        => __( 'Users', 'easy-mcp-ai' ),
            'design'       => __( 'Design', 'easy-mcp-ai' ),
            'site'         => __( 'Site management', 'easy-mcp-ai' ),
            'integrations' => __( 'Integrations', 'easy-mcp-ai' ),
            'powerful'     => __( 'Powerful access', 'easy-mcp-ai' ),
        );
    }

    public static function group( array $categories, int $privileged_count = 0 ): array {
        $position = array();
        foreach ( self::SECTIONS as $key => $slugs ) {
            foreach ( $slugs as $index => $slug ) {
                $position[ $slug ] = array( $key, $index );
            }
        }

        $buckets = array_fill_keys( array_keys( self::SECTIONS ), array() ) + array( 'other' => array() );
        $abilities = array();
        foreach ( $categories as $category ) {
            $slug = isset( $category['slug'] ) ? (string) $category['slug'] : '';
            if ( ! empty( $category['is_ability'] ) ) {
                $abilities[] = $category;
            } elseif ( isset( $position[ $slug ] ) ) {
                $buckets[ $position[ $slug ][0] ][ $position[ $slug ][1] ] = $category;
            } else {
                $buckets['other'][] = $category;
            }
        }

        $headings = self::headings();
        $sections = array();
        foreach ( $buckets as $key => $rows ) {
            ksort( $rows );
            $rows  = array_values( $rows );
            $extra = self::PRIVILEGED_SECTION === $key ? $privileged_count : 0;
            if ( self::ABILITY_SECTION === $key ) {
                $rows = array_merge( $rows, $abilities );
            }
            if ( array() === $rows && 0 === $extra ) {
                continue;
            }
            $sections[] = array(
                'key'        => $key,
                'heading'    => isset( $headings[ $key ] ) && count( $rows ) + $extra >= self::MIN_ROWS_FOR_HEADING ? $headings[ $key ] : '',
                'categories' => $rows,
            );
        }
        return $sections;
    }
}
