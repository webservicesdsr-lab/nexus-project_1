<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Profile — Update My Profile (PHASE 2.BETA+)
 * ----------------------------------------------------------
 * Endpoint:
 * - POST /wp-json/knx/v2/profile/update
 *
 * Payload:
 * - name (required)
 * - phone (required)
 * - email (optional)
 * - knx_nonce (required)
 *
 * Security:
 * - Route-level: session required
 * - Handler: nonce + validation
 * - Wrapped with knx_rest_wrap
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v2', '/profile/update', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_update_my_profile')($request);
        },
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_v2_update_my_profile(WP_REST_Request $request) {
    global $wpdb;

    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $users_table = $wpdb->prefix . 'knx_users';

    // Schema check (prevents "Unknown column" DB errors)
    $has_name  = function_exists('knx_db_column_exists') ? knx_db_column_exists($users_table, 'name')  : false;
    $has_phone = function_exists('knx_db_column_exists') ? knx_db_column_exists($users_table, 'phone') : false;

    if (!$has_name || !$has_phone) {
        return knx_rest_error('Profile schema is not installed (missing name/phone columns).', 501);
    }

    // Nonce validation (forward-compatible + backward-compatible)
    $nonce = $request->get_param('knx_nonce');

    $nonceCheck = knx_rest_verify_nonce($nonce, 'knx:profile:update:v1');
    if ($nonceCheck instanceof WP_REST_Response) {
        // Fallback to legacy action to avoid breaking existing UI
        $legacyCheck = knx_rest_verify_nonce($nonce, 'knx_profile_update');
        if ($legacyCheck instanceof WP_REST_Response) return $nonceCheck;
    }

    // Validate user is active
    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT status FROM {$users_table} WHERE id = %d",
        $session->user_id
    ));

    if (!$user || $user->status !== 'active') {
        return knx_rest_error('Account is not active', 403);
    }

    // Validate required fields
    $name  = sanitize_text_field($request->get_param('name'));
    $phone = sanitize_text_field($request->get_param('phone'));

    if ($name === '' || $phone === '') {
        return knx_rest_error('Name and phone are required', 422);
    }

    // Optional fields
    $email = sanitize_email($request->get_param('email'));

    // Email conflict check (unique constraint)
    if (!empty($email)) {
        $email_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$users_table} WHERE email = %s AND id != %d",
            $email,
            $session->user_id
        ));

        if ((int) $email_exists > 0) {
            return knx_rest_error('Email already in use by another account', 409);
        }
    }

    $update_data = [
        'name'  => $name,
        'phone' => $phone,
    ];
    $update_format = ['%s', '%s'];

    if (!empty($email)) {
        $update_data['email'] = $email;
        $update_format[] = '%s';
    }

    $updated = $wpdb->update(
        $users_table,
        $update_data,
        ['id' => (int) $session->user_id],
        $update_format,
        ['%d']
    );

    if ($updated === false) return knx_rest_error('Failed to update profile', 500);

    // Fetch updated profile (column-safe)
    $updated_user = $wpdb->get_row($wpdb->prepare(
        "SELECT id, username, email, name, phone, role, status, created_at
         FROM {$users_table}
         WHERE id = %d",
        $session->user_id
    ));

    $profile_complete = !empty($updated_user->name) && !empty($updated_user->phone);

    return knx_rest_response(true, 'Profile updated successfully', [
        'profile' => [
            'id'         => (int) $updated_user->id,
            'username'   => (string) $updated_user->username,
            'email'      => (string) $updated_user->email,
            'name'       => (string) $updated_user->name,
            'phone'      => (string) $updated_user->phone,
            'role'       => (string) $updated_user->role,
            'status'     => (string) $updated_user->status,
            'created_at' => (string) $updated_user->created_at,
        ],
        'profile_complete' => (bool) $profile_complete,
        'nonce_action'     => 'knx:profile:update:v1', // informational
    ]);
}
