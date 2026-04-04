<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode: [knx_reset_password]
 * Renders the password reset UI only.
 * Business logic is handled by auth-handler.php
 */
function knx_render_reset_password_shortcode($atts = []) {

    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $valid = false;

    if ($token && preg_match('/^[0-9a-f]{64}$/i', $token)) {
        $row = knx_get_password_reset_by_token($token);
        if ($row) $valid = true;
    }

    ob_start();

    echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/auth/auth-style.css?v=' . KNX_VERSION) . '">';

    echo '<div class="knx-auth-shell" data-mode="reset">';
    echo '<div class="knx-auth-card">';

    // Toast notification system
    if (file_exists(__DIR__ . '/auth-toast.php')) require_once __DIR__ . '/auth-toast.php';
    $knx_toast = class_exists('KNX_Auth_Toast') ? KNX_Auth_Toast::consume() : false;
    if ($knx_toast):
        $toast_type = esc_attr($knx_toast['type']);
        $toast_msg  = esc_html($knx_toast['message']);
    ?>
        <div class="knx-auth-toast knx-auth-toast--<?php echo $toast_type; ?>" aria-live="polite" role="status">
            <div class="knx-auth-toast__inner">
                <span class="knx-auth-toast__icon"></span>
                <div class="knx-auth-toast__msg"><?php echo $toast_msg; ?></div>
                <button type="button" class="knx-auth-toast__close" aria-label="Close">×</button>
            </div>
        </div>
        <script>
        (function(){
            const TOAST_TIMEOUT = 5000;
            const toast = document.querySelector('.knx-auth-toast');
            if (!toast) return;
            toast.classList.add('knx-auth-toast--anim-in');
            const closeBtn = toast.querySelector('.knx-auth-toast__close');
            function dismiss() {
                toast.classList.remove('knx-auth-toast--anim-in');
                toast.classList.add('knx-auth-toast--anim-out');
                setTimeout(() => toast.remove(), 300);
            }
            if (closeBtn) closeBtn.addEventListener('click', dismiss);
            setTimeout(dismiss, TOAST_TIMEOUT);
        })();
        </script>
    <?php
    endif;

    if (!$valid) {

        echo '<h1>Password Reset</h1>';
        echo '<p class="knx-auth-sub">This password reset link is invalid or has expired. Please request a new one.</p>';
        echo '<div class="knx-auth-links">';
        echo '<a href="' . esc_url(site_url('/login')) . '">Back to login</a>';
        echo '</div>';

    } else {

        echo '<h1>Set a new password</h1>';
        echo '<p class="knx-auth-sub">Choose a secure new password for your account.</p>';

        echo '<form method="post">';
        knx_nonce_field('reset');

        // Honeypot - timestamp set via JS to avoid caching issues
        echo '<div class="knx-hp" style="display:none;opacity:0;height:0;overflow:hidden;">';
        echo '<input type="text" name="knx_hp">';
        echo '<input type="hidden" name="knx_hp_ts" value="" class="knx-hp-ts">';
        echo '</div>';

        echo '<label>New password</label>';
        echo '<div class="knx-password-wrap">';
        echo '<input type="password" name="knx_reset_password" minlength="8" required>';
        echo '<button type="button" class="knx-pass-toggle" aria-label="Toggle password visibility">';
        echo '<i class="fas fa-eye"></i>';
        echo '</button>';
        echo '</div>';

        echo '<label>Confirm password</label>';
        echo '<div class="knx-password-wrap">';
        echo '<input type="password" name="knx_reset_password_confirm" minlength="8" required>';
        echo '<button type="button" class="knx-pass-toggle" aria-label="Toggle password visibility">';
        echo '<i class="fas fa-eye"></i>';
        echo '</button>';
        echo '</div>';

        echo '<input type="hidden" name="knx_reset_token" value="' . esc_attr($token) . '">';

        echo '<button type="submit" class="knx-btn-primary" name="knx_reset_btn" value="1">Set new password</button>';
        echo '</form>';
    }

    echo '</div></div>';

    // Password toggle script + honeypot timestamp
    ?>
    <script>
    (function(){
        const shell = document.querySelector('.knx-auth-shell');
        if (!shell) return;

        // Set honeypot timestamp on page load to avoid cache issues
        const hpTs = shell.querySelector('.knx-hp-ts');
        if (hpTs) hpTs.value = Math.floor(Date.now() / 1000);

        shell.addEventListener('click', function(e) {
            const toggleBtn = e.target.closest('.knx-pass-toggle');
            if (toggleBtn) {
                e.preventDefault();
                const wrap = toggleBtn.closest('.knx-password-wrap');
                const input = wrap ? wrap.querySelector('input') : null;
                const icon = toggleBtn.querySelector('i');

                if (input && icon) {
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                }
            }
        });
    })();
    </script>
    <?php

    return ob_get_clean();
}

add_shortcode('knx_reset_password', 'knx_render_reset_password_shortcode');
