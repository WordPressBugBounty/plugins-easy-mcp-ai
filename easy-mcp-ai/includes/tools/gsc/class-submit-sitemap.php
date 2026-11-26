<?php
namespace Easy_MCP_AI\Tools\GSC;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Post_Type_Tool_Hint;
use Easy_MCP_AI\GSC\GSC_Client;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Submit_Sitemap extends Base_Tool {

    public function get_name() { return 'wp_gsc_submit_sitemap'; }

    public function get_description() {
        return 'Submits sitemaps to a Google Search Console property, or submits them again so Search Console reads them anew; works for a sitemap index too. Required: `feed_paths`, a list of 1 to 100 sitemap URLs on the property, e.g. ["https://example.com/sitemap_index.xml"]. Returns { submitted: true, count, site_url, sitemaps } with each sitemap as Search Console lists it after the submit, plus `resubmitted`: true when it was already submitted. A sitemap stays pending until Google fetches it'
            . ( Post_Type_Tool_Hint::may_describe( 'wp_gsc_get_sitemap' ) ? '; check it later with wp_gsc_get_sitemap.' : '.' )
            . ( Post_Type_Tool_Hint::may_describe( 'wp_gsc_delete_sitemap' ) ? ' Remove one with wp_gsc_delete_sitemap.' : '' )
            . ' The configured service account must be an Owner or a Full user of the property.';
    }

    public function get_category() { return 'gsc'; }

    public function get_required_capability() { return 'manage_options'; }

    public function get_annotations() {
        return array(
            'title'           => 'Submit Search Console sitemaps',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => true,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'site_url'   => array( 'type' => 'string', 'description' => 'The Search Console property, e.g. "https://example.com/" or "sc-domain:example.com". Omit it to use the configured default property; a value that names no property (such as "") is refused, never replaced by the default.' ),
                'feed_paths' => array(
                    'type'        => 'array',
                    'description' => 'Sitemap URLs to submit (1-100), e.g. ["https://example.com/sitemap.xml"]. Duplicates are ignored.',
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                    'maxItems'    => Sitemap_Batch::MAX_SITEMAPS,
                ),
            ),
            'required'   => array( 'feed_paths' ),
        );
    }

    public function describe( array $arguments ) {
        try {
            $site  = GSC_Client::write_site_url( $arguments );
            $paths = Sitemap_Batch::feed_paths( $arguments );
        } catch ( \RuntimeException | \InvalidArgumentException $e ) {
            return self::generic_description( $this, $arguments );
        }
        return sprintf( 'Submit %d %s to Google Search Console %s: %s', count( $paths ), 1 === count( $paths ) ? 'sitemap' : 'sitemaps', $site, implode( '; ', $paths ) );
    }

    public function throttle_units( array $arguments ) {
        return count( Sitemap_Batch::feed_paths( $arguments ) );
    }

    public function execute( array $arguments ) {
        $site   = GSC_Client::write_site_url( $arguments );
        $paths  = Sitemap_Batch::feed_paths( $arguments );
        $before = Sitemap_Batch::submitted( $site );

        $change = Sitemap_Change::submit( $site );
        $done   = array();
        foreach ( $paths as $i => $path ) {
            try {
                GSC_Client::edit( 'PUT', Sitemap_Batch::url( $site, $path ) );
            } catch ( \RuntimeException $e ) {
                throw Sitemap_Batch::stopped( $change, $path, $done, array_slice( $paths, $i + 1 ), $e );
            }
            $done[] = $path;
        }

        try {
            $after = Sitemap_Batch::submitted( $site );
        } catch ( \RuntimeException $e ) {
            throw Sitemap_Batch::unconfirmed( $done, $e );
        }
        list( $listed, $unlisted ) = Sitemap_Batch::split( $change, $done, $after );
        if ( array() !== $unlisted ) {
            throw Sitemap_Batch::contradicted( $change, $listed, $unlisted );
        }
        $sitemaps = array();
        foreach ( $listed as $path ) {
            $key        = Sitemap_Batch::key( $path );
            $sitemaps[] = $after[ $key ] + array( 'resubmitted' => isset( $before[ $key ] ) );
        }

        return array(
            'submitted' => true,
            'count'     => count( $sitemaps ),
            'site_url'  => $site,
            'sitemaps'  => $sitemaps,
        );
    }
}
