<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Block_Checks;
use Easy_MCP_AI\Cloud\Cloud_Refs;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks_Edit extends Local_Block_Tool {

    protected function writes_content(): bool {
        return true;
    }

    public function get_name() {
        return 'blocks_edit';
    }

    public function get_title() {
        return 'Edit blocks';
    }

    public function get_approval_policy( array $arguments ) {
        return $this->is_read_only_call( $arguments ) ? 'never' : parent::get_approval_policy( $arguments );
    }

    public function get_description() {
        return implode(
            "\n",
            array(
                'Edit single blocks or sections of an existing page, post or product: insert or replace blocks with refs from blocks_execute, remove blocks, or set block attributes. A product keeps no revisions and its old description can\'t be read by the tools, so saving an edit to one needs confirm: true, and a ref with svg, form, input, style or similar tags keeps them only for a user with unfiltered_html: the tool warns when the saved description lost them. After saving inserted markup into a page or post, the tool reads it back and warns when the site removed such elements.',
                'Read the block paths with blocks_get first. Ops apply in order, each on the tree the previous ones left. dry_run checks them without saving.',
                'Every op with a path (insert before/after, replace, remove, set_attributes) needs expected_modified (the modified value blocks_get returned, or the one the last edit returned): the edit is refused when the post changed since, because paths are positions. Only append and prepend inserts and dry runs go without. The answer lists the blocks each op hit and the new modified value, which the next edit can use. Check them against what you meant.',
            )
        );
    }

    public function is_read_only_call( array $arguments ) {
        return isset( $arguments['dry_run'] ) && true === \rest_sanitize_boolean( $arguments['dry_run'] );
    }

    public function describe( array $arguments ) {
        try {
            $sentence = $this->edit_sentence( $arguments );
        } catch ( \Throwable $e ) {
            $sentence = null;
        }
        return null === $sentence ? self::generic_description( $this, $arguments ) : $sentence;
    }

    public function approval_details( array $arguments ) {
        $ops = self::ops_list( $arguments );
        if ( array() === $ops ) {
            return null;
        }
        $details = array();
        foreach ( $ops as $i => $op ) {
            $parts = array();
            if ( array_key_exists( 'ref', $op ) ) {
                $stored  = is_string( $op['ref'] ) ? Cloud_Refs::get( $op['ref'] ) : null;
                $parts[] = null === $stored ? 'Unknown or expired ref: this call will be refused.' : $stored['markup'];
            }
            if ( isset( $op['attrs'] ) && is_array( $op['attrs'] ) ) {
                $parts[] = (string) \wp_json_encode( (object) $op['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }
            if ( array() === $parts ) {
                $parts[] = self::op_action( $op );
            }
            $details[] = array( 'label' => sprintf( 'ops[%1$d] %2$s', $i, self::op_label( $op ) ), 'after' => implode( "\n", $parts ) );
        }
        try {
            $generic = null === $this->edit_sentence( $arguments );
        } catch ( \Throwable $e ) {
            $generic = true;
        }
        return $generic ? array_merge( $details, self::generic_details( $this, $arguments ) ) : $details;
    }

    private function edit_sentence( array $arguments ): ?string {
        $post_id = self::int_arg( $arguments, 'post_id', 1 );
        $type    = self::enum_arg( $arguments, 'type', self::POST_KINDS ) ?? 'page';
        $ops     = self::ops_list( $arguments );
        if ( null === $post_id || array() === $ops ) {
            return null;
        }
        $target = $this->target_line( $type, $post_id );
        if ( null === $target ) {
            return null;
        }
        $kinds = array();
        $force = 0;
        foreach ( $ops as $op ) {
            $kind           = self::short( is_string( $op['op'] ?? null ) ? $op['op'] : 'op', 20 );
            $kinds[ $kind ] = ( $kinds[ $kind ] ?? 0 ) + 1;
            $force         += true === ( $op['force'] ?? null ) ? 1 : 0;
        }
        $counted = array();
        foreach ( $kinds as $kind => $count ) {
            $counted[] = $count . ' ' . $kind;
        }
        return sprintf(
            'Edit %1$s: %2$d %3$s (%4$s)%5$s',
            $target,
            count( $ops ),
            1 === count( $ops ) ? 'change' : 'changes',
            implode( ', ', $counted ),
            $force > 0 ? sprintf( ', %d of them with force, which writes attributes even where the editor will flag the block as invalid', $force ) : ''
        );
    }

    private static function ops_list( array $arguments ): array {
        $ops = $arguments['ops'] ?? null;
        if ( ! is_array( $ops ) || $ops !== array_values( $ops ) ) {
            return array();
        }
        foreach ( $ops as $op ) {
            if ( ! is_array( $op ) ) {
                return array();
            }
        }
        return $ops;
    }

    private static function op_label( array $op ): string {
        $kind  = self::short( is_string( $op['op'] ?? null ) ? $op['op'] : 'op', 20 );
        $path  = is_string( $op['path'] ?? null ) && '' !== $op['path'] ? self::short( $op['path'] ) : null;
        $at    = $op['at'] ?? null;
        $force = true === ( $op['force'] ?? null ) ? ' with force' : '';
        if ( 'insert' === $kind && null !== $path && ( 'before' === $at || 'after' === $at ) ) {
            return 'insert ' . $at . ' ' . $path . $force;
        }
        if ( 'insert' === $kind && 'prepend' === $at ) {
            return 'insert at the start' . $force;
        }
        if ( 'insert' === $kind && 'append' === $at ) {
            return 'insert at the end' . $force;
        }
        $where = $path ?? ( is_string( $at ) && '' !== $at ? self::short( $at ) : null );
        return $kind . ( null !== $where ? ' at ' . $where : '' ) . $force;
    }

    private static function op_action( array $op ): string {
        $path = is_string( $op['path'] ?? null ) && '' !== $op['path'] ? 'the block at ' . self::short( $op['path'] ) : 'a block (no path given)';
        $kind = is_string( $op['op'] ?? null ) ? $op['op'] : '';
        if ( 'remove' === $kind ) {
            return 'Removes ' . $path . '.';
        }
        return sprintf( 'Does %s on %s with nothing to write: this call will be refused.', self::short( '' === $kind ? 'an unknown op' : $kind, 20 ), $path );
    }

    public function get_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'post_id'           => self::post_id_property(),
                'type'              => self::type_property(),
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'description' => 'Check and report the ops without saving.',
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'description' => "Products only, unless dry_run: required, since a product keeps no revisions and the tools can't read its old description.",
                ),
                'expected_modified' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 64,
                    'description' => 'The modified value blocks_get (or your last edit) returned. Required for any op with a path: the edit is refused when the post changed since.',
                ),
                'ops'               => array(
                    'type'        => 'array',
                    'minItems'    => 1,
                    'maxItems'    => Block_Checks::MAX_EDIT_OPS,
                    'description' => 'Applied in order, each on the tree the previous ones left. insert {ref, at: before|after (with path) or append|prepend (without)}; replace {ref, path}; remove {path}; set_attributes {path, attrs, force?}.',
                    'items'       => array(
                        'type'                 => 'object',
                        'properties'           => array(
                            'op'    => array(
                                'type' => 'string',
                                'enum' => array( 'insert', 'replace', 'remove', 'set_attributes' ),
                            ),
                            'ref'   => self::ref_property(),
                            'at'    => array(
                                'type' => 'string',
                                'enum' => array( 'before', 'after', 'append', 'prepend' ),
                            ),
                            'path'  => self::path_property( 'A block path from blocks_get: zero-based indexes joined by dots, e.g. "0.2.1".' ),
                            'attrs' => array(
                                'type'        => 'object',
                                'description' => 'set_attributes: attribute name → value (null deletes it).',
                            ),
                            'force' => array(
                                'type'        => 'boolean',
                                'description' => 'set_attributes: write the attributes even where the editor will flag the block until it is recovered.',
                            ),
                        ),
                        'required'             => array( 'op' ),
                    ),
                ),
            ),
            'required'             => array( 'post_id', 'ops' ),
        );
    }

    private function checked_arguments( array $arguments ): array {
        $post_id  = self::int_arg( $arguments, 'post_id', 1 );
        $type     = self::enum_arg( $arguments, 'type', self::POST_KINDS ) ?? 'page';
        $dry_run  = self::bool_arg( $arguments, 'dry_run' );
        $confirm  = self::bool_arg( $arguments, 'confirm' );
        $expected = self::string_arg( $arguments, 'expected_modified', 1, 64 );
        $ops      = self::ops_arg( $arguments );
        if ( null === $post_id ) {
            throw new Detailed_Tool_Error( 'Missing required parameters: post_id' );
        }

        foreach ( $ops as $i => $op ) {
            $problem = Block_Checks::op_problem( $op, $i );
            if ( null !== $problem ) {
                throw self::refusal( $problem );
            }
        }
        $has_path = false;
        foreach ( $ops as $op ) {
            $has_path = $has_path || array_key_exists( 'path', $op );
        }
        if ( true !== $dry_run && null === $expected && $has_path ) {
            throw self::refusal( 'Ops with a path (before/after inserts, replace, remove, set_attributes) need expected_modified: the modified value from blocks_get, since paths are positions. Call blocks_get, then call blocks_edit again with it.' );
        }
        if ( 'product' === $type && true !== $dry_run && true !== $confirm ) {
            throw self::refusal( $this->confirm_text( 'an edit' ) );
        }
        $refs = array();
        foreach ( $ops as $i => $op ) {
            if ( ! array_key_exists( 'ref', $op ) || isset( $refs[ $op['ref'] ] ) ) {
                continue;
            }
            $stored = Cloud_Refs::get( $op['ref'] );
            if ( null === $stored ) {
                throw self::refusal( sprintf( 'ops[%1$d]: %2$s', $i, Block_Checks::UNKNOWN_REF ) );
            }
            if ( '' === trim( $stored['markup'] ) ) {
                throw self::refusal( sprintf( 'ops[%1$d]: %2$s', $i, Block_Checks::NOTHING_BUILT ) );
            }
            $refs[ $op['ref'] ] = $stored;
        }
        $inserted_markup = '';
        $markup_bytes    = 0;
        $attrs_bytes     = 0;
        foreach ( $ops as $op ) {
            if ( array_key_exists( 'ref', $op ) ) {
                $markup_bytes += strlen( $refs[ $op['ref'] ]['markup'] );
                if ( 'insert' === $op['op'] || 'replace' === $op['op'] ) {
                    $inserted_markup .= $refs[ $op['ref'] ]['markup'];
                }
            }
            if ( array_key_exists( 'attrs', $op ) ) {
                $attrs_bytes += strlen( (string) \wp_json_encode( (object) $op['attrs'] ) );
            }
        }
        $request_bytes = $markup_bytes + $attrs_bytes;
        if ( $request_bytes > Block_Checks::MAX_MARKUP_BYTES ) {
            throw self::refusal( sprintf( 'These ops send %s MB of blocks and attributes, over the 4 MB one edit may send. Split them over several blocks_edit calls.', self::megabytes( $request_bytes ) ) );
        }
        return array(
            'post_id'         => $post_id,
            'type'            => $type,
            'dry_run'         => $dry_run,
            'expected'        => $expected,
            'ops'             => $ops,
            'refs'            => $refs,
            'inserted_markup' => $inserted_markup,
            'request_bytes'   => $request_bytes,
        );
    }

    public function precheck( array $arguments ): void {
        $checked = $this->checked_arguments( $arguments );
        if ( true === $checked['dry_run'] ) {
            return;
        }
        if ( ! $this->may_change_nothing( $checked['type'], $checked['dry_run'], $checked['ops'] ) ) {
            $this->refuse_unavailable( 'wp_update_post_blocks', 'saves the edit' );
        }
        if ( null !== $checked['expected'] ) {
            $this->refuse_unavailable( self::read_tool( $checked['type'] ), sprintf( 'reads when the %s last changed', $checked['type'] ) );
        }
    }

    protected function perform( array $arguments ) {
        $checked         = $this->checked_arguments( $arguments );
        $post_id         = $checked['post_id'];
        $type            = $checked['type'];
        $dry_run         = $checked['dry_run'];
        $expected        = $checked['expected'];
        $ops             = $checked['ops'];
        $refs            = $checked['refs'];
        $inserted_markup = $checked['inserted_markup'];
        $request_bytes   = $checked['request_bytes'];

        if ( true !== $dry_run && null !== $expected ) {
            $current = $this->read_modified( 'blocks_edit', $type, $post_id );
            if ( null === $current ) {
                throw self::refusal( sprintf( "Couldn't tell when %1\$s %2\$d on this site last changed, so nothing was edited.", $type, $post_id ) );
            }
            if ( $current !== $expected ) {
                throw self::refusal( sprintf( 'The %1$s changed since you read it (modified %2$s, not %3$s). Call blocks_get again, then edit.', $type, $current, $expected ) );
            }
        }
        $block_types = array();
        foreach ( $refs as $stored ) {
            $block_types = array_merge( $block_types, $stored['blockTypes'] );
        }
        $warnings = array();
        $this->check_block_plugins( array_values( array_unique( $block_types ) ), $warnings );

        if ( $this->may_change_nothing( $type, $dry_run, $ops ) && $this->ops_change_nothing( $type, $post_id, $ops, $refs ) ) {
            $output = array(
                'content_notice' => Block_Checks::SITE_CONTENT_NOTICE,
                'message'        => sprintf( 'Nothing was saved: %1$s %2$d already holds this.', $type, $post_id ),
                'post_id'        => $post_id,
                'saved'          => false,
                'changed'        => false,
                'ops_applied'    => 0,
            );
            $modified = $this->read_modified_quietly( $type, $post_id );
            if ( null !== $modified ) {
                $output['modified'] = $modified;
            }
            if ( array() !== $warnings ) {
                $output['warnings'] = $warnings;
            }
            return $output;
        }

        $plugin_ops = array();
        foreach ( $ops as $op ) {
            $sent = array( 'op' => $op['op'] );
            foreach ( array( 'at', 'path' ) as $key ) {
                if ( array_key_exists( $key, $op ) ) {
                    $sent[ $key ] = $op[ $key ];
                }
            }
            if ( array_key_exists( 'ref', $op ) ) {
                $sent['content'] = $refs[ $op['ref'] ]['markup'];
            }
            foreach ( array( 'attrs', 'force' ) as $key ) {
                if ( array_key_exists( $key, $op ) ) {
                    $sent[ $key ] = $op[ $key ];
                }
            }
            $plugin_ops[] = $sent;
        }
        $args = array(
            'post_id'   => $post_id,
            'post_type' => self::REST_BASE[ $type ],
        );
        if ( null !== $dry_run ) {
            $args['dry_run'] = $dry_run;
        }
        $args['ops'] = $plugin_ops;
        $data        = $this->ask( 'wp_update_post_blocks', $args, true !== $dry_run, self::target( 'blocks_edit', $post_id, $type ) );

        $counted = isset( $data['ops_applied'] ) && is_int( $data['ops_applied'] ) ? $data['ops_applied'] . ( 1 === $data['ops_applied'] ? ' op' : ' ops' ) : 'the ops';
        $summary = true === ( $data['saved'] ?? null )
            ? sprintf( 'Saved %1$s on %2$s %3$d of this site.', $counted, $type, $post_id )
            : sprintf( '%1$s%2$s checked on %3$s %4$d of this site, nothing saved.', true === $dry_run ? 'Dry run: ' : '', $counted, $type, $post_id );
        $hit     = array();
        foreach ( (array) ( $data['changes'] ?? array() ) as $change ) {
            if ( ! is_array( $change ) || ! isset( $change['op'] ) || ! is_string( $change['op'] ) ) {
                continue;
            }
            $hit[] = $change['op'] . ( isset( $change['path'] ) && is_string( $change['path'] ) ? ' at ' . $change['path'] : '' ) . ( isset( $change['block'] ) && is_string( $change['block'] ) ? ': ' . $change['block'] : '' );
        }
        if ( true === ( $data['saved'] ?? null ) && array() !== Block_Checks::kses_stripped_tags( $inserted_markup ) ) {
            $back  = $this->ask_quietly( self::read_tool( $type ), self::read_arguments( $type, $post_id ) );
            $field = 'product' === $type ? 'description' : 'content';
            if ( null === $back || ! isset( $back[ $field ] ) || ! is_string( $back[ $field ] ) ) {
                $warnings[] = sprintf( "Couldn't read the %s back to check what the site saved (it needs posts read, and the answer must fit the size limit).", $type );
            } else {
                $removed = Block_Checks::removed_tags( $inserted_markup, $back[ $field ] );
                if ( null !== $removed ) {
                    $warnings[] = sprintf( 'The site removed %1$s from the saved %2$s, likely because the connected WordPress user lacks unfiltered_html. Check the %2$s.', $removed, $type );
                }
            }
        }
        if ( $request_bytes > Block_Checks::RECOMMENDED_REQUEST_BYTES ) {
            $warnings[] = $this->size_warning( $request_bytes );
        }
        if ( array() !== $warnings ) {
            $data['warnings'] = array_merge( isset( $data['warnings'] ) && is_array( $data['warnings'] ) ? $data['warnings'] : array(), $warnings );
        }
        $lines = array( $summary );
        if ( array() !== $hit ) {
            $lines[] = 'Blocks hit: ' . implode( '; ', $hit ) . '.';
        }
        return array_merge( array( 'content_notice' => Block_Checks::SITE_CONTENT_NOTICE, 'message' => implode( "\n", $lines ) ), $data );
    }

    private function may_change_nothing( string $type, ?bool $dry_run, array $ops ): bool {
        if ( true === $dry_run || 'product' === $type ) {
            return false;
        }
        foreach ( $ops as $op ) {
            if ( ! in_array( $op['op'], array( 'replace', 'set_attributes' ), true ) || ! isset( $op['path'] ) ) {
                return false;
            }
        }
        return true;
    }

    private function ops_change_nothing( string $type, int $post_id, array $ops, array $refs ): bool {
        $page = $this->ask_quietly( self::read_tool( $type ), self::read_arguments( $type, $post_id ) );
        if ( null === $page || ! isset( $page['content'] ) || ! is_string( $page['content'] ) ) {
            return false;
        }
        $tree = parse_blocks( $page['content'] );
        foreach ( $ops as $op ) {
            $block = self::block_at( $tree, (string) $op['path'] );
            if ( null === $block ) {
                return false;
            }
            if ( 'replace' === $op['op'] ) {
                $new = array_values( array_filter( parse_blocks( $refs[ $op['ref'] ]['markup'] ?? '' ), array( __CLASS__, 'is_addressable' ) ) );
                if ( 1 !== count( $new ) || serialize_blocks( array( $new[0] ) ) !== serialize_blocks( array( $block ) ) ) {
                    return false;
                }
                continue;
            }
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            foreach ( (array) ( $op['attrs'] ?? array() ) as $name => $value ) {
                $present = array_key_exists( $name, $attrs );
                if ( null === $value ? $present : ( ! $present || wp_json_encode( $attrs[ $name ] ) !== wp_json_encode( $value ) ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function is_addressable( $block ): bool {
        return is_array( $block ) && ( ! empty( $block['blockName'] ) || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) );
    }

    private static function block_at( array $blocks, string $path ): ?array {
        $block = null;
        foreach ( explode( '.', $path ) as $index ) {
            $level = array_values( array_filter( $blocks, array( __CLASS__, 'is_addressable' ) ) );
            if ( ! isset( $level[ (int) $index ] ) ) {
                return null;
            }
            $block  = $level[ (int) $index ];
            $blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
        }
        return $block;
    }

    private static function ops_arg( array $arguments ): array {
        $ops = $arguments['ops'] ?? null;
        if ( ! is_array( $ops ) || array() === $ops || $ops !== array_values( $ops ) ) {
            throw new Detailed_Tool_Error( 'ops must be a non-empty list of op objects.' );
        }
        if ( count( $ops ) > Block_Checks::MAX_EDIT_OPS ) {
            throw new Detailed_Tool_Error( sprintf( 'ops takes at most %d ops.', Block_Checks::MAX_EDIT_OPS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        foreach ( $ops as $i => $op ) {
            if ( ! is_array( $op ) || ( array() !== $op && $op === array_values( $op ) ) ) {
                throw new Detailed_Tool_Error( sprintf( 'ops[%d] must be an object.', $i ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            self::check_op_fields( $op, $i );
        }
        return $ops;
    }

    private static function check_op_fields( array $op, int $i ): void {
        $label = static function ( string $field ) use ( $i ): string {
            return sprintf( 'ops[%1$d].%2$s', $i, $field );
        };
        if ( ! isset( $op['op'] ) || ! in_array( $op['op'], array( 'insert', 'replace', 'remove', 'set_attributes' ), true ) ) {
            throw new Detailed_Tool_Error( sprintf( 'ops[%d]: op must be insert, replace, remove or set_attributes.', $i ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        foreach ( $op as $field => $value ) {
            $field = (string) $field;
            if ( 'ref' === $field && ( ! is_string( $value ) || '' === $value || strlen( $value ) > self::MAX_REF_CHARS ) ) {
                throw new Detailed_Tool_Error( $label( 'ref' ) . ' must be a ref from blocks_execute.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            if ( 'at' === $field && ! in_array( $value, array( 'before', 'after', 'append', 'prepend' ), true ) ) {
                throw new Detailed_Tool_Error( $label( 'at' ) . ' must be before, after, append or prepend.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            if ( 'path' === $field && ( ! is_string( $value ) || strlen( $value ) > 100 || 1 !== preg_match( self::PATH_PATTERN, $value ) ) ) {
                throw new Detailed_Tool_Error( $label( 'path' ) . ' must be zero-based indexes joined by dots, e.g. "0.2.1".' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            if ( 'attrs' === $field && ( ! is_array( $value ) || ( array() !== $value && $value === array_values( $value ) ) ) ) {
                throw new Detailed_Tool_Error( $label( 'attrs' ) . ' must be an object.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            if ( 'force' === $field && ! is_bool( $value ) ) {
                throw new Detailed_Tool_Error( $label( 'force' ) . ' must be true or false.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
    }
}
