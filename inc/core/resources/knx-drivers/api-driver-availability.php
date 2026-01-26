<?php
if (!defined('ABSPATH')) exit;

/**
 * KNX Drivers — Availability API
 * GET  /wp-json/knx/v1/driver/availability
 * POST /wp-json/knx/v1/driver/availability
 *
 * Runtime endpoints — session/driver context only. No WP nonce.
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v1', '/driver/availability', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_driver_availability_get'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);

    register_rest_route('knx/v1', '/driver/availability', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_driver_availability_set'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);

});

function knx_driver_availability_table_name() {
    global $wpdb;
    if (function_exists('knx_table')) {
        $t = knx_table('driver_availability');
        if (is_string($t) && $t !== '') return $t;
    }
    return $wpdb->prefix . 'knx_driver_availability';
}

function knx_api_driver_availability_get(WP_REST_Request $req) {
    global $wpdb;

    // Canonical driver contract: verify driver context (do NOT block on empty hubs)
    if (!function_exists('knx_get_driver_context')) {
        return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || (int)$ctx->driver_id <= 0) {
        return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);
    }

    $driver_user_id = (int) $ctx->driver_id;
    // IMPORTANT: availability is driver-level state; do NOT block when hubs are empty
    $hub_ids = is_array($ctx->hubs) ? $ctx->hubs : [];

    $table = knx_driver_availability_table_name();
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === null) {
        // table missing => default off
        return knx_rest_response(true, 'OK', [ 'status' => 'off' ], 200);
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT status, updated_at FROM {$table} WHERE driver_user_id = %d LIMIT 1", $driver_user_id), ARRAY_A);
    if (!$row) {
        return knx_rest_response(true, 'OK', [ 'status' => 'off' ], 200);
    }

    return knx_rest_response(true, 'OK', [ 'status' => $row['status'], 'updated_at' => $row['updated_at'] ], 200);
}

function knx_api_driver_availability_set(WP_REST_Request $req) {
    global $wpdb;

    // Canonical driver contract: verify driver context (do NOT block on empty hubs)
    if (!function_exists('knx_get_driver_context')) {
        return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || (int)$ctx->driver_id <= 0) {
        return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);
    }

    $driver_user_id = (int) $ctx->driver_id;
    // DO NOT block because hubs array is empty; availability is driver-level
    $hub_ids = is_array($ctx->hubs) ? $ctx->hubs : [];

    $body = $req->get_json_params();
    if (!is_array($body)) return knx_rest_error('invalid_request', 400);

    $status = isset($body['status']) ? strtolower(trim((string) $body['status'])) : '';
    if ($status !== 'on' && $status !== 'off') return knx_rest_error('invalid_status', 400);

    $table = knx_driver_availability_table_name();
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === null) {
        return knx_rest_error('availability_table_missing', 500);
    }

    $now = current_time('mysql');

    $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$table} WHERE driver_user_id = %d", $driver_user_id));
    if ($existing > 0) {
        $updated = $wpdb->update($table, [ 'status' => $status, 'updated_at' => $now ], [ 'driver_user_id' => $driver_user_id ]);
        if ($updated === false) return knx_rest_error('db_update_failed', 500);
    } else {
        $inserted = $wpdb->insert($table, [ 'driver_user_id' => $driver_user_id, 'status' => $status, 'updated_at' => $now ]);
        if (!$inserted) return knx_rest_error('db_insert_failed', 500);
    }

    return knx_rest_response(true, 'OK', [ 'status' => $status, 'updated_at' => $now ], 200);
}
