<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver/delay', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_driver_delay'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);
});

function knx_api_driver_delay(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    $order_id = isset($body['order_id']) ? (int)$body['order_id'] : 0;
    $delay_code = isset($body['delay_code']) ? (string)$body['delay_code'] : '';
    $allowed = ['10_min_delay','20_min_delay','30_min_delay'];
    if ($order_id <= 0 || !in_array($delay_code, $allowed, true)) return knx_rest_response(false, 'missing_params', null, 400);

    if (!function_exists('knx_get_driver_user_id')) {
        require_once KNX_PATH . 'inc/core/functions/knx-driver-runtime.php';
    }
    if (!function_exists('knx_get_driver_user_id')) return knx_rest_response(false, 'forbidden', null, 403);
    $driver_user_id = knx_get_driver_user_id();
    if ($driver_user_id <= 0) return knx_rest_response(false, 'forbidden', null, 403);

    $delay_table = $wpdb->prefix . 'knx_driver_order_delays';
    if (function_exists('knx_table')) { $maybe = knx_table('driver_order_delays'); if (is_string($maybe) && $maybe !== '') $delay_table = $maybe; }

    // Ensure table exists (do NOT create at runtime)
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $delay_table));
    if (!$exists) return knx_rest_response(false, 'delay_table_missing', null, 500);

    // Verify driver actually owns an assigned/picked_up pipeline row for this order
    $ops_table = $wpdb->prefix . 'knx_driver_ops';
    if (function_exists('knx_table')) { $maybe = knx_table('driver_ops'); if (is_string($maybe) && $maybe !== '') $ops_table = $maybe; }
    $owned = $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$ops_table} WHERE order_id = %d AND driver_user_id = %d AND ops_status IN ('assigned','picked_up','delayed') LIMIT 1",
        $order_id, $driver_user_id
    ));
    if (empty($owned)) {
        return knx_rest_response(false, 'not_assigned', null, 403);
    }

    $note = 'Delivery delay reported by driver.';
    $ok = $wpdb->insert($delay_table, ['order_id' => $order_id, 'driver_user_id' => $driver_user_id, 'delay_code' => $delay_code, 'note' => $note], ['%d','%d','%s','%s']);
    if ($ok === false) return knx_rest_response(false, 'db_insert_failed', null, 500);

    return knx_rest_response(true, 'OK', ['order_id' => $order_id, 'delay_code' => $delay_code], 200);
}
