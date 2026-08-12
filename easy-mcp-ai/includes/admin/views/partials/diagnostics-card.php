<?php
























if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

























// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Easy_MCP_AI\Diagnostics\Diagnostic_Result;
use Easy_MCP_AI\Diagnostics\Diagnostics;

if ( ! class_exists( '\Easy_MCP_AI\Diagnostics\Diagnostics' ) ) {
    return;
}

$results = Diagnostics::cached();
if ( empty( $results ) ) {
    return;
}

$summary  = Diagnostics::summary();
$problems = array_values( array_filter( $results, static function ( $r ) {
    return $r->is_problem();
} ) );
















$failures = array_values( array_filter( $problems, static function ( $r ) {
    return Diagnostic_Result::STATUS_FAIL === $r->status();
} ) );
$warnings = array_values( array_filter( $problems, static function ( $r ) {
    return Diagnostic_Result::STATUS_FAIL !== $r->status();
} ) );

$problems = array_merge( $failures, $warnings );
$unknown  = array_values( array_filter( $results, static function ( $r ) {
    return Diagnostic_Result::STATUS_UNKNOWN === $r->status();
} ) );
$last_run = Diagnostics::last_run_at();

$rerun_url = \wp_nonce_url(
    \admin_url( 'admin.php?page=easy-mcp-ai&easy_mcp_ai_action=rerun_diagnostics' ),
    'easy_mcp_ai_rerun_diagnostics'
);
?>
<?php














?>
<div class="wp-mcp-card" style="grid-column:1 / -1;padding:14px 20px;display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap;">
    <?php
    




















    ?>
    <div style="flex:1 1 420px;min-width:0;">
        <h2 style="margin:0;padding:0;border:0;white-space:nowrap;"><?php esc_html_e( 'Diagnostics', 'easy-mcp-ai' ); ?></h2>

    <p style="margin:8px 0 0;">
        <?php if ( empty( $problems ) ) : ?>
            <span style="color:#00733f;font-weight:600;">&#10003;</span>
            <?php
            
            
            
            
            
            
            
            if ( 0 === (int) $summary['unknown'] ) {
                printf(
                    /* translators: %d: number of checks that passed. */
                    esc_html__( 'All %d checks passed.', 'easy-mcp-ai' ),
                    (int) $summary['pass']
                );
            } else {
                printf(
                    /* translators: 1: number of checks that passed, 2: number of checks run. */
                    esc_html__( '%1$d of %2$d checks passed, none failed.', 'easy-mcp-ai' ),
                    (int) $summary['pass'],
                    (int) $summary['total']
                );
            }
            ?>
        <?php elseif ( ! empty( $failures ) ) : ?>
            <span style="color:#b32d2e;font-weight:600;">&#9888;</span>
            <?php
            if ( empty( $warnings ) ) {
                printf(
                    /* translators: 1: number of checks that failed, 2: total number of checks. */
                    esc_html__( '%1$d check(s) failed, out of %2$d.', 'easy-mcp-ai' ),
                    count( $failures ),
                    (int) $summary['total']
                );
            } else {
                printf(
                    /* translators: 1: number of checks that failed, 2: number of warnings, 3: total number of checks. */
                    esc_html__( '%1$d check(s) failed and %2$d to review, out of %3$d.', 'easy-mcp-ai' ),
                    count( $failures ),
                    count( $warnings ),
                    (int) $summary['total']
                );
            }
            ?>
        <?php else : ?>
            <?php ?>
            <span style="color:#996800;font-weight:600;">&#9888;</span>
            <?php
            printf(
                /* translators: 1: number of warnings to review, 2: total number of checks. */
                esc_html__( '%1$d thing(s) to review across %2$d checks — nothing failed.', 'easy-mcp-ai' ),
                count( $warnings ),
                (int) $summary['total']
            );
            ?>
        <?php endif; ?>

        <?php
        
        
        
        
        
        
        
        $deferred_count = isset( $summary['deferred'] ) ? (int) $summary['deferred'] : 0;

        
        
        
        
        
        $restricted_count = 0;
        foreach ( $unknown as $u ) {
            $e = $u->evidence();
            if ( is_array( $e ) && ! empty( $e['not_permitted'] ) ) {
                $restricted_count++;
            }
        }

        $blocked_count = max( 0, count( $unknown ) - $deferred_count - $restricted_count );
        ?>
        <?php if ( $deferred_count > 0 ) : ?>
            <span style="color:#646970;">
                <?php
                printf(
                    /* translators: %d: number of slower checks not run on this page load. */
                    esc_html__( '(%d slower checks not run — press Re-run checks.)', 'easy-mcp-ai' ),
                    absint( $deferred_count )
                );
                ?>
            </span>
        <?php endif; ?>

        <?php if ( $restricted_count > 0 ) : ?>
            <span style="color:#646970;">
                <?php
                printf(
                    /* translators: %d: number of checks hidden because the viewer is not a network administrator. */
                    esc_html__( '(%d hidden — only a network administrator can see them.)', 'easy-mcp-ai' ),
                    absint( $restricted_count )
                );
                ?>
            </span>
        <?php endif; ?>

        <?php if ( $blocked_count > 0 ) : ?>
            <span style="color:#646970;">
                <?php
                printf(
                    /* translators: %d: number of checks that could not be run on this host. */
                    esc_html__( '(%d could not be checked on this host.)', 'easy-mcp-ai' ),
                    absint( $blocked_count )
                );
                ?>
            </span>
        <?php endif; ?>

        <?php if ( $last_run ) : ?>
            <span style="color:#646970;font-size:12px;">
                <?php
                printf(
                    /* translators: %s: human-readable time since the last diagnostics run, e.g. "5 mins". */
                    esc_html__( '— last run %s ago', 'easy-mcp-ai' ),
                    esc_html( human_time_diff( $last_run, time() ) )
                );
                ?>
            </span>
        <?php endif; ?>
    </p>

    <details>
        <summary style="cursor:pointer;color:#2271b1;">
            <?php
            if ( empty( $problems ) ) {
                esc_html_e( 'Show details', 'easy-mcp-ai' );
            } else {
                printf(
                    /* translators: %d: number of checks needing attention. */
                    esc_html__( 'Show details (%d to look at)', 'easy-mcp-ai' ),
                    count( $problems )
                );
            }
            ?>
        </summary>

        <?php if ( ! empty( $problems ) ) : ?>
            <ul style="margin:10px 0 10px 1.2em;list-style:disc;">
                <?php foreach ( $problems as $problem ) : ?>
                    <li style="margin-bottom:6px;">
                        <strong><?php echo esc_html( $problem->label() ); ?></strong>
                        <?php if ( Diagnostic_Result::STATUS_FAIL === $problem->status() ) : ?>
                            <span style="color:#b32d2e;font-size:11px;font-weight:600;">[<?php esc_html_e( 'BLOCKING', 'easy-mcp-ai' ); ?>]</span>
                        <?php endif; ?>
                        <br><?php echo esc_html( $problem->detail() ); ?>
                        <?php if ( '' !== $problem->fix() ) : ?>
                            <br><em style="color:#646970;"><?php echo esc_html( $problem->fix() ); ?></em>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <table class="wp-mcp-status-table" style="margin-top:10px;">
            <tbody>
            <?php foreach ( $results as $result ) : ?>
                <tr>
                    <td style="width:70px;">
                        <?php
                        switch ( $result->status() ) {
                            case Diagnostic_Result::STATUS_PASS:
                                echo '<span style="color:#00733f;">' . esc_html__( 'Pass', 'easy-mcp-ai' ) . '</span>';
                                break;
                            case Diagnostic_Result::STATUS_WARN:
                                echo '<span style="color:#996800;">' . esc_html__( 'Warn', 'easy-mcp-ai' ) . '</span>';
                                break;
                            case Diagnostic_Result::STATUS_FAIL:
                                echo '<span style="color:#b32d2e;">' . esc_html__( 'Fail', 'easy-mcp-ai' ) . '</span>';
                                break;
                            default:
                                echo '<span style="color:#646970;">' . esc_html__( 'Skipped', 'easy-mcp-ai' ) . '</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php echo esc_html( $result->label() ); ?>
                        <?php if ( '' !== $result->detail() ) : ?>
                            <br><span style="color:#646970;font-size:12px;"><?php echo esc_html( $result->detail() ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    </div>

        <span style="flex:0 1 auto;display:flex;flex-direction:column;align-items:center;gap:10px;">
        <span style="display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;">
            <?php
            
            
            
            
            
            
            
            
            if ( ! empty( $system_info_text ) ) :
                ?>
                <button type="button" class="button-link wp-mcp-copy-btn" data-copy="<?php echo esc_attr( $system_info_text ); ?>" style="font-size:12px;"><?php esc_html_e( 'Copy System Info &amp; Diagnostic', 'easy-mcp-ai' ); ?></button>
            <?php endif; ?>

            <a href="<?php echo esc_url( $rerun_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Re-run checks', 'easy-mcp-ai' ); ?></a>

            <?php
            
            
            
            
            
            
            
            
            
            
            if ( ! empty( $diag_host ) ) :
                ?>
                <a href="<?php echo esc_url( 'https://easymcpai.com/diagnose?url=' . rawurlencode( $diag_host ) ); ?>"
                   class="button button-secondary"
                   target="_blank"
                   rel="noopener noreferrer"
                   title="<?php esc_attr_e( 'Opens easymcpai.com, which tries to reach this site from the public internet', 'easy-mcp-ai' ); ?>"><?php esc_html_e( 'Test from the internet', 'easy-mcp-ai' ); ?></a>
            <?php endif; ?>
        </span>

        <?php
        
















        












        $support_site     = \home_url();
        $support_endpoint = ! empty( $endpoint_url ) ? $endpoint_url : \rest_url( 'easy-mcp-ai/v1/mcp' );
        $support_wellknown_root = \rtrim( \home_url(), '/' ) . '/.well-known/';
        $support_version  = defined( 'EASY_MCP_AI_VERSION' ) ? EASY_MCP_AI_VERSION : __( 'unknown', 'easy-mcp-ai' );

        $support_subject = __( 'Easy MCP AI — support request', 'easy-mcp-ai' );

        $support_body = implode( "\n", array(
            /* translators: %s: the site's URL. */
            sprintf( __( 'Site: %s', 'easy-mcp-ai' ), $support_site ),
            /* translators: %s: the plugin version. */
            sprintf( __( 'Plugin version: %s', 'easy-mcp-ai' ), $support_version ),
            '',
            __( '[1] What I was trying to do:', 'easy-mcp-ai' ),
            '',
            '',
            __( '[2] What happened instead (please include the exact error your AI client shows):', 'easy-mcp-ai' ),
            '',
            '',
            __( '[3] When it started, and anything that changed just before:', 'easy-mcp-ai' ),
            '',
            '',
            '--- ' . __( 'PASTE DIAGNOSTICS BELOW', 'easy-mcp-ai' ) . ' ---',
            __( '(On the plugin dashboard, Diagnostics card, press "Copy System Info & Diagnostic", then paste here.)', 'easy-mcp-ai' ),
            '',
            '',
            '--- ' . __( 'PASTE EXTERNAL TEST RESULT BELOW', 'easy-mcp-ai' ) . ' ---',
            __( '(Press "Test from the internet", then copy the result and paste here.)', 'easy-mcp-ai' ),
            '',
            '',
        ) );

        $ai_prompt = implode( "\n", array(
            __( 'You are helping me diagnose a WordPress plugin called "Easy MCP AI", which exposes WordPress to AI assistants over the Model Context Protocol (MCP).', 'easy-mcp-ai' ),
            '',
            __( 'THE SITE', 'easy-mcp-ai' ),
            /* translators: %s: the site's URL. */
            sprintf( __( 'Site URL: %s', 'easy-mcp-ai' ), $support_site ),
            /* translators: %s: the plugin's MCP endpoint URL. */
            sprintf( __( 'MCP endpoint: %s', 'easy-mcp-ai' ), $support_endpoint ),
            /* translators: %s: the site's /.well-known/ base URL. */
            sprintf( __( 'OAuth discovery: %1$soauth-protected-resource and %1$soauth-authorization-server', 'easy-mcp-ai' ), $support_wellknown_root ),
            /* translators: %s: the plugin version. */
            sprintf( __( 'Plugin version: %s', 'easy-mcp-ai' ), $support_version ),
            '',
            __( 'WHERE TO READ THE PLUGIN SOURCE', 'easy-mcp-ai' ),
            __( 'The plugin is open source. Its released code is readable at https://plugins.svn.wordpress.org/easy-mcp-ai/ — trunk/ is the current release, and tags/ holds each published version. If you need to confirm how a specific check or endpoint actually behaves, read it there rather than assuming.', 'easy-mcp-ai' ),
            __( 'The MCP specification is at https://modelcontextprotocol.io.', 'easy-mcp-ai' ),
            '',
            __( 'MY PROBLEM', 'easy-mcp-ai' ),
            __( '[Describe what is not working. Include the exact error message your AI client shows, if there is one.]', 'easy-mcp-ai' ),
            '',
            __( 'WHAT I EXPECTED INSTEAD', 'easy-mcp-ai' ),
            __( '[Describe what should have happened.]', 'easy-mcp-ai' ),
            '',
            __( 'PLUGIN DIAGNOSTICS', 'easy-mcp-ai' ),
            __( '[Paste here. On the plugin dashboard, Diagnostics card, press "Copy System Info & Diagnostic".]', 'easy-mcp-ai' ),
            '',
            __( 'EXTERNAL CONNECTION TEST', 'easy-mcp-ai' ),
            __( '[Paste here. On the same card, press "Test from the internet", then copy the result.]', 'easy-mcp-ai' ),
            '',
            __( 'HOW TO READ THE DATA ABOVE', 'easy-mcp-ai' ),
            __( '- The "Server" block lists the web server, PHP SAPI and PHP limits. Web server and SAPI together decide whether an Authorization header survives to PHP, which is the most common cause of "it worked yesterday and now the token is rejected".', 'easy-mcp-ai' ),
            __( '- The "Diagnostics" block lists every check as ID, status and label. FAIL is a directly observed fault. WARN is a sign worth ruling out, not proof. UNKNOWN means the check could not run and proves nothing either way — do not treat it as a pass or a failure.', 'easy-mcp-ai' ),
            __( '- Some check details read "[details withheld]" because they can identify WordPress users or API tokens. Treat those as status-only.', 'easy-mcp-ai' ),
            '',
            __( 'WHAT I NEED FROM YOU', 'easy-mcp-ai' ),
            __( '1. Name the single most likely cause, based only on the data above.', 'easy-mcp-ai' ),
            __( '2. Quote the exact line or lines from the data that support that conclusion.', 'easy-mcp-ai' ),
            __( '3. Give me step-by-step instructions to fix it on a WordPress site, in plain language.', 'easy-mcp-ai' ),
            __( '4. If the data is not enough to reach a conclusion, say exactly what is missing instead of guessing.', 'easy-mcp-ai' ),
            __( '5. If a check you rely on is UNKNOWN, tell me how to make it answerable rather than working around it.', 'easy-mcp-ai' ),
            '',
            __( 'THINGS THAT ARE USUALLY THE CAUSE, SO CHECK THEM FIRST', 'easy-mcp-ai' ),
            __( '- Permalinks set to "Plain": WordPress writes no rewrite rules, so /wp-json/ and /.well-known/ return 404 before PHP runs, and OAuth discovery fails even though the endpoint appears to work.', 'easy-mcp-ai' ),
            __( '- The Authorization header never reaching PHP: on Apache this needs a RewriteRule that WordPress writes into .htaccess; on FastCGI or PHP-FPM it needs CGIPassAuth On or the nginx equivalent. From outside, a stripped header and a wrong token look like the same 401.', 'easy-mcp-ai' ),
            __( '- A site behind a proxy or CDN that terminates HTTPS: PHP then sees the request as insecure and every OAuth request is refused, while the site is perfectly secure for visitors.', 'easy-mcp-ai' ),
            __( '- A security or firewall plugin, or a host-level rule, refusing the request before WordPress sees it. This is invisible from inside WordPress, which is what the external test is for.', 'easy-mcp-ai' ),
            __( '- A token whose permissions, OAuth scope, or WordPress user role leave it able to see no tools at all.', 'easy-mcp-ai' ),
        ) );

        $mailto = 'mailto:support@easymcpai.com'
            . '?subject=' . rawurlencode( $support_subject )
            . '&body=' . rawurlencode( $support_body );
        ?>
        <span style="align-self:stretch;display:flex;align-items:center;justify-content:flex-end;gap:14px;flex-wrap:wrap;font-size:12px;">

            <span style="color:#646970;"><?php esc_html_e( 'Need help with a connection issue?', 'easy-mcp-ai' ); ?></span>

            <?php
            













































            ?>
            <a href="<?php echo esc_attr( 'https://claude.ai/new?q=' . rawurlencode( $ai_prompt ) ); ?>"
               target="_blank"
               rel="noopener noreferrer"
               title="<?php esc_attr_e( 'Opens Claude with a diagnostic prompt ready. Fill in the placeholders and paste your data.', 'easy-mcp-ai' ); ?>"><?php esc_html_e( 'Ask Claude', 'easy-mcp-ai' ); ?></a>

            <?php
            














            ?>
            <button type="button"
                    class="button-link wp-mcp-copy-btn"
                    data-copy="<?php echo esc_attr( $ai_prompt ); ?>"
                    style="font-size:12px;"
                    title="<?php esc_attr_e( 'Copies a ready-made diagnostic prompt. Paste it into any AI assistant, fill in the placeholders, and add your diagnostics.', 'easy-mcp-ai' ); ?>"><?php esc_html_e( 'Copy AI Prompt', 'easy-mcp-ai' ); ?></button>

            <a href="<?php echo esc_url( $mailto ); ?>"
               title="<?php esc_attr_e( 'Opens your email app with a blank template. Your site data is NOT included — paste it in yourself so you can see what you are sending.', 'easy-mcp-ai' ); ?>"><?php esc_html_e( 'Email support', 'easy-mcp-ai' ); ?></a>
        </span>
        </span>
</div>
