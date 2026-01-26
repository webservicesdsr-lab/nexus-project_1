<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver/release', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_driver_release'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);
});

function knx_api_driver_release(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    $order_id = isset($body['order_id']) ? (int)$body['order_id'] : 0;
    if ($order_id <= 0) return knx_rest_response(false, 'missing_params', null, 400);

    if (!function_exists('knx_get_driver_user_id')) {
        require_once KNX_PATH . 'inc/core/functions/knx-driver-runtime.php';
    }
    if (!function_exists('knx_get_driver_user_id')) return knx_rest_response(false, 'forbidden', null, 403);
    $driver_user_id = knx_get_driver_user_id();
    if ($driver_user_id <= 0) return knx_rest_response(false, 'forbidden', null, 403);

    $ops_table = $wpdb->prefix . 'knx_driver_ops';
    if (function_exists('knx_table')) { $maybe = knx_table('driver_ops'); if (is_string($maybe) && $maybe !== '') $ops_table = $maybe; }

    // Select the latest row for this driver/order only (avoid multi-driver leakage)
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ops_table} WHERE order_id = %d AND driver_user_id = %d ORDER BY updated_at DESC LIMIT 1", $order_id, $driver_user_id));
    if (!$row) return knx_rest_response(false, 'not_assigned', null, 403);

    $ops_status = isset($row->ops_status) ? strtolower((string)$row->ops_status) : '';
    $terminal = ['completed','delivered'];
    if (in_array($ops_status, $terminal, true)) {
        return knx_rest_response(false, 'terminal_order', null, 409);
    }

    // Also check the orders table (if present) for terminal status to avoid
    // releasing when the canonical order row is already completed/delivered.
    $orders_table = $wpdb->prefix . 'knx_orders';
    if (function_exists('knx_table')) { $maybe_o = knx_table('orders'); if (is_string($maybe_o) && $maybe_o !== '') $orders_table = $maybe_o; }
    $has_status_col = false;
    try {
        $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", 'status'));
        if ($col && count($col)) $has_status_col = true;
    } catch (Exception $e) {
        $has_status_col = false;
    }

    if ($has_status_col) {
        $order_row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$orders_table} WHERE id = %d LIMIT 1", $order_id));
        if ($order_row) {
            $ord_status = isset($order_row->status) ? strtolower((string)$order_row->status) : '';
            $ord_terminal = ['completed','delivered','cancelled'];
            if (in_array($ord_status, $ord_terminal, true)) {
                return knx_rest_response(false, 'terminal_order', null, 409);
            }
        }
    }

    // Also check history: if the order is archived, deny release
    $history_table = $wpdb->prefix . 'knx_driver_orders_history';
    if (function_exists('knx_table')) { $maybe_h = knx_table('driver_orders_history'); if (is_string($maybe_h) && $maybe_h !== '') $history_table = $maybe_h; }
    $hist_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table));
    if ($hist_exists) {
        $in_hist = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$history_table} WHERE order_id = %d", $order_id));
        if ($in_hist > 0) return knx_rest_response(false, 'terminal_order', null, 409);
    }

    // Allow release from these states
    $allow_release = ['assigned','picked_up','delayed','accepted'];
    if (!in_array($ops_status, $allow_release, true)) {
        return knx_rest_response(false, 'invalid_action', null, 409);
    }

    // Prefer a driver-scoped sync unassign helper when available. Do NOT call
    // the global knx_driver_ops_sync_unassign() here because multi-assignment
    // may be in use and a global unassign could affect other drivers.
    if (function_exists('knx_driver_ops_sync_unassign_for_driver')) {
        $sync = knx_driver_ops_sync_unassign_for_driver($order_id, $driver_user_id, ['actor' => 'driver', 'unassigned_by' => $driver_user_id]);
        if (empty($sync['success'])) {
            return knx_rest_response(false, 'unassign_failed', null, 500);
        }
        return knx_rest_response(true, 'OK', ['order_id' => $order_id], 200);
    }

    // NOTE: do NOT call knx_driver_ops_sync_unassign() here because it may
    // perform a global unassign. Use a safe, scoped update as the MVP.
    $ok = $wpdb->update($ops_table, ['driver_user_id' => null, 'ops_status' => 'unassigned', 'updated_at' => current_time('mysql')], ['order_id' => $order_id, 'driver_user_id' => $driver_user_id]);
    if ($ok === false) return knx_rest_response(false, 'db_update_failed', null, 500);

    return knx_rest_response(true, 'OK', ['order_id' => $order_id], 200);
}
