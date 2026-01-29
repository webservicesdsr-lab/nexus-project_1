<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode: [knx_reset_password]
 * Renders the password reset UI only. Business logic (processing form POST)
 * must be handled by the auth handler routing code.
 */
function knx_render_reset_password_shortcode($atts = []) {
    // token comes from query string: ?token=...
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $valid = false;

    if (!empty($token)) {
        $row = knx_get_password_reset_by_token($token);
        if ($row) $valid = true;
    }

    ob_start();

    // Reuse auth styles
    echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/auth/auth-style.css') . '">';

    echo '<div class="knx-auth-shell" data-mode="reset">';
    echo '<div class="knx-auth-card">';

    if (!$valid) {
        echo '<h1>Password Reset</h1>';
        echo '<p class="knx-auth-sub">This password reset link is invalid or has expired. Please request a new link from the login page.</p>';
        echo '<div class="knx-auth-links">';
        echo '<a href="' . esc_url(site_url('/login')) . '">Back to login</a>';
        echo '</div>';
    } else {
        echo '<h1>Set a new password</h1>';
        echo '<p class="knx-auth-sub">Choose a secure new password for your account.</p>';

        // The form posts to the routing handler. Name the submit so handler can detect it.
        echo '<form method="post">';
        knx_nonce_field('reset');

        // Honeypot
        echo '<div class="knx-hp" style="display:none;opacity:0;height:0;overflow:hidden;">';
        echo '<input type="text" name="knx_hp">';
        echo '<input type="hidden" name="knx_hp_ts" value="' . esc_attr(time()) . '">';
        echo '</div>';

        echo '<label>New password</label>';
        echo '<input type="password" name="knx_reset_password" required>';

        echo '<label>Confirm password</label>';
        echo '<input type="password" name="knx_reset_password_confirm" required>';

        // include token as hidden field so routing handler can validate
        echo '<input type="hidden" name="knx_reset_token" value="' . esc_attr($token) . '">';

        echo '<button class="knx-btn-primary" name="knx_reset_btn">Set new password</button>';
        echo '</form>';
    }

    echo '</div></div>';

    return ob_get_clean();
}

add_shortcode('knx_reset_password', 'knx_render_reset_password_shortcode');
