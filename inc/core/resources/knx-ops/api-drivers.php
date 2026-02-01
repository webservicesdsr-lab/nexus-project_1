<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Get Drivers (OPS v1)
 * Endpoint: GET /wp-json/knx/v1/ops/drivers?city_id=123
 *
 * Returns drivers assignable for a given city_id.
 *
 * Rules:
 * - Fail-closed: requires session + role (super_admin|manager)
 * - Manager is city-scoped (order city must be within manager allowed cities)
 * - Enforces driver <-> city mapping via {prefix}knx_driver_cities when present
 *   - If mapping table is missing: managers are blocked (403), super_admin allowed
 * - Filters by active status when drivers table supports it
 *
 * Notes:
 * - Uses $wpdb->prefix always
 * - Uses knx_rest_wrap + permission callback roles
 * - No wp_enqueue, no wp_footer dependency
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/drivers', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_ops_get_drivers')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Resolve manager allowed city IDs based on hubs.manager_user_id.
 * Defined defensively (only if not already defined elsewhere).
 *
 * @param int $manager_user_id
 * @return array<int>
 */
if (!function_exists('knx_ops_live_orders_manager_city_ids')) {
    function knx_ops_live_orders_manager_city_ids($manager_user_id) {
        global $wpdb;

        $hubs_table = $wpdb->prefix . 'knx_hubs';

        // Fail-closed if column doesn't exist
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
}

/**
 * Helper: check if a DB table exists (by exact name).
 *
 * @param string $table
 * @return bool
 */
function knx_ops_drivers_table_exists($table) {
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    return !empty($exists);
}

/**
 * Helper: get column names for a table (lowercased).
 *
 * @param string $table
 * @return array<string>
 */
function knx_ops_drivers_get_cols($table) {
    global $wpdb;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
    if (!is_array($cols)) return [];
    return array_map('strtolower', $cols);
}

/**
 * KNX OPS handler: list drivers for a given city_id
 */
function knx_ops_get_drivers(WP_REST_Request $request) {
    global $wpdb;

    // Require session + role (fail-closed)
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    // Optional nonce enforcement (centralized)
    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    // Param: city_id (required)
    $city_id_raw = $request->get_param('city_id');
    if ($city_id_raw === null) {
        return knx_rest_error('city_id is required', 400);
    }

    $city_id = (int)$city_id_raw;
    if ($city_id <= 0) {
        return knx_rest_error('city_id is required and must be a positive integer', 400);
    }

    // Manager scope enforcement (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed)) return knx_rest_error('Manager city assignment not configured', 403);

        if (!in_array($city_id, $allowed, true)) {
            return knx_rest_error('Forbidden: city outside manager scope', 403);
        }
    }

    $drivers_table       = $wpdb->prefix . 'knx_drivers';
    $driver_cities_table = $wpdb->prefix . 'knx_driver_cities';

    // Drivers table must exist
    if (!knx_ops_drivers_table_exists($drivers_table)) {
        return knx_rest_error('Drivers not configured', 409);
    }

    // Inspect driver columns (fail-closed if core columns missing)
    $driver_cols = knx_ops_drivers_get_cols($drivers_table);
    if (!in_array('id', $driver_cols, true) || !in_array('name', $driver_cols, true)) {
        return knx_rest_error('Drivers schema invalid', 409);
    }

    // Select whitelist (only if column exists)
    $select = ['id', 'name'];
    if (in_array('phone', $driver_cols, true)) $select[] = 'phone';
    if (in_array('email', $driver_cols, true)) $select[] = 'email';

    // Active filter (best-effort; if no signal exists, do not block legacy)
    $active_sql = '';
    if (in_array('is_active', $driver_cols, true)) {
        $active_sql = "d.`is_active` = 1";
    } elseif (in_array('active', $driver_cols, true)) {
        $active_sql = "d.`active` = 1";
    } elseif (in_array('status', $driver_cols, true)) {
        $active_sql = "LOWER(d.`status`) IN ('active','enabled','on','1')";
    }

    // Driver-city mapping enforcement
    $mapping_exists = knx_ops_drivers_table_exists($driver_cities_table);
    if (!$mapping_exists && $role === 'manager') {
        // Fail-closed for managers: cannot verify driver-city relationship
        return knx_rest_error('Forbidden: cannot validate driver-city mapping', 403);
    }

    // Build SQL (defensive quoting; only whitelisted columns)
    $cols_sql = implode(', ', array_map(static function ($c) {
        return "d.`{$c}`";
    }, $select));

    $sql = "SELECT {$cols_sql} FROM {$drivers_table} d";
    $where = [];
    $params = [];

    if ($mapping_exists) {
        // Require drivers that serve this city
        $sql .= " INNER JOIN {$driver_cities_table} dc
                  ON dc.driver_id = d.id AND dc.city_id = %d";
        $params[] = $city_id;
    }

    if ($active_sql !== '') {
        $where[] = $active_sql;
    }

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY d.`name` ASC LIMIT 500';

    // Execute
    if (!empty($params)) {
        $prepared = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($prepared);
    } else {
        $rows = $wpdb->get_results($sql);
    }

    if (!is_array($rows)) $rows = [];

    $drivers = [];
    foreach ($rows as $r) {
        $item = [
            'id' => isset($r->id) ? (int)$r->id : 0,
            'name' => isset($r->name) ? (string)$r->name : '',
        ];
        if (property_exists($r, 'phone')) $item['phone'] = (string)$r->phone;
        if (property_exists($r, 'email')) $item['email'] = (string)$r->email;
        $drivers[] = $item;
    }

    return knx_rest_response(true, 'Drivers', [
        'drivers' => $drivers,
        'meta' => [
            'city_id' => $city_id,
            'count' => count($drivers),
        ],
    ], 200);
}
