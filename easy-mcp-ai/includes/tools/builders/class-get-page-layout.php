<?php
namespace Easy_MCP_AI\Tools\Builders;

use Easy_MCP_AI\Builders\Builder_Registry;
use Easy_MCP_AI\Builders\Layout_Adapter;
use Easy_MCP_AI\MCP\Server;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Post_Type_Rest_Base;
use Easy_MCP_AI\Rest_Routes\Route_Gate;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Page_Layout extends Base_Tool {

    const DEFAULT_DEPTH = 4;
    const MAX_DEPTH     = 12;

    const REPLY_BUDGET = 50000;

    public function get_name() {
        return 'wp_get_page_layout';
    }

    public function get_description() {
        return 'Reads the layout of a post, page, template, UX Block or Divi Theme Builder layout built with a page builder (Elementor, Breakdance, WPBakery, Flatsome UX Builder, Divi 4 or Divi 5) from the builder\'s own stored data, which post tools do not show. Detects the builder per item. Required: `post_id`. Optional: `element` (an address from a previous read; returns that element with its settings), `depth` (levels to expand, default 4, max 12), `include_settings` (each element\'s stored settings; default false). Returns { post_id, builder, builder_version, version, editable, element_count, elements: [{ address, type, label, text (excerpt), image, link, flags, lists, children_count, children | has_children }] }; `lists` are an Elementor widget\'s lists of items (repeaters such as accordion tabs or icon-list items): [{ list, label, items: [{ item, label }], flags? }], where `item` is the id wp_update_page_layout takes with `list` (flags: `not_stored`, `no_ids`, `hidden`). `editable` says whether wp_update_page_layout can change the item (`edit_note` says why not), and `version` is what that edit sends. Addresses are Elementor or Breakdance element ids, or dot-joined zero-based paths for the other builders. `references` lists the reply\'s elements that render another post (a Flatsome UX Block, a Divi global module from its Divi Library item): edit that post instead. Flag `atomic` marks Elementor V4 elements, which Elementor\'s own abilities edit (see `vendor_route`, which for Breakdance names its own abilities for inserting, moving and removing elements); `code` marks elements that run HTML or script. A reply is cut to ' . number_format( self::REPLY_BUDGET ) . ' characters: deeper levels stop expanding first (has_children), then settings below the root, then an element read pages its children and lists, a list read its root elements (`*_omitted`); a cut list gives `next_offset` for `list_offset`; a cut reply has `truncated: true` and a `hint`. For a Gutenberg or classic post `builder` is null and `use` names the tool to read it. Requires edit access to the item.';
    }

    public function get_category() {
        return 'builders';
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'post_id'          => array(
                    'type'        => 'integer',
                    'description' => 'ID of the post, page, template or UX Block.',
                ),
                'element'          => array(
                    'type'        => 'string',
                    'description' => 'Address of one element, as a previous read returned it (an Elementor id such as "a1b2c3d", or a path such as "0.1.2"). Returns that element with its settings and its children, within the same ' . number_format( self::REPLY_BUDGET ) . '-character budget.',
                ),
                'depth'            => array(
                    'type'        => 'integer',
                    'description' => 'How many levels to expand (1 = root elements only). Default 4, max 12.',
                    'default'     => self::DEFAULT_DEPTH,
                    'minimum'     => 1,
                    'maximum'     => self::MAX_DEPTH,
                ),
                'list_offset'      => array(
                    'type'        => 'integer',
                    'description' => 'Skip this many leading entries of each list the reply pages, to read what a cut reply left out: the root elements of a read without `element`; with `element`, its children and each of its lists of items (and their rows in `settings`). Pass the `next_offset` a cut reply gives. Default 0.',
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'include_settings' => array(
                    'type'        => 'boolean',
                    'description' => 'Include each element\'s stored settings or attributes. Default false (an element read with `element` always has them).',
                    'default'     => false,
                ),
            ),
            'required'   => array( 'post_id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'post_id' ) );
        $post_id = $this->parse_required_id( $arguments['post_id'], 'post_id' );
        $depth   = isset( $arguments['depth'] ) ? min( self::MAX_DEPTH, max( 1, absint( $arguments['depth'] ) ) ) : self::DEFAULT_DEPTH;
        $element = isset( $arguments['element'] ) ? trim( (string) $arguments['element'] ) : '';
        $with    = ! empty( $arguments['include_settings'] );
        $offset  = self::list_offset( $arguments['list_offset'] ?? 0 );

        $post = get_post( $post_id );
        if ( ! is_object( $post ) || empty( $post->ID ) || 'revision' === ( $post->post_type ?? '' ) ) {
            throw new \RuntimeException( sprintf( 'No post with ID %d.', $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            throw new \RuntimeException( sprintf( 'You cannot edit post %d, so its layout cannot be read.', $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $adapter = Builder_Registry::for_post( $post );
        if ( null === $adapter ) {
            return self::fits_or_refuse( $this->not_a_builder_page( $post ), 'Read the post with wp_get_post.' );
        }

        $nodes = $adapter->nodes( $post );
        $reply = array(
            'post_id'         => $post_id,
            'post_type'       => $post->post_type,
            'title'           => $post->post_title,
            'builder'         => $adapter->id(),
            'builder_label'   => $adapter->label(),
            'builder_version' => $adapter->version(),
            'version'         => $adapter->version_token( $post ),
            'element_count'   => self::count_nodes( $nodes ),
        );
        $refusal           = $adapter->edit_refusal( $post );
        $reply['editable'] = null === $refusal;
        if ( null !== $refusal ) {
            $reply['edit_note'] = $refusal;
        }

        if ( '' !== $element ) {
            $node = self::find( $nodes, $element );
            if ( null === $node ) {
                throw new \InvalidArgumentException( self::not_an_element( $nodes, $element, $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            if ( 0 < $offset && array() === $node['lists'] && array() === $node['children'] ) {
                throw new \InvalidArgumentException( sprintf( 'Element "%s" has no children and no lists of items, so `list_offset` has nothing to page. Read it without `list_offset`.', $element ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $key      = 'element';
            $roots    = array( $node );
            $settings = PHP_INT_MAX;
        } else {
            $key      = 'elements';
            $roots    = array_slice( $nodes, $offset );
            $settings = $with ? PHP_INT_MAX : 0;
            if ( 0 < $offset ) {
                $reply['offset'] = $offset;
            }
        }
        $reply[ $key ] = null;

        $refs = array(
            'self'    => 'element' === $key ? $adapter->references( array( array( 'children' => array() ) + $roots[0] ) ) : array(),
            'entries' => array(),
        );
        foreach ( 'element' === $key ? $roots[0]['children'] : $roots as $entry ) {
            $refs['entries'][] = $adapter->references( array( $entry ) );
        }
        $reply['references'] = array();
        $route               = $adapter->vendor_route( $post, $nodes );
        if ( null !== $route ) {
            $reply['vendor_route'] = $route;
        }
        return self::within_budget( $reply, $key, $roots, $depth, $settings, $offset, $refs, $this->raw_read( $adapter, $post ) );
    }

    private function raw_read( Layout_Adapter $adapter, object $post ): ?array {
        $type   = (string) ( $post->post_type ?? '' );
        $object = get_post_type_object( $type );
        if ( true !== Server::current_call_may_use( 'wp_get_post_full' ) || ! is_object( $object ) ) {
            return null;
        }
        $found = Post_Type_Rest_Base::inspect( $type, $object );
        if ( Post_Type_Rest_Base::OK !== $found['reason'] ) {
            return null;
        }
        try {
            $base = $this->resolve_post_type_rest_base( $found['base'], 'post_type' );
            if ( Post_Type_Rest_Base::require_served_type( $base )[0] !== $type ) {
                return null;
            }
            self::post_type_route( $base, (int) $post->ID, 'GET' );
            $this->refuse_switched_off_post_type_route( 'GET', $base, true );
        } catch ( \InvalidArgumentException | \RuntimeException $e ) {
            return null;
        }
        if ( ! self::route_permits_read( $base, (int) $post->ID ) ) {
            return null;
        }
        $arguments = array(
            'id'        => (int) $post->ID,
            'post_type' => $base,
            'context'   => 'edit',
            'exclude'   => array( 'content' ),
        );
        $keys      = $adapter->layout_meta_keys();
        $arguments += array() === $keys
            ? array(
                'include'  => array(),
                'raw_post' => true,
            )
            : array(
                'include'   => array( 'meta' ),
                'meta_keys' => array( $keys[0] ),
            );
        return array(
            'tool'      => 'wp_get_post_full',
            'arguments' => $arguments,
        );
    }

    private static function route_permits_read( string $base, int $id ): bool {
        $server = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
        if ( ! is_object( $server ) ) {
            return true;
        }
        $path = '/' . Post_Type_Rest_Base::route_namespace( $base ) . '/' . $base . '/' . $id;
        try {
            $found = Route_Gate::resolve( $server, 'GET', $path );
        } catch ( \RuntimeException $e ) {
            return false;
        }
        $handler = $found['handler'];
        $request = new \WP_REST_Request( 'GET', $path );
        $request->set_url_params( $found['url_params'] );
        $request->set_query_params( array( 'context' => 'edit' ) );
        $defaults = array();
        foreach ( (array) ( $handler['args'] ?? array() ) as $arg => $options ) {
            if ( is_array( $options ) && isset( $options['default'] ) ) {
                $defaults[ $arg ] = $options['default'];
            }
        }
        $request->set_attributes( $handler );
        $request->set_default_params( $defaults );
        if ( is_wp_error( $request->has_valid_params() ) || is_wp_error( $request->sanitize_params() ) ) {
            return false;
        }
        $callback = $handler['permission_callback'] ?? null;
        if ( empty( $callback ) ) {
            return true;
        }
        if ( ! is_callable( $callback ) ) {
            return false;
        }
        $controller = is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ? $callback[0] : null;
        $filter     = null !== $controller && method_exists( $controller, 'check_password_required' ) ? array( $controller, 'check_password_required' ) : null;
        $had        = null !== $filter && false !== has_filter( 'post_password_required', $filter );
        $allowed    = call_user_func( $callback, $request );
        if ( null !== $filter && ! $had ) {
            remove_filter( 'post_password_required', $filter );
        }
        return ! is_wp_error( $allowed ) && false !== $allowed && null !== $allowed;
    }

    private static function list_offset( $value ): int {
        if ( is_string( $value ) ) {
            $value = trim( $value );
            if ( '' === $value ) {
                return 0;
            }
        }
        if ( ! is_numeric( $value ) || round( (float) $value ) !== (float) $value || (float) $value < 0 || (float) $value >= (float) PHP_INT_MAX ) {
            throw new \InvalidArgumentException( sprintf( '`list_offset` must be a whole number of 0 or more, not %s.', (string) wp_json_encode( $value ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return (int) $value;
    }

    private static function not_an_element( array $nodes, string $address, int $post_id ): string {
        $found = self::find_item( $nodes, $address );
        if ( null === $found ) {
            return sprintf( 'No element "%s" in post %d. Read the layout without `element` to see the addresses.', $address, $post_id );
        }
        return sprintf( '"%1$s" is item %2$d of the list "%3$s" of element "%4$s", not an element. Read that element with `element` "%4$s" and `list_offset` %2$d to start its lists at this item.', $address, $found['index'], $found['list'], $found['element'] );
    }

    private static function find_item( array $nodes, string $item ): ?array {
        foreach ( $nodes as $node ) {
            foreach ( $node['lists'] as $list ) {
                foreach ( array_values( $list['items'] ) as $index => $entry ) {
                    if ( $entry['item'] === $item ) {
                        return array(
                            'element' => $node['address'],
                            'list'    => $list['list'],
                            'index'   => $index,
                        );
                    }
                }
            }
            $found = self::find_item( $node['children'], $item );
            if ( null !== $found ) {
                return $found;
            }
        }
        return null;
    }

    private static function within_budget( array $reply, string $key, array $roots, int $depth, int $settings, int $offset, array $refs, ?array $raw ): array {
        $passes = array( array( $depth, $settings ) );
        for ( $level = $depth - 1; $level >= 2; $level-- ) {
            $passes[] = array( $level, $settings );
        }
        if ( $settings > 1 ) {
            $passes[] = array( min( 2, $depth ), 1 );
        }
        $passes[] = array( 1, min( 1, $settings ) );
        $passes[] = array( 1, 0 );
        $passes   = array_values( array_unique( $passes, SORT_REGULAR ) );

        $cut = array(
            'key'         => $key,
            'depth'       => $depth,
            'settings'    => $settings,
            'offset'      => $offset,
            'nodes'       => $roots,
            'start'       => 'element' === $key ? min( $offset, count( $roots[0]['children'] ) ) : 0,
            'raw'         => $raw,
        );
        $page_depth = 'element' === $key && array() !== $roots[0]['children'] ? 2 : 1;
        $page_at    = 2 === $page_depth ? array( 1, min( 1, $settings ) ) : array( 1, 0 );
        $start = $cut['start'];
        foreach ( $passes as $i => $pass ) {
            if ( 'element' === $key && $page_at === $pass ) {
                $paged = self::paged_lists( $reply, $roots[0], $offset, $page_depth, $cut, $refs );
                if ( null !== $paged ) {
                    return $paged;
                }
            }
            $candidate         = self::with_references( $reply, self::page_refs( $refs, $start, null ) );
            $candidate[ $key ] = self::items( $key, $roots, $pass[0], $pass[1], $offset );
            if ( 0 < $i ) {
                $candidate = self::mark_cut( $candidate, $cut + array( 'got_depth' => $pass[0], 'got_settings' => $pass[1] ) );
            }
            if ( self::reply_size( $candidate ) <= self::REPLY_BUDGET ) {
                return $candidate;
            }
        }

        $cut += array( 'got_depth' => 1, 'got_settings' => 0 );
        if ( 'elements' === $key ) {
            $candidate = self::leading_items( $candidate, 'elements', $cut, $refs );
            if ( array() === $candidate['elements'] && array() !== $roots ) {
                $first     = self::items( 'elements', array( $roots[0] ), 1, 0, 0 );
                $candidate = self::with_leading( $candidate, 'elements', $first, count( $roots ) - 1, $cut, $refs );
            }
        }
        if ( isset( $candidate['references'] ) && self::reply_size( $candidate ) > self::REPLY_BUDGET ) {
            $candidate = self::leading_items( $candidate, 'references', $cut );
        }
        if ( 'element' === $key && isset( $candidate['element']['lists'] ) && self::reply_size( $candidate ) > self::REPLY_BUDGET ) {
            $candidate['lists_omitted'] = count( $candidate['element']['lists'] );
            unset( $candidate['element']['lists'] );
            $candidate = self::mark_cut( $candidate, $cut );
        }
        return self::fits_or_refuse( $candidate, 'It does not fit even with every element unexpanded, no settings and no references; read one element by passing its address as `element`.' );
    }

    private static function paged_lists( array $reply, array $node, int $offset, int $depth, array $cut, array $refs ): ?array {
        $starts = array();
        foreach ( $node['lists'] as $list ) {
            $starts[] = count( $list['items'] ) - $offset;
        }
        if ( 2 === $depth ) {
            $starts[] = count( $node['children'] ) - $offset;
        }
        $left = max( array_merge( array( 0 ), $starts ) );
        if ( $left < 1 ) {
            return null;
        }
        $starts[] = 1;
        $starts   = array_unique( array_filter( $starts, static function ( int $start ): bool {
            return $start >= 1;
        } ) );
        rsort( $starts );
        $first = min( $offset, count( $node['children'] ) );
        $build = static function ( int $count, int $settings ) use ( $reply, $node, $offset, $depth, $cut, $refs, $first ): array {
            $reply            = self::with_references( $reply, self::page_refs( $refs, $first, 2 === $depth ? $count : null ) );
            $reply['element'] = self::paged_item( $node, $offset, $count, $depth, $settings, false );
            return self::mark_cut( $reply, $cut + array( 'got_depth' => $depth, 'got_settings' => $settings ) );
        };
        $head_too_long = false;
        foreach ( array_unique( array( min( 1, $cut['settings'] ), 0 ) ) as $settings ) {
            if ( 0 === $settings && $head_too_long ) {
                foreach ( array_reverse( $starts ) as $count ) {
                    $page = $build( $count, $settings );
                    if ( self::reply_size( $page ) <= self::REPLY_BUDGET ) {
                        return $page;
                    }
                }
                break;
            }
            $high = $left;
            foreach ( $starts as $low ) {
                if ( self::reply_size( $build( $low, $settings ) ) > self::REPLY_BUDGET ) {
                    $high = $low - 1;
                    continue;
                }
                while ( $low < $high ) {
                    $mid = intdiv( $low + $high + 1, 2 );
                    if ( self::reply_size( $build( $mid, $settings ) ) <= self::REPLY_BUDGET ) {
                        $low = $mid;
                    } else {
                        $high = $mid - 1;
                    }
                }
                return $build( $low, $settings );
            }
            $head_too_long = 0 < $settings && self::reply_size( $build( 0, $settings ) ) <= self::REPLY_BUDGET;
        }
        $page = $build( 1, 0 );
        if ( 2 === $depth && $first < count( $node['children'] ) && isset( $page['references'] ) ) {
            $page = self::leading_items( $page, 'references', $cut + array( 'got_depth' => $depth, 'got_settings' => 0 ) );
            if ( self::reply_size( $page ) <= self::REPLY_BUDGET ) {
                return $page;
            }
        }
        return null;
    }

    private static function page_refs( array $refs, int $start, ?int $count ): array {
        $out = $refs['self'];
        foreach ( array_slice( $refs['entries'], $start, $count ) as $entry ) {
            $out = array_merge( $out, $entry );
        }
        return $out;
    }

    private static function with_references( array $reply, array $references ): array {
        unset( $reply['references_omitted'] );
        if ( array() === $references ) {
            unset( $reply['references'] );
            return $reply;
        }
        if ( array_key_exists( 'references', $reply ) ) {
            $reply['references'] = $references;
            return $reply;
        }
        $out = array();
        foreach ( $reply as $name => $value ) {
            $out[ $name ] = $value;
            if ( 'element' === $name || 'elements' === $name ) {
                $out['references'] = $references;
            }
        }
        return $out;
    }

    private static function paged_item( array $node, int $offset, ?int $count, int $depth, int $settings, bool $child_lists = true ): array {
        $total = count( $node['children'] );
        $start = min( $offset, $total );
        $paged = self::window( $node, $offset, $count );
        if ( $depth >= 2 ) {
            $paged['children'] = array_slice( $node['children'], $start, $count );
        }
        $item = self::item( $paged, 1, $depth, $settings );
        if ( $depth < 2 || 0 === $total ) {
            return $item;
        }
        if ( ! $child_lists ) {
            foreach ( $item['children'] ?? array() as $n => $child ) {
                if ( isset( $child['lists'] ) ) {
                    $item['children'][ $n ]['lists_omitted'] = count( $child['lists'] );
                    unset( $item['children'][ $n ]['lists'] );
                }
            }
        }
        $next                   = $start + count( $paged['children'] );
        $item['children_count'] = $total;
        if ( 0 < $offset ) {
            $item['offset'] = $offset;
        }
        if ( $next < $total ) {
            $item['children_omitted'] = $total - $next;
            $item['next_offset']      = $next;
        }
        return $item;
    }

    private static function window( array $node, int $offset, ?int $count ): array {
        foreach ( $node['lists'] as $n => $list ) {
            $total = count( $list['items'] );
            $start = min( $offset, $total );
            $kept  = array_slice( $list['items'], $start, $count );
            $next  = $start + count( $kept );
            $rows  = $node['settings'][ $list['list'] ] ?? null;
            if ( is_array( $rows ) && count( $rows ) === $total ) {
                $node['settings'][ $list['list'] ] = array_slice( $rows, $start, count( $kept ) );
            }
            $list['items'] = $kept;
            if ( 0 < $offset ) {
                $list['offset'] = $offset;
            }
            if ( $next < $total ) {
                $list['items_omitted'] = $total - $next;
                $list['next_offset']   = $next;
            }
            $node['lists'][ $n ] = $list;
        }
        return $node;
    }

    private static function fits_or_refuse( array $reply, string $next ): array {
        if ( self::reply_size( $reply ) > self::REPLY_BUDGET ) {
            throw new \RuntimeException( sprintf( 'The reply for post %1$d is longer than %2$s characters. %3$s', (int) $reply['post_id'], number_format( self::REPLY_BUDGET ), $next ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $reply;
    }

    private static function leading_items( array $reply, string $list, array $cut, array $refs = array() ): array {
        $items = array_values( (array) $reply[ $list ] );
        $total = count( $items );
        $kept  = array();
        $used  = 0;
        foreach ( $items as $n => $item ) {
            $size = self::reply_size( $item ) + ( 0 < $n ? 1 : 0 );
            foreach ( array() !== $refs ? $refs['entries'][ $n ] : array() as $reference ) {
                $size += self::reply_size( $reference ) + 1;
            }
            $base = self::reply_size( self::with_leading( $reply, $list, array(), $total - $n - 1, $cut, $refs ) );
            if ( $base + $used + $size > self::REPLY_BUDGET ) {
                break;
            }
            $kept[] = $item;
            $used  += $size;
        }
        $out = self::with_leading( $reply, $list, $kept, $total - count( $kept ), $cut, $refs );
        while ( array() !== $kept && self::reply_size( $out ) > self::REPLY_BUDGET ) {
            array_pop( $kept );
            $out = self::with_leading( $reply, $list, $kept, $total - count( $kept ), $cut, $refs );
        }
        return $out;
    }

    private static function with_leading( array $reply, string $list, array $kept, int $omitted, array $cut, array $refs = array() ): array {
        $reply[ $list ] = $kept;
        if ( array() !== $refs ) {
            $reply = self::with_references( $reply, self::page_refs( $refs, 0, count( $kept ) ) );
        }
        unset( $reply[ $list . '_omitted' ] );
        if ( 'elements' === $list ) {
            unset( $reply['next_offset'] );
        }
        if ( 0 < $omitted ) {
            $reply[ $list . '_omitted' ] = $omitted;
            if ( 'elements' === $list && array() !== $kept ) {
                $reply['next_offset'] = $cut['offset'] + count( $kept );
            }
        }
        return self::mark_cut( $reply, $cut );
    }

    private static function mark_cut( array $reply, array $cut ): array {
        $items = 'element' === $cut['key'] ? array( $reply['element'] ) : (array) $reply['elements'];
        $cuts  = array();
        if ( $cut['got_depth'] < $cut['depth'] && self::any_unexpanded( $items ) ) {
            $cuts[] = sprintf( 'elements below level %d are not expanded (has_children)', $cut['got_depth'] );
        }
        if ( $cut['got_settings'] < $cut['settings'] && self::settings_left_out( $items, $cut ) ) {
            if ( 0 === $cut['got_settings'] ) {
                $cuts[] = 'settings are left out';
            } else {
                $cuts[] = 'element' === $cut['key'] ? 'settings are left out below the element' : 'settings are left out below the root elements';
            }
        }
        $paged = array();
        if ( 'element' === $cut['key'] && isset( $reply['element']['next_offset'] ) ) {
            $paged[] = 'children';
            $cuts[]  = 'only the element\'s leading children are listed (children_omitted, next_offset)';
        }
        if ( 'element' === $cut['key'] && array() !== array_filter( array_column( $reply['element']['children'] ?? array(), 'lists_omitted' ) ) ) {
            $cuts[] = 'the children\'s lists of items are left out (lists_omitted)';
        }
        if ( 'element' === $cut['key'] && self::any_next_offset( $reply['element']['lists'] ?? array() ) ) {
            $paged[] = 'items';
            $cuts[]  = 'the element\'s lists keep only their leading items (items_omitted, next_offset)';
        }
        if ( isset( $reply['references_omitted'] ) ) {
            $cuts[] = sprintf( 'the last %d references of the elements listed are left out (references_omitted)', $reply['references_omitted'] );
        }
        if ( isset( $reply['elements_omitted'] ) ) {
            $cuts[] = sprintf( 'the last %d root elements are left out (elements_omitted%s)', $reply['elements_omitted'], isset( $reply['next_offset'] ) ? ', next_offset' : '' );
            if ( isset( $reply['next_offset'] ) ) {
                $paged[] = 'root elements';
            }
        }
        if ( isset( $reply['lists_omitted'] ) ) {
            $cuts[] = sprintf( 'the element\'s %d lists of items are left out (lists_omitted)', $reply['lists_omitted'] );
        }
        $next = array();
        if ( 'elements' === $cut['key'] && array() !== $items ) {
            $next[] = 'Read one element, with its settings, by passing its address as `element`.';
        }
        if ( 'element' === $cut['key'] && self::any_children( $items ) ) {
            $next[] = 'Read a child, with its settings, by passing its address as `element`.';
        }
        $listed = 'element' === $cut['key'] ? ( $reply['element']['children'] ?? array() ) : $items;
        if ( isset( $reply['references_omitted'] ) && 1 === count( $listed ) ) {
            $next[] = 'Read the element listed by passing its address as `element`: its children are paged with their references.';
        }
        if ( array() !== $paged ) {
            $next[] = sprintf( 'Read the next %s by passing `next_offset` as `list_offset`.', implode( ' and ', $paged ) );
        }
        unset( $reply['raw_read'] );
        $own = 'element' === $cut['key'] && 0 === $cut['got_settings'] && 0 < $cut['settings'] && array() !== $cut['nodes'][0]['settings'];
        if ( $own && null !== $cut['raw'] ) {
            $reply['raw_read'] = $cut['raw'];
            $next[]            = sprintf( 'The element\'s own settings do not fit any page: %s with the arguments in raw_read returns the stored layout whole; find the element there by its address.', $cut['raw']['tool'] );
        } elseif ( $own ) {
            $next[] = 'The element\'s own settings do not fit any page, and no read here returns them whole.';
        }
        $reply['truncated'] = true;
        $reply['hint']      = trim( sprintf( 'This reply was cut to fit %s characters: %s. %s', number_format( self::REPLY_BUDGET ), implode( '; ', $cuts ), implode( ' ', $next ) ) );
        return $reply;
    }

    private static function settings_left_out( array $items, array $cut ): bool {
        if ( 'elements' === $cut['key'] ) {
            return self::settings_left_out_of( array_values( $items ), $cut['nodes'] );
        }
        $item = $items[0];
        $node = $cut['nodes'][0];
        return ( array() !== $node['settings'] && ! isset( $item['settings'] ) )
            || self::settings_left_out_of( $item['children'] ?? array(), array_slice( $node['children'], $cut['start'], count( $item['children'] ?? array() ) ) );
    }

    private static function settings_left_out_of( array $items, array $nodes ): bool {
        foreach ( $items as $n => $item ) {
            $node = $nodes[ $n ] ?? null;
            if ( null === $node ) {
                return false;
            }
            if ( ( array() !== $node['settings'] && ! isset( $item['settings'] ) ) || self::settings_left_out_of( $item['children'] ?? array(), $node['children'] ) ) {
                return true;
            }
        }
        return false;
    }

    private static function any_unexpanded( array $items ): bool {
        foreach ( $items as $item ) {
            if ( ! empty( $item['has_children'] ) || self::any_unexpanded( $item['children'] ?? array() ) ) {
                return true;
            }
        }
        return false;
    }

    private static function any_children( array $items ): bool {
        foreach ( $items as $item ) {
            if ( ! empty( $item['children'] ) ) {
                return true;
            }
        }
        return false;
    }

    private static function any_next_offset( array $lists ): bool {
        foreach ( $lists as $list ) {
            if ( isset( $list['next_offset'] ) ) {
                return true;
            }
        }
        return false;
    }

    private static function items( string $key, array $roots, int $depth, int $settings, int $offset ): array {
        if ( 'element' === $key ) {
            return self::paged_item( $roots[0], $offset, null, $depth, $settings );
        }
        return array_map(
            static function ( array $node ) use ( $depth, $settings ): array {
                return self::item( $node, 1, $depth, $settings );
            },
            $roots
        );
    }

    private static function reply_size( $value ): int {
        return strlen( (string) wp_json_encode( $value, Server::RESULT_JSON_FLAGS ) );
    }

    private function not_a_builder_page( object $post ): array {
        $present = array_map(
            static function ( Layout_Adapter $adapter ): string {
                return $adapter->label();
            },
            Builder_Registry::present()
        );
        $blocks  = function_exists( 'has_blocks' ) && has_blocks( $post->post_content );
        return array(
            'post_id'          => (int) $post->ID,
            'post_type'        => $post->post_type,
            'title'            => $post->post_title,
            'builder'          => null,
            'builders_on_site' => $present,
            'use'              => $blocks ? 'wp_get_post_blocks' : 'wp_get_post',
            'note'             => $blocks
                ? 'This item is not built with a supported page builder; its content is Gutenberg blocks.'
                : 'This item is not built with a supported page builder; its content is classic post content.',
        );
    }

    private static function item( array $node, int $level, int $depth, int $settings ): array {
        $item = array(
            'address' => $node['address'],
            'type'    => $node['type'],
        );
        foreach ( array( 'label', 'text', 'image', 'link', 'flags', 'lists' ) as $key ) {
            if ( null !== $node[ $key ] && '' !== $node[ $key ] && array() !== $node[ $key ] ) {
                $item[ $key ] = $node[ $key ];
            }
        }
        if ( $level <= $settings && array() !== $node['settings'] ) {
            $item['settings'] = $node['settings'];
        }
        $item['children_count'] = count( $node['children'] );
        if ( array() === $node['children'] ) {
            return $item;
        }
        if ( $level >= $depth ) {
            $item['has_children'] = true;
            return $item;
        }
        $item['children'] = array();
        foreach ( $node['children'] as $child ) {
            $item['children'][] = self::item( $child, $level + 1, $depth, $settings );
        }
        return $item;
    }

    private static function find( array $nodes, string $address ): ?array {
        foreach ( $nodes as $node ) {
            if ( $node['address'] === $address ) {
                return $node;
            }
            $found = self::find( $node['children'], $address );
            if ( null !== $found ) {
                return $found;
            }
        }
        return null;
    }

    private static function count_nodes( array $nodes ): int {
        $count = 0;
        foreach ( $nodes as $node ) {
            $count += 1 + self::count_nodes( $node['children'] );
        }
        return $count;
    }
}
