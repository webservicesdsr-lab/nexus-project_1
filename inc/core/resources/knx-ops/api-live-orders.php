<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Ops — Live Orders (Scaffold)
 * ----------------------------------------------------------
 * Endpoint: GET /wp-json/knx/v1/ops/live-orders
 * Access: super_admin, manager
 * Input: city_ids: array required
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/live-orders', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_ops_live_orders')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

function knx_ops_live_orders(WP_REST_Request $request) {
    global $wpdb;

    // Session + role
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string) $session->role : '';

    // Normalize city_ids param (supports query array or JSON string)
    $raw = $request->get_param('city_ids');
    $city_ids = [];
    if (is_string($raw)) {
        // try JSON decode
        $maybe = json_decode($raw, true);
        if (is_array($maybe)) $raw = $maybe;
    }
    if (is_array($raw)) {
        foreach ($raw as $c) {
            $c = intval($c);
            if ($c > 0) $city_ids[] = $c;
        }
    }

    if (empty($city_ids)) {
        return knx_rest_error('city_ids is required and must be a non-empty array', 400);
    }

    // Manager: enforce assigned cities
    if ($role === 'manager') {
        $hubs_table = $wpdb->prefix . 'knx_hubs';
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$hubs_table} LIKE %s", 'manager_user_id'));
        if (empty($col)) {
            return knx_rest_error('Manager city assignment not configured', 403);
        }

        $user_id = isset($session->user_id) ? absint($session->user_id) : 0;
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id FROM {$hubs_table} WHERE manager_user_id = %d AND city_id IS NOT NULL",
            $user_id
        ));

        $allowed = array_map('intval', array_filter($allowed));

        // If requested cities contain a city outside allowed scope -> error
        foreach ($city_ids as $c) {
            if (!in_array($c, $allowed, true)) {
                return knx_rest_error('Forbidden: city outside manager scope', 403);
            }
        }
    }

    // Live statuses
    $live = ['placed','confirmed','preparing','ready','assigned','in_progress'];

    $orders_table = $wpdb->prefix . 'knx_orders';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';
    $users_table  = $wpdb->prefix . 'knx_users';

    // Build placeholders for city_ids
    $placeholders = implode(',', array_fill(0, count($city_ids), '%d'));

    // Build status placeholders
    $status_ph = implode(',', array_fill(0, count($live), '%s'));

    $query = "SELECT
                    o.id AS order_id,
                    o.city_id AS city_id,
                    o.created_at AS created_at,
                    o.customer_name AS customer_name,
                    o.total AS total_amount,
                    o.tip_amount AS tip_amount,
                    o.status AS status,
                    o.driver_id AS driver_id,
                    h.name AS hub_name
               FROM {$orders_table} o
               INNER JOIN {$hubs_table} h ON o.hub_id = h.id
               WHERE o.city_id IN ({$placeholders})
                 AND o.status IN ({$status_ph})
               ORDER BY o.created_at DESC
               LIMIT 200
    ";

    $params = array_merge($city_ids, $live);

    $prepared = $wpdb->prepare($query, $params);
    $rows = $wpdb->get_results($prepared);

    $now = current_time('timestamp');

    $data = array_map(function($r) use ($now) {
        $created_ts = strtotime($r->created_at);
        $human = function_exists('human_time_diff')
            ? human_time_diff($created_ts, $now) . ' ago'
            : $r->created_at;

        return [
            'order_id' => (int) $r->order_id,
            'restaurant_name' => trim((string) $r->hub_name),
            'hub_name' => trim((string) $r->hub_name),
            'city_id' => (int) $r->city_id,
            'customer_name' => trim((string) $r->customer_name),
            'created_at' => $r->created_at,
            'created_human' => $human,
            'total_amount' => (float) $r->total_amount,
            'tip_amount' => (float) $r->tip_amount,
            'status' => (string) $r->status,
            'assigned_driver' => (!empty($r->driver_id) && intval($r->driver_id) > 0) ? true : false,
        ];
    }, $rows ?: []);

    return knx_rest_response(true, 'Live orders', $data, 200);
}
