<?php
namespace Easy_MCP_AI\GSC;

use Easy_MCP_AI\MCP\Detailed_Runtime_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Edit_Refusal extends Detailed_Runtime_Error {

    public static function of( \RuntimeException $error ): self {
        return new self( $error->getMessage(), $error instanceof Detailed_Runtime_Error ? $error->get_details() : '' );
    }
}
