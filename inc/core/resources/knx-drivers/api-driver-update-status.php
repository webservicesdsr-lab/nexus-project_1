<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Drivers — Driver Update Ops Status (SEALED MVP)
 * ----------------------------------------------------------
 * POST /wp-json/knx/v1/driver/update-status
 *
 * Body (JSON):
 * - order_id (int)
 * - next_status (string) : picked_up | delivered
 *
 * Rules:
 * - Only assigned driver can mutate ops_status
 * - Valid transitions:
 *   assigned  -> picked_up
 *   picked_up -> delivered
 *   delivered -> (no further)
 *
 * Response:
 * - success=true always on handled path
 * - data.updated indicates real mutation (idempotent safe)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver/update-status', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_driver_update_status'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);
});

function knx_api_driver_update_status(WP_REST_Request $req) {
    global $wpdb;

    // --- KNX: Resolve driver identity (sealed helper) — fail-closed ---
    if (!function_exists('knx_get_driver_user_id')) {
        require_once KNX_PATH . 'inc/core/functions/knx-driver-runtime.php';
    }

    if (!function_exists('knx_get_driver_user_id')) {
        return knx_rest_response(false, 'forbidden', null, 403);
    }

    $driver_user_id = (int) knx_get_driver_user_id();
    $hub_ids = [];
    // best-effort hub list from ctx if available
    if (function_exists('knx_get_driver_context')) {
        $ctx = knx_get_driver_context();
        $hub_ids = is_array($ctx->hubs) ? array_values(array_map('intval', $ctx->hubs)) : [];
    }

    if ($driver_user_id <= 0) {
        return knx_rest_response(false, 'forbidden', null, 403);
    }

    $body = $req->get_json_params();
    if (!is_array($body)) {
        return knx_rest_response(false, 'invalid_request', null, 400);
    }

    $order_id = isset($body['order_id']) ? (int) $body['order_id'] : 0;
    $next_status = isset($body['next_status']) ? strtolower(trim((string) $body['next_status'])) : '';

    // Admin override: allow super_admin/manager to act on behalf of driver via body.as_user_id
    $as_user = isset($body['as_user_id']) ? (int) $body['as_user_id'] : 0;
    if ($as_user > 0) {
        $session = function_exists('knx_get_session') ? knx_get_session() : null;
        $role = $session && isset($session->role) ? (string) $session->role : '';
        $allowed_roles = ['super_admin', 'manager'];
        $ctx_present = isset($ctx) && !empty($ctx);
        $session_present = !empty($session);
        if (in_array($role, $allowed_roles, true) && ($ctx_present || $session_present)) {
            $driver_user_id = $as_user;
        }
        // else: ignore as_user_id silently (fail-closed)
    }

    if ($order_id <= 0 || $next_status === '') {
        return knx_rest_response(false, 'missing_params', null, 400);
    }

    $allowed_next = ['picked_up', 'delivered'];
    if (!in_array($next_status, $allowed_next, true)) {
        return knx_rest_response(false, 'invalid_next_status', null, 409);
    }

    $ops_table = $wpdb->prefix . 'knx_driver_ops';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_ops');
        if (is_string($maybe) && $maybe !== '') $ops_table = $maybe;
    }

    // Fail-closed if ops table missing
    $ops_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ops_table));
    if (!$ops_exists) {
        return knx_rest_response(false, 'driver_ops_table_missing', null, 500);
    }

    // Ensure ownership and current status matches expected transition
    $expected_current = $next_status === 'picked_up' ? 'assigned' : 'picked_up';

    $op = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$ops_table} WHERE order_id = %d AND driver_user_id = %d ORDER BY updated_at DESC LIMIT 1",
        $order_id, $driver_user_id
    ));

    if (!$op) {
        return knx_rest_response(false, 'not_assigned', null, 403);
    }

    $current = isset($op->ops_status) ? strtolower((string) $op->ops_status) : '';

    // Terminal check against orders.status if available
    $orders_table = $wpdb->prefix . 'knx_orders';
    if (function_exists('knx_table')) {
        $maybe = knx_table('orders'); if (is_string($maybe) && $maybe !== '') $orders_table = $maybe;
    }
    $terminal_states = ['cancelled','canceled','refunded','completed','delivered'];
    $order_status = null;
    $orders_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
    if ($orders_exists) {
        $ord = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$orders_table} WHERE id = %d LIMIT 1", $order_id));
        if ($ord && isset($ord->status)) $order_status = strtolower((string)$ord->status);
        if ($order_status !== null && in_array($order_status, $terminal_states, true)) {
            return knx_rest_response(false, 'terminal_order', null, 409);
        }
    }

    if ($current === $next_status) {
        return knx_rest_response(true, 'OK', [
            'updated'    => false,
            'order_id'   => $order_id,
            'ops_status' => $current,
        ], 200);
    }

    if ($current !== $expected_current) {
        return knx_rest_response(false, 'invalid_next_status', null, 409);
    }

    $now = current_time('mysql');
    $sql = $wpdb->prepare(
        "UPDATE {$ops_table} SET ops_status = %s, updated_at = %s WHERE order_id = %d AND driver_user_id = %d AND ops_status = %s",
        $next_status, $now, $order_id, $driver_user_id, $expected_current
    );

    $updated = $wpdb->query($sql);
    if ($updated === false) {
        return knx_rest_response(false, 'db_update_failed', null, 500);
    }

    // Optionally update knx_orders.status to 'delivered' when delivered and column exists
    if ($next_status === 'delivered' && isset($orders_exists) && $orders_exists) {
        // safe attempt: only update if orders table has 'status' column
        $has_status_col = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", 'status'));
        if ($has_status_col) {
            $wpdb->query($wpdb->prepare("UPDATE {$orders_table} SET status = %s WHERE id = %d", 'delivered', $order_id));
        }
    }

    // If delivered, archive to history (idempotent). This consolidates delivered/completed flows.
    if ($next_status === 'delivered') {
        if (!function_exists('knx_archive_order_to_history')) {
            require_once KNX_PATH . 'inc/core/functions/knx-driver-runtime.php';
        }
        if (function_exists('knx_archive_order_to_history')) {
            $arch = knx_archive_order_to_history($order_id, 'driver', $driver_user_id);
            if (empty($arch['success'])) {
                return knx_rest_response(false, 'archive_failed', null, 500);
            }
        }
    }

    return knx_rest_response(true, 'OK', [
        'updated'    => ($updated > 0),
        'order_id'   => $order_id,
        'ops_status' => $next_status,
    ], 200);
}
