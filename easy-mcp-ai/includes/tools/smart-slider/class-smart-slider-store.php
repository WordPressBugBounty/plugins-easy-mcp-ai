<?php

namespace Easy_MCP_AI\Tools\Smart_Slider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Smart_Slider_Store {

    const CLASSES = array(
        'Nextend\\SmartSlider3\\Application\\ApplicationSmartSlider3',
        'Nextend\\SmartSlider3\\Application\\Model\\ModelSliders',
        'Nextend\\SmartSlider3\\Application\\Model\\ModelSlides',
        'Nextend\\SmartSlider3\\Application\\Model\\ModelSlidersXRef',
        'Nextend\\SmartSlider3\\Renderable\\Item\\ItemFactory',
        'Nextend\\Framework\\Acl\\Acl',
        'Nextend\\Framework\\Request\\Request',
        'Nextend\\Framework\\Misc\\Base64',
        'Nextend\\Framework\\Data\\Data',
    );

    const EDIT = array( 'smartslider_edit' );

    const DELETE = array( 'smartslider_edit', 'smartslider_delete' );

    const EDITABLE = array(
        'heading' => array( 'heading', 'title', 'href', 'href-target', 'href-rel' ),
        'button'  => array( 'content', 'href', 'href-target', 'href-rel' ),
        'text'    => array( 'content', 'contenttablet', 'contentmobile' ),
    );

    const DEVICE_SWITCHES = array(
        'contenttablet' => 'content-tablet-enabled',
        'contentmobile' => 'content-mobile-enabled',
    );

    const ADJUSTED_BEFORE = '3.3.9999';

    const REFRESHER = 'Nextend\\SmartSlider3\\Application\\Helper\\HelperSliderChanged';

    const HOOK_FRAMES = 8;

    const SAVED_COLUMNS = array( 'title', 'slide', 'description', 'thumbnail', 'published', 'publish_up', 'publish_down', 'params' );

    public static function unavailable(): ?string {
        foreach ( self::CLASSES as $class ) {
            if ( ! class_exists( $class ) ) {
                return 'Smart Slider 3 is not active, or this version does not have ' . $class . ', so its sliders cannot be reached here.';
            }
        }
        return null;
    }

    public static function refusal( array $capabilities ): ?string {
        $missing = self::unavailable();
        if ( null !== $missing ) {
            return $missing;
        }
        if ( ! current_user_can( 'smartslider' ) ) {
            return 'Smart Slider 3 does not give this account its menu (the smartslider capability), so it cannot open sliders in wp-admin either.';
        }
        foreach ( $capabilities as $capability ) {
            if ( \Nextend\Framework\Acl\Acl::canDo( $capability, self::helper() ) ) {
                continue;
            }
            if ( current_user_can( $capability ) ) {
                return sprintf( 'Smart Slider 3 also needs unfiltered_html for %s, which WordPress does not give this account (none while DISALLOW_UNFILTERED_HTML is set; on a network, super admins only).', $capability );
            }
            return sprintf( 'Smart Slider 3 does not give this account the %s capability, so it cannot do this in wp-admin either.', $capability );
        }
        return null;
    }

    public static function require_access( array $capabilities ): void {
        $refusal = self::refusal( $capabilities );
        if ( null !== $refusal ) {
            throw new \RuntimeException( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    public static function sliders( string $status ): array {
        $rows = self::sliders_model()->getAll( '*', $status );
        $seen = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            if ( is_array( $row ) && isset( $row['id'] ) ) {
                $seen[ (int) $row['id'] ] = $row;
            }
        }
        return array_values( $seen );
    }

    public static function slider( int $id ): ?array {
        $row = $id > 0 ? self::sliders_model()->get( $id ) : null;
        return is_array( $row ) && ! empty( $row['id'] ) ? $row : null;
    }

    public static function group_members( int $group_id ): array {
        $xref    = new \Nextend\SmartSlider3\Application\Model\ModelSlidersXRef( self::helper() );
        $rows    = $xref->getSliders( $group_id, 'published' );
        $members = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $slider = is_array( $row ) && isset( $row['slider_id'] ) ? self::slider( (int) $row['slider_id'] ) : null;
            if ( null !== $slider ) {
                $members[] = $slider;
            }
        }
        return $members;
    }

    public static function slides( int $slider_id ): array {
        $rows = self::slides_model()->getAll( $slider_id );
        return array_values( array_filter( is_array( $rows ) ? $rows : array(), 'is_array' ) );
    }

    public static function slide( int $id ): ?array {
        $row = $id > 0 ? self::slides_model()->get( $id ) : null;
        return is_array( $row ) && ! empty( $row['id'] ) ? $row : null;
    }

    public static function require_slide( int $id ): array {
        $slide = self::slide( $id );
        if ( null === $slide ) {
            throw new \InvalidArgumentException( sprintf( 'Smart Slider 3 has no slide %d. wp_smartslider_get_slider lists a slider\'s slides.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $slide;
    }

    public static function layers( array $slide ): array {
        $layers = json_decode( (string) ( $slide['slide'] ?? '' ), true );
        return is_array( $layers ) ? $layers : array();
    }

    public static function params( array $slide ): array {
        $params = json_decode( (string) ( $slide['params'] ?? '' ), true );
        return is_array( $params ) ? $params : array();
    }

    public static function version( array $slide ): string {
        $columns = array();
        foreach ( self::SAVED_COLUMNS as $column ) {
            $columns[] = (string) ( $slide[ $column ] ?? '' );
        }
        return hash( 'sha256', implode( "\0", $columns ) );
    }

    public static function layer_nodes( array $nodes, string $prefix = '' ): array {
        $found = array();
        foreach ( $nodes as $index => $node ) {
            if ( ! is_int( $index ) || $index < 0 || ! is_array( $node ) ) {
                continue;
            }
            $address = '' === $prefix ? (string) $index : $prefix . '.' . $index;
            if ( 'layer' === ( $node['type'] ?? 'layer' ) ) {
                $found[] = array(
                    'address' => $address,
                    'layer'   => $node,
                );
            }
            $children = self::children( $node );
            if ( null !== $children ) {
                $found = array_merge( $found, self::layer_nodes( $node[ $children ], $address ) );
            }
        }
        return $found;
    }

    public static function children( array $node ): ?string {
        $key = 'row' === ( $node['type'] ?? '' ) ? 'cols' : 'layers';
        return isset( $node[ $key ] ) && is_array( $node[ $key ] ) ? $key : null;
    }

    public static function item_path( array $layer ): array {
        if ( ! empty( $layer['item'] ) && is_array( $layer['item'] ) ) {
            return array( 'item' );
        }
        if ( isset( $layer['items'][0] ) && is_array( $layer['items'][0] ) ) {
            return array( 'items', 0 );
        }
        return array();
    }

    public static function item( array $layer ): array {
        $path = self::item_path( $layer );
        if ( array() === $path ) {
            return array();
        }
        return 'item' === $path[0] ? $layer['item'] : $layer['items'][0];
    }

    public static function item_type( array $item ): string {
        return isset( $item['type'] ) && is_string( $item['type'] ) ? $item['type'] : '';
    }

    public static function upgraded_values( string $type, array $values ): array {
        $items = \Nextend\SmartSlider3\Renderable\Item\ItemFactory::getItems();
        if ( ! isset( $items[ $type ] ) || ! is_object( $items[ $type ] ) || ! method_exists( $items[ $type ], 'upgradeData' ) ) {
            return $values;
        }
        $data = new \Nextend\Framework\Data\Data( $values );
        $items[ $type ]->upgradeData( $data );
        return $data->toArray();
    }

    public static function editable_fields( string $type, array $values ): array {
        $fields = array();
        foreach ( self::EDITABLE[ $type ] ?? array() as $field ) {
            if ( isset( self::DEVICE_SWITCHES[ $field ] ) && empty( $values[ self::DEVICE_SWITCHES[ $field ] ] ) ) {
                continue;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    public static function is_adjusted_on_load( array $slide ): bool {
        $version = self::params( $slide )['version'] ?? '';
        return ! is_string( $version ) || '' === $version || version_compare( $version, self::ADJUSTED_BEFORE, '<' );
    }

    public static function item_labels(): array {
        $labels = array();
        foreach ( \Nextend\SmartSlider3\Renderable\Item\ItemFactory::getItems() as $type => $item ) {
            if ( is_object( $item ) && method_exists( $item, 'getTitle' ) ) {
                $labels[ (string) $type ] = (string) $item->getTitle();
            }
        }
        return $labels;
    }

    public static function save( array $slide, array $fields, array $layers ): void {
        $params = self::params( $slide );
        $guides = isset( $params['guides'] ) && is_string( $params['guides'] ) ? $params['guides'] : '';
        unset( $params['guides'] );
        $data = array_merge(
            $params,
            array(
                'title'        => (string) $slide['title'],
                'description'  => (string) $slide['description'],
                'thumbnail'    => (string) $slide['thumbnail'],
                'published'    => (int) $slide['published'],
                'publish_up'   => (string) $slide['publish_up'],
                'publish_down' => (string) $slide['publish_down'],
                'layers'       => $layers,
            ),
            $fields
        );
        $json = wp_json_encode( $data );
        if ( false === $json ) {
            throw new \RuntimeException( 'The slide could not be encoded for Smart Slider 3, so nothing was changed.' );
        }
        $id = (int) $slide['id'];
        self::as_admin_request(
            $slide,
            static function () use ( $id, $json, $guides ) {
                self::vendor_write(
                    static function () use ( $id, $json, $guides ) {
                        self::slides_model()->save( $id, \Nextend\Framework\Misc\Base64::encode( $json ), $guides );
                    }
                );
            }
        );
    }

    public static function order( int $slider_id, array $slide_ids ): void {
        self::vendor_write(
            static function () use ( $slider_id, $slide_ids ) {
                self::slides_model()->order( $slider_id, $slide_ids );
            }
        );
    }

    public static function duplicate( int $slide_id ): int {
        $copy = 0;
        self::vendor_write(
            static function () use ( $slide_id, &$copy ) {
                $copy = (int) self::slides_model()->copyTo( $slide_id );
            }
        );
        return $copy;
    }

    public static function delete( int $slide_id ): void {
        self::vendor_write(
            static function () use ( $slide_id ) {
                self::slides_model()->delete( $slide_id );
            }
        );
    }

    private static function vendor_write( callable $call ): void {
        $mark = static function ( $post_id ): void {
            if ( self::fired_by_smart_slider() ) {
                \Easy_MCP_AI\History\Change_Context::expect_refresh_only( 'post', (int) $post_id, 'save_post' );
            }
        };
        add_action( 'save_post', $mark, PHP_INT_MIN );
        try {
            $call();
        } finally {
            remove_action( 'save_post', $mark, PHP_INT_MIN );
        }
    }

    private static function fired_by_smart_slider(): bool {
        $frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::HOOK_FRAMES ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- a bounded caller check, nothing is logged.
        $count  = count( $frames );
        for ( $i = 2; $i < $count; $i++ ) {
            if ( isset( $frames[ $i ]['class'] ) ? 'WP_Hook' === $frames[ $i ]['class'] : in_array( $frames[ $i ]['function'], array( 'do_action', 'call_user_func', 'call_user_func_array' ), true ) ) {
                continue;
            }
            return $i > 2 && isset( $frames[ $i ]['class'] ) && self::REFRESHER === ltrim( $frames[ $i ]['class'], '\\' );
        }
        return false;
    }

    private static function as_admin_request( array $slide, callable $call ): void {
        $request = \Nextend\Framework\Request\Request::$REQUEST;
        if ( ! is_object( $request ) || ! property_exists( $request, 'storage' ) || ! method_exists( $request, 'set' ) ) {
            throw new \RuntimeException( 'Smart Slider 3\'s request store is not loaded, so nothing was changed.' );
        }
        $before = $request->storage;
        try {
            $request->set( 'sliderid', (int) $slide['slider'] );
            $request->set( 'slideid', (int) $slide['id'] );
            $call();
        } finally {
            $request->storage = $before;
        }
    }

    private static function helper(): object {
        return \Nextend\SmartSlider3\Application\ApplicationSmartSlider3::getInstance()->getApplicationTypeAdmin();
    }

    private static function sliders_model(): object {
        return new \Nextend\SmartSlider3\Application\Model\ModelSliders( self::helper() );
    }

    private static function slides_model(): object {
        return new \Nextend\SmartSlider3\Application\Model\ModelSlides( self::helper() );
    }
}
