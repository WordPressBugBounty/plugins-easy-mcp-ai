<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Block_Checks;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;
use Easy_MCP_AI\MCP\Error_Codes;
use Easy_MCP_AI\MCP\Server;
use Easy_MCP_AI\MCP\Tool_Status;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Local_Block_Tool extends Cloud_Block_Tool {

    const REST_BASE = array(
        'page'    => 'pages',
        'post'    => 'posts',
        'product' => 'product',
    );

    private const REFUSED_BEFORE_APPROVAL = array( 'off', 'off_locked', 'integration_off', 'integration_off_locked', 'not_registered' );

    const OTHER_TYPE = '/^Error: (?:Use wp_\w+ for that ID, which has post type|ID \d+ has post type) "([a-z0-9_-]{1,20})", not "[a-z0-9_-]{1,20}"/';

    const INVALID_ID = '/^Error: Invalid (?:post )?ID\./';

    private $dispatcher = null;

    public function is_composite( array $arguments = array() ) {
        return true;
    }

    public function set_dispatcher( callable $dispatcher ): void {
        $this->dispatcher = $dispatcher;
    }

    protected function request( string $tool, array $args, bool $write = false, ?array $target = null ): array {
        $approval = $write && 'redeem' === Server::current_call_approval_kind() ? 'skip' : null;
        if ( null !== $this->dispatcher ) {
            $out = call_user_func( $this->dispatcher, $tool, $args, $approval );
        } else {
            $out = Server::call_as_current_caller( $tool, $args, $approval, false );
        }
        if ( null === $out ) {
            throw new \RuntimeException( sprintf( '%s runs only inside an MCP tools/call.', $this->get_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $response = isset( $out['response'] ) && is_array( $out['response'] ) ? $out['response'] : array();
        $reply    = array(
            'data'        => null,
            'text'        => '',
            'refused'     => false,
            'tool_error'  => false,
            'raw'         => '',
            'paused'      => false,
            'approve_url' => '',
        );
        if ( 'paused' === ( $out['approval']['kind'] ?? '' ) ) {
            $approval_id = isset( $out['approval']['approval_id'] ) ? (string) $out['approval']['approval_id'] : '';
            $reply['paused'] = true;
            if ( '' !== $approval_id && class_exists( '\\Easy_MCP_AI\\Approvals\\Approval_Gate' ) ) {
                $reply['approve_url'] = \Easy_MCP_AI\Approvals\Approval_Gate::approve_url( $approval_id );
                $reply['text']        = sprintf( "%s needs a person's approval on this site. Open the approval link to approve it, then call again.", $tool );
            } else {
                $reply['text'] = sprintf( "%s needs a person's approval on this site; approve it, then call again.", $tool );
            }
            return $reply;
        }
        if ( isset( $response['error'] ) ) {
            $message          = is_array( $response['error'] ) ? (string) ( $response['error']['message'] ?? 'the call was refused' ) : (string) $response['error'];
            $reply['text']    = $tool . ': ' . $message;
            if ( 'Unknown tool' === $message ) {
                $predicate = self::unavailable_predicate( $tool );
                if ( null !== $predicate ) {
                    $reply['text'] = $tool . ' ' . $predicate;
                }
            }
            $reply['refused'] = is_array( $response['error'] ) && Error_Codes::FORBIDDEN === ( $response['error']['code'] ?? null );
            return $reply;
        }
        $result = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
        $texts  = array();
        foreach ( (array) ( $result['content'] ?? array() ) as $block ) {
            if ( is_array( $block ) && isset( $block['text'] ) && is_string( $block['text'] ) ) {
                $texts[] = $block['text'];
            }
        }
        $reply['raw'] = $texts[0] ?? '';
        if ( ! empty( $result['isError'] ) ) {
            $reply['tool_error'] = true;
            $first               = 0 === strpos( $reply['raw'], 'Error: ' ) ? substr( $reply['raw'], 7 ) : $reply['raw'];
            $reply['text']       = $tool . ': ' . trim( implode( "\n", array_merge( array( $first ), array_slice( $texts, 1 ) ) ) );
            if ( null !== $target ) {
                $reply['text'] = $this->explain_other_type( $reply, $target );
            }
            return $reply;
        }
        if ( isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ) {
            $reply['data'] = $result['structuredContent'];
            return $reply;
        }
        $decoded = json_decode( $reply['raw'], true );
        if ( is_array( $decoded ) ) {
            $reply['data'] = $decoded;
            return $reply;
        }
        $reply['text'] = $tool . ': ' . ( '' === $reply['raw'] ? 'answered without a message.' : $reply['raw'] );
        return $reply;
    }

    protected function refuse_unavailable( string $inner, string $doing ): void {
        $predicate = self::unavailable_predicate( $inner, self::REFUSED_BEFORE_APPROVAL );
        if ( null === $predicate ) {
            return;
        }
        throw self::refusal( sprintf( '%1$s %2$s through %3$s, which %4$s', $this->get_name(), $doing, $inner, $predicate ) );
    }

    protected static function unavailable_predicate( string $tool, ?array $only = null ): ?string {
        $status = Server::current_call_tool_status( $tool );
        if ( null === $status || 'available' === $status['status'] || ( null !== $only && ! in_array( $status['status'], $only, true ) ) ) {
            return null;
        }
        $predicate = Tool_Status::predicate( $status );
        return '' === $predicate ? null : $predicate;
    }

    protected function ask( string $tool, array $args, bool $write = false, ?array $target = null ): array {
        $reply = $this->request( $tool, $args, $write, $target );
        if ( null === $reply['data'] ) {
            throw self::refusal_for( $reply );
        }
        return $reply['data'];
    }

    protected static function refusal_for( array $reply ): Detailed_Tool_Error {
        $refusal = self::refusal( $reply['text'] );
        if ( '' === $reply['approve_url'] ) {
            return $refusal;
        }
        return new Detailed_Tool_Error( $refusal->getMessage(), $refusal->get_details(), $reply['approve_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    protected function ask_quietly( string $tool, array $args ): ?array {
        return $this->request( $tool, $args )['data'];
    }

    private function explain_other_type( array $reply, array $target ): string {
        $tool = $target['tool'];
        $id   = $target['postId'];
        $type = $target['type'];
        if ( 1 === preg_match( self::OTHER_TYPE, $reply['raw'], $other ) ) {
            $actual = $other[1];
            return in_array( $actual, self::POST_KINDS, true )
                ? sprintf( '%1$d is a %2$s, not a %3$s. Call %4$s again with type: "%2$s".', $id, $actual, $type, $tool )
                : sprintf( '%1$d has post type "%2$s", not a %3$s. %4$s works on pages, posts and products only.', $id, $actual, $type, $tool );
        }
        if ( 1 === preg_match( self::INVALID_ID, $reply['raw'] ) ) {
            $others = array();
            foreach ( self::POST_KINDS as $kind ) {
                if ( $kind !== $type ) {
                    $others[] = '"' . $kind . '"';
                }
            }
            return $reply['text'] . "\n" . sprintf( "If %1\$d isn't a %2\$s, call %3\$s again with its type: %4\$s (blocks_list_posts lists each type's ids).", $id, $type, $tool, implode( ' or ', $others ) );
        }
        return $reply['text'];
    }

    protected static function target( string $tool, int $post_id, string $type ): array {
        return array(
            'tool'   => $tool,
            'postId' => $post_id,
            'type'   => $type,
        );
    }

    protected function read_modified( string $tool, string $type, int $post_id ): ?string {
        return self::modified_of( $this->ask( self::read_tool( $type ), self::read_arguments( $type, $post_id ), false, self::target( $tool, $post_id, $type ) ) );
    }

    protected function read_modified_quietly( string $type, int $post_id ): ?string {
        $data = $this->ask_quietly( self::read_tool( $type ), self::read_arguments( $type, $post_id ) );
        return null === $data ? null : self::modified_of( $data );
    }

    protected function target_line( string $type, int $post_id ): ?string {
        $is_product = 'product' === $type;
        $data       = $this->peek(
            ( $is_product ? '/wc/v3/products/' : '/wp/v2/' . self::REST_BASE[ $type ] . '/' ) . $post_id,
            $is_product ? 'id,name,status,date_modified' : 'id,title,status,modified'
        );
        if ( null === $data ) {
            return null;
        }
        $facts    = array();
        $status   = $data['status'] ?? null;
        $modified = self::modified_of( $data );
        if ( is_string( $status ) && '' !== $status ) {
            $facts[] = 'publish' === $status ? 'published' : ( 'future' === $status ? 'scheduled' : self::short( $status, 20 ) );
        }
        if ( null !== $modified ) {
            $facts[] = 'last changed ' . $modified;
        }
        return sprintf( '%1$s #%2$d "%3$s"%4$s', $type, $post_id, self::short( self::rest_title( $data, $is_product ? 'name' : 'title' ) ), array() === $facts ? '' : ' (' . implode( ', ', $facts ) . ')' );
    }

    protected static function short( string $text, int $max = 100 ): string {
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
        if ( $length <= $max ) {
            return $text;
        }
        return ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max ) ) . '...';
    }

    protected static function join_clauses( array $clauses ): string {
        $last = array_pop( $clauses );
        return array() === $clauses ? (string) $last : implode( ', ', $clauses ) . ' and ' . $last;
    }

    protected static function read_arguments( string $type, int $post_id ): array {
        $key = 'page' === $type ? 'page_id' : ( 'post' === $type ? 'post_id' : 'id' );
        return array( $key => $post_id );
    }

    protected static function read_tool( string $type ): string {
        return 'page' === $type ? 'wp_get_page' : ( 'post' === $type ? 'wp_get_post' : 'wp_wc_get_product' );
    }

    protected static function modified_of( array $data ): ?string {
        $value = $data['modified'] ?? $data['date_modified'] ?? null;
        return is_string( $value ) && 1 === preg_match( '/^[0-9T:+.\- Z]{10,40}$/D', $value ) ? $value : null;
    }

    protected static function block_type_names( array $data ): array {
        $names = array();
        foreach ( (array) ( $data['block_types'] ?? array() ) as $type ) {
            if ( is_array( $type ) && isset( $type['name'] ) && is_string( $type['name'] ) ) {
                $names[] = $type['name'];
            }
        }
        return $names;
    }

    protected static function namespace_of( string $block_type ): string {
        $slash = strpos( $block_type, '/' );
        return false === $slash ? 'core' : substr( $block_type, 0, $slash );
    }

    protected function check_block_plugins( array $block_types, array &$warnings ): void {
        $by_namespace = array();
        foreach ( $block_types as $block_type ) {
            $namespace = self::namespace_of( $block_type );
            if ( 'core' !== $namespace || in_array( $block_type, Block_Checks::VERSION_GATED_CORE_BLOCKS, true ) ) {
                $by_namespace[ $namespace ][ $block_type ] = true;
            }
        }
        foreach ( $by_namespace as $namespace => $missing ) {
            $core = 'core' === (string) $namespace;
            for ( $page = 1; $page <= Block_Checks::MAX_BLOCK_CHECK_CALLS && array() !== $missing; $page++ ) {
                $args  = array(
                    'search'   => $core ? 'core/' : (string) $namespace,
                    'per_page' => Block_Checks::BLOCK_TYPES_PER_PAGE,
                );
                $args  = $page > 1 ? $args + array( 'page' => $page ) : $args;
                $reply = $this->request( 'wp_list_block_types', $args );
                if ( $core && null === $reply['data'] ) {
                    $names = array_keys( $missing );
                    sort( $names, SORT_STRING );
                    $warnings[] = sprintf(
                        "Couldn't check whether this site has the newer WordPress blocks this page uses (%1\$s): %2\$s. A site without them saves them as unknown blocks.",
                        implode( ', ', array_slice( $names, 0, Block_Checks::LISTED_MISSING_TYPES ) ),
                        $reply['refused'] ? 'that needs blocks read' : ( $reply['paused'] ? sprintf( "that check needs a person's approval (%s)", $reply['approve_url'] ) : 'the site did not answer the check' )
                    );
                    $missing = array();
                    break;
                }
                if ( null === $reply['data'] ) {
                    throw self::refusal_for( $reply );
                }
                foreach ( self::block_type_names( $reply['data'] ) as $name ) {
                    unset( $missing[ $name ] );
                }
                $total_pages = $reply['data']['total_pages'] ?? null;
                if ( ! ( is_numeric( $total_pages ) && $total_pages > $page ) ) {
                    break;
                }
            }
            if ( array() !== $missing ) {
                throw self::refusal( self::missing_text( (string) $namespace, array_keys( $missing ) ) );
            }
        }
    }

    private static function missing_text( string $namespace, array $missing ): string {
        sort( $missing, SORT_STRING );
        $listed = array_slice( $missing, 0, Block_Checks::LISTED_MISSING_TYPES );
        $names  = 'Missing block types: ' . implode( ', ', $listed ) . ( count( $missing ) > count( $listed ) ? sprintf( ' and %d more', count( $missing ) - count( $listed ) ) : '' ) . '.';
        if ( 'core' === $namespace ) {
            $never = array_values( array_intersect( $missing, Block_Checks::UNREGISTERED_CORE_BLOCKS ) );
            return array() !== $never
                ? sprintf( "This page uses blocks that this site doesn't have and that current WordPress doesn't register (%1\$s), so updating WordPress won't help. Build the page without them. %2\$s", implode( ', ', $never ), $names )
                : "This page uses WordPress blocks that this site doesn't have: they come with a newer WordPress or the Gutenberg plugin. Update WordPress or install Gutenberg, then try again. " . $names;
        }
        if ( 'atomic-wind' === $namespace ) {
            return "This page uses Atomic Wind blocks, which aren't turned on at this site. Atomic Wind is a module of Otter Blocks (3.2 or later): make sure Otter Blocks is active and turn on \"Enable Atomic Wind Blocks\" in Otter's settings, then try again. " . $names;
        }
        $pro = array_values( array_intersect( $missing, Block_Checks::OTTER_PRO_BLOCKS ) );
        if ( array() !== $pro && count( $pro ) === count( $missing ) ) {
            return sprintf( "This page uses Otter Pro blocks (%1\$s), which this site doesn't have: they come with Otter Pro. Activate Otter Pro, or build the page without them, then try again. %2\$s", implode( ', ', $pro ), $names );
        }
        $pro_note = array() !== $pro ? sprintf( ' %1$s also need%2$s Otter Pro.', implode( ', ', $pro ), 1 === count( $pro ) ? 's' : '' ) : '';
        $plugin   = Block_Checks::PLUGIN_NAMES[ $namespace ] ?? null;
        return null !== $plugin
            ? sprintf( "This page uses %1\$s, which isn't active on this site. Install and activate %1\$s, then try again.%2\$s %3\$s", $plugin, $pro_note, $names )
            : sprintf( "This page uses %1\$s blocks, which aren't active on this site. Install and activate the plugin that provides them, then try again. %2\$s", $namespace, $names );
    }

    protected function size_warning( int $bytes ): string {
        return sprintf(
            'This sends %1$s MB at once. Hosts with a small post_max_size or a firewall may refuse over %2$s MB; build in sections if it fails.',
            self::megabytes( $bytes ),
            self::megabytes( Block_Checks::RECOMMENDED_REQUEST_BYTES )
        );
    }

    protected function confirm_text( string $what ): string {
        return sprintf(
            "A product keeps no revisions and no tool returns its full raw description (blocks_get shows the block structure, attributes and the start of each block's text), so %s can't be undone from here. Copy the current description from the product's editor first if you need it, then call again with confirm: true.",
            $what
        );
    }

    protected static function is_positive_int( $value ): bool {
        return is_int( $value ) && $value > 0;
    }
}
