<?php
namespace Easy_MCP_AI\MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Detailed_Runtime_Error extends \RuntimeException {

    const CONTINUED = '… (the whole message follows)';

    private $details;

    private $validation = false;

    public function __construct( string $message, string $details = '' ) {
        parent::__construct( $message );
        $this->details = $details;
    }

    public function get_details(): string {
        return $this->details;
    }

    public function is_validation(): bool {
        return $this->validation;
    }

    public static function for_validation( string $message ): self {
        return self::validation( self::fitted( $message ) );
    }

    public static function fitted( string $message ): self {
        if ( Refusal_Text::fits( $message ) ) {
            return new self( $message );
        }
        $budget = Detailed_Tool_Error::MAX_MESSAGE - strlen( self::CONTINUED );
        do {
            $short = function_exists( 'mb_strcut' ) ? mb_strcut( $message, 0, $budget, 'UTF-8' ) : substr( $message, 0, $budget );
            $space = strrpos( $short, ' ' );
            if ( false !== $space && $space > $budget / 2 ) {
                $short = substr( $short, 0, $space );
            }
            $short   = rtrim( $short, ' ,;:—' ) . self::CONTINUED;
            $budget -= 10;
        } while ( ! Refusal_Text::fits( $short ) && $budget > 20 );
        return new self( $short, $message );
    }

    private static function validation( self $error ): self {
        $error->validation = true;
        return $error;
    }
}
