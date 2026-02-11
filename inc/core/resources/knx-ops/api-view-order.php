<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — View Order (Operational Read)
 * Endpoint: GET /wp-json/knx/v1/ops/view-order
 *
 * Notes:
 * - Fail-closed: requires session + role + scoped cities for managers.
 * - Manager scope is resolved via {prefix}knx_manager_cities (NOT hubs.manager_user_id).
 * - Hubs are the "restaurants" (no restaurants table).
 * - Payload may include order_id for internal navigation, but UI must not display IDs.
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/view-order', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_ops_view_order')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Resolve manager allowed city IDs based on {prefix}knx_manager_cities.
 *
 * Fail-closed returns [] if table missing or no rows found.
 *
 * @param int $manager_user_id
 * @return array<int>
 */
function knx_ops_view_order_manager_city_ids($manager_user_id) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_manager_cities';

    // Fail-closed if table does not exist
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (empty($exists)) return [];

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id
         FROM {$table}
         WHERE manager_user_id = %d
           AND city_id IS NOT NULL",
        (int)$manager_user_id
    ));

    $ids = array_map('intval', (array)$ids);
    $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));

    return $ids;
}

/**
 * Best-effort: detect a lat/lng pair on orders table for "View Location".
 * Cached per request.
 *
 * @return array{lat:string,lng:string}|null
 */
function knx_ops_view_order_detect_latlng_columns() {
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
 * Best-effort: detect a notes column on orders table.
 *
 * @return string|null
 */
function knx_ops_view_order_detect_notes_column() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $candidates = ['notes', 'order_notes', 'customer_notes', 'delivery_notes', 'instructions'];

    foreach ($candidates as $col) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", $col));
        if (!empty($exists)) {
            $cache = $col;
            return $cache;
        }
    }

    $cache = null;
    return null;
}

/**
 * Best-effort: detect an items/snapshot JSON column on orders table.
 *
 * @return string|null
 */
function knx_ops_view_order_detect_items_column() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $candidates = ['items_json', 'cart_json', 'snapshot_json', 'order_snapshot', 'cart_snapshot', 'items'];

    foreach ($candidates as $col) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", $col));
        if (!empty($exists)) {
            $cache = $col;
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
function knx_ops_view_order_human_age($age_seconds) {
    $age_seconds = (int)$age_seconds;
    if ($age_seconds <= 60) return 'Just now';

    if (function_exists('human_time_diff')) {
        $created_ts = time() - $age_seconds;
        return human_time_diff($created_ts, time()) . ' ago';
    }

    $mins = max(1, (int)floor($age_seconds / 60));
    return $mins . ' min ago';
}

/**
 * Optional: best-effort detect driver table and name column.
 * If not found, we keep driver name null.
 *
 * @return array{table:string,name_col:string}|null
 */
function knx_ops_view_order_detect_driver_name_source() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $table = $wpdb->prefix . 'knx_drivers';

    // Check table exists
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (empty($table_exists)) {
        $cache = null;
        return null;
    }

    $candidates = ['full_name', 'name', 'driver_name', 'display_name'];

    foreach ($candidates as $col) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        if (!empty($exists)) {
            $cache = ['table' => $table, 'name_col' => $col];
            return $cache;
        }
    }

    $cache = null;
    return null;
}

function knx_ops_view_order(WP_REST_Request $request) {
    global $wpdb;

    // Require session + allowed roles (fail-closed)
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    $order_id = (int)$request->get_param('order_id');
    if ($order_id <= 0) {
        return knx_rest_error('order_id is required and must be a positive integer', 400);
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    // Optional columns
    $latlng = knx_ops_view_order_detect_latlng_columns();
    $notes_col = knx_ops_view_order_detect_notes_column();
    $items_col = knx_ops_view_order_detect_items_column();

    $select_latlng = '';
    if ($latlng) {
        $select_latlng = ", o.`{$latlng['lat']}` AS lat, o.`{$latlng['lng']}` AS lng";
    }

    $select_notes = '';
    if ($notes_col) {
        $select_notes = ", o.`{$notes_col}` AS notes";
    }

    $select_items = '';
    if ($items_col) {
        $select_items = ", o.`{$items_col}` AS items_json";
    }

    // Optional driver name join
    $driver_src = knx_ops_view_order_detect_driver_name_source();
    $select_driver_name = '';
    $join_driver = '';
    if ($driver_src) {
        $drivers_table = $driver_src['table'];
        $driver_name_col = $driver_src['name_col'];
        $select_driver_name = ", d.`{$driver_name_col}` AS driver_name";
        $join_driver = " LEFT JOIN {$drivers_table} d ON o.driver_id = d.id ";
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
            h.name AS hub_name,
            h.city_id AS hub_city_id
            {$select_latlng}
            {$select_notes}
            {$select_items}
            {$select_driver_name}
        FROM {$orders_table} o
        INNER JOIN {$hubs_table} h ON o.hub_id = h.id
        {$join_driver}
        WHERE o.id = %d
        LIMIT 1
    ";

    $row = $wpdb->get_row($wpdb->prepare($query, $order_id));
    if (!$row) {
        return knx_rest_error('Order not found', 404);
    }

    // Manager scope enforcement (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed = knx_ops_view_order_manager_city_ids($user_id);
        if (empty($allowed)) {
            return knx_rest_error('Manager city assignment not configured', 403);
        }

        $order_city = (int)$row->city_id;
        if (!in_array($order_city, $allowed, true)) {
            return knx_rest_error('Forbidden: order outside manager scope', 403);
        }
    }

    $now_ts = (int)current_time('timestamp');
    $created_ts = strtotime((string)$row->created_at);
    $age = ($created_ts > 0) ? max(0, $now_ts - $created_ts) : 0;

    // Location URL
    $lat = null;
    $lng = null;
    $view_location_url = null;

    if (isset($row->lat) && isset($row->lng)) {
        $lat_f = (float)$row->lat;
        $lng_f = (float)$row->lng;
        if ($lat_f !== 0.0 || $lng_f !== 0.0) {
            $lat = $lat_f;
            $lng = $lng_f;
            $view_location_url = 'https://www.google.com/maps?q=' . rawurlencode($lat_f . ',' . $lng_f);
        }
    }

    // Items best-effort decode
    $items = null;
    if (isset($row->items_json)) {
        $raw_items = $row->items_json;
        if (is_string($raw_items) && $raw_items !== '') {
            $decoded = json_decode($raw_items, true);
            $items = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw_items;
        } else {
            $items = $raw_items;
        }
    }

    $notes = isset($row->notes) ? trim((string)$row->notes) : null;

    $hub_name = trim((string)$row->hub_name);

    $driver_id = isset($row->driver_id) ? (int)$row->driver_id : 0;
    $driver_assigned = ($driver_id > 0);
    $driver_name = isset($row->driver_name) ? trim((string)$row->driver_name) : null;
    if ($driver_name === '') $driver_name = null;

    $payload = [
        'order_id' => (int)$row->order_id, // internal navigation only
        'status' => (string)$row->status,
        'created_at' => (string)$row->created_at,
        'created_age_seconds' => (int)$age,
        'created_human' => knx_ops_view_order_human_age((int)$age),
        'city_id' => (int)$row->city_id,
        'hub' => [
            'id' => (int)$row->hub_id,
            'name' => $hub_name,
            'city_id' => (int)$row->hub_city_id,
        ],
        'customer' => [
            'name' => trim((string)$row->customer_name),
        ],
        'totals' => [
            'total' => (float)$row->total_amount,
            'tip' => (float)$row->tip_amount,
        ],
        'driver' => [
            'assigned' => $driver_assigned,
            'driver_id' => $driver_id > 0 ? $driver_id : null,
            'name' => $driver_name,
        ],
        'location' => [
            'lat' => $lat,
            'lng' => $lng,
            'view_url' => $view_location_url,
        ],
        'raw' => [
            'items' => $items,
            'notes' => $notes,
        ],
    ];

    return knx_rest_response(true, 'View order', [
        'order' => $payload,
        'meta' => [
            'role' => $role,
            'generated_at' => current_time('mysql'),
        ],
    ], 200);
}
