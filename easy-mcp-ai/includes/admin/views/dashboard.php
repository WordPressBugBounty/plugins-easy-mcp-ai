<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'easy_mcp_ai_view_dashboard' ) ) :
function easy_mcp_ai_view_dashboard( $endpoint_url, $token_count, $tool_count, $tool_groups, $client_guides, $oauth_client_count = 0, $external_data_integrations = array() ) {
	$tokens_url  = esc_url( admin_url( 'admin.php?page=easy-mcp-ai-tokens' ) );
	$oauth_url   = esc_url( admin_url( 'admin.php?page=easy-mcp-ai-oauth' ) );
	$tokens_link = '<a href="' . $tokens_url . '">' . esc_html__( 'API Tokens', 'easy-mcp-ai' ) . '</a>';
?>
<div class="wrap wp-mcp-admin">
	<h1><?php esc_html_e( 'Easy MCP AI for WP Dashboard', 'easy-mcp-ai' ); ?></h1>

	<?php include __DIR__ . '/partials/page-nav.php'; ?>

	<div class="wp-mcp-dashboard-grid">

		<!-- Quick Start Guides -->
		<?php
		$quickstart_collapsible = false;
		include __DIR__ . '/partials/quickstart-card.php';
		?>

		<!-- Server Status Card -->
		<div class="wp-mcp-card">
			<?php
				
				
				
				
				
				
				
				
				
				
				$diag_host = wp_parse_url( $endpoint_url, PHP_URL_HOST );
				$diag_port = wp_parse_url( $endpoint_url, PHP_URL_PORT );
				if ( $diag_port ) {
					$diag_host .= ':' . $diag_port;
				}
				$diag_home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
				if ( '' !== $diag_home_path ) {
					$diag_host .= '/' . $diag_home_path;
				}

				
				
				global $wpdb;
				$env_wp_version = get_bloginfo( 'version' );
				$db_server_info = ( isset( $wpdb ) && is_object( $wpdb ) ) ? (string) $wpdb->db_server_info() : '';
				$db_version_num = ( isset( $wpdb ) && is_object( $wpdb ) ) ? (string) $wpdb->db_version() : '';
				
				
				
				
				
				$db_engine  = ( '' !== $db_server_info && false !== stripos( $db_server_info, 'mariadb' ) ) ? 'MariaDB' : 'MySQL';
				$db_display = ( '' !== $db_version_num )
					? $db_engine . ' ' . $db_version_num
					: ( '' !== $db_server_info ? $db_server_info : __( 'Unknown', 'easy-mcp-ai' ) );

				
				$external_data_integrations = is_array( $external_data_integrations ) ? $external_data_integrations : array();
				$ext_connected = array_filter( $external_data_integrations );
				$ext_count     = count( $ext_connected );
				$ext_total     = count( $external_data_integrations );
				$ext_missing   = array_keys( array_filter( $external_data_integrations, fn( $v ) => ! $v ) );

				
				
				
				
				$set_rate_limit       = (int) get_option( 'easy_mcp_ai_rate_limit_per_minute', 60 );
				$set_audit_enabled    = (bool) get_option( 'easy_mcp_ai_audit_log_enabled', true );
				$set_audit_retention  = (int) get_option( 'easy_mcp_ai_audit_log_retention', 30 );
				$set_change_enabled   = (bool) get_option( 'easy_mcp_ai_change_log_enabled', true );
				$set_change_retention = (int) get_option( 'easy_mcp_ai_change_log_retention', 30 );
				$set_force_draft      = (bool) get_option( 'easy_mcp_ai_force_draft_on_create', false );
				$set_max_title        = (int) get_option( 'easy_mcp_ai_max_title_length', 0 );
				$set_admin_language   = (string) get_option( 'easy_mcp_ai_admin_language', '' );
				$set_disabled_tools   = count( (array) get_option( 'easy_mcp_ai_disabled_tools', array() ) );
				$set_allowed_patterns = count( (array) get_option( 'easy_mcp_ai_allowed_tool_patterns', array() ) );
				$set_oauth_cap        = (string) get_option( 'easy_mcp_ai_oauth_min_capability', 'publish_posts' );
				$set_ext_cap          = (string) get_option( 'easy_mcp_ai_external_data_min_capability', 'manage_options' );
				
				
				
				$set_ip_entries = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) get_option( 'easy_mcp_ai_ip_whitelist', '' ) ) ) );
				$set_ip_count   = count( $set_ip_entries );
				$set_ip_display = ( 0 === $set_ip_count )
					? 'None'
					: 'Configured (' . $set_ip_count . ( 1 === $set_ip_count ? ' entry)' : ' entries)' );

				
				
				
				$system_info_text = implode( "\n", array(
					'Easy MCP AI — System Info',
					'Plugin Version:   ' . EASY_MCP_AI_VERSION,
					'WordPress:        ' . $env_wp_version,
					'PHP Version:      ' . PHP_VERSION,
					'Database:         ' . $db_display,
					'Protocol:         2025-11-25 / 2025-06-18 / 2025-03-26',
					'Active Tokens:    ' . (int) $token_count,
					'Active Clients:   ' . (int) $oauth_client_count,
					'Registered Tools: ' . (int) $tool_count,
					'External Data:    ' . $ext_count . '/' . $ext_total,
					'',
					'Settings',
					'Rate Limit (per min):  ' . $set_rate_limit,
					'Audit Log:             ' . ( $set_audit_enabled ? 'Enabled (' . $set_audit_retention . '-day retention)' : 'Disabled' ),
					'Change History:        ' . ( $set_change_enabled ? 'Enabled (' . $set_change_retention . '-day retention)' : 'Disabled' ),
					'Force Draft on Create: ' . ( $set_force_draft ? 'Yes' : 'No' ),
					'Max Title Length:      ' . ( 0 === $set_max_title ? '0 (unlimited)' : $set_max_title ),
					'Admin Language:        ' . ( '' === $set_admin_language ? 'Site default' : $set_admin_language ),
					'Disabled Tools:        ' . $set_disabled_tools,
					'Allowed Tool Patterns: ' . $set_allowed_patterns,
					'OAuth Min Capability:  ' . $set_oauth_cap,
					'External Data Min Cap: ' . $set_ext_cap,
					'IP Whitelist:          ' . $set_ip_display,
				) );
				?>
				<div class="wp-mcp-status-head" style="display:flex; align-items:center; justify-content:space-between; gap:1em; flex-wrap:wrap; border-bottom:1px solid #f0f0f1; padding-bottom:10px; margin-bottom:1em;">
					<h2 style="margin:0; padding:0; border:0;"><?php esc_html_e( 'Server Status', 'easy-mcp-ai' ); ?></h2>
					<span style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
					<button type="button" class="button-link wp-mcp-copy-btn" data-copy="<?php echo esc_attr( $system_info_text ); ?>" style="font-size:12px;"><?php esc_html_e( 'Copy System Info', 'easy-mcp-ai' ); ?></button>
					<?php if ( $diag_host ) : ?>
					<a href="<?php echo esc_url( 'https://easymcpai.com/diagnose?url=' . rawurlencode( $diag_host ) ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Diagnose Connection', 'easy-mcp-ai' ); ?></a>
					<?php endif; ?>
					</span>
				</div>
			<table class="widefat striped">
				<tbody>
					<tr>
						<td class="wp-mcp-status-label"><?php esc_html_e( 'Protocol Version', 'easy-mcp-ai' ); ?></td>
						<td><code>2025-11-25 / 2025-06-18 / 2025-03-26</code></td>
					</tr>
					<tr>
						<td class="wp-mcp-status-label"><?php esc_html_e( 'Plugin Version', 'easy-mcp-ai' ); ?></td>
						<td><code><?php echo esc_html( EASY_MCP_AI_VERSION ); ?></code></td>
					</tr>
					<tr>
						<td class="wp-mcp-status-label"><?php esc_html_e( 'Environment', 'easy-mcp-ai' ); ?></td>
						<td><code><?php echo esc_html( 'WP ' . $env_wp_version ); ?></code> / <code><?php echo esc_html( 'PHP ' . PHP_VERSION ); ?></code></td>
					</tr>
					<tr>
						<td class="wp-mcp-status-label"><?php esc_html_e( 'Active Tokens', 'easy-mcp-ai' ); ?></td>
						<td><?php if ( $token_count > 0 ) : ?><a href="<?php echo esc_url( $tokens_url ); ?>"><?php echo esc_html( $token_count ); ?></a><?php else : ?><?php echo esc_html( $token_count ); ?><?php endif; ?></td>
					</tr>
					<tr>
						<td class="wp-mcp-status-label"><?php esc_html_e( 'Active Clients', 'easy-mcp-ai' ); ?></td>
						<td><?php if ( $oauth_client_count > 0 ) : ?><a href="<?php echo esc_url( $oauth_url ); ?>"><?php echo esc_html( $oauth_client_count ); ?></a><?php else : ?><?php echo esc_html( $oauth_client_count ); ?><?php endif; ?></td>
					</tr>
					<tr>
						<td class="wp-mcp-status-label"><?php esc_html_e( 'Registered Tools', 'easy-mcp-ai' ); ?></td>
						<td><a href="#available-tools"><?php echo esc_html( $tool_count ); ?></a></td>
					</tr>
					<tr>
						<td class="wp-mcp-status-label"><?php esc_html_e( 'External Data', 'easy-mcp-ai' ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ); ?>"><code><?php echo esc_html( $ext_count . '/' . $ext_total ); ?></code></a></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $ext_count < $ext_total ) : ?>
			<div class="wp-mcp-hint wp-mcp-hint-warn" style="margin-top:12px;">
				<span class="dashicons dashicons-warning"></span>
				<span>
					<?php
					printf(
						/* translators: 1: connected count, 2: total count, 3: comma-separated list of missing integration names, 4: link to External Data page */
						esc_html__( '%1$s of %2$s external data sources connected. Set up %3$s on the %4$s page to enable more AI tools.', 'easy-mcp-ai' ),
						'<strong>' . esc_html( $ext_count ) . '</strong>',
						'<strong>' . esc_html( $ext_total ) . '</strong>',
						'<strong>' . esc_html( implode( ', ', $ext_missing ) ) . '</strong>',
						'<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ) . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>'
					);
					?>
				</span>
			</div>
			<?php endif; ?>
		</div>

		<!-- Available Tools Card -->
		<div class="wp-mcp-card wp-mcp-card-full" id="available-tools">
			<h2><?php esc_html_e( 'Available Tools', 'easy-mcp-ai' ); ?></h2>
			<p><?php
			/* translators: %d: number of registered MCP tools */
			printf( esc_html__( '%d tools are registered on this site. A client may see fewer.', 'easy-mcp-ai' ), absint( $tool_count ) ); ?></p>
			<ul class="description" style="margin-top:4px; list-style: disc; padding-left: 20px;">
				<li>
					<?php
					printf(
						/* translators: 1: link to Settings page, 2: link to Plugin Integrations page, 3: link to Abilities page, 4: link to External Data page. */
						esc_html__( 'To enable or disable tools, visit the %1$s, %2$s, %3$s, or %4$s page — disabled tools will not appear in this list.', 'easy-mcp-ai' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'easy-mcp-ai' ) . '</a>',
						'<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-plugin-integrations' ) ) . '">' . esc_html__( 'Plugin Integrations', 'easy-mcp-ai' ) . '</a>',
						'<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-abilities' ) ) . '">' . esc_html__( 'Abilities', 'easy-mcp-ai' ) . '</a>',
						'<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ) . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>'
					);
					?>
				</li>
			</ul>

			<?php
			$hints = isset( $tool_groups['hints'] ) ? $tool_groups['hints'] : array();

			
			if ( ! empty( $hints['has_global_overrides'] ) ) :
				$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'easy-mcp-ai' ) . '</a>';
			?>
				<div class="wp-mcp-hint wp-mcp-hint-info">
					<span class="dashicons dashicons-filter" aria-hidden="true"></span>
					<span><?php
						/* translators: %s: link to Settings page */
						echo wp_kses_post( sprintf( __( 'Some tools are hidden by your global filters in %s.', 'easy-mcp-ai' ), $settings_link ) );
					?></span>
				</div>
			<?php endif; ?>

			<?php
			
			$bucket_links = array();
			if ( ! empty( $hints['disabled_abilities_present'] ) ) {
				$bucket_links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-abilities' ) ) . '">' . esc_html__( 'Abilities', 'easy-mcp-ai' ) . '</a>';
			}
			if ( ! empty( $hints['disabled_plugin_tools_present'] ) ) {
				$bucket_links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-plugin-integrations' ) ) . '">' . esc_html__( 'Plugin Integrations', 'easy-mcp-ai' ) . '</a>';
			}
			if ( ! empty( $hints['disabled_ga_present'] ) || ! empty( $hints['disabled_gsc_present'] ) ) {
				$bucket_links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ) . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>';
			}
			if ( ! empty( $bucket_links ) ) :
				$count = count( $bucket_links );
				if ( 1 === $count ) {
					$joined = $bucket_links[0];
				} elseif ( 2 === $count ) {
					/* translators: separator between two page links */
					$joined = $bucket_links[0] . ' ' . esc_html__( 'or', 'easy-mcp-ai' ) . ' ' . $bucket_links[1];
				} else {
					$last   = array_pop( $bucket_links );
					$joined = implode( ', ', $bucket_links ) . ', ' . esc_html__( 'or', 'easy-mcp-ai' ) . ' ' . $last;
				}
			?>
				<div class="wp-mcp-hint wp-mcp-hint-warn">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<span><?php
						/* translators: %s: list of admin page links (Abilities / Plugin Integrations / External Data) */
						echo wp_kses_post( sprintf( __( 'Some installed tools are turned off — re-enable in %s.', 'easy-mcp-ai' ), $joined ) );
					?></span>
				</div>
			<?php endif; ?>

			<?php
			
			$missing_sources = array();
			if ( ! empty( $hints['ga_missing'] ) )       { $missing_sources[] = __( 'Google Analytics', 'easy-mcp-ai' ); }
			if ( ! empty( $hints['gsc_missing'] ) )      { $missing_sources[] = __( 'Google Search Console', 'easy-mcp-ai' ); }
			if ( ! empty( $hints['dfs_missing'] ) )      { $missing_sources[] = __( 'DataForSEO', 'easy-mcp-ai' ); }
			if ( ! empty( $hints['semrush_missing'] ) )  { $missing_sources[] = __( 'SEMrush', 'easy-mcp-ai' ); }
			if ( ! empty( $missing_sources ) ) :
				$ext_link = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ) . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>';
				$names    = '<strong>' . esc_html( implode( ', ', $missing_sources ) ) . '</strong>';
				/* translators: 1: comma-separated source names, 2: link to External Data page */
				$msg = sprintf( __( '%1$s %2$s — add credentials in %3$s to expose their tools.', 'easy-mcp-ai' ),
					$names,
					count( $missing_sources ) === 1 ? esc_html__( 'is not connected', 'easy-mcp-ai' ) : esc_html__( 'are not connected', 'easy-mcp-ai' ),
					$ext_link
				);
			?>
				<div class="wp-mcp-hint wp-mcp-hint-warn">
					<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
					<span><?php echo wp_kses_post( $msg ); ?></span>
				</div>
			<?php endif; ?>

			<?php
			
			if ( ! empty( $hints['ahrefs_disabled'] ) ) :
				$ext_link_ahrefs = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ) . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>';
				/* translators: %s: link to External Data page */
				$ahrefs_msg = sprintf( __( '<strong>Ahrefs Domain Rating</strong> is free and needs no API key, but is off by default — enable it in %s to expose it to your AI.', 'easy-mcp-ai' ), $ext_link_ahrefs );
			?>
				<div class="wp-mcp-hint wp-mcp-hint-info">
					<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
					<span><?php echo wp_kses_post( $ahrefs_msg ); ?></span>
				</div>
			<?php endif; ?>

			<div class="wp-mcp-hint wp-mcp-hint-info">
				<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
				<span><?php
					$tokens_oauth_link = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-tokens' ) ) . '">' . esc_html__( 'API Token &amp; OAuth', 'easy-mcp-ai' ) . '</a>';
					/* translators: %s: link to Tokens & OAuth page */
					echo wp_kses_post( sprintf( __( 'Three things can narrow this list for a client: token permissions, OAuth scope, and the WordPress capabilities of the user assigned to the token. A tool is hidden when that user lacks the capability it requires. Review access in %s.', 'easy-mcp-ai' ), $tokens_oauth_link ) );
				?></span>
			</div>

			<?php
			$render_tool_chips = function( array $tools ) {
				echo '<div class="wp-mcp-tools-list" style="margin:8px 0 4px;">';
				foreach ( $tools as $def ) {
					$name = is_array( $def ) ? $def['name'] : $def;
					echo '<span class="wp-mcp-tool-chip"><code>' . esc_html( $name ) . '</code></span>';
				}
				echo '</div>';
			};
			?>

			<!-- Core Tools -->
			<details class="wp-mcp-quickstart-item" style="margin-top:12px;">
				<summary><span><?php
					/* translators: %d: number of core tools */
					printf( esc_html__( 'Core (%d tools)', 'easy-mcp-ai' ), absint( array_sum( array_map( 'count', $tool_groups['core'] ) ) ) );
				?></span></summary>
				<div class="wp-mcp-quickstart-body">
					<?php if ( empty( $tool_groups['core'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No core tools are currently registered.', 'easy-mcp-ai' ); ?></p>
					<?php else : ?>
						<?php foreach ( $tool_groups['core'] as $label => $tools ) : ?>
							<details class="wp-mcp-quickstart-item" style="margin-top:6px;">
								<summary><span><?php echo esc_html( $label ); ?> <small style="font-weight:normal;opacity:0.7;">(<?php echo absint( count( $tools ) ); ?>)</small></span></summary>
								<div class="wp-mcp-quickstart-body">
									<?php $render_tool_chips( $tools ); ?>
								</div>
							</details>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</details>

			<!-- Plugin Tools: always shows all supported plugins with status -->
			<?php
			$plugin_active_count = 0;
			foreach ( $tool_groups['plugins'] as $p ) {
				if ( 'active' === $p['status'] ) {
					$plugin_active_count += count( $p['tools'] );
				}
			}
			?>
			<details class="wp-mcp-quickstart-item" style="margin-top:8px;">
				<summary><span><?php
					/* translators: %d: number of plugin tools */
					printf( esc_html__( 'Plugins (%d tools)', 'easy-mcp-ai' ), absint( $plugin_active_count ) );
				?></span></summary>
				<div class="wp-mcp-quickstart-body">
					<?php foreach ( $tool_groups['plugins'] as $plugin_label => $plugin_data ) : ?>
						<details class="wp-mcp-quickstart-item" style="margin-top:6px;">
							<summary><span>
								<?php echo esc_html( $plugin_label ); ?>
								<?php if ( 'active' === $plugin_data['status'] ) : ?>
									<small style="font-weight:normal;opacity:0.7;">(<?php echo absint( count( $plugin_data['tools'] ) ); ?> tools)</small>
								<?php elseif ( 'not_installed' === $plugin_data['status'] ) : ?>
									<small style="font-weight:normal;opacity:0.55;">— <?php esc_html_e( 'Not installed', 'easy-mcp-ai' ); ?></small>
								<?php else : ?>
									<small style="font-weight:normal;opacity:0.55;">— <?php esc_html_e( 'Installed, no tools active', 'easy-mcp-ai' ); ?></small>
								<?php endif; ?>
							</span></summary>
							<div class="wp-mcp-quickstart-body">
								<?php if ( 'active' === $plugin_data['status'] ) : ?>
									<?php $render_tool_chips( $plugin_data['tools'] ); ?>
								<?php elseif ( 'not_installed' === $plugin_data['status'] ) : ?>
									<p class="description"><?php
									/* translators: %s: plugin name */
									printf( esc_html__( '%s is not installed or not active. Install and activate it to unlock these MCP tools.', 'easy-mcp-ai' ), esc_html( $plugin_label ) ); ?></p>
								<?php else : ?>
									<p class="description"><?php
									$integrations_link = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-plugin-integrations' ) ) . '">' . esc_html__( 'Plugin Integrations', 'easy-mcp-ai' ) . '</a>';
									/* translators: 1: plugin name, 2: Plugin Integrations page link */
									echo wp_kses_post( sprintf( __( '%1$s installed but no tools activated. Please activate in %2$s.', 'easy-mcp-ai' ), esc_html( $plugin_label ), $integrations_link ) );
								?></p>
								<?php endif; ?>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
			</details>

			<!-- External Data Tools (GA, GSC) -->
			<?php
			$external_active_count = 0;
			foreach ( $tool_groups['external'] as $e ) {
				if ( 'active' === $e['status'] ) {
					$external_active_count += count( $e['tools'] );
				}
			}
			$external_data_url = esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) );
			?>
			<details class="wp-mcp-quickstart-item" style="margin-top:8px;">
				<summary><span><?php
					/* translators: %d: number of external data tools */
					printf( esc_html__( 'External Data (%d tools)', 'easy-mcp-ai' ), absint( $external_active_count ) );
				?></span></summary>
				<div class="wp-mcp-quickstart-body">
					<p class="description"><?php
						$ext_link = '<a href="' . $external_data_url . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>';
						/* translators: %s: link to External Data settings page */
						echo wp_kses_post( sprintf( __( 'Configure credentials in %s to activate these tools.', 'easy-mcp-ai' ), $ext_link ) );
					?></p>
					<?php foreach ( $tool_groups['external'] as $ext_label => $ext_data ) : ?>
						<details class="wp-mcp-quickstart-item" style="margin-top:6px;">
							<summary><span>
								<?php echo esc_html( $ext_label ); ?>
								<?php if ( 'active' === $ext_data['status'] ) : ?>
									<small style="font-weight:normal;opacity:0.7;">(<?php echo absint( count( $ext_data['tools'] ) ); ?> tools)</small>
								<?php elseif ( 'not_configured' === $ext_data['status'] ) : ?>
									<small style="font-weight:normal;opacity:0.55;">— <?php echo ! empty( $ext_data['keyless'] ) ? esc_html__( 'Not enabled', 'easy-mcp-ai' ) : esc_html__( 'Not configured', 'easy-mcp-ai' ); ?></small>
								<?php else : ?>
									<small style="font-weight:normal;opacity:0.55;">— <?php esc_html_e( 'Configured, no tools active', 'easy-mcp-ai' ); ?></small>
								<?php endif; ?>
							</span></summary>
							<div class="wp-mcp-quickstart-body">
								<?php if ( 'active' === $ext_data['status'] ) : ?>
									<?php $render_tool_chips( $ext_data['tools'] ); ?>
								<?php elseif ( 'not_configured' === $ext_data['status'] ) : ?>
									<p class="description"><?php
										$ext_link2 = '<a href="' . $external_data_url . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>';
										/* translators: 1: integration name, 2: External Data page link */
										if ( ! empty( $ext_data['keyless'] ) ) {
												/* translators: 1: integration name (e.g. "Ahrefs (DR)"), 2: External Data page link */
												echo wp_kses_post( sprintf( __( '%1$s is not enabled. Turn it on in %2$s to use this tool — no API key or account needed.', 'easy-mcp-ai' ), esc_html( $ext_label ), $ext_link2 ) );
											} else {
												/* translators: 1: integration name, 2: External Data page link */
												echo wp_kses_post( sprintf( __( '%1$s credentials are not configured. Add them in %2$s to enable these tools.', 'easy-mcp-ai' ), esc_html( $ext_label ), $ext_link2 ) );
											}
									?></p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'Credentials configured but no tools are active.', 'easy-mcp-ai' ); ?></p>
								<?php endif; ?>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
			</details>

			<!-- Abilities: Core (flat) + one collapsible per plugin -->
			<?php
			$abilities_tool_count = 0;
			foreach ( $tool_groups['abilities'] as $group_data ) {
				$abilities_tool_count += count( $group_data['tools'] );
			}
			$integrations_url = admin_url( 'admin.php?page=easy-mcp-ai-plugin-integrations' );
			?>
			<details class="wp-mcp-quickstart-item" style="margin-top:8px;">
				<summary><span><?php
					/* translators: %d: number of registered abilities */
					printf( esc_html__( 'Abilities (%d tools)', 'easy-mcp-ai' ), absint( $abilities_tool_count ) );
				?></span></summary>
				<div class="wp-mcp-quickstart-body">
					<?php if ( empty( $tool_groups['abilities'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No abilities are currently registered. Abilities require WordPress 6.9+ and plugins that implement wp_register_ability().', 'easy-mcp-ai' ); ?></p>
					<?php else : ?>
						<?php foreach ( $tool_groups['abilities'] as $group_label => $group_data ) :
							$tools    = $group_data['tools'];
							$is_known = ! empty( $group_data['is_known'] );
						?>
							<?php if ( 'Core' === $group_label ) : ?>
								<div style="margin-top:8px;">
									<strong><?php esc_html_e( 'Core', 'easy-mcp-ai' ); ?></strong>
									<?php if ( empty( $tools ) ) : ?>
										<p class="description" style="margin-top:4px;"><?php esc_html_e( 'No core abilities are registered yet.', 'easy-mcp-ai' ); ?></p>
									<?php else : ?>
										<?php $render_tool_chips( $tools ); ?>
									<?php endif; ?>
								</div>
							<?php else : ?>
								<details class="wp-mcp-quickstart-item" style="margin-top:6px;">
									<summary><span>
										<?php echo esc_html( $group_label ); ?>
										<?php if ( ! empty( $tools ) ) : ?>
											<small style="font-weight:normal;opacity:0.7;">(<?php echo absint( count( $tools ) ); ?> abilities)</small>
										<?php elseif ( ! empty( $group_data['has_abilities'] ) ) : ?>
											<small style="font-weight:normal;opacity:0.55;">— <?php esc_html_e( 'Have abilities but not activated', 'easy-mcp-ai' ); ?></small>
										<?php else : ?>
											<small style="font-weight:normal;opacity:0.55;">— <?php esc_html_e( 'No abilities', 'easy-mcp-ai' ); ?></small>
										<?php endif; ?>
									</span></summary>
									<div class="wp-mcp-quickstart-body">
										<?php if ( ! empty( $tools ) ) : ?>
											<?php $render_tool_chips( $tools ); ?>
										<?php elseif ( ! empty( $group_data['has_abilities'] ) ) : ?>
											<?php  ?>
											<p class="description"><?php
												$abilities_link = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-abilities' ) ) . '">' . esc_html__( 'Abilities', 'easy-mcp-ai' ) . '</a>';
												/* translators: 1: plugin name, 2: Abilities page link */
												echo wp_kses_post( sprintf( __( '%1$s has registered abilities but they are not currently active in Easy MCP AI. Enable them in %2$s.', 'easy-mcp-ai' ), esc_html( $group_label ), $abilities_link ) );
											?></p>
										<?php else : ?>
											<?php  ?>
											<p class="description"><?php esc_html_e( 'This plugin does not expose any abilities. Ask the plugin developer to implement them via wp_register_ability() and they will automatically appear here.', 'easy-mcp-ai' ); ?></p>
											<?php if ( $is_known ) : ?>
												<p class="description"><?php
													$link = '<a href="' . esc_url( $integrations_url ) . '">' . esc_html__( 'Plugin Integrations', 'easy-mcp-ai' ) . '</a>';
													/* translators: 1: plugin name, 2: Plugin Integrations link */
													echo wp_kses_post( sprintf( __( 'We do support %1$s without abilities — see available tools in %2$s.', 'easy-mcp-ai' ), esc_html( $group_label ), $link ) );
												?></p>
											<?php endif; ?>
										<?php endif; ?>
									</div>
								</details>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</details>

		</div>

	</div>
</div>
<?php
}
endif;
easy_mcp_ai_view_dashboard( $endpoint_url, $token_count, $tool_count, $tool_groups, $client_guides, isset( $oauth_client_count ) ? $oauth_client_count : 0, isset( $external_data_integrations ) ? $external_data_integrations : array() );
