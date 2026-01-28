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
    // Backwards-compatible wrapper: increment IP counter using new helper
    $res = knx_record_failed_login($ip, '');
    // Return true if not blocked, false if blocked
    return empty($res['blocked_ip']);
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
        $val = filter_input(INPUT_SERVER, $key, FILTER_DEFAULT);
        if (!empty($val)) {
            $ip = explode(',', (string)$val)[0];
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
    $block_key = 'knx_login_block_ip_' . md5($ip);
    return get_transient($block_key) ? true : false;
}

/**
 * Honeypot check helper.
 * Returns true when submission looks human (hp empty and ts within range).
 */
function knx_check_honeypot(array $post): bool {
    $hp = isset($post['knx_hp']) ? trim((string)$post['knx_hp']) : '';
    $ts = isset($post['knx_hp_ts']) ? intval($post['knx_hp_ts']) : 0;

    if ($hp !== '') return false; // bot filled field
    if ($ts <= 0) return false;

    $now = time();
    // Accept forms rendered between 1 second and 12 hours ago
    if ($ts > $now || ($now - $ts) < 1 || ($now - $ts) > 43200) return false;

    return true;
}

/**
 * Create a password reset record for a user.
 * Returns the plain token (to be sent via email) on success or false on failure.
 */
function knx_create_password_reset(int $user_id, string $ip = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_password_resets';

    // Invalidate previous tokens for user (mark used)
    $wpdb->update($table, ['used_at' => current_time('mysql')], ['user_id' => $user_id, 'used_at' => null], ['%s'], ['%d', '%s']);

    // Generate token (random) and HMAC-hash it with AUTH_KEY for safe lookup
    $token = bin2hex(random_bytes(32));
    $token_hash = hash_hmac('sha256', $token, AUTH_KEY);

    $now = current_time('mysql');
    $expires = date('Y-m-d H:i:s', time() + 60 * MINUTE_IN_SECONDS); // 60 minutes

    $inserted = $wpdb->insert($table, [
        'user_id'    => $user_id,
        'token_hash' => $token_hash,
        'expires_at' => $expires,
        'created_at' => $now,
        'ip_address' => substr($ip, 0, 45),
    ], ['%d', '%s', '%s', '%s', '%s']);

    if ($inserted === false) return false;
    return $token;
}

/**
 * Validate a reset token and return the row object or false.
 */
function knx_get_password_reset_by_token(string $token) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_password_resets';
    $token_hash = hash_hmac('sha256', $token, AUTH_KEY);

    $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", $token_hash);
    $row = $wpdb->get_row($sql);
    if (!$row) return false;

    // Check used and expiration
    if (!empty($row->used_at)) return false;
    if (strtotime($row->expires_at) < time()) return false;

    return $row;
}

/**
 * Mark a password reset record as used.
 */
function knx_mark_password_reset_used(int $id) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_password_resets';
    return $wpdb->update($table, ['used_at' => current_time('mysql')], ['id' => $id], ['%s'], ['%d']);
}

/**
 * Record a failed login attempt for IP and optional login (email/username).
 * Returns array: ['ip_attempts'=>int, 'user_attempts'=>int, 'blocked_ip'=>bool, 'blocked_user'=>bool]
 */
function knx_record_failed_login(string $ip, string $login = ''): array {
    $ip_key = 'knx_login_attempts_ip_' . md5($ip);
    $user_key = $login !== '' ? 'knx_login_attempts_user_' . md5(strtolower($login)) : null;

    $ip_attempts = (int) get_transient($ip_key);
    $ip_attempts++;
    set_transient($ip_key, $ip_attempts, 15 * MINUTE_IN_SECONDS);

    $user_attempts = 0;
    if ($user_key) {
        $user_attempts = (int) get_transient($user_key);
        $user_attempts++;
        set_transient($user_key, $user_attempts, 15 * MINUTE_IN_SECONDS);
    }

    $blocked_ip = false;
    $blocked_user = false;

    // Threshold reached -> set block transients
    if ($ip_attempts >= 5) {
        $blocked_ip = true;
        set_transient('knx_login_block_ip_' . md5($ip), 1, HOUR_IN_SECONDS);
    }

    if ($user_key && $user_attempts >= 5) {
        $blocked_user = true;
        set_transient('knx_login_block_user_' . md5(strtolower($login)), 1, HOUR_IN_SECONDS);
    }

    return [
        'ip_attempts' => $ip_attempts,
        'user_attempts' => $user_attempts,
        'blocked_ip' => $blocked_ip,
        'blocked_user' => $blocked_user
    ];
}

/**
 * Get current attempt counts without mutating state.
 */
function knx_get_login_attempts(string $ip, string $login = ''): array {
    $ip_key = 'knx_login_attempts_ip_' . md5($ip);
    $user_key = $login !== '' ? 'knx_login_attempts_user_' . md5(strtolower($login)) : null;

    $ip_attempts = (int) get_transient($ip_key);
    $user_attempts = $user_key ? (int) get_transient($user_key) : 0;

    return ['ip' => $ip_attempts, 'user' => $user_attempts];
}

/**
 * Check if a username/email is blocked.
 */
function knx_is_user_blocked(string $login): bool {
    if (empty($login)) return false;
    $block_key = 'knx_login_block_user_' . md5(strtolower($login));
    return get_transient($block_key) ? true : false;
}

/**
 * Explicitly block an IP for a duration (seconds).
 */
function knx_block_ip(string $ip, int $duration_seconds = HOUR_IN_SECONDS): void {
    set_transient('knx_login_block_ip_' . md5($ip), 1, $duration_seconds);
}

/**
 * Validate a redirect target and return safe relative path or null.
 */
function knx_safe_redirect_target(string $target): ?string {
    $target = trim($target);
    if ($target === '') return null;
    // Only allow absolute-paths starting with '/'
    if (strpos($target, '/') !== 0) return null;
    // Disallow scheme or host
    if (strpos($target, '://') !== false) return null;
    // Normalize and remove double slashes
    $path = preg_replace('#/+#', '/', $target);
    // Basic sanitation
    return esc_url_raw($path);
}
