<?php
namespace Easy_MCP_AI\Tools\WooCommerce;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Delete_Outcome;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delete_Customer extends Base_Tool {

    public function get_name() {
        return 'wp_wc_delete_customer';
    }

    public function get_description() {
        return 'Permanently deletes a WooCommerce customer account by ID. Required: `id`. Deletion is immediate and irreversible — no trash. On a single site the WordPress user account is deleted with it. Orders placed by this customer are preserved (they retain the billing info). Returns { deleted: true, id, scope }: `scope` is "account" when the account was deleted, and on multisite "site", because WooCommerce then removes the customer from this site only and keeps the network account (as `wp_delete_user` without `network` does); for a customer who is not a member of this site the call is an error, because nothing is removed. If a plugin stops the delete, the call is an error. Requires WooCommerce active.';
    }

    public function get_category() {
        return 'woocommerce';
    }

    public function get_required_capability() {
        return 'manage_woocommerce';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id' => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the customer to delete.',
                ),
            ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            throw new \RuntimeException( 'WooCommerce is not active.' );
        }

        $this->validate_required( $arguments, array( 'id' ) );
        $id = $this->parse_required_id( $arguments['id'], 'id' );

        $was_member = is_multisite() ? Delete_Outcome::site_membership( $id ) : null;

        $this->rest_request( 'DELETE', '/wc/v3/customers/' . $id, array( 'force' => true ) );

        return array(
            'deleted' => true,
            'id'      => $id,
            'scope'   => self::outcome( $id, $was_member ),
        );
    }

    private static function outcome( int $id, ?bool $was_member ): string {
        $account = Delete_Outcome::stored_user( $id, 'customer ' . $id );
        if ( false === $account ) {
            return 'account';
        }
        if ( ! is_multisite() ) {
            throw new \RuntimeException( sprintf( 'Customer %d was not deleted: the account still exists. A plugin or theme on this site stopped it. %s', $id, Delete_Outcome::NOTHING_DELETED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $is_member = Delete_Outcome::site_membership( $id );
        if ( null === $was_member || null === $is_member ) {
            throw new \RuntimeException( sprintf( 'WooCommerce answered success for customer %d, but a database error stopped this site\'s membership being read to confirm it. Check the user under Users.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( ! $was_member ) {
            throw new \RuntimeException( sprintf( 'Customer %d is not a member of this site: WooCommerce answered success, but no account or site membership was removed.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( $is_member ) {
            throw new \RuntimeException( sprintf( 'Customer %d was not removed from this site: they are still a member. A plugin or theme on this site stopped it. %s', $id, Delete_Outcome::NOTHING_DELETED ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return 'site';
    }
}
