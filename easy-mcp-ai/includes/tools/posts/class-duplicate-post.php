<?php
namespace Easy_MCP_AI\Tools\Posts;

use Easy_MCP_AI\Drafts\Strict_Ceiling;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Post_Type_Rest_Base;
use Easy_MCP_AI\Tools\Stored_Meta;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Duplicate_Post extends Base_Tool {

    use Post_Copy_Fields;

    const COPY_OF_KEY = '_easy_mcp_ai_copy_of';

    const REFUSED_TYPES = array(
        'attachment'          => 'a media item',
        'revision'            => 'a revision',
        'nav_menu_item'       => 'a menu item',
        'wp_template'         => 'a site editor template',
        'wp_template_part'    => 'a site editor template part',
        'wp_global_styles'    => 'the site editor\'s global styles',
        'wp_navigation'       => 'a site editor navigation menu',
        'wp_font_family'      => 'an installed font',
        'wp_font_face'        => 'an installed font',
        'customize_changeset' => 'a Customizer changeset',
        'product'             => 'a WooCommerce product (its SKU must be unique and its lookup tables would not be copied)',
        'product_variation'   => 'a WooCommerce product variation',
    );

    const SKIPPED_META = array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '_wp_desired_post_slug',
        '_encloseme',
        '_pingme',
        '_thumbnail_id',
        '_wp_page_template',
        '_elementor_css',
        '_elementor_element_cache',
        '_elementor_page_assets',
        self::COPY_OF_KEY,
    );

    const SKIPPED_META_PREFIXES = array(
        '_wp_trash_meta_',
        '_dp_',
    );

    const STATUSES = array( 'draft', 'pending', 'private', 'publish' );

    public function get_name() {
        return 'wp_duplicate_post';
    }

    public function get_description() {
        return 'Duplicates a post, page or custom post type item into a new one of the same type. Required: `post_id`. Optional: `title` (default: the original\'s title), `status` (draft/pending/private/publish, default draft; under Draft mode New content the copy is a draft, under Strict publish and private are refused). Copies the content, excerpt, terms of every taxonomy shown in the REST API, featured image, page template, post format, parent, menu order, comment and ping settings, password and post meta, page-builder layouts such as Elementor\'s included. Not copied: the date, slug (WordPress makes a new one), author (you become the author), sticky flag, comments, child pages and the original\'s editing history. Requires permission to edit the original and, for a custom post type item, the access wp_create_cpt_item needs. Refuses media, revisions, menu items, site editor entities and WooCommerce products. Returns { id, source_id, post_type, title, status, link, copied_meta_keys }, plus `skipped_meta_keys`, `skipped_taxonomies`, `skipped_template`, `skipped_format` (a stored post format WordPress does not know) or `skipped_fields` (menu_order, parent) when something was left out, `content_filtered: true` when WordPress filtered HTML from the content because you cannot post unfiltered HTML, and `status_error` when the copy was created but stayed a draft. An error after the copy was created (a meta key WordPress refused) names the draft. To put a reviewed copy live in place of a published original, keeping its URL, use wp_republish_post, never a publish of the copy (`next` says so when it applies).';
    }

    public function get_category() {
        return 'posts';
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'ID of the post, page or custom post type item to duplicate.',
                ),
                'title'   => array(
                    'type'        => 'string',
                    'description' => 'Title of the copy. Default: the original\'s title.',
                ),
                'status'  => array(
                    'type'        => 'string',
                    'description' => 'Status of the copy. "publish" and "private" need permission to publish this post type.',
                    'enum'        => self::STATUSES,
                    'default'     => 'draft',
                ),
            ),
            'required'   => array( 'post_id' ),
        );
    }

    public function precheck( array $arguments ): void {
        try {
            $post_id = $this->parse_required_id( $arguments['post_id'] ?? null, 'post_id' );
        } catch ( \InvalidArgumentException $e ) {
            return;
        }
        $this->checked_source( $post_id, $arguments );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'post_id' ) );
        $post_id = $this->parse_required_id( $arguments['post_id'], 'post_id' );
        list( $source, $rest_base, $status ) = $this->checked_source( $post_id, $arguments );

        $route    = self::post_type_route( $rest_base, null, 'POST' );
        $fields   = $this->copied_fields( $source, $arguments );
        $created  = $this->create_copy( $route, (string) $source->post_type, $fields );
        $new_id   = (int) $created['id'];
        self::refuse_other_created_type( $new_id, (string) $source->post_type );
        try {
            $meta = self::copy_meta( (int) $source->ID, $new_id );
        } catch ( \Throwable $e ) {
            throw new Detailed_Tool_Error( sprintf( 'Post %d was created as a draft copy, but copying its meta failed: delete it, or finish it by hand.', $new_id ), 'The error was: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( array() !== $meta['failed'] ) {
            throw new Detailed_Tool_Error( sprintf( 'Post %1$d was created as a draft copy, but WordPress refused %2$d of its meta keys: delete it, or finish it by hand.', $new_id, count( $meta['failed'] ) ), self::refused_meta_details( $meta['failed'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        update_post_meta( $new_id, self::COPY_OF_KEY, (int) $source->ID );

        $reply = array(
            'id'               => $new_id,
            'source_id'        => (int) $source->ID,
            'post_type'        => $source->post_type,
            'title'            => $fields['title'],
            'status'           => (string) $created['status'],
            'link'             => (string) ( $created['link'] ?? '' ),
            'copied_meta_keys' => $meta['copied'],
        );
        $reply = array_merge( $reply, $this->apply_status( $route . '/' . $new_id, $status ) );
        if ( 'publish' !== $reply['status'] && 'publish' === $source->post_status ) {
            $reply['next'] = sprintf( 'Once the copy is reviewed, put it live in place of post %1$d with wp_republish_post { "copy_id": %2$d }. Publishing the copy instead makes it a second page at another URL.', (int) $source->ID, $new_id );
        }

        $copy = get_post( $new_id );
        if ( is_object( $copy ) && $copy->post_content !== $source->post_content ) {
            $reply['content_filtered'] = true;
        }
        if ( array() !== $meta['skipped'] ) {
            $reply['skipped_meta_keys'] = $meta['skipped'];
        }
        $unset = self::columns_not_stored( $source, $new_id );
        if ( array() !== $unset ) {
            $reply['skipped_fields'] = $unset;
        }
        return array_merge( $reply, $fields['skipped'] );
    }

    private function checked_source( int $post_id, array $arguments ): array {
        $post = get_post( $post_id );
        if ( ! is_object( $post ) || ! get_post_type_object( $post->post_type ) || ! current_user_can( 'read_post', $post_id ) ) {
            throw new \InvalidArgumentException( sprintf( 'Post %d not found.', $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( isset( self::REFUSED_TYPES[ $post->post_type ] ) ) {
            throw new \InvalidArgumentException( sprintf( 'Post %1$d is %2$s, which this tool does not duplicate.', $post_id, self::REFUSED_TYPES[ $post->post_type ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( ! Strict_Ceiling::user_can( 'edit_post', $post_id ) ) {
            throw new \InvalidArgumentException( sprintf( 'You cannot duplicate post %d: duplicating needs permission to edit the original.', $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) && false === $this->caller_may_create_custom_items() ) {
            throw new \InvalidArgumentException( sprintf( 'Duplicating a "%s" item needs access to wp_create_cpt_item, which this connection does not have (for OAuth: the custom post types write permission).', $post->post_type ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $rest_base = $this->resolve_post_rest_base( $post_id );
        $served    = Post_Type_Rest_Base::served_type( $rest_base );
        if ( null === $served || $served[0] !== $post->post_type ) {
            throw new \InvalidArgumentException( sprintf( 'Post %1$d has post type "%2$s", which the REST API does not serve at /wp/v2/%3$s, so this tool cannot create its copy.', $post_id, $post->post_type, $rest_base ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        self::post_type_route( $rest_base, null, 'POST' );
        $this->refuse_switched_off_post_type_route( 'POST', $rest_base, false );
        if ( isset( $arguments['title'] ) && '' !== $arguments['title'] ) {
            $this->validate_title_length( (string) $arguments['title'] );
        }
        $status = self::checked_status( $arguments, get_post_type_object( $post->post_type ) );
        $params = array( 'status' => $status );
        $this->maybe_force_draft( $params );
        if ( 'draft' !== $params['status'] ) {
            $this->refuse_switched_off_post_type_route( 'POST', $rest_base, true );
        }
        return array( $post, $rest_base, $status );
    }

    protected function caller_may_create_custom_items(): ?bool {
        if ( ! class_exists( '\\Easy_MCP_AI\\MCP\\Server' ) ) {
            return null;
        }
        return \Easy_MCP_AI\MCP\Server::current_call_may_use( 'wp_create_cpt_item' );
    }

    private static function refused_meta_details( array $failed ): string {
        $head  = 'Refused by a plugin or the database: ';
        $tail  = '. A key listed without a count has no value on the copy. Every other key was copied.';
        $list  = static function ( array $shown ) use ( $failed ): string {
            $left = count( $failed ) - count( $shown );
            if ( array() === $shown ) {
                return sprintf( '%d keys (compare the copy\'s meta with the original\'s)', $left );
            }
            return implode( ', ', $shown ) . ( $left > 0 ? sprintf( ' and %d more (compare the copy\'s meta with the original\'s)', $left ) : '' );
        };
        $shown = array();
        foreach ( $failed as $entry ) {
            $candidate = array_merge( $shown, array( $entry ) );
            if ( strlen( \Easy_MCP_AI\MCP\Server::sanitize_error_message( $head . $list( $candidate ) . $tail, PHP_INT_MAX ) ) > Detailed_Tool_Error::MAX_DETAILS ) {
                break;
            }
            $shown = $candidate;
        }
        return $head . $list( $shown ) . $tail;
    }

    private static function refuse_other_created_type( int $new_id, string $post_type ): void {
        $created_type = (string) get_post_type( $new_id );
        if ( $created_type === $post_type ) {
            return;
        }
        $what    = sprintf( 'Post %1$d was created as a "%2$s", not a "%3$s": another post type answers this REST route.', $new_id, $created_type, $post_type );
        $details = 'It held the original\'s title, content and other fields; no meta was copied to it. Plugins had already seen it saved, and the change history keeps its creation';
        if ( ! wp_delete_post( $new_id, true ) ) {
            throw new Detailed_Tool_Error( $what . ' It could not be deleted; delete it with wp_delete_post.', $details . '. A plugin refused to delete it.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        throw new Detailed_Tool_Error( $what . ' It was deleted again.', $details . ' and deletion.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    private static function checked_status( array $arguments, object $post_type_object ): string {
        $status = isset( $arguments['status'] ) && '' !== $arguments['status'] ? (string) $arguments['status'] : 'draft';
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Invalid status: use one of %1$s (got "%2$s").', implode( ', ', self::STATUSES ), $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
            throw new \InvalidArgumentException( sprintf( 'You cannot give the copy status "%s": that needs permission to publish this post type. Use "draft" or "pending".', $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $status;
    }

    private function copied_fields( object $source, array $arguments ): array {
        $title = $source->post_title;
        if ( isset( $arguments['title'] ) && '' !== $arguments['title'] ) {
            $title = sanitize_text_field( (string) $arguments['title'] );
        }
        $params  = array(
            'status'         => 'draft',
            'title'          => $title,
            'content'        => $source->post_content,
            'excerpt'        => $source->post_excerpt,
            'comment_status' => self::discussion_status( $source->comment_status ),
            'ping_status'    => self::discussion_status( $source->ping_status ),
            'password'       => $source->post_password,
            'menu_order'     => (int) $source->menu_order,
            'parent'         => (int) $source->post_parent,
        );
        $skipped = array();

        $thumbnail = (int) get_post_thumbnail_id( (int) $source->ID );
        if ( $thumbnail > 0 ) {
            $params['featured_media'] = $thumbnail;
        }
        $format = self::copied_format( $source );
        if ( isset( $format['format'] ) ) {
            $params['format'] = $format['format'];
        } elseif ( isset( $format['skipped_format'] ) ) {
            $skipped['skipped_format'] = $format['skipped_format'];
        }
        $template = self::copied_template( $source );
        if ( isset( $template['template'] ) ) {
            $params['template'] = $template['template'];
        } elseif ( isset( $template['skipped_template'] ) ) {
            $skipped['skipped_template'] = $template['skipped_template'];
        }

        $item_fields = self::rest_item_fields( get_post_type_object( $source->post_type ) );
        $columns     = array();
        if ( null !== $item_fields ) {
            foreach ( array( 'menu_order' => 'menu_order', 'parent' => 'post_parent' ) as $field => $column ) {
                if ( ! in_array( $field, $item_fields, true ) && 0 !== $params[ $field ] ) {
                    $columns[ $column ] = $params[ $field ];
                }
            }
            $params = array_intersect_key( $params, array_flip( array_merge( $item_fields, array( 'status' ) ) ) );
        }

        $terms = $this->copied_terms( $source );
        if ( array() !== $terms['skipped'] ) {
            $skipped['skipped_taxonomies'] = $terms['skipped'];
        }
        return array(
            'title'   => isset( $params['title'] ) ? (string) $title : '',
            'params'  => array_replace( $params, $terms['params'] ),
            'columns' => $columns,
            'skipped' => $skipped,
        );
    }

    private function create_copy( string $route, string $post_type, array $fields ): array {
        if ( array() === $fields['columns'] ) {
            return $this->rest_request( 'POST', $route, $fields['params'], 'id,status,link' );
        }
        $copy    = self::build_rest_request( 'POST', $route, $fields['params'], 'id,status,link' );
        $pending = true;
        $set     = static function ( $prepared, $request ) use ( &$pending, $copy, $fields ) {
            if ( $pending && $prepared instanceof \stdClass && $request === $copy ) {
                $pending = false;
                foreach ( $fields['columns'] as $column => $value ) {
                    $prepared->$column = $value;
                }
            }
            return $prepared;
        };
        $hook = 'rest_pre_insert_' . $post_type;
        add_filter( $hook, $set, PHP_INT_MAX, 2 );
        try {
            return $this->dispatch_rest_request( $copy );
        } finally {
            remove_filter( $hook, $set, PHP_INT_MAX );
        }
    }

    private static function columns_not_stored( object $source, int $new_id ): array {
        $copy  = get_post( $new_id );
        $unset = array();
        foreach ( array( 'menu_order' => 'menu_order', 'post_parent' => 'parent' ) as $column => $name ) {
            if ( ! is_object( $copy ) || (int) $copy->$column !== (int) $source->$column ) {
                $unset[] = $name;
            }
        }
        return $unset;
    }

    public static function copy_meta( int $source_id, int $copy_id, array $leave = array() ): array {
        $result = self::write_meta( $copy_id, self::source_meta( $source_id, $leave ), null );
        unset( $result['changed'] );
        return $result;
    }

    public static function meta_snapshot( int $source_id, int $copy_id, array $leave = array() ): array {
        $rows = self::stored_rows( $copy_id );
        if ( null === $rows ) {
            throw new \RuntimeException( sprintf( 'Could not read the meta of post %d before writing it, so nothing was written.', $copy_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return self::source_meta( $source_id, $leave ) + array( 'rows' => $rows );
    }

    public static function copy_meta_or_put_back( int $copy_id, array $snapshot ): array {
        return self::write_meta( $copy_id, $snapshot, $snapshot['rows'] );
    }

    public static function put_back_meta( int $copy_id, array $snapshot, array $changed ): array {
        self::put_back( $copy_id, $snapshot['copied'], $snapshot['rows'] );
        $written = array_map(
            static function ( array $change ): array {
                return array(
                    'refused'  => '',
                    'in_place' => $change['in_place'],
                    'values'   => $change['after'],
                );
            },
            $changed
        );
        return self::differing( $copy_id, $snapshot['rows'], $written );
    }

    private static function source_meta( int $source_id, array $leave ): array {
        $all     = get_post_meta( $source_id );
        $copied  = array();
        $skipped = array();
        foreach ( array_keys( is_array( $all ) ? $all : array() ) as $key ) {
            $key = (string) $key;
            if ( in_array( $key, $leave, true ) ) {
                continue;
            }
            $values = (array) get_post_meta( $source_id, $key );
            if ( self::is_skipped_meta( $key ) || self::holds_unloadable_object( $values ) ) {
                $skipped[] = $key;
            } else {
                $copied[ $key ] = $values;
            }
        }
        return array(
            'copied'  => $copied,
            'skipped' => $skipped,
        );
    }

    private static function write_meta( int $copy_id, array $source, ?array $rows ): array {
        $failed  = array();
        $written = array();
        foreach ( $source['copied'] as $key => $values ) {
            $result          = self::write_meta_key( $copy_id, (string) $key, $values, null !== $rows );
            $written[ $key ] = $result;
            if ( '' === $result['refused'] ) {
                continue;
            }
            $failed[] = $result['refused'];
            if ( null !== $rows ) {
                self::put_back( $copy_id, $source['copied'], $rows );
                return array(
                    'copied'  => 0,
                    'skipped' => $source['skipped'],
                    'failed'  => $failed,
                    'changed' => self::differing( $copy_id, $rows, $written ),
                );
            }
        }
        return array(
            'copied'  => count( $source['copied'] ) - count( $failed ),
            'skipped' => $source['skipped'],
            'failed'  => $failed,
            'changed' => null === $rows ? array() : self::differing( $copy_id, $rows, $written ),
        );
    }

    private static function write_meta_key( int $post_id, string $key, array $values, bool $all_or_nothing ): array {
        $rows = self::exact_meta_ids( $post_id, $key );
        if ( 1 === count( $values ) && 1 === count( $rows ) ) {
            $result = self::replace_meta_row( $rows[0], $values[0] );
            return array(
                'refused'  => 'refused' === $result['status'] ? $key : '',
                'in_place' => true,
                'values'   => array( $result['value'] ),
            );
        }
        $refused = self::replace_rows( $post_id, $key, $rows, $values, $all_or_nothing );
        if ( 0 === $refused ) {
            $label = '';
        } else {
            $label = count( $values ) === $refused ? $key : sprintf( '%1$s (%2$d of %3$d values)', $key, $refused, count( $values ) );
        }
        return array(
            'refused'  => $label,
            'in_place' => false,
            'values'   => $values,
        );
    }

    private static function replace_rows( int $post_id, string $key, array $rows, array $values, bool $all_or_nothing ): int {
        $added   = array();
        $refused = 0;
        foreach ( $values as $value ) {
            $meta_id = add_post_meta( $post_id, wp_slash( $key ), self::slash_strings( $value ) );
            if ( ! $meta_id ) {
                ++$refused;
            } elseif ( is_int( $meta_id ) ) {
                $added[] = $meta_id;
            }
        }
        $obsolete = $refused > 0 && $all_or_nothing ? array_reverse( $added ) : $rows;
        foreach ( $obsolete as $meta_id ) {
            delete_metadata_by_mid( 'post', $meta_id );
        }
        return $refused;
    }

    private static function put_back( int $post_id, array $copied, array $rows ): void {
        foreach ( array_reverse( array_keys( $copied ) ) as $key ) {
            $before = $rows[ $key ] ?? array();
            $now    = self::stored_rows( $post_id, (string) $key );
            if ( null === $now || ( $now[ $key ] ?? array() ) !== $before ) {
                self::write_back( $post_id, (string) $key, $before );
            }
        }
    }

    private static function differing( int $post_id, array $rows, array $written ): array {
        $now     = self::stored_rows( $post_id );
        $changed = array();
        foreach ( array_keys( $rows + ( $now ?? array() ) + $written ) as $key ) {
            $before = $rows[ $key ] ?? array();
            if ( null !== $now && ( $now[ $key ] ?? array() ) === $before ) {
                continue;
            }
            $changed[ $key ] = array(
                'before'   => $before,
                'after'    => isset( $written[ $key ] ) ? $written[ $key ]['values'] : array(),
                'in_place' => isset( $written[ $key ] ) ? $written[ $key ]['in_place'] : true,
            );
        }
        return $changed;
    }

    private static function write_back( int $post_id, string $key, array $before ): void {
        $rows = self::exact_meta_ids( $post_id, $key );
        $old  = array_map( 'maybe_unserialize', $before );
        if ( 1 === count( $old ) && 1 === count( $rows ) ) {
            update_metadata_by_mid( 'post', $rows[0], $old[0] );
            return;
        }
        if ( self::holds_unloadable_object( $old ) ) {
            return;
        }
        self::replace_rows( $post_id, $key, $rows, $old, true );
    }

    private static function stored_rows( int $post_id, ?string $key = null ): ?array {
        global $wpdb;
        if ( null === $key ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the rows as stored, which a put-back restores and checks.
            $results = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post_id ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the rows as stored, which a put-back restores and checks.
            $results = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $post_id, $key ) );
        }
        if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) {
            return null;
        }
        $by_id = array();
        foreach ( (array) $results as $row ) {
            $by_id[ (int) $row->meta_id ] = $row;
        }
        ksort( $by_id );
        $rows = array();
        foreach ( $by_id as $row ) {
            $rows[ (string) $row->meta_key ][] = null === $row->meta_value ? null : (string) $row->meta_value;
        }
        return $rows;
    }

    private static function exact_meta_ids( int $post_id, string $key ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- meta IDs of one post, read once for this write.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
        $ids  = array();
        foreach ( (array) $rows as $row ) {
            if ( $key === $row->meta_key ) {
                $ids[] = (int) $row->meta_id;
            }
        }
        return $ids;
    }

    private static function replace_meta_row( int $meta_id, $value ): array {
        $row = get_metadata_by_mid( 'post', $meta_id );
        if ( ! is_object( $row ) ) {
            return array(
                'status' => 'refused',
                'value'  => $value,
            );
        }
        $value = sanitize_meta( (string) $row->meta_key, $value, 'post', (string) get_post_type( (int) $row->post_id ) );
        if ( Stored_Meta::form( $row->meta_value ) === Stored_Meta::form( $value ) ) {
            $status = 'same';
        } else {
            $status = update_metadata_by_mid( 'post', $meta_id, $value ) ? 'written' : 'refused';
        }
        return array(
            'status' => $status,
            'value'  => $value,
        );
    }

    private static function holds_unloadable_object( $value ): bool {
        if ( $value instanceof \__PHP_Incomplete_Class ) {
            return true;
        }
        if ( is_object( $value ) ) {
            $value = get_object_vars( $value );
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( self::holds_unloadable_object( $item ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function is_skipped_meta( string $key ): bool {
        if ( in_array( $key, self::SKIPPED_META, true ) ) {
            return true;
        }
        foreach ( self::SKIPPED_META_PREFIXES as $prefix ) {
            if ( 0 === strpos( $key, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    public static function slash_strings( $value ) {
        return map_deep(
            $value,
            static function ( $item ) {
                return is_string( $item ) ? addslashes( $item ) : $item;
            }
        );
    }

    private function apply_status( string $route, string $status ): array {
        $params = array( 'status' => $status );
        $this->maybe_force_draft( $params );
        if ( 'draft' === $params['status'] ) {
            return array();
        }
        try {
            $updated = $this->rest_request( 'POST', $route, $params, 'id,status,link' );
        } catch ( \RuntimeException $e ) {
            return array( 'status_error' => sprintf( 'The copy was created as a draft; setting status "%1$s" failed: %2$s', $status, $e->getMessage() ) );
        }
        return array(
            'status' => (string) $updated['status'],
            'link'   => (string) ( $updated['link'] ?? '' ),
        );
    }
}
