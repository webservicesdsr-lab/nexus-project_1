<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Live Orders (Operational Board)
 * Endpoint: GET /wp-json/knx/v1/ops/live-orders
 *
 * Notes:
 * - Fail-closed: requires session + role + scoped cities for managers.
 * - Hubs are the "restaurants" (no separate restaurants table).
 * - Response may include order_id for internal navigation, but UI must not display IDs.
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

/**
 * Resolve manager allowed city IDs based on hubs.manager_user_id.
 *
 * @param int $manager_user_id
 * @return array<int>
 */
function knx_ops_live_orders_manager_city_ids($manager_user_id) {
    global $wpdb;

    $hubs_table = $wpdb->prefix . 'knx_hubs';

    // Fail-closed if assignment column doesn't exist
    $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$hubs_table} LIKE %s", 'manager_user_id'));
    if (empty($col)) return [];

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id
         FROM {$hubs_table}
         WHERE manager_user_id = %d
           AND city_id IS NOT NULL",
        (int)$manager_user_id
    ));

    $ids = array_map('intval', (array)$ids);
    $ids = array_values(array_filter($ids, function($v){ return $v > 0; }));

    return $ids;
}

/**
 * Best-effort: detect a lat/lng pair on orders table for "View Location".
 * We keep this defensive because schema may evolve.
 *
 * @return array{lat:string,lng:string}|null
 */
function knx_ops_live_orders_detect_latlng_columns() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $candidates = [
        ['lat' => 'delivery_lat', 'lng' => 'delivery_lng'],
        ['lat' => 'address_lat',  'lng' => 'address_lng'],
        ['lat' => 'lat',          'lng' => 'lng'],
    ];

    foreach ($candidates as $pair) {
        $lat_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", $pair['lat']));
        $lng_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", $pair['lng']));
        if (!empty($lat_exists) && !empty($lng_exists)) {
            $cache = $pair;
            return $cache;
        }
    }

    $cache = null;
    return null;
}

/**
 * Format "created_human" with a friendly "Just now" for sub-60s.
 *
 * @param int $age_seconds
 * @return string
 */
function knx_ops_live_orders_human_age($age_seconds) {
    if ($age_seconds <= 60) return 'Just now';
    if (function_exists('human_time_diff')) {
        return human_time_diff(time() - (int)$age_seconds, time()) . ' ago';
    }
    $mins = max(1, floor($age_seconds / 60));
    return $mins . ' min ago';
}

function knx_ops_live_orders(WP_REST_Request $request) {
    global $wpdb;

    // Require session + allowed roles (fail-closed)
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    // Parse city_ids (supports query array or JSON string)
    $raw = $request->get_param('city_ids');
    if (is_string($raw)) {
        $maybe = json_decode($raw, true);
        if (is_array($maybe)) $raw = $maybe;
    }

    $city_ids = [];
    if (is_array($raw)) {
        foreach ($raw as $c) {
            $c = (int)$c;
            if ($c > 0) $city_ids[] = $c;
        }
    }

    $city_ids = array_values(array_unique($city_ids));
    if (empty($city_ids)) {
        return knx_rest_error('city_ids is required and must be a non-empty array', 400);
    }

    $include_resolved = (int)$request->get_param('include_resolved');
    $include_resolved = ($include_resolved === 0) ? 0 : 1;

    $resolved_hours = (int)$request->get_param('resolved_hours');
    if ($resolved_hours <= 0) $resolved_hours = 24;
    if ($resolved_hours > 168) $resolved_hours = 168; // Hard cap 7 days

    // Manager scope enforcement (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed)) {
            return knx_rest_error('Manager city assignment not configured', 403);
        }

        foreach ($city_ids as $c) {
            if (!in_array($c, $allowed, true)) {
                return knx_rest_error('Forbidden: city outside manager scope', 403);
            }
        }
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    // Status sets
    $live_statuses = ['placed','confirmed','preparing','ready','assigned','in_progress'];
    $resolved_statuses = ['completed','cancelled'];

    // For resolved orders, only include recent ones (board convenience)
    $cutoff_ts = current_time('timestamp') - ($resolved_hours * 3600);
    $cutoff_dt = date('Y-m-d H:i:s', $cutoff_ts);

    // Determine optional lat/lng columns
    $latlng = knx_ops_live_orders_detect_latlng_columns();
    $lat_col = $latlng ? $latlng['lat'] : null;
    $lng_col = $latlng ? $latlng['lng'] : null;

    // Build placeholders for city_ids
    $city_ph = implode(',', array_fill(0, count($city_ids), '%d'));

    // Build placeholders for statuses
    $live_ph = implode(',', array_fill(0, count($live_statuses), '%s'));
    $resolved_ph = implode(',', array_fill(0, count($resolved_statuses), '%s'));

    // Select list (defensive: some schemas may not have tip_amount/driver_id; keep as-is, expect existing)
    $select_latlng = '';
    if ($lat_col && $lng_col) {
        $select_latlng = ", o.`{$lat_col}` AS lat, o.`{$lng_col}` AS lng";
    }

    // Include resolved (recent) or not
    $where_status_block = "o.status IN ({$live_ph})";
    $params = array_merge($city_ids, $live_statuses);

    if ($include_resolved) {
        $where_status_block = "(o.status IN ({$live_ph}) OR (o.status IN ({$resolved_ph}) AND o.created_at >= %s))";
        $params = array_merge($city_ids, $live_statuses, $resolved_statuses, [$cutoff_dt]);
    }

    $query = "
        SELECT
            o.id AS order_id,
            o.city_id AS city_id,
            o.hub_id AS hub_id,
            o.customer_name AS customer_name,
            o.created_at AS created_at,
            o.total AS total_amount,
            o.tip_amount AS tip_amount,
            o.status AS status,
            o.driver_id AS driver_id,
            h.name AS hub_name
            {$select_latlng}
        FROM {$orders_table} o
        INNER JOIN {$hubs_table} h ON o.hub_id = h.id
        WHERE o.city_id IN ({$city_ph})
          AND {$where_status_block}
        ORDER BY o.created_at DESC
        LIMIT 250
    ";

    $prepared = $wpdb->prepare($query, $params);
    $rows = $wpdb->get_results($prepared);

    $now_ts = current_time('timestamp');

    $orders = [];
    foreach ((array)$rows as $r) {
        $created_ts = strtotime((string)$r->created_at);
        $age = ($created_ts > 0) ? max(0, $now_ts - $created_ts) : 0;

        $view_location_url = null;
        if (isset($r->lat) && isset($r->lng)) {
            $lat = (float)$r->lat;
            $lng = (float)$r->lng;
            if ($lat !== 0.0 || $lng !== 0.0) {
                $view_location_url = 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);
            }
        }

        $hub_name = trim((string)$r->hub_name);

        $orders[] = [
            'order_id' => (int)$r->order_id, // internal use only (View Order page)
            'restaurant_name' => $hub_name,  // hubs are restaurants visually
            'hub_name' => $hub_name,
            'city_id' => (int)$r->city_id,
            'customer_name' => trim((string)$r->customer_name),
            'created_at' => (string)$r->created_at,
            'created_age_seconds' => (int)$age,
            'created_human' => knx_ops_live_orders_human_age((int)$age),
            'total_amount' => (float)$r->total_amount,
            'tip_amount' => (float)$r->tip_amount,
            'status' => (string)$r->status,
            'assigned_driver' => (!empty($r->driver_id) && (int)$r->driver_id > 0),
            'view_location_url' => $view_location_url,
        ];
    }

    return knx_rest_response(true, 'Live orders', [
        'orders' => $orders,
        'meta' => [
            'role' => $role,
            'include_resolved' => (int)$include_resolved,
            'resolved_hours' => (int)$resolved_hours,
            'generated_at' => current_time('mysql'),
            'count' => count($orders),
        ]
    ], 200);
}
