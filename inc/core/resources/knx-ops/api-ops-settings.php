<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — OPS Settings API (MVP v1.0)
 * ----------------------------------------------------------
 * Purpose:
 * - Global Operating Hours (2 shifts/day)
 * - Payments Admin (minimal toggles)
 *
 * Autonomy:
 * - NO wp_options usage
 * - Uses custom table: {$wpdb->prefix}knx_ops_settings
 *
 * Endpoints (admin only: super_admin, manager):
 *   GET  /knx/v2/ops/get
 *   POST /knx/v2/ops/hours/save
 *   POST /knx/v2/ops/payments/save
 *
 * Security:
 * - Wrapped with knx_rest_wrap()
 * - Permission: knx_rest_permission_roles(['super_admin','manager'])
 * - Nonces required for write operations (custom action nonces)
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/ops/get', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_ops_get'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/ops/hours/save', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_ops_save_hours'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/ops/payments/save', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_ops_save_payments'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Table resolver (supports optional knx_table('ops_settings') convention).
 */
function knx_v2_ops_settings_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_ops_settings';

    if (function_exists('knx_table')) {
        $maybe = knx_table('ops_settings');
        if (is_string($maybe) && $maybe !== '') {
            $table = $maybe;
        }
    }
    return $table;
}

/**
 * Ensure OPS settings table exists (idempotent).
 */
function knx_v2_ops_ensure_table() {
    global $wpdb;
    $table = knx_v2_ops_settings_table();

    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(80) NOT NULL,
        setting_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_setting_key (setting_key)
    ) {$charset};";

    $wpdb->query($sql);
}

/**
 * Defaults (MVP).
 */
function knx_v2_ops_defaults_hours() {
    // Mon-Sat: 08:30-14:30 and 14:30-20:30 | Sun: closed
    return [
        'version' => 1,
        'week' => [
            'mon' => ['enabled' => true,  'shifts' => [['start' => '08:30', 'end' => '14:30'], ['start' => '14:30', 'end' => '20:30']]],
            'tue' => ['enabled' => true,  'shifts' => [['start' => '08:30', 'end' => '14:30'], ['start' => '14:30', 'end' => '20:30']]],
            'wed' => ['enabled' => true,  'shifts' => [['start' => '08:30', 'end' => '14:30'], ['start' => '14:30', 'end' => '20:30']]],
            'thu' => ['enabled' => true,  'shifts' => [['start' => '08:30', 'end' => '14:30'], ['start' => '14:30', 'end' => '20:30']]],
            'fri' => ['enabled' => true,  'shifts' => [['start' => '08:30', 'end' => '14:30'], ['start' => '14:30', 'end' => '20:30']]],
            'sat' => ['enabled' => true,  'shifts' => [['start' => '08:30', 'end' => '14:30'], ['start' => '14:30', 'end' => '20:30']]],
            'sun' => ['enabled' => false, 'shifts' => []],
        ],
    ];
}

function knx_v2_ops_defaults_payments() {
    return [
        'version' => 1,
        'accept_payments' => true,
        'stripe_mode' => 'test', // 'test' | 'live'
        'note' => 'MVP admin-only config (does not change checkout authority by itself).',
    ];
}

/**
 * Helpers
 */
function knx_v2_ops_is_valid_hhmm($s) {
    if (!is_string($s)) return false;
    return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $s);
}

function knx_v2_ops_time_to_minutes($hhmm) {
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return ($h * 60) + $m;
}

function knx_v2_ops_get_setting($key, $default) {
    global $wpdb;
    knx_v2_ops_ensure_table();

    $table = knx_v2_ops_settings_table();
    $row = $wpdb->get_row($wpdb->prepare("SELECT setting_json FROM {$table} WHERE setting_key = %s LIMIT 1", $key));
    if (!$row || !isset($row->setting_json)) return $default;

    $decoded = json_decode($row->setting_json, true);
    return is_array($decoded) ? $decoded : $default;
}

function knx_v2_ops_set_setting($key, $value) {
    global $wpdb;
    knx_v2_ops_ensure_table();

    $table = knx_v2_ops_settings_table();
    $json  = wp_json_encode($value);

    // Upsert without wp_options (autonomous)
    $sql = "INSERT INTO {$table} (setting_key, setting_json)
            VALUES (%s, %s)
            ON DUPLICATE KEY UPDATE
                setting_json = VALUES(setting_json),
                updated_at = CURRENT_TIMESTAMP";

    $ok = $wpdb->query($wpdb->prepare($sql, $key, $json));
    return ($ok !== false);
}

/**
 * GET /ops/get
 */
function knx_v2_ops_get(WP_REST_Request $req) {
    $hours = knx_v2_ops_get_setting('operating_hours', knx_v2_ops_defaults_hours());
    $payments = knx_v2_ops_get_setting('payments_settings', knx_v2_ops_defaults_payments());

    return knx_rest_response(true, 'OPS settings', [
        'hours' => $hours,
        'payments' => $payments,
    ], 200);
}

/**
 * POST /ops/hours/save
 * Body JSON:
 * { hours: { week: {...}, version: 1 }, knx_nonce: "..." }
 */
function knx_v2_ops_save_hours(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }

    $nonce = sanitize_text_field($body['knx_nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'knx_ops_save_hours_nonce')) {
        return knx_rest_response(false, 'Invalid nonce.', null, 403);
    }

    $hours = $body['hours'] ?? null;
    if (!is_array($hours) || !is_array($hours['week'] ?? null)) {
        return knx_rest_response(false, 'hours.week is required.', null, 400);
    }

    $week = $hours['week'];
    $days = ['mon','tue','wed','thu','fri','sat','sun'];

    foreach ($days as $d) {
        $day = $week[$d] ?? null;
        if (!is_array($day)) {
            return knx_rest_response(false, "Missing day: {$d}", null, 400);
        }

        $enabled = !empty($day['enabled']);
        $shifts = $day['shifts'] ?? [];

        if (!$enabled) {
            // closed day: allow empty shifts
            $week[$d] = ['enabled' => false, 'shifts' => []];
            continue;
        }

        if (!is_array($shifts) || count($shifts) !== 2) {
            return knx_rest_response(false, "Day {$d} must have exactly 2 shifts.", null, 400);
        }

        $s1 = $shifts[0] ?? [];
        $s2 = $shifts[1] ?? [];

        $s1s = (string) ($s1['start'] ?? '');
        $s1e = (string) ($s1['end'] ?? '');
        $s2s = (string) ($s2['start'] ?? '');
        $s2e = (string) ($s2['end'] ?? '');

        if (!knx_v2_ops_is_valid_hhmm($s1s) || !knx_v2_ops_is_valid_hhmm($s1e) || !knx_v2_ops_is_valid_hhmm($s2s) || !knx_v2_ops_is_valid_hhmm($s2e)) {
            return knx_rest_response(false, "Invalid time format on {$d}. Use HH:MM (24h).", null, 400);
        }

        $m1s = knx_v2_ops_time_to_minutes($s1s);
        $m1e = knx_v2_ops_time_to_minutes($s1e);
        $m2s = knx_v2_ops_time_to_minutes($s2s);
        $m2e = knx_v2_ops_time_to_minutes($s2e);

        if ($m1s >= $m1e) return knx_rest_response(false, "Shift 1 invalid on {$d}.", null, 400);
        if ($m2s >= $m2e) return knx_rest_response(false, "Shift 2 invalid on {$d}.", null, 400);
        if ($m1e > $m2s) return knx_rest_response(false, "Shifts overlap on {$d}.", null, 400);

        $week[$d] = [
            'enabled' => true,
            'shifts' => [
                ['start' => $s1s, 'end' => $s1e],
                ['start' => $s2s, 'end' => $s2e],
            ],
        ];
    }

    $payload = [
        'version' => 1,
        'week' => $week,
    ];

    $ok = knx_v2_ops_set_setting('operating_hours', $payload);
    if (!$ok) {
        return knx_rest_response(false, 'Failed to save hours.', null, 500);
    }

    return knx_rest_response(true, 'Hours saved.', ['hours' => $payload], 200);
}

/**
 * POST /ops/payments/save
 * Body JSON:
 * { payments: { accept_payments: bool, stripe_mode: "test|live" }, knx_nonce: "..." }
 */
function knx_v2_ops_save_payments(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }

    $nonce = sanitize_text_field($body['knx_nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'knx_ops_save_payments_nonce')) {
        return knx_rest_response(false, 'Invalid nonce.', null, 403);
    }

    $payments = $body['payments'] ?? null;
    if (!is_array($payments)) {
        return knx_rest_response(false, 'payments is required.', null, 400);
    }

    $accept = !empty($payments['accept_payments']);
    $mode = sanitize_text_field($payments['stripe_mode'] ?? 'test');
    $mode = ($mode === 'live') ? 'live' : 'test';

    $payload = knx_v2_ops_defaults_payments();
    $payload['accept_payments'] = (bool) $accept;
    $payload['stripe_mode'] = $mode;

    $ok = knx_v2_ops_set_setting('payments_settings', $payload);
    if (!$ok) {
        return knx_rest_response(false, 'Failed to save payments settings.', null, 500);
    }

    return knx_rest_response(true, 'Payments settings saved.', ['payments' => $payload], 200);
}
