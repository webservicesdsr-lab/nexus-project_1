<?php
if (!defined('ABSPATH')) exit;

/**
        // Honeypot: quick bot detection
        if (!knx_check_honeypot($_POST)) {
            // Record attempt for IP and optional login
            $login_attempt = isset($_POST['knx_login']) ? sanitize_text_field($_POST['knx_login']) : '';
            knx_record_failed_login($ip, $login_attempt);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Rate limit protection: IP or user block
        $login_attempt = isset($_POST['knx_login']) ? sanitize_text_field($_POST['knx_login']) : '';
        if (knx_is_ip_blocked($ip) || ($login_attempt !== '' && knx_is_user_blocked($login_attempt))) {
            wp_safe_redirect(site_url('/login?error=locked'));
            exit;
        }

        // Validate nonce
        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'login')) {
            // Generic failure
            knx_record_failed_login($ip, $login_attempt);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $login    = $login_attempt;
        $password = sanitize_text_field($_POST['knx_password'] ?? '');
        $remember = isset($_POST['knx_remember']);

        // Lookup user by username or email
        $sql = "SELECT * FROM {$users_table} WHERE username = %s OR email = %s LIMIT 1";
        $user = $wpdb->get_row($wpdb->prepare($sql, $login, $login));

        // Validation
        if (!$user || $user->status !== 'active' || !password_verify($password, $user->password)) {
            knx_record_failed_login($ip, $login);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Success: create session & claim cart via helper
        $ok = knx_auto_login_user_by_id(intval($user->id), $remember);
        if (!$ok) {
            // fallback: if session creation failed, record and show generic error
            knx_record_failed_login($ip, $login);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // PHASE 2.A — Redirect (canonical funnel)
        $redirects = [
            'super_admin'   => '/cart',
            'manager'       => '/cart',
            'menu_uploader' => '/cart',
            'driver'        => '/driver-dashboard',
            'customer'      => '/cart',
            'user'          => '/cart'
        ];

        $target = isset($redirects[$user->role]) ? $redirects[$user->role] : '/cart';
        wp_safe_redirect(site_url($target));
        exit;

    }

    // Handle public customer registration
    if (isset($_POST['knx_register_btn'])) {
        $ip = knx_get_client_ip();

        // Honeypot
        if (!knx_check_honeypot($_POST)) {
            $email_try = isset($_POST['knx_register_email']) ? sanitize_email($_POST['knx_register_email']) : '';
            knx_record_failed_login($ip, $email_try);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $email = isset($_POST['knx_register_email']) ? sanitize_email($_POST['knx_register_email']) : '';
        $pass  = isset($_POST['knx_register_password']) ? trim($_POST['knx_register_password']) : '';
        $pass2 = isset($_POST['knx_register_password_confirm']) ? trim($_POST['knx_register_password_confirm']) : '';

        // Rate limit check
        if (knx_is_ip_blocked($ip) || ($email !== '' && knx_is_user_blocked($email))) {
            wp_safe_redirect(site_url('/login?error=locked'));
            exit;
        }

        // Nonce
        if (!isset($_POST['knx_register_nonce']) || !knx_verify_nonce($_POST['knx_register_nonce'], 'register')) {
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Basic validation
        if (empty($email) || !is_email($email) || empty($pass) || $pass !== $pass2) {
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Ensure email unique
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$users_table} WHERE email = %s LIMIT 1", $email));
        if ($exists) {
            // Do not reveal existence
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Insert new customer (fail-closed)
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $inserted = $wpdb->insert($users_table, [
            'username' => $email,
            'email'    => $email,
            'password' => $hash,
            'role'     => 'customer',
            'status'   => 'active'
        ], ['%s','%s','%s','%s','%s']);

        if ($inserted === false) {
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $new_id = (int) $wpdb->insert_id;

        // Auto-login and claim cart
        $ok = knx_auto_login_user_by_id($new_id, false);
        if (!$ok) {
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        wp_safe_redirect(site_url('/cart'));
        exit;
    }

    // Handle logout (form-based)
    if (isset($_POST['knx_logout'])) {
        if (!isset($_POST['knx_logout_nonce']) || !wp_verify_nonce($_POST['knx_logout_nonce'], 'knx_logout_action')) {
            wp_die('Security check failed.');
        }

        knx_logout_user();
        exit;
    }
});


/**
 * AJAX Logout (for sidebar / navbar)
 * Secure version — requires valid session & nonce
 */
add_action('wp_ajax_knx_logout_user', function() {
    $session = knx_get_session();
    if (!$session) {
        wp_send_json_error(['message' => 'Unauthorized'], 401);
    }

    // Validate nonce
    $nonce = sanitize_text_field($_POST['knx_logout_nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'knx_logout_action')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    // Perform logout
    knx_logout_user();

    wp_send_json_success(['redirect' => site_url('/login')]);
});


/**
 * Handle Forgot Password requests (POST) and show generic UX.
 */
add_action('template_redirect', function() {
    if (!empty($_POST['knx_forgot_btn'])) {
        global $wpdb;
        $users_table = $wpdb->prefix . 'knx_users';
        $ip = knx_get_client_ip();

        // Honeypot
        if (!knx_check_honeypot($_POST)) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?forgot=1'));
            exit;
        }

        $email = isset($_POST['knx_forgot_email']) ? sanitize_email($_POST['knx_forgot_email']) : '';

        // Rate-limit checks (do not reveal)
        if (knx_is_ip_blocked($ip) || ($email !== '' && knx_is_user_blocked($email))) {
            wp_safe_redirect(site_url('/login?forgot=1'));
            exit;
        }

        // Nonce
        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'forgot')) {
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?forgot=1'));
            exit;
        }

        // Lookup user by email silently
        $user_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$users_table} WHERE email = %s LIMIT 1", $email));

        if ($user_id) {
            // Create token and send email; fail-closed (don't reveal failures)
            $token = knx_create_password_reset(intval($user_id), $ip);
            if ($token) {
                $reset_link = site_url('/reset-password?token=' . rawurlencode($token));

                $subject = 'Password reset request';
                $html = '<div style="font-family:system-ui, -apple-system, Roboto, "Segoe UI", "Helvetica Neue", Arial; color:#111">'
                    . '<p>Hello,</p>'
                    . '<p>We received a request to reset your password. Click the link below to reset it. This link will expire in 60 minutes.</p>'
                    . '<p><a href="' . esc_url($reset_link) . '" style="color:#1E6FF0;">Reset your password</a></p>'
                    . '<p>If you did not request this, you can ignore this message.</p>'
                    . '<p>— ' . esc_html(get_bloginfo('name')) . '</p>'
                    . '</div>';

                // Use wrapper
                knx_send_email($email, $subject, $html);
            }
        }

        // Always show the same UX
        wp_safe_redirect(site_url('/login?forgot=1'));
        exit;
    }
});


/**
 * Reset password shortcode & handler. Shortcode: [knx_reset_password]
 */
add_shortcode('knx_reset_password', function() {
    global $wpdb;
    $users_table = $wpdb->prefix . 'knx_users';

    ob_start();

    // If POSTing new password
    if (!empty($_POST['knx_reset_btn'])) {
        $ip = knx_get_client_ip();

        if (!knx_check_honeypot($_POST)) {
            knx_record_failed_login($ip, '');
            echo '<div class="knx-auth-error">Invalid request. Please try again.</div>';
            return ob_get_clean();
        }

        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'reset')) {
            knx_record_failed_login($ip, '');
            echo '<div class="knx-auth-error">Invalid request. Please try again.</div>';
            return ob_get_clean();
        }

        $token = isset($_POST['knx_reset_token']) ? trim($_POST['knx_reset_token']) : '';
        $pass = isset($_POST['knx_new_password']) ? trim($_POST['knx_new_password']) : '';
        $pass2 = isset($_POST['knx_new_password_confirm']) ? trim($_POST['knx_new_password_confirm']) : '';

        // Basic strength: min 8, letter and number
        $ok_strength = (strlen($pass) >= 8) && preg_match('/[A-Za-z]/', $pass) && preg_match('/[0-9]/', $pass);
        if ($pass === '' || $pass !== $pass2 || !$ok_strength) {
            echo '<div class="knx-auth-error">Invalid input. Please make sure passwords match and meet strength requirements.</div>';
            return ob_get_clean();
        }

        $reset_row = knx_get_password_reset_by_token($token);
        if (!$reset_row) {
            echo '<div class="knx-auth-error">Invalid or expired token. Please request a new reset.</div>';
            return ob_get_clean();
        }

        $user_id = intval($reset_row->user_id);

        // Update password (fail-closed)
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $updated = $wpdb->update($users_table, ['password' => $hash], ['id' => $user_id], ['%s'], ['%d']);
        if ($updated === false) {
            echo '<div class="knx-auth-error">An error occurred. Please try again.</div>';
            return ob_get_clean();
        }

        // Mark token used and invalidate sessions
        knx_mark_password_reset_used(intval($reset_row->id));
        knx_invalidate_user_sessions($user_id);

        wp_safe_redirect(site_url('/login?reset=success'));
        exit;
    }

    // Show form if token present in querystring
    $token = isset($_GET['token']) ? trim($_GET['token']) : '';
    $valid = false;
    if ($token) {
        $valid = (bool) knx_get_password_reset_by_token($token);
    }

    if (!$token || !$valid) {
        echo '<div class="knx-auth-card" style="max-width:560px;margin:40px auto;">';
        echo '<h2>Invalid or expired reset link</h2>';
        echo '<p>If you still need access, request a new password reset from the <a href="' . esc_url(site_url('/login')) . '">login page</a>.</p>';
        echo '</div>';
        return ob_get_clean();
    }

    // Render reset form
    ?>
    <div class="knx-auth-shell" style="min-height:0;padding:24px;">
      <div class="knx-auth-card">
        <h1>Reset password</h1>
        <p class="knx-auth-sub">Enter a new password for your account.</p>
        <form method="post">
          <?php knx_nonce_field('reset'); ?>
          <div class="knx-hp" style="display:none;">
            <input type="text" name="knx_hp">
            <input type="hidden" name="knx_hp_ts" value="<?php echo time(); ?>">
          </div>
          <input type="hidden" name="knx_reset_token" value="<?php echo esc_attr($token); ?>">
          <label>New password</label>
          <input type="password" name="knx_new_password" required>
          <label>Confirm password</label>
          <input type="password" name="knx_new_password_confirm" required>
          <button class="knx-btn-primary" name="knx_reset_btn">Set new password</button>
        </form>
      </div>
    </div>
    <?php

    return ob_get_clean();
});
