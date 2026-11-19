<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Block_Checks;
use Easy_MCP_AI\Cloud\Cloud_Client;
use Easy_MCP_AI\Cloud\Cloud_Error;
use Easy_MCP_AI\Cloud\Cloud_Refs;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks_Execute extends Cloud_Block_Tool {

    const MAX_CODE_CHARS = 500000;

    const MAX_LISTED_ERRORS_CHARS = 1800;

    const WARNINGS_HEADING = 'Warnings (the editor may flag these blocks as invalid or change them when the page is opened; fix the code unless you accept that):';

    public function get_redacted_arguments() {
        return array( 'code' );
    }

    protected function reaches_cloud(): bool {
        return true;
    }

    public function get_name() {
        return 'blocks_execute';
    }

    public function get_title() {
        return 'Build blocks';
    }

    public function get_description() {
        return "Build WordPress Gutenberg blocks (a page, a section, a pattern) from JavaScript, and get a ref to publish with blocks_publish or add to a page with blocks_edit. It changes nothing on this site.\nCall blocks_schema_get first. The code uses only blocks.*, otter.* and atomicWind.* and assigns the final block array to template (template = [...]).\nThis site keeps the newest 50 refs, each for up to 7 days. Invalid blocks answer structured errors: fix the code and call again.";
    }

    public function get_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'code' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => self::MAX_CODE_CHARS,
                    'description' => 'JavaScript using only blocks.*, otter.* and atomicWind.*, assigning the final block array to template (template = [...]).',
                ),
            ),
            'required'             => array( 'code' ),
        );
    }

    protected function perform( array $arguments ) {
        $code = self::string_arg( $arguments, 'code', 1, self::MAX_CODE_CHARS );
        if ( null === $code ) {
            throw new Detailed_Tool_Error( 'Missing required parameters: code' );
        }
        $answer = $this->client()->execute( $code );
        if ( false === ( $answer['ok'] ?? null ) ) {
            throw self::build_errors( isset( $answer['errors'] ) && is_array( $answer['errors'] ) ? $answer['errors'] : array() );
        }
        if ( true !== ( $answer['ok'] ?? null ) || ! isset( $answer['markup'] ) || ! is_string( $answer['markup'] ) ) {
            throw new Cloud_Error( Cloud_Client::MESSAGE_LATER );
        }
        $summary = isset( $answer['summary'] ) && is_array( $answer['summary'] ) ? $answer['summary'] : array();
        $count   = isset( $summary['block_count'] ) && is_int( $summary['block_count'] ) ? $summary['block_count'] : null;
        if ( '' === trim( $answer['markup'] ) || 0 === $count ) {
            throw self::refusal( Block_Checks::NOTHING_BUILT );
        }
        if ( null === $count ) {
            throw new Cloud_Error( Cloud_Client::MESSAGE_LATER );
        }
        $sections = self::strings( $summary['sections'] ?? array() );
        $types    = self::strings( $answer['block_types'] ?? array() );
        $warnings = self::strings( $answer['warnings'] ?? array() );

        $stored = Cloud_Refs::put( $answer['markup'], $types, array( 'sections' => $sections, 'blockCount' => $count ) );

        $lines  = array(
            sprintf( 'Built %1$d %2$s%3$s.', $count, 1 === $count ? 'block' : 'blocks', array() !== $sections ? ' (sections: ' . implode( ', ', $sections ) . ')' : '' ),
            sprintf( 'Ref %1$s, valid until %2$s. Publish it with blocks_publish, or add it to a page with blocks_edit.', $stored['ref'], $stored['expiresAt'] ),
        );
        $output = array(
            'ref'       => $stored['ref'],
            'expiresAt' => $stored['expiresAt'],
            'summary'   => array(
                'sections'   => $sections,
                'blockCount' => $count,
                'blockTypes' => $types,
            ),
        );
        if ( array() !== $warnings ) {
            $output['warnings'] = $warnings;
            $lines[]            = self::WARNINGS_HEADING;
            foreach ( $warnings as $warning ) {
                $lines[] = '- ' . $warning;
            }
        }
        $output['message'] = implode( "\n", $lines );
        return $output;
    }

    private static function strings( $value ): array {
        return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
    }

    private static function build_errors( array $errors ): Detailed_Tool_Error {
        $lines = array();
        foreach ( $errors as $error ) {
            if ( ! is_array( $error ) ) {
                continue;
            }
            $field = static function ( string $key ) use ( $error ): string {
                return isset( $error[ $key ] ) && is_string( $error[ $key ] ) ? mb_substr( $error[ $key ], 0, 300 ) : '';
            };
            $lines[] = sprintf( '- %1$s (%2$s): %3$s', $field( 'blockPath' ), $field( 'attribute' ), $field( 'message' ) );
        }
        $total = count( $lines );
        $shown = array();
        $size  = 0;
        foreach ( $lines as $line ) {
            $size += strlen( $line ) + 1;
            if ( $size > self::MAX_LISTED_ERRORS_CHARS ) {
                break;
            }
            $shown[] = $line;
        }
        if ( count( $shown ) < $total ) {
            $shown[] = sprintf( '- and %d more.', $total - count( $shown ) );
        }
        return new Detailed_Tool_Error( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            sprintf( "The blocks didn't build (%1\$d %2\$s). Fix the code and call blocks_execute again:", $total, 1 === $total ? 'error' : 'errors' ),
            implode( "\n", $shown )
        );
    }
}
