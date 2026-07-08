<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function easy_mcp_ai_view_abilities( $has_abilities_api, $enabled_abilities, $message, $flat_abilities ) {
?>
<div class="wrap wp-mcp-admin">
    <h1><?php esc_html_e( 'Easy MCP AI - Abilities Browser', 'easy-mcp-ai' ); ?></h1>

    <?php include __DIR__ . '/partials/page-nav.php'; ?>

    <?php if ( 'saved' === $message ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><strong><?php esc_html_e( 'Ability settings saved. Enabled abilities are now available as individual MCP tools.', 'easy-mcp-ai' ); ?></strong> <?php echo \Easy_MCP_AI\Admin\Admin_Page::tool_cache_hint_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped static HTML ?></p>
        </div>
    <?php endif; ?>

    <p class="description wp-mcp-mb-16">
        <?php esc_html_e( 'Each enabled ability becomes its own MCP tool, discoverable by AI assistants via tools/list.', 'easy-mcp-ai' ); ?>
    </p>

    <p class="wp-mcp-mb-16">
        <a href="<?php echo esc_url( 'https://easymcpai.com/abilities-directory' ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Browse Plugins with Abilities', 'easy-mcp-ai' ); ?></a>
    </p>

    <!-- ===== ABILITIES ===== -->
    <div id="wp-mcp-abilities-tab">

        <?php if ( ! $has_abilities_api ) : ?>
            <div class="wp-mcp-card wp-mcp-mt-16">
                <div class="notice notice-warning inline wp-mcp-m-0 wp-mcp-p-12-16">
                    <h3 class="wp-mcp-m-0-0-8"><?php esc_html_e( 'WordPress 6.9+ Required', 'easy-mcp-ai' ); ?></h3>
                    <p class="wp-mcp-m-0">
                        <?php
                        printf(
                            /* translators: %s: current WordPress version */
                            esc_html__( 'The WordPress Abilities API requires WordPress 6.9 or later. Your current version is %s.', 'easy-mcp-ai' ),
                            esc_html( $GLOBALS['wp_version'] )
                        );
                        ?>
                    </p>
                </div>
            </div>
        <?php else : ?>

            <?php if ( empty( $flat_abilities ) ) : ?>
                <div class="wp-mcp-card wp-mcp-mt-16">
                    <p><?php esc_html_e( 'No WordPress Abilities registered yet.', 'easy-mcp-ai' ); ?></p>
                </div>
            <?php else : ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=easy-mcp-ai-abilities' ) ); ?>">
                    <?php wp_nonce_field( 'easy_mcp_ai_save_abilities' ); ?>
                    <input type="hidden" name="easy_mcp_ai_save_abilities" value="1">

                    <?php
                    
                    
                    
                    
                    $rendered_ability_names = array();
                    $grouped_abilities      = array();
                    foreach ( $flat_abilities as $item ) {
                        $rendered_ability_names[]               = $item['ability']->get_name();
                        $grouped_abilities[ $item['prefix'] ][] = $item;
                    }
                    ?>
                    <input type="hidden" name="abilities_rendered" value="<?php echo esc_attr( wp_json_encode( $rendered_ability_names ) ); ?>">
                    <?php
                    
                    
                    
                    
                    ?>
                    <input type="hidden" name="enabled_abilities_json" id="wp-mcp-enabled-abilities-json" value="">
                    <?php foreach ( $grouped_abilities as $group_prefix => $group_items ) :
                        $group_id      = sanitize_html_class( 'abilities-group-' . $group_prefix );
                        $group_total   = count( $group_items );
                        $group_enabled = 0;
                        $group_read    = 0;
                        $group_write   = 0;
                        foreach ( $group_items as $group_item ) {
                            $group_ability = $group_item['ability'];
                            if ( in_array( $group_ability->get_name(), $enabled_abilities, true ) ) {
                                $group_enabled++;
                            }
                            $group_ann = \Easy_MCP_AI\Admin\Abilities_Page::ability_annotations( $group_ability );
                            if ( ! empty( $group_ann['readonly'] ) ) {
                                $group_read++;
                            } else {
                                $group_write++;
                            }
                        }
                        $group_all_enabled = ( $group_total > 0 && $group_enabled === $group_total );
                        /* translators: 1: enabled count, 2: total count, 3: read-only count, 4: write count */
                        $group_counts_tmpl = __( '%1$d / %2$d enabled · %3$d read, %4$d write', 'easy-mcp-ai' );
                    ?>
                        <div class="wp-mcp-card wp-mcp-plugin-section">
                            <div class="wp-mcp-plugin-header">
                                <h3>
                                    <button type="button" class="wp-mcp-collapse-btn" aria-expanded="false"
                                        aria-controls="<?php echo esc_attr( $group_id ); ?>">
                                        <span class="wp-mcp-collapse-icon dashicons dashicons-arrow-right-alt2"></span>
                                    </button>
                                    <label class="wp-mcp-group-toggle">
                                        <input type="checkbox" class="wp-mcp-abilities-group-checkbox"
                                            data-group="<?php echo esc_attr( $group_id ); ?>"
                                            <?php checked( $group_all_enabled ); ?>>
                                        <strong><?php echo esc_html( ucfirst( $group_prefix ) ); ?></strong>
                                    </label>
                                </h3>
                                <span class="description wp-mcp-abilities-group-counts"
                                    data-group="<?php echo esc_attr( $group_id ); ?>"
                                    data-read="<?php echo (int) $group_read; ?>"
                                    data-write="<?php echo (int) $group_write; ?>"
                                    data-tmpl="<?php echo esc_attr( $group_counts_tmpl ); ?>">
                                    <?php echo esc_html( sprintf( $group_counts_tmpl, $group_enabled, $group_total, $group_read, $group_write ) ); ?>
                                </span>
                            </div>
                            <div class="wp-mcp-plugin-body" id="<?php echo esc_attr( $group_id ); ?>" hidden>
                                <table class="widefat striped">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Slug', 'easy-mcp-ai' ); ?></th>
                                            <th><?php esc_html_e( 'Label', 'easy-mcp-ai' ); ?></th>
                                            <th><?php esc_html_e( 'Description', 'easy-mcp-ai' ); ?></th>
                                            <th><?php esc_html_e( 'Read-Only', 'easy-mcp-ai' ); ?></th>
                                            <th><?php esc_html_e( 'MCP Tool', 'easy-mcp-ai' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $group_items as $item ) :
                                            $ability     = $item['ability'];
                                            $slug        = $ability->get_name();
                                            $annotations = \Easy_MCP_AI\Admin\Abilities_Page::ability_annotations( $ability );
                                            $readonly    = isset( $annotations['readonly'] ) && $annotations['readonly'];
                                            $tool_name   = 'wp_ability_' . \Easy_MCP_AI\Tools\Dynamic_Tool_Registrar::normalize_identifier( $slug );
                                        ?>
                                            <tr>
                                                <td><code><?php echo esc_html( $slug ); ?></code></td>
                                                <td><?php echo esc_html( $ability->get_label() ?: $slug ); ?></td>
                                                <td class="wp-mcp-mw-280"><?php echo esc_html( $ability->get_description() ); ?></td>
                                                <td>
                                                    <?php if ( $readonly ) : ?>
                                                        <span class="wp-mcp-badge wp-mcp-badge-ok"><?php esc_html_e( 'Yes', 'easy-mcp-ai' ); ?></span>
                                                    <?php else : ?>
                                                        <span class="wp-mcp-badge wp-mcp-badge-inactive"><?php esc_html_e( 'No', 'easy-mcp-ai' ); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <label>
                                                        <input type="checkbox"
                                                            class="wp-mcp-ability-checkbox"
                                                            name="enabled_abilities[]"
                                                            value="<?php echo esc_attr( $slug ); ?>"
                                                            <?php checked( in_array( $slug, $enabled_abilities, true ) ); ?>>
                                                        <code><?php echo esc_html( $tool_name ); ?></code>
                                                    </label>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php submit_button( __( 'Save Ability Settings', 'easy-mcp-ai' ) ); ?>
                </form>

            <?php endif; ?>
        <?php endif; ?>
    </div><!-- /

</div><!-- /.wrap -->
<?php
}
easy_mcp_ai_view_abilities( $has_abilities_api, $enabled_abilities, $message, $flat_abilities );
