<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Cities — Delete City (SEALED v2)
 * ----------------------------------------------------------
 * Endpoint:
 * - POST /wp-json/knx/v2/cities/delete
 *
 * Payload (JSON):
 * - city_id (int)
 * - knx_nonce (string)
 *
 * Security:
 * - Route-level permission_callback (anti-bot): super_admin only
 * - Session required (handler)
 * - Role: super_admin ONLY (handler)
 * - Nonce required (handler)
 * - Soft delete enforced
 * - Blocks delete if city has hubs
 * - Wrapped with knx_rest_wrap (normalized responses)
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/cities/delete', [
        'methods'  => 'POST',

        // Lazy wrapper execution (required)
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_delete_city')($request);
        },

        // Route-level block (anti-bot) — relies on knx-rest-guard.php (128 lines)
        'permission_callback' => knx_rest_permission_roles(['super_admin']),
    ]);
});

/**
 * Delete city handler (soft delete).
 */
function knx_v2_delete_city(WP_REST_Request $request) {
    global $wpdb;

    /* --------------------------------------------------
     * 1) Require session
     * -------------------------------------------------- */
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) {
        return $session;
    }

    /* --------------------------------------------------
     * 2) Require super_admin role
     * -------------------------------------------------- */
    $roleCheck = knx_rest_require_role($session, ['super_admin']);
    if ($roleCheck instanceof WP_REST_Response) {
        return $roleCheck;
    }

    /* --------------------------------------------------
     * 3) Verify nonce
     * -------------------------------------------------- */
    $nonceCheck = knx_rest_verify_nonce(
        $request->get_param('knx_nonce'),
        'knx_city_delete'
    );
    if ($nonceCheck instanceof WP_REST_Response) {
        return $nonceCheck;
    }

    /* --------------------------------------------------
     * 4) Validate input
     * -------------------------------------------------- */
    $city_id = absint($request->get_param('city_id'));
    if (!$city_id) {
        return knx_rest_error('Invalid city_id', 400);
    }

    $cities_table = $wpdb->prefix . 'knx_cities';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    /* --------------------------------------------------
     * 5) Ensure required columns exist
     * -------------------------------------------------- */
    if (!knx_v2_column_exists($cities_table, 'deleted_at')) {
        return knx_rest_error(
            'Missing deleted_at column. Run DB migration first.',
            500
        );
    }

    /* --------------------------------------------------
     * 6) Ensure city exists & not already deleted
     * -------------------------------------------------- */
    $city = $wpdb->get_row($wpdb->prepare("
        SELECT id
        FROM {$cities_table}
        WHERE id = %d
          AND deleted_at IS NULL
        LIMIT 1
    ", $city_id));

    if (!$city) {
        return knx_rest_error('City not found', 404);
    }

    /* --------------------------------------------------
     * 7) Block delete if city has hubs
     * -------------------------------------------------- */
    $hub_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$hubs_table} WHERE city_id = %d",
        $city_id
    ));

    if ($hub_count > 0) {
        return knx_rest_error(
            'City has active hubs and cannot be deleted',
            409,
            ['hub_count' => $hub_count]
        );
    }

    /* --------------------------------------------------
     * 8) Soft delete city
     * -------------------------------------------------- */
    $update_data = [
        'deleted_at' => current_time('mysql'),
        'status'     => 'inactive',
        'updated_at' => current_time('mysql'),
    ];

    $formats = ['%s', '%s', '%s'];

    if (knx_v2_column_exists($cities_table, 'is_operational')) {
        $update_data['is_operational'] = 0;
        $formats[] = '%d';
    }

    $updated = $wpdb->update(
        $cities_table,
        $update_data,
        ['id' => $city_id],
        $formats,
        ['%d']
    );

    if ($updated === false) {
        return knx_rest_error('Database error', 500);
    }

    /* --------------------------------------------------
     * 9) Success
     * -------------------------------------------------- */
    return knx_rest_response(
        true,
        'City deleted successfully',
        [
            'city_id' => $city_id,
        ],
        200
    );
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
