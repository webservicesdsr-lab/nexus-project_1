<?php
/* inc/core/resources/knx-ops/api-live-orders.php */
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Live Orders (Manager Board) — CANON y05
 * Endpoint: GET /wp-json/knx/v1/ops/live-orders
 *
 * Scope:
 * - Roles: super_admin, manager
 * - Manager scope: {prefix}knx_manager_cities (manager_user_id, city_id)
 * - Statuses are DB-truth (ENUM):
 *   placed, accepted_by_driver, confirmed, preparing, prepared, out_for_delivery
 * - We DO NOT expose pending_payment in this board.
 *
 * Notes:
 * - Fail-closed for managers with no city scope rows.
 * - Accepts city_ids[] (array) and legacy cities=1,2 (csv).
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
 * Resolve manager allowed city IDs based on knx_manager_cities pivot.
 * Fail-closed if missing or empty.
 *
 * @param int $manager_user_id
 * @return array<int>
 */
function knx_ops_live_orders_manager_city_ids($manager_user_id) {
    global $wpdb;

    $manager_user_id = (int)$manager_user_id;
    if ($manager_user_id <= 0) return [];

    $pivot = $wpdb->prefix . 'knx_manager_cities';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pivot));
    if (empty($exists)) return [];

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id
         FROM {$pivot}
         WHERE manager_user_id = %d
           AND city_id IS NOT NULL",
        $manager_user_id
    ));

    $ids = array_map('intval', (array)$ids);
    $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));
    return $ids;
}

/**
 * Parse city IDs from request:
 * - city_ids[]=1&city_ids[]=2
 * - city_ids=[1,2] (json string)
 * - cities=1,2 (legacy csv)
 * - cities[]=1&cities[]=2 (legacy)
 *
 * @param WP_REST_Request $request
 * @return array<int>
 */
function knx_ops_live_orders_parse_city_ids(WP_REST_Request $request) {
    $city_ids = [];

    $raw = $request->get_param('city_ids');
    if (is_string($raw)) {
        $maybe = json_decode($raw, true);
        if (is_array($maybe)) $raw = $maybe;
    }
    if (is_array($raw)) {
        foreach ($raw as $c) {
            $n = (int)$c;
            if ($n > 0) $city_ids[] = $n;
        }
    }

    if (empty($city_ids)) {
        $legacy = $request->get_param('cities');

        if (is_string($legacy)) {
            $legacy = trim($legacy);
            if ($legacy !== '') {
                $parts = preg_split('/\s*,\s*/', $legacy);
                foreach ((array)$parts as $p) {
                    $n = (int)$p;
                    if ($n > 0) $city_ids[] = $n;
                }
            }
        } elseif (is_array($legacy)) {
            foreach ($legacy as $p) {
                $n = (int)$p;
                if ($n > 0) $city_ids[] = $n;
            }
        }
    }

    return array_values(array_unique($city_ids));
}

/**
 * Best-effort: detect lat/lng columns on orders table.
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
 * Human age helper.
 * @param int $age_seconds
 * @return string
 */
function knx_ops_live_orders_human_age($age_seconds) {
    $age_seconds = (int)$age_seconds;
    if ($age_seconds <= 60) return 'Just now';

    if (function_exists('human_time_diff')) {
        $now = (int)current_time('timestamp');
        $from = max(0, $now - $age_seconds);
        return human_time_diff($from, $now) . ' ago';
    }

    $mins = max(1, (int)floor($age_seconds / 60));
    return $mins . ' min ago';
}

function knx_ops_live_orders(WP_REST_Request $request) {
    global $wpdb;

    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    // City scope / request
    $all_cities = false;
    $city_id_param = $request->get_param('city_id');

    if (is_string($city_id_param) && strtolower($city_id_param) === 'all') {
        if ($role !== 'super_admin') return knx_rest_error('Forbidden: city_id=all only for super_admin', 403);
        $all_cities = true;
        $city_ids = [];
    } else {
        $city_ids = knx_ops_live_orders_parse_city_ids($request);
        if (empty($city_ids)) return knx_rest_error('city_ids is required and must be non-empty', 400);
    }

    // Manager scope enforcement (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed)) return knx_rest_error('Manager city assignment not configured', 403);

        foreach ($city_ids as $c) {
            if (!in_array((int)$c, $allowed, true)) {
                return knx_rest_error('Forbidden: city outside manager scope', 403);
            }
        }
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    // Live board statuses (NO pending_payment, NO ready)
    $live_statuses = ['placed','accepted_by_driver','confirmed','preparing','prepared','out_for_delivery'];

    $latlng = knx_ops_live_orders_detect_latlng_columns();
    $select_latlng = '';
    if ($latlng) {
        $select_latlng = ", o.`{$latlng['lat']}` AS lat, o.`{$latlng['lng']}` AS lng";
    }

    $status_ph = implode(',', array_fill(0, count($live_statuses), '%s'));

    if ($all_cities) {
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
                COALESCE(NULLIF(h.logo_url, ''), NULL) AS hub_thumbnail
                {$select_latlng}
            FROM {$orders_table} o
            INNER JOIN {$hubs_table} h ON o.hub_id = h.id
            WHERE o.status IN ({$status_ph})
            ORDER BY o.created_at DESC
            LIMIT 250
        ";
        $prepared = $wpdb->prepare($query, $live_statuses);
    } else {
        $city_ph = implode(',', array_fill(0, count($city_ids), '%d'));
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
                COALESCE(NULLIF(h.logo_url, ''), NULL) AS hub_thumbnail
                {$select_latlng}
            FROM {$orders_table} o
            INNER JOIN {$hubs_table} h ON o.hub_id = h.id
            WHERE o.city_id IN ({$city_ph})
              AND o.status IN ({$status_ph})
            ORDER BY o.created_at DESC
            LIMIT 250
        ";
        $params = array_merge(array_map('intval', $city_ids), $live_statuses);
        $prepared = $wpdb->prepare($query, $params);
    }

    $rows = $wpdb->get_results($prepared);
    $now_ts = (int)current_time('timestamp');

    $orders = [];
    foreach ((array)$rows as $r) {
        $created_ts = strtotime((string)$r->created_at);
        $age = ($created_ts > 0) ? max(0, $now_ts - $created_ts) : 0;

        $view_location_url = null;
        if (isset($r->lat, $r->lng)) {
            $lat = (float)$r->lat;
            $lng = (float)$r->lng;
            if ($lat !== 0.0 || $lng !== 0.0) {
                $view_location_url = 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);
            }
        }

        $hub_name = trim((string)$r->hub_name);

        $orders[] = [
            'order_id' => (int)$r->order_id,
            'city_id' => (int)$r->city_id,
            'hub_id' => (int)$r->hub_id,
            'restaurant_name' => $hub_name,
            'hub_name' => $hub_name,
            'hub_thumbnail' => isset($r->hub_thumbnail) ? (string)$r->hub_thumbnail : null,
            'customer_name' => trim((string)$r->customer_name),
            'created_at' => (string)$r->created_at,
            'created_age_seconds' => (int)$age,
            'created_human' => knx_ops_live_orders_human_age((int)$age),
            'total_amount' => (float)$r->total_amount,
            'tip_amount' => (float)$r->tip_amount,
            'status' => (string)$r->status, // DB-truth
            'assigned_driver' => (!empty($r->driver_id) && (int)$r->driver_id > 0),
            'view_location_url' => $view_location_url,
        ];
    }

    return knx_rest_response(true, 'Live orders', [
        'orders' => $orders,
        'meta' => [
            'role' => $role,
            'generated_at' => current_time('mysql'),
            'count' => count($orders),
        ],
    ], 200);
}
