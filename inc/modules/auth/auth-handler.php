<?php
if (!defined('ABSPATH')) exit;

// Ensure AUTH_TOAST is available
if (file_exists(__DIR__ . '/auth-toast.php')) {
    require_once __DIR__ . '/auth-toast.php';
}

// Email verification helpers
if (file_exists(__DIR__ . '/email-verification.php')) {
    require_once __DIR__ . '/email-verification.php';
}

// (mail-failure-log was removed after diagnosis)

/**
 * KNX Auth Handler
 * Production-ready authentication flow.
 * - SMTP plugin is the single source of truth for mail transport.
 * - No runtime schema creation.
 * - No logging.
 * - Deterministic UX with AUTH_TOAST.
 */
add_action('init', function () {

    global $wpdb;
    $users_table = $wpdb->prefix . 'knx_users';

    /**
     * ---------------------------------------
     * EMAIL VERIFICATION ENDPOINT
     * /verify-email?token=...
     * ---------------------------------------
     */
    if (
        isset($_GET['token']) &&
        isset($_SERVER['REQUEST_URI']) &&
        strpos($_SERVER['REQUEST_URI'], '/verify-email') !== false
    ) {
        $raw = sanitize_text_field($_GET['token']);

        // Basic sanity check (64 hex chars)
        if (!preg_match('/^[0-9a-f]{64}$/i', $raw)) {
            if (class_exists('KNX_Auth_Toast')) {
                KNX_Auth_Toast::push('error', 'verify_invalid');
            }
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $row = knx_get_email_verification_by_token($raw);

        if (!$row) {
            if (class_exists('KNX_Auth_Toast')) {
                KNX_Auth_Toast::push('error', 'verify_invalid');
            }
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        // Mark token used
        knx_mark_email_verification_used((int) $row->id);

        // Activate user
        $wpdb->update(
            $users_table,
            ['status' => 'active'],
            ['id' => (int) $row->user_id],
            ['%s'],
            ['%d']
        );

        if (class_exists('KNX_Auth_Toast')) {
            KNX_Auth_Toast::push('success', 'verify_success');
        }

        wp_safe_redirect(site_url('/login'));
        exit;
    }

    /**
     * ---------------------------------------
     * LOGIN
     * ---------------------------------------
     */
    if (isset($_POST['knx_login_btn'])) {

        $ip = knx_get_client_ip();
        $login = sanitize_text_field($_POST['knx_login'] ?? '');

        if (!knx_check_honeypot($_POST)) {
            knx_record_failed_login($ip, $login);
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        if (knx_is_ip_blocked($ip) || knx_is_user_blocked($login)) {
            KNX_Auth_Toast::push('error', 'locked');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        if (
            !isset($_POST['knx_nonce']) ||
            !knx_verify_nonce($_POST['knx_nonce'], 'login')
        ) {
            knx_record_failed_login($ip, $login);
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $password = $_POST['knx_password'] ?? '';

        $user = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$users_table} WHERE email = %s OR username = %s LIMIT 1",
                $login,
                $login
            )
        );

        if (!$user || !password_verify($password, $user->password)) {
            knx_record_failed_login($ip, $login);
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        if ($user->status !== 'active') {
            KNX_Auth_Toast::push('warning', 'inactive');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        if (!knx_auto_login_user_by_id((int) $user->id, !empty($_POST['knx_remember']))) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        wp_safe_redirect(site_url('/cart'));
        exit;
    }

    /**
     * ---------------------------------------
     * REGISTRATION (CUSTOMER)
     * ---------------------------------------
     */
    if (isset($_POST['knx_register_btn'])) {

        $ip    = knx_get_client_ip();
        $email = sanitize_email($_POST['knx_register_email'] ?? '');
        $pass  = $_POST['knx_register_password'] ?? '';
        $pass2 = $_POST['knx_register_password_confirm'] ?? '';

        if (
            !isset($_POST['knx_register_nonce']) ||
            !knx_verify_nonce($_POST['knx_register_nonce'], 'register')
        ) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        if (
            !is_email($email) ||
            empty($pass) ||
            $pass !== $pass2
        ) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        if ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$users_table} WHERE email = %s LIMIT 1",
            $email
        ))) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $wpdb->insert(
            $users_table,
            [
                'username' => $email,
                'email'    => $email,
                'password' => password_hash($pass, PASSWORD_DEFAULT),
                'role'     => 'customer',
                'status'   => 'inactive'
            ],
            ['%s','%s','%s','%s','%s']
        );

        $user_id = (int) $wpdb->insert_id;

        $raw_token = knx_create_email_verification_token($user_id);

        if ($raw_token) {
            $verify_url = site_url('/verify-email?token=' . urlencode($raw_token));

            $subject = 'Verify your account';
            $message = '<p>Thanks for registering.</p>';
            $message .= '<p><a href="' . esc_url($verify_url) . '">Activate your account</a></p>';

            $headers = 'Content-Type: text/html; charset=UTF-8';

            wp_mail($email, $subject, $message, $headers);

            KNX_Auth_Toast::push('success', 'verify_sent');
        } else {
            KNX_Auth_Toast::push('error', 'verify_send_failed');
        }

        wp_safe_redirect(site_url('/login'));
        exit;
    }

    /**
     * ---------------------------------------
     * LOGOUT
     * ---------------------------------------
     */
    if (isset($_POST['knx_logout'])) {
        if (
            !isset($_POST['knx_logout_nonce']) ||
            !wp_verify_nonce($_POST['knx_logout_nonce'], 'knx_logout_action')
        ) {
            wp_die('Security check failed.');
        }

        knx_logout_user();
        exit;
    }

}, 9999); // late init = SMTP-safe
