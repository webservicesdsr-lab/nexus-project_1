<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Cities — Update Delivery Rates (SEALED v1)
 * ----------------------------------------------------------
 * Endpoint:
 * - POST /wp-json/knx/v2/cities/update-delivery-rates
 *
 * Payload (JSON):
 * - city_id (int)
 * - flat_rate (decimal)
 * - rate_per_distance (decimal)
 * - distance_unit ('mile'|'kilometer')
 * - status ('active'|'inactive') [optional]
 * - knx_nonce (string) (action: knx_edit_city_nonce)
 *
 * Security:
 * - Session required
 * - Role: super_admin ONLY
 * - Nonce required
 *
 * Behavior:
 * - UPSERT by unique city_id
 * - No side writes beyond delivery_rates row
 * ==========================================================
 */

/**
 * Safe fallbacks if core wrappers are not loaded for any reason.
 */
if (!function_exists('knx_rest_success')) {
    function knx_rest_success($data = [], $status = 200) {
        return new WP_REST_Response(['success' => true, 'data' => $data], (int)$status);
    }
}
if (!function_exists('knx_rest_error')) {
    function knx_rest_error($message = 'error', $status = 400) {
        return new WP_REST_Response(['success' => false, 'error' => (string)$message], (int)$status);
    }
}
if (!function_exists('knx_rest_guard')) {
    function knx_rest_guard($args = []) {
        $require_session = !empty($args['require_session']);
        $roles = isset($args['roles']) && is_array($args['roles']) ? $args['roles'] : [];

        $session = function_exists('knx_get_session') ? knx_get_session() : null;
        if ($require_session && !is_object($session)) return false;

        if (!empty($roles)) {
            $role = is_object($session) && isset($session->role) ? $session->role : '';
            return in_array($role, $roles, true);
        }

        return true;
    }
}

/**
 * Sanitize a decimal money-like input into a fixed string "0.00".
 */
function knx_dr__money($value) {
    $raw = is_string($value) ? trim($value) : $value;

    if ($raw === '' || $raw === null) {
        return '0.00';
    }

    // Allow digits and dot only
    $raw = preg_replace('/[^0-9.]/', '', (string)$raw);
    if ($raw === '' || !is_numeric($raw)) {
        return '0.00';
    }

    $num = (float)$raw;
    if ($num < 0) $num = 0;

    // Clamp to DECIMAL(10,2) max: 99999999.99
    if ($num > 99999999.99) $num = 99999999.99;

    return number_format($num, 2, '.', '');
}

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/cities/update-delivery-rates', [
        'methods'             => 'POST',
        'callback'            => 'knx_update_city_delivery_rates',
        'permission_callback' => function () {
            return knx_rest_guard([
                'require_session' => true,
                'roles'           => ['super_admin'],
            ]);
        },
    ]);
});

/**
 * Update delivery rates (UPSERT).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_update_city_delivery_rates(WP_REST_Request $request) {
    global $wpdb;

    $data = $request->get_json_params();
    if (!is_array($data)) {
        $data = json_decode($request->get_body(), true);
    }
    if (!is_array($data)) $data = [];

    $city_id = absint($data['city_id'] ?? 0);
    if (!$city_id) {
        return knx_rest_error('Invalid city id.', 400);
    }

    $nonce = sanitize_text_field($data['knx_nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'knx_edit_city_nonce')) {
        return knx_rest_error('Invalid nonce.', 403);
    }

    $distance_unit = strtolower(sanitize_text_field($data['distance_unit'] ?? 'mile'));
    if (!in_array($distance_unit, ['mile', 'kilometer'], true)) {
        $distance_unit = 'mile';
    }

    $status = strtolower(sanitize_text_field($data['status'] ?? 'active'));
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    $flat_rate         = knx_dr__money($data['flat_rate'] ?? '0.00');
    $rate_per_distance = knx_dr__money($data['rate_per_distance'] ?? '0.00');

    // Verify city exists and is not soft-deleted (deleted_at IS NULL)
    $table_cities = $wpdb->prefix . 'knx_cities';
    $city_exists = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1)
         FROM {$table_cities}
         WHERE id = %d
           AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')",
        $city_id
    ));

    if ($city_exists < 1) {
        return knx_rest_error('City not found.', 404);
    }

    $table = $wpdb->prefix . 'knx_delivery_rates';

    /**
     * UPSERT using UNIQUE(city_id)
     * Note: If min_order column still exists in DB, it will keep its default.
     */
    $sql = $wpdb->prepare(
        "INSERT INTO {$table}
            (city_id, flat_rate, rate_per_distance, distance_unit, status, created_at, updated_at)
         VALUES
            (%d, %s, %s, %s, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            flat_rate = VALUES(flat_rate),
            rate_per_distance = VALUES(rate_per_distance),
            distance_unit = VALUES(distance_unit),
            status = VALUES(status),
            updated_at = CURRENT_TIMESTAMP",
        $city_id,
        $flat_rate,
        $rate_per_distance,
        $distance_unit,
        $status
    );

    $result = $wpdb->query($sql);
    if ($result === false) {
        return knx_rest_error('Database error while updating delivery rates.', 500);
    }

    // Return saved values (single source of truth from DB)
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT flat_rate, rate_per_distance, distance_unit, status
             FROM {$table}
             WHERE city_id = %d
             LIMIT 1",
            $city_id
        ),
        ARRAY_A
    );

    if (!$row) {
        return knx_rest_error('Updated but unable to load delivery rates.', 500);
    }

    return knx_rest_success([
        'message'           => '✅ Delivery rates updated successfully',
        'flat_rate'         => (string)$row['flat_rate'],
        'rate_per_distance' => (string)$row['rate_per_distance'],
        'distance_unit'     => $row['distance_unit'],
        'status'            => $row['status'],
        'exists'            => true,
    ], 200);
}
