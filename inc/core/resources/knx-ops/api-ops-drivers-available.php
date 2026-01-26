<?php
if (!defined('ABSPATH')) exit;

/**
 * KNX OPS — Available Drivers
 * GET /wp-json/knx/v1/ops/drivers/available
 * Returns drivers available for assignment (availability = 'on')
 * Query params: hub_id (optional)
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/drivers/available', [
        'methods'             => 'GET',
        'callback'            => 'knx_api_ops_drivers_available',
        'permission_callback' => knx_rest_permission_roles(['super_admin','manager']),
    ]);
});

function knx_get_available_drivers($hub_id = 0) {
    global $wpdb;

    $drivers_table = $wpdb->prefix . 'knx_drivers';
    if (function_exists('knx_table')) {
        $maybe = knx_table('drivers');
        if (is_string($maybe) && $maybe !== '') $drivers_table = $maybe;
    }

    $availability_table = $wpdb->prefix . 'knx_driver_availability';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_availability');
        if (is_string($maybe) && $maybe !== '') $availability_table = $maybe;
    }

    // If drivers table missing, return empty
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drivers_table));
    if (!$exists) return [];

    // Build base query: select drivers joined to availability (match by user_id OR id)
    $select = "SELECT d.*,
        a.status AS availability_status,
        a.updated_at AS availability_updated_at
        FROM {$drivers_table} d
        LEFT JOIN {$availability_table} a ON (a.driver_user_id = d.user_id OR a.driver_user_id = d.id)";

    $where = " WHERE 1=1";

    // Filter by active driver status column if present
    $cols = $wpdb->get_results("SHOW COLUMNS FROM {$drivers_table}", ARRAY_A);
    $col_names = $cols ? array_map(function($c){ return $c['Field']; }, $cols) : [];
    if (in_array('status', $col_names, true)) {
        $where .= " AND d.status = 'active'";
    }

    // Optional: filter by hub mapping if hub_id provided and mapping table exists
    if ($hub_id && in_array('id', $col_names, true)) {
        $driver_hubs_table = $wpdb->prefix . 'knx_driver_hubs';
        if (function_exists('knx_table')) {
            $maybe = knx_table('driver_hubs');
            if (is_string($maybe) && $maybe !== '') $driver_hubs_table = $maybe;
        }
        $dh_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $driver_hubs_table));
        if ($dh_exists) {
            $select .= " INNER JOIN {$driver_hubs_table} dh ON dh.driver_id = d.id";
            $where .= $wpdb->prepare(" AND dh.hub_id = %d", $hub_id);
        }
    }

    // Only availability = 'on'
    $where .= " AND (a.status = 'on')";

    $sql = $select . $where . " GROUP BY d.id ORDER BY d.full_name ASC";
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!$rows) return [];
    return $rows;
}

function knx_api_ops_drivers_available(WP_REST_Request $req) {
    $hub_id = $req->get_param('hub_id');
    $hub_id = $hub_id ? intval($hub_id) : 0;

    $rows = knx_get_available_drivers($hub_id);
    return knx_rest_response(true, 'OK', [ 'drivers' => $rows ], 200);
}
