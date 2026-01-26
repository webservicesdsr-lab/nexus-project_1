<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Cities — Add City (SEALED v2)
 * ----------------------------------------------------------
 * Endpoint:
 * - POST /wp-json/knx/v2/cities/add
 *
 * Payload:
 * - name (string)
 * - knx_nonce
 *
 * Security:
 * - Route-level: session + super_admin (permission_callback)
 * - Handler: nonce + duplicate prevention
 * - Wrapped with knx_rest_wrap
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/cities/add', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_add_city')($request);
        },

        // Anti-bot: block unauth/role BEFORE handler runs
        'permission_callback' => function () {
            if (function_exists('knx_rest_permission_roles')) {
                $cb = knx_rest_permission_roles(['super_admin']);
                return $cb();
            }
            // Fallback (should not happen if guard is loaded)
            return new WP_Error('knx_forbidden', 'Forbidden', ['status' => 403]);
        },
    ]);
});

function knx_v2_add_city(WP_REST_Request $request) {
    global $wpdb;

    /* ---------------------------
     * Session + role (defense-in-depth)
     * --------------------------- */
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    if (($session->role ?? '') !== 'super_admin') {
        return knx_rest_error('Forbidden', 403);
    }

    /* ---------------------------
     * Nonce
     * --------------------------- */
    $nonceCheck = knx_rest_verify_nonce(
        $request->get_param('knx_nonce'),
        'knx_city_add'
    );
    if ($nonceCheck instanceof WP_REST_Response) return $nonceCheck;

    /* ---------------------------
     * Validate input
     * --------------------------- */
    $name = sanitize_text_field($request->get_param('name'));
    if ($name === '') {
        return knx_rest_error('City name is required', 400);
    }

    $table = $wpdb->prefix . 'knx_cities';

    /* ---------------------------
     * Prevent duplicates
     * --------------------------- */
    $exists = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE name = %s AND deleted_at IS NULL", $name)
    );
    if ($exists > 0) {
        return knx_rest_error('City already exists', 409);
    }

    /* ---------------------------
     * Insert
     * --------------------------- */
    $inserted = $wpdb->insert(
        $table,
        [
            'name'           => $name,
            'status'         => 'active',
            'is_operational' => 1,
            'created_at'     => current_time('mysql'),
        ],
        ['%s', '%s', '%d', '%s']
    );

    if (!$inserted) {
        return knx_rest_error('Database error', 500);
    }

    return knx_rest_response(true, 'City added successfully', [
        'city_id' => $wpdb->insert_id,
        'name'    => $name,
    ]);
}
