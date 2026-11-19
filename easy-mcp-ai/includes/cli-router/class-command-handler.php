<?php
namespace Easy_MCP_AI\Cli_Router;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Command_Handler {

    protected function formatter( Context $ctx, array &$assoc, ?array $fields = null, ?string $prefix = null ): Formatter {
        return new Formatter( $ctx->out, $assoc, $fields, $prefix );
    }

    protected function default_fields( Invocation $inv, array $fallback = array() ): array {
        $fields = $inv->leaf['fields']['default'] ?? null;
        return is_array( $fields ) && $fields ? array_values( $fields ) : $fallback;
    }

    protected function refuse_before_preview( Invocation $inv, Context $ctx, int $at, callable $refusal ): void {
        $args = array_values( $inv->args );
        if ( ! isset( $args[ $at ] ) || ! Self_Protection::refused_before_preview( (string) $args[ $at ] ) ) {
            return;
        }
        $write = ! in_array( (string) ( $inv->leaf['name'] ?? '' ), array( 'get', 'pluck' ), true );
        $ctx->out->error( (string) $refusal( (string) $args[ $at ], $write ) );
    }
}
