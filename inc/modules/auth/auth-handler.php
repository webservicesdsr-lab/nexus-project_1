<?php
if (!defined('ABSPATH')) exit;

/**
 * KNX Auth Handler
 * Centralized handling for login, password reset, registration and logout.
 * Runs on the `init` hook so form POSTs are processed early.
 */
add_action('init', function () {
    global $wpdb;
    $users_table = $wpdb->prefix . 'knx_users';

    // ------------------
    // LOGIN (form POST)
    // ------------------
    if (isset($_POST['knx_login_btn'])) {
        $ip = knx_get_client_ip();

        // Honeypot
        if (!knx_check_honeypot($_POST)) {
            $login_attempt = isset($_POST['knx_login']) ? sanitize_text_field($_POST['knx_login']) : '';
            knx_record_failed_login($ip, $login_attempt);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $login_attempt = isset($_POST['knx_login']) ? sanitize_text_field($_POST['knx_login']) : '';

        // Rate-limit checks
        if (knx_is_ip_blocked($ip) || ($login_attempt !== '' && knx_is_user_blocked($login_attempt))) {
            wp_safe_redirect(site_url('/login?error=locked'));
            exit;
        }

        // Nonce
        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'login')) {
            knx_record_failed_login($ip, $login_attempt);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $login    = $login_attempt;
        $password = sanitize_text_field($_POST['knx_password'] ?? '');
        $remember = isset($_POST['knx_remember']);

        // Lookup by username or email
        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$users_table} WHERE username = %s OR email = %s LIMIT 1", $login, $login));

        if (!$user) {
            knx_record_failed_login($ip, $login);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        if ($user->status !== 'active') {
            knx_record_failed_login($ip, $login);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        if (!password_verify($password, $user->password)) {
            knx_record_failed_login($ip, $login);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Create session / auto-login
        $ok = knx_auto_login_user_by_id(intval($user->id), $remember);
        if (!$ok) {
            knx_record_failed_login($ip, $login);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        // Redirect by role
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
    if (isset($_POST['knx_reset_btn'])) {
        $ip = knx_get_client_ip();

        if (!knx_check_honeypot($_POST)) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        if (knx_is_ip_blocked($ip)) {
            wp_safe_redirect(site_url('/login?error=locked'));
            exit;
        }

        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'reset')) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        if (empty($_POST['knx_reset_token'])) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }
        $token = sanitize_text_field($_POST['knx_reset_token']);

        $row = knx_get_password_reset_by_token($token);
        if (!$row) {
            knx_record_failed_login($ip, '');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $reset_id = isset($row->id) ? intval($row->id) : 0;
        $user_id = isset($row->user_id) ? intval($row->user_id) : 0;

        if ($user_id <= 0) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        if (!isset($_POST['knx_reset_password']) || !isset($_POST['knx_reset_password_confirm'])) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $pass = trim($_POST['knx_reset_password']);
        $pass2 = trim($_POST['knx_reset_password_confirm']);

        if ($pass !== $pass2) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        if (strlen($pass) < 8) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);

        $updated = $wpdb->update($users_table, ['password' => $hash], ['id' => $user_id], ['%s'], ['%d']);
        if ($updated === false) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        knx_mark_password_reset_used($reset_id);
        knx_invalidate_password_resets_for_user($user_id);

        if (function_exists('knx_invalidate_user_sessions')) {
            knx_invalidate_user_sessions($user_id);
        }

        $ok = knx_auto_login_user_by_id($user_id, false);
        if (!$ok) {
            knx_record_failed_login($ip, 'reset');
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        wp_safe_redirect(site_url('/cart'));
        exit;
    }

    // Handle public customer registration
    if (isset($_POST['knx_register_btn'])) {
        $ip = knx_get_client_ip();

        if (!knx_check_honeypot($_POST)) {
            $email_try = isset($_POST['knx_register_email']) ? sanitize_email($_POST['knx_register_email']) : '';
            knx_record_failed_login($ip, $email_try);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $email = isset($_POST['knx_register_email']) ? sanitize_email($_POST['knx_register_email']) : '';
        $pass  = isset($_POST['knx_register_password']) ? trim($_POST['knx_register_password']) : '';
        $pass2 = isset($_POST['knx_register_password_confirm']) ? trim($_POST['knx_register_password_confirm']) : '';

        if (knx_is_ip_blocked($ip) || ($email !== '' && knx_is_user_blocked($email))) {
            wp_safe_redirect(site_url('/login?error=locked'));
            exit;
        }

        if (!isset($_POST['knx_register_nonce']) || !knx_verify_nonce($_POST['knx_register_nonce'], 'register')) {
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        if (empty($email) || !is_email($email) || empty($pass) || $pass !== $pass2) {
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$users_table} WHERE email = %s LIMIT 1", $email));
        if ($exists) {
            knx_record_failed_login($ip, $email);
            wp_safe_redirect(site_url('/login?error=auth'));
            exit;
        }

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
