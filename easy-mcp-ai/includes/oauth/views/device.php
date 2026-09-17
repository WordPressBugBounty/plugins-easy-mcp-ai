<?php



















if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


$easy_mcp_ai_brand_bg         = '#f5f5ef';
$easy_mcp_ai_brand_surface    = '#ffffff';
$easy_mcp_ai_brand_ink        = '#0b1220';
$easy_mcp_ai_brand_ink_soft   = '#4b5563';
$easy_mcp_ai_brand_border     = '#e5e7eb';
$easy_mcp_ai_brand_accent     = '#c8f542';
$easy_mcp_ai_brand_accent_ink = '#0b1220';
$easy_mcp_ai_brand_warn_bg    = '#fff8e1';
$easy_mcp_ai_brand_warn_br    = '#e6c64a';
$easy_mcp_ai_brand_ok_bg      = '#f0fbe6';
$easy_mcp_ai_brand_ok_br      = '#9fd35a';
$easy_mcp_ai_brand_err_bg     = '#fdf1f0';
$easy_mcp_ai_brand_err_br     = '#f0a8a0';

$easy_mcp_ai_stage = isset( $stage ) ? (string) $stage : 'enter';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html__( 'Connect a Device', 'easy-mcp-ai' ); ?> &mdash; <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
</head>
<body style="font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',sans-serif;background:<?php echo esc_attr( $easy_mcp_ai_brand_bg ); ?>;color:<?php echo esc_attr( $easy_mcp_ai_brand_ink ); ?>;line-height:1.55;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:40px 20px;margin:0;">

<div style="background:<?php echo esc_attr( $easy_mcp_ai_brand_surface ); ?>;border:1px solid <?php echo esc_attr( $easy_mcp_ai_brand_border ); ?>;border-radius:14px;box-shadow:0 10px 40px rgba(11,18,32,0.06);max-width:520px;width:100%;overflow:hidden;">

    <div style="background:<?php echo esc_attr( $easy_mcp_ai_brand_ink ); ?>;color:#fff;padding:28px 36px;display:flex;align-items:center;gap:14px;">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:<?php echo esc_attr( $easy_mcp_ai_brand_accent ); ?>;color:<?php echo esc_attr( $easy_mcp_ai_brand_accent_ink ); ?>;font-weight:800;font-size:18px;letter-spacing:-0.5px;">W</span>
        <div>
            <h1 style="font-size:20px;font-weight:700;margin:0;letter-spacing:-0.3px;"><?php echo esc_html__( 'Connect a Device', 'easy-mcp-ai' ); ?></h1>
            <span style="font-size:13px;opacity:0.7;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
        </div>
    </div>

    <div style="padding:28px 36px;">
    <?php if ( 'done' === $easy_mcp_ai_stage ) : ?>

        <?php
        $easy_mcp_ai_box_bg = ! empty( $approved ) ? $easy_mcp_ai_brand_ok_bg : $easy_mcp_ai_brand_err_bg;
        $easy_mcp_ai_box_br = ! empty( $approved ) ? $easy_mcp_ai_brand_ok_br : $easy_mcp_ai_brand_err_br;
        ?>
        <div style="background:<?php echo esc_attr( $easy_mcp_ai_box_bg ); ?>;border:1px solid <?php echo esc_attr( $easy_mcp_ai_box_br ); ?>;border-radius:10px;padding:18px 20px;">
            <div style="font-size:17px;font-weight:700;margin-bottom:6px;"><?php echo esc_html( isset( $title ) ? $title : '' ); ?></div>
            <div style="font-size:13px;color:<?php echo esc_attr( $easy_mcp_ai_brand_ink_soft ); ?>;line-height:1.6;"><?php echo esc_html( isset( $message ) ? $message : '' ); ?></div>
        </div>

    <?php else : ?>

        <?php if ( ! empty( $error ) ) : ?>
        <div style="background:<?php echo esc_attr( $easy_mcp_ai_brand_err_bg ); ?>;border:1px solid <?php echo esc_attr( $easy_mcp_ai_brand_err_br ); ?>;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <p style="margin:0 0 14px;font-size:14px;">
            <?php echo esc_html__( 'An application running in a terminal or on another machine is waiting to connect to this site. Enter the code it displayed.', 'easy-mcp-ai' ); ?>
        </p>

        <div style="background:<?php echo esc_attr( $easy_mcp_ai_brand_warn_bg ); ?>;border:1px solid <?php echo esc_attr( $easy_mcp_ai_brand_warn_br ); ?>;border-radius:10px;padding:12px 16px;margin-bottom:22px;font-size:13px;line-height:1.6;">
            <?php echo esc_html__( 'Only enter a code you started yourself, on a device you control. A code someone else sent you would connect their application to your account.', 'easy-mcp-ai' ); ?>
        </div>

        <form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin:0;">
            <?php wp_nonce_field( 'easy_mcp_ai_oauth_device_lookup' ); ?>
            <input type="hidden" name="device_action" value="lookup">

            <label for="easy-mcp-ai-user-code" style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:<?php echo esc_attr( $easy_mcp_ai_brand_ink_soft ); ?>;margin-bottom:8px;"><?php echo esc_html__( 'Code', 'easy-mcp-ai' ); ?></label>
            <input id="easy-mcp-ai-user-code" type="text" name="user_code" value="" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus required maxlength="<?php echo esc_attr( (int) $code_length + 3 ); ?>" placeholder="XXXX-XXXX" style="display:block;width:100%;box-sizing:border-box;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:26px;letter-spacing:0.25em;text-transform:uppercase;text-align:center;padding:12px 14px;border:1.5px solid <?php echo esc_attr( $easy_mcp_ai_brand_border ); ?>;border-radius:10px;color:<?php echo esc_attr( $easy_mcp_ai_brand_ink ); ?>;background:#fafafa;">

            <div style="display:flex;justify-content:flex-end;margin-top:22px;">
                <button type="submit" style="display:inline-block;padding:10px 26px;font-size:14px;font-weight:700;border-radius:999px;border:1px solid <?php echo esc_attr( $easy_mcp_ai_brand_ink ); ?>;cursor:pointer;background:<?php echo esc_attr( $easy_mcp_ai_brand_accent ); ?>;color:<?php echo esc_attr( $easy_mcp_ai_brand_accent_ink ); ?>;box-shadow:0 1px 0 <?php echo esc_attr( $easy_mcp_ai_brand_ink ); ?>;">
                    <?php echo esc_html__( 'Continue', 'easy-mcp-ai' ); ?>
                </button>
            </div>
        </form>

        <?php
        



















        ?>
        <script nonce="<?php echo esc_attr( isset( $script_nonce ) ? $script_nonce : '' ); ?>">
(function () {
    'use strict';
    var field = document.getElementById('easy-mcp-ai-user-code');
    if (!field) { return; }
    var LETTERS = <?php echo (int) $code_length; ?>;
    var HALF = LETTERS / 2;
    function letters(value) { return value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, LETTERS); }
    function format(value) {
        var s = letters(value);
        return s.length > HALF ? s.slice(0, HALF) + '-' + s.slice(HALF) : s;
    }
    function caretAfter(count, formatted) {
        var pos = 0, seen = 0;
        while (pos < formatted.length && seen < count) {
            if (formatted.charAt(pos) !== '-') { seen++; }
            pos++;
        }
        if (pos < formatted.length && formatted.charAt(pos) === '-') { pos++; }
        return pos;
    }
    function reformat() {
        var before = field.value;
        var caret = field.selectionStart;
        var lettersBefore = letters(before.slice(0, caret)).length;
        var next = format(before);
        if (next !== before) { field.value = next; }
        var at = caretAfter(lettersBefore, next);
        try { field.setSelectionRange(at, at); } catch (e) {}
    }
    field.addEventListener('keydown', function (e) {
        if (e.key !== 'Backspace') { return; }
        var start = field.selectionStart, end = field.selectionEnd;
        if (start !== end || start < 1 || field.value.charAt(start - 1) !== '-') { return; }
        e.preventDefault();
        var v = field.value;
        field.value = v.slice(0, start - 2) + v.slice(start);
        var at = start - 2;
        try { field.setSelectionRange(at, at); } catch (err) {}
        reformat();
    });
    field.addEventListener('input', reformat);
    if (field.value) { reformat(); }
})();
        </script>

    <?php endif; ?>
    </div>
</div>

<div style="margin-top:18px;font-size:12px;color:<?php echo esc_attr( $easy_mcp_ai_brand_ink_soft ); ?>;">
    <?php echo esc_html__( 'Powered by Easy MCP AI', 'easy-mcp-ai' ); ?>
</div>

</body>
</html>
