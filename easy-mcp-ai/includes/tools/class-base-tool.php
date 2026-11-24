<?php
namespace Easy_MCP_AI\Tools;

use Easy_MCP_AI\Approvals\Preview_Values;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Base_Tool {

    const EXISTING_TITLE_LABEL_LENGTH = 60;

    const EXTERNAL_DATA_CATEGORIES = array( 'ga', 'gsc', 'dfs', 'semrush', 'seranking' );

    const REST_ERROR_WITHOUT_MESSAGE = 1;

    const MSG_OTHER_TAXONOMY_TOOL = 'Use %1$s for that ID, which is a term of taxonomy "%2$s", not "%3$s": term_id %4$d, taxonomy "%2$s".';

    const MSG_OTHER_TAXONOMY = 'ID %1$d is a term of taxonomy "%2$s", not "%3$s", so %4$s cannot %5$s it.';

    const RESPONSE_NOISE_KEYS = array( 'yoast_head', 'yoast_head_json', 'aioseo_head', 'aioseo_head_json', 'aioseo_breadcrumb', 'aioseo_breadcrumb_json', '_links' );

    const RESPONSE_NOISE_PROTECTED_KEYS = array( 'id', 'meta', 'meta_data', 'acf', 'aioseo_meta_data' );

    protected static $already_invalidated = array();

    abstract public function get_name();
    abstract public function get_description();
    abstract public function get_input_schema();
    abstract public function execute( array $arguments );

    public function get_required_capability() {
        return 'manage_options';
    }

    public function get_title() {
        $name = $this->get_name();

        $prefixes = array(
            'wp_wc_'       => 'WooCommerce',
            'wp_ga_'       => 'Google Analytics',
            'wp_gsc_'      => 'Search Console',
            'wp_dfs_'      => 'DataForSEO',
            'wp_semrush_'  => 'SEMrush',
            'wp_ahrefs_'   => 'Ahrefs',
            'wp_acf_'      => 'ACF',
            'wp_aioseo_'   => 'AIOSEO',
            'wp_bp_'       => 'BuddyPress',
            'wp_tec_'      => 'Events Calendar',
            'wp_redirection_' => 'Redirection',
            'wp_sitekit_'  => 'Site Kit',
            'wp_divi_'     => 'Divi',
            'wp_cf7_'      => 'Contact Form 7',
            'wp_smartslider_' => 'Smart Slider',
            'wp_wpforms_'  => 'WPForms',
            'wp_loco_'     => 'Loco Translate',
            'wp_wordfence_' => 'Wordfence',
            'wp_yoast_'    => 'Yoast',
            'wp_rm_'       => 'Rank Math',
            'wp_seranking_' => 'SE Ranking',
            'wp_seopress_' => 'SEOPress',
            'wp_slimseo_'  => 'Slim SEO',
            'wp_tsf_'      => 'The SEO Framework',
            'wp_ability_'  => 'Ability',
        );

        foreach ( $prefixes as $prefix => $label ) {
            if ( strpos( $name, $prefix ) === 0 ) {
                $rest = substr( $name, strlen( $prefix ) );
                return $label . ': ' . ucwords( str_replace( '_', ' ', $rest ) );
            }
        }

        $rest = substr( $name, 3 );
        return ucwords( str_replace( '_', ' ', $rest ) );
    }

    public function get_redacted_arguments() {
        return array();
    }

    public function get_argument_targets( array $arguments ): array {
        return Argument_Targets::of( $this->get_name() );
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'openWorldHint'   => false,
        );
    }

    public function get_category() {
        return 'general';
    }

    public function get_covers(): string {
        return '';
    }

    public function get_match_phrases(): string {
        return '';
    }

    public function is_disabled_by_default() {
        return false;
    }

    public function get_approval_policy( array $arguments ) {
        return 'inherit';
    }

    public function is_composite( array $arguments = array() ) {
        return false;
    }

    public function is_read_only_call( array $arguments ) {
        $annotations = $this->get_annotations();
        return ! empty( $annotations['readOnlyHint'] );
    }

    public function throttle_units( array $arguments ) {
        return 1;
    }

    protected static function count_items( array $arguments, array $keys ) {
        $items = 0;
        foreach ( $keys as $key ) {
            if ( isset( $arguments[ $key ] ) && is_array( $arguments[ $key ] ) ) {
                $items += count( $arguments[ $key ] );
            }
        }
        return max( 1, $items );
    }

    public function redact_for_record( array $arguments ) {
        return $arguments;
    }

    public function describe( array $arguments ) {
        return self::generic_description( $this, $arguments );
    }

    public static function generic_description( object $tool, array $arguments ): string {
        $title = method_exists( $tool, 'get_title' ) ? (string) $tool->get_title() : (string) $tool->get_name();
        if ( empty( $arguments ) ) {
            return $title;
        }
        list( $short, $long ) = Preview_Values::split( self::preview_arguments( $tool, $arguments ) );
        $pairs = array();
        foreach ( $short + $long as $key => $value ) {
            $pairs[] = $key . ': ' . Preview_Values::shown( $value );
        }
        return sprintf( '%s (%s)', $title, implode( ', ', $pairs ) );
    }

    public static function generic_details( object $tool, array $arguments ): array {
        return Preview_Values::long_details( self::preview_arguments( $tool, $arguments ) );
    }

    public static function approval_details_for( object $tool, array $arguments, callable $summary_is_generic ): array {
        $own = null;
        if ( method_exists( $tool, 'approval_details' ) ) {
            try {
                $own = $tool->approval_details( $arguments );
            } catch ( \Throwable $e ) {
                $own = null;
            }
        }
        if ( is_array( $own ) ) {
            return array_values( $own );
        }
        return $summary_is_generic() ? self::generic_details( $tool, $arguments ) : array();
    }

    private static function preview_arguments( object $tool, array $arguments ): array {
        $mask = method_exists( $tool, 'get_redacted_arguments' ) ? (array) $tool->get_redacted_arguments() : array();
        return Preview_Values::mask( $arguments, $mask );
    }

    protected function peek( $route, $fields, array $params = array() ) {
        try {
            $data = Widgets\Sidebars_Widgets_Hold::during(
                function () use ( $route, $params, $fields ) {
                    return $this->rest_request( 'GET', $route, $params, $fields );
                }
            );
        } catch ( \Throwable $e ) {
            return null;
        }
        return is_array( $data ) ? $data : null;
    }

    protected static function rest_title( $data, $key = 'title' ) {
        $value = isset( $data[ $key ] ) ? $data[ $key ] : '';
        if ( is_array( $value ) ) {
            $value = isset( $value['rendered'] ) ? $value['rendered'] : ( isset( $value['raw'] ) ? $value['raw'] : '' );
        }
        $value = trim( \wp_strip_all_tags( (string) $value ) );
        return '' === $value ? __( '(no title)', 'easy-mcp-ai' ) : $value;
    }

    public function get_output_schema() {
        return null;
    }

    public function get_definition() {
        $definition = array(
            'name'        => $this->get_name(),
            'description' => $this->get_description(),
            'inputSchema' => $this->get_input_schema(),
        );
        $output_schema = $this->get_output_schema();
        if ( null !== $output_schema ) {
            $definition['outputSchema'] = $output_schema;
        }
        $annotations = $this->get_annotations();
        if ( ! empty( $annotations ) ) {
            $definition['annotations'] = $annotations;
        }
        $ui = $this->get_ui_meta();
        if ( ! empty( $ui ) ) {
            $definition['_meta'] = array( 'ui' => $ui );
            if ( isset( $ui['resourceUri'] ) ) {
                $definition['_meta']['ui/resourceUri'] = $ui['resourceUri'];
            }
        }
        return $definition;
    }

    public function get_ui_meta() {
        if ( ! \Easy_MCP_AI\Config::get( 'approval_required' ) || ! \Easy_MCP_AI\Approvals\Approval_Gate::is_destructive_by_annotation( $this ) ) {
            return array();
        }
        return array( 'resourceUri' => 'ui://easy-mcp-ai/approval' );
    }

    protected function parse_required_id( $value, string $label = 'ID' ): int {
        if ( is_int( $value ) ) {
            $id = $value;
        } elseif ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
            $id = (int) $value;
        } else {
            throw new \InvalidArgumentException( sprintf( "Invalid %s: must be a positive integer, got: %s", $label, json_encode( $value ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( $id < 1 ) {
            throw new \InvalidArgumentException( sprintf( 'Invalid %s: must be a positive integer.', $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $id;
    }

    protected function normalize_counts( $counts ) {
        $out = array();
        foreach ( (array) $counts as $key => $value ) {
            $out[ (string) $key ] = is_scalar( $value ) ? (int) $value : 0;
        }
        return $out;
    }

    protected static function text_arg( array $arguments, string $key ): string {
        return isset( $arguments[ $key ] ) && is_string( $arguments[ $key ] ) ? trim( $arguments[ $key ] ) : '';
    }

    protected static function markup_arg( array $arguments, string $key ): string {
        if ( ! is_scalar( $arguments[ $key ] ) ) {
            throw new \InvalidArgumentException( sprintf( '%s must be a string.', $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return (string) $arguments[ $key ];
    }

    protected static function boolean_arg( array $arguments, string $key, bool $default, string $nothing_done ): bool {
        if ( ! isset( $arguments[ $key ] ) ) {
            return $default;
        }
        $value = $arguments[ $key ];
        if ( \rest_is_boolean( $value ) ) {
            return \rest_sanitize_boolean( $value );
        }
        $shown = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        throw new \InvalidArgumentException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            \Easy_MCP_AI\MCP\Refusal_Text::fit(
                '%1$s must be true or false, not %2$s.%3$s',
                array( $key, is_string( $shown ) ? $shown : gettype( $value ), '' === $nothing_done ? '' : ' ' . $nothing_done ),
                1
            )
        );
    }

    public function accepted_arguments(): ?array {
        $annotations = $this->get_annotations();
        if ( isset( $annotations['readOnlyHint'] ) && true === $annotations['readOnlyHint'] ) {
            return null;
        }
        $schema = $this->get_input_schema();
        if ( array_key_exists( 'additionalProperties', $schema ) && false !== $schema['additionalProperties'] ) {
            return null;
        }
        return array_map( 'strval', array_keys( (array) ( $schema['properties'] ?? array() ) ) );
    }

    protected function undeclared_arguments( array $arguments ): array {
        $accepted = $this->accepted_arguments();
        if ( null === $accepted ) {
            return array();
        }
        return array_values( array_diff( array_map( 'strval', array_keys( $arguments ) ), $accepted ) );
    }

    public function refuse_undeclared_arguments( array $arguments ): void {
        $undeclared = $this->undeclared_arguments( $arguments );
        if ( array() === $undeclared ) {
            return;
        }
        $shown = array();
        foreach ( array_slice( $undeclared, 0, 3 ) as $name ) {
            $name    = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $name );
            $shown[] = '' === $name ? '?' : ( strlen( $name ) > 20 ? substr( $name, 0, 17 ) . '...' : $name );
        }
        $more     = count( $undeclared ) - count( $shown );
        $accepted = (array) $this->accepted_arguments();
        throw new \Easy_MCP_AI\MCP\Detailed_Tool_Error( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            sprintf(
                'Nothing was changed: %1$s has no argument%2$s %3$s%4$s.',
                $this->get_name(),
                1 === count( $undeclared ) ? '' : 's',
                implode( ', ', $shown ),
                $more > 0 ? sprintf( ' and %d more', $more ) : ''
            ),
            array() === $accepted
                ? 'It takes no arguments. Call it again without them.'
                : 'Its arguments are: ' . implode( ', ', $accepted ) . '. Call it again with only these.'
        );
    }

    protected function validate_required( array $arguments, array $required_keys ) {
        $missing = array();
        foreach ( $required_keys as $key ) {
            if ( ! isset( $arguments[$key] ) || '' === $arguments[$key] ) {
                $missing[] = $key;
            }
        }
        if ( ! empty( $missing ) ) {
            throw new \InvalidArgumentException( sprintf( 'Missing required parameters: %s', implode( ', ', $missing ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    protected function refuse_unless_one_of( string $label, $checked, array $allowed, $shown, string $hint ): void {
        if ( ! in_array( $checked, $allowed, true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Invalid %1$s: %2$s. Use %3$s.', $label, is_scalar( $shown ) ? (string) $shown : gettype( $shown ), $hint ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    protected function parse_json_param( $value, $label = 'parameter' ) {
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }
        throw new \InvalidArgumentException(
            sprintf( '%s must be a valid JSON array or object.', $label ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        );
    }

    protected function validate_title_length( $title ) {
        if ( null === $title ) {
            return;
        }
        $max = (int) \Easy_MCP_AI\Config::get( 'easy_mcp_ai_max_title_length', 0 );
        if ( $max > 0 && mb_strlen( $title ) > $max ) {
            throw new \InvalidArgumentException(
                sprintf( 'Title exceeds maximum allowed length of %d characters.', $max ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }
    }

    protected function maybe_force_draft( array &$params ) {
        if ( \Easy_MCP_AI\Drafts\Draft_Mode::drafts_new() ) {
            $params['status'] = 'draft';
        }
    }

    protected function validate_assignable_roles( array $roles ) {
        foreach ( $roles as $role ) {
            if ( 'administrator' === $role && ! current_user_can( 'manage_options' ) ) {
                throw new \InvalidArgumentException( 'You do not have permission to assign the administrator role.' );
            }
            if ( ! current_user_can( 'promote_users' ) ) {
                throw new \InvalidArgumentException( 'You do not have permission to assign roles.' );
            }
        }
        return $roles;
    }

    protected function validate_rest_route_segment( $value, $label = 'value' ) {
        $value = is_string( $value ) ? trim( $value ) : '';

        if ( '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid %s "%s". Use only letters, numbers, underscores, and hyphens.',
                    $label, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    $value // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                )
            );
        }

        return $value;
    }

    protected function resolve_post_type_rest_base( $value, string $label ): string {
        $value = $this->validate_rest_route_segment( $value, $label );
        $base = Post_Type_Rest_Base::resolve( $value );
        Post_Type_Rest_Base::require_served_type( $base );
        return $base;
    }

    protected static function post_type_route( string $base, ?int $id, string ...$methods ): string {
        $route = '/' . Post_Type_Rest_Base::route_namespace( $base ) . '/' . $base . ( null === $id ? '' : '/' . $id );
        foreach ( $methods as $method ) {
            Post_Type_Rest_Base::require_own_route( $method, $base, $route );
        }
        return $route;
    }

    protected function precheck_post_type_route( array $arguments, string $key, string $default, ?string $id_key, string ...$methods ): ?string {
        $given = '' === $default
            ? isset( $arguments[ $key ] ) && '' !== $arguments[ $key ]
            : ! empty( $arguments[ $key ] );
        if ( $given ) {
            $base = $this->resolve_post_type_rest_base( $arguments[ $key ], $key );
        } elseif ( '' !== $default ) {
            $base = $default;
        } else {
            return null;
        }
        $id = null;
        if ( null !== $id_key ) {
            $id = $this->precheck_id( $arguments, $id_key );
            if ( null === $id ) {
                return $base;
            }
        }
        self::post_type_route( $base, $id, ...$methods );
        if ( null !== $id ) {
            $this->refuse_other_post_type_base( $id, $base, $key );
        }
        return $base;
    }

    protected function refuse_switched_off_dedicated_tool( ?string $rest_base ): void {
        $this->throw_switched_off( null === $rest_base ? null : \Easy_MCP_AI\Rest_Routes\Dedicated_Tools::cpt_refusal( $this->get_name(), $rest_base ) );
    }

    public function get_required_tools(): array {
        return array();
    }

    protected function refuse_switched_off_route( string $method, string $route ): void {
        $this->throw_switched_off( \Easy_MCP_AI\Rest_Routes\Dedicated_Tools::route_refusal( $method, $route ) );
    }

    protected function refuse_switched_off_post_type_route( string $method, string $base, bool $item ): void {
        $this->refuse_switched_off_route( $method, '/' . Post_Type_Rest_Base::route_namespace( $base ) . '/' . $base . ( $item ? '/{id}' : '' ) );
    }

    protected function refuse_switched_off_post_update( $post, bool $approved_css = false ): void {
        if ( $approved_css && is_object( $post ) && isset( $post->post_type ) && in_array( $post->post_type, \Easy_MCP_AI\Rest_Routes\Dedicated_Tools::SITE_CSS_TYPES, true )
            && 'redeem' === \Easy_MCP_AI\MCP\Server::current_call_approval_kind() ) {
            return;
        }
        $type = is_object( $post ) && isset( $post->post_type ) ? \get_post_type_object( (string) $post->post_type ) : null;
        $tool = is_object( $type ) ? \Easy_MCP_AI\Rest_Routes\Dedicated_Tools::post_update_tool( (string) $post->post_type, $type ) : null;
        $this->throw_switched_off( null === $tool ? null : \Easy_MCP_AI\Rest_Routes\Dedicated_Tools::refusal( array( $tool ) ) );
    }

    protected function refuse_switched_off_term_action( string $action, $taxonomy ): void {
        $tax_obj = is_string( $taxonomy ) && '' !== $taxonomy && function_exists( 'get_taxonomy' ) ? \get_taxonomy( $taxonomy ) : null;
        $tool    = is_object( $tax_obj ) ? \Easy_MCP_AI\Rest_Routes\Dedicated_Tools::term_tool( $taxonomy, $tax_obj, $action ) : null;
        $this->throw_switched_off( null === $tool ? null : \Easy_MCP_AI\Rest_Routes\Dedicated_Tools::refusal( array( $tool ) ) );
    }

    protected function refuse_switched_off_term_route( string $method, $taxonomy, bool $item ): void {
        $tax_obj = is_string( $taxonomy ) && '' !== $taxonomy && function_exists( 'get_taxonomy' ) ? \get_taxonomy( $taxonomy ) : null;
        if ( ! is_object( $tax_obj ) || empty( $tax_obj->show_in_rest ) ) {
            return;
        }
        $namespace = ! empty( $tax_obj->rest_namespace ) && is_string( $tax_obj->rest_namespace ) ? trim( $tax_obj->rest_namespace, '/' ) : 'wp/v2';
        $base      = ! empty( $tax_obj->rest_base ) && is_string( $tax_obj->rest_base ) ? $tax_obj->rest_base : $taxonomy;
        $this->refuse_switched_off_route( $method, '/' . $namespace . '/' . $base . ( $item ? '/{id}' : '' ) );
    }

    private function throw_switched_off( ?string $refusal ): void {
        if ( null !== $refusal ) {
            throw \Easy_MCP_AI\MCP\Detailed_Runtime_Error::for_validation( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    private function precheck_id( array $arguments, string $id_key ): ?int {
        try {
            return $this->parse_required_id( $arguments[ $id_key ] ?? null, $id_key );
        } catch ( \InvalidArgumentException $e ) {
            return null;
        }
    }

    protected function refuse_other_type( int $post_id, string $expected_type, string $verb ): void {
        $post = get_post( $post_id );
        if ( ! is_object( $post ) || $expected_type === $post->post_type || ! self::may_name_post_type( $post ) ) {
            return;
        }
        throw new \InvalidArgumentException( Post_Type_Tool_Hint::other_type( $post, $expected_type, $verb, $this->get_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    protected function refuse_other_post_type_base( int $post_id, string $base, string $argument ): void {
        $post   = get_post( $post_id );
        $served = Post_Type_Rest_Base::served_type( $base ) ?? Hidden_Post_Types::open_type( $base );
        if ( null === $served || ! is_object( $post ) || $served[0] === $post->post_type || ! Post_Type_Rest_Base::uses_core_get_post( $served[1] ) || ! self::may_name_post_type( $post ) ) {
            return;
        }
        throw new \InvalidArgumentException( Post_Type_Tool_Hint::other_base( $post, $served[0], $argument, $this->get_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    private static function may_name_post_type( object $post ): bool {
        if ( ! get_post_type_object( $post->post_type ) || ! current_user_can( 'read_post', (int) $post->ID ) ) {
            return false;
        }
        return 'revision' !== $post->post_type || current_user_can( 'edit_post', (int) $post->post_parent );
    }

    protected function precheck_other_type( array $arguments, string $id_key, string $expected_type, string $verb ): void {
        $post_id = $this->precheck_id( $arguments, $id_key );
        if ( null !== $post_id ) {
            $this->refuse_other_type( $post_id, $expected_type, $verb );
        }
    }

    protected function refuse_other_taxonomy( int $term_id, string $expected, string $verb ): void {
        $term     = get_term( $term_id );
        $taxonomy = is_object( $term ) && ! is_wp_error( $term ) ? (string) $term->taxonomy : '';
        $object   = '' !== $taxonomy && $expected !== $taxonomy ? get_taxonomy( $taxonomy ) : false;
        if ( ! is_object( $object ) || ! self::rest_shows_term( $term_id, $taxonomy, $object ) ) {
            return;
        }
        $tools = array(
            'get'    => array( 'wp_get_term', (string) $object->cap->assign_terms ),
            'update' => array( 'wp_update_term', 'edit_term' ),
            'delete' => array( 'wp_delete_term', 'delete_term' ),
        );
        list( $tool, $capability ) = $tools[ $verb ];
        if ( Post_Type_Tool_Hint::may_use( $tool ) && current_user_can( $capability, $term_id ) ) {
            throw new \InvalidArgumentException( sprintf( self::MSG_OTHER_TAXONOMY_TOOL, $tool, $taxonomy, $expected, $term_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        throw new \InvalidArgumentException( sprintf( self::MSG_OTHER_TAXONOMY, $term_id, $taxonomy, $expected, $this->get_name(), Post_Type_Tool_Hint::VERBS[ $verb ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    private static function rest_shows_term( int $term_id, string $taxonomy, object $object ): bool {
        if ( empty( $object->show_in_rest ) ) {
            return false;
        }
        $base = ! empty( $object->rest_base ) && is_string( $object->rest_base ) ? trim( $object->rest_base, '/' ) : $taxonomy;
        try {
            $namespace = Taxonomy\List_Terms::taxonomy_rest_namespace( $object, $taxonomy );
        } catch ( \RuntimeException $e ) {
            return false;
        }
        if ( ! preg_match( '#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z#', $base ) ) {
            return false;
        }
        $request = new \WP_REST_Request( 'GET', sprintf( '/%s/%s/%d', $namespace, $base, $term_id ) );
        $request->set_param( 'context', 'view' );
        $response = apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );
        if ( ! $response instanceof \WP_REST_Response || $response->is_error() || 200 !== $response->get_status() ) {
            return false;
        }
        $data = $response->get_data();
        return is_array( $data ) && isset( $data['id'], $data['taxonomy'] ) && (int) $data['id'] === $term_id && $taxonomy === $data['taxonomy'];
    }

    protected function precheck_other_taxonomy( array $arguments, string $id_key, string $expected, string $verb ): void {
        $term_id = $this->precheck_id( $arguments, $id_key );
        if ( null !== $term_id && get_taxonomy( $expected ) ) {
            $this->refuse_other_taxonomy( $term_id, $expected, $verb );
        }
    }

    protected function refuse_delete_value_read_as_empty( array $arguments ): void {
        $value = $arguments['value'] ?? null;
        if ( is_string( $value ) && '' !== $value && '' === wp_unslash( $value ) ) {
            throw new \InvalidArgumentException( 'A value of one backslash is refused: WordPress removes the backslash before it matches the value, so it would delete every row of the key. To delete every row, omit value.' );
        }
    }

    protected function delete_meta_by_value( string $meta_type, int $object_id, string $meta_key, callable $delete, $value ): array {
        $slashed = wp_slash( $value );
        if ( $delete( $slashed ) ) {
            return array( true, $slashed );
        }
        if ( $slashed === $value || false !== Delete_Outcome::meta_kept( $meta_type, $object_id, $meta_key, $slashed ) ) {
            return array( false, $slashed );
        }
        return array( (bool) $delete( $value ), $value );
    }

    protected function refuse_builder_owned_write( int $post_id, bool $content, array $meta = array() ): void {
        $post = get_post( $post_id );
        if ( ! is_object( $post ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $adapter = \Easy_MCP_AI\Builders\Builder_Registry::for_post( $post );
        if ( null === $adapter ) {
            return;
        }
        if ( $content && ! $adapter->renders_post_content( $post ) && ! $adapter->released_by( $meta ) ) {
            throw new \InvalidArgumentException( sprintf( 'Post %d is built with %s, which shows its own layout, not the post content, so this content edit would not appear. Edit the layout with wp_update_page_layout.', $post_id, $adapter->label() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $layout = self::split_layout_meta( $meta, $adapter->layout_meta_keys() )[1];
        if ( array() !== $layout ) {
            throw new \InvalidArgumentException( sprintf( 'Post %d is built with %s: writing %s directly leaves the page showing its old layout. Edit the layout with wp_update_page_layout.', $post_id, $adapter->label(), implode( ', ', $layout ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    protected static function split_layout_meta( array $meta, array $layout_keys ): array {
        $found = array_values( array_intersect( array_map( 'strval', array_keys( $meta ) ), $layout_keys ) );
        return array( array_diff_key( $meta, array_flip( $found ) ), $found );
    }

    protected static function builder_layout_meta_keys( int $post_id ): array {
        $post    = get_post( $post_id );
        $adapter = is_object( $post ) ? \Easy_MCP_AI\Builders\Builder_Registry::for_post( $post ) : null;
        return null === $adapter ? array() : $adapter->layout_meta_keys();
    }

    protected static function rest_meta_without_layout( int $post_id, array $meta, string $rest_base ): array {
        list( $kept, $left_out ) = self::split_layout_meta( $meta, self::builder_layout_meta_keys( $post_id ) );
        if ( array() === $left_out ) {
            return array( 'meta' => $meta );
        }
        return array(
            'meta'               => $kept,
            'meta_left_out'      => $left_out,
            'meta_left_out_note' => sprintf( 'Left out of meta: %1$s, the page builder\'s stored layout. Read the layout with wp_get_page_layout, or the raw value with wp_get_post_meta (key %2$s, post_type %3$s).', implode( ', ', $left_out ), implode( ' or ', $left_out ), $rest_base ),
        );
    }

    protected function refuse_builder_owned_product_writes( array $items ): void {
        foreach ( $items as $item ) {
            $product_id = is_array( $item ) && isset( $item['id'] ) && is_scalar( $item['id'] ) ? (int) $item['id'] : 0;
            if ( $product_id <= 0 ) {
                continue;
            }
            $meta = array();
            foreach ( isset( $item['meta_data'] ) && is_array( $item['meta_data'] ) ? $item['meta_data'] : array() as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                if ( isset( $entry['key'] ) && is_scalar( $entry['key'] ) ) {
                    $meta[ (string) $entry['key'] ] = $entry['value'] ?? null;
                }
                $row = isset( $entry['id'] ) && is_scalar( $entry['id'] ) && (int) $entry['id'] > 0 ? get_metadata_by_mid( 'post', (int) $entry['id'] ) : false;
                if ( is_object( $row ) && isset( $row->meta_key, $row->post_id ) && (int) $row->post_id === $product_id ) {
                    $meta[ (string) $row->meta_key ] = $entry['value'] ?? null;
                }
            }
            $this->refuse_builder_owned_write( $product_id, array_key_exists( 'description', $item ), $meta );
        }
    }

    protected function rest_taxonomies_for( $object_type ) {
        $map = array();
        foreach ( (array) get_object_taxonomies( $object_type, 'objects' ) as $key => $tax ) {
            if ( ! is_object( $tax ) || empty( $tax->show_in_rest ) ) {
                continue;
            }
            $slug         = ! empty( $tax->name ) ? (string) $tax->name : (string) $key;
            $map[ $slug ] = ! empty( $tax->rest_base ) ? (string) $tax->rest_base : $slug;
        }
        return $map;
    }

    protected function item_taxonomy_terms( array $item, array $map ) {
        $terms = array();
        foreach ( $map as $slug => $rest_base ) {
            $ids = isset( $item[ $rest_base ] ) && is_array( $item[ $rest_base ] ) ? $item[ $rest_base ] : array();
            $terms[ $slug ] = array_values( array_map( 'intval', $ids ) );
        }
        return empty( $terms ) ? new \stdClass() : $terms;
    }

    protected function parse_taxonomy_filter( $value, $label, array $map ) {
        $filters = $this->parse_json_param( $value, $label );
        $params  = array();
        foreach ( $filters as $slug => $ids ) {
            $slug = (string) $slug;
            if ( ! isset( $map[ $slug ] ) ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        '%s: unknown taxonomy "%s". Valid taxonomies: %s.',
                        $label, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                        $slug, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                        empty( $map ) ? '(none exposed in the REST API)' : implode( ', ', array_keys( $map ) ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    )
                );
            }
            if ( is_scalar( $ids ) ) {
                $ids = array( $ids );
            }
            $clean = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
            if ( ! empty( $clean ) ) {
                $params[ $map[ $slug ] ] = $clean;
            }
        }
        return $params;
    }

    protected function refuse_non_rest_taxonomy( $taxonomy, $tax_obj ) {
        if ( ! empty( $tax_obj->show_in_rest ) ) {
            return;
        }
        $message = sprintf( 'Taxonomy %s is not exposed in the REST API (show_in_rest is false), so its terms cannot be changed here.', $taxonomy );
        if ( 0 === strpos( (string) $taxonomy, 'pa_' ) ) {
            $message .= ' Attribute values: Products > Attributes > Configure terms.';
        }
        throw new \InvalidArgumentException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    protected static function image_filename_from_bytes( string $filename, string $path ): ?string {
        if ( ! function_exists( 'wp_get_image_mime' ) ) {
            return null;
        }
        $mime = wp_get_image_mime( $path );
        return is_string( $mime ) ? self::image_filename_for_type( $filename, $mime ) : null;
    }

    protected static function image_filename_for_type( string $filename, string $mime ): ?string {
        $mime = strtolower( trim( $mime ) );
        if ( 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime || ! function_exists( 'get_allowed_mime_types' ) ) {
            return null;
        }
        foreach ( (array) get_allowed_mime_types() as $extensions => $type ) {
            if ( $type === $mime ) {
                $base = (string) pathinfo( $filename, PATHINFO_FILENAME );
                return ( '' !== $base ? $base : 'image' ) . '.' . explode( '|', (string) $extensions )[0];
            }
        }
        return null;
    }

    protected function refuse_missing_post( $post_id ) {
        if ( ! get_post( $post_id ) ) {
            throw new \InvalidArgumentException( sprintf( 'Post %d not found.', $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    protected function resolve_post_rest_base( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            throw new \RuntimeException( 'Post not found.' );
        }

        $post_type_object = get_post_type_object( $post->post_type );
        if ( ! $post_type_object || empty( $post_type_object->show_in_rest ) ) {
            throw new \RuntimeException(
                sprintf( 'Post type "%s" is not available via the REST API.', $post->post_type ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }

        return ! empty( $post_type_object->rest_base )
            ? $post_type_object->rest_base
            : $post->post_type;
    }

    protected function discover_global_styles_id() {
        $stylesheet = get_stylesheet();
        $request    = new \WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/' . $stylesheet );
        $response   = rest_do_request( $request );

        if ( ! $response->is_error() ) {
            $data = $response->get_data();
            if ( ! empty( $data['id'] ) ) {
                return (int) $data['id'];
            }
        }

        if ( class_exists( 'WP_Theme_JSON_Resolver' )
             && method_exists( 'WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
            $id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
            if ( $id ) {
                return (int) $id;
            }
        }

        throw new \RuntimeException(
            'Could not discover global styles. This requires WordPress 6.1 or later with an active block theme.'
        );
    }

    protected function fetch_style_variations() {
        $stylesheet = get_stylesheet();
        $request    = new \WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/' . $stylesheet . '/variations' );
        $response   = rest_do_request( $request );

        if ( $response->is_error() ) {
            $wp_error = $response->as_error();
            if ( 'rest_no_route' === $wp_error->get_error_code() ) {
                throw new \RuntimeException(
                    'Style variations endpoint is not available. This requires WordPress 6.0 or later with an active block theme (Full Site Editing).'
                );
            }
            throw new \RuntimeException( $wp_error->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $variations = array();
        foreach ( (array) $response->get_data() as $variation ) {
            if ( ! is_array( $variation ) ) {
                continue;
            }
            $title = isset( $variation['title'] ) && is_scalar( $variation['title'] ) ? (string) $variation['title'] : '';
            $slug  = isset( $variation['slug'] ) && is_string( $variation['slug'] ) && '' !== $variation['slug']
                ? $variation['slug']
                : ( function_exists( '_wp_to_kebab_case' ) ? _wp_to_kebab_case( $title ) : sanitize_title( $title ) );
            if ( '' === $slug ) {
                continue;
            }
            $settings = isset( $variation['settings'] ) && is_array( $variation['settings'] ) ? $variation['settings'] : array();
            $styles   = isset( $variation['styles'] ) && is_array( $variation['styles'] ) ? $variation['styles'] : array();

            $variations[] = array(
                'slug'     => $slug,
                'title'    => $title,
                'scope'    => $this->style_variation_scope( $settings, $styles ),
                'settings' => $settings,
                'styles'   => $styles,
            );
        }

        return $this->disambiguate_style_variation_slugs( $variations );
    }

    protected function style_variation_scope( array $settings, array $styles ) {
        $touched = array();
        $this->collect_style_variation_categories( $settings, $touched );
        $this->collect_style_variation_categories( $styles, $touched );

        $keys = array_keys( $touched );
        if ( array( 'color' ) === $keys ) {
            return 'color';
        }
        if ( array( 'typography' ) === $keys ) {
            return 'typography';
        }
        return 'full';
    }

    private function collect_style_variation_categories( array $node, array &$touched ) {
        foreach ( $node as $key => $value ) {
            if ( 'color' === $key || 'typography' === $key ) {
                $touched[ $key ] = true;
                continue;
            }
            if ( is_array( $value ) ) {
                $this->collect_style_variation_categories( $value, $touched );
            } else {
                $touched['other'] = true;
            }
        }
    }

    private function disambiguate_style_variation_slugs( array $variations ) {
        $scopes_per_slug = array();
        foreach ( $variations as $variation ) {
            $scopes_per_slug[ $variation['slug'] ][ $variation['scope'] ] = true;
        }

        $taken = array();
        foreach ( $variations as &$variation ) {
            if ( count( $scopes_per_slug[ $variation['slug'] ] ) > 1 && 'full' !== $variation['scope'] ) {
                $variation['slug'] .= '-' . $variation['scope'];
            }
            $base = $variation['slug'];
            $slug = $base;
            for ( $n = 2; isset( $taken[ $slug ] ); $n++ ) {
                $slug = $base . '-' . $n;
            }
            $taken[ $slug ]    = true;
            $variation['slug'] = $slug;
        }
        unset( $variation );

        return $variations;
    }

    protected function refuse_webhook_api_version( array $arguments ): void {
        if ( array_key_exists( 'api_version', $arguments ) ) {
            throw new \InvalidArgumentException( '"api_version" cannot be set: the WooCommerce REST API ignores it. A new webhook gets the /wc/v3 route default and an update keeps the stored version. Send the call again without it.' );
        }
    }

    protected function validate_webhook_url( $url ) {
        $url    = is_string( $url ) ? trim( $url ) : '';
        $parsed = wp_parse_url( $url );

        if ( ! $parsed || empty( $parsed['host'] ) ) {
            throw new \InvalidArgumentException( 'Invalid webhook delivery URL.' );
        }

        $scheme = strtolower( isset( $parsed['scheme'] ) ? $parsed['scheme'] : '' );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            throw new \InvalidArgumentException( 'Webhook delivery URL must use http or https.' );
        }

        $host = $parsed['host'];
        if ( '[' === $host[0] ) {
            $host = trim( $host, '[]' );
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $resolved_ip = $host;
        } else {
            $resolved_ip = gethostbyname( $host );
            if ( $resolved_ip === $host ) {
                throw new \InvalidArgumentException(
                    'Webhook delivery URL hostname could not be resolved.' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                );
            }
        }

        $is_public = filter_var(
            $resolved_ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ( false === $is_public ) {
            throw new \InvalidArgumentException(
                'Webhook delivery URL cannot target private or reserved IP ranges.' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }

        return esc_url_raw( $url );
    }

    protected function invalidate_post_cache( $post_id, array $context = array() ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }

        if ( isset( self::$already_invalidated[ $post_id ] ) ) {
            return;
        }
        self::$already_invalidated[ $post_id ] = true;

        if ( function_exists( 'clean_post_cache' ) ) {
            clean_post_cache( $post_id );
        }

        $context = wp_parse_args(
            $context,
            array(
                'source' => 'mcp',
                'tool'   => $this->get_name(),
            )
        );

        do_action( 'easy_mcp_ai_post_changed', $post_id, $context );
    }

    protected function clear_page_cache( array $post_ids, array $urls = array() ): void {
        do_action( 'easy_mcp_ai_clear_page_cache', $post_ids, $urls );
    }

    public static function reset_cache_invalidation_state() {
        self::$already_invalidated = array();
    }

    protected function pagination_meta( $response, $page, $per_page, $count = 0 ) {
        $headers  = ( is_object( $response ) && method_exists( $response, 'get_headers' ) ) ? $response->get_headers() : array();
        $per_page = max( 1, (int) $per_page );
        $total    = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : (int) $count;
        $total_pages = isset( $headers['X-WP-TotalPages'] )
            ? (int) $headers['X-WP-TotalPages']
            : (int) ceil( $total / $per_page );

        return array(
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => (int) $page,
            'per_page'    => $per_page,
        );
    }

    protected function past_the_end_meta( \WP_REST_Request $request, callable $send, int $page, int $per_page ): array {
        $asked = $request->get_param( 'page' );
        $request->set_param( 'page', 1 );
        try {
            $first = $send();
        } finally {
            $request->set_param( 'page', $asked );
        }
        if ( $first->is_error() ) {
            throw self::rest_error_exception( $first->as_error() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $this->pagination_meta( $first, $page, $per_page, 0 );
    }

    protected function search_past_the_end( \WP_REST_Response $response, \WP_REST_Request $request, callable $send, int $page, int $per_page, string $query ): ?array {
        if ( $response->is_error() && ! $this->is_invalid_page_error( $response->as_error() ) ) {
            throw new \RuntimeException( $response->as_error()->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( ! $response->is_error() && ( array() !== $response->get_data() || $page <= 1 ) ) {
            return null;
        }
        return array_merge(
            array( 'results' => array() ),
            $this->past_the_end_meta( $request, $send, $page, $per_page ),
            array( 'query' => $query )
        );
    }

    protected function is_invalid_page_error( $error ) {
        return \is_wp_error( $error ) && false !== strpos( (string) $error->get_error_code(), 'invalid_page_number' );
    }

    protected function build_search_snippet( $content, $query, $length = 200 ) {
        $length  = max( 20, min( 1000, (int) $length ) );
        $content = (string) $content;
        if ( '' === $content ) {
            return '';
        }
        $needles = $this->search_snippet_needles( (string) $query );
        $cap = 20000;
        if ( strlen( $content ) > $cap ) {
            $window = min( $cap, $length + 4000 );
            $use_mb    = function_exists( 'mb_stripos' ) && function_exists( 'mb_substr' );
            $raw_pos   = $this->locate_search_match( $content, $needles, $use_mb );
            $raw_start = ( false !== $raw_pos ) ? (int) max( 0, $raw_pos - 2000 ) : 0;
            $content   = $use_mb
                ? mb_substr( $content, $raw_start, $window, 'UTF-8' )
                : substr( $content, $raw_start, $window );
        }

        $text = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $content ) : $content;
        $text = preg_replace( '/<!--.*?-->/s', ' ', $text );
        $text = wp_strip_all_tags( (string) $text );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $collapsed = preg_replace( '/\s+/u', ' ', $text );
        if ( null === $collapsed ) {
            $collapsed = preg_replace( '/\s+/', ' ', $text );
        }
        $text = trim( (string) $collapsed );
        if ( '' === $text ) {
            return '';
        }

        $have_mb = function_exists( 'mb_stripos' ) && function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' );

        $pos = $this->locate_search_match( $text, $needles, $have_mb );
        if ( false === $pos ) {
            $pos = 0;
        }

        $total = $have_mb ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
        if ( $total <= $length ) {
            return $text;
        }

        $start = (int) max( 0, $pos - (int) floor( $length / 2 ) );
        if ( $start + $length > $total ) {
            $start = (int) max( 0, $total - $length );
        }
        $snippet = $have_mb ? mb_substr( $text, $start, $length, 'UTF-8' ) : substr( $text, $start, $length );
        $snippet = trim( (string) $snippet );

        $prefix = $start > 0 ? "\xE2\x80\xA6" : '';
        $suffix = ( $start + $length ) < $total ? "\xE2\x80\xA6" : '';
        return $prefix . $snippet . $suffix;
    }

    private function search_snippet_needles( string $query ): array {
        $search = trim( stripslashes( $query ) );
        if ( '' === $search ) {
            return array();
        }
        $terms = self::wp_search_terms( $search );
        if ( array() === $terms ) {
            $terms = array( $search );
        }

        $exclusion_prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );
        $needles          = array();
        foreach ( $terms as $term ) {
            if ( ! $exclusion_prefix || 0 !== strpos( $term, (string) $exclusion_prefix ) ) {
                $needles[] = $term;
            }
        }
        if ( count( $needles ) === count( $terms ) ) {
            array_unshift( $needles, $search );
        }
        return array_values( array_unique( $needles ) );
    }

    protected static function wp_search_terms( string $search ): array {
        preg_match_all( '/".*?("|$)|((?<=[\t ",+])|^)[^\t ",+]+/', $search, $matches );
        $parser = new class() extends \WP_Query {
            public function search_terms( array $terms ): array {
                return $this->parse_search_terms( $terms );
            }
        };

        $terms = array_values( $parser->search_terms( $matches[0] ) );
        return count( $terms ) > 9 ? array() : $terms;
    }

    private function locate_search_match( string $haystack, array $needles, bool $use_mb ) {
        foreach ( $needles as $needle ) {
            $pos = $use_mb ? mb_stripos( $haystack, $needle, 0, 'UTF-8' ) : stripos( $haystack, $needle );
            if ( false !== $pos ) {
                return (int) $pos;
            }
        }
        return false;
    }

    protected function apply_meta_argument( array $arguments, array &$params ) {
        if ( ! isset( $arguments['meta'] ) ) {
            return false;
        }
        $meta = $this->parse_json_param( $arguments['meta'], 'meta' );
        if ( array() === $meta ) {
            return false;
        }
        if ( array_keys( $meta ) === range( 0, count( $meta ) - 1 ) ) {
            throw new \InvalidArgumentException( 'meta must be an object of meta key-value pairs, not a list.' );
        }
        $params['meta'] = $meta;
        return true;
    }

    protected function ignored_meta_report( array $sent, $persisted, string $object_type = 'post', string $post_type = '' ): array {
        $registered = self::takes_meta( $object_type, $post_type ) ? self::rest_meta_names( $object_type, $post_type ) : array();
        $persisted  = is_array( $persisted ) ? $persisted : array();
        $ignored    = array();
        foreach ( array_keys( $sent ) as $key ) {
            if ( ! array_key_exists( $key, $persisted ) && ! isset( $registered[ $key ] ) ) {
                $ignored[] = (string) $key;
            }
        }
        if ( empty( $ignored ) ) {
            return array();
        }
        $scopes = array(
            'post'    => 'this post type',
            'comment' => 'comments',
        );
        $notice = sprintf(
            'The following meta keys were sent but not persisted (they are not registered with show_in_rest=true for %1$s): %2$s.',
            $scopes[ $object_type ],
            implode( ', ', $ignored )
        );
        $explained = array();
        if ( class_exists( '\\Easy_MCP_AI\\Meta\\Meta_Exposure' ) ) {
            $explained = \Easy_MCP_AI\Meta\Meta_Exposure::explain_ignored_keys( $ignored );
            if ( ! empty( $explained ) ) {
                $notice .= ' ' . implode( ' ', array_values( $explained ) );
            }
        }
        if ( 'post' === $object_type ) {
            $acf = self::acf_field_hint( array_values( array_diff( $ignored, array_keys( $explained ) ) ) );
            if ( '' !== $acf ) {
                $notice .= ' ' . $acf;
            }
        }
        return array(
            'meta_ignored' => $ignored,
            'notice'       => $notice,
        );
    }

    private static function takes_meta( string $object_type, string $post_type ): bool {
        if ( 'comment' === $object_type ) {
            return true;
        }
        $fields = self::rest_item_fields( get_post_type_object( $post_type ) );
        return null !== $fields && in_array( 'meta', $fields, true );
    }

    public static function rest_item_fields( ?object $post_type_object ): ?array {
        if ( ! is_object( $post_type_object ) || ! method_exists( $post_type_object, 'get_rest_controller' ) ) {
            return null;
        }
        $controller = $post_type_object->get_rest_controller();
        if ( ! is_object( $controller ) || ! method_exists( $controller, 'get_item_schema' ) ) {
            return null;
        }
        $schema = $controller->get_item_schema();
        if ( ! is_array( $schema ) || ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
            return null;
        }
        return array_map( 'strval', array_keys( $schema['properties'] ) );
    }

    public static function discussion_status( $stored ): string {
        return 'open' === $stored ? 'open' : 'closed';
    }

    private static function rest_meta_names( string $object_type, string $post_type ): array {
        $fields = 'comment' === $object_type ? new \WP_REST_Comment_Meta_Fields() : new \WP_REST_Post_Meta_Fields( $post_type );
        return $fields->get_field_schema()['properties'];
    }

    private static function acf_field_hint( array $keys ): string {
        if ( ! function_exists( 'acf_get_field' ) ) {
            return '';
        }
        if ( ! Post_Type_Tool_Hint::may_use( 'wp_acf_update_fields' ) ) {
            return '';
        }
        $fields = array();
        foreach ( $keys as $key ) {
            if ( is_array( acf_get_field( $key ) ) ) {
                $fields[] = $key;
            }
        }
        if ( empty( $fields ) ) {
            return '';
        }
        return sprintf( 'ACF fields are not saved through meta: %s. Write them with wp_acf_update_fields (post_id, post_type, and fields as { field name: value }).', implode( ', ', $fields ) );
    }

    protected function require_compat_shim( string $id, array $arguments ): void {
        $registry = '\\Easy_MCP_AI\\Compat\\Compat_Registry';
        if ( class_exists( $registry ) && $registry::is_armed( $id, $this, $arguments ) ) {
            return;
        }
        throw new \RuntimeException( 'This tool cannot run safely right now: its data-protection step did not start, so nothing was changed. Please report this to the site administrator.' );
    }

    protected function find_posts_by_title( string $post_type, string $title, int $limit = 5 ): array {
        if ( '' === trim( $title ) ) {
            return array();
        }
        $query = new \WP_Query(
            array(
                'post_type'              => $post_type,
                'title'                  => $title,
                'post_status'            => array_values( array_diff( get_post_stati(), array( 'trash', 'auto-draft', 'inherit' ) ) ),
                'posts_per_page'         => max( 1, $limit ),
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );
        return array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
    }

    protected function refuse_existing_title( string $post_type, string $name, array $meta_keys, string $noun, string $usage, string $list_tool ): void {
        $existing = $this->find_posts_by_title( $post_type, $name, 1 );
        if ( ! $existing ) {
            return;
        }
        $id      = $existing[0];
        $details = array();
        if ( current_user_can( 'read_post', $id ) ) {
            foreach ( $meta_keys as $meta_key ) {
                $value = get_post_meta( $id, $meta_key, true );
                if ( is_string( $value ) && '' !== $value ) {
                    $details[] = $value;
                }
            }
        }
        $label = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::EXISTING_TITLE_LABEL_LENGTH ) : substr( $name, 0, self::EXISTING_TITLE_LABEL_LENGTH );
        throw new \Easy_MCP_AI\MCP\Detailed_Tool_Error( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            sprintf( '%1$s "%2$s" already exists (ID %3$d). Nothing was changed.', ucfirst( $noun ), $label, $id ),
            sprintf(
                'Existing %1$s %2$d%3$s. %4$s To create a second %1$s with the same name anyway, call this tool again with `allow_duplicate` set to true. Use `%5$s` with `search` to see every match.',
                $noun,
                $id,
                $details ? ' (' . implode( ', ', $details ) . ')' : '',
                $usage,
                $list_tool
            )
        );
    }

    protected function allows_duplicate( array $arguments ): bool {
        return ! empty( $arguments['allow_duplicate'] ) && (bool) rest_sanitize_boolean( $arguments['allow_duplicate'] );
    }

    protected function strip_response_noise( $reply ) {
        if ( ! is_array( $reply ) ) {
            return $reply;
        }
        $drop      = array_flip( self::response_noise_keys() );
        $strip_row = static function ( $row ) use ( $drop ) {
            return is_array( $row ) ? array_diff_key( $row, $drop ) : $row;
        };
        if ( self::is_batch_reply( $reply ) ) {
            foreach ( $reply as $operation => $items ) {
                $reply[ $operation ] = is_array( $items ) ? array_map( $strip_row, $items ) : $items;
            }
            return $reply;
        }
        return array_values( $reply ) === $reply ? array_map( $strip_row, $reply ) : $strip_row( $reply );
    }

    protected static function response_noise_keys(): array {
        $keys = apply_filters( 'easy_mcp_ai_response_noise_keys', self::RESPONSE_NOISE_KEYS );
        if ( ! is_array( $keys ) ) {
            return self::RESPONSE_NOISE_KEYS;
        }
        $keys = array_filter(
            $keys,
            static function ( $key ) {
                return is_string( $key ) && '' !== $key;
            }
        );
        return array_values( array_diff( $keys, self::RESPONSE_NOISE_PROTECTED_KEYS ) );
    }

    private static function is_batch_reply( array $reply ): bool {
        return ! empty( $reply ) && array() === array_diff( array_keys( $reply ), array( 'create', 'update', 'delete' ) );
    }

    protected function rest_request( $method, $route, $params = array(), $fields = null ) {
        return $this->dispatch_rest_request( self::build_rest_request( $method, $route, $params, $fields ) );
    }

    protected static function build_rest_request( $method, $route, $params = array(), $fields = null ) {
        $request = new \WP_REST_Request( $method, $route );
        if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && ! empty( $params ) ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( wp_json_encode( $params ) );
        } else {
            foreach ( $params as $key => $value ) {
                $request->set_param( $key, $value );
            }
        }
        if ( null !== $fields ) {
            $request->set_param( '_fields', $fields );
        }
        return $request;
    }

    protected function dispatch_rest_request( \WP_REST_Request $request ) {
        return $this->dispatch_rest_response( $request )->get_data();
    }

    protected function rest_response( $method, $route, $params = array() ) {
        return $this->dispatch_rest_response( self::build_rest_request( $method, $route, $params ) );
    }

    private function dispatch_rest_response( \WP_REST_Request $request ) {
        return \Easy_MCP_AI\Tools\Users\Customer_Meta_Filter::filter_response( $request->get_route(), self::refuse_rest_error( $request, rest_do_request( $request ) ) );
    }

    protected function dispatch_post_update( \WP_REST_Request $request, int $post_id, bool $resave ) {
        $route   = (string) preg_replace( '#/' . $post_id . '/?$#', '/{id}', $request->get_route() );
        $method  = $request->get_method();
        $refusal = null;
        $guard   = static function () use ( $method, $route, &$refusal ): ?\WP_Error {
            $refusal = \Easy_MCP_AI\Rest_Routes\Dedicated_Tools::route_refusal( $method, $route );
            return null === $refusal ? null : new \WP_Error( 'easy_mcp_ai_tool_switched_off', $refusal, array( 'status' => 403 ) );
        };
        $narrow = Narrow_Post_Write::dispatch( $request, $post_id, $resave, $guard );
        if ( null === $narrow ) {
            $guard();
            $this->throw_switched_off( $refusal );
            return rest_do_request( $request );
        }
        $this->throw_switched_off( $refusal );
        if ( $narrow['wrote'] ) {
            $this->clear_page_cache( array( $post_id ) );
        }
        return $narrow['response'];
    }

    protected function rest_post_update( string $method, string $route, array $params, ?string $fields, int $post_id, bool $resave ) {
        $request = self::build_rest_request( $method, $route, $params, $fields );
        return self::refuse_rest_error( $request, $this->dispatch_post_update( $request, $post_id, $resave ) )->get_data();
    }

    protected static function resave_property( string $what ): array {
        return array(
            'type'        => 'boolean',
            'default'     => false,
            'description' => sprintf( 'Also save the whole post, as an edit in WordPress does: its save hooks run, a revision may be added and its modified date moves. By default a call that changes only %s writes just that and leaves the post itself untouched.', $what ),
        );
    }

    private static function refuse_rest_error( \WP_REST_Request $request, $response ) {
        $method = $request->get_method();
        $route  = $request->get_route();
        if ( $response->is_error() ) {
            $message = self::rest_error_message_or_empty( $response );
            if ( '' === $message ) {
                $code = self::refusal_code( $response->get_status() );
                throw new \RuntimeException( self::rest_status_message( $method, $route, $response ), 0 !== $code ? $code : self::REST_ERROR_WITHOUT_MESSAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $data = $response->get_data();
            if ( is_array( $data ) && isset( $data['code'] ) && 'rest_invalid_param' === $data['code'] ) {
                throw \Easy_MCP_AI\MCP\Detailed_Runtime_Error::for_validation( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            throw new \RuntimeException( $message, self::refusal_code( $response->get_status() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $response;
    }

    protected static function rest_status_message( $method, $route, $response ) {
        $status = (int) $response->get_status();
        return sprintf( '%s %s answered HTTP %s with no error message.', $method, $route, trim( $status . ' ' . get_status_header_desc( $status ) ) );
    }

    protected static function rest_error_message_or_empty( $response ) {
        $data = $response->get_data();
        if ( is_array( $data ) && ! isset( $data['code'] ) && ! isset( $data['message'] ) ) {
            return '';
        }
        $error = $response->as_error();
        return $error ? trim( (string) self::rest_error_message( $error ) ) : '';
    }

    protected static function rest_error_message( $error ) {
        $message = $error->get_error_message();
        if ( 'rest_invalid_param' !== $error->get_error_code() ) {
            return $message;
        }
        $data    = $error->get_error_data();
        $reasons = array();
        if ( is_array( $data ) && isset( $data['params'] ) && is_array( $data['params'] ) ) {
            foreach ( $data['params'] as $reason ) {
                if ( is_string( $reason ) && '' !== trim( $reason ) && false === strpos( $message, $reason ) ) {
                    $reasons[] = trim( $reason );
                }
            }
        }
        return empty( $reasons ) ? $message : $message . ' — ' . implode( ' ', $reasons );
    }

    protected static function rest_error_exception( $error, string $prefix = '' ): \RuntimeException {
        $message = $prefix . self::rest_error_message( $error );
        if ( 'rest_invalid_param' === $error->get_error_code() ) {
            return \Easy_MCP_AI\MCP\Detailed_Runtime_Error::for_validation( $message );
        }
        return new \RuntimeException( $message, self::refusal_code( $error->get_error_data() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    protected static function refusal_code( $data ): int {
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : ( is_numeric( $data ) ? (int) $data : 0 );
        return in_array( $status, array( 401, 403 ), true ) ? $status : 0;
    }
}
