<?php
namespace Easy_MCP_AI\Tools\GSC;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Post_Type_Tool_Hint;
use Easy_MCP_AI\GSC\GSC_Client;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delete_Sitemap extends Base_Tool {

    public function get_name() { return 'wp_gsc_delete_sitemap'; }

    public function get_description() {
        $list = Post_Type_Tool_Hint::may_describe( 'wp_gsc_list_sitemaps' );
        return 'Removes submitted sitemaps from a Google Search Console property\'s Sitemaps report. The files on the site are untouched, and Google remembers a sitemap it has read and the URLs in it, and may keep crawling them: to stop that for one URL (the sitemap\'s or a page\'s), block it in robots.txt or remove it from the site. Required: `feed_paths`, a list of 1 to 100 sitemap URLs exactly as '
            . ( $list ? 'wp_gsc_list_sitemaps lists them' : 'Search Console lists them' )
            . '. Every URL is checked first: if any is not a sitemap submitted for the property, the call is refused and nothing is deleted. A sitemap listed only inside a sitemap index'
            . ( $list ? ' (wp_gsc_list_sitemaps with sitemap_index)' : '' )
            . ' is not a submitted one and is refused. Returns { deleted: true, count, site_url, sitemaps } with each sitemap as Search Console listed it.'
            . ( Post_Type_Tool_Hint::may_describe( 'wp_gsc_submit_sitemap' ) ? ' Undo with wp_gsc_submit_sitemap.' : '' )
            . ' The configured service account must be an Owner or a Full user of the property.';
    }

    public function get_category() { return 'gsc'; }

    public function get_required_capability() { return 'manage_options'; }

    public function get_annotations() {
        return array(
            'title'           => 'Delete Search Console sitemaps',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
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
                    'description' => 'Sitemap URLs to remove (1-100), exactly as ' . ( Post_Type_Tool_Hint::may_describe( 'wp_gsc_list_sitemaps' ) ? 'wp_gsc_list_sitemaps lists them' : 'Search Console lists them' ) . ', e.g. ["https://example.com/old-sitemap.xml"]. Duplicates are ignored.',
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
        return sprintf( 'Remove %d %s from Google Search Console %s: %s', count( $paths ), 1 === count( $paths ) ? 'sitemap' : 'sitemaps', $site, implode( '; ', $paths ) );
    }

    public function throttle_units( array $arguments ) {
        return count( Sitemap_Batch::feed_paths( $arguments ) );
    }

    public function execute( array $arguments ) {
        $site  = GSC_Client::write_site_url( $arguments );
        $paths = Sitemap_Batch::feed_paths( $arguments );

        $submitted = Sitemap_Batch::submitted( $site );
        $missing   = array();
        $targets   = array();
        foreach ( $paths as $path ) {
            $key = Sitemap_Batch::key( $path );
            if ( isset( $submitted[ $key ] ) ) {
                $targets[] = $submitted[ $key ];
            } else {
                $missing[] = $path;
            }
        }
        if ( array() !== $missing ) {
            throw Sitemap_Batch::receipt( 'Nothing was deleted.', array( array( 'not submitted for ' . Sitemap_Batch::shown( $site ), $missing ) ) );
        }

        $change = Sitemap_Change::delete( $site );
        $done   = array();
        foreach ( $targets as $i => $entry ) {
            try {
                GSC_Client::edit( 'DELETE', Sitemap_Batch::url( $site, $entry['path'] ) );
            } catch ( \RuntimeException $e ) {
                $untried = array_map(
                    static function ( array $later ): string {
                        return $later['path'];
                    },
                    array_slice( $targets, $i + 1 )
                );
                throw Sitemap_Batch::stopped( $change, $entry['path'], $done, $untried, $e );
            }
            $done[] = $entry['path'];
        }

        try {
            $after = Sitemap_Batch::submitted( $site );
        } catch ( \RuntimeException $e ) {
            throw Sitemap_Batch::unconfirmed( $done, $e );
        }
        list( $deleted, $left ) = Sitemap_Batch::split( $change, $done, $after );
        if ( array() !== $left ) {
            throw Sitemap_Batch::contradicted( $change, $deleted, $left );
        }

        return array(
            'deleted'  => true,
            'count'    => count( $targets ),
            'site_url' => $site,
            'sitemaps' => $targets,
        );
    }
}
