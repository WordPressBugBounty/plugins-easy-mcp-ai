<?php
namespace Easy_MCP_AI\MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Detailed_Tool_Error extends \InvalidArgumentException {

    const MAX_MESSAGE = 200;

    const MAX_DETAILS = 2000;

    private $details;

    private $approve_url;

    public function __construct( string $message, string $details = '', string $approve_url = '' ) {
        parent::__construct( $message );
        $this->details     = $details;
        $this->approve_url = $approve_url;
    }

    public function get_approve_url(): string {
        return $this->approve_url;
    }

    public function get_details(): string {
        return $this->details;
    }
}
