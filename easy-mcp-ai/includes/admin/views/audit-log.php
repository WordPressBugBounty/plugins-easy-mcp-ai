<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function easy_mcp_ai_view_audit_log( $total, $entries, $page, $total_pages, $message ) {
?>
<div class="wrap wp-mcp-admin">
    <h1><?php esc_html_e( 'Audit Log', 'easy-mcp-ai' ); ?></h1>

    <?php include __DIR__ . '/partials/page-nav.php'; ?>

    <?php if ( 'cleaned' === $message ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Audit log entries have been cleaned up.', 'easy-mcp-ai' ); ?></p>
        </div>
    <?php endif; ?>

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
            <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete old audit log entries?', 'easy-mcp-ai' ) ); ?>');">
                <?php esc_html_e( 'Clean Up Old Entries', 'easy-mcp-ai' ); ?>
            </button>
        </p>
    </form>

    <table class="wp-list-table widefat fixed striped table-view-list">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-token"><?php esc_html_e( 'Token', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-tool"><?php esc_html_e( 'Tool', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-arguments"><?php esc_html_e( 'Arguments', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-ip"><?php esc_html_e( 'IP Address', 'easy-mcp-ai' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $entries ) ) : ?>
                <tr>
                    <td colspan="6"><?php esc_html_e( 'No audit log entries found.', 'easy-mcp-ai' ); ?></td>
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
                    $is_error     = ! empty( $entry['result_status'] ) && 'error' === strtolower( $entry['result_status'] );
                    $token_display = ! empty( $entry['token_name'] ) ? $entry['token_name'] : '#' . $entry['token_id'];
                    ?>
                    <tr>
                        <td class="column-date">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry['created_at'] . ' UTC' ) ) ); ?>
                        </td>
                        <td class="column-token">
                            <?php echo esc_html( $token_display ); ?>
                        </td>
                        <td class="column-tool">
                            <code><?php echo esc_html( $entry['tool_name'] ); ?></code>
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
                        <td class="column-ip">
                            <?php echo esc_html( $entry['ip_address'] ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-token"><?php esc_html_e( 'Token', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-tool"><?php esc_html_e( 'Tool', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-arguments"><?php esc_html_e( 'Arguments', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'easy-mcp-ai' ); ?></th>
                <th scope="col" class="manage-column column-ip"><?php esc_html_e( 'IP Address', 'easy-mcp-ai' ); ?></th>
            </tr>
        </tfoot>
    </table>

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
easy_mcp_ai_view_audit_log( $total, $entries, $page, $total_pages, $message );
