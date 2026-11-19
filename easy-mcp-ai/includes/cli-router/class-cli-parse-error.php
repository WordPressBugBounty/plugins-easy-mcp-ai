<?php
namespace Easy_MCP_AI\Cli_Router;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cli_Parse_Error extends \Exception {

    private $reason;

    private $plain_message;

    private $offset;

    public function __construct( string $reason, string $message, ?string $plain_message = null, ?int $offset = null ) {
        parent::__construct( $message );
        $this->reason        = $reason;
        $this->plain_message = $plain_message ?? $message;
        $this->offset        = $offset;
    }

    public function get_offset(): ?int {
        return $this->offset;
    }

    public function get_plain_message(): string {
        return $this->plain_message;
    }

    public function get_reason(): string {
        return $this->reason;
    }
}
