<?php

namespace Easy_MCP_AI\Builders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Elementor_Editor {

    const OPS = array( 'set_text', 'set_link', 'set_image', 'set_fields', 'replace_text', 'add_item', 'remove_item', 'move_item', 'insert', 'remove', 'move', 'duplicate' );

    const SET_OPS = array( 'set_text', 'set_link', 'set_image', 'set_fields', 'replace_text' );

    const ITEM_OPS = array( 'add_item', 'remove_item', 'move_item' );

    const STRUCTURE_OPS = array( 'remove', 'move', 'duplicate' );

    const MAX_OPS = 50;

    const MAX_CREATED = 150;

    const MAX_COLUMNS = 10;

    const SECTION_PRESETS = array(
        '21' => array( 33, 66 ),
        '22' => array( 66, 33 ),
        '31' => array( 25, 25, 50 ),
        '32' => array( 50, 25, 25 ),
        '33' => array( 25, 50, 25 ),
        '34' => array( 16, 66, 16 ),
    );

    const TEXT_KINDS = array( 'text', 'html' );

    const REPLACE_KINDS = array( 'text', 'html', 'code' );

    const FIELD_KINDS = array( 'text', 'html', 'code', 'select', 'number', 'boolean', 'color', 'size', 'spacing', 'gap', 'gallery' );

    const SIDES = array(
        'spacing' => array( 'top', 'right', 'bottom', 'left' ),
        'gap'     => array( 'column', 'row' ),
    );

    const CONTAINERS = array( 'section', 'column', 'container' );

    const CSS_VALUE = 'is written into the page CSS, so it takes a CSS value without ; { } < > \\ or a comment';

    const DYNAMIC_KEY = '__dynamic__';
    const GLOBALS_KEY = '__globals__';

    const ITEM_ID = '_id';

    const SAVED_DEFAULTS = array( 'text', 'textarea' );

    private $fields_of;

    private $hidden;

    private $media;

    private $nested;

    private $unfiltered;

    private $containers;

    private $kses;

    private $v4_unavailable;

    private $refs = array();

    private $taken = array();

    private $created = 0;

    private $notes = array();

    private $widths_written = array();

    public function __construct( callable $fields_of, callable $media, callable $nested, bool $unfiltered, bool $containers, ?callable $kses = null, ?callable $hidden = null, ?callable $v4_unavailable = null ) {
        $this->fields_of  = $fields_of;
        $this->media      = $media;
        $this->nested     = $nested;
        $this->unfiltered = $unfiltered;
        $this->containers = $containers;
        $this->kses       = $kses ?? 'wp_kses_post';
        $this->hidden     = $hidden ?? static function (): array {
            return array();
        };
        $this->v4_unavailable = $v4_unavailable ?? static function (): array {
            return array();
        };
    }

    public function plan( array $data, array $ops ): array {
        $errors = array();
        if ( array() === $ops ) {
            $errors[] = '`ops` is empty: give at least one operation.';
        }
        if ( count( $ops ) > self::MAX_OPS ) {
            $errors[] = sprintf( 'At most %d operations per call; this call has %d.', self::MAX_OPS, count( $ops ) );
        }
        $repeated = self::repeated_ids( $data );
        if ( array() !== $repeated ) {
            $errors[] = self::repeated_message( $repeated );
        }
        if ( array() !== $errors ) {
            return self::refused( $data, $errors );
        }

        $this->refs     = array();
        $this->taken    = Elementor_Tree::ids( $data );
        $this->created  = 0;
        $this->notes    = array();
        $this->widths_written = array();
        $next           = $data;
        $results        = array();
        foreach ( array_values( $ops ) as $index => $op ) {
            $applied = is_array( $op ) ? $this->apply( $next, $op ) : 'must be an object.';
            if ( is_string( $applied ) ) {
                $errors[] = sprintf( 'Operation %d: %s', $index + 1, $applied );
                continue;
            }
            $sized = $this->resize_sections( $next, array_values( $applied['data'] ) );
            if ( is_string( $sized ) ) {
                $errors[] = sprintf( 'Operation %d: %s', $index + 1, $sized );
                continue;
            }
            $next      = $sized;
            $results[] = $applied['result'];
            if ( 'column' === $applied['result']['type'] && in_array( $applied['result']['op'], self::SET_OPS, true ) && array() !== array_intersect( explode( ', ', $applied['result']['field'] ), array( '_column_size', '_inline_size' ) ) ) {
                $this->widths_written[] = $applied['result']['element'];
            }
        }
        if ( array() !== $errors ) {
            return self::refused( $data, $errors );
        }
        return array(
            'data'     => $next,
            'results'  => $results,
            'errors'   => array(),
            'warnings' => array_values( array_unique( $this->notes ) ),
        );
    }

    public static function repeated_message( array $repeated ): string {
        return sprintf( 'This layout has more than one element with the id %s, so no element can be addressed safely. Open the page in Elementor and save it once, which gives every element its own id.', implode( ', ', $repeated ) );
    }

    public static function repeated_ids( array $data ): array {
        $seen     = array();
        $repeated = array();
        foreach ( Elementor_Tree::elements( $data ) as $element ) {
            $id = isset( $element['id'] ) && is_scalar( $element['id'] ) ? (string) $element['id'] : '';
            if ( '' === $id ) {
                continue;
            }
            if ( isset( $seen[ $id ] ) ) {
                $repeated[ $id ] = true;
            }
            $seen[ $id ] = true;
        }
        return array_map( 'strval', array_keys( $repeated ) );
    }

    private static function refused( array $data, array $errors ): array {
        return array(
            'data'    => $data,
            'results' => array(),
            'errors'  => $errors,
        );
    }

    private function apply( array $data, array $op ) {
        $name = isset( $op['op'] ) && is_string( $op['op'] ) ? $op['op'] : '';
        if ( ! in_array( $name, self::OPS, true ) ) {
            return sprintf( 'unknown `op` "%s"; one of %s.', $name, implode( ', ', self::OPS ) );
        }
        if ( 'insert' === $name ) {
            return $this->insert( $data, $op );
        }
        $address = $this->resolve( $op, 'element' );
        if ( '' === $address ) {
            return '`element` is required: the element\'s address' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_get_page_layout' ) ? ' from wp_get_page_layout' : '' ) . '.';
        }
        $path = Elementor_Tree::path( $data, $address );
        if ( null === $path ) {
            return $this->no_element( $data, $address, \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_get_page_layout' ) ? '. Read it again with wp_get_page_layout.' : '.' );
        }
        $element = Elementor_Tree::at( $data, $path );
        $type    = self::type_of( $element );
        $expect  = isset( $op['expect'] ) && is_string( $op['expect'] ) ? $op['expect'] : '';
        if ( '' !== $expect && $expect !== $type ) {
            return sprintf( 'element "%s" is a %s, not the expected %s.', $address, $type, $expect );
        }
        if ( in_array( $name, self::STRUCTURE_OPS, true ) ) {
            return $this->restructure( $name, $data, $op, $path, $element, $type, $address );
        }
        $refusal = $this->element_refusal( $element, $type, $address );
        if ( null !== $refusal ) {
            return $refusal;
        }
        $fields = self::fields_or_none( ( $this->fields_of )( $type ), $type );
        if ( null === $fields ) {
            return sprintf( 'Elementor has no %s widget registered, so "%s" cannot be edited.', $type, $address );
        }
        if ( in_array( $name, self::ITEM_OPS, true ) ) {
            return $this->item_op( $name, $data, $op, $path, $element, array_values( $fields ), $type, $address );
        }
        return $this->set_op( $name, $data, $op, $path, $element, array_values( $fields ), $type, $address );
    }

    private function set_op( string $name, array $data, array $op, array $path, array $element, array $fields, string $type, string $address ) {
        $settings = $element['settings'];
        $in_item  = isset( $op['list'] ) || isset( $op['item'] );
        $target   = $settings;
        $specs    = $fields;
        $owner    = self::owner( $type );
        $hint     = self::hint( $type );
        $where    = '"' . $address . '"';
        if ( $in_item ) {
            $rows = $this->rows( $op, $fields, $settings, $type, $address );
            if ( is_string( $rows ) ) {
                return $rows;
            }
            $item  = self::text_arg( $op, 'item' );
            $index = self::row_index( $rows['rows'], $item );
            if ( null === $index ) {
                $read = \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_get_page_layout' );
                return '' === $item
                    ? '`item` is required with `list`: the id of one item' . ( $read ? ', from wp_get_page_layout' : '' ) . '.'
                    : sprintf( 'list "%s" of "%s" has no item "%s".%s', $rows['spec']['name'], $address, $item, $read ? ' Read the layout again with wp_get_page_layout.' : '' );
            }
            $target = $rows['rows'][ $index ];
            $specs  = $rows['spec']['item_fields'] ?? array();
            $owner  = sprintf( 'items of list "%s"', $rows['spec']['name'] );
            $hint   = self::item_hint( $type, $rows['spec']['name'] );
            $where  = sprintf( 'item "%s" of list "%s" in "%s"', $item, $rows['spec']['name'], $address );
        }

        if ( 'replace_text' === $name ) {
            $changes = $this->replaced_change( $op, $specs, $target, $in_item ? '' : $type, $owner, $hint, $where );
        } elseif ( 'set_fields' === $name ) {
            $changes = $this->field_changes( $op, $specs, $owner, $hint, $target );
        } else {
            $changes = $this->single_change( $name, $op, $specs, $target, $in_item ? '' : $type, $owner, $hint );
        }
        if ( is_string( $changes ) ) {
            return $changes;
        }
        $bound = array_values( array_unique( array_merge( self::bound_fields( $target, array_keys( $changes ) ), self::bound_by_group( $target, array_keys( $changes ), $specs ) ) ) );
        if ( array() !== $bound ) {
            return sprintf( 'field %s of %s shows a dynamic tag or a global value set in Elementor, so a value written there would not appear. Remove it in Elementor first.', implode( ', ', $bound ), $where );
        }
        $css_id = self::css_id_refusal( $changes );
        if ( null !== $css_id ) {
            return $css_id;
        }

        $before = array();
        $after  = array();
        foreach ( $changes as $field => $value ) {
            $before[ $field ] = $target[ $field ] ?? null;
            $after[ $field ]  = $value;
            $target[ $field ] = $value;
        }
        if ( ! $in_item ) {
            $hidden = $this->hidden_refusal( $type, $target, array_keys( $changes ), $where );
            if ( null !== $hidden ) {
                return $hidden;
            }
        }
        $result = array(
            'op'      => $name,
            'element' => $address,
            'type'    => $type,
            'field'   => implode( ', ', array_map( 'strval', array_keys( $changes ) ) ),
            'before'  => 'set_fields' === $name ? $before : reset( $before ),
            'after'   => 'set_fields' === $name ? $after : reset( $after ),
            'changed' => $before !== $after,
        );
        if ( 'replace_text' === $name ) {
            $result += Text_Replacement::result( (string) reset( $before ), (string) reset( $after ), (string) $op['search'], (string) $op['replace'] );
        }
        if ( $in_item ) {
            $result['list']                    = $rows['spec']['name'];
            $result['item']                    = $item;
            $rows['rows'][ $index ]            = $target;
            $settings[ $rows['spec']['name'] ] = $rows['rows'];
        } else {
            $settings = $target;
        }
        $element['settings'] = $settings;
        return array(
            'data'   => Elementor_Tree::replace( $data, $path, $element ),
            'result' => $result,
        );
    }

    private function item_op( string $name, array $data, array $op, array $path, array $element, array $fields, string $type, string $address ) {
        $rows = $this->rows( $op, $fields, $element['settings'], $type, $address );
        if ( is_string( $rows ) ) {
            return $rows;
        }
        if ( $this->is_nested( $element, $type ) ) {
            return sprintf( 'each item of "%s" (%s) has its own container of content, and Elementor\'s editor adds, removes and reorders the two together; do that in Elementor. An item\'s fields can be changed here with `list` and `item`.', $address, $type );
        }
        $list   = $rows['spec']['name'];
        $where  = sprintf( 'list "%s" of "%s"', $list, $address );
        $result = array(
            'op'      => $name,
            'element' => $address,
            'type'    => $type,
            'field'   => $list,
            'list'    => $list,
        );

        if ( 'add_item' === $name ) {
            $max = $rows['spec']['max_items'] ?? 0;
            if ( $max > 0 && count( $rows['rows'] ) >= $max ) {
                return sprintf( '%s already holds %d items, the most Elementor allows there.', $where, $max );
            }
            $at = $this->row_place( $op, $rows['rows'], '' );
            if ( is_string( $at ) ) {
                return $at;
            }
            $row = $this->new_row( $op, $rows, $type, $where );
            if ( is_string( $row ) ) {
                return $row;
            }
            array_splice( $rows['rows'], $at ?? count( $rows['rows'] ), 0, array( $row ) );
            $result += array(
                'item'    => (string) $row[ self::ITEM_ID ],
                'before'  => null,
                'after'   => $row,
                'changed' => true,
            );
        } else {
            $item  = self::text_arg( $op, 'item' );
            $index = self::row_index( $rows['rows'], $item );
            if ( null === $index ) {
                $read = \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_get_page_layout' );
                return '' === $item ? sprintf( '`item` is required for %s: the id of one item%s.', $name, $read ? ', from wp_get_page_layout' : '' ) : sprintf( '%s has no item "%s".%s', $where, $item, $read ? ' Read the layout again with wp_get_page_layout.' : '' );
            }
            $row = $rows['rows'][ $index ];
            array_splice( $rows['rows'], $index, 1 );
            $result['item'] = $item;
            if ( 'remove_item' === $name ) {
                $min = $rows['spec']['min_items'] ?? 0;
                if ( count( $rows['rows'] ) < $min ) {
                    return sprintf( '%s must keep at least %d %s, as Elementor\'s editor does.', $where, $min, 1 === $min ? 'item' : 'items' );
                }
                $result += array(
                    'before'  => $row,
                    'after'   => null,
                    'changed' => true,
                );
            } else {
                $at = $this->row_place( $op, $rows['rows'], $item );
                if ( is_string( $at ) ) {
                    return $at;
                }
                if ( null === $at ) {
                    return 'move_item needs `index` (the item\'s new position, from 0) or `after` (the item it follows).';
                }
                array_splice( $rows['rows'], $at, 0, array( $row ) );
                $result += array(
                    'before'  => $index,
                    'after'   => $at,
                    'changed' => $index !== $at,
                );
            }
        }
        $element['settings'][ $list ] = $rows['rows'];
        return array(
            'data'   => Elementor_Tree::replace( $data, $path, $element ),
            'result' => $result,
        );
    }

    private function new_row( array $op, array $rows, string $type, string $where ) {
        $specs = $rows['spec']['item_fields'] ?? array();
        $copy  = self::text_arg( $op, 'copy_of' );
        $row   = array();
        if ( '' !== $copy ) {
            $index = self::row_index( $rows['rows'], $copy );
            if ( null === $index ) {
                return sprintf( '%s has no item "%s" to copy.', $where, $copy );
            }
            $row = $rows['rows'][ $index ];
        } else {
            foreach ( $specs as $spec ) {
                if ( in_array( $spec['control'], self::SAVED_DEFAULTS, true ) && isset( $spec['default'] ) && '' !== (string) $spec['default'] ) {
                    $row[ $spec['name'] ] = $spec['default'];
                }
            }
        }
        if ( isset( $op['fields'] ) ) {
            if ( ! is_array( $op['fields'] ) ) {
                return '`fields` must be an object of field name to value.';
            }
            $changes = $this->checked_fields( $op['fields'], $specs, sprintf( 'items of list "%s"', $rows['spec']['name'] ), self::item_hint( $type, $rows['spec']['name'] ), true, $row );
            if ( is_string( $changes ) ) {
                return $changes;
            }
            $bound = self::bound_fields( $row, array_keys( $changes ) );
            if ( array() !== $bound ) {
                return sprintf( 'field %s of item "%s" in %s shows a dynamic tag or a global value set in Elementor, so a value written in the copy would not appear.', implode( ', ', $bound ), $copy, $where );
            }
            $row = array_merge( $row, $changes );
        }
        return array( self::ITEM_ID => Elementor_Tree::new_id( $this->taken ) ) + $row;
    }

    private function row_place( array $op, array $rows, string $moving ) {
        $after = self::text_arg( $op, 'after' );
        $index = self::index_arg( $op, count( $rows ) );
        if ( '' !== $after && null !== $index ) {
            return 'give `index` or `after`, not both.';
        }
        if ( '' === $after ) {
            return $index;
        }
        if ( $after === $moving ) {
            return 'an item cannot be placed after itself.';
        }
        $position = self::row_index( $rows, $after );
        return null === $position ? sprintf( 'no item "%s" in this list to place it after.', $after ) : $position + 1;
    }

    private function rows( array $op, array $fields, array $settings, string $type, string $address ) {
        $name = self::text_arg( $op, 'list' );
        if ( '' === $name ) {
            return sprintf( '`list` is required: the name of a list of items in %s%s.', self::owner( $type ), self::hint( $type ) );
        }
        $spec = self::field_spec( $fields, $name );
        if ( null === $spec ) {
            return sprintf( '%s have no list "%s"%s.', self::owner( $type ), $name, self::hint( $type ) );
        }
        if ( 'gallery' === $spec['kind'] ) {
            return sprintf( 'field "%s" of %s is a gallery of images, not a list of items: set it whole with set_fields and a list of image attachment IDs.', $name, self::owner( $type ) );
        }
        if ( 'list' !== $spec['kind'] || ! isset( $spec['item_fields'] ) ) {
            return sprintf( 'field "%s" of %s is not a list of items.', $name, self::owner( $type ) );
        }
        $where  = sprintf( 'list "%s" of "%s"', $name, $address );
        $stored = $settings[ $name ] ?? null;
        if ( ! is_array( $stored ) ) {
            return sprintf( '%s is not stored with the page: it shows the widget\'s default items, which Elementor stores once the page is saved in its editor. Open the page in Elementor and save it once, then read it again.', $where );
        }
        $rows = array_values( $stored );
        $ids  = array();
        foreach ( $rows as $row ) {
            $id = is_array( $row ) && isset( $row[ self::ITEM_ID ] ) && is_scalar( $row[ self::ITEM_ID ] ) ? (string) $row[ self::ITEM_ID ] : '';
            if ( '' === $id ) {
                return sprintf( '%s has an item without an id, so no item can be addressed safely. Open the page in Elementor and save it once, which gives every item an id.', $where );
            }
            if ( isset( $ids[ $id ] ) ) {
                return sprintf( '%s has two items with the id "%s", so no item can be addressed safely. Delete one of them in Elementor and add it again.', $where, $id );
            }
            $ids[ $id ] = true;
        }
        return array(
            'spec' => $spec,
            'rows' => $rows,
        );
    }

    private static function row_index( array $rows, string $id ): ?int {
        foreach ( $rows as $index => $row ) {
            if ( '' !== $id && (string) $row[ self::ITEM_ID ] === $id ) {
                return $index;
            }
        }
        return null;
    }

    private function insert( array $data, array $op ) {
        $place = $this->place( $data, $op );
        if ( is_string( $place ) ) {
            return $place;
        }
        $created = array();
        $built   = $this->build( $op, $place['parent'], $created, self::in_inner_section( $data, $place['path'] ) || self::is_inner_section( $place['parent'] ) );
        if ( is_string( $built ) ) {
            return $built;
        }
        $children = Elementor_Tree::children( $data, $place['path'] );
        array_splice( $children, $place['index'], 0, array( $built ) );
        $result = array(
            'op'      => 'insert',
            'element' => (string) $built['id'],
            'type'    => self::type_of( $built ),
            'field'   => '',
            'before'  => null,
            'after'   => self::position( $place['parent'], $place['index'] ),
            'changed' => true,
            'created' => $created,
        );
        $ref = self::text_arg( $op, 'ref' );
        if ( '' !== $ref ) {
            $result['ref'] = $ref;
        }
        return array(
            'data'   => Elementor_Tree::with_children( $data, $place['path'], $children ),
            'result' => $result,
        );
    }

    private function build( $spec, ?array $parent, array &$created, bool $in_inner = false ) {
        if ( ! is_array( $spec ) ) {
            return 'each entry of `children` must be an object.';
        }
        $type = self::text_arg( $spec, 'element_type' );
        if ( '' === $type ) {
            return '`element_type` is required: a widget type' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_list_layout_elements' ) ? ' from wp_list_layout_elements' : '' ) . ', or "container".';
        }
        if ( 0 === strpos( $type, 'e-' ) ) {
            return $this->v4_refusal( sprintf( '"%s" is an Elementor V4 element', $type ), 'adds' );
        }
        $is_container = 'container' === $type;
        $is_structure = self::is_structure( $type );
        if ( $is_container && ! $this->containers ) {
            return 'Elementor\'s container experiment (Flexbox Container) is off on this site, so a container would not show. Turn it on under Elementor › Settings › Features, or add widgets to an existing column.';
        }
        $fields = self::is_structure( $type ) && empty( $spec['fields'] ) ? array() : self::fields_or_none( ( $this->fields_of )( $type ), $type );
        if ( null === $fields ) {
            return sprintf( 'Elementor has no %s widget registered.', $type );
        }
        if ( ! $is_structure && ( $this->nested )( $type ) ) {
            return sprintf( 'a %s widget keeps a container of content for each of its items, which Elementor\'s editor creates with it; add it in Elementor, then edit its items here.', $type );
        }
        $kind      = self::kind_of( $type, $parent );
        $misplaced = self::misplaced( $kind, $parent ) ?? ( 'inner-section' === $kind && $in_inner ? 'in a column of an inner section; Elementor nests sections one level only' : null );
        if ( null !== $misplaced ) {
            return sprintf( 'a %s cannot go %s.', $type, $misplaced );
        }
        if ( $this->created >= self::MAX_CREATED ) {
            return self::too_many();
        }
        ++$this->created;

        $settings = array();
        if ( isset( $spec['fields'] ) ) {
            if ( ! is_array( $spec['fields'] ) ) {
                return '`fields` must be an object of field name to value.';
            }
            $settings = $this->checked_fields( $spec['fields'], array_values( $fields ), self::owner( $type ), self::hint( $type ), true );
            if ( is_string( $settings ) ) {
                return $settings;
            }
        }
        if ( isset( $spec['value'] ) ) {
            $text = $this->inserted_text( $spec['value'], $type, array_values( $fields ), $settings );
            if ( is_string( $text ) ) {
                return $text;
            }
            $settings += $text;
        }
        if ( array() !== $settings ) {
            $refusal = self::css_id_refusal( $settings ) ?? $this->hidden_refusal( $type, $settings, array_keys( $settings ), 'the new ' . $type );
            if ( null !== $refusal ) {
                return $refusal;
            }
        }
        $ref = self::text_arg( $spec, 'ref' );
        if ( '' !== $ref && ( isset( $this->refs[ $ref ] ) || isset( $this->taken[ $ref ] ) ) ) {
            return sprintf( '`ref` "%s" is already used in this layout or this call; give each inserted element its own.', $ref );
        }

        $id        = Elementor_Tree::new_id( $this->taken );
        $created[] = $id;
        if ( 'column' === $type && array() !== array_intersect( array_keys( $settings ), array( '_column_size', '_inline_size' ) ) ) {
            $this->widths_written[] = $id;
        }
        $element   = $is_structure
            ? array( 'id' => $id, 'elType' => $type, 'settings' => $settings, 'elements' => array() )
            : array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
        if ( 'column' !== $type && $is_structure ) {
            $element['isInner'] = null !== $parent;
        }
        if ( '' !== $ref ) {
            $this->refs[ $ref ] = $id;
        }
        if ( 'section' === $type ) {
            return $this->built_section( $element, $spec, $created );
        }
        if ( ! isset( $spec['children'] ) ) {
            return $element;
        }
        if ( ! $is_structure ) {
            return sprintf( 'a %s widget holds no elements, so it takes no `children`.', $type );
        }
        if ( ! is_array( $spec['children'] ) ) {
            return '`children` must be a list of elements to insert.';
        }
        $problems = array();
        foreach ( array_values( $spec['children'] ) as $child ) {
            $built = $this->build( $child, $element, $created, $in_inner );
            if ( is_string( $built ) ) {
                $problems[] = $built;
                continue;
            }
            $element['elements'][] = $built;
        }
        return array() === $problems ? $element : implode( '; ', array_unique( $problems ) );
    }

    private function inserted_text( $value, string $type, array $fields, array $settings ) {
        if ( self::is_structure( $type ) ) {
            return sprintf( 'a %s holds no text of its own, so it takes no `value`; insert a widget in it with the text.', $type );
        }
        if ( ! is_string( $value ) ) {
            return '`value` must be a string: the new widget\'s main text.';
        }
        $owner      = self::owner( $type );
        $candidates = self::default_fields( $type, 'text', self::TEXT_KINDS, self::primary_fields( $fields, $type ) );
        if ( array() === $candidates ) {
            return sprintf( '%s have no text field, so an insert of one takes no `value`; set its fields with `fields`%s.', $owner, self::hint( $type ) );
        }
        if ( count( $candidates ) > 1 ) {
            return self::ambiguous_field( $owner, 'text', $candidates, 'send the text in `fields`, under one of them' . self::hint( $type ) );
        }
        $spec = self::field_spec( $fields, $candidates[0] );
        if ( null === $spec ) {
            return sprintf( '%s have no field "%s"%s.', $owner, $candidates[0], self::hint( $type ) );
        }
        if ( ! in_array( $spec['kind'], self::TEXT_KINDS, true ) ) {
            return sprintf( 'field "%s" of %s holds %s, so an insert\'s `value` cannot fill it; set it with `fields`%s.', $spec['name'], $owner, $spec['kind'], self::hint( $type ) );
        }
        if ( array_key_exists( $spec['name'], $settings ) ) {
            return sprintf( 'the text of the new %s is sent twice, in `value` and in `fields` as "%s"; send it once.', $type, $spec['name'] );
        }
        return $this->text_value( $spec, $value );
    }

    private function built_section( array $section, array $spec, array &$created ) {
        $inner    = ! empty( $section['isInner'] );
        $children = isset( $spec['children'] ) ? $spec['children'] : null;
        if ( null !== $children && ! is_array( $children ) ) {
            return '`children` must be a list of columns to insert.';
        }
        $count = null === $children ? ( $inner ? 2 : 1 ) : count( $children );
        if ( isset( $spec['columns'] ) ) {
            $columns = is_int( $spec['columns'] ) || ( is_string( $spec['columns'] ) && ctype_digit( $spec['columns'] ) ) ? (int) $spec['columns'] : 0;
            if ( $columns < 1 || $columns > self::MAX_COLUMNS ) {
                return sprintf( '`columns` must be a whole number from 1 to %d.', self::MAX_COLUMNS );
            }
            if ( null !== $children && $columns !== $count ) {
                return sprintf( '`columns` is %d but `children` lists %d columns; give one of them.', $columns, $count );
            }
            $count = $columns;
        }
        if ( $count < 1 || $count > self::MAX_COLUMNS ) {
            return sprintf( 'a section holds 1 to %d columns, not %d.', self::MAX_COLUMNS, $count );
        }
        $widths = self::preset( $count, isset( $spec['structure'] ) ? (string) $spec['structure'] : '' );
        if ( is_string( $widths ) ) {
            return $widths;
        }
        $problems = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $entry = null === $children ? array( 'element_type' => 'column' ) : array_values( $children )[ $i ];
            if ( ! is_array( $entry ) || 'column' !== ( $entry['element_type'] ?? 'column' ) ) {
                $problems[] = 'a section takes only columns as `children`: each entry is { "element_type": "column", "children"?: [...] }';
                continue;
            }
            $built = $this->build( array( 'element_type' => 'column' ) + $entry, $section, $created, $inner );
            if ( is_string( $built ) ) {
                $problems[] = $built;
                continue;
            }
            $built['settings']['_column_size'] = $widths[ $i ];
            $built['isInner']                  = $inner;
            $section['elements'][]             = $built;
        }
        if ( array() !== $problems ) {
            return implode( '; ', array_unique( $problems ) );
        }
        $structure = isset( $spec['structure'] ) && '' !== (string) $spec['structure'] ? (string) $spec['structure'] : $count . '0';
        if ( '10' !== $structure ) {
            $section['settings']['structure'] = $structure;
        }
        return $section;
    }

    private static function preset( int $count, string $structure ) {
        if ( '' === $structure || $count . '0' === $structure ) {
            return array_fill( 0, $count, (int) floor( 100 / $count ) );
        }
        if ( isset( self::SECTION_PRESETS[ $structure ] ) && count( self::SECTION_PRESETS[ $structure ] ) === $count ) {
            return self::SECTION_PRESETS[ $structure ];
        }
        $keys = array( $count . '0' );
        foreach ( self::SECTION_PRESETS as $key => $widths ) {
            if ( count( $widths ) === $count ) {
                $keys[] = (string) $key;
            }
        }
        return sprintf( '`structure` "%s" is not one of Elementor\'s presets for %d columns: %s.', $structure, $count, implode( ', ', $keys ) );
    }

    private static function kind_of( string $type, ?array $parent ): string {
        if ( 'section' === $type ) {
            return null !== $parent && 'column' === self::type_of( $parent ) ? 'inner-section' : 'section';
        }
        return in_array( $type, array( 'container', 'column' ), true ) ? $type : 'widget';
    }

    private function resize_sections( array $before, array $after ) {
        $old = self::section_columns( $before );
        foreach ( self::section_columns( $after ) as $id => $columns ) {
            if ( ! isset( $old[ $id ] ) ) {
                continue;
            }
            $was = $old[ $id ];
            $now = $columns;
            sort( $was );
            sort( $now );
            if ( $was === $now ) {
                continue;
            }
            if ( count( $columns ) > self::MAX_COLUMNS && count( $columns ) > count( $old[ $id ] ) ) {
                return sprintf( 'section "%s" would hold %d columns; Elementor\'s editor allows at most %d.', $id, count( $columns ), self::MAX_COLUMNS );
            }
            $resets = array_intersect( $columns, $this->widths_written );
            if ( array() !== $resets ) {
                return sprintf( 'this resizes section "%s", which resets the column width this call sets on %s; set column widths after adding, removing or moving columns.', $id, implode( ', ', array_map( array( __CLASS__, 'quoted' ), $resets ) ) );
            }
            $path    = Elementor_Tree::path( $after, (string) $id );
            $section = Elementor_Tree::at( $after, $path );
            $count   = count( $section['elements'] );
            $width   = (int) floor( 100 / $count );
            if ( '10' === $count . '0' ) {
                unset( $section['settings']['structure'] );
            } else {
                $section['settings']['structure'] = $count . '0';
            }
            foreach ( $section['elements'] as $i => $column ) {
                $column['settings']['_column_size'] = $width;
                unset( $column['settings']['_inline_size'] );
                $column['isInner']         = ! empty( $section['isInner'] );
                $section['elements'][ $i ] = $column;
            }
            $after         = Elementor_Tree::replace( $after, $path, $section );
            $this->notes[] = sprintf( 'Section "%s" now has %d column%s, each %d%% wide, as Elementor\'s editor sizes them when a column is added, removed or moved; any custom column widths in it were reset.', $id, $count, 1 === $count ? '' : 's', $width );
        }
        return $after;
    }

    private static function section_columns( array $data ): array {
        $sections = array();
        foreach ( Elementor_Tree::elements( $data ) as $element ) {
            if ( 'section' === ( $element['elType'] ?? '' ) && isset( $element['id'] ) && is_scalar( $element['id'] ) ) {
                $sections[ (string) $element['id'] ] = array_map(
                    static function ( $column ): string {
                        return is_array( $column ) && isset( $column['id'] ) && is_scalar( $column['id'] ) ? (string) $column['id'] : '';
                    },
                    isset( $element['elements'] ) && is_array( $element['elements'] ) ? array_values( $element['elements'] ) : array()
                );
            }
        }
        return $sections;
    }

    private static function in_inner_section( array $data, array $path ): bool {
        if ( count( $path ) < 2 ) {
            return false;
        }
        $column  = Elementor_Tree::at( $data, $path );
        $section = Elementor_Tree::at( $data, array_slice( $path, 0, -1 ) );
        return 'column' === ( $column['elType'] ?? '' ) && ! empty( $section['isInner'] );
    }

    private function restructure( string $name, array $data, array $op, array $path, array $element, string $type, string $address ) {
        if ( 0 === strpos( $type, 'e-' ) || self::has_transformables( $element['settings'] ) ) {
            return $this->v4_refusal( sprintf( 'element "%s" is an Elementor V4 element', $address ), 'changes' );
        }
        $parent_path = array_slice( $path, 0, -1 );
        $parent      = array() === $parent_path ? null : Elementor_Tree::at( $data, $parent_path );
        if ( null !== $parent && 'widget' === ( $parent['elType'] ?? '' ) ) {
            return sprintf( 'element "%s" is the content of one item of "%s" (%s), matched to it by position; Elementor\'s editor moves, copies and removes the two together. Do that in Elementor.', $address, (string) $parent['id'], self::type_of( $parent ) );
        }
        $siblings    = Elementor_Tree::children( $data, $parent_path );
        $index       = (int) array_search( end( $path ), array_keys( array() === $parent_path ? $data : $parent['elements'] ), true );
        $before      = self::position( $parent, $index );
        $result      = array(
            'op'      => $name,
            'element' => $address,
            'type'    => $type,
            'field'   => '',
        );

        $kind = 'section' === $type ? ( empty( $element['isInner'] ) ? 'section' : 'inner-section' ) : self::kind_of( $type, null );
        if ( 'duplicate' === $name ) {
            return $this->duplicate( $data, $op, $element, $kind, $result, self::position( $parent, $index + 1 ) + array( 'path' => $parent_path ) );
        }

        array_splice( $siblings, $index, 1 );
        $without = Elementor_Tree::with_children( $data, $parent_path, $siblings );
        if ( 'remove' === $name ) {
            if ( 'column' === $type && array() === $siblings ) {
                return sprintf( 'element "%s" is the only column of section "%s"; a section needs one. Remove the section instead.', $address, (string) $parent['id'] );
            }
            if ( array() === $without ) {
                return sprintf( 'element "%s" is the last one at the top of the layout; with no elements Elementor shows the post content instead. Insert its replacement first.', $address );
            }
            return array(
                'data'   => $without,
                'result' => $result + array(
                    'before'  => $before,
                    'after'   => null,
                    'changed' => true,
                ),
            );
        }

        foreach ( array( 'parent', 'after' ) as $key ) {
            $target = $this->resolve( $op, $key );
            if ( '' !== $target && ( $target === $address || null !== Elementor_Tree::path( $element['elements'], $target ) ) ) {
                return sprintf( 'element "%s" cannot be moved inside itself.', $address );
            }
        }
        if ( '' === $this->resolve( $op, 'parent' ) && '' === $this->resolve( $op, 'after' ) && ! isset( $op['index'] ) ) {
            return 'move needs `parent` (with an optional `index`), `after`, or `index` alone for the top level.';
        }
        $place = $this->place( $without, $op );
        if ( is_string( $place ) ) {
            return $place;
        }
        $misplaced = $this->placement_refusal( $kind, $without, $place, $element );
        if ( null !== $misplaced ) {
            return sprintf( 'a %s cannot go %s.', $type, $misplaced );
        }
        if ( 'column' === $type && array() === $siblings && 'section' === self::type_of( (array) $parent ) ) {
            return sprintf( 'element "%s" is the only column of section "%s"; a section needs one. Move its contents instead.', $address, (string) $parent['id'] );
        }
        if ( 'container' === $kind ) {
            $element['isInner'] = null !== $place['parent'];
        }
        $children = Elementor_Tree::children( $without, $place['path'] );
        array_splice( $children, $place['index'], 0, array( $element ) );
        $after = self::position( $place['parent'], $place['index'] );
        return array(
            'data'   => Elementor_Tree::with_children( $without, $place['path'], $children ),
            'result' => $result + array(
                'before'  => $before,
                'after'   => $after,
                'changed' => $before !== $after,
            ),
        );
    }

    private function duplicate( array $data, array $op, array $element, string $kind, array $result, array $next ) {
        $placed = '' !== $this->resolve( $op, 'parent' ) || '' !== $this->resolve( $op, 'after' ) || isset( $op['index'] );
        $place  = $placed ? $this->place( $data, $op ) : array(
            'path'   => $next['path'],
            'parent' => array() === $next['path'] ? null : Elementor_Tree::at( $data, $next['path'] ),
            'index'  => $next['index'],
        );
        if ( is_string( $place ) ) {
            return $place;
        }
        $misplaced = $this->placement_refusal( $kind, $data, $place, $element );
        if ( null !== $misplaced ) {
            return sprintf( 'a %s cannot go %s.', $result['type'], $misplaced );
        }
        $size = count( Elementor_Tree::elements( array( $element ) ) );
        if ( $this->created + $size > self::MAX_CREATED ) {
            return self::too_many();
        }
        $ref = self::text_arg( $op, 'ref' );
        if ( '' !== $ref && ( isset( $this->refs[ $ref ] ) || isset( $this->taken[ $ref ] ) ) ) {
            return sprintf( '`ref` "%s" is already used in this layout or this call; give each new element its own.', $ref );
        }
        $this->created += $size;
        $created        = array();
        $copy           = $this->copied( $element, $created );
        if ( 'container' === $kind ) {
            $copy['isInner'] = null !== $place['parent'];
        }
        if ( '' !== $ref ) {
            $this->refs[ $ref ] = (string) $copy['id'];
            $result['ref']      = $ref;
        }
        $children = Elementor_Tree::children( $data, $place['path'] );
        array_splice( $children, $place['index'], 0, array( $copy ) );
        return array(
            'data'   => Elementor_Tree::with_children( $data, $place['path'], $children ),
            'result' => $result + array(
                'before'  => null,
                'after'   => self::position( $place['parent'], $place['index'] ),
                'changed' => true,
                'copy'    => (string) $copy['id'],
                'created' => $created,
            ),
        );
    }

    private function copied( array $element, array &$created ): array {
        $element['id'] = Elementor_Tree::new_id( $this->taken );
        $created[]     = $element['id'];
        $children      = array();
        foreach ( isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array() as $child ) {
            $children[] = is_array( $child ) ? $this->copied( $child, $created ) : $child;
        }
        $element['elements'] = $children;
        return $element;
    }

    private function place( array $data, array $op ) {
        $parent_id = $this->resolve( $op, 'parent' );
        $after_id  = $this->resolve( $op, 'after' );
        if ( '' !== $parent_id && '' !== $after_id ) {
            return 'give `parent` (with an optional `index`) or `after`, not both.';
        }
        if ( '' !== $after_id ) {
            if ( isset( $op['index'] ) ) {
                return 'give `after` or `index`, not both.';
            }
            $after = Elementor_Tree::path( $data, $after_id );
            if ( null === $after ) {
                return $this->no_element( $data, $after_id, ' to place it after.' );
            }
            $path   = array_slice( $after, 0, -1 );
            $parent = array() === $path ? null : Elementor_Tree::at( $data, $path );
            $keys   = array_keys( null === $parent ? $data : $parent['elements'] );
            return array(
                'path'   => $path,
                'parent' => $parent,
                'index'  => (int) array_search( end( $after ), $keys, true ) + 1,
            );
        }
        $path = array();
        if ( '' !== $parent_id ) {
            $path = Elementor_Tree::path( $data, $parent_id );
            if ( null === $path ) {
                return $this->no_element( $data, $parent_id, ' to place it in.' );
            }
        }
        $count = count( Elementor_Tree::children( $data, $path ) );
        $index = self::index_arg( $op, $count );
        if ( is_string( $index ) ) {
            return $index;
        }
        return array(
            'path'   => $path,
            'parent' => array() === $path ? null : Elementor_Tree::at( $data, $path ),
            'index'  => $index ?? $count,
        );
    }

    private function is_nested( array $element, string $type ): bool {
        return ( $this->nested )( $type ) || ( isset( $element['elements'] ) && is_array( $element['elements'] ) && array() !== $element['elements'] );
    }

    private static function too_many(): string {
        return sprintf( 'At most %d elements can be created in one call, inserted or copied, nested ones included.', self::MAX_CREATED );
    }

    private static function misplaced( string $kind, ?array $parent ): ?string {
        if ( null === $parent ) {
            if ( 'widget' === $kind ) {
                return 'at the top level of the layout; put it in a container or a column';
            }
            if ( 'column' === $kind ) {
                return 'at the top level of the layout; put it in a section';
            }
            return 'inner-section' === $kind ? 'at the top level of the layout: an inner section stays in a column' : null;
        }
        $type = self::type_of( $parent );
        $id   = isset( $parent['id'] ) && is_scalar( $parent['id'] ) ? (string) $parent['id'] : '';
        if ( 0 === strpos( $type, 'e-' ) || self::has_transformables( $parent['settings'] ?? array() ) ) {
            return sprintf( 'in "%s", an Elementor V4 element', $id );
        }
        $accepts = array(
            'container' => array( 'container', 'widget' ),
            'column'    => array( 'widget', 'inner-section' ),
            'section'   => array( 'column' ),
        );
        if ( in_array( $kind, $accepts[ $type ] ?? array(), true ) ) {
            return null;
        }
        if ( 'section' === $kind ) {
            return 'anywhere but the top level of the layout';
        }
        return sprintf( 'in "%s", a %s', $id, $type );
    }

    private function placement_refusal( string $kind, array $data, array $place, ?array $element = null ): ?string {
        $misplaced = self::misplaced( $kind, $place['parent'] );
        if ( null === $misplaced && 'inner-section' === $kind && self::in_inner_section( $data, $place['path'] ) ) {
            return 'in a column of an inner section; Elementor nests sections one level only';
        }
        if ( null === $misplaced && 'column' === $kind && self::is_inner_section( $place['parent'] ) && null !== $element && self::holds_section( $element ) ) {
            return 'in an inner section while it holds a section; Elementor nests sections one level only';
        }
        return $misplaced;
    }

    private static function is_inner_section( ?array $element ): bool {
        return null !== $element && 'section' === ( $element['elType'] ?? '' ) && ! empty( $element['isInner'] );
    }

    private static function holds_section( array $element ): bool {
        foreach ( Elementor_Tree::elements( isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array() ) as $inside ) {
            if ( 'section' === ( $inside['elType'] ?? '' ) ) {
                return true;
            }
        }
        return false;
    }

    private static function position( ?array $parent, int $index ): array {
        return array(
            'parent' => null === $parent ? null : (string) $parent['id'],
            'index'  => $index,
        );
    }

    private function resolve( array $op, string $key ): string {
        $value = self::text_arg( $op, $key );
        return $this->refs[ $value ] ?? $value;
    }

    private static function text_arg( array $op, string $key ): string {
        return isset( $op[ $key ] ) && is_scalar( $op[ $key ] ) && ! is_bool( $op[ $key ] ) ? trim( (string) $op[ $key ] ) : '';
    }

    private static function index_arg( array $op, int $max ) {
        if ( ! isset( $op['index'] ) ) {
            return null;
        }
        $value = $op['index'];
        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
            if ( (int) $value >= 0 && (int) $value <= $max ) {
                return (int) $value;
            }
        }
        return sprintf( '`index` must be a whole number from 0 to %d.', $max );
    }

    private function v4_refusal( string $subject, string $verb ): string {
        $refusal     = sprintf( '%s; Elementor\'s own manage-elements ability (wp_ability_elementor_manage_elements) %s those.', $subject, $verb );
        $unavailable = ( $this->v4_unavailable )();
        if ( array() === $unavailable ) {
            return $refusal;
        }
        return sprintf( '%s That tool cannot be called now: %s.', $refusal, implode( '; ', $unavailable ) );
    }

    private function element_refusal( array $element, string $type, string $address ): ?string {
        $settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
        if ( 0 === strpos( $type, 'e-' ) || self::has_transformables( $settings ) ) {
            return $this->v4_refusal( sprintf( 'element "%s" is an Elementor V4 element', $address ), 'edits' );
        }
        if ( 'widget' !== ( $element['elType'] ?? '' ) && ! self::is_structure( $type ) ) {
            return sprintf( 'element "%s" is a %s; only widgets, containers, sections and columns have fields these operations write.', $address, $type );
        }
        return null;
    }

    private function single_change( string $name, array $op, array $fields, array $settings, string $roles_type, string $owner, string $hint ) {
        $writes = array(
            'set_text'  => array( 'text', self::TEXT_KINDS ),
            'set_link'  => array( 'link', array( 'link' ) ),
            'set_image' => array( 'image', array( 'image' ) ),
        );
        list( $role, $kinds ) = $writes[ $name ];

        if ( isset( $op['field'] ) && is_string( $op['field'] ) && '' !== $op['field'] ) {
            $field = $op['field'];
        } else {
            $candidates = self::default_fields( $roles_type, $role, $kinds, self::primary_fields( $fields, $roles_type ) );
            if ( array() === $candidates ) {
                return sprintf( '%s have no %s field; give `field`%s.', $owner, $role, $hint );
            }
            if ( count( $candidates ) > 1 && '' !== $roles_type ) {
                return self::ambiguous_field( $owner, $role, $candidates, 'give `field`, one of them' . $hint );
            }
            $field = $candidates[0];
        }
        $spec = self::field_spec( $fields, $field );
        if ( null === $spec ) {
            return sprintf( '%s have no field "%s"%s.', $owner, $field, $hint );
        }
        if ( ! in_array( $spec['kind'], $kinds, true ) ) {
            return sprintf( 'field "%s" holds %s, which %s does not write%s.', $field, $spec['kind'], $name, $this->writer_hint( $spec['kind'], $field ) );
        }

        if ( 'set_text' === $name ) {
            if ( ! isset( $op['value'] ) || ! is_string( $op['value'] ) ) {
                return 'set_text needs `value`, a string.';
            }
            return $this->text_value( $spec, $op['value'] );
        }
        if ( 'set_link' === $name ) {
            return $this->link_value( $op, $settings[ $field ] ?? null, $field );
        }
        return $this->image_value( $op, $settings[ $field ] ?? null, $field );
    }

    private function replaced_change( array $op, array $fields, array $settings, string $roles_type, string $owner, string $hint, string $where ) {
        $args = Text_Replacement::arguments( $op );
        if ( is_string( $args ) ) {
            return $args;
        }
        if ( isset( $op['field'] ) && is_string( $op['field'] ) && '' !== $op['field'] ) {
            $spec = self::field_spec( $fields, $op['field'] );
            if ( null === $spec ) {
                return sprintf( '%s have no field "%s"%s.', $owner, $op['field'], $hint );
            }
            if ( ! in_array( $spec['kind'], self::REPLACE_KINDS, true ) ) {
                return sprintf( 'field "%s" holds %s, which replace_text does not write%s.', $op['field'], $spec['kind'], $this->writer_hint( $spec['kind'], $op['field'] ) );
            }
        } else {
            $candidates = array();
            $values     = array();
            foreach ( self::primary_fields( $fields, $roles_type ) as $candidate ) {
                if ( in_array( $candidate['kind'], self::REPLACE_KINDS, true ) ) {
                    $candidates[ $candidate['name'] ] = $candidate;
                    $values[ $candidate['name'] ]     = self::stored_text( $settings, $candidate['name'] );
                }
            }
            if ( array() === $candidates ) {
                return sprintf( '%s have no text, HTML or code field on their first tab; give `field`%s.', $owner, $hint );
            }
            $holder = Text_Replacement::holder( $values, $args['search'], $where );
            if ( is_string( $holder ) ) {
                return $holder;
            }
            $spec = $candidates[ $holder['field'] ];
        }
        $replaced = Text_Replacement::replaced( self::stored_text( $settings, $spec['name'] ), $args, $spec['name'], $where );
        return is_string( $replaced ) ? $replaced : $this->text_value( $spec, $replaced['value'] );
    }

    private static function stored_text( array $settings, string $field ): string {
        return isset( $settings[ $field ] ) && is_string( $settings[ $field ] ) ? $settings[ $field ] : '';
    }

    private function text_value( array $spec, string $text ) {
        $field = $spec['name'];
        if ( ! empty( $spec['css'] ) && self::leaves_css_value( $text ) ) {
            return sprintf( 'field "%s" %s.', $field, self::CSS_VALUE );
        }
        $font = self::font_refusal( $spec, $text );
        if ( null !== $font ) {
            return sprintf( 'field "%s" %s.', $field, $font );
        }
        $value = $this->html_value( $text, $spec['kind'] );
        return is_array( $value ) ? $value['error'] : array( $field => $value );
    }

    private static function ambiguous_field( string $owner, string $role, array $candidates, string $ask ): string {
        return sprintf(
            '%1$s have %2$d %3$s fields (%4$s) and none is known as the main one, so the field is not guessed; %5$s.',
            $owner,
            count( $candidates ),
            $role,
            implode( ', ', array_map( array( __CLASS__, 'quoted' ), $candidates ) ),
            $ask
        );
    }

    private function writer_hint( string $kind, string $field ): string {
        if ( 'code' === $kind && ! $this->unfiltered ) {
            return '; set_fields writes it, but only for an account that may post unfiltered HTML, and this one may not';
        }
        $writers = array(
            'text'  => 'set_text',
            'html'  => 'set_text',
            'link'  => 'set_link',
            'image' => 'set_image',
        );
        if ( isset( $writers[ $kind ] ) ) {
            return '; use ' . $writers[ $kind ];
        }
        return in_array( $kind, self::FIELD_KINDS, true ) ? sprintf( '; use set_fields with fields: { "%s": … }', $field ) : '';
    }

    private function field_changes( array $op, array $fields, string $owner, string $hint, array $stored ) {
        if ( ! isset( $op['fields'] ) || ! is_array( $op['fields'] ) || array() === $op['fields'] ) {
            return 'set_fields needs `fields`, an object of field name to value.';
        }
        return $this->checked_fields( $op['fields'], $fields, $owner, $hint, false, $stored );
    }

    private function checked_fields( array $values, array $fields, string $owner, string $hint, bool $creating, array $stored = array() ) {
        $changes  = array();
        $problems = array();
        foreach ( $values as $field => $value ) {
            $field = (string) $field;
            $spec  = self::field_spec( $fields, $field );
            if ( null === $spec ) {
                $problems[] = sprintf( '%s have no field "%s"', $owner, $field );
                continue;
            }
            if ( $creating && in_array( $spec['kind'], array( 'link', 'image' ), true ) ) {
                $checked = is_array( $value )
                    ? ( 'link' === $spec['kind'] ? $this->link_value( $value, null, $field ) : $this->image_value( $value, null, $field ) )
                    : sprintf( 'takes an object: %s', 'link' === $spec['kind'] ? '{ "url": "…", "new_tab"?: true, "nofollow"?: true }' : '{ "attachment_id": 123 }' );
                if ( is_string( $checked ) ) {
                    $problems[] = sprintf( 'field "%s" %s', $field, lcfirst( rtrim( $checked, '.' ) ) );
                    continue;
                }
                $changes[ $field ] = $checked[ $field ];
                continue;
            }
            $checked = $this->field_value( $spec, $value );
            if ( is_array( $checked ) && isset( $checked['error'] ) ) {
                $problems[] = sprintf( 'field "%s" %s', $field, $checked['error'] );
                continue;
            }
            $changes[ $field ] = $checked;
        }
        if ( array() !== $problems ) {
            return implode( '; ', $problems ) . $hint . '.';
        }
        return self::with_stored_sides( $changes, $fields, $stored );
    }

    private static function hint( string $type ): string {
        return \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_list_layout_elements' ) ? sprintf( ' (see wp_list_layout_elements with type "%s")', $type ) : '';
    }

    private static function item_hint( string $type, string $list ): string {
        return \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_list_layout_elements' ) ? sprintf( ' (see `item_fields` of "%s" in wp_list_layout_elements with type "%s")', $list, $type ) : '';
    }

    private function field_value( array $spec, $value ) {
        $kind = $spec['kind'];
        if ( ! in_array( $kind, self::FIELD_KINDS, true ) ) {
            $hint = array(
                'link'  => '; use set_link',
                'image' => '; use set_image',
                'list'  => '; use add_item, remove_item and move_item, or `list` and `item` on the set operations',
            );
            return array( 'error' => sprintf( 'holds %s, which set_fields does not write%s', $kind, $hint[ $kind ] ?? '' ) );
        }
        if ( 'select' === $kind ) {
            $options = $spec['options'] ?? array();
            if ( ! empty( $spec['multiple'] ) ) {
                return self::choices_value( $value, $options );
            }
            if ( ! self::is_option( $value, $options ) ) {
                return array( 'error' => sprintf( 'must be one of: %s', implode( ', ', array_map( array( __CLASS__, 'quoted' ), $options ) ) ) );
            }
            if ( array() === $options && ! empty( $spec['css'] ) && self::leaves_css_value( (string) $value ) ) {
                return array( 'error' => self::CSS_VALUE );
            }
            return (string) $value;
        }
        if ( 'number' === $kind ) {
            $number = is_numeric( $value ) && ! is_bool( $value ) ? $value + 0 : null;
            return is_int( $number ) || ( is_float( $number ) && is_finite( $number ) ) ? $number : array( 'error' => 'must be a finite number' );
        }
        if ( 'boolean' === $kind ) {
            if ( ! is_bool( $value ) ) {
                return array( 'error' => 'must be true or false' );
            }
            return $value ? ( $spec['on_value'] ?? 'yes' ) : '';
        }
        if ( 'size' === $kind ) {
            return self::size_value( $spec, $value );
        }
        if ( 'gallery' === $kind ) {
            return $this->gallery_value( $value );
        }
        $font = self::font_refusal( $spec, $value );
        if ( null !== $font ) {
            return array( 'error' => $font );
        }
        if ( isset( self::SIDES[ $kind ] ) ) {
            return self::sides_value( $spec, $value );
        }
        if ( 'color' === $kind ) {
            $valid = is_string( $value ) && 1 === preg_match( '/^(?:#[0-9a-fA-F]{3,8}|rgba?\([0-9.,%\s]+\)|hsla?\([0-9.,%\s]+\)|)$/', $value );
            return $valid ? $value : array( 'error' => 'must be a colour such as #1a2b3c, or "" to clear it' );
        }
        if ( ! is_string( $value ) ) {
            return array( 'error' => 'must be a string' );
        }
        if ( ! empty( $spec['css'] ) && self::leaves_css_value( $value ) ) {
            return array( 'error' => self::CSS_VALUE );
        }
        $checked = $this->html_value( $value, $kind );
        return is_array( $checked ) ? array( 'error' => lcfirst( $checked['error'] ) ) : $checked;
    }

    private static function font_refusal( array $spec, $value ): ?string {
        if ( 'font' !== ( $spec['control'] ?? '' ) ) {
            return null;
        }
        return is_string( $value ) && 1 === preg_match( '/^[\p{L}\p{N} _\-]*\z/u', $value ) ? null : 'takes a font family name such as "Open Sans" (letters, digits, spaces, - and _), or "" to clear it';
    }

    private static function leaves_css_value( string $value ): bool {
        return 1 === preg_match( '/[;{}<>\\\\]|\/\*|\*\//', $value );
    }

    private function gallery_value( $value ) {
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            return array( 'error' => 'takes a list of image attachment IDs from the media library, such as [12, 15], or [] to empty it' );
        }
        $images = array();
        $seen   = array();
        foreach ( $value as $raw ) {
            $id = is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ? (int) $raw : 0;
            if ( $id <= 0 ) {
                return array( 'error' => sprintf( 'takes image attachment IDs, not %s', is_scalar( $raw ) ? self::quoted( (string) $raw ) : (string) wp_json_encode( $raw ) ) );
            }
            if ( isset( $seen[ $id ] ) ) {
                return array( 'error' => sprintf( 'lists attachment %d twice', $id ) );
            }
            $media = ( $this->media )( $id );
            if ( null === $media ) {
                return array( 'error' => sprintf( 'holds attachment %d, which is not an image in the media library that this account can use', $id ) );
            }
            $seen[ $id ] = true;
            $images[]    = array(
                'id'  => (int) $media['id'],
                'url' => (string) $media['url'],
            );
        }
        return $images;
    }

    private static function size_value( array $spec, $value ) {
        $units = $spec['units'] ?? array( 'px' );
        if ( is_int( $value ) || is_float( $value ) || '' === $value ) {
            $value = array( 'size' => $value );
        }
        if ( ! is_array( $value ) || ! array_key_exists( 'size', $value ) ) {
            return array( 'error' => sprintf( 'takes a number, or { "size": 24, "unit": one of %s }', implode( ', ', array_map( array( __CLASS__, 'quoted' ), $units ) ) ) );
        }
        $unit = self::unit_of( $value, $units );
        if ( is_array( $unit ) ) {
            return $unit;
        }
        $size = self::length( $value['size'], $unit );
        return is_array( $size ) ? $size : array( 'unit' => $unit, 'size' => $size, 'sizes' => array() );
    }

    private static function sides_value( array $spec, $value ) {
        $sides = self::SIDES[ $spec['kind'] ];
        $units = $spec['units'] ?? array( 'px' );
        $keys = array_merge( $sides, array( 'unit', 'isLinked' ) );
        if ( ! is_array( $value ) || array() === $value ) {
            return array( 'error' => sprintf( 'takes an object of %s, each a number or "" (unit: one of %s)', implode( ', ', $sides ), implode( ', ', array_map( array( __CLASS__, 'quoted' ), $units ) ) ) );
        }
        $unknown = array_diff( array_map( 'strval', array_keys( $value ) ), $keys );
        if ( array() !== $unknown ) {
            return array( 'error' => sprintf( 'has no side %s; its sides are %s', implode( ', ', array_map( array( __CLASS__, 'quoted' ), $unknown ) ), implode( ', ', $sides ) ) );
        }
        $out = array();
        if ( array_key_exists( 'unit', $value ) ) {
            $unit = self::unit_of( $value, $units );
            if ( is_array( $unit ) ) {
                return $unit;
            }
            $out['unit'] = $unit;
        }
        foreach ( $sides as $side ) {
            if ( array_key_exists( $side, $value ) ) {
                $out[ $side ] = $value[ $side ];
            }
        }
        if ( array_key_exists( 'isLinked', $value ) ) {
            if ( ! is_bool( $value['isLinked'] ) ) {
                return array( 'error' => '"isLinked" must be true or false' );
            }
            $out['isLinked'] = $value['isLinked'];
        }
        return $out;
    }

    private static function unit_of( array $value, array $units ) {
        if ( ! array_key_exists( 'unit', $value ) ) {
            return $units[0];
        }
        return is_string( $value['unit'] ) && in_array( $value['unit'], $units, true ) ? $value['unit'] : array( 'error' => sprintf( 'takes a unit of %s', implode( ', ', array_map( array( __CLASS__, 'quoted' ), $units ) ) ) );
    }

    private static function length( $length, string $unit ) {
        if ( '' === $length ) {
            return '';
        }
        if ( 'custom' === $unit ) {
            return is_string( $length ) && ! self::leaves_css_value( $length ) ? $length : array( 'error' => 'with the custom unit takes a CSS value such as "calc(100% - 2rem)", without ; { } < > \\ or a comment' );
        }
        $number = is_numeric( $length ) && ! is_bool( $length ) ? $length + 0 : null;
        return is_int( $number ) || ( is_float( $number ) && is_finite( $number ) ) ? $number : array( 'error' => 'must be a finite number, or "" to clear it' );
    }

    private static function choices_value( $value, array $options ) {
        $choices = is_array( $value ) ? $value : array( $value );
        $valid   = array_values( $choices ) === $choices;
        foreach ( $choices as $choice ) {
            $valid = $valid && self::is_option( $choice, $options );
        }
        if ( ! $valid ) {
            $each = array() === $options ? '' : ', each one of: ' . implode( ', ', array_map( array( __CLASS__, 'quoted' ), $options ) );
            return array( 'error' => 'takes a list of options' . $each );
        }
        return array_values( array_unique( array_map( 'strval', $choices ) ) );
    }

    private static function is_option( $value, array $options ): bool {
        return is_scalar( $value ) && ! is_bool( $value ) && ( array() === $options || in_array( (string) $value, $options, true ) );
    }

    private function html_value( string $value, string $kind ) {
        if ( $this->unfiltered ) {
            return $value;
        }
        if ( 'code' === $kind ) {
            return array( 'error' => 'Holds code (HTML or script), which only accounts that may post unfiltered HTML can change.' );
        }
        $clean = (string) ( $this->kses )( $value );
        if ( ! self::same_text( $clean, $value ) ) {
            return array( 'error' => 'Holds HTML this account cannot save (it may not post unfiltered HTML), so WordPress would cut part of it. Send it without scripts, styles, iframes or event attributes.' );
        }
        return $clean;
    }

    private static function same_text( string $clean, string $original ): bool {
        return html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === html_entity_decode( $original, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    private function link_value( array $op, $current, string $field ) {
        if ( ! isset( $op['url'] ) || ! is_string( $op['url'] ) ) {
            return 'set_link needs `url`, a string ("" removes the link).';
        }
        $asked = trim( $op['url'] );
        $url   = '' === $asked ? '' : esc_url_raw( $asked, wp_allowed_protocols() );
        if ( '' !== $asked && '' === $url ) {
            return sprintf( '"%s" is not a URL WordPress accepts.', $asked );
        }
        $link        = is_array( $current ) ? $current : array();
        $link['url'] = $url;
        foreach ( array( 'new_tab' => 'is_external', 'nofollow' => 'nofollow' ) as $arg => $key ) {
            if ( isset( $op[ $arg ] ) ) {
                $link[ $key ] = $op[ $arg ] ? 'on' : '';
            } elseif ( ! isset( $link[ $key ] ) ) {
                $link[ $key ] = '';
            }
        }
        return array( $field => $link );
    }

    private function image_value( array $op, $current, string $field ) {
        $id = isset( $op['attachment_id'] ) && is_numeric( $op['attachment_id'] ) ? (int) $op['attachment_id'] : 0;
        if ( $id <= 0 ) {
            return 'set_image needs `attachment_id`, the ID of an image in the media library' . ( \Easy_MCP_AI\Tools\Post_Type_Tool_Hint::may_reach( 'wp_list_media' ) ? ' (wp_list_media, or upload one first).' : ' (upload one first if it is not there).' );
        }
        $media = ( $this->media )( $id );
        if ( null === $media ) {
            return sprintf( 'attachment %d is not an image in the media library that this account can use.', $id );
        }
        $image = is_array( $current ) ? $current : array();
        unset( $image['alt'] );
        $image['id']     = $media['id'];
        $image['url']    = $media['url'];
        $image['source'] = 'library';
        return array( $field => $image );
    }

    private static function bound_fields( array $settings, array $names ): array {
        $bound = array();
        foreach ( $names as $name ) {
            foreach ( array( self::DYNAMIC_KEY, self::GLOBALS_KEY ) as $key ) {
                if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) && ! empty( $settings[ $key ][ $name ] ) ) {
                    $bound[] = '"' . $name . '"';
                    break;
                }
            }
        }
        return $bound;
    }

    private static function bound_by_group( array $settings, array $names, array $fields ): array {
        $globals = isset( $settings[ self::GLOBALS_KEY ] ) && is_array( $settings[ self::GLOBALS_KEY ] ) ? $settings[ self::GLOBALS_KEY ] : array();
        $bound   = array();
        foreach ( $names as $name ) {
            $spec = self::field_spec( $fields, (string) $name );
            if ( null !== $spec && 'typography' === ( $spec['group'] ?? '' ) && ! empty( $globals[ ( $spec['group_prefix'] ?? '' ) . 'typography' ] ) ) {
                $bound[] = '"' . $name . '"';
            }
        }
        return $bound;
    }

    private static function default_fields( string $type, string $role, array $kinds, array $fields ): array {
        $roles = Elementor_Adapter::ROLES[ $type ] ?? array();
        if ( isset( $roles[ $role ] ) ) {
            return array( $roles[ $role ] );
        }
        $names = array();
        foreach ( $fields as $spec ) {
            if ( in_array( $spec['kind'], $kinds, true ) ) {
                $names[] = $spec['name'];
            }
        }
        return $names;
    }

    private static function field_spec( array $fields, string $name ): ?array {
        foreach ( $fields as $spec ) {
            if ( $spec['name'] === $name ) {
                return $spec;
            }
        }
        foreach ( $fields as $spec ) {
            foreach ( $spec['devices'] ?? array() as $device ) {
                if ( $spec['name'] . '_' . $device === $name ) {
                    unset( $spec['default'], $spec['devices'] );
                    $spec['name'] = $name;
                    return $spec;
                }
            }
        }
        return null;
    }

    private static function with_stored_sides( array $changes, array $fields, array $stored ) {
        foreach ( $changes as $name => $value ) {
            $spec = self::field_spec( $fields, (string) $name );
            if ( null === $spec || ! isset( self::SIDES[ $spec['kind'] ] ) || ! is_array( $value ) ) {
                continue;
            }
            $sides  = self::SIDES[ $spec['kind'] ];
            $base   = isset( $stored[ $name ] ) && is_array( $stored[ $name ] ) ? $stored[ $name ] : array();
            $merged = array( 'unit' => $value['unit'] ?? ( isset( $base['unit'] ) && is_string( $base['unit'] ) && in_array( $base['unit'], $spec['units'] ?? array( 'px' ), true ) ? $base['unit'] : ( $spec['units'][0] ?? 'px' ) ) );
            foreach ( $sides as $side ) {
                if ( ! array_key_exists( $side, $value ) ) {
                    $merged[ $side ] = isset( $base[ $side ] ) && is_scalar( $base[ $side ] ) ? (string) $base[ $side ] : '';
                    continue;
                }
                $length = self::length( $value[ $side ], $merged['unit'] );
                if ( is_array( $length ) ) {
                    return sprintf( 'field "%s": "%s" %s (unit "%s")', $name, $side, $length['error'], $merged['unit'] );
                }
                $merged[ $side ] = (string) $length;
            }
            $values = array_intersect_key( $merged, array_flip( $sides ) );
            if ( array() !== array_filter( $values, 'strlen' ) ) {
                foreach ( $sides as $side ) {
                    $merged[ $side ] = '' === $merged[ $side ] ? '0' : $merged[ $side ];
                }
            }
            $merged['isLinked'] = $value['isLinked'] ?? 1 === count( array_unique( array_intersect_key( $merged, array_flip( $sides ) ) ) );
            $changes[ $name ]   = $merged;
        }
        return $changes;
    }

    private static function primary_fields( array $fields, string $type ): array {
        $tab = Elementor_Adapter::primary_tab( $type );
        return array_values(
            array_filter(
                $fields,
                static function ( array $spec ) use ( $tab ): bool {
                    return ! isset( $spec['tab'] ) || $tab === $spec['tab'];
                }
            )
        );
    }

    private static function fields_or_none( ?array $fields, string $type ): ?array {
        return null === $fields && self::is_structure( $type ) ? array() : $fields;
    }

    private static function is_structure( string $type ): bool {
        return in_array( $type, self::CONTAINERS, true );
    }

    private static function owner( string $type ): string {
        return $type . ( self::is_structure( $type ) ? ' elements' : ' widgets' );
    }

    private static function css_id_refusal( array $changes ): ?string {
        if ( ! isset( $changes['_element_id'] ) || ! is_string( $changes['_element_id'] ) ) {
            return null;
        }
        $id = trim( $changes['_element_id'] );
        if ( '#' === substr( $id, 0, 1 ) ) {
            return sprintf( 'the CSS ID %s starts with "#"; send it without the "#", as Elementor\'s CSS ID field asks.', self::quoted( $id ) );
        }
        if ( 1 === preg_match( '/\s/', $id ) ) {
            return sprintf( 'the CSS ID %s holds white space, which an HTML id cannot; join the words with "-" or "_".', self::quoted( $id ) );
        }
        return null;
    }

    private function hidden_refusal( string $type, array $settings, array $names, string $where ): ?string {
        $hidden = ( $this->hidden )( $type, $settings, array_map( 'strval', $names ) );
        if ( array() === $hidden ) {
            return null;
        }
        $parts = array();
        foreach ( $hidden as $name => $when ) {
            $parts[] = sprintf( 'field "%s" shows %s', $name, $when );
        }
        return sprintf( 'Elementor would not show what this writes on %s: %s. Set those fields first, in the same call.', $where, implode( '; ', $parts ) );
    }

    private function no_element( array $data, string $address, string $tail ): string {
        $holder = $this->item_holder( $data, $address );
        if ( null === $holder ) {
            return sprintf( 'no element "%s" in this layout%s', $address, $tail );
        }
        return sprintf( '"%1$s" is an item of the list "%2$s" of element "%3$s", not an element: address it with `element` "%3$s", `list` "%2$s" and `item` "%1$s".', $address, $holder['list'], $holder['element'] );
    }

    private function item_holder( array $elements, string $id ): ?array {
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
            foreach ( ( $this->fields_of )( self::type_of( $element ) ) ?? array() as $spec ) {
                if ( 'list' !== $spec['kind'] || ! isset( $spec['item_fields'] ) ) {
                    continue;
                }
                $name = $spec['name'];
                foreach ( is_array( $settings[ $name ] ?? null ) ? $settings[ $name ] : array() as $row ) {
                    if ( is_array( $row ) && isset( $row[ self::ITEM_ID ] ) && is_scalar( $row[ self::ITEM_ID ] ) && (string) $row[ self::ITEM_ID ] === $id ) {
                        return array(
                            'element' => isset( $element['id'] ) && is_scalar( $element['id'] ) ? (string) $element['id'] : '',
                            'list'    => (string) $name,
                        );
                    }
                }
            }
            $found = $this->item_holder( is_array( $element['elements'] ?? null ) ? $element['elements'] : array(), $id );
            if ( null !== $found ) {
                return $found;
            }
        }
        return null;
    }

    private static function type_of( array $element ): string {
        $el_type = isset( $element['elType'] ) && is_scalar( $element['elType'] ) ? (string) $element['elType'] : '';
        return 'widget' === $el_type && isset( $element['widgetType'] ) && is_scalar( $element['widgetType'] ) ? (string) $element['widgetType'] : $el_type;
    }

    private static function has_transformables( array $settings ): bool {
        foreach ( $settings as $value ) {
            if ( is_array( $value ) && array_key_exists( '$$type', $value ) ) {
                return true;
            }
        }
        return false;
    }

    private static function quoted( string $value ): string {
        return '"' . $value . '"';
    }
}
