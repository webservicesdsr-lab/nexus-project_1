<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Push — Subscriptions API (SEALED MVP)
 * ----------------------------------------------------------
 * POST /wp-json/knx/v1/push/subscribe
 * POST /wp-json/knx/v1/push/unsubscribe
 *
 * Stores subscriptions for:
 * - driver
 * - manager
 * - super_admin
 *
 * Notes:
 * - Does NOT send push notifications yet.
 * - FAIL-CLOSED: requires session (nonce removed for runtime drivers).
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v1', '/push/subscribe', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_push_subscribe'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/push/unsubscribe', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_push_unsubscribe'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_push_table_name() {
    global $wpdb;

    if (function_exists('knx_table')) {
        $t = knx_table('push_subscriptions');
        if (is_string($t) && $t !== '') return $t;
    }

    return $wpdb->prefix . 'knx_push_subscriptions';
}

function knx_push_table_exists($table) {
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    return (bool) $exists;
}

function knx_api_push_subscribe(WP_REST_Request $req) {
    global $wpdb;

    $session = knx_rest_get_session();
    if (!$session) return knx_rest_error('unauthorized', 401);

    $role = isset($session->role) ? (string) $session->role : '';
    $allowed_roles = ['driver', 'manager', 'super_admin'];
    if (!$role || !in_array($role, $allowed_roles, true)) {
        return knx_rest_error('forbidden', 403);
    }

    $user_id = isset($session->user_id) ? (int) $session->user_id : 0;
    if ($user_id <= 0) return knx_rest_error('forbidden', 403);

    $body = $req->get_json_params();
    if (!is_array($body)) return knx_rest_error('invalid_request', 400);

    $sub = isset($body['subscription']) ? $body['subscription'] : null;
    if (!is_array($sub)) return knx_rest_error('subscription_required', 400);

    $endpoint = isset($sub['endpoint']) ? (string) $sub['endpoint'] : '';
    $endpoint = trim($endpoint);
    if ($endpoint !== '' && strlen($endpoint) > 2000) {
        $endpoint = substr($endpoint, 0, 2000);
    }

    $keys = (isset($sub['keys']) && is_array($sub['keys'])) ? $sub['keys'] : [];
    $p256dh = isset($keys['p256dh']) ? (string) $keys['p256dh'] : '';
    $auth   = isset($keys['auth']) ? (string) $keys['auth'] : '';

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return knx_rest_error('invalid_subscription', 400);
    }

    $table = knx_push_table_name();
    if (!knx_push_table_exists($table)) {
        return knx_rest_error('push_table_missing', 500);
    }

    $hash = hash('sha256', $endpoint);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : '';
    $device_label = isset($body['device_label']) ? sanitize_text_field((string) $body['device_label']) : null;

    $existing_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d AND endpoint_hash = %s LIMIT 1",
        $user_id, $hash
    ));

    $now = current_time('mysql');

    if ($existing_id > 0) {
        $updated = $wpdb->update($table, [
            'role'         => $role,
            'endpoint'     => $endpoint,
            'p256dh'       => $p256dh,
            'auth'         => $auth,
            'user_agent'   => $ua,
            'device_label' => $device_label,
            'updated_at'   => $now,
            'revoked_at'   => null,
        ], ['id' => $existing_id]);

        if ($updated === false) return knx_rest_error('db_update_failed', 500);

        return knx_rest_response(true, 'OK', [
            'subscribed' => true,
            'subscription_id' => $existing_id,
        ], 200);
    }

    $inserted = $wpdb->insert($table, [
        'user_id'       => $user_id,
        'role'          => $role,
        'endpoint'      => $endpoint,
        'endpoint_hash' => $hash,
        'p256dh'        => $p256dh,
        'auth'          => $auth,
        'user_agent'    => $ua,
        'device_label'  => $device_label,
        'created_at'    => $now,
        'updated_at'    => null,
        'revoked_at'    => null,
    ]);

    if (!$inserted) return knx_rest_error('db_insert_failed', 500);

    return knx_rest_response(true, 'OK', [
        'subscribed' => true,
        'subscription_id' => (int) $wpdb->insert_id,
    ], 201);
}

function knx_api_push_unsubscribe(WP_REST_Request $req) {
    global $wpdb;

    $session = knx_rest_get_session();
    if (!$session) return knx_rest_error('unauthorized', 401);

    $role = isset($session->role) ? (string) $session->role : '';
    $allowed_roles = ['driver', 'manager', 'super_admin'];
    if (!$role || !in_array($role, $allowed_roles, true)) {
        return knx_rest_error('forbidden', 403);
    }

    $user_id = isset($session->user_id) ? (int) $session->user_id : 0;
    if ($user_id <= 0) return knx_rest_error('forbidden', 403);

    $body = $req->get_json_params();
    if (!is_array($body)) return knx_rest_error('invalid_request', 400);

    $endpoint = isset($body['endpoint']) ? (string) $body['endpoint'] : '';
    $endpoint = trim($endpoint);
    if ($endpoint === '') return knx_rest_error('endpoint_required', 400);

    $table = knx_push_table_name();
    if (!knx_push_table_exists($table)) {
        return knx_rest_error('push_table_missing', 500);
    }

    $hash = hash('sha256', $endpoint);
    $now  = current_time('mysql');

    $updated = $wpdb->update($table, [
        'revoked_at' => $now,
        'updated_at' => $now,
    ], [
        'user_id' => $user_id,
        'endpoint_hash' => $hash,
    ]);

    if ($updated === false) return knx_rest_error('db_update_failed', 500);

    return knx_rest_response(true, 'OK', [
        'unsubscribed' => ($updated > 0),
    ], 200);
}
