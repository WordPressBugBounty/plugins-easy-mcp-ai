<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Base_Tool {

    








    protected static $deferred_purge_ids = array();

    









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
        return $definition;
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
        $max = (int) get_option( 'easy_mcp_ai_max_title_length', 0 );
        if ( $max > 0 && mb_strlen( $title ) > $max ) {
            throw new \InvalidArgumentException(
                sprintf( 'Title exceeds maximum allowed length of %d characters.', $max ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }
    }

    




    protected function maybe_force_draft( array &$params ) {
        if ( get_option( 'easy_mcp_ai_force_draft_on_create', false ) ) {
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

        
        
        
        self::$deferred_purge_ids[ $post_id ] = true;
    }

    












    public static function flush_deferred_purges() {
        if ( empty( self::$deferred_purge_ids ) ) {
            return;
        }
        foreach ( array_keys( self::$deferred_purge_ids ) as $id ) {
            
            if ( function_exists( 'rocket_clean_post' ) ) {
                rocket_clean_post( $id );
            }
            
            do_action( 'litespeed_purge_post', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by LiteSpeed Cache plugin.
            
            if ( function_exists( 'w3tc_flush_post' ) ) {
                w3tc_flush_post( $id );
            }
        }
        self::$deferred_purge_ids = array();
    }

    






    public static function reset_cache_invalidation_state() {
        self::$deferred_purge_ids   = array();
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

    









    protected function is_invalid_page_error( $error ) {
        return \is_wp_error( $error ) && false !== strpos( (string) $error->get_error_code(), 'invalid_page_number' );
    }

    

















    protected function build_search_snippet( $content, $query, $length = 200 ) {
        $length  = max( 20, min( 1000, (int) $length ) );
        $content = (string) $content;
        if ( '' === $content ) {
            return '';
        }
        
        
        
        $cap = 20000;
        if ( strlen( $content ) > $cap ) {
            $window = min( $cap, $length + 4000 );
            
            
            $raw_needle = trim( (string) $query );
            
            
            
            $use_mb  = function_exists( 'mb_stripos' ) && function_exists( 'mb_substr' );
            $raw_pos = false;
            if ( '' !== $raw_needle ) {
                $raw_pos = $use_mb
                    ? mb_stripos( $content, $raw_needle, 0, 'UTF-8' )
                    : stripos( $content, $raw_needle );
            }
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

        
        $pos    = false;
        $needle = trim( (string) $query );
        if ( '' !== $needle ) {
            $pos = $have_mb ? mb_stripos( $text, $needle, 0, 'UTF-8' ) : stripos( $text, $needle );
            if ( false === $pos && false !== strpos( $needle, ' ' ) ) {
                $first_word = strtok( $needle, ' ' );
                if ( '' !== (string) $first_word ) {
                    $pos = $have_mb ? mb_stripos( $text, $first_word, 0, 'UTF-8' ) : stripos( $text, $first_word );
                }
            }
        }
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

    protected function rest_request( $method, $route, $params = array(), $fields = null ) {
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
        $response = rest_do_request( $request );
        if ( $response->is_error() ) {
            $error = $response->as_error();
            throw new \RuntimeException( $error->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $response->get_data();
    }
}
