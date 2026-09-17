<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function easy_mcp_ai_view_settings( $settings, $all_tool_names, $message, $ip_invalid = '' ) {
?>
<div class="wrap wp-mcp-admin">
    <h1><?php esc_html_e( 'Easy MCP AI - Settings', 'easy-mcp-ai' ); ?></h1>

    <?php include __DIR__ . '/partials/page-nav.php'; ?>

    <p class="description" style="margin: 8px 0 16px; font-size: 13px;">
        <?php
        printf(
            /* translators: %s: link to the dashboard page with the AI client setup guide. */
            esc_html__( 'To connect an MCP client, see the %s.', 'easy-mcp-ai' ),
            '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai' ) ) . '">' . esc_html__( 'setup guide', 'easy-mcp-ai' ) . '</a>'
        );
        ?>
    </p>

    <?php if ( 'saved' === $message ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'easy-mcp-ai' ); ?></p></div>
    <?php endif; ?>

    <?php
    $ip_invalid_raw = $ip_invalid;
    if ( ! empty( $ip_invalid_raw ) ) :
        $ip_invalid_entries = array_filter( array_map( 'trim', explode( ',', $ip_invalid_raw ) ) );
        if ( ! empty( $ip_invalid_entries ) ) :
    ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <?php esc_html_e( 'The following IP whitelist entries were invalid and have been removed:', 'easy-mcp-ai' ); ?>
                <?php foreach ( $ip_invalid_entries as $i => $inv ) : ?><?php if ( $i > 0 ) : ?>, <?php endif; ?><code><?php echo esc_html( $inv ); ?></code><?php endforeach; ?>
            </p>
        </div>
    <?php endif; endif; ?>


    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=easy-mcp-ai-settings' ) ); ?>">
        <?php wp_nonce_field( 'easy_mcp_ai_save_settings' ); ?>
        <input type="hidden" name="easy_mcp_ai_save_settings" value="1">

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="rate_limit_per_minute"><?php esc_html_e( 'Rate Limit (per minute)', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <input type="number" id="rate_limit_per_minute" name="rate_limit_per_minute" min="1" max="1000"
                        value="<?php echo esc_attr( $settings['rate_limit_per_minute'] ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Maximum number of tool calls per token per minute.', 'easy-mcp-ai' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="oauth_min_capability"><?php esc_html_e( 'Minimum Capability to Authorize (OAuth)', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <?php
                    $oauth_cap_choices  = \Easy_MCP_AI\Admin\Admin_Page::oauth_min_capability_choices();
                    
                    
                    $oauth_cap_selected = \Easy_MCP_AI\Admin\Admin_Page::sanitize_oauth_min_capability( isset( $settings['oauth_min_capability'] ) ? $settings['oauth_min_capability'] : '' );
                    ?>
                    <select id="oauth_min_capability" name="oauth_min_capability">
                        <?php foreach ( $oauth_cap_choices as $cap => $label ) : ?>
                            <option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $cap, $oauth_cap_selected ); ?>>
                                <?php echo esc_html( $label ); ?> <?php echo esc_html( '(' . $cap . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Minimum WordPress capability a user must have to authorize an MCP client through the OAuth consent screen. The floor is Author (publish_posts) — you can only raise it. This is capability-based, so any custom role that grants the selected capability also qualifies. Does not affect creating tokens from this dashboard (that stays admin-only).', 'easy-mcp-ai' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="external_data_min_capability">
                        <?php
                        
                        
                        $ext_data_link = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ) . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>';
                        echo wp_kses(
                            sprintf(
                                /* translators: %s: the words "External Data", linked to the External Data admin page. */
                                __( 'Minimum Capability for %s Tools', 'easy-mcp-ai' ),
                                $ext_data_link
                            ),
                            array( 'a' => array( 'href' => array() ) )
                        );
                        ?>
                    </label>
                </th>
                <td>
                    <?php
                    $ext_cap_choices  = \Easy_MCP_AI\Admin\Admin_Page::external_data_min_capability_choices();
                    
                    
                    $ext_cap_selected = \Easy_MCP_AI\Admin\Admin_Page::sanitize_external_data_min_capability( isset( $settings['external_data_min_capability'] ) ? $settings['external_data_min_capability'] : '' );
                    ?>
                    <select id="external_data_min_capability" name="external_data_min_capability">
                        <?php foreach ( $ext_cap_choices as $cap => $label ) : ?>
                            <option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $cap, $ext_cap_selected ); ?>>
                                <?php echo esc_html( $label ); ?> <?php echo esc_html( '(' . $cap . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Minimum WordPress capability required to use the Google Analytics, Search Console, DataForSEO, Semrush, and SE Ranking tools. These default to Administrators only; lower this to let Editors or Authors use them. Tools a user cannot call are also hidden from the MCP client\'s tool list. (Ahrefs is unaffected and stays available to any authorized user.)', 'easy-mcp-ai' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Force Draft on Create', 'easy-mcp-ai' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="force_draft_on_create" value="1"
                            <?php checked( $settings['force_draft_on_create'], true ); ?>>
                        <?php esc_html_e( 'Always save new posts and pages as draft, regardless of the status requested by the MCP tool.', 'easy-mcp-ai' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="max_title_length"><?php esc_html_e( 'Max Title Length (characters)', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <input type="number" id="max_title_length" name="max_title_length" min="0" max="2000"
                        value="<?php echo esc_attr( $settings['max_title_length'] ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Maximum character length for post and page titles. Set to 0 to disable the limit. If a title exceeds this length the tool returns an error.', 'easy-mcp-ai' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Audit Log', 'easy-mcp-ai' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="audit_log_enabled" value="1"
                            <?php checked( $settings['audit_log_enabled'], true ); ?>>
                        <?php esc_html_e( 'Enable audit logging of all tool calls. When disabled, no log entries are written.', 'easy-mcp-ai' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="audit_log_retention"><?php esc_html_e( 'Audit Log Retention (days)', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <input type="number" id="audit_log_retention" name="audit_log_retention" min="1" max="365"
                        value="<?php echo esc_attr( $settings['audit_log_retention'] ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Older audit log entries are pruned daily by cron.', 'easy-mcp-ai' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Change History', 'easy-mcp-ai' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="change_log_enabled" value="1"
                            <?php checked( $settings['change_log_enabled'], true ); ?>>
                        <?php esc_html_e( 'Record before/after snapshots of every write performed via MCP. When disabled, no change-history entries are written.', 'easy-mcp-ai' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="change_log_retention"><?php esc_html_e( 'Change History Retention (days)', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <input type="number" id="change_log_retention" name="change_log_retention" min="1" max="3650"
                        value="<?php echo esc_attr( $settings['change_log_retention'] ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Older change-history rows are pruned daily by cron.', 'easy-mcp-ai' ); ?></p>
                    <?php ?>
                    <p><?php echo wp_kses_post( \Easy_MCP_AI\Admin\History_Settings_Page::settings_link_html() ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Disabled Tools', 'easy-mcp-ai' ); ?></th>
                <td>
                    <?php
                    
                    
                    
                    
                    
                    $disableable_tools = array(
                        
                        'wp_delete_post',
                        'wp_delete_page',
                        'wp_delete_media',
                        'wp_delete_comment',
                        'wp_delete_category',
                        'wp_delete_tag',
                        'wp_delete_block',
                        'wp_delete_cpt_item',
                        'wp_delete_menu',
                        'wp_delete_menu_item',
                        'wp_delete_revision',
                        'wp_delete_user_meta',
                        
                        'wp_create_user',
                        'wp_update_user',
                        'wp_delete_user',
                        'wp_update_user_meta',
                        'wp_update_site_settings',
                        'wp_update_template',
                        'wp_update_global_styles',
                    );
                    ?>
                    <div class="wp-mcp-tool-grid">
                    <?php foreach ( $disableable_tools as $tool_name ) : ?>
                        <label class="wp-mcp-block-label">
                            <input type="checkbox" name="disabled_tools[]" value="<?php echo esc_attr( $tool_name ); ?>"
                                <?php checked( in_array( $tool_name, $settings['disabled_tools'], true ) ); ?>>
                            <code><?php echo esc_html( $tool_name ); ?></code>
                        </label>
                    <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'Checked tools are globally disabled and will return an error when called, regardless of token permissions.', 'easy-mcp-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="allowed_tool_patterns"><?php esc_html_e( 'Whitelist Tools', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <div class="notice notice-warning inline wp-mcp-notice-warning">
                        <strong><?php esc_html_e( '⚠️ Be careful:', 'easy-mcp-ai' ); ?></strong>
                        <?php esc_html_e( 'When set, only tools whose names match one of these glob patterns are accessible via MCP — for all tokens. This can break existing workflows. Leave empty to allow all tools.', 'easy-mcp-ai' ); ?>
                    </div>
                    <input type="text" id="allowed_tool_patterns" name="allowed_tool_patterns"
                        class="regular-text"
                        value="<?php echo esc_attr( implode( ', ', $settings['allowed_tool_patterns'] ) ); ?>"
                        placeholder="<?php esc_attr_e( 'e.g. wp_get_*, wp_list_*, wp_search_*', 'easy-mcp-ai' ); ?>">
                    <p class="description"><?php esc_html_e( 'Comma-separated glob patterns (e.g. wp_get_* or wp_list_*) matched against tool names using fnmatch(). A tool is accessible if its name matches any pattern. Use * as a wildcard.', 'easy-mcp-ai' ); ?></p>

                    <div class="wp-mcp-mt-14">
                    <?php
                    if ( empty( $settings['allowed_tool_patterns'] ) ) :
                    ?>
                        <p><?php
                    /* translators: %d: number of registered tools */
                    printf( esc_html__( 'All %d tools are available (no filter applied).', 'easy-mcp-ai' ), count( $all_tool_names ) ); ?></p>
                    <?php else :
                        $matching = array_values( array_filter( $all_tool_names, function ( $name ) use ( $settings ) {
                            foreach ( $settings['allowed_tool_patterns'] as $p ) {
                                $p = trim( $p );
                                if ( false === strpos( $p, '*' ) && false === strpos( $p, '?' ) ) {
                                    $p = '*' . $p . '*';
                                }
                                if ( fnmatch( $p, $name ) ) {
                                    return true;
                                }
                            }
                            return false;
                        } ) );
                        $blocked_count = count( $all_tool_names ) - count( $matching );
                    ?>
                        <p>
                            <strong><?php
                            /* translators: %d: number of available tools */
                            printf( esc_html__( '%d tools will be available', 'easy-mcp-ai' ), count( $matching ) ); ?></strong>
                            <?php if ( $blocked_count > 0 ) : ?>
                                <span class="wp-mcp-text-danger"> &mdash; <?php
                                /* translators: %d: number of blocked tools */
                                printf( esc_html__( '%d blocked', 'easy-mcp-ai' ), absint( $blocked_count ) ); ?></span>
                            <?php endif; ?>:
                        </p>
                        <?php if ( ! empty( $matching ) ) : ?>
                            <ul class="wp-mcp-column-list">
                                <?php foreach ( $matching as $tn ) : ?>
                                    <li><code><?php echo esc_html( $tn ); ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <p class="wp-mcp-text-danger"><strong><?php esc_html_e( 'Warning: No tools match the current filter. MCP clients will see an empty tool list.', 'easy-mcp-ai' ); ?></strong></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ip_whitelist"><?php esc_html_e( 'IP Whitelist', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <textarea id="ip_whitelist" name="ip_whitelist" rows="4" cols="50" class="large-text code"><?php echo esc_textarea( $settings['ip_whitelist'] ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One IP address or CIDR range per line (e.g., 203.0.113.10 or 192.168.1.0/24). Leave empty to allow all IPs.', 'easy-mcp-ai' ); ?></p>
                    <p class="description"><?php esc_html_e( 'Applies to every request on the MCP endpoint, whether it authenticates with an API key or an OAuth grant. A request from an address that is not listed is refused with 403 and recorded in the Audit Log. Cloud clients such as claude.ai and ChatGPT connect from the vendor\'s servers, not from the user\'s machine — for those users either list the vendor\'s IP ranges or leave this empty.', 'easy-mcp-ai' ); ?></p>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: the EASY_MCP_AI_TRUSTED_PROXIES constant name, 2: link to the trusted-proxies guide */
                            wp_kses( __( 'Behind a reverse proxy, load balancer or CDN such as Cloudflare, WordPress sees the proxy\'s address for every visitor, so this list, the rate limits and the Audit Log cannot tell visitors apart. Declare the proxy with the %1$s constant in wp-config.php — see %2$s.', 'easy-mcp-ai' ), array( 'code' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
                            '<code>EASY_MCP_AI_TRUSTED_PROXIES</code>',
                            '<a href="' . esc_url( \Easy_MCP_AI\Client_IP::DOC_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Trusted proxies', 'easy-mcp-ai' ) . '</a>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Save Settings', 'easy-mcp-ai' ) ); ?>
    </form>
</div>
<?php
}
easy_mcp_ai_view_settings( $settings, $all_tool_names, $message, $ip_invalid );
