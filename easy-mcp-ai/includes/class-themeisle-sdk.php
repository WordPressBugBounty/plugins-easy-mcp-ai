<?php






namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}






























final class Themeisle_SDK {

    
    const PRODUCT_SLUG = 'easy-mcp-ai';

    
    const PRODUCT_KEY = 'easy_mcp_ai';

    
    const ABOUT_US_MENU_SLUG = 'ti-about-easy_mcp_ai';

    
    const MENU_SLUG = 'easy-mcp-ai';

    




    private static $basefile = '';

    





    public static function register( $basefile ) {
        self::$basefile = (string) $basefile;

        \add_filter( 'themeisle_sdk_products', array( __CLASS__, 'register_product' ) );

        foreach ( self::policy() as $hook => $callback ) {
            \add_filter( $hook, $callback );
        }

        \add_filter( self::PRODUCT_KEY . '_about_us_metadata', array( __CLASS__, 'about_us_metadata' ) );
        \add_action( 'admin_menu', array( __CLASS__, 'move_about_us_submenu_last' ), 999 );
    }

    








    public static function move_about_us_submenu_last() {
        global $submenu;

        if ( ! is_array( $submenu ) || empty( $submenu[ self::MENU_SLUG ] ) || ! is_array( $submenu[ self::MENU_SLUG ] ) ) {
            return;
        }

        $items = $submenu[ self::MENU_SLUG ];
        $about = array();
        foreach ( $items as $index => $item ) {
            if ( isset( $item[2] ) && self::ABOUT_US_MENU_SLUG === $item[2] ) {
                $about[] = $item;
                unset( $items[ $index ] );
            }
        }

        if ( empty( $about ) ) {
            return;
        }

        $submenu[ self::MENU_SLUG ] = array_merge( array_values( $items ), $about );
    }

    













    public static function about_us_metadata( $data = array() ) {
        return array(
            'location'         => self::MENU_SLUG,
            'logo'             => \plugin_dir_url( self::$basefile ) . 'assets/images/icon-256x256.png',
            'has_upgrade_menu' => false,
        );
    }

    





    public static function register_product( $products ) {
        $products   = is_array( $products ) ? $products : array();
        $products[] = self::$basefile;

        return $products;
    }

    




    public static function policy() {
        return array(
            
            
            
            
            
            'themeisle_sdk_ran_promos'                    => '__return_true',

            
            
            
            'themeisle_sdk_hide_notifications'            => '__return_true',

            
            
            
            'themeisle_sdk_blackfriday_data'              => '__return_empty_array',

            
            
            
            self::PRODUCT_SLUG . '_sdk_should_review'     => '__return_false',
            self::PRODUCT_SLUG . '_sdk_enable_translate'  => '__return_false',
            self::PRODUCT_SLUG . '_sdk_enable_logger'     => '__return_false',

            
            self::PRODUCT_SLUG . '_load_dashboard_widget' => '__return_false',
        );
    }
}
