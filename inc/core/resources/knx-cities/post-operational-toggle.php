<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Cities — Operational Toggle (SEALED v2)
 * ----------------------------------------------------------
 * Endpoint:
 * - POST /wp-json/knx/v2/cities/operational-toggle
 *
 * Payload:
 * - city_id (int)
 * - operational (0|1)
 * - knx_nonce
 *
 * Security:
 * - Route-level: session + (super_admin | manager)
 * - Handler: nonce + manager scope enforcement
 * - Soft-delete respected
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/cities/operational-toggle', [
        'methods'  => 'POST',

        // Lazy wrapper execution
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_city_operational_toggle')($request);
        },

        // Anti-bot: block unauth/role BEFORE handler runs
        'permission_callback' => function () {
            if (function_exists('knx_rest_permission_roles')) {
                $cb = knx_rest_permission_roles(['super_admin', 'manager']);
                return $cb();
            }
            return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
        },
    ]);
});

/**
 * Toggle operational flag for a city.
 */
function knx_v2_city_operational_toggle(WP_REST_Request $request) {
    global $wpdb;

    /* --------------------------------------------------
     * 1. Require session (defense-in-depth)
     * -------------------------------------------------- */
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) {
        return $session;
    }

    /* --------------------------------------------------
     * 2. Require role (defense-in-depth)
     * -------------------------------------------------- */
    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) {
        return $roleCheck;
    }

    /* --------------------------------------------------
     * 3. Verify nonce
     * -------------------------------------------------- */
    $nonceCheck = knx_rest_verify_nonce(
        $request->get_param('knx_nonce'),
        'knx_city_operational_toggle'
    );
    if ($nonceCheck instanceof WP_REST_Response) {
        return $nonceCheck;
    }

    /* --------------------------------------------------
     * 4. Validate input
     * -------------------------------------------------- */
    $city_id     = absint($request->get_param('city_id'));
    $operational = $request->get_param('operational');

    if (!$city_id || !in_array((string) $operational, ['0', '1'], true)) {
        return knx_rest_error('Invalid parameters', 400);
    }

    $operational  = (int) $operational;
    $cities_table = $wpdb->prefix . 'knx_cities';

    /* --------------------------------------------------
     * 5. Ensure city exists & not soft-deleted
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
     * 6. Manager scope enforcement
     * -------------------------------------------------- */
    if (($session->role ?? '') === 'manager') {

        $hubs_table = $wpdb->prefix . 'knx_hubs';
        $user_id    = absint($session->user_id ?? 0);

        if (!$user_id) {
            return knx_rest_error('Unauthorized', 401);
        }

        $allowed = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$hubs_table}
            WHERE city_id = %d
              AND manager_user_id = %d
        ", $city_id, $user_id));

        if ($allowed !== 1) {
            return knx_rest_error('Forbidden', 403);
        }
    }

    /* --------------------------------------------------
     * 7. Update operational flag
     * -------------------------------------------------- */
    $updated = $wpdb->update(
        $cities_table,
        ['is_operational' => $operational],
        ['id' => $city_id],
        ['%d'],
        ['%d']
    );

    if ($updated === false) {
        return knx_rest_error('Update failed', 500);
    }

    /* --------------------------------------------------
     * 8. Success response
     * -------------------------------------------------- */
    return knx_rest_response(
        true,
        'Operational status updated',
        [
            'city_id'        => $city_id,
            'is_operational' => $operational,
        ],
        200
    );
}
