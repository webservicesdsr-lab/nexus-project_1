<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Profile — Change Password (Driver Self-Service)
 * ----------------------------------------------------------
 * Endpoint:
 * - POST /wp-json/knx/v2/profile/change-password
 *
 * Security:
 * - Route-level: session required (permission_callback)
 * - Handler: validates current password and updates to new password
 * - Wrapped with knx_rest_wrap
 * - Requires nonce (knx_nonce)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v2', '/profile/change-password', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_change_password')($request);
        },
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_v2_change_password(WP_REST_Request $request) {
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

    $current_password = isset($body['current_password']) ? trim((string)$body['current_password']) : '';
    $new_password = isset($body['new_password']) ? trim((string)$body['new_password']) : '';
    $confirm_password = isset($body['confirm_password']) ? trim((string)$body['confirm_password']) : '';

    // Validation
    if ($current_password === '') {
        return knx_rest_error('Current password is required', 400);
    }

    if ($new_password === '') {
        return knx_rest_error('New password is required', 400);
    }

    if (strlen($new_password) < 8) {
        return knx_rest_error('New password must be at least 8 characters', 400);
    }

    if ($new_password !== $confirm_password) {
        return knx_rest_error('Passwords do not match', 400);
    }

    if ($current_password === $new_password) {
        return knx_rest_error('New password must be different from current password', 400);
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

    // Hash new password
    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);

    // Update password
    $ok = $wpdb->update(
        $users_table,
        [
            'password' => $new_hash,
            'updated_at' => current_time('mysql'),
        ],
        ['id' => $user_id],
        ['%s', '%s'],
        ['%d']
    );

    if ($ok === false) {
        return knx_rest_error('Failed to update password', 500);
    }

    // Success response
    return knx_rest_response(true, 'Password changed successfully', [
        'user_id' => $user_id,
        'username' => $user->username,
        'changed_at' => current_time('mysql'),
    ]);
}
