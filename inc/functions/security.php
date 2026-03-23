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
    // Create a nonce tied to the action but output a hidden input without an `id`
    // to avoid duplicate element IDs when multiple forms appear on the same page.
    $nonce = wp_create_nonce("knx_{$action}_action");
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($nonce) . '">';
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
 * Return the canonical password_resets table name.
 */
function knx_password_resets_table() {
    global $wpdb;
    return $wpdb->prefix . 'knx_password_resets';
}

/**
 * Internal: robust table existence check for password reset table.
 * Fail-closed: returns false if table not found.
 *
 * We avoid esc_like() here because some MySQL configurations (e.g. NO_BACKSLASH_ESCAPES)
 * can make LIKE + backslash escaping behave unexpectedly, causing false negatives.
 *
 * @param string $table Fully prefixed table name
 * @return bool
 */
function knx_password_resets_table_exists($table) {
    global $wpdb;
    $table = (string) $table;
    if ($table === '') return false;
    $tables = $wpdb->get_col("SHOW TABLES");
    return in_array($table, $tables, true);
}

/**
 * Create a password reset record and return the raw token (plain) or false.
 * The token is stored hashed (sha256) in the DB.
 */
function knx_create_password_reset(int $user_id, string $ip = '') {
    global $wpdb;
    $table = knx_password_resets_table();

    // Fail closed if table doesn't exist (robust guard)
    if (!knx_password_resets_table_exists($table)) return false;

    // Invalidate any previous active tokens for this user.
    // Must use raw SQL: $wpdb->update() with null in WHERE generates
    // "used_at = ''" which is invalid for datetime and poisons $wpdb->last_error,
    // causing the subsequent INSERT to fail on strict MySQL servers.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET used_at = %s WHERE user_id = %d AND used_at IS NULL",
        current_time('mysql'),
        $user_id
    ));

    // Generate secure random token and hash it
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        return false;
    }
    $token_hash = hash('sha256', $token);

    $now = current_time('mysql');
    $expires = gmdate('Y-m-d H:i:s', time() + 60 * MINUTE_IN_SECONDS);

    $inserted = $wpdb->insert($table, [
        'user_id'    => $user_id,
        'token_hash' => $token_hash,
        'expires_at' => $expires,
        'created_at' => $now,
        'ip_address' => substr($ip, 0, 45),
    ], ['%d','%s','%s','%s','%s']);

    if ($inserted === false) return false;
    return $token;
}

/**
 * Validate a raw token and return the DB row or false.
 */
function knx_get_password_reset_by_token(string $token) {
    global $wpdb;
    $table = knx_password_resets_table();

    if (!knx_password_resets_table_exists($table)) return false;

    $token_hash = hash('sha256', $token);

    $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", $token_hash);
    $row = $wpdb->get_row($sql);
    if (!$row) return false;

    if (!empty($row->used_at)) return false;
    if (strtotime($row->expires_at) < time()) return false;

    return $row;
}

/**
 * Mark a password reset record as used.
 */
function knx_mark_password_reset_used(int $id) {
    global $wpdb;
    $table = knx_password_resets_table();

    if (!knx_password_resets_table_exists($table)) return false;

    return $wpdb->update($table, ['used_at' => current_time('mysql')], ['id' => $id], ['%s'], ['%d']);
}

/**
 * Invalidate all tokens for a given user (mark used).
 */
function knx_invalidate_password_resets_for_user(int $user_id) {
    global $wpdb;
    $table = knx_password_resets_table();

    if (!knx_password_resets_table_exists($table)) return false;

    return $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET used_at = %s WHERE user_id = %d AND used_at IS NULL",
        current_time('mysql'),
        $user_id
    ));
}

/**
 * Check if the user is temporarily locked due to failed attempts.
 * Returns true if blocked, false otherwise.
 */
function knx_is_ip_blocked($ip) {
    $block_key = 'knx_login_block_ip_' . md5($ip);
    $remaining = (int) get_transient($block_key);
    // transient stores expiry timestamp (int). Return true when still in the future.
    return ($remaining && $remaining > time());
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
 * Record a failed login attempt for IP and optional login (email/username).
 * Returns array: ['ip_attempts'=>int, 'user_attempts'=>int, 'blocked_ip'=>bool, 'blocked_user'=>bool]
 */
function knx_record_failed_login(string $ip, string $login = ''): array {
    $ip_key = 'knx_login_attempts_ip_' . md5($ip);
    $user_key = $login !== '' ? 'knx_login_attempts_user_' . md5(strtolower($login)) : null;

    // If already blocked, do not increment counters or extend blocks.
    $blocked_ip_now = knx_get_block_remaining_seconds_for_ip($ip) > 0;
    $blocked_user_now = ($user_key && knx_get_block_remaining_seconds_for_user($login) > 0);

    if ($blocked_ip_now || $blocked_user_now) {
        return [
            'ip_attempts' => (int) get_transient($ip_key),
            'user_attempts' => $user_key ? (int) get_transient($user_key) : 0,
            'blocked_ip' => $blocked_ip_now,
            'blocked_user' => $blocked_user_now
        ];
    }

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

    // Compute and apply blocks based on escalating policy
    // If attempts reach threshold, set an expiry timestamp in the block transient.
    if ($ip_attempts >= 12) {
        $seconds = knx_compute_block_seconds($ip_attempts);
        $blocked_ip = true;
        knx_set_block('knx_login_block_ip_' . md5($ip), $seconds);
    }

    if ($user_key && $user_attempts >= 12) {
        $seconds = knx_compute_block_seconds($user_attempts);
        $blocked_user = true;
        knx_set_block('knx_login_block_user_' . md5(strtolower($login)), $seconds);
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
    $remaining = (int) get_transient($block_key);
    return ($remaining && $remaining > time());
}

/**
 * Explicitly block an IP for a duration (seconds).
 */
function knx_block_ip(string $ip, int $duration_seconds = HOUR_IN_SECONDS): void {
    knx_set_block('knx_login_block_ip_' . md5($ip), $duration_seconds);
}

/**
 * Helper: set a block transient storing the expiry timestamp (int).
 */
function knx_set_block(string $block_key, int $duration_seconds): void {
    $expiry = time() + $duration_seconds;
    // store expiry timestamp as transient value and set TTL to duration
    set_transient($block_key, $expiry, $duration_seconds);
}

/**
 * Helper: return remaining seconds for a stored block transient keyed by block_key.
 * Returns 0 when not blocked or expired.
 */
function knx_get_block_remaining_seconds_by_key(string $block_key): int {
    $val = get_transient($block_key);
    if (!$val) return 0;
    $expiry = (int) $val;
    $remaining = $expiry - time();
    return $remaining > 0 ? $remaining : 0;
}

function knx_get_block_remaining_seconds_for_ip(string $ip): int {
    $block_key = 'knx_login_block_ip_' . md5($ip);
    return knx_get_block_remaining_seconds_by_key($block_key);
}

function knx_get_block_remaining_seconds_for_user(string $login): int {
    if (empty($login)) return 0;
    $block_key = 'knx_login_block_user_' . md5(strtolower($login));
    return knx_get_block_remaining_seconds_by_key($block_key);
}

/**
 * Compute block duration (seconds) for given attempts using the escalating policy:
 * - 12 attempts -> 5 minutes
 * - 17 attempts -> 10 minutes
 * - 20 attempts -> 15 minutes
 * - For each additional 3 attempts beyond 20, increase by 5 minutes
 */
function knx_compute_block_seconds(int $attempts): int {
    if ($attempts < 12) return 0;
    // base thresholds
    if ($attempts >= 12 && $attempts < 17) return 5 * MINUTE_IN_SECONDS;
    if ($attempts >= 17 && $attempts < 20) return 10 * MINUTE_IN_SECONDS;
    // attempts >= 20
    $base = 15; // minutes for 20 attempts
    $extra_groups = intdiv(max(0, $attempts - 20), 3);
    $minutes = $base + ($extra_groups * 5);
    // optional cap: 4 hours (240 minutes)
    $cap_minutes = 240;
    if ($minutes > $cap_minutes) $minutes = $cap_minutes;
    return $minutes * MINUTE_IN_SECONDS;
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