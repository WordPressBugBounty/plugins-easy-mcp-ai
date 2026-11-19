<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$headings = array(
    __( 'App', 'easy-mcp-ai' ),
    __( 'Access', 'easy-mcp-ai' ),
    __( 'Connected (UTC)', 'easy-mcp-ai' ),
    __( 'Last used (UTC)', 'easy-mcp-ai' ),
    __( 'Actions', 'easy-mcp-ai' ),
);
?>
<h2 id="easy-mcp-connections">
    <?php
    printf(
        /* translators: %s: configured plugin brand name. */
        esc_html__( 'AI apps connected through %s', 'easy-mcp-ai' ),
        esc_html( \Easy_MCP_AI\Config::get( 'brand_name' ) )
    );
    ?>
</h2>
<p><?php esc_html_e( 'AI apps you approved to act with your account. Revoking signs the app out at once; to use it again, connect it again.', 'easy-mcp-ai' ); ?></p>

<table class="widefat striped">
    <thead>
        <tr>
            <?php foreach ( $headings as $heading ) : ?>
            <th scope="col"><?php echo esc_html( $heading ); ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php
        foreach ( $grants as $grant ) :
            $name = '' !== $grant['client']['name'] ? $grant['client']['name'] : __( 'Unnamed app', 'easy-mcp-ai' );
            ?>
        <tr>
            <td>
                <?php echo esc_html( $name ); ?>
                <?php if ( null !== $grant['documentHost'] ) : ?>
                <br><span class="description">
                    <?php
                    printf(
                        /* translators: %s: the host that published the app's registration, e.g. claude.ai */
                        esc_html__( 'Registered by %s', 'easy-mcp-ai' ),
                        esc_html( $grant['documentHost'] )
                    );
                    ?>
                </span>
                <?php endif; ?>
                <?php if ( $grant['issuedElsewhere'] ) : ?>
                <br><span class="description"><?php esc_html_e( 'Issued for a previous site address. Connect the app again to use it.', 'easy-mcp-ai' ); ?></span>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html( \Easy_MCP_AI\Admin\Self_Service_Connections::access_label( $grant ) ); ?></td>
            <td><?php echo esc_html( \Easy_MCP_AI\Admin\Self_Service_Connections::utc( $grant['grantedAt'] ) ); ?></td>
            <td><?php echo esc_html( \Easy_MCP_AI\Admin\Self_Service_Connections::utc( $grant['lastUsedAt'] ) ); ?></td>
            <td>
                <button type="submit" class="button" form="<?php echo esc_attr( 'easy-mcp-self-revoke-connection-' . (int) $grant['id'] ); ?>"><?php esc_html_e( 'Revoke', 'easy-mcp-ai' ); ?></button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ( ! $grants ) : ?>
        <tr>
            <td colspan="5"><?php esc_html_e( 'You have not connected any AI app.', 'easy-mcp-ai' ); ?></td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<p>
    <?php if ( $page > 1 ) : ?>
    <a href="<?php echo esc_url( admin_url( 'profile.php?easy_mcp_connections_page=' . ( $page - 1 ) . '#easy-mcp-connections' ) ); ?>"><?php esc_html_e( 'Previous apps', 'easy-mcp-ai' ); ?></a>
    <?php endif; ?>
    <?php if ( $more ) : ?>
    <a href="<?php echo esc_url( admin_url( 'profile.php?easy_mcp_connections_page=' . ( $page + 1 ) . '#easy-mcp-connections' ) ); ?>"><?php esc_html_e( 'Next apps', 'easy-mcp-ai' ); ?></a>
    <?php endif; ?>
</p>
