<?php
namespace Easy_MCP_AI\Tools\Posts;

use Easy_MCP_AI\Builders\Builder_Registry;
use Easy_MCP_AI\MCP\Server;
use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Privacy_Requests;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Find_In_Post extends Base_Tool {

    const MAX_POSTS = 20;

    const MAX_QUERIES = 10;

    const MAX_MATCHES = 20;

    const MAX_CONTEXT = 200;

    const DEFAULT_CONTEXT = 60;

    const TIME_BUDGET = 5.0;

    const MAX_REPLY_BYTES = 65536;

    const FIELDS = array( 'post_content', 'post_excerpt', 'post_title' );

    public function get_name() {
        return 'wp_find_in_post';
    }

    public function get_description() {
        return 'Counts and locates text in one field of up to 20 posts without returning their content: the read half of wp_replace_in_post. Required: `ids` (1-20 post IDs) and `queries` (1-10 strings, each up to 500 bytes). Optional: `field` (post_content, the default, post_excerpt or post_title), `regex` (default false: each query is a PCRE pattern body, with the same limits as wp_replace_in_post), `case_sensitive` (default true), `max_matches` (0-20, default 0: counts only; above 0, up to that many locations per post and query), `context` (0-200 bytes of text each side of a location, default 60). Counts are of the field as stored, markup included (`<a href` counts the links), non-overlapping, and a literal count equals the `matches_found` wp_replace_in_post reports for the same search. A regex runs on the whole field (20 KB at most), so a match across blocks counts. Returns { queries, posts: [{ id, field, bytes, sha256, counts, matches?, incomplete?, block_paths?, builder?, error? }], truncated? }: `counts[i]` is for `queries[i]`, and null (never 0) when it could not be counted, with the reason in `incomplete` (field_too_large, time_budget, backtrack_limit, invalid_utf8, regex_error). `sha256` and `bytes` describe the field as stored; for post_content they equal the `content_sha256` and `content_bytes` the post read and write tools return. Each location is { query (index), offset (bytes), length, snippet, and for post_content block_path (the path wp_get_post_blocks shows) and in (html; delimiter: a block\'s name or settings; between: whitespace between blocks), spans_blocks when the match ends in another block }; `block_paths` explains a post whose paths are not given. `builder` names a page builder that does not render the page from post_content. A post that does not exist or that you cannot edit gets `error` and the others still answer. Locations are left out (`truncated: true`) to keep the reply within 64 KB; counts, errors and their reasons never are. Read only: nothing is written or paused for approval.';
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
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'ids'            => array(
                    'type'        => 'array',
                    'description' => 'The IDs of the posts, pages or custom post type items to read (1-20).',
                    'items'       => array( 'type' => 'integer' ),
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_POSTS,
                ),
                'queries'        => array(
                    'type'        => 'array',
                    'description' => 'The texts (or, with regex, pattern bodies) to count (1-10, each up to 500 bytes).',
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_QUERIES,
                ),
                'field'          => array(
                    'type'        => 'string',
                    'description' => 'Which field to read. Default post_content.',
                    'enum'        => self::FIELDS,
                    'default'     => 'post_content',
                ),
                'regex'          => array(
                    'type'        => 'boolean',
                    'description' => 'Treat each query as a PCRE pattern body (no delimiters; / is used). Default false.',
                    'default'     => false,
                ),
                'case_sensitive' => array(
                    'type'        => 'boolean',
                    'description' => 'Match letter case exactly. Default true.',
                    'default'     => true,
                ),
                'max_matches'    => array(
                    'type'        => 'integer',
                    'description' => 'Locations to return per post and query (0-20). Default 0: counts only.',
                    'minimum'     => 0,
                    'maximum'     => self::MAX_MATCHES,
                    'default'     => 0,
                ),
                'context'        => array(
                    'type'        => 'integer',
                    'description' => 'Bytes of text each side of a location (0-200). Default 60.',
                    'minimum'     => 0,
                    'maximum'     => self::MAX_CONTEXT,
                    'default'     => self::DEFAULT_CONTEXT,
                ),
            ),
            'required'   => array( 'ids', 'queries' ),
        );
    }

    public function execute( array $arguments ) {
        $request = $this->check_arguments( $arguments );

        $previous = ini_get( 'pcre.backtrack_limit' );
        $lowered  = false;
        if ( $request['regex'] ) {
            $lowered = $this->lower_backtrack_limit();
            foreach ( $request['queries'] as $query ) {
                Text_Matcher::check_regex_bound( $query, false !== $lowered );
            }
        }
        try {
            $started   = microtime( true );
            $spent     = 0;
            $truncated = false;
            $posts     = array();
            $located   = array();
            foreach ( $request['ids'] as $id ) {
                list( $posts[], $located[] ) = $this->find_in( $id, $request, $started, $spent, $truncated );
            }
        } finally {
            if ( false !== $lowered && false !== $previous ) {
                @ini_set( 'pcre.backtrack_limit', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restoring previous PCRE backtrack limit.
            }
        }

        return self::fit( $request['queries'], $posts, $located, $truncated );
    }

    private static function fit( array $queries, array $posts, array $located, bool $truncated ): array {
        $reply = array(
            'queries'   => $queries,
            'posts'     => $posts,
            'truncated' => true,
        );
        $room  = self::MAX_REPLY_BYTES - strlen( (string) wp_json_encode( $reply, Server::RESULT_JSON_FLAGS ) );
        $key = strlen( ',"matches":[]' );
        foreach ( $reply['posts'] as $post ) {
            if ( array_key_exists( 'matches', $post ) ) {
                $room += $key;
            }
        }
        foreach ( $located as $i => $locations ) {
            foreach ( $locations as $n => $location ) {
                $cost = strlen( (string) wp_json_encode( $location, Server::RESULT_JSON_FLAGS ) ) + ( 0 === $n ? $key : 1 );
                if ( $cost > $room ) {
                    $truncated = true;
                    break 2;
                }
                $room -= $cost;
                $reply['posts'][ $i ]['matches'][] = $location;
            }
        }
        foreach ( $reply['posts'] as $i => $post ) {
            if ( array_key_exists( 'matches', $post ) && array() === $post['matches'] ) {
                unset( $reply['posts'][ $i ]['matches'] );
            }
        }
        for ( $i = count( $located ) - 1; $i >= 0 && strlen( (string) wp_json_encode( $reply, Server::RESULT_JSON_FLAGS ) ) > self::MAX_REPLY_BYTES; ) {
            if ( empty( $reply['posts'][ $i ]['matches'] ) ) {
                --$i;
                continue;
            }
            array_pop( $reply['posts'][ $i ]['matches'] );
            $truncated = true;
        }
        if ( ! $truncated ) {
            unset( $reply['truncated'] );
        }
        return $reply;
    }

    protected function lower_backtrack_limit() {
        return Text_Matcher::lower_backtrack_limit();
    }

    protected function elapsed( float $started ): float {
        return microtime( true ) - $started;
    }

    private function check_arguments( array $arguments ): array {
        $this->validate_required( $arguments, array( 'ids', 'queries' ) );
        $ids     = self::ids( $arguments['ids'] );
        $queries = self::queries( $arguments['queries'] );

        $field = $arguments['field'] ?? 'post_content';
        if ( ! in_array( $field, self::FIELDS, true ) ) {
            throw new \InvalidArgumentException( 'field must be one of: post_content, post_excerpt, post_title' );
        }

        $regex          = ! empty( $arguments['regex'] );
        $case_sensitive = ! isset( $arguments['case_sensitive'] ) || ! empty( $arguments['case_sensitive'] );
        if ( $regex && ! Text_Matcher::regex_allowed() ) {
            throw new \InvalidArgumentException( 'Regex search is disabled on this site (filter: easy_mcp_ai_replace_in_post_allow_regex).' );
        }
        $patterns = array();
        foreach ( $queries as $query ) {
            if ( $regex ) {
                $patterns[] = Text_Matcher::pattern( $query, $case_sensitive ? '' : 'i' );
            } else {
                $patterns[] = $case_sensitive ? null : '/' . preg_quote( $query, '/' ) . '/iu';
            }
        }

        return array(
            'ids'         => $ids,
            'queries'     => $queries,
            'patterns'    => $patterns,
            'field'       => $field,
            'regex'       => $regex,
            'max_matches' => self::bounded( $arguments, 'max_matches', 0, self::MAX_MATCHES, 0 ),
            'context'     => self::bounded( $arguments, 'context', 0, self::MAX_CONTEXT, self::DEFAULT_CONTEXT ),
        );
    }

    private static function ids( $value ): array {
        if ( ! is_array( $value ) || array() === $value || count( $value ) > self::MAX_POSTS ) {
            throw new \InvalidArgumentException( sprintf( 'ids must be a list of 1 to %d post IDs.', self::MAX_POSTS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $ids = array();
        foreach ( array_values( $value ) as $i => $id ) {
            $parsed = is_bool( $id ) ? false : filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
            if ( false === $parsed ) {
                throw new \InvalidArgumentException( sprintf( 'ids[%d] must be a positive integer post ID.', $i ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $ids[] = $parsed;
        }
        return array_values( array_unique( $ids ) );
    }

    private static function queries( $value ): array {
        if ( ! is_array( $value ) || array() === $value || count( $value ) > self::MAX_QUERIES ) {
            throw new \InvalidArgumentException( sprintf( 'queries must be a list of 1 to %d texts.', self::MAX_QUERIES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $queries = array();
        foreach ( array_values( $value ) as $i => $query ) {
            if ( ! is_string( $query ) || '' === $query ) {
                throw new \InvalidArgumentException( sprintf( 'queries[%d] must be a non-empty text.', $i ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            Text_Matcher::check_length( $query );
            $queries[] = $query;
        }
        return $queries;
    }

    private static function bounded( array $arguments, string $name, int $min, int $max, int $default ): int {
        if ( ! isset( $arguments[ $name ] ) ) {
            return $default;
        }
        $value = is_bool( $arguments[ $name ] ) ? false : filter_var( $arguments[ $name ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => $min, 'max_range' => $max ) ) );
        if ( false === $value ) {
            throw new \InvalidArgumentException( sprintf( '%s must be an integer from %d to %d.', $name, $min, $max ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $value;
    }

    private function find_in( int $id, array $request, float $started, int &$spent, bool &$truncated ): array {
        $post = get_post( $id );
        if ( ! is_object( $post ) || ! Privacy_Requests::may_read( $post ) || ! current_user_can( 'edit_post', $id ) ) {
            return array(
                array(
                    'id'    => $id,
                    'error' => 'Post not found, or you cannot edit it.',
                ),
                array(),
            );
        }

        $field   = $request['field'];
        $subject = (string) $post->{$field};
        $limit   = $request['regex'] ? Text_Matcher::MAX_REGEX_SUBJECT_BYTES : Text_Matcher::MAX_SUBJECT_BYTES;
        $out     = array(
            'id'     => $id,
            'field'  => $field,
            'bytes'  => strlen( $subject ),
            'sha256' => hash( 'sha256', $subject ),
            'counts' => array(),
        );

        $incomplete = array();
        $found      = array();
        foreach ( $request['queries'] as $i => $query ) {
            $reason = null;
            if ( $this->elapsed( $started ) > self::TIME_BUDGET ) {
                $reason = 'time_budget';
            } elseif ( strlen( $subject ) > $limit ) {
                $reason = 'field_too_large';
            }
            $count = null === $reason ? self::count( $subject, $query, $request['patterns'][ $i ] ) : $reason;
            if ( is_string( $count ) ) {
                $out['counts'][] = null;
                $incomplete[]    = array(
                    'query'  => $i,
                    'reason' => $count,
                );
                continue;
            }
            $out['counts'][] = $count;
            if ( $count > 0 && $request['max_matches'] > 0 ) {
                foreach ( self::offsets( $subject, $query, $request['patterns'][ $i ], $request['max_matches'] ) as $match ) {
                    $found[] = array( 'query' => $i ) + $match;
                }
            }
        }

        $locations = array();
        if ( array() !== $found ) {
            $out['matches'] = array();
            list( $locations, $note ) = $this->locations( $subject, $field, $found, $request['context'], $spent, $truncated );
            if ( null !== $note ) {
                $out['block_paths'] = $note;
            }
        }
        if ( array() !== $incomplete ) {
            $out['incomplete'] = $incomplete;
        }
        $adapter = 'post_content' === $field ? Builder_Registry::for_post( $post ) : null;
        if ( null !== $adapter && ! $adapter->renders_post_content( $post ) ) {
            $out['builder'] = $adapter->id();
        }
        return array( $out, $locations );
    }

    private static function count( string $subject, string $query, ?string $pattern ) {
        if ( null === $pattern ) {
            return substr_count( $subject, $query );
        }
        $count = preg_match_all( $pattern, $subject );
        return false === $count ? self::preg_failure() : $count;
    }

    private static function preg_failure(): string {
        $error = preg_last_error();
        if ( PREG_BACKTRACK_LIMIT_ERROR === $error ) {
            return 'backtrack_limit';
        }
        if ( PREG_BAD_UTF8_ERROR === $error ) {
            return 'invalid_utf8';
        }
        return 'regex_error';
    }

    private static function offsets( string $subject, string $query, ?string $pattern, int $max ): array {
        $out = array();
        if ( null !== $pattern ) {
            preg_replace_callback(
                $pattern,
                static function ( array $match ) use ( &$out ): string {
                    $out[] = array(
                        'offset' => $match[0][1],
                        'length' => strlen( $match[0][0] ),
                    );
                    return $match[0][0];
                },
                $subject,
                $max,
                $count,
                PREG_OFFSET_CAPTURE
            );
            return $out;
        }
        $offset = 0;
        while ( count( $out ) < $max ) {
            $at = strpos( $subject, $query, $offset );
            if ( false === $at ) {
                break;
            }
            $out[]  = array(
                'offset' => $at,
                'length' => strlen( $query ),
            );
            $offset = $at + strlen( $query );
        }
        return $out;
    }

    private function locations( string $subject, string $field, array $found, int $context, int &$spent, bool &$truncated ): array {
        $blocks = null;
        $note   = null;
        if ( 'post_content' === $field ) {
            $offsets = array();
            foreach ( $found as $match ) {
                $offsets[] = $match['offset'];
                $offsets[] = $match['offset'] + max( 0, $match['length'] - 1 );
            }
            $blocks = Block_Locator::locate( $subject, $offsets );
            if ( null === $blocks ) {
                $note = 'Not given: the blocks are not well formed or could not be read, or a plugin replaced the block parser.';
            }
        }

        $locations = array();
        foreach ( $found as $match ) {
            if ( $spent > self::MAX_REPLY_BYTES ) {
                $truncated = true;
                break;
            }
            $location = $match + array( 'snippet' => Text_Matcher::window( $subject, $match['offset'], $match['length'], $context ) );
            if ( null !== $blocks ) {
                $start                  = $blocks[ $match['offset'] ];
                $last                   = $blocks[ $match['offset'] + max( 0, $match['length'] - 1 ) ];
                $location['block_path'] = $start['path'];
                $location['in']         = $start['in'];
                if ( $last['path'] !== $start['path'] ) {
                    $location['spans_blocks'] = true;
                }
            }
            $spent      += strlen( (string) wp_json_encode( $location, Server::RESULT_JSON_FLAGS ) );
            $locations[] = $location;
        }
        return array( $locations, $note );
    }
}
