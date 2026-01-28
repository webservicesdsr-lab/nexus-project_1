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
