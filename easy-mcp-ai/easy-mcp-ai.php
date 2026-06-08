<?php
/**
 * Plugin Name: Easy MCP AI – Claude, ChatGPT & SEO Data Connector
 * Plugin URI:  https://easymcpai.com
 * Description: Connect Claude, ChatGPT & any AI to WordPress. Manage your entire site by chat — content, media, GA4, Search Console, SEO & more. 223 tools. Free.
 * Version:     1.7.2
 * Author:      EasyMCPAI
 * Author URI:
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easy-mcp-ai
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EASY_MCP_AI_VERSION', '1.7.2' );
define( 'EASY_MCP_AI_PLUGIN_FILE', __FILE__ );
define( 'EASY_MCP_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EASY_MCP_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EASY_MCP_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Easy_MCP_AI\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Easy_MCP_AI\\Deactivator', 'deactivate' ) );

Easy_MCP_AI\Plugin::instance();

add_filter(
    'plugin_action_links_' . EASY_MCP_AI_PLUGIN_BASENAME,
    function ( $links ) {
        $prepend = array(
            'dashboard' => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai' ) ) . '">' . esc_html__( 'Getting Started', 'easy-mcp-ai' ) . '</a>',
            'plugins'   => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-plugin-integrations' ) ) . '">' . esc_html__( 'Plugin', 'easy-mcp-ai' ) . '</a>',
            'abilities'      => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-abilities' ) ) . '">' . esc_html__( 'Abilities', 'easy-mcp-ai' ) . '</a>',
            'external_data'  => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ) . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>',
        );
        $append = array(
            'settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'easy-mcp-ai' ) . '</a>',
        );
        return array_merge( $prepend, $links, $append );
    }
);

add_filter(
    'plugin_row_meta',
    function ( $links, $file ) {
        if ( EASY_MCP_AI_PLUGIN_BASENAME !== $file ) {
            return $links;
        }
        $links[] = '<a href="https://wordpress.org/support/plugin/easy-mcp-ai/reviews/" target="_blank" rel="noopener">' . esc_html__( 'Rate Plugin', 'easy-mcp-ai' ) . '</a>';
        return $links;
    },
    10,
    2
);
