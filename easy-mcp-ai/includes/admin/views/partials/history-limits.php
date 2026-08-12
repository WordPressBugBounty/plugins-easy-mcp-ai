<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
















?>
    <details class="emai-limits">
        <summary><?php esc_html_e( 'What is not recorded here — read this before relying on the log', 'easy-mcp-ai' ); ?></summary>
        <div class="emai-limits-body">
            <h3><?php esc_html_e( 'Not recorded at all', 'easy-mcp-ai' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Anything you or your team change yourselves — edits in WordPress admin, WP-CLI, other plugins. This log covers AI assistant activity only.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Changes sent to an outside service, such as a push-notification or email-marketing provider. We record that it happened and which service was contacted, but not what changed over there.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Files written to disk — backups, generated images, code-snippet files.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Work that finishes after the assistant\'s request ends, such as queued or scheduled jobs. The same action can be fully recorded on a small site and missed on a busy one, because size is what pushes a plugin into queuing.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'A few plugins switch off WordPress\'s change notifications before saving. Unless "Capture raw database writes" is turned on, we can only note that this happened.', 'easy-mcp-ai' ); ?></li>
            </ul>

            <h3><?php esc_html_e( 'Recorded, but not in full', 'easy-mcp-ai' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'One database command that updates thousands of rows appears as a single entry showing one example row. The number of rows affected is not shown.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'The body text of posts and pages is not stored here — use the linked revision to see it. Some content types, including WooCommerce products, keep no revisions, and revisions can also be switched off site-wide. Where that is the case the entry says so plainly, and the previous body text cannot be recovered from this log or anywhere else.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Very large values are shortened, and the entry is marked as truncated.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Complicated database updates are stored as the command that ran, without before and after values.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'If a plugin changes a setting or custom field the normal way and, in the same request, also alters that same row directly in the database — for example switching whether the setting loads on every page — only the value change is recorded. This applies when "Capture raw database writes" is on.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'If one request makes more than 200 direct database writes, recording stops there and a single summary entry is added.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'If a plugin cancels a batch of changes part-way through, a few entries may describe changes that were undone. The reverse can also happen: genuine changes made earlier in the same request, before the cancelled part even began, can be lost from the log along with the cancelled ones.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'When a BuddyPress activity item is deleted, we record that it happened but not what it said. Deleted users, comments, settings and WooCommerce products do keep a record of what they were. The one exception is a network-wide setting on a multisite network: WordPress removes it before it tells us, so that entry says the previous value was not captured rather than implying it was empty.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'When a custom field is deleted, we normally capture its value first so the entry shows what was removed. If another plugin interferes with that step, the entry honestly shows that a value was removed without being able to say what it was, rather than guessing it was empty.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'On a single site (not part of a network), a change to a network-wide setting is labelled as an ordinary setting change rather than as network-wide. The change itself is still recorded in full — only the label is affected.', 'easy-mcp-ai' ); ?></li>
            </ul>

            <h3><?php esc_html_e( 'Depends on your settings', 'easy-mcp-ai' ); ?></h3>
            <ul>
                <li><?php esc_html_e( '"Capture raw database writes" is off until you turn it on, so changes plugins make directly to the database are not recorded by default.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Choosing to record only a small fixed list of options means plugin settings changes are not recorded anywhere.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Turning off extra field capture drops custom fields on categories, users and comments, and network-wide settings.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Routine background values are never recorded — scheduled-task bookkeeping and temporary cached data.', 'easy-mcp-ai' ); ?></li>
            </ul>

            <h3><?php esc_html_e( 'Hidden on purpose, to keep secrets safe', 'easy-mcp-ai' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Passwords, API keys and access tokens show as [REDACTED]. You can see that a secret changed, but not what it changed to.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Login and password tables are never read directly. User changes are recorded separately, with sensitive fields hidden.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'On a WooCommerce order, the customer\'s name, company, street address, postcode, email address and phone number are hidden. Town, county or state, and country are kept, so you can still see a delivery or tax area change. This log is separate from WooCommerce\'s own tools for handling a customer\'s personal-data requests.', 'easy-mcp-ai' ); ?></li>
            </ul>

            <h3><?php esc_html_e( 'How long entries are kept', 'easy-mcp-ai' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Normal entries are kept for 30 days and direct-database entries for 7 days. Both are adjustable, but this is not a permanent archive.', 'easy-mcp-ai' ); ?></li>
                <li><?php esc_html_e( 'Only standard WordPress content can be restored from history. Entries for a plugin\'s own database tables are a record, not an undo.', 'easy-mcp-ai' ); ?></li>
            </ul>
        </div>
    </details>
