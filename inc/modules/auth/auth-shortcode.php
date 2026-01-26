<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Auth Shortcode (v2)
 *
 * Shortcode: [knx_auth]
 * Provides a secure and modern login form.
 * Automatically redirects logged-in users to home.
 */

add_shortcode('knx_auth', function () {
    // Redirect if user already has a valid session
    $session = knx_get_session();
    if ($session) {
        wp_safe_redirect(site_url('/home'));
        exit;
    }

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/auth/auth-style.css'); ?>">

    <div class="knx-auth-container">
        <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-back-home">Back to Home</a>

        <div class="knx-auth-card">
            <h2>Sign In</h2>

            <form method="post">
                <?php knx_nonce_field('login'); ?>

                <div class="knx-input-group">
                    <input type="text" name="knx_login" placeholder="Username or Email" required>
                </div>

                <div class="knx-input-group">
                    <input type="password" name="knx_password" placeholder="Password" required>
                </div>

                <div class="knx-auth-options">
                    <label><input type="checkbox" name="knx_remember"> Remember me</label>
                    <a href="#">Forgot password?</a>
                </div>

                <button type="submit" name="knx_login_btn" class="knx-btn">Sign In</button>

                <p class="knx-signup-text">
                    New here? <a href="#">Create an Account</a>
                </p>
            </form>
        </div>
    </div>

    <?php
    return ob_get_clean();
});
