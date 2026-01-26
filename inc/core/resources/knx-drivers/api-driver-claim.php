<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Drivers — Claim Order (driver self-claim)
 * POST /wp-json/knx/v1/driver/claim
 *
 * - Permission: driver context only (knx_rest_permission_driver_context)
 * - Identity: knx_get_driver_context() only (no admin override)
 * - SSOT: must call knx_driver_ops_sync_assign()
 * - Hub safety: validate order hub_id against ctx->hubs when present
 * - Availability: driver must be 'on' when availability table exists
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver/claim', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_driver_claim'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);
});

function knx_api_driver_claim(WP_REST_Request $req) {
    global $wpdb;

    if (!function_exists('knx_get_driver_context')) {
        return knx_rest_error('forbidden', 403);
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || !isset($ctx->driver_id) || (int)$ctx->driver_id <= 0) {
        return knx_rest_error('forbidden', 403);
    }

    $driver_user_id = (int) $ctx->driver_id;
    $hub_ids = is_array($ctx->hubs) ? array_values(array_map('intval', $ctx->hubs)) : [];

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_error('invalid_request', 400);
    }

    $order_id = isset($body['order_id']) ? (int) $body['order_id'] : 0;
    if ($order_id <= 0) {
        return knx_rest_error('order_id_required', 400);
    }

    // Resolve orders table (flexible knx_table if available)
    $orders_table = $wpdb->prefix . 'knx_orders';
    if (function_exists('knx_table')) {
        $maybe = knx_table('orders');
        if (is_string($maybe) && $maybe !== '') $orders_table = $maybe;
    }

    // Ensure orders table exists
    $exists_tbl = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
    if (empty($exists_tbl)) {
        return knx_rest_error('orders_table_missing', 500);
    }

    // Detect hub_id column and fetch hub_id for order if present
    $hub_id = 0;
    $has_hub_col = (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", 'hub_id'));
    if ($has_hub_col) {
        $hub_id = (int) $wpdb->get_var($wpdb->prepare("SELECT hub_id FROM {$orders_table} WHERE id = %d LIMIT 1", $order_id));
    }

    // If order has a hub and ctx->hubs is non-empty, enforce hub membership
    if ($hub_id > 0 && !empty($hub_ids) && !in_array($hub_id, $hub_ids, true)) {
        return knx_rest_error('forbidden', 403);
    }

    // Availability table check: if availability table exists, driver must be 'on'
    $availability_table = $wpdb->prefix . 'knx_driver_availability';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_availability');
        if (is_string($maybe) && $maybe !== '') $availability_table = $maybe;
    }

    $has_av_table = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $availability_table));
    if ($has_av_table) {
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$availability_table} WHERE driver_user_id = %d LIMIT 1", $driver_user_id));
        $status = is_string($status) ? strtolower(trim($status)) : '';
        if ($status !== 'on') {
            return knx_rest_response(false, 'Driver must be on duty to claim order', null, 409);
        }
    }

    // Ensure pipeline table exists
    $ops_table = $wpdb->prefix . 'knx_driver_ops';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_ops');
        if (is_string($maybe) && $maybe !== '') $ops_table = $maybe;
    }
    $has_ops = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ops_table));
    if (!$has_ops) {
        return knx_rest_error('driver_ops_table_missing', 500);
    }

    // Ensure pipeline row exists (idempotent seed) — avoid duplicates: use same seed strategy
    $row_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ops_table} WHERE order_id = %d", $order_id));
    if (!$row_count) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$ops_table} (order_id, driver_user_id, ops_status, assigned_by, assigned_at, updated_at) VALUES (%d, NULL, 'unassigned', NULL, NULL, %s) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
            $order_id, current_time('mysql')
        ));
    }

    // SSOT call required
    if (!function_exists('knx_driver_ops_sync_assign')) {
        return knx_rest_error('sync_engine_missing', 500);
    }

    $sync = knx_driver_ops_sync_assign($order_id, $driver_user_id, [
        'actor' => 'driver',
        'assigned_by' => $driver_user_id,
    ]);

    if (!empty($sync['success'])) {
        return knx_rest_response(true, 'Order claimed.', [
            'order_id' => $order_id,
            'driver_id' => $driver_user_id,
            'sync' => $sync,
        ], 200);
    }

    // Attempt safe fallback update for recovery (handle insert/update failures)
    $code = is_array($sync) && isset($sync['code']) ? (string)$sync['code'] : '';
    $msg = is_array($sync) && isset($sync['message']) ? (string)$sync['message'] : '';
    if ($code === 'assign_insert_failed' || $code === 'assign_update_failed' || strpos(strtolower($msg), 'duplicate') !== false) {
        $update_sql = $wpdb->prepare(
            "UPDATE {$ops_table} SET driver_user_id = %d, ops_status = %s, assigned_by = %d, assigned_at = %s, updated_at = %s WHERE order_id = %d",
            $driver_user_id,
            'assigned',
            $driver_user_id,
            current_time('mysql'),
            current_time('mysql'),
            $order_id
        );
        $res = $wpdb->query($update_sql);
        if ($res === false) {
            return knx_rest_response(false, 'Driver claim failed and fallback update failed', [
                'order_id' => $order_id,
                'driver_id' => $driver_user_id,
                'sync' => $sync,
            ], 500);
        }

        return knx_rest_response(true, 'Order claimed (recovered from sync duplicate).', [
            'order_id' => $order_id,
            'driver_id' => $driver_user_id,
            'sync' => $sync,
        ], 200);
    }

    return knx_rest_response(false, 'Driver claim failed', [
        'order_id' => $order_id,
        'driver_id' => $driver_user_id,
        'sync' => $sync,
    ], 500);
}
