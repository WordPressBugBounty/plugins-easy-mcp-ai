<?php
namespace Easy_MCP_AI\Tools\Search;

use Easy_MCP_AI\Builders\Builder_Registry;
use Easy_MCP_AI\Cli_Router\Handlers\Option_Handler;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Posts\Text_Matcher;
use Easy_MCP_AI\Tools\Privacy_Requests;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Find_In_Site extends Base_Tool {

    const MAX_QUERIES = 10;

    const MAX_CONTEXT = 200;

    const DEFAULT_CONTEXT = 60;

    const TIME_BUDGET = 5.0;

    const MAX_LOCATIONS = 200;

    const MAX_ROW_LOCATIONS = 50;

    const REPLY_BUDGET = 49152;

    const MAX_VALUE_BYTES = Text_Matcher::MAX_SUBJECT_BYTES;

    const BATCH = 20;

    const RANGE = 1000;

    const JSON_FLAGS = JSON_UNESCAPED_SLASHES;

    const MAX_POST_TYPES = 100;

    const SOURCES = array( 'posts', 'post_meta', 'terms' );

    const POST_FIELDS = array( 'post_title', 'post_excerpt', 'post_content' );

    const BOOKKEEPING_META = array( '_edit_lock', '_edit_last', '_wp_attachment_metadata', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_encloseme', '_pingme' );

    const PLUGIN_LIKE = '%easy\\_mcp\\_ai\\_%';

    const NOT_SEARCHED = array(
        'site options, widgets and theme mods',
        'users and user meta, comments, term meta',
        'WooCommerce order tables and other plugins\' own tables, files, other sites of a network',
        'posts, revisions and custom fields you cannot edit',
        'attachments and their custom fields, unless post_types names attachment',
        'values under secret-named keys (password, token, api_key, …) and custom fields with such a key',
        'WordPress bookkeeping fields (_edit_lock, _wp_old_slug, _wp_attachment_metadata, …)',
        'array keys inside stored values, values over 1 MB, values that are corrupt or nested over 64 levels, objects that serialize themselves, and references and enum cases inside serialized values',
    );

    public function get_name() {
        return 'wp_find_in_site';
    }

    public function get_description() {
        return 'Finds where text appears across the site without returning content: posts of every type and status (title, excerpt, content; templates, template parts, global styles, navigation and forms included; revisions flagged), their custom fields (page-builder data included) and term names and descriptions. Only what you can edit is searched. Read only, never paused for approval. Required: `queries` (1-10 strings, up to 500 bytes each). Optional: `sources` (posts, post_meta, terms; default all), `post_types` (limit posts and custom fields to these types; default every type but attachment), `include_revisions` (default true), `regex`, `case_sensitive` (default true), `snippets` (default false), `context` (0-200 bytes, default 60), `cursor`. A literal query is also found as JSON stores it (`\\/`, `\\u00e9`), as block settings store it (`\\u0026` for &) and with & < > as HTML entities; `form` says which. Serialized and JSON values are searched string by string and `path` names the string. Returns { queries, counts, locations: [{ query, source (post, post_meta, term), post_id, post_type, status, field or meta_key and meta_id, revision?, autosave?, parent?, builder?, term_id, taxonomy, path?, form, count, incomplete?, snippet? }], complete, next_cursor?, not_searched }. A scan stops after 5 seconds or when the reply is full: `complete` false, send `next_cursor` back with the same arguments to continue. `counts` are for this reply only. `count` is null with a reason when a string could not be searched. Not searched: options, widgets, users, comments, term meta, other tables, anything under a secret-named key; `not_searched` lists them all. To count or locate in posts you already know, use wp_find_in_post.';
    }

    public function get_required_tools(): array {
        return array( 'wp_search' );
    }

    public function get_category() {
        return 'search';
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
                'queries'           => array(
                    'type'        => 'array',
                    'description' => 'The texts (or, with regex, pattern bodies) to find (1-10, each up to 500 bytes).',
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_QUERIES,
                ),
                'sources'           => array(
                    'type'        => 'array',
                    'description' => 'Where to search. Default all three.',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => self::SOURCES,
                    ),
                    'minItems'    => 1,
                ),
                'post_types'        => array(
                    'type'        => 'array',
                    'description' => 'Search only posts of these types (slugs, e.g. page or product), and only their custom fields. A revision counts as its parent\'s type. Default every type but attachment.',
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                ),
                'include_revisions' => array(
                    'type'        => 'boolean',
                    'description' => 'Search revisions and autosaves too (flagged `revision`). Default true.',
                    'default'     => true,
                ),
                'regex'             => array(
                    'type'        => 'boolean',
                    'description' => 'Treat each query as a PCRE pattern body (no delimiters), with wp_replace_in_post\'s limits; strings over 20 KB are not searched. Much slower: every row is read. Default false.',
                    'default'     => false,
                ),
                'case_sensitive'    => array(
                    'type'        => 'boolean',
                    'description' => 'Match letter case exactly. Default true.',
                    'default'     => true,
                ),
                'snippets'          => array(
                    'type'        => 'boolean',
                    'description' => 'Add the text around the first match of each location, secrets masked. Default false.',
                    'default'     => false,
                ),
                'context'           => array(
                    'type'        => 'integer',
                    'description' => 'Bytes of text each side of a snippet (0-200). Default 60.',
                    'minimum'     => 0,
                    'maximum'     => self::MAX_CONTEXT,
                    'default'     => self::DEFAULT_CONTEXT,
                ),
                'cursor'            => array(
                    'type'        => 'string',
                    'description' => 'The next_cursor of the previous reply, to continue the same search.',
                ),
            ),
            'required'   => array( 'queries' ),
        );
    }

    public function precheck( array $arguments ): void {
        $this->refuse_switched_off_route( 'GET', '/wp/v2/search' );
    }

    public function execute( array $arguments ) {
        $this->refuse_switched_off_route( 'GET', '/wp/v2/search' );
        $request = $this->check_arguments( $arguments );
        $finder  = new Site_Finder( $request['queries'], $request['regex'], $request['case_sensitive'] );
        $cursor  = $this->read_cursor( $arguments, $request );

        $previous = ini_get( 'pcre.backtrack_limit' );
        $lowered  = false;
        if ( $request['regex'] ) {
            $lowered = Text_Matcher::lower_backtrack_limit();
            foreach ( $request['queries'] as $query ) {
                Text_Matcher::check_regex_bound( $query, false !== $lowered );
            }
        }
        try {
            $scan = $this->scan( $request, $finder, $cursor );
        } finally {
            if ( false !== $lowered && false !== $previous ) {
                @ini_set( 'pcre.backtrack_limit', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restoring previous PCRE backtrack limit.
            }
        }

        $reply = array(
            'queries'   => $request['queries'],
            'counts'    => self::totals( count( $request['queries'] ), $scan['locations'] ),
            'locations' => $scan['locations'],
            'complete'  => null === $scan['next'],
        );
        if ( null !== $scan['next'] ) {
            $reply['next_cursor'] = self::encode_cursor( $scan['next'], $request['hash'] );
        }
        $reply['not_searched'] = array_merge( self::left_out( $request['sources'] ), self::NOT_SEARCHED );
        return $reply;
    }

    private static function left_out( array $sources ): array {
        $words = array(
            'posts'     => 'posts (left out of sources)',
            'post_meta' => 'custom fields (left out of sources)',
            'terms'     => 'terms (left out of sources)',
        );
        return array_values( array_diff_key( $words, array_flip( $sources ) ) );
    }

    protected function elapsed( float $started ): float {
        return microtime( true ) - $started;
    }

    private function check_arguments( array $arguments ): array {
        $this->validate_required( $arguments, array( 'queries' ) );
        $queries = self::strings( $arguments['queries'], 'queries', self::MAX_QUERIES );
        foreach ( $queries as $i => $query ) {
            Text_Matcher::check_length( $query );
            if ( false !== strpos( $query, "\0" ) ) {
                throw new \InvalidArgumentException( sprintf( 'queries[%d] must not hold a NUL byte.', $i ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
        $sources = isset( $arguments['sources'] ) ? array_unique( self::strings( $arguments['sources'], 'sources', count( self::SOURCES ) ) ) : self::SOURCES;
        foreach ( $sources as $source ) {
            if ( ! in_array( $source, self::SOURCES, true ) ) {
                throw new \InvalidArgumentException( 'sources must hold only posts, post_meta and terms.' );
            }
        }
        $post_types = isset( $arguments['post_types'] ) ? array_values( array_unique( self::strings( $arguments['post_types'], 'post_types', self::MAX_POST_TYPES ) ) ) : null;

        $regex = ! empty( $arguments['regex'] );
        if ( $regex && ! Text_Matcher::regex_allowed() ) {
            throw new \InvalidArgumentException( 'Regex search is disabled on this site (filter: easy_mcp_ai_replace_in_post_allow_regex).' );
        }
        $context = null;
        if ( ! empty( $arguments['snippets'] ) ) {
            $context = self::DEFAULT_CONTEXT;
            if ( isset( $arguments['context'] ) ) {
                $context = is_bool( $arguments['context'] ) ? false : filter_var( $arguments['context'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => self::MAX_CONTEXT ) ) );
                if ( false === $context ) {
                    throw new \InvalidArgumentException( sprintf( 'context must be an integer from 0 to %d.', self::MAX_CONTEXT ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
            }
        }

        $request = array(
            'queries'           => $queries,
            'sources'           => array_values( array_intersect( self::SOURCES, $sources ) ),
            'post_types'        => $post_types,
            'include_revisions' => ! isset( $arguments['include_revisions'] ) || ! empty( $arguments['include_revisions'] ),
            'regex'             => $regex,
            'case_sensitive'    => ! isset( $arguments['case_sensitive'] ) || ! empty( $arguments['case_sensitive'] ),
            'context'           => $context,
        );
        $request['hash'] = substr( hash( 'sha256', (string) wp_json_encode( $request ) ), 0, 16 );
        return $request;
    }

    private static function strings( $value, string $name, int $max ): array {
        if ( ! is_array( $value ) || array() === $value || count( $value ) > $max ) {
            throw new \InvalidArgumentException( sprintf( '%s must be a list of 1 to %d texts.', $name, $max ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $out = array();
        foreach ( array_values( $value ) as $i => $item ) {
            if ( ! is_string( $item ) || '' === $item ) {
                throw new \InvalidArgumentException( sprintf( '%s[%d] must be a non-empty text.', $name, $i ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $out[] = $item;
        }
        return $out;
    }

    private function read_cursor( array $arguments, array $request ): array {
        if ( ! isset( $arguments['cursor'] ) ) {
            return array(
                'source' => $request['sources'][0],
                'after'  => 0,
            );
        }
        $raw     = is_string( $arguments['cursor'] ) ? base64_decode( strtr( $arguments['cursor'], '-_', '+/' ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the cursor is this tool's own position marker, not code.
        $decoded = false === $raw ? null : json_decode( $raw, true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['s'], $decoded['a'], $decoded['h'] ) || ! is_string( $decoded['s'] ) || ! is_int( $decoded['a'] ) || $decoded['a'] < 0 ) {
            throw new \InvalidArgumentException( 'cursor is not a next_cursor this tool returned. Leave it out to start the search again.' );
        }
        if ( $decoded['h'] !== $request['hash'] || ! in_array( $decoded['s'], $request['sources'], true ) ) {
            throw new \InvalidArgumentException( 'cursor belongs to a search with other arguments. Send the same queries and options as the call that returned it, or leave it out to start again.' );
        }
        return array(
            'source' => $decoded['s'],
            'after'  => $decoded['a'],
        );
    }

    private static function encode_cursor( array $next, string $hash ): string {
        $json = (string) wp_json_encode(
            array(
                's' => $next['source'],
                'a' => $next['after'],
                'h' => $hash,
            )
        );
        return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the cursor is this tool's own position marker.
    }

    private function scan( array $request, Site_Finder $finder, array $cursor ): array {
        $started   = microtime( true );
        $locations = array();
        $spent     = 0;
        $ranges    = 0;
        $needles   = $finder->needles();
        $sources   = array_slice( $request['sources'], (int) array_search( $cursor['source'], $request['sources'], true ) );
        $after     = $cursor['after'];
        foreach ( $sources as $source ) {
            $last   = $this->last_id( $source );
            $resume = $after;
            while ( $after < $last ) {
                if ( $ranges > 0 && $this->elapsed( $started ) > self::TIME_BUDGET ) {
                    return array(
                        'locations' => $locations,
                        'next'      => array(
                            'source' => $source,
                            'after'  => $after,
                        ),
                    );
                }
                $upto = min( $after + self::RANGE, $last );
                if ( $this->scan_range( $source, $after, $upto, $needles, $request, $finder, $locations, $spent, $resume, $started ) ) {
                    return array(
                        'locations' => $locations,
                        'next'      => array(
                            'source' => $source,
                            'after'  => $resume,
                        ),
                    );
                }
                $after = $upto;
                ++$ranges;
            }
            $after = 0;
        }
        return array(
            'locations' => $locations,
            'next'      => null,
        );
    }

    private function scan_range( string $source, int $after, int $upto, array $needles, array $request, Site_Finder $finder, array &$locations, int &$spent, int &$resume, float $started ): bool {
        $from = $after;
        do {
            $rows = $this->rows( $source, $from, $upto, $needles, $request );
            foreach ( $rows as $row ) {
                $from  = (int) $row['id'];
                $found = $this->locate( $source, $row, $finder, $request );
                if ( array() === $found ) {
                    if ( $request['regex'] && $this->elapsed( $started ) > self::TIME_BUDGET ) {
                        $resume = $from;
                        return true;
                    }
                    continue;
                }
                if ( ! self::add( $found, $locations, $spent ) ) {
                    return true;
                }
                $resume = $from;
                if ( count( $locations ) >= self::MAX_LOCATIONS || $spent > self::REPLY_BUDGET || $this->elapsed( $started ) > self::TIME_BUDGET ) {
                    return true;
                }
            }
        } while ( count( $rows ) === self::BATCH );
        return false;
    }

    private static function add( array $found, array &$locations, int &$spent ): bool {
        $cost = 0;
        foreach ( $found as $location ) {
            $cost += strlen( (string) wp_json_encode( $location, self::JSON_FLAGS ) );
        }
        $full = count( $locations ) + count( $found ) > self::MAX_LOCATIONS || $spent + $cost > self::REPLY_BUDGET;
        if ( $full && array() !== $locations ) {
            return false;
        }
        foreach ( $found as $location ) {
            $locations[] = $location;
        }
        $spent += $cost;
        return true;
    }

    protected function last_id( string $source ): int {
        global $wpdb;
        $tables = array(
            'posts'     => array( $wpdb->posts, 'ID' ),
            'post_meta' => array( $wpdb->postmeta, 'meta_id' ),
            'terms'     => array( $wpdb->term_taxonomy, 'term_taxonomy_id' ),
        );
        list( $table, $column ) = $tables[ $source ];
        return (int) $wpdb->get_var( 'SELECT MAX(`' . $column . '`) FROM `' . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress's own table and column names, no input.
    }

    protected function rows( string $source, int $after, int $upto, array $needles, array $request ): array {
        global $wpdb;
        list( $sql, $values ) = self::query( $wpdb, $source, $after, $upto, $needles, $request );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query() quotes the table names and leaves every value to a placeholder.
        return is_array( $rows ) ? $rows : array();
    }

    public static function query( object $db, string $source, int $after, int $upto, array $needles, array $request ): array {
        $like = $request['case_sensitive'] ? ' LIKE BINARY %s' : ' LIKE %s';
        if ( 'posts' === $source ) {
            $columns = array( 'post_title', 'post_excerpt', 'post_content' );
            $sql    = 'SELECT `ID` AS `id`, `post_type`, `post_status`, `post_parent`, `post_name`, IF(LENGTH(`post_title`) <= %d, `post_title`, NULL) AS `post_title`, IF(LENGTH(`post_excerpt`) <= %d, `post_excerpt`, NULL) AS `post_excerpt`, IF(LENGTH(`post_content`) <= %d, `post_content`, NULL) AS `post_content` FROM `' . $db->posts . '` WHERE `ID` > %d AND `ID` <= %d';
            $values = array( self::MAX_VALUE_BYTES, self::MAX_VALUE_BYTES, self::MAX_VALUE_BYTES, $after, $upto );
            if ( null !== $request['post_types'] ) {
                $types = $request['post_types'];
                if ( $request['include_revisions'] ) {
                    $types[] = 'revision';
                }
                $sql   .= ' AND `post_type` IN (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')';
                $values = array_merge( $values, $types );
            } else {
                $sql     .= ' AND `post_type` <> %s';
                $values[] = 'attachment';
                if ( ! $request['include_revisions'] ) {
                    $sql     .= ' AND `post_type` <> %s';
                    $values[] = 'revision';
                }
            }
            $order = '`ID`';
        } elseif ( 'post_meta' === $source ) {
            $columns = array( 'meta_value' );
            $sql     = 'SELECT `meta_id` AS `id`, `post_id`, `meta_key`, `meta_value` FROM `' . $db->postmeta . '` WHERE `meta_id` > %d AND `meta_id` <= %d AND `meta_key` NOT IN (' . implode( ', ', array_fill( 0, count( self::BOOKKEEPING_META ), '%s' ) ) . ') AND `meta_key` NOT LIKE %s AND LENGTH(`meta_value`) <= %d';
            $values  = array_merge( array( $after, $upto ), self::BOOKKEEPING_META, array( self::PLUGIN_LIKE, self::MAX_VALUE_BYTES ) );
            $order   = '`meta_id`';
        } else {
            $columns = array( 't`.`name', 'tt`.`description' );
            $sql     = 'SELECT tt.`term_taxonomy_id` AS `id`, tt.`term_id`, tt.`taxonomy`, t.`name`, IF(LENGTH(tt.`description`) <= %d, tt.`description`, NULL) AS `description` FROM `' . $db->term_taxonomy . '` tt INNER JOIN `' . $db->terms . '` t ON t.`term_id` = tt.`term_id` WHERE tt.`term_taxonomy_id` > %d AND tt.`term_taxonomy_id` <= %d';
            $values  = array( self::MAX_VALUE_BYTES, $after, $upto );
            $order   = 'tt.`term_taxonomy_id`';
        }
        if ( array() !== $needles ) {
            $ors = array();
            foreach ( $columns as $column ) {
                foreach ( $needles as $needle ) {
                    $ors[]    = '`' . $column . '`' . $like;
                    $values[] = '%' . $db->esc_like( $needle ) . '%';
                }
            }
            $sql .= ' AND (' . implode( ' OR ', $ors ) . ')';
        }
        return array( $sql . ' ORDER BY ' . $order . ' LIMIT ' . self::BATCH, $values );
    }

    private function locate( string $source, array $row, Site_Finder $finder, array $request ): array {
        if ( 'terms' === $source ) {
            return $this->locate_term( $row, $finder, $request );
        }
        $post_id = 'posts' === $source ? (int) $row['id'] : (int) $row['post_id'];
        $post    = get_post( $post_id );
        $owner   = is_object( $post ) ? $this->editable_owner( $post, $request ) : null;
        self::forget( $post_id, is_object( $post ) ? (int) $post->post_parent : 0 );
        if ( null === $owner ) {
            return array();
        }
        $where = self::post_where( $post, $owner );
        $found = array();
        if ( 'posts' === $source ) {
            foreach ( self::POST_FIELDS as $field ) {
                $value = (string) $row[ $field ];
                $matches = $finder->find( $value, true, $request['context'], false );
                if ( array() === $matches ) {
                    continue;
                }
                $at = array( 'field' => $field );
                if ( 'post_content' === $field ) {
                    $at += self::builder_of( $owner, null );
                }
                $found = array_merge( $found, self::entries( $matches, array( 'source' => 'post' ) + $where + $at ) );
            }
        } else {
            $key = (string) $row['meta_key'];
            if ( Option_Handler::key_is_secret( $key ) ) {
                return array();
            }
            $matches = $finder->find( (string) $row['meta_value'], true, $request['context'], true );
            if ( array() === $matches ) {
                return array();
            }
            $at    = array(
                'meta_key' => $key,
                'meta_id'  => (int) $row['id'],
            ) + self::builder_of( $owner, $key );
            $found = self::entries( $matches, array( 'source' => 'post_meta' ) + $where + $at );
        }
        return self::cap_row( $found );
    }

    private static function forget( int $post_id, int $parent ): void {
        wp_cache_delete( $post_id, 'posts' );
        if ( $parent > 0 ) {
            wp_cache_delete( $parent, 'posts' );
        }
    }

    private function editable_owner( object $post, array $request ): ?object {
        $owner = $post;
        if ( 'revision' === $post->post_type ) {
            if ( ! $request['include_revisions'] ) {
                return null;
            }
            $owner = get_post( (int) $post->post_parent );
            if ( ! is_object( $owner ) ) {
                return null;
            }
        }
        $type = (string) $owner->post_type;
        if ( null === $request['post_types'] ? 'attachment' === $type : ! in_array( $type, $request['post_types'], true ) ) {
            return null;
        }
        if ( ! Privacy_Requests::may_read( $owner ) ) {
            return null;
        }
        return self::can_edit( (int) $post->ID, post_type_exists( $type ) ) ? $owner : null;
    }

    private static function can_edit( int $post_id, bool $registered ): bool {
        if ( $registered ) {
            return current_user_can( 'edit_post', $post_id );
        }
        $quiet = static function ( $trigger, $function_name ) {
            return 'map_meta_cap' === $function_name ? false : $trigger;
        };
        add_filter( 'doing_it_wrong_trigger_error', $quiet, 10, 2 );
        try {
            return current_user_can( 'edit_post', $post_id );
        } finally {
            remove_filter( 'doing_it_wrong_trigger_error', $quiet, 10 );
        }
    }

    private static function post_where( object $post, object $owner ): array {
        $where = array(
            'post_id'   => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status'    => (string) $post->post_status,
        );
        if ( $post !== $owner ) {
            $where['revision'] = true;
            $where['parent']   = (int) $owner->ID;
            if ( false !== strpos( (string) $post->post_name, $owner->ID . '-autosave' ) ) {
                $where['autosave'] = true;
            }
        }
        return $where;
    }

    private static function builder_of( object $owner, ?string $meta_key ): array {
        $adapter = Builder_Registry::for_post( $owner );
        if ( null === $adapter ) {
            return array();
        }
        $owns = null === $meta_key ? ! $adapter->renders_post_content( $owner ) : in_array( $meta_key, $adapter->layout_meta_keys(), true );
        return $owns ? array( 'builder' => $adapter->id() ) : array();
    }

    private function locate_term( array $row, Site_Finder $finder, array $request ): array {
        $taxonomy = (string) $row['taxonomy'];
        $object   = taxonomy_exists( $taxonomy ) ? get_taxonomy( $taxonomy ) : null;
        if ( ! is_object( $object ) || ( ! is_taxonomy_viewable( $taxonomy ) && ! current_user_can( $object->cap->manage_terms ) ) ) {
            return array();
        }
        $where = array(
            'source'   => 'term',
            'term_id'  => (int) $row['term_id'],
            'taxonomy' => $taxonomy,
        );
        $found = array();
        foreach ( array( 'name', 'description' ) as $field ) {
            $found = array_merge( $found, self::entries( $finder->find( (string) $row[ $field ], false, $request['context'], false ), $where + array( 'field' => $field ) ) );
        }
        return self::cap_row( $found );
    }

    private static function entries( array $matches, array $where ): array {
        $out = array();
        foreach ( $matches as $match ) {
            $out[] = array( 'query' => $match['query'] ) + $where + array_diff_key( $match, array( 'query' => true ) );
        }
        return $out;
    }

    private static function cap_row( array $found ): array {
        $kept  = array();
        $bytes = 0;
        foreach ( $found as $n => $location ) {
            $bytes += strlen( (string) wp_json_encode( $location, self::JSON_FLAGS ) );
            if ( $n >= self::MAX_ROW_LOCATIONS || $bytes > self::REPLY_BUDGET ) {
                break;
            }
            $kept[] = $location;
        }
        $others = array();
        foreach ( array_slice( $found, count( $kept ) ) as $location ) {
            $i = $location['query'];
            if ( ! isset( $others[ $i ] ) ) {
                $others[ $i ] = array_diff_key( $location, array_flip( array( 'path', 'form', 'count', 'incomplete', 'snippet' ) ) ) + array(
                    'other_locations' => 0,
                    'count'           => 0,
                );
            }
            ++$others[ $i ]['other_locations'];
            if ( ! is_int( $location['count'] ) ) {
                $others[ $i ]['count']       = null;
                $others[ $i ]['incomplete'] = $others[ $i ]['incomplete'] ?? $location['incomplete'];
            } elseif ( is_int( $others[ $i ]['count'] ) ) {
                $others[ $i ]['count'] += $location['count'];
            }
        }
        ksort( $others );
        return array_merge( $kept, array_values( $others ) );
    }

    private static function totals( int $queries, array $locations ): array {
        $out = array_fill( 0, $queries, 0 );
        foreach ( $locations as $location ) {
            if ( is_int( $location['count'] ) ) {
                $out[ $location['query'] ] += $location['count'];
            }
        }
        return $out;
    }
}
