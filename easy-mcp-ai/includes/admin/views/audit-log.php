<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function easy_mcp_ai_view_audit_log( $total, $entries, $page, $total_pages, $message, $change_counts = array(), $changes_nonce = '', $audit_filters = array() ) {
    $audit_filters = array_merge( array( 'search' => '', 'tool_name' => '', 'wp_user_id' => 0, 'auth_source' => '', 'result_status' => '', 'since' => '', 'until' => '', 'tools' => array(), 'statuses' => array(), 'users' => array() ), (array) $audit_filters );
    $has_filter    = '' !== $audit_filters['search'] || '' !== $audit_filters['tool_name'] || $audit_filters['wp_user_id'] > 0 || '' !== $audit_filters['auth_source'] || '' !== $audit_filters['result_status'] || '' !== $audit_filters['since'] || '' !== $audit_filters['until'];
?>
<div class="wrap wp-mcp-admin">
    <h1><?php esc_html_e( 'Easy MCP AI - Audit Log', 'easy-mcp-ai' ); ?></h1>

    <?php include __DIR__ . '/partials/page-nav.php'; ?>

    <?php if ( 'cleaned' === $message ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Audit log entries have been cleaned up.', 'easy-mcp-ai' ); ?></p>
        </div>
    <?php elseif ( 'cleaned_more' === $message ) : ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e( 'Cleaned up 10,000 audit log entries. More likely remain — click the button again to drain the rest.', 'easy-mcp-ai' ); ?></p>
        </div>
    <?php endif; ?>

    <?php $retention = (int) get_option( 'easy_mcp_ai_audit_log_retention', 30 ); ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=easy-mcp-ai-audit' ) ); ?>" class="wp-mcp-cleanup-form">
        <?php wp_nonce_field( 'easy_mcp_ai_cleanup_audit' ); ?>
        <input type="hidden" name="easy_mcp_ai_cleanup_audit" value="1">
        <p>
            <?php
            printf(
                /* translators: %d: number of log entries */
                esc_html__( 'Showing %d total log entries.', 'easy-mcp-ai' ),
                absint( $total )
            );
            ?>
            <button type="submit" class="button button-secondary wp-mcp-confirm-submit" data-confirm="<?php echo esc_attr( sprintf( /* translators: %d: retention days */ __( 'Delete all audit log entries older than %d days?', 'easy-mcp-ai' ), absint( $retention ) ) ); ?>">
                <?php
                printf(
                    /* translators: %d: retention days */
                    esc_html__( 'Clean Up Entries Older Than %d Days', 'easy-mcp-ai' ),
                    absint( $retention )
                );
                ?>
            </button>
        </p>
        <p class="description">
            <?php
            printf(
                /* translators: %s: link to settings page */
                wp_kses( __( 'Retention period is %s.', 'easy-mcp-ai' ), array( 'a' => array( 'href' => array() ) ) ),
                sprintf(
                    '<a href="%s">%s</a>',
                    esc_url( admin_url( 'admin.php?page=easy-mcp-ai-settings' ) ),
                    sprintf(
                        /* translators: %d: number of days */
                        esc_html__( 'set to %d days — change in Settings', 'easy-mcp-ai' ),
                        absint( $retention )
                    )
                )
            );
            ?>
        </p>
    </form>

    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wp-mcp-history-filter wp-mcp-audit-filter">
        <input type="hidden" name="page" value="easy-mcp-ai-audit">
        <p>
            <label class="wp-mcp-filter-grow wp-mcp-filter-grow-2">
                <span class="screen-reader-text"><?php esc_html_e( 'Search', 'easy-mcp-ai' ); ?></span>
                <input type="search" name="s" value="<?php echo esc_attr( $audit_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'tool name or arguments', 'easy-mcp-ai' ); ?>" size="24">
            </label>
            <label class="wp-mcp-filter-grow">
                <span class="screen-reader-text"><?php esc_html_e( 'Tool', 'easy-mcp-ai' ); ?></span>
                <select name="tool_name">
                    <option value=""><?php esc_html_e( 'All tools', 'easy-mcp-ai' ); ?></option>
                    <?php foreach ( (array) $audit_filters['tools'] as $easy_mcp_ai_t ) : ?>
                        <option value="<?php echo esc_attr( $easy_mcp_ai_t ); ?>" <?php selected( $audit_filters['tool_name'], $easy_mcp_ai_t ); ?>><?php echo esc_html( $easy_mcp_ai_t ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="wp-mcp-filter-grow">
                <span class="screen-reader-text"><?php esc_html_e( 'User', 'easy-mcp-ai' ); ?></span>
                <select name="wp_user_id">
                    <option value=""><?php esc_html_e( 'All users', 'easy-mcp-ai' ); ?></option>
                    <?php foreach ( (array) $audit_filters['users'] as $easy_mcp_ai_uid => $easy_mcp_ai_login ) : ?>
                        <option value="<?php echo esc_attr( $easy_mcp_ai_uid ); ?>" <?php selected( (int) $audit_filters['wp_user_id'], (int) $easy_mcp_ai_uid ); ?>><?php echo esc_html( $easy_mcp_ai_login ); ?> (#<?php echo esc_html( $easy_mcp_ai_uid ); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="wp-mcp-filter-grow">
                <span class="screen-reader-text"><?php esc_html_e( 'Source', 'easy-mcp-ai' ); ?></span>
                <select name="auth_source">
                    <option value=""><?php esc_html_e( 'Any source', 'easy-mcp-ai' ); ?></option>
                    <option value="legacy" <?php selected( $audit_filters['auth_source'], 'legacy' ); ?>><?php esc_html_e( 'API key', 'easy-mcp-ai' ); ?></option>
                    <option value="oauth" <?php selected( $audit_filters['auth_source'], 'oauth' ); ?>><?php esc_html_e( 'OAuth', 'easy-mcp-ai' ); ?></option>
                    <option value="unknown" <?php selected( $audit_filters['auth_source'], 'unknown' ); ?>><?php esc_html_e( 'Unknown (pre-upgrade rows)', 'easy-mcp-ai' ); ?></option>
                </select>
            </label>
            <label class="wp-mcp-filter-grow">
                <span class="screen-reader-text"><?php esc_html_e( 'Status', 'easy-mcp-ai' ); ?></span>
                <select name="result_status">
                    <option value=""><?php esc_html_e( 'Any status', 'easy-mcp-ai' ); ?></option>
                    <?php foreach ( (array) $audit_filters['statuses'] as $easy_mcp_ai_st ) : ?>
                        <option value="<?php echo esc_attr( $easy_mcp_ai_st ); ?>" <?php selected( $audit_filters['result_status'], $easy_mcp_ai_st ); ?>><?php echo esc_html( $easy_mcp_ai_st ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?php esc_html_e( 'From', 'easy-mcp-ai' ); ?>
                <input type="datetime-local" name="since" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( (string) $audit_filters['since'], 0, 16 ) ) ); ?>">
            </label>
            <label>
                <?php esc_html_e( 'To', 'easy-mcp-ai' ); ?>
                <input type="datetime-local" name="until" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( (string) $audit_filters['until'], 0, 16 ) ) ); ?>">
            </label>
            <span class="wp-mcp-filter-hint"><?php esc_html_e( 'UTC', 'easy-mcp-ai' ); ?></span>
            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'easy-mcp-ai' ); ?></button>
            <?php if ( $has_filter ) : ?>
                <a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=easy-mcp-ai-audit' ) ); ?>"><?php esc_html_e( 'Clear', 'easy-mcp-ai' ); ?></a>
            <?php endif; ?>
        </p>
    </form>

    <table class="wp-list-table widefat fixed striped table-view-list">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-user"><?php esc_html_e( 'User', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-source"><?php esc_html_e( 'Source', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-token"><?php esc_html_e( 'Credential', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-tool"><?php esc_html_e( 'Tool', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-changes"><?php esc_html_e( 'Changes', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-arguments"><?php esc_html_e( 'Arguments', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-duration"><?php esc_html_e( 'Duration', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-ip"><?php esc_html_e( 'IP Address', 'easy-mcp-ai' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $entries ) ) : ?>
                <tr>
                    <td colspan="10"><?php esc_html_e( 'No audit log entries found.', 'easy-mcp-ai' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $entries as $entry ) : ?>
                    <?php
                    $args_display = '';
                    if ( ! empty( $entry['arguments'] ) ) {
                        $args = json_decode( $entry['arguments'], true );
                        if ( is_array( $args ) ) {
                            $pairs = array();
                            foreach ( $args as $key => $value ) {
                                if ( is_array( $value ) || is_object( $value ) ) {
                                    $value = wp_json_encode( $value );
                                }
                                $display_value = strlen( (string) $value ) > 50 ? substr( (string) $value, 0, 50 ) . '...' : (string) $value;
                                $pairs[]       = $key . '=' . $display_value;
                            }
                            $args_display = implode( ', ', $pairs );
                            if ( strlen( $args_display ) > 150 ) {
                                $args_display = substr( $args_display, 0, 150 ) . '...';
                            }
                        }
                    }
                    
                    
                    
                    
                    
                    $status_raw   = ! empty( $entry['result_status'] ) ? strtolower( $entry['result_status'] ) : '';
                    $is_error     = in_array( $status_raw, array( 'error', 'refused', 'auth_failure' ), true );
                    
                    
                    
                    $credential   = \Easy_MCP_AI\Audit_Log_Repository::credential_label( $entry );
                    $source_label = array(
                        'legacy'  => __( 'API key', 'easy-mcp-ai' ),
                        'oauth'   => __( 'OAuth', 'easy-mcp-ai' ),
                        'unknown' => __( 'Unknown', 'easy-mcp-ai' ),
                    );
                    $user_display = '';
                    if ( ! empty( $entry['wp_user_id'] ) ) {
                        $user_display = ! empty( $entry['user_login'] )
                            ? $entry['user_login'] . ' (#' . (int) $entry['wp_user_id'] . ')'
                            /* translators: %d: WordPress user id whose account no longer exists */
                            : sprintf( __( 'user #%d', 'easy-mcp-ai' ), (int) $entry['wp_user_id'] );
                    }
                    ?>
                    <tr id="audit-<?php echo absint( $entry['id'] ); ?>">
                        <td class="column-date">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry['created_at'] . ' UTC' ) ) ); ?>
                        </td>
                        <td class="column-user">
                            <?php if ( '' !== $user_display ) : ?>
                                <?php echo esc_html( $user_display ); ?>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-source">
                            <?php echo esc_html( $source_label[ $credential['source'] ] ); ?>
                        </td>
                        <td class="column-token">
                            <?php echo esc_html( $credential['label'] ); ?>
                            <?php if ( 'oauth' === $credential['source'] && ! empty( $entry['client_name'] ) && ! empty( $entry['oauth_client_id'] ) ) : ?>
                                <br><code class="description"><?php echo esc_html( $entry['oauth_client_id'] ); ?></code>
                            <?php endif; ?>
                        </td>
                        <td class="column-tool">
                            <code><?php echo esc_html( $entry['tool_name'] ); ?></code>
                        </td>
                        <td class="column-changes">
                            <?php
                            $c = isset( $change_counts[ (int) $entry['id'] ] ) ? (int) $change_counts[ (int) $entry['id'] ] : 0;
                            
                            
                            $is_read_only_tool = isset( $entry['tool_name'] )
                                && preg_match( '/^wp_(list|get|search|count|history)_|^wp_search$/', $entry['tool_name'] );
                            if ( $c > 0 && ! $is_read_only_tool ) : ?>
                                <a href="#" class="emai-changes-toggle"
                                   data-audit-id="<?php echo absint( $entry['id'] ); ?>"
                                   aria-expanded="false">
                                    <?php
                                    /* translators: %d: change row count */
                                    echo esc_html( sprintf( _n( '%d change', '%d changes', $c, 'easy-mcp-ai' ), $c ) );
                                    ?>
                                    <span class="emai-changes-caret" aria-hidden="true">▾</span>
                                </a>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-arguments">
                            <?php if ( ! empty( $args_display ) ) : ?>
                                <span title="<?php echo esc_attr( $entry['arguments'] ); ?>"><?php echo esc_html( $args_display ); ?></span>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e( 'None', 'easy-mcp-ai' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="column-status">
                            <?php if ( $is_error ) : ?>
                                <span class="wp-mcp-badge wp-mcp-badge-error"><?php esc_html_e( 'Error', 'easy-mcp-ai' ); ?></span>
                            <?php else : ?>
                                <span class="wp-mcp-badge wp-mcp-badge-ok"><?php esc_html_e( 'OK', 'easy-mcp-ai' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="column-duration">
                            <?php if ( isset( $entry['duration_ms'] ) && null !== $entry['duration_ms'] && '' !== $entry['duration_ms'] ) : ?>
                                <?php
                                /* translators: %s: milliseconds */
                                echo esc_html( sprintf( __( '%s ms', 'easy-mcp-ai' ), number_format_i18n( (int) $entry['duration_ms'] ) ) );
                                ?>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-ip">
                            <?php echo esc_html( $entry['ip_address'] ); ?>
                        </td>
                    </tr>
                    <tr class="emai-changes-detail" data-audit-id="<?php echo absint( $entry['id'] ); ?>" hidden>
                        <td colspan="10" style="background:#f6f7f7;">
                            <div class="emai-changes-detail-body">
                                <em><?php esc_html_e( 'Loading…', 'easy-mcp-ai' ); ?></em>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-user"><?php esc_html_e( 'User', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-source"><?php esc_html_e( 'Source', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-token"><?php esc_html_e( 'Credential', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-tool"><?php esc_html_e( 'Tool', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-changes"><?php esc_html_e( 'Changes', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-arguments"><?php esc_html_e( 'Arguments', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-duration"><?php esc_html_e( 'Duration', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-ip"><?php esc_html_e( 'IP Address', 'easy-mcp-ai' ); ?></th>
            </tr>
        </tfoot>
    </table>

    <?php  ?>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post( paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'prev_text' => __( '&laquo; Previous', 'easy-mcp-ai' ),
                    'next_text' => __( 'Next &raquo;', 'easy-mcp-ai' ),
                    'total'     => $total_pages,
                    'current'   => $page,
                ) ) );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
}
easy_mcp_ai_view_audit_log( $total, $entries, $page, $total_pages, $message, isset( $change_counts ) ? $change_counts : array(), isset( $changes_nonce ) ? $changes_nonce : '', isset( $audit_filters ) ? $audit_filters : array() );
