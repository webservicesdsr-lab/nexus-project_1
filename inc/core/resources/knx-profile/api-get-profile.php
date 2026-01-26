<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Profile — Get My Profile (PHASE 2.BETA+)
 * ----------------------------------------------------------
 * Endpoint:
 * - GET /wp-json/knx/v2/profile/me
 *
 * Security:
 * - Route-level: session required (permission_callback)
 * - Handler: status active check
 * - Wrapped with knx_rest_wrap
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v2', '/profile/me', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_get_my_profile')($request);
        },
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_v2_get_my_profile(WP_REST_Request $request) {
    global $wpdb;

    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $users_table = $wpdb->prefix . 'knx_users';

    // Column-safe select list
    $has_name  = function_exists('knx_db_column_exists') ? knx_db_column_exists($users_table, 'name')  : false;
    $has_phone = function_exists('knx_db_column_exists') ? knx_db_column_exists($users_table, 'phone') : false;

    $select = "id, username, email, role, status, created_at";
    if ($has_name)  $select .= ", name";
    if ($has_phone) $select .= ", phone";

    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT {$select} FROM {$users_table} WHERE id = %d",
        $session->user_id
    ));

    if (!$user) return knx_rest_error('User not found', 404);
    if ($user->status !== 'active') return knx_rest_error('Account is inactive', 403);

    return knx_rest_response(true, 'Profile retrieved', [
        'id'         => (int) $user->id,
        'username'   => (string) $user->username,
        'email'      => (string) $user->email,
        'name'       => $has_name  ? (string) ($user->name ?? '')  : null,
        'phone'      => $has_phone ? (string) ($user->phone ?? '') : null,
        'role'       => (string) $user->role,
        'status'     => (string) $user->status,
        'created_at' => (string) $user->created_at,
    ]);
}
