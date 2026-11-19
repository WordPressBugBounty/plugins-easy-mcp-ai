<?php
namespace Easy_MCP_AI\Tools\Builders;

use Easy_MCP_AI\Builders\Builder_Registry;
use Easy_MCP_AI\Builders\Elementor_Adapter;
use Easy_MCP_AI\Builders\Layout_Adapter;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class List_Layout_Elements extends Base_Tool {

    public function get_name() {
        return 'wp_list_layout_elements';
    }

    public function get_description() {
        return 'Lists the element types of a page builder active on this site (Elementor, Breakdance, WPBakery, Flatsome UX Builder, Divi 4 or Divi 5), or the fields of one type: what a layout element can hold before you read or plan an edit. Optional: `builder` (`elementor`, `breakdance`, `wpbakery`, `flatsome`, `divi4` or `divi5`; default: the only one active, required when several are: a Divi 5 site also reads pages still in the Divi 4 format), `type` (an element type such as `heading`, `EssentialElements\\FText`, `vc_btn`, `ux_banner`, `et_pb_button` or `divi/button`; returns its fields), `search` (filters by type or label), `tab` (Elementor only: `content`, `layout`, `style`, `advanced` or `all`; default the type\'s first tab, Content for a widget and Layout for `container`, `section` and `column`). Returns { builder, builder_version, types: [{ type, label, category, atomic?, code? }] } or { builder, type, fields: [{ name, kind, label, tab?, responsive?, devices?, options?, multiple?, default?, condition? }] }; `multiple: true` marks an Elementor select that takes a list of its options. An Elementor field with `devices` also takes one value per device, named `<name>_<device>` (`min_height_tablet`); its `condition` names the other fields Elementor shows it for (`{ "background_background": ["classic"] }`), which a write must meet. Kinds: text, html, link, image, select, number, size, color, boolean, spacing, gap, gallery, list, icon, code, other. An Elementor list of items (a repeater) also has `item_fields` (each item\'s fields, same shape), `min_items` and `max_items` (as many as Elementor\'s editor allows). Elementor, Breakdance, WPBakery and Divi read their own registries (a Breakdance field\'s name is its path in the element, such as `content.content.text`, and `condition` says which other field value Breakdance shows it for); Flatsome\'s list is a map shipped with this plugin, because its builder registry loads only inside UX Builder. A Divi 5 field is named by where Divi stores it, such as `button.innerContent.text`.';
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
                'builder' => array(
                    'type'        => 'string',
                    'description' => 'The builder: "elementor", "breakdance", "wpbakery", "flatsome", "divi4" or "divi5". Default: the only one active on this site.',
                ),
                'type'    => array(
                    'type'        => 'string',
                    'description' => 'An element type, e.g. "heading", "EssentialElements\\FText", "vc_btn", "ux_banner", "et_pb_button" or "divi/button". Returns its fields instead of the list.',
                ),
                'search'  => array(
                    'type'        => 'string',
                    'description' => 'Only types whose name or label contains this text (case-insensitive).',
                ),
                'tab'     => array(
                    'type'        => 'string',
                    'enum'        => array_merge( Elementor_Adapter::TABS, array( 'all' ) ),
                    'description' => 'Elementor only, with `type`: the panel tab whose fields to list, or "all". Default: the type\'s first tab (Content for a widget, Layout for container, section and column).',
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $adapter = $this->adapter( isset( $arguments['builder'] ) ? strtolower( trim( (string) $arguments['builder'] ) ) : '' );
        $type    = isset( $arguments['type'] ) ? trim( (string) $arguments['type'] ) : '';

        if ( '' !== $type ) {
            $fields = $this->fields( $adapter, $type, isset( $arguments['tab'] ) ? (string) $arguments['tab'] : '' );
            if ( null === $fields ) {
                throw new \InvalidArgumentException( sprintf( '%1$s has no element type "%2$s". Call this tool without `type` to list them.', $adapter->label(), $type ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            return array(
                'builder'         => $adapter->id(),
                'builder_version' => $adapter->version(),
                'type'            => $type,
                'fields'          => $fields,
            );
        }

        $types  = $adapter->element_types();
        $search = isset( $arguments['search'] ) ? strtolower( trim( (string) $arguments['search'] ) ) : '';
        if ( '' !== $search ) {
            $types = array_values(
                array_filter(
                    $types,
                    static function ( array $entry ) use ( $search ): bool {
                        return false !== strpos( strtolower( $entry['type'] . ' ' . $entry['label'] ), $search );
                    }
                )
            );
        }
        return array(
            'builder'         => $adapter->id(),
            'builder_version' => $adapter->version(),
            'count'           => count( $types ),
            'types'           => $types,
        );
    }

    private function fields( Layout_Adapter $adapter, string $type, string $tab ): ?array {
        if ( '' === $tab ) {
            return $adapter->element_fields( $type );
        }
        if ( ! $adapter instanceof Elementor_Adapter ) {
            throw new \InvalidArgumentException( sprintf( '`tab` is for Elementor\'s panel tabs; %s fields are listed without it.', $adapter->label() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $tabs = array_merge( Elementor_Adapter::TABS, array( 'all' ) );
        if ( ! in_array( $tab, $tabs, true ) ) {
            throw new \InvalidArgumentException( sprintf( '`tab` must be one of %s, not "%s".', implode( ', ', $tabs ), $tab ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $adapter->tab_fields( $type, $tab );
    }

    private function adapter( string $id ): Layout_Adapter {
        $present = Builder_Registry::present();
        $ids     = array_map(
            static function ( Layout_Adapter $adapter ): string {
                return $adapter->id();
            },
            $present
        );
        if ( '' === $id ) {
            if ( 1 === count( $present ) ) {
                return $present[0];
            }
            if ( array() === $present ) {
                throw new \RuntimeException( 'No supported page builder (Elementor, Breakdance, WPBakery, Flatsome UX Builder or Divi) is active on this site.' );
            }
            throw new \InvalidArgumentException( sprintf( 'Several page builders are active (%s); name one in `builder`.', implode( ', ', $ids ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $adapter = Builder_Registry::get( $id );
        if ( null === $adapter ) {
            throw new \InvalidArgumentException( sprintf( '"%1$s" is not an active page builder here. Active: %2$s.', $id, array() === $ids ? 'none' : implode( ', ', $ids ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $adapter;
    }
}
