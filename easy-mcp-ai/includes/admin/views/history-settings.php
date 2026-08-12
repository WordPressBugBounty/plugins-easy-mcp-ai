<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}








?>
<div class="wrap wp-mcp-admin">
    <h1><?php esc_html_e( 'Easy MCP AI - Change History Capture Settings', 'easy-mcp-ai' ); ?></h1>

    <p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=easy-mcp-ai-settings' ) ); ?>">&larr; <?php esc_html_e( 'Settings', 'easy-mcp-ai' ); ?></a>
        &nbsp;|&nbsp;
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=easy-mcp-ai-history' ) ); ?>"><?php esc_html_e( 'Change History log', 'easy-mcp-ai' ); ?></a>
    </p>

    <?php
    
    
    
    include __DIR__ . '/partials/history-limits.php';
    ?>

    <p class="description">
        <?php esc_html_e( 'Controls WHAT the change history captures. Turning capture on or off entirely, and how long rows are kept, stay on the main Settings page.', 'easy-mcp-ai' ); ?>
    </p>

    <form method="post">
        <?php wp_nonce_field( 'easy_mcp_ai_save_history_settings' ); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="easy_mcp_ai_change_log_option_mode"><?php esc_html_e( 'Option recording mode', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <select id="easy_mcp_ai_change_log_option_mode" name="easy_mcp_ai_change_log_option_mode">
                        <option value="all_except_denylist" <?php selected( $option_mode, 'all_except_denylist' ); ?>>
                            <?php esc_html_e( 'All options except churn (recommended)', 'easy-mcp-ai' ); ?>
                        </option>
                        <option value="allowlist" <?php selected( $option_mode, 'allowlist' ); ?>>
                            <?php esc_html_e( 'Allowlist only (legacy)', 'easy-mcp-ai' ); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Which wp_options (and network option) writes get a change-history row. The recommended mode records every option except known churn (transients, cron, scheduler noise); the legacy mode records only a small fixed allowlist of core settings.', 'easy-mcp-ai' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Extended meta capture', 'easy-mcp-ai' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="easy_mcp_ai_change_log_capture_meta" value="1" <?php checked( $capture_meta, true ); ?>>
                        <?php esc_html_e( 'Capture term, user and comment meta changes, and network (site) options.', 'easy-mcp-ai' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Post meta is always captured. Session tokens and transient-like user meta are never recorded.', 'easy-mcp-ai' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Capture raw database writes', 'easy-mcp-ai' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="easy_mcp_ai_change_log_capture_db" value="1" <?php checked( $capture_db, true ); ?>>
                        <?php esc_html_e( 'Record custom-table and raw SQL writes made during MCP tool calls (Layer 3 interceptor).', 'easy-mcp-ai' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Opt-in. Catches plugin writes that bypass WordPress hooks (custom tables, direct SQL). Sensitive tables (users, credentials) are never captured; sensitive columns are redacted. Only writes made through MCP tool calls are ever recorded.', 'easy-mcp-ai' ); ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e( 'Recommended if you run plugins that remove WordPress hooks before writing (SureCart does this). Their hooks are re-armed automatically and a "hooks reasserted" entry is logged, but the re-arm happens after the call, so with this setting off those writes leave no before/after record at all — only the notice that it happened.', 'easy-mcp-ai' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="easy_mcp_ai_change_log_db_retention"><?php esc_html_e( 'Raw-write retention (days)', 'easy-mcp-ai' ); ?></label>
                </th>
                <td>
                    <input type="number" id="easy_mcp_ai_change_log_db_retention" name="easy_mcp_ai_change_log_db_retention" min="0" max="3650"
                        value="<?php echo esc_attr( $db_retention ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Raw database rows are high-volume, so they are pruned on their own shorter schedule (default 7 days; 0 disables pruning). Semantic rows keep the main Change History retention.', 'easy-mcp-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Record external write intent', 'easy-mcp-ai' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="easy_mcp_ai_change_log_external_intent" value="1" <?php checked( $external_intent, true ); ?>>
                        <?php esc_html_e( 'When a write-shaped tool call produces no local database change but did contact a remote service, record a marker row.', 'easy-mcp-ai' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Covers plugins that store their data in an external SaaS. The marker shows "Remote change — local before/after not available" with the contacted hosts and redacted call arguments.', 'easy-mcp-ai' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <button type="submit" name="easy_mcp_ai_save_history_settings" value="1" class="button button-primary">
                <?php esc_html_e( 'Save Capture Settings', 'easy-mcp-ai' ); ?>
            </button>
        </p>
    </form>
</div>
