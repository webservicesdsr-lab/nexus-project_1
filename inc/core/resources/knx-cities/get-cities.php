<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Cities — GET Cities (SEALED v2)
 * ----------------------------------------------------------
 * Canonical endpoint for Cities UI
 *
 * Endpoints:
 * - GET /wp-json/knx/v2/cities/get   (primary)
 * - GET /wp-json/knx/v2/cities       (alias)
 *
 * Security:
 * - Route-level permission_callback (anti-bot): super_admin | manager
 * - Session required (handler)
 * - Role-based access (handler)
 * - Soft-delete aware
 * - Wrapped with knx_rest_wrap
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/cities/get', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_get_cities')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    // Alias (temporary, safe)
    register_rest_route('knx/v2', '/cities', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_get_cities')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * GET Cities handler.
 */
function knx_v2_get_cities(WP_REST_Request $request) {
    global $wpdb;

    /* -----------------------------------------
     * Security — Session
     * ----------------------------------------- */
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) {
        return $session;
    }

    /* -----------------------------------------
     * Security — Role
     * ----------------------------------------- */
    $role = isset($session->role) ? (string) $session->role : '';
    if (!in_array($role, ['super_admin', 'manager'], true)) {
        return knx_rest_error('Forbidden', 403);
    }

    $cities_table = $wpdb->prefix . 'knx_cities';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    /* -----------------------------------------
     * Column detection (safe)
     * ----------------------------------------- */
    $has_deleted_at     = knx_v2_column_exists($cities_table, 'deleted_at');
    $has_is_operational = knx_v2_column_exists($cities_table, 'is_operational');
    $has_state          = knx_v2_column_exists($cities_table, 'state');
    $has_country        = knx_v2_column_exists($cities_table, 'country');

    $where_not_deleted   = $has_deleted_at ? "c.deleted_at IS NULL" : "1=1";
    $select_operational  = $has_is_operational ? "c.is_operational" : "1 AS is_operational";

    /* -----------------------------------------
     * SUPER ADMIN → all cities
     * ----------------------------------------- */
    if ($role === 'super_admin') {

        $cities = $wpdb->get_results("
            SELECT
                c.id,
                c.name,
                c.status,
                {$select_operational},
                " . ($has_state ? "c.state," : "'' AS state,") . "
                " . ($has_country ? "c.country," : "'' AS country,") . "
                c.created_at,
                c.updated_at
            FROM {$cities_table} c
            WHERE {$where_not_deleted}
            ORDER BY c.name ASC
        ");

        return knx_rest_response(true, 'Cities list', [
            'scope'  => 'all',
            'cities' => is_array($cities) ? $cities : [],
        ]);
    }

    /* -----------------------------------------
     * MANAGER → assigned cities only
     * ----------------------------------------- */
    if ($role === 'manager') {

        if (!knx_v2_column_exists($hubs_table, 'manager_user_id')) {
            return knx_rest_error('Manager city assignment not configured', 403);
        }

        $user_id = isset($session->user_id) ? absint($session->user_id) : 0;
        if (!$user_id) {
            return knx_rest_error('Unauthorized', 401);
        }

        $cities = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT
                c.id,
                c.name,
                c.status,
                {$select_operational},
                " . ($has_state ? "c.state," : "'' AS state,") . "
                " . ($has_country ? "c.country" : "'' AS country") . "
            FROM {$cities_table} c
            INNER JOIN {$hubs_table} h ON h.city_id = c.id
            WHERE {$where_not_deleted}
              AND h.manager_user_id = %d
            ORDER BY c.name ASC
        ", $user_id));

        return knx_rest_response(true, 'Cities scope', [
            'scope'  => 'assigned',
            'cities' => is_array($cities) ? $cities : [],
        ]);
    }

    return knx_rest_error('Forbidden', 403);
}

/**
 * Column existence helper (local, guarded to avoid redeclare fatals).
 */
if (!function_exists('knx_v2_column_exists')) {
    function knx_v2_column_exists($table, $column) {
        global $wpdb;
        $col = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)
        );
        return !empty($col);
    }
}
