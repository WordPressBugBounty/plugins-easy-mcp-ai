<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Cloud_Client;
use Easy_MCP_AI\Cloud\Cloud_Error;
use Easy_MCP_AI\Cloud\Cloud_Tools;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Cloud_Block_Tool extends Base_Tool {

    const POST_KINDS = array( 'page', 'post', 'product' );

    const MAX_REF_CHARS = 100;

    const PATH_PATTERN = '/^\d+(\.\d+)*$/D';

    private $client = null;

    protected function writes_content(): bool {
        return false;
    }

    protected function reaches_cloud(): bool {
        return false;
    }

    protected function is_idempotent(): bool {
        return false;
    }

    public function get_category() {
        return Cloud_Tools::CATEGORY;
    }

    public function is_disabled_by_default() {
        return true;
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function get_annotations() {
        $annotations = array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => ! $this->writes_content(),
            'destructiveHint' => $this->writes_content(),
        );
        if ( $this->is_idempotent() ) {
            $annotations['idempotentHint'] = true;
        }
        $annotations['openWorldHint'] = $this->reaches_cloud();
        return $annotations;
    }

    public function accepted_arguments(): ?array {
        return array_map( 'strval', array_keys( (array) ( $this->get_input_schema()['properties'] ?? array() ) ) );
    }

    abstract protected function perform( array $arguments );

    public function execute( array $arguments ) {
        try {
            return $this->perform( $arguments );
        } catch ( Cloud_Error $e ) {
            if ( strlen( $e->getMessage() ) <= Detailed_Tool_Error::MAX_MESSAGE ) {
                throw $e;
            }
            throw self::refusal( $e->getMessage() );
        }
    }

    public function set_client( Cloud_Client $client ): void {
        $this->client = $client;
    }

    protected function client(): Cloud_Client {
        if ( null === $this->client ) {
            $this->client = new Cloud_Client();
        }
        return $this->client;
    }

    protected static function refusal( string $text ): Detailed_Tool_Error {
        $text = trim( $text );
        $max  = Detailed_Tool_Error::MAX_MESSAGE;
        if ( strlen( $text ) <= $max ) {
            return new Detailed_Tool_Error( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $head = substr( $text, 0, $max );
        $cut  = strrpos( $head, '. ' );
        if ( false !== $cut ) {
            ++$cut;
        } else {
            $cut = strrpos( $head, "\n" );
            $cut = false !== $cut ? $cut : strrpos( $head, ' ' );
        }
        if ( false === $cut || $cut < 1 ) {
            $cut = $max;
        }
        return new Detailed_Tool_Error( trim( substr( $text, 0, $cut ) ), trim( substr( $text, $cut ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    protected static function string_arg( array $arguments, string $key, int $min, int $max ): ?string {
        if ( ! array_key_exists( $key, $arguments ) || null === $arguments[ $key ] ) {
            return null;
        }
        $value = $arguments[ $key ];
        if ( ! is_string( $value ) ) {
            throw new Detailed_Tool_Error( sprintf( '%s must be a string.', $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
        if ( $length < $min || $length > $max ) {
            throw new Detailed_Tool_Error( sprintf( '%1$s must be %2$d to %3$d characters.', $key, $min, $max ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $value;
    }

    protected static function int_arg( array $arguments, string $key, int $min, ?int $max = null ): ?int {
        if ( ! array_key_exists( $key, $arguments ) || null === $arguments[ $key ] ) {
            return null;
        }
        $value = $arguments[ $key ];
        if ( is_string( $value ) && 1 === preg_match( '/^\d+$/D', $value ) ) {
            $value = (int) $value;
        }
        if ( ! is_int( $value ) || $value < $min || ( null !== $max && $value > $max ) ) {
            throw new Detailed_Tool_Error( sprintf( null === $max ? '%1$s must be an integer of %2$d or more.' : '%1$s must be an integer from %2$d to %3$d.', $key, $min, (int) $max ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $value;
    }

    protected static function bool_arg( array $arguments, string $key ): ?bool {
        if ( ! array_key_exists( $key, $arguments ) || null === $arguments[ $key ] ) {
            return null;
        }
        if ( ! is_bool( $arguments[ $key ] ) && ! in_array( $arguments[ $key ], array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
            throw new Detailed_Tool_Error( sprintf( '%s must be true or false.', $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return \rest_sanitize_boolean( $arguments[ $key ] );
    }

    protected static function enum_arg( array $arguments, string $key, array $allowed ): ?string {
        if ( ! array_key_exists( $key, $arguments ) || null === $arguments[ $key ] ) {
            return null;
        }
        if ( ! is_string( $arguments[ $key ] ) || ! in_array( $arguments[ $key ], $allowed, true ) ) {
            throw new Detailed_Tool_Error( sprintf( '%1$s must be one of: %2$s.', $key, implode( ', ', $allowed ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $arguments[ $key ];
    }

    protected static function path_arg( array $arguments, string $key ): ?string {
        $path = self::string_arg( $arguments, $key, 1, 100 );
        if ( null !== $path && 1 !== preg_match( self::PATH_PATTERN, $path ) ) {
            throw new Detailed_Tool_Error( sprintf( '%s must be zero-based indexes joined by dots, e.g. "0.2.1".', $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $path;
    }

    protected static function type_property(): array {
        return array(
            'type'        => 'string',
            'enum'        => self::POST_KINDS,
            'description' => 'page (default), post or product (WooCommerce).',
        );
    }

    protected static function post_id_property( string $description = 'The id of the page, post or product (blocks_list_posts lists them).' ): array {
        return array(
            'type'        => 'integer',
            'minimum'     => 1,
            'description' => $description,
        );
    }

    protected static function path_property( string $description ): array {
        return array(
            'type'        => 'string',
            'pattern'     => '^\\d+(\\.\\d+)*$',
            'maxLength'   => 100,
            'description' => $description,
        );
    }

    protected static function ref_property(): array {
        return array(
            'type'        => 'string',
            'minLength'   => 1,
            'maxLength'   => self::MAX_REF_CHARS,
            'description' => 'A ref from blocks_execute (tpl_…).',
        );
    }

    protected static function megabytes( int $bytes ): string {
        return number_format( $bytes / 1024 / 1024, 1, '.', '' );
    }
}
