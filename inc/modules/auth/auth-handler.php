<?php
if (!defined('ABSPATH')) exit;

// ==============================
// Dependencies
// ==============================
if (file_exists(__DIR__ . '/auth-toast.php')) {
    require_once __DIR__ . '/auth-toast.php';
}

if (file_exists(__DIR__ . '/email-verification.php')) {
    require_once __DIR__ . '/email-verification.php';
}

// Simple mail queue + redirect queue for late delivery on shutdown
if (!function_exists('knx_queue_mail')) {
    global $knx_mail_queue;
    $knx_mail_queue = [];

    function knx_queue_mail($to, $subject, $html, $headers = []) {
        global $knx_mail_queue;
        $knx_mail_queue[] = [
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'headers' => $headers,
        ];
        return true;
    }

    // queue a redirect to run after mails are sent
    global $knx_pending_redirect;
    $knx_pending_redirect = null;

    function knx_queue_redirect($url) {
        global $knx_pending_redirect;
        $knx_pending_redirect = $url;
    }

    function knx_send_queued_mails_and_maybe_redirect() {
        global $knx_mail_queue, $knx_pending_redirect;

        if (!empty($knx_mail_queue) && is_array($knx_mail_queue)) {
            foreach ($knx_mail_queue as $m) {
                // ensure headers are an array
                $headers = is_array($m['headers']) ? $m['headers'] : [$m['headers']];
                // attempt send
                try {
                    wp_mail($m['to'], $m['subject'], $m['html'], $headers);
                } catch (\Exception $e) {
                    error_log('knx mail send error: ' . $e->getMessage());
                }
            }
            // clear queue so multiple shutdown calls don't resend
            $knx_mail_queue = [];
        }

        if (!empty($knx_pending_redirect)) {
            $url = $knx_pending_redirect;
            // only redirect if headers not already sent
            if (!headers_sent()) {
                wp_safe_redirect($url);
                exit;
            }
        }
    }

    add_action('shutdown', 'knx_send_queued_mails_and_maybe_redirect', 0);
}

// ==============================
// AUTH HANDLER (FULL)
// ==============================
add_action('init', function () {

    global $wpdb;
    $users_table = $wpdb->prefix . 'knx_users';

    // =====================================================
    // EMAIL VERIFICATION ENDPOINT
    // =====================================================
    if (
        isset($_GET['token']) &&
        isset($_SERVER['REQUEST_URI']) &&
        strpos($_SERVER['REQUEST_URI'], '/verify-email') !== false
    ) {
        $raw = sanitize_text_field($_GET['token']);

        if (!preg_match('/^[0-9a-f]{64}$/i', $raw)) {
            KNX_Auth_Toast::push('error', 'verify_invalid');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $row = knx_get_email_verification_by_token($raw);
        if (!$row) {
            KNX_Auth_Toast::push('error', 'verify_invalid');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        knx_mark_email_verification_used((int)$row->id);

        $wpdb->update(
            $users_table,
            ['status' => 'active'],
            ['id' => (int)$row->user_id],
            ['%s'],
            ['%d']
        );

        KNX_Auth_Toast::push('success', 'verify_success');
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // =====================================================
    // FORGOT PASSWORD (REQUEST)
    // =====================================================
    if (isset($_POST['knx_forgot_btn'])) {

        $ip = knx_get_client_ip();

        if ((isset($_POST['knx_hp']) || isset($_POST['knx_hp_ts'])) && !knx_check_honeypot($_POST)) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'forgot')) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $email = sanitize_email($_POST['knx_forgot_email'] ?? '');
        if (is_email($email)) {
            $user = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$users_table} WHERE email = %s LIMIT 1",
                $email
            ));

            if ($user && function_exists('knx_create_password_reset')) {
                $raw = knx_create_password_reset((int)$user->id, $ip);
                if ($raw && function_exists('knx_get_password_reset_email')) {
                    $reset_url = site_url('/reset-password?token=' . urlencode($raw));
                    $mail = knx_get_password_reset_email($reset_url);
                    $headers = ['Content-Type: text/html; charset=UTF-8'];
                    // queue mail to be sent on shutdown
                    knx_queue_mail($email, $mail['subject'], $mail['html'], $headers);
                }
            }
        }

        KNX_Auth_Toast::push('success', 'reset_sent');
        // queue redirect so mail is sent first on shutdown
        knx_queue_redirect(site_url('/login'));
        exit;
    }

    // =====================================================
    // RESET PASSWORD (APPLY)
    // =====================================================
    if (isset($_POST['knx_reset_btn'])) {

        if ((isset($_POST['knx_hp']) || isset($_POST['knx_hp_ts'])) && !knx_check_honeypot($_POST)) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'reset')) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $token = sanitize_text_field($_POST['knx_reset_token'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/i', $token)) {
            KNX_Auth_Toast::push('error', 'reset_invalid');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $row = knx_get_password_reset_by_token($token);
        if (!$row || empty($row->user_id)) {
            KNX_Auth_Toast::push('error', 'reset_invalid');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $pass  = trim($_POST['knx_reset_password'] ?? '');
        $pass2 = trim($_POST['knx_reset_password_confirm'] ?? '');

        if ($pass === '' || $pass !== $pass2 || strlen($pass) < 8) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $wpdb->update(
            $users_table,
            ['password' => password_hash($pass, PASSWORD_DEFAULT)],
            ['id' => (int)$row->user_id],
            ['%s'],
            ['%d']
        );

        knx_mark_password_reset_used((int)$row->id);
        knx_invalidate_password_resets_for_user((int)$row->user_id);

        if (function_exists('knx_invalidate_user_sessions')) {
            knx_invalidate_user_sessions((int)$row->user_id);
        }

        KNX_Auth_Toast::push('success', 'reset_success');
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // =====================================================
    // LOGIN
    // =====================================================
    if (isset($_POST['knx_login_btn'])) {

        $ip    = knx_get_client_ip();
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

        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'login')) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$users_table} WHERE email = %s OR username = %s LIMIT 1",
            $login,
            $login
        ));

        if (!$user || !password_verify($_POST['knx_password'] ?? '', $user->password)) {
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

        knx_auto_login_user_by_id((int)$user->id, !empty($_POST['knx_remember']));

        // Redirect drivers to the Driver Ops dashboard; others keep previous behavior.
        $redirect_url = site_url('/cart');
        if (isset($user->role) && $user->role === 'driver') {
            $redirect_url = site_url('/driver-ops');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    // =====================================================
    // REGISTER (CUSTOMER)
    // =====================================================
    if (isset($_POST['knx_register_btn'])) {

        if (!isset($_POST['knx_register_nonce']) || !knx_verify_nonce($_POST['knx_register_nonce'], 'register')) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect(site_url('/login'));
            exit;
        }

        $email = sanitize_email($_POST['knx_register_email'] ?? '');
        $pass  = $_POST['knx_register_password'] ?? '';
        $pass2 = $_POST['knx_register_password_confirm'] ?? '';

        if (!is_email($email) || $pass !== $pass2 || strlen($pass) < 8) {
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

        $wpdb->insert($users_table, [
            'username' => $email,
            'email'    => $email,
            'password' => password_hash($pass, PASSWORD_DEFAULT),
            'role'     => 'customer',
            'status'   => 'inactive'
        ]);

        $user_id = (int)$wpdb->insert_id;
        $raw = knx_create_email_verification_token($user_id);

        if ($raw) {
            $url = site_url('/verify-email?token=' . urlencode($raw));
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            // queue verification mail to be sent on shutdown
            knx_queue_mail(
                $email,
                'Verify your account',
                '<p><a href="' . esc_url($url) . '">Activate your account</a></p>',
                $headers
            );
            KNX_Auth_Toast::push('success', 'verify_sent');
        } else {
            KNX_Auth_Toast::push('error', 'verify_send_failed');
        }

        // queue redirect so mail is sent first on shutdown
        knx_queue_redirect(site_url('/login'));
        exit;
    }

    // =====================================================
    // LOGOUT
    // =====================================================
    if (isset($_POST['knx_logout'])) {
        if (!isset($_POST['knx_logout_nonce']) || !wp_verify_nonce($_POST['knx_logout_nonce'], 'knx_logout_action')) {
            wp_die('Security check failed.');
        }
        knx_logout_user();
        exit;
    }

}, 9999); // late init = SMTP-safe
