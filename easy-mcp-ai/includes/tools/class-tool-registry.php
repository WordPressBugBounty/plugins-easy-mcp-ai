<?php
namespace Easy_MCP_AI\Tools;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tool_Registry {

    private $tools = array();

    






    private $lazy_classes = array();

    public function register( Base_Tool $tool ) {
        $this->tools[ $tool->get_name() ] = $tool;
    }

    public function get_tool( $name ) {
        if ( isset( $this->tools[ $name ] ) ) {
            return $this->tools[ $name ];
        }
        
        
        
        foreach ( $this->lazy_classes as $key => $class ) {
            $instance  = new $class();
            $tool_name = $instance->get_name();
            $this->tools[ $tool_name ] = $instance;
            unset( $this->lazy_classes[ $key ] );
            if ( $tool_name === $name ) {
                return $instance;
            }
        }
        return null;
    }

    public function get_all_definitions() {
        $this->materialize_all();
        $definitions = array();
        foreach ( $this->tools as $tool ) {
            $definitions[] = $tool->get_definition();
        }
        return $definitions;
    }

    public function get_all_tool_names() {
        $this->materialize_all();
        return array_keys( $this->tools );
    }

    public function get_tools_by_category() {
        $this->materialize_all();
        $categorized = array();
        foreach ( $this->tools as $tool ) {
            $category = $tool->get_category();
            if ( ! isset( $categorized[ $category ] ) ) {
                $categorized[ $category ] = array();
            }
            $categorized[ $category ][] = $tool->get_definition();
        }
        return $categorized;
    }

    private function materialize_all() {
        foreach ( $this->lazy_classes as $key => $class ) {
            $instance = new $class();
            $this->tools[ $instance->get_name() ] = $instance;
            unset( $this->lazy_classes[ $key ] );
        }
    }

    public function auto_discover() {
        $tool_classes = array(
            'Easy_MCP_AI\\Tools\\Posts\\List_Posts',
            'Easy_MCP_AI\\Tools\\Posts\\Get_Post',
            'Easy_MCP_AI\\Tools\\Posts\\Create_Post',
            'Easy_MCP_AI\\Tools\\Posts\\Update_Post',
            'Easy_MCP_AI\\Tools\\Posts\\Delete_Post',
            'Easy_MCP_AI\\Tools\\Posts\\Search_Posts',
            'Easy_MCP_AI\\Tools\\Pages\\List_Pages',
            'Easy_MCP_AI\\Tools\\Pages\\Get_Page',
            'Easy_MCP_AI\\Tools\\Pages\\Create_Page',
            'Easy_MCP_AI\\Tools\\Pages\\Update_Page',
            'Easy_MCP_AI\\Tools\\Pages\\Delete_Page',
            'Easy_MCP_AI\\Tools\\Media\\List_Media',
            'Easy_MCP_AI\\Tools\\Media\\Get_Media',
            'Easy_MCP_AI\\Tools\\Media\\Upload_Media',
            'Easy_MCP_AI\\Tools\\Media\\Update_Media',
            'Easy_MCP_AI\\Tools\\Media\\Delete_Media',
            'Easy_MCP_AI\\Tools\\Taxonomy\\List_Categories',
            'Easy_MCP_AI\\Tools\\Taxonomy\\Get_Category',
            'Easy_MCP_AI\\Tools\\Taxonomy\\Create_Category',
            'Easy_MCP_AI\\Tools\\Taxonomy\\Update_Category',
            'Easy_MCP_AI\\Tools\\Taxonomy\\Delete_Category',
            'Easy_MCP_AI\\Tools\\Taxonomy\\List_Tags',
            'Easy_MCP_AI\\Tools\\Taxonomy\\Get_Tag',
            'Easy_MCP_AI\\Tools\\Taxonomy\\Create_Tag',
            'Easy_MCP_AI\\Tools\\Taxonomy\\Update_Tag',
            'Easy_MCP_AI\\Tools\\Taxonomy\\Delete_Tag',
            'Easy_MCP_AI\\Tools\\Comments\\List_Comments',
            'Easy_MCP_AI\\Tools\\Comments\\Get_Comment',
            'Easy_MCP_AI\\Tools\\Comments\\Create_Comment',
            'Easy_MCP_AI\\Tools\\Comments\\Update_Comment',
            'Easy_MCP_AI\\Tools\\Comments\\Delete_Comment',
            'Easy_MCP_AI\\Tools\\Users\\List_Users',
            'Easy_MCP_AI\\Tools\\Users\\Get_User',
            'Easy_MCP_AI\\Tools\\Users\\Create_User',
            'Easy_MCP_AI\\Tools\\Users\\Update_User',
            'Easy_MCP_AI\\Tools\\Users\\Delete_User',
            'Easy_MCP_AI\\Tools\\Site\\Get_Site_Settings',
            'Easy_MCP_AI\\Tools\\Site\\Update_Site_Settings',
            'Easy_MCP_AI\\Tools\\Site\\Get_Post_Types',
            'Easy_MCP_AI\\Tools\\Site\\Get_Taxonomies',
            'Easy_MCP_AI\\Tools\\Menus\\List_Menus',
            'Easy_MCP_AI\\Tools\\Menus\\Get_Menu',
            'Easy_MCP_AI\\Tools\\Menus\\List_Menu_Items',
            'Easy_MCP_AI\\Tools\\Menus\\Create_Menu_Item',
            'Easy_MCP_AI\\Tools\\Plugins\\List_Plugins',
            'Easy_MCP_AI\\Tools\\Themes\\List_Themes',
            'Easy_MCP_AI\\Tools\\Themes\\Get_Active_Theme',
            
            'Easy_MCP_AI\\Tools\\Revisions\\List_Revisions',
            'Easy_MCP_AI\\Tools\\Revisions\\Get_Revision',
            'Easy_MCP_AI\\Tools\\Revisions\\Delete_Revision',
            
            'Easy_MCP_AI\\Tools\\Meta\\Get_Post_Meta',
            'Easy_MCP_AI\\Tools\\Meta\\Update_Post_Meta',
            
            'Easy_MCP_AI\\Tools\\Search\\Search',
            
            'Easy_MCP_AI\\Tools\\Blocks\\List_Blocks',
            'Easy_MCP_AI\\Tools\\Blocks\\Get_Block',
            'Easy_MCP_AI\\Tools\\Blocks\\Create_Block',
            'Easy_MCP_AI\\Tools\\Blocks\\Update_Block',
            'Easy_MCP_AI\\Tools\\Blocks\\Delete_Block',
            
            'Easy_MCP_AI\\Tools\\Site\\Get_Post_Statuses',
            
            'Easy_MCP_AI\\Tools\\CPT\\List_CPT_Items',
            'Easy_MCP_AI\\Tools\\CPT\\Get_CPT_Item',
            'Easy_MCP_AI\\Tools\\CPT\\Create_CPT_Item',
            'Easy_MCP_AI\\Tools\\CPT\\Update_CPT_Item',
            'Easy_MCP_AI\\Tools\\CPT\\Delete_CPT_Item',
            
            'Easy_MCP_AI\\Tools\\Menus\\Create_Menu',
            'Easy_MCP_AI\\Tools\\Menus\\Update_Menu',
            'Easy_MCP_AI\\Tools\\Menus\\Delete_Menu',
            'Easy_MCP_AI\\Tools\\Menus\\Update_Menu_Item',
            'Easy_MCP_AI\\Tools\\Menus\\Delete_Menu_Item',
            
            'Easy_MCP_AI\\Tools\\Templates\\List_Templates',
            'Easy_MCP_AI\\Tools\\Templates\\Get_Template',
            'Easy_MCP_AI\\Tools\\Templates\\Update_Template',
            
            'Easy_MCP_AI\\Tools\\Styles\\Get_Global_Styles',
            'Easy_MCP_AI\\Tools\\Styles\\Update_Global_Styles',
            
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Products',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Get_Product',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Create_Product',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Update_Product',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Delete_Product',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Product_Variations',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Create_Product_Variation',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Product_Categories',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Orders',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Get_Order',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Create_Order',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Update_Order',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Order_Notes',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Create_Order_Note',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Order_Refunds',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Customers',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Get_Customer',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Create_Customer',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Update_Customer',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Delete_Customer',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Coupons',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Create_Coupon',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Update_Coupon',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Delete_Coupon',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Report_Sales',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Report_Top_Sellers',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Report_Orders',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Report_Products',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Report_Customers',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Webhooks',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Create_Webhook',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Update_Webhook',
            'Easy_MCP_AI\\Tools\\WooCommerce\\Delete_Webhook',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Shipping_Zones',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Shipping_Methods',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Tax_Rates',
            'Easy_MCP_AI\\Tools\\WooCommerce\\List_Payment_Gateways',
            
            'Easy_MCP_AI\\Tools\\ACF\\Get_Fields',
            'Easy_MCP_AI\\Tools\\ACF\\Update_Fields',
            'Easy_MCP_AI\\Tools\\ACF\\Get_User_Fields',
            'Easy_MCP_AI\\Tools\\ACF\\Update_User_Fields',
            'Easy_MCP_AI\\Tools\\ACF\\Get_Term_Fields',
            'Easy_MCP_AI\\Tools\\ACF\\List_Field_Groups',
            
            'Easy_MCP_AI\\Tools\\Events_Calendar\\List_Events',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\Get_Event',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\Create_Event',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\Update_Event',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\Delete_Event',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\List_Venues',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\Get_Venue',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\List_Organizers',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\Create_Venue',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\Create_Organizer',
            
            'Easy_MCP_AI\\Tools\\BuddyPress\\List_Members',
            'Easy_MCP_AI\\Tools\\BuddyPress\\Get_Member',
            'Easy_MCP_AI\\Tools\\BuddyPress\\List_Activity',
            'Easy_MCP_AI\\Tools\\BuddyPress\\Create_Activity',
            'Easy_MCP_AI\\Tools\\BuddyPress\\Delete_Activity',
            'Easy_MCP_AI\\Tools\\BuddyPress\\List_Groups',
            'Easy_MCP_AI\\Tools\\BuddyPress\\Get_Group',
            'Easy_MCP_AI\\Tools\\BuddyPress\\List_Group_Members',
            'Easy_MCP_AI\\Tools\\BuddyPress\\List_Message_Threads',
            'Easy_MCP_AI\\Tools\\BuddyPress\\Get_Message_Thread',
            
            'Easy_MCP_AI\\Tools\\SEO\\Yoast_Get_Head',
            'Easy_MCP_AI\\Tools\\SEO\\Yoast_Get_Post_Seo',
            'Easy_MCP_AI\\Tools\\SEO\\Yoast_Update_Post_Seo',
            'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Get_Head',
            'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Get_Post_Seo',
            'Easy_MCP_AI\\Tools\\SEO\\Rankmath_Update_Post_Seo',
            'Easy_MCP_AI\\Tools\\SEO\\Aioseo_Get_Post_Seo',
            'Easy_MCP_AI\\Tools\\SEO\\Aioseo_Update_Post_Seo',
            'Easy_MCP_AI\\Tools\\GSC\\List_Sites',
            'Easy_MCP_AI\\Tools\\GSC\\Get_Site',
            'Easy_MCP_AI\\Tools\\GSC\\Query_Performance',
            'Easy_MCP_AI\\Tools\\GSC\\List_Sitemaps',
            'Easy_MCP_AI\\Tools\\GSC\\Get_Sitemap',
            'Easy_MCP_AI\\Tools\\GSC\\Inspect_Url',

            'Easy_MCP_AI\\Tools\\GA\\List_Account_Summaries',
            'Easy_MCP_AI\\Tools\\GA\\Get_Property',
            'Easy_MCP_AI\\Tools\\GA\\List_Data_Streams',
            'Easy_MCP_AI\\Tools\\GA\\List_Custom_Dimensions',
            'Easy_MCP_AI\\Tools\\GA\\List_Custom_Metrics',
            'Easy_MCP_AI\\Tools\\GA\\List_Conversion_Events',
            'Easy_MCP_AI\\Tools\\GA\\Get_Metadata',
            'Easy_MCP_AI\\Tools\\GA\\Run_Report',
            'Easy_MCP_AI\\Tools\\GA\\Run_Pivot_Report',
            'Easy_MCP_AI\\Tools\\GA\\Run_Realtime_Report',
            'Easy_MCP_AI\\Tools\\GA\\Check_Compatibility',

            
            'Easy_MCP_AI\\Tools\\DFS\\Serp_Google_Organic_Live',
            'Easy_MCP_AI\\Tools\\DFS\\Keywords_Search_Volume_Live',
            'Easy_MCP_AI\\Tools\\DFS\\Backlinks_Referring_Domains_Live',
            'Easy_MCP_AI\\Tools\\DFS\\On_Page_Instant_Pages',
            'Easy_MCP_AI\\Tools\\DFS\\Account_Balance',
            'Easy_MCP_AI\\Tools\\DFS\\Labs_Keywords_For_Site_Live',
            'Easy_MCP_AI\\Tools\\DFS\\Labs_Ranked_Keywords_Live',
            'Easy_MCP_AI\\Tools\\DFS\\Backlinks_Summary_Live',

            
            'Easy_MCP_AI\\Tools\\Semrush\\Domain_Overview',
            'Easy_MCP_AI\\Tools\\Semrush\\Domain_Organic_Keywords',
            'Easy_MCP_AI\\Tools\\Semrush\\Competitors_Organic',
            'Easy_MCP_AI\\Tools\\Semrush\\Keyword_Overview',
            'Easy_MCP_AI\\Tools\\Semrush\\Related_Keywords',
            'Easy_MCP_AI\\Tools\\Semrush\\Keyword_Difficulty',
            'Easy_MCP_AI\\Tools\\Semrush\\Phrase_Questions',
            'Easy_MCP_AI\\Tools\\Semrush\\Backlinks_Overview',
            'Easy_MCP_AI\\Tools\\Semrush\\Backlinks',
            'Easy_MCP_AI\\Tools\\Semrush\\Referring_Domains',
            'Easy_MCP_AI\\Tools\\Semrush\\Anchors',
            'Easy_MCP_AI\\Tools\\Semrush\\Url_Organic_Keywords',
            'Easy_MCP_AI\\Tools\\Semrush\\Api_Units_Balance',

        );
        $plugin_namespaces = array(
            'Easy_MCP_AI\\Tools\\WooCommerce\\',
            'Easy_MCP_AI\\Tools\\ACF\\',
            'Easy_MCP_AI\\Tools\\Events_Calendar\\',
            'Easy_MCP_AI\\Tools\\BuddyPress\\',
            'Easy_MCP_AI\\Tools\\SEO\\',
            'Easy_MCP_AI\\Tools\\GSC\\',
            'Easy_MCP_AI\\Tools\\GA\\',
            'Easy_MCP_AI\\Tools\\DFS\\',
            'Easy_MCP_AI\\Tools\\Semrush\\',
        );

        foreach ( $tool_classes as $class ) {
            if ( ! class_exists( $class ) ) {
                $is_plugin_class = false;
                foreach ( $plugin_namespaces as $ns ) {
                    if ( strncmp( $class, $ns, strlen( $ns ) ) === 0 ) {
                        $is_plugin_class = true;
                        break;
                    }
                }
                if ( ! $is_plugin_class ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( 'Easy MCP AI: Tool class not found, skipping: ' . $class );
                }
                continue;
            }
            $this->lazy_classes[] = $class;
        }
    }
}
