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

    // ===============================
    // PASSWORD RESET HANDLER (POST)
    // ===============================
    // Handle password reset (form POST from [knx_reset_password])
    if (isset($_POST['knx_reset_btn'])) {
        // Step 2: get client IP
        $ip = knx_get_client_ip();

        // Step 3: honeypot
        if (!knx_check_honeypot($_POST)) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Step 4: check IP block
        if (knx_is_ip_blocked($ip)) {
            wp_safe_redirect(site_url('/login?error=locked'));
            exit;
        }

        // Step 5: validate nonce
        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'reset')) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Step 6: presence of token
        if (empty($_POST['knx_reset_token'])) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }
        $token = sanitize_text_field($_POST['knx_reset_token']);

        // Step 7: validate token row
        $row = knx_get_password_reset_by_token($token);
        if (!$row) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Step 8: derive ids
        $reset_id = isset($row->id) ? intval($row->id) : 0;
        $user_id = isset($row->user_id) ? intval($row->user_id) : 0;

        // Step 8b: validate derived user_id (fail-closed)
        if ($user_id <= 0) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Step 9: validate password fields presence
        if (!isset($_POST['knx_reset_password']) || !isset($_POST['knx_reset_password_confirm'])) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $pass = trim($_POST['knx_reset_password']);
        $pass2 = trim($_POST['knx_reset_password_confirm']);

        // Step 10: passwords must match
        if ($pass !== $pass2) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Step 11: minimal strength (enforce at least 8 chars)
        // TODO: expand strength check (length + complexity) per system standard
        if (strlen($pass) < 8) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Step 12: prepare hash
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        // Step 13: persist new password for user
        $updated = $wpdb->update($users_table, ['password' => $hash], ['id' => $user_id], ['%s'], ['%d']);
        if ($updated === false) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Step 14: mark token used and invalidate other tokens
        knx_mark_password_reset_used($reset_id);
        knx_invalidate_password_resets_for_user($user_id);

        // Step 15: invalidate existing sessions for user
        if (function_exists('knx_invalidate_user_sessions')) {
            knx_invalidate_user_sessions($user_id);
        }

        // Step 16: auto-login
        $ok = knx_auto_login_user_by_id($user_id, false);
        if (!$ok) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Step 17: success redirect
        wp_safe_redirect(site_url('/cart'));
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
