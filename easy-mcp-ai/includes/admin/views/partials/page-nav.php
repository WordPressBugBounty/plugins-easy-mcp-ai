<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template partial, variables are include-scoped not global.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav highlighting, no form processing.
$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'easy-mcp-ai';

$nav_items = array(
	'easy-mcp-ai'               => __( 'Dashboard', 'easy-mcp-ai' ),
	'easy-mcp-ai-oauth'         => __( 'API Token & OAuth', 'easy-mcp-ai' ),
	'easy-mcp-ai-settings'      => __( 'Settings', 'easy-mcp-ai' ),
	'easy-mcp-ai-plugin-integrations' => __( 'Plugins', 'easy-mcp-ai' ),
	'easy-mcp-ai-abilities'     => __( 'Abilities', 'easy-mcp-ai' ),
	'easy-mcp-ai-external-data' => __( 'External Data', 'easy-mcp-ai' ),
	'easy-mcp-ai-audit'         => __( 'Audit Log', 'easy-mcp-ai' ),
	'easy-mcp-ai-history'       => __( 'Change History', 'easy-mcp-ai' ),
);


$oauth_pages = array( 'easy-mcp-ai-oauth', 'easy-mcp-ai-tokens' );
?>
<nav class="nav-tab-wrapper wp-mcp-page-nav" style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">
	<div style="display:flex;flex-wrap:wrap;">
	<?php foreach ( $nav_items as $slug => $label ) :
		$is_active = ( $current_page === $slug )
			|| ( 'easy-mcp-ai-oauth' === $slug && in_array( $current_page, $oauth_pages, true ) );
		$class = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
	?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="<?php echo esc_attr( $class ); ?>">
			<?php echo esc_html( $label ); ?>
		</a>
	<?php endforeach; ?>
	</div>
	<div style="display:inline-flex;gap:6px;margin-bottom:6px;">
		<a href="https://wordpress.org/support/plugin/easy-mcp-ai/reviews/"
			target="_blank"
			rel="noopener noreferrer"
			class="button button-secondary button-small"
			style="line-height:1.2;display:inline-flex;align-items:center;gap:4px;">
			<span class="dashicons dashicons-star-filled" style="color:#f7b500;font-size:14px;width:14px;height:14px;line-height:1;"></span>
			<?php esc_html_e( 'Rate & Review', 'easy-mcp-ai' ); ?>
		</a>
		<a href="https://wordpress.org/support/plugin/easy-mcp-ai/"
			target="_blank"
			rel="noopener noreferrer"
			class="button button-secondary button-small"
			style="line-height:1.2;display:inline-flex;align-items:center;gap:4px;">
			<span class="dashicons dashicons-sos" style="color:#2271b1;font-size:14px;width:14px;height:14px;line-height:1;"></span>
			<?php esc_html_e( 'Get Help', 'easy-mcp-ai' ); ?>
		</a>
	</div>
</nav>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
