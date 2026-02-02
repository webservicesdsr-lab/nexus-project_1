<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Live Orders (Operational Board)
 * Endpoint: GET /wp-json/knx/v1/ops/live-orders
 *
 * Production notes:
 * - Fail-closed: requires session + role + scoped cities (manager).
 * - Block 1: Accept city_ids[] (array) AND legacy cities=1,2 (CSV).
 * - Block 2: HARD filter only canonical live statuses (no historical statuses).
 * - Always use $wpdb->prefix (no hardcoded table prefixes).
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
 * Fail-closed if assignment column does not exist or no rows found.
 *
 * @param int $manager_user_id
 * @return array<int>
 */
function knx_ops_live_orders_manager_city_ids($manager_user_id) {
    global $wpdb;

    $hubs_table = $wpdb->prefix . 'knx_hubs';

    $col = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$hubs_table} LIKE %s",
        'manager_user_id'
    ));
    if (empty($col)) return [];

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id
         FROM {$hubs_table}
         WHERE manager_user_id = %d
           AND city_id IS NOT NULL",
        (int)$manager_user_id
    ));

    $ids = array_map('intval', (array)$ids);
    $ids = array_values(array_filter($ids, static function ($v) {
        return $v > 0;
    }));

    return $ids;
}

/**
 * Best-effort: detect a lat/lng pair on orders table for "View Location".
 * Cached per-request to avoid repeated SHOW COLUMNS calls.
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

/**
 * Parse and normalize city IDs from request:
 * - city_ids[]=1&city_ids[]=2
 * - city_ids=[1,2] (JSON string)
 * - cities=1,2 (legacy CSV)
 * - cities[]=1&cities[]=2 (legacy repeated)
 *
 * @param WP_REST_Request $request
 * @return array<int>
 */
function knx_ops_live_orders_parse_city_ids(WP_REST_Request $request) {
    $city_ids = [];

    // Primary: city_ids (array) OR JSON string
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

    // Backwards compatibility: cities CSV or cities[]
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

    $city_ids = array_values(array_unique($city_ids));
    return $city_ids;
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

    // BLOCK 1 — City param contract normalization (fail-closed)
    // Accept `city_id=all` for super_admin to indicate all cities (no city filter).
    $all_cities = false;
    $city_id_param = $request->get_param('city_id');
    if (is_string($city_id_param) && strtolower($city_id_param) === 'all') {
        if ($role !== 'super_admin') {
            return knx_rest_error('Forbidden: city_id=all is only allowed for super_admin', 403);
        }
        $all_cities = true;
        $city_ids = [];
    } else {
        $city_ids = knx_ops_live_orders_parse_city_ids($request);
        if (empty($city_ids)) {
            return knx_rest_error('city_ids is required and must be a non-empty array', 400);
        }
    }

    // Manager scope enforcement (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed)) {
            return knx_rest_error('Manager city assignment not configured', 403);
        }

        // Fail-closed if any requested city is outside scope
        foreach ($city_ids as $c) {
            if (!in_array($c, $allowed, true)) {
                return knx_rest_error('Forbidden: city outside manager scope', 403);
            }
        }
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    // BLOCK 2 — OPERATIVE LIVE STATES (HARD FILTER)
    // Decision: Live Orders (OPS v1) returns ONLY operationally active orders.
    // Allowed live statuses for this endpoint:
    //   - placed
    //   - confirmed
    //   - preparing
    //   - assigned
    //   - in_progress
    // Any other status (e.g. ready, completed, cancelled, etc.) is excluded.
    // Historical data will be handled by a separate archive endpoint in a later phase.
    $live_statuses = ['placed', 'confirmed', 'preparing', 'assigned', 'in_progress'];

    // Optional lat/lng columns
    $latlng = knx_ops_live_orders_detect_latlng_columns();
    $lat_col = $latlng ? $latlng['lat'] : null;
    $lng_col = $latlng ? $latlng['lng'] : null;

    $select_latlng = '';
    if ($lat_col && $lng_col) {
        $select_latlng = ", o.`{$lat_col}` AS lat, o.`{$lng_col}` AS lng";
    }

    // Placeholders
    $status_ph = implode(',', array_fill(0, count($live_statuses), '%s'));

    if ($all_cities) {
        // No city filter; only filter by status
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

        $params = $live_statuses;
        $prepared = $wpdb->prepare($query, $params);
    } else {
        $city_ph   = implode(',', array_fill(0, count($city_ids), '%d'));

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

        $params = array_merge($city_ids, $live_statuses);
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
            'order_id' => (int)$r->order_id, // internal use only (View Order navigation)
            'restaurant_name' => $hub_name,  // hubs are restaurants visually
            'hub_name' => $hub_name,
            'hub_thumbnail' => isset($r->hub_thumbnail) && $r->hub_thumbnail !== '' ? (string)$r->hub_thumbnail : null,
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
            'generated_at' => current_time('mysql'),
            'count' => count($orders),
        ],
    ], 200);
}
