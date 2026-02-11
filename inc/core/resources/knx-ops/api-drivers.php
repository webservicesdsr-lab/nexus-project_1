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
 * - Manager is city-scoped
 * - Enforces driver <-> city mapping
 * - Filters by active status when supported
 * - Uses $wpdb->prefix always
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
 * Resolve manager allowed city IDs
 */
if (!function_exists('knx_ops_live_orders_manager_city_ids')) {
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

        return array_values(array_filter(array_map('intval', (array)$ids)));
    }
}

function knx_ops_drivers_table_exists($table) {
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    return !empty($exists);
}

function knx_ops_drivers_get_cols($table) {
    global $wpdb;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
    if (!is_array($cols)) return [];
    return array_map('strtolower', $cols);
}

/**
 * Main handler
 */
function knx_ops_get_drivers(WP_REST_Request $request) {
    global $wpdb;

    // Require session
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = (string)($session->role ?? '');
    $user_id = (int)($session->user_id ?? 0);

    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    $city_id_raw = $request->get_param('city_id');
    if ($city_id_raw === null) {
        return knx_rest_error('city_id is required', 400);
    }

    $city_id = (int)$city_id_raw;
    if ($city_id <= 0) {
        return knx_rest_error('city_id must be positive integer', 400);
    }

    // Manager scope enforcement
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed)) return knx_rest_error('Manager city assignment not configured', 403);

        if (!in_array($city_id, $allowed, true)) {
            return knx_rest_error('Forbidden: city outside manager scope', 403);
        }
    }

    $drivers_table = $wpdb->prefix . 'knx_drivers';
    $driver_cities_table = $wpdb->prefix . 'knx_driver_cities';

    if (!knx_ops_drivers_table_exists($drivers_table)) {
        return knx_rest_error('Drivers not configured', 409);
    }

    $mapping_exists = knx_ops_drivers_table_exists($driver_cities_table);
    if (!$mapping_exists && $role === 'manager') {
        return knx_rest_error('Forbidden: cannot validate driver-city mapping', 403);
    }

    $driver_cols = knx_ops_drivers_get_cols($drivers_table);

    $has_id = in_array('id', $driver_cols, true);
    $has_name = in_array('name', $driver_cols, true);
    $has_full_name = in_array('full_name', $driver_cols, true);

    if (!$has_id || (!$has_name && !$has_full_name)) {
        return knx_rest_response(true, 'Drivers', [
            'drivers' => [],
            'meta' => [
                'city_id' => $city_id,
                'count' => 0,
            ],
        ], 200);
    }

    // Build safe select list (NO aliasing)
    $select = ['id'];

    if ($has_name) {
        $select[] = 'name';
    }

    if ($has_full_name && !$has_name) {
        $select[] = 'full_name';
    }

    if (in_array('phone', $driver_cols, true)) $select[] = 'phone';
    if (in_array('email', $driver_cols, true)) $select[] = 'email';

    // Active filter
    $active_sql = '';
    if (in_array('is_active', $driver_cols, true)) {
        $active_sql = "d.`is_active` = 1";
    } elseif (in_array('active', $driver_cols, true)) {
        $active_sql = "d.`active` = 1";
    } elseif (in_array('status', $driver_cols, true)) {
        $active_sql = "LOWER(d.`status`) IN ('active','enabled','on','1')";
    }

    $cols_sql = implode(', ', array_map(function ($c) {
        return "d.`{$c}`";
    }, $select));

    $sql = "SELECT {$cols_sql} FROM {$drivers_table} d";
    $params = [];

    if ($mapping_exists) {
        $sql .= " INNER JOIN {$driver_cities_table} dc
                  ON dc.driver_id = d.id AND dc.city_id = %d";
        $params[] = $city_id;
    }

    if ($active_sql !== '') {
        $sql .= " WHERE {$active_sql}";
    }

    $sql .= " ORDER BY d.`id` ASC LIMIT 500";

    if (!empty($params)) {
        $prepared = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($prepared);
    } else {
        $rows = $wpdb->get_results($sql);
    }

    if (!is_array($rows)) $rows = [];

    $drivers = [];

    foreach ($rows as $r) {
        $name_value = '';

        if (isset($r->name) && $r->name !== '') {
            $name_value = (string)$r->name;
        } elseif (isset($r->full_name) && $r->full_name !== '') {
            $name_value = (string)$r->full_name;
        }

        $drivers[] = [
            'id' => isset($r->id) ? (int)$r->id : 0,
            'name' => $name_value,
            'phone' => isset($r->phone) ? (string)$r->phone : null,
            'email' => isset($r->email) ? (string)$r->email : null,
        ];
    }

    return knx_rest_response(true, 'Drivers', [
        'drivers' => $drivers,
        'meta' => [
            'city_id' => $city_id,
            'count' => count($drivers),
        ],
    ], 200);
}
