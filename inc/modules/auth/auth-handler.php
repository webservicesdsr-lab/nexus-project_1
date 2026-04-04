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

if (file_exists(__DIR__ . '/auth-emails.php')) {
    require_once __DIR__ . '/auth-emails.php';
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

        // Capture redirect_to from verification link for forwarding to login
        $_verify_redirect_to = isset($_GET['redirect_to']) ? sanitize_text_field(wp_unslash($_GET['redirect_to'])) : '';
        $_verify_login_url = site_url('/login');
        if ($_verify_redirect_to && function_exists('knx_validate_redirect_to')) {
            $validated = knx_validate_redirect_to($_verify_redirect_to);
            if ($validated) {
                // Convert back to relative path for the query param
                $rel_path = wp_parse_url($validated, PHP_URL_PATH);
                $rel_query = wp_parse_url($validated, PHP_URL_QUERY);
                $rel = $rel_path . ($rel_query ? '?' . $rel_query : '');
                $_verify_login_url = add_query_arg('redirect_to', rawurlencode($rel), $_verify_login_url);
            }
        }

        if (!preg_match('/^[0-9a-f]{64}$/i', $raw)) {
            KNX_Auth_Toast::push('error', 'verify_invalid');
            wp_safe_redirect($_verify_login_url);
            exit;
        }

        $row = knx_get_email_verification_by_token($raw);
        if (!$row) {
            KNX_Auth_Toast::push('error', 'verify_invalid');
            wp_safe_redirect($_verify_login_url);
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
        wp_safe_redirect($_verify_login_url);
        exit;
    }

    // =====================================================
    // FORGOT PASSWORD (REQUEST)
    // =====================================================
    if (isset($_POST['knx_forgot_btn'])) {

        $ip = knx_get_client_ip();

        if (!knx_check_honeypot($_POST)) {
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

            if ($user) {
                $raw = knx_create_password_reset((int)$user->id, $ip);
                if ($raw) {
                    $reset_url = site_url('/reset-password?token=' . urlencode($raw));
                    $mail = knx_get_password_reset_email($reset_url);
                    $headers = ['Content-Type: text/html; charset=UTF-8'];
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

        if (!knx_check_honeypot($_POST)) {
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

        if ($pass === '' || strlen($pass) < 8) {
            KNX_Auth_Toast::push('error', 'password_too_short');
            wp_safe_redirect(site_url('/reset-password?token=' . urlencode($token)));
            exit;
        }

        if ($pass !== $pass2) {
            KNX_Auth_Toast::push('error', 'password_mismatch');
            wp_safe_redirect(site_url('/reset-password?token=' . urlencode($token)));
            exit;
        }

        // Update password in database
        $update_result = $wpdb->update(
            $users_table,
            ['password' => password_hash($pass, PASSWORD_DEFAULT)],
            ['id' => (int)$row->user_id],
            ['%s'],
            ['%d']
        );

        // Verify update succeeded
        if ($update_result === false || $update_result === 0) {
            KNX_Auth_Toast::push('error', 'reset_db_error');
            wp_safe_redirect(site_url('/reset-password?token=' . urlencode($token)));
            exit;
        }

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

        // Preserve redirect_to across failed-login retries
        $_login_redirect_to = isset($_POST['knx_redirect_to']) ? sanitize_text_field(wp_unslash($_POST['knx_redirect_to'])) : '';
        $_login_fail_url = site_url('/login');
        if ($_login_redirect_to) {
            $_login_fail_url = add_query_arg('redirect_to', rawurlencode($_login_redirect_to), $_login_fail_url);
        }

        if (!knx_check_honeypot($_POST)) {
            knx_record_failed_login($ip, $login);
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect($_login_fail_url);
            exit;
        }

        // Check for active blocks and show remaining minutes in toast when blocked.
        $ip_remaining = function_exists('knx_get_block_remaining_seconds_for_ip') ? knx_get_block_remaining_seconds_for_ip($ip) : 0;
        $user_remaining = function_exists('knx_get_block_remaining_seconds_for_user') ? knx_get_block_remaining_seconds_for_user($login) : 0;
        $remaining = max($ip_remaining, $user_remaining);
        if ($remaining > 0) {
            $minutes = (int) ceil($remaining / MINUTE_IN_SECONDS);
            $msg = 'Too many attempts. Try again in ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '') . '.';
            KNX_Auth_Toast::push('error', 'locked', $msg);
            wp_safe_redirect($_login_fail_url);
            exit;
        }

        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'login')) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect($_login_fail_url);
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
            wp_safe_redirect($_login_fail_url);
            exit;
        }

        if ($user->status !== 'active') {
            KNX_Auth_Toast::push('warning', 'inactive');
            wp_safe_redirect($_login_fail_url);
            exit;
        }

        knx_auto_login_user_by_id((int)$user->id, !empty($_POST['knx_remember']));

        // ── Phase 1: Consume redirect_to if present and valid ──
        $redirect_url = '';
        $posted_redirect = isset($_POST['knx_redirect_to']) ? sanitize_text_field(wp_unslash($_POST['knx_redirect_to'])) : '';
        $query_redirect  = isset($_GET['redirect_to']) ? sanitize_text_field(wp_unslash($_GET['redirect_to'])) : '';
        $raw_redirect    = $posted_redirect ?: $query_redirect;

        if ($raw_redirect && function_exists('knx_validate_redirect_to')) {
            $redirect_url = knx_validate_redirect_to($raw_redirect);
        }

        // Fallback: role-based landing
        if (empty($redirect_url)) {
            $redirect_url = function_exists('knx_role_landing_url')
                ? knx_role_landing_url($user->role ?? 'customer')
                : site_url('/');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    // =====================================================
    // REGISTER (CUSTOMER)
    // =====================================================
    if (isset($_POST['knx_register_btn'])) {

        // Preserve redirect_to across register error retries
        $_register_redirect_to = isset($_POST['knx_redirect_to']) ? sanitize_text_field(wp_unslash($_POST['knx_redirect_to'])) : '';
        $_register_fail_url = site_url('/login');
        if ($_register_redirect_to) {
            $_register_fail_url = add_query_arg('redirect_to', rawurlencode($_register_redirect_to), $_register_fail_url);
        }

        // Honeypot check (reject early to block bots)
        if (!knx_check_honeypot($_POST)) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect($_register_fail_url);
            exit;
        }

        if (!isset($_POST['knx_register_nonce']) || !knx_verify_nonce($_POST['knx_register_nonce'], 'register')) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect($_register_fail_url);
            exit;
        }

        // Read and sanitize inputs
        $fullname = sanitize_text_field($_POST['knx_register_fullname'] ?? '');
        $email    = sanitize_email($_POST['knx_register_email'] ?? '');
        $phone_raw = sanitize_text_field($_POST['knx_register_phone'] ?? '');
        $pass     = $_POST['knx_register_password'] ?? '';
        $pass2    = $_POST['knx_register_password_confirm'] ?? '';

        // Normalize phone: keep digits and optional leading +
        $phone = preg_replace('/[^0-9+]/', '', $phone_raw);
        $phone_digits = preg_replace('/[^0-9]/', '', $phone);

        // Validate full name: at least 2 chars
        if (strlen($fullname) < 2) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect($_register_fail_url);
            exit;
        }

        // Basic validation for email/password
        if (!is_email($email) || $pass !== $pass2 || strlen($pass) < 8) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect($_register_fail_url);
            exit;
        }

        // Ensure email uniqueness
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$users_table} WHERE email = %s LIMIT 1",
            $email
        ))) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect($_register_fail_url);
            exit;
        }

        // No separate username input: keep username column as email to preserve login behavior

        // Validate phone: at least 7 digits
        if (strlen($phone_digits) < 7) {
            KNX_Auth_Toast::push('error', 'auth_failed');
            wp_safe_redirect($_register_fail_url);
            exit;
        }

        // Check admin-configured requirement for email verification
        $require_verification = get_option('knx_require_email_verification', '1');

        // Build username from full name: normalize to allowed chars and ensure uniqueness
        $candidate = strtolower(trim($fullname));
        // replace spaces with dot
        $candidate = preg_replace('/\s+/', '.', $candidate);
        // remove disallowed characters, keep a-z0-9._-
        $candidate = preg_replace('/[^a-z0-9._-]/', '', $candidate);
        if (strlen($candidate) < 3) {
            // fallback to email local part
            $local = strtolower(preg_replace('/[^a-z0-9._-]/', '', strstr($email, '@', true)));
            $candidate = $local ?: 'user' . rand(1000,9999);
        }

        // ensure uniqueness by appending suffix when needed
        $base = $candidate;
        $i = 0;
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$users_table} WHERE username = %s LIMIT 1", $candidate))) {
            $i++;
            $candidate = substr($base, 0, 45) . '-' . $i; // keep within reasonable length
        }

        $username = $candidate;

        // Build insert array; store username=username and store full name into available column
        $insert = [
            'username' => $username,
            'email'    => $email,
            'password' => password_hash($pass, PASSWORD_DEFAULT),
            'role'     => 'customer',
            'status'   => ($require_verification === '1') ? 'inactive' : 'active'
        ];

        if (function_exists('knx_db_column_exists')) {
            if (knx_db_column_exists($users_table, 'name')) {
                $insert['name'] = $fullname;
            } elseif (knx_db_column_exists($users_table, 'full_name')) {
                $insert['full_name'] = $fullname;
            }
            if (knx_db_column_exists($users_table, 'phone')) {
                $insert['phone'] = $phone;
            }
        }

        $wpdb->insert($users_table, $insert);

        $user_id = (int)$wpdb->insert_id;

        if ($require_verification === '1') {
            // Email verification ENABLED: send verification email and redirect to login
            $raw = knx_create_email_verification_token($user_id);

            if ($raw) {
                // Include redirect_to in verification URL so it survives the full flow
                $verify_url = site_url('/verify-email?token=' . urlencode($raw));
                if ($_register_redirect_to) {
                    $verify_url = add_query_arg('redirect_to', rawurlencode($_register_redirect_to), $verify_url);
                }
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                // Build branded activation email
                if (function_exists('knx_get_account_activation_email')) {
                    $mail = knx_get_account_activation_email($verify_url, $email);
                } else {
                    $mail = [
                        'subject' => 'Verify your account',
                        'html'    => '<p><a href="' . esc_url($verify_url) . '">Activate your account</a></p>',
                    ];
                }
                // queue verification mail to be sent on shutdown
                knx_queue_mail($email, $mail['subject'], $mail['html'], $headers);
                KNX_Auth_Toast::push('success', 'verify_sent');
            } else {
                KNX_Auth_Toast::push('error', 'verify_send_failed');
            }

            // queue redirect so mail is sent first on shutdown
            // Carry redirect_to forward so the user can continue after verifying + logging in
            $register_login_url = site_url('/login');
            if ($_register_redirect_to) {
                $register_login_url = add_query_arg('redirect_to', rawurlencode($_register_redirect_to), $register_login_url);
            }
            knx_queue_redirect($register_login_url);
            exit;

        } else {
            // Email verification DISABLED: auto-login and redirect to destination
            $remember = !empty($_POST['knx_remember']);
            $auto_logged = knx_auto_login_user_by_id($user_id, $remember);

            if ($auto_logged) {
                KNX_Auth_Toast::push('success', 'register_success');

                // Determine redirect destination (same logic as login)
                $redirect_url = '';
                if ($_register_redirect_to) {
                    $redirect_url = knx_validate_redirect_to($_register_redirect_to);
                }
                if (!$redirect_url) {
                    $redirect_url = knx_role_landing_url('customer');
                }

                wp_safe_redirect($redirect_url);
                exit;
            } else {
                // Auto-login failed (rare) — fallback to login page
                KNX_Auth_Toast::push('success', 'verify_success');
                $register_login_url = site_url('/login');
                if ($_register_redirect_to) {
                    $register_login_url = add_query_arg('redirect_to', rawurlencode($_register_redirect_to), $register_login_url);
                }
                knx_queue_redirect($register_login_url);
                exit;
            }
        }
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
