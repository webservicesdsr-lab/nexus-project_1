<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Auth Handler (v2.1)
 *
 * Processes secure login, session creation, and logout.
 * Integrates with CSRF nonces, rate limiting, and safe cookies.
 */

add_action('init', function() {
    global $wpdb;
    $users_table    = $wpdb->prefix . 'knx_users';
    $sessions_table = $wpdb->prefix . 'knx_sessions';

    // Handle login
    if (isset($_POST['knx_login_btn'])) {
        $ip = knx_get_client_ip();

        // Rate limit protection
        if (knx_is_ip_blocked($ip)) {
            wp_safe_redirect(site_url('/login?error=locked'));
            exit;
        }

        // Validate nonce
        if (!isset($_POST['knx_nonce']) || !knx_verify_nonce($_POST['knx_nonce'], 'login')) {
            wp_die('Security check failed. Please refresh and try again.');
        }

        $login    = sanitize_text_field($_POST['knx_login']);
        $password = sanitize_text_field($_POST['knx_password']);
        $remember = isset($_POST['knx_remember']);

        // Lookup user by username or email
        $user = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM $users_table
            WHERE username = %s OR email = %s
            LIMIT 1
        ", $login, $login));

        // Validation
        if (!$user || $user->status !== 'active' || !password_verify($password, $user->password)) {
            knx_limit_login_attempts($ip);
            wp_safe_redirect(site_url('/login?error=invalid'));
            exit;
        }

        // Generate secure session token
        $token   = knx_generate_token();
        $expires = $remember ? date('Y-m-d H:i:s', strtotime('+30 days')) : date('Y-m-d H:i:s', strtotime('+1 day'));
        $agent   = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);

        // Store session
        $wpdb->insert($sessions_table, [
            'user_id'    => $user->id,
            'token'      => $token,
            'ip_address' => $ip,
            'user_agent' => $agent,
            'expires_at' => $expires
        ]);

        // Set secure cookie
        setcookie('knx_session', $token, [
            'expires'  => $remember ? time() + (30 * DAY_IN_SECONDS) : time() + DAY_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        // PHASE 2.A — Cart Claim (idempotent)
        // If guest has active cart, assign customer_id
        if (!empty($_COOKIE['knx_cart_token'])) {
            $cart_token = sanitize_text_field(wp_unslash($_COOKIE['knx_cart_token']));
            $carts_table = $wpdb->prefix . 'knx_carts';
            
            // Find guest cart (customer_id IS NULL)
            $guest_cart = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$carts_table}
                 WHERE session_token = %s 
                 AND status = 'active' 
                 AND customer_id IS NULL
                 ORDER BY updated_at DESC 
                 LIMIT 1",
                $cart_token
            ));
            
            // Claim cart: set customer_id (no recalc, no item touch)
            if ($guest_cart) {
                $wpdb->update(
                    $carts_table,
                    ['customer_id' => $user->id],
                    ['id' => $guest_cart->id],
                    ['%d'],
                    ['%d']
                );
            }
        }

        // PHASE 2.A — Redirect (canonical funnel)
        // All roles → /cart (except driver → /driver-dashboard)
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
