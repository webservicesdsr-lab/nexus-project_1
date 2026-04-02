<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Management — Settings REST Endpoints (v1.0)
 * ----------------------------------------------------------
 * GET  /knx/v1/hub-management/temp-location   → read temp loc
 * POST /knx/v1/hub-management/temp-location   → write temp loc
 *
 * Storage: y05_knx_hub_management_settings (key/value)
 * Keys:
 *   knx_hub_temp_loc_{hub_id}      → JSON {lat, lng, note, expires_at, updated_by, updated_at}
 *
 * Security: session + role + ownership (fail-closed)
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v1', '/hub-management/temp-location', [
        [
            'methods'             => 'GET',
            'callback'            => knx_rest_wrap('knx_hub_mgmt_get_temp_location'),
            'permission_callback' => knx_rest_permission_session(),
        ],
        [
            'methods'             => 'POST',
            'callback'            => knx_rest_wrap('knx_hub_mgmt_save_temp_location'),
            'permission_callback' => knx_rest_permission_session(),
        ],
    ]);
});

/**
 * GET temp location for a hub
 */
function knx_hub_mgmt_get_temp_location(WP_REST_Request $request) {
    global $wpdb;

    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $table = $wpdb->prefix . 'knx_hub_management_settings';
    $key   = 'knx_hub_temp_loc_' . $hub_id;

    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$table} WHERE setting_key = %s LIMIT 1",
        $key
    ));

    $data = $value ? json_decode($value, true) : null;

    // Check if expired
    if ($data && !empty($data['expires_at'])) {
        if (strtotime($data['expires_at']) < time()) {
            $data['expired'] = true;
        }
    }

    return knx_rest_response(true, 'OK', [
        'hub_id'   => $hub_id,
        'location' => $data,
    ]);
}

/**
 * POST save temp location for a hub
 */
function knx_hub_mgmt_save_temp_location(WP_REST_Request $request) {
    global $wpdb;

    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    // Nonce verification
    $nonce = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_settings_nonce')) {
        return knx_rest_error('Invalid nonce', 403);
    }

    // Strict field allowlist
    $lat        = floatval($request->get_param('lat'));
    $lng        = floatval($request->get_param('lng'));
    $note       = sanitize_textarea_field($request->get_param('note') ?? '');
    $expires_at = sanitize_text_field($request->get_param('expires_at') ?? '');

    if ($lat == 0 && $lng == 0) {
        return knx_rest_error('Invalid coordinates', 400);
    }

    // Validate expires_at if provided
    if ($expires_at && !strtotime($expires_at)) {
        return knx_rest_error('Invalid expires_at', 400);
    }

    $user_id = isset($session->user_id) ? (int) $session->user_id : 0;

    $payload = wp_json_encode([
        'lat'        => $lat,
        'lng'        => $lng,
        'note'       => $note,
        'expires_at' => $expires_at ?: null,
        'updated_by' => $user_id,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $table = $wpdb->prefix . 'knx_hub_management_settings';
    $key   = 'knx_hub_temp_loc_' . $hub_id;

    // Upsert
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE setting_key = %s LIMIT 1",
        $key
    ));

    if ($existing) {
        $wpdb->update($table, [
            'setting_value' => $payload,
            'updated_at'    => current_time('mysql'),
        ], ['id' => $existing], ['%s', '%s'], ['%d']);
    } else {
        $wpdb->insert($table, [
            'setting_key'   => $key,
            'setting_value' => $payload,
            'created_at'    => current_time('mysql'),
        ], ['%s', '%s', '%s']);
    }

    return knx_rest_response(true, 'Temporary location saved', [
        'hub_id'   => $hub_id,
        'location' => json_decode($payload, true),
    ]);
}
