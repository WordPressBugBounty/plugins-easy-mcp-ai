<?php
namespace Easy_MCP_AI\Tools\ACF;

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\Tools\Post_Type_Tool_Hint;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Term_Fields extends Base_Tool {
    use Acf_Rest_Tool;

    public function get_name() {
        return 'wp_acf_update_term_fields';
    }

    public function get_description() {
        $read = Post_Type_Tool_Hint::may_describe( 'wp_acf_get_term_fields' ) ? ' (wp_acf_get_term_fields returns them)' : '';
        return 'Updates one or more ACF field values on a taxonomy term (a category, a tag, a WooCommerce product category or any custom taxonomy). Send `term_id`, `taxonomy` (its name, e.g. "category" or "product_cat", or its REST base, e.g. "categories") and `fields`, an object of field names and values (e.g. {"my_field_name": "value"}); field keys (e.g. field_abc123) also work, and Group, Repeater and Flexible Content values take sub-field names' . $read . '. Fields not sent keep their values; a Repeater or Flexible Content value replaces all its rows. Reaches every field group assigned to the term, including groups with "Show in REST API" off, when you can edit the term. The term itself (name, slug, description) is not saved again, unless a plugin serves the taxonomy\'s REST route with its own controller. Any field that was not saved is listed in `fields_ignored` with a `notice` giving the reason.';
    }

    public function get_category() {
        return 'acf';
    }

    public function get_required_capability() {
        return 'read';
    }

    public function get_annotations() {
        return array( 'title' => $this->get_title(), 'readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'term_id'  => array( 'type' => 'integer', 'description' => 'The ID of the taxonomy term to update ACF fields on.' ),
                'taxonomy' => array( 'type' => 'string', 'description' => 'The term\'s taxonomy: its name (e.g. "category", "post_tag", "product_cat") or its REST base (e.g. "categories", "tags").' ),
                'fields'   => array( 'type' => 'object', 'description' => 'Key-value pairs of ACF field names and their new values. Use field names (e.g. my_field_name); field keys (e.g. field_abc123) also work.' ),
            ),
            'required'   => array( 'term_id', 'taxonomy', 'fields' ),
        );
    }

    public function precheck( array $arguments ): void {
        try {
            $term_id = $this->parse_required_id( $arguments['term_id'] ?? null, 'term_id' );
        } catch ( \InvalidArgumentException $e ) {
            return;
        }
        $this->term_route( $arguments['taxonomy'] ?? null, $term_id );
    }

    public function execute( array $arguments ) {
        $this->require_acf();
        $this->validate_required( $arguments, array( 'term_id', 'taxonomy', 'fields' ) );
        $term_id = $this->parse_required_id( $arguments['term_id'], 'term_id' );
        $target  = $this->term_route( $arguments['taxonomy'], $term_id );
        $fields  = $this->parse_json_param( $arguments['fields'], 'fields' );
        return array( 'term_id' => $term_id, 'taxonomy' => $target['taxonomy'] )
            + $this->acf_write( 'term', $term_id, $target['route'], $fields );
    }

    private function term_route( $taxonomy, int $term_id ): array {
        $value  = $this->validate_rest_route_segment( $taxonomy, 'taxonomy' );
        $object = self::named_taxonomy( $value );
        if ( null === $object ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown taxonomy: %s. Send the taxonomy\'s name (e.g. category) or its REST base (e.g. categories).', $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $this->refuse_non_rest_taxonomy( $object->name, $object );
        $term = get_term( $term_id, $object->name );
        if ( ! is_object( $term ) || is_wp_error( $term ) || ( $term->taxonomy ?? '' ) !== $object->name ) {
            throw new \InvalidArgumentException( sprintf( 'No term with ID %1$d in taxonomy %2$s.', $term_id, $object->name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return array(
            'route'    => (string) rest_get_route_for_term( $term ),
            'taxonomy' => (string) $object->name,
        );
    }

    private static function named_taxonomy( string $value ): ?object {
        $object = get_taxonomy( $value );
        if ( is_object( $object ) ) {
            return $object;
        }
        $all = function_exists( 'get_taxonomies' ) ? get_taxonomies( array(), 'objects' ) : array();
        foreach ( (array) $all as $candidate ) {
            $base = ! empty( $candidate->rest_base ) ? (string) $candidate->rest_base : (string) $candidate->name;
            if ( 0 === strcasecmp( $base, $value ) ) {
                return $candidate;
            }
        }
        return null;
    }
}
