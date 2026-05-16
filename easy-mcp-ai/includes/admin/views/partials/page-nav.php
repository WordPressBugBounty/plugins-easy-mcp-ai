<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template partial, variables are include-scoped not global.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav highlighting, no form processing.
$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'easy-mcp-ai';

$nav_items = array(
	'easy-mcp-ai'               => __( 'Dashboard', 'easy-mcp-ai' ),
	'easy-mcp-ai-tokens'        => __( 'API Token & OAuth', 'easy-mcp-ai' ),
	'easy-mcp-ai-audit'         => __( 'Audit Log', 'easy-mcp-ai' ),
	'easy-mcp-ai-settings'      => __( 'Settings', 'easy-mcp-ai' ),
	'easy-mcp-ai-plugin-integrations' => __( 'Plugins', 'easy-mcp-ai' ),
	'easy-mcp-ai-abilities'     => __( 'Abilities', 'easy-mcp-ai' ),
	'easy-mcp-ai-external-data' => __( 'External Data', 'easy-mcp-ai' ),
);


$oauth_pages = array( 'easy-mcp-ai-oauth', 'easy-mcp-ai-tokens' );
?>
<nav class="nav-tab-wrapper wp-mcp-page-nav">
	<?php foreach ( $nav_items as $slug => $label ) :
		$is_active = ( $current_page === $slug )
			|| ( 'easy-mcp-ai-tokens' === $slug && in_array( $current_page, $oauth_pages, true ) );
		$class = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
	?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="<?php echo esc_attr( $class ); ?>">
			<?php echo esc_html( $label ); ?>
		</a>
	<?php endforeach; ?>
</nav>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
