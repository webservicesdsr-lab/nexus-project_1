<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Profile — Change Username (Driver Self-Service)
 * ----------------------------------------------------------
 * Endpoint:
 * - POST /wp-json/knx/v2/profile/change-username
 *
 * Security:
 * - Route-level: session required (permission_callback)
 * - Handler: validates current password, checks username uniqueness, updates username
 * - Wrapped with knx_rest_wrap
 * - Requires nonce (knx_nonce)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v2', '/profile/change-username', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_change_username')($request);
        },
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_v2_change_username(WP_REST_Request $request) {
    global $wpdb;

    // Require session
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    // Require nonce for CSRF protection
    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    // Get JSON body
    $body = $request->get_json_params();
    if (!is_array($body)) $body = $request->get_params();

    $new_username = isset($body['new_username']) ? trim((string)$body['new_username']) : '';
    $current_password = isset($body['current_password']) ? trim((string)$body['current_password']) : '';

    if ($new_username === '') {
        return knx_rest_error('New username is required', 400);
    }

    if (strlen($new_username) < 3) {
        return knx_rest_error('Username must be at least 3 characters', 400);
    }

    // allowed characters: letters, numbers, hyphen, underscore
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $new_username)) {
        return knx_rest_error('Username contains invalid characters', 400);
    }

    if ($current_password === '') {
        return knx_rest_error('Current password is required', 400);
    }

    $users_table = $wpdb->prefix . 'knx_users';
    $user_id = (int)$session->user_id;

    // Get current user record
    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT id, username, password, status FROM {$users_table} WHERE id = %d",
        $user_id
    ));

    if (!$user) {
        return knx_rest_error('User not found', 404);
    }

    if ($user->status !== 'active') {
        return knx_rest_error('Account is inactive', 403);
    }

    // Verify current password
    if (!password_verify($current_password, $user->password)) {
        return knx_rest_error('Current password is incorrect', 401);
    }

    // Ensure username uniqueness
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$users_table} WHERE username = %s AND id != %d LIMIT 1",
        $new_username,
        $user_id
    ));

    if ($exists) {
        return knx_rest_error('Username is already taken', 409);
    }

    // Update username
    $ok = $wpdb->update(
        $users_table,
        [
            'username' => $new_username,
            'updated_at' => current_time('mysql'),
        ],
        ['id' => $user_id],
        ['%s', '%s'],
        ['%d']
    );

    if ($ok === false) {
        return knx_rest_error('Failed to update username', 500);
    }

    return knx_rest_response(true, 'Username changed successfully', [
        'user_id' => $user_id,
        'username' => $new_username,
        'changed_at' => current_time('mysql'),
    ]);
}

