<?php
namespace Easy_MCP_AI\Tools\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Batch_Reply {

    protected static function short_batch_reply( $reply, array $body ) {
        if ( ! is_array( $reply ) ) {
            return $reply;
        }
        foreach ( array( 'create', 'update' ) as $operation ) {
            if ( ! isset( $reply[ $operation ] ) || ! is_array( $reply[ $operation ] ) ) {
                continue;
            }
            $sent = array_values( $body[ $operation ] ?? array() );
            $rows = array();
            foreach ( array_values( $reply[ $operation ] ) as $i => $row ) {
                $rows[] = self::short_batch_row( $row, $sent[ $i ] ?? array() );
            }
            $reply[ $operation ] = $rows;
        }
        if ( isset( $reply['delete'] ) && is_array( $reply['delete'] ) ) {
            foreach ( $reply['delete'] as $i => $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $id                    = self::deleted_id( $row['id'] ?? null );
                $reply['delete'][ $i ] = isset( $row['error'] )
                    ? array(
                        'id'      => $id,
                        'deleted' => false,
                        'error'   => $row['error'],
                    )
                    : array(
                        'id'      => $id,
                        'status'  => 'deleted',
                        'deleted' => true,
                    );
            }
        }
        return $reply;
    }

    protected static function refuse_kept_deletes( $reply, callable $kept ) {
        if ( ! is_array( $reply ) || ! isset( $reply['delete'] ) || ! is_array( $reply['delete'] ) ) {
            return $reply;
        }
        foreach ( $reply['delete'] as $i => $row ) {
            if ( ! is_array( $row ) || true !== ( $row['deleted'] ?? null ) || ! is_numeric( $row['id'] ?? null ) ) {
                continue;
            }
            $refusal = $kept( (int) $row['id'] );
            if ( null !== $refusal ) {
                $reply['delete'][ $i ] = array(
                    'id'      => $row['id'],
                    'deleted' => false,
                    'error'   => array(
                        'code'    => 'easy_mcp_ai_delete_stopped',
                        'message' => $refusal,
                    ),
                );
            }
        }
        return $reply;
    }

    private static function deleted_id( $id ) {
        return is_array( $id ) && isset( $id['id'] ) && is_scalar( $id['id'] ) ? (int) $id['id'] : $id;
    }

    private static function short_batch_row( $row, $sent ) {
        if ( ! is_array( $row ) ) {
            return $row;
        }
        $keys = array_merge( array( 'id', 'status', 'error' ), is_array( $sent ) ? array_map( 'strval', array_keys( $sent ) ) : array() );
        $out  = array();
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $row ) ) {
                $out[ $key ] = $row[ $key ];
            }
        }
        return $out;
    }
}
