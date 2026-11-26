<?php
namespace Easy_MCP_AI\Tools\GSC;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Sitemap_Change {

    public $site_url;

    public $verb;

    public $listed_when_applied;

    public $contradiction;

    private function __construct( string $site_url, string $verb, bool $listed_when_applied, string $contradiction ) {
        $this->site_url            = $site_url;
        $this->verb                = $verb;
        $this->listed_when_applied = $listed_when_applied;
        $this->contradiction       = $contradiction;
    }

    public static function submit( string $site_url ): self {
        return new self( $site_url, 'submitted', true, 'not listed' );
    }

    public static function delete( string $site_url ): self {
        return new self( $site_url, 'deleted', false, 'still listed' );
    }
}
