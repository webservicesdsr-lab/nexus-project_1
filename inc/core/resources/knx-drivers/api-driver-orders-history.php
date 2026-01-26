<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver/orders/history', [
        'methods' => 'GET',
        'callback' => knx_rest_wrap('knx_api_driver_orders_history'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);
});

function knx_api_driver_orders_history(WP_REST_Request $req) {
    global $wpdb;

    if (!function_exists('knx_get_driver_context')) return knx_rest_response(false, 'forbidden', null, 403);
    $ctx = knx_get_driver_context(); if (!$ctx) return knx_rest_response(false, 'forbidden', null, 403);

    require_once KNX_PATH . 'inc/core/functions/knx-driver-runtime.php';
    if (!function_exists('knx_get_driver_user_id')) return knx_rest_response(false, 'forbidden', null, 403);
    $driver_user_id = knx_get_driver_user_id();
    if ($driver_user_id <= 0) return knx_rest_response(false, 'forbidden', null, 403);

    $history_table = $wpdb->prefix . 'knx_driver_orders_history';
    if (function_exists('knx_table')) { $maybe = knx_table('driver_orders_history'); if (is_string($maybe) && $maybe !== '') $history_table = $maybe; }

    // Ensure table exists (do NOT create at runtime)
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table));
    if (!$exists) return knx_rest_response(false, 'history_table_missing', null, 500);

    $page = max(1, intval($req->get_param('page') ? $req->get_param('page') : 1));
    $per_page = intval($req->get_param('per_page') ? $req->get_param('per_page') : 5);
    $per_page = $per_page > 0 ? min(5, $per_page) : 5; // hard cap 5
    $offset = ($page - 1) * $per_page;

    // Choose timestamp column consistently: prefer created_at, fallback to completed_at
    $has_created = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$history_table} LIKE %s", 'created_at'));
    $ts_col = $has_created ? 'created_at' : 'completed_at';

    // Determine caller role: drivers see only today's history and minimal fields.
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session && isset($session->role) ? (string)$session->role : '';
    $is_manager = in_array($role, ['super_admin','manager'], true);

    $params = [];
    // Drivers: default to today's history if no explicit from/to provided
    $from = $req->get_param('from_date');
    $to = $req->get_param('to_date');
    $order_id = $req->get_param('order_id');

    if (!$is_manager) {
        $today = date('Y-m-d');
        if (!$from) $from = $today . ' 00:00:00';
        if (!$to) $to = $today . ' 23:59:59';
    }

    $where = " WHERE 1=1 ";
    if (!$is_manager) {
        // Drivers only see their own history
        $where .= " AND driver_user_id = %d"; $params[] = $driver_user_id;
    } else {
        // Managers may request history optionally filtering by driver_user_id
        if ($req->get_param('driver_user_id')) { $where .= " AND driver_user_id = %d"; $params[] = (int)$req->get_param('driver_user_id'); }
    }

    if ($from) { $where .= " AND {$ts_col} >= %s"; $params[] = $from; }
    if ($to) { $where .= " AND {$ts_col} <= %s"; $params[] = $to; }
    if ($order_id) { $where .= " AND order_id = %d"; $params[] = (int)$order_id; }

    // Field selection: managers get snapshot_json for richer view; drivers get minimal fields
    if ($is_manager) {
        if ($has_created) {
            $select = 'order_id, completed_at, created_at, snapshot_json';
        } else {
            $select = 'order_id, completed_at, NULL AS created_at, snapshot_json';
        }
    } else {
        if ($has_created) {
            $select = 'order_id, completed_at, created_at';
        } else {
            $select = 'order_id, completed_at, NULL AS created_at';
        }
    }

    $sql = "SELECT {$select} FROM {$history_table} " . $where . " ORDER BY {$ts_col} DESC LIMIT %d OFFSET %d";
    $params[] = $per_page; $params[] = $offset;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if (!$rows) $rows = [];

    return knx_rest_response(true, 'OK', [
        'page' => $page,
        'per_page' => $per_page,
        'items' => $rows,
    ], 200);
}
