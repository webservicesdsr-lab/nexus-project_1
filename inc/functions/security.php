<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Security Functions (v2)
 *
 * Provides reusable protection for all modules:
 * - Global CSRF nonce generator and validator
 * - Login rate limiting by IP
 * - Secure token generation
 */

/**
 * Generate a secure random token.
 * Returns a 64-character hexadecimal string.
 */
function knx_generate_token() {
    return bin2hex(random_bytes(32));
}

/**
 * Create a reusable nonce field for forms.
 * Example: knx_nonce_field('login')
 */
function knx_nonce_field($action, $name = 'knx_nonce') {
    wp_nonce_field("knx_{$action}_action", $name);
}

/**
 * Verify a nonce safely.
 * Example: knx_verify_nonce($_POST['knx_nonce'], 'login')
 */
function knx_verify_nonce($nonce_value, $action) {
    if (empty($nonce_value)) return false;
    return wp_verify_nonce($nonce_value, "knx_{$action}_action");
}

/**
 * Limit repeated login attempts by IP.
 * Prevents brute force attacks.
 */
function knx_limit_login_attempts($ip) {
    $limit_key = 'knx_login_attempts_' . md5($ip);
    $attempts = get_transient($limit_key);

    if ($attempts === false) {
        set_transient($limit_key, 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    if ($attempts >= 5) {
        return false;
    }

    set_transient($limit_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);
    return true;
}

/**
 * Get the client IP address safely.
 */
function knx_get_client_ip() {
    $headers = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            return sanitize_text_field(trim($ip));
        }
    }

    return '0.0.0.0';
}

/**
 * Check if the user is temporarily locked due to failed attempts.
 * Returns true if blocked, false otherwise.
 */
function knx_is_ip_blocked($ip) {
    $limit_key = 'knx_login_attempts_' . md5($ip);
    $attempts = get_transient($limit_key);
    return ($attempts !== false && $attempts >= 5);
}
