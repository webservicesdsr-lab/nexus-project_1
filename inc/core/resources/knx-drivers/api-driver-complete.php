<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver/complete', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_driver_complete'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);
});

function knx_api_driver_complete(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    $order_id = isset($body['order_id']) ? (int)$body['order_id'] : 0;
    if ($order_id <= 0) return knx_rest_response(false, 'order_id required', null, 400);

    if (!function_exists('knx_get_driver_context')) return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);
    $ctx = knx_get_driver_context(); if (!$ctx) return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);

    require_once KNX_PATH . 'inc/core/functions/knx-driver-runtime.php';
    $driver_user_id = knx_get_driver_user_id();
    if ($driver_user_id <= 0) return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);

    // Resolve table names
    $ops_table = $wpdb->prefix . 'knx_driver_ops';
    $orders_table = $wpdb->prefix . 'knx_orders';
    $history_table = $wpdb->prefix . 'knx_driver_orders_history';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_ops'); if (is_string($maybe) && $maybe !== '') $ops_table = $maybe;
        $maybe = knx_table('orders'); if (is_string($maybe) && $maybe !== '') $orders_table = $maybe;
        $maybe = knx_table('driver_orders_history'); if (is_string($maybe) && $maybe !== '') $history_table = $maybe;
    }


    // Find active assigned pipeline row explicitly owned by this driver
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$ops_table} WHERE order_id = %d AND driver_user_id = %d AND ops_status = %s ORDER BY updated_at DESC LIMIT 1",
        $order_id, $driver_user_id, 'assigned'
    ));

    if (!$row) {
        // Either not assigned to this driver, or not currently assigned
        return knx_rest_response(false, 'Order not assigned to current driver (or not in assigned state)', null, 403);
    }

    $ops_status = isset($row->ops_status) ? strtolower((string)$row->ops_status) : '';
    if ($ops_status === 'completed') {
        return knx_rest_response(false, 'Order already completed', null, 409);
    }

    // Validate real order status in orders table to prevent completing cancelled/refunded orders
    $order_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$orders_table} WHERE id = %d LIMIT 1", $order_id));
    $order_status_l = strtolower((string) $order_status);
    $blocked = ['cancelled','canceled','refunded'];
    if (in_array($order_status_l, $blocked, true)) {
        return knx_rest_response(false, 'Cannot complete order: order status is terminal', ['status' => $order_status_l], 409);
    }

    // Update ops pipeline to completed
    $updated = $wpdb->update($ops_table, ['ops_status' => 'completed', 'updated_at' => current_time('mysql')], ['order_id' => $order_id]);
    if ($updated === false) return knx_rest_response(false, 'Failed to update pipeline', null, 500);

    // Update orders.status to delivered if column exists
    $cols = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table}", ARRAY_A);
    $has_status = false; $id_col = 'id';
    if ($cols) {
        foreach ($cols as $c) {
            if ($c['Field'] === 'status') $has_status = true;
            if ($c['Field'] === 'id') $id_col = 'id';
        }
    }
    if ($has_status) {
        $wpdb->update($orders_table, ['status' => 'delivered'], [ $id_col => $order_id ]);
    }

    // Archive using central helper (idempotent)
    if (!function_exists('knx_archive_order_to_history')) {
        require_once KNX_PATH . 'inc/core/functions/knx-driver-runtime.php';
    }
    if (!function_exists('knx_archive_order_to_history')) {
        return knx_rest_response(false, 'archive_helper_missing', null, 500);
    }

    $arch = knx_archive_order_to_history($order_id, 'driver', $driver_user_id);
    if (empty($arch['success'])) {
        // If archive failed because table missing, surface explicit error
        if (isset($arch['code']) && $arch['code'] === 'history_table_missing') {
            return knx_rest_response(false, 'history_table_missing', null, 500);
        }
        return knx_rest_response(false, 'archive_failed', null, 500);
    }

    return knx_rest_response(true, 'Order completed', ['order_id' => $order_id, 'archived' => $arch['code']], 200);
}
