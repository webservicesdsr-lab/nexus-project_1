<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — OPS Push Subscriptions (MVP v1.0)
 * ----------------------------------------------------------
 * Endpoints (OPS-only):
 *   POST /knx/v2/ops/push/subscribe
 *   POST /knx/v2/ops/push/unsubscribe
 *
 * Access:
 *   Roles: super_admin, manager
 *
 * Separation rule:
 *   audience = "ops_orders" only (DO NOT mix driver audience)
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/ops/push/subscribe', [
        'methods'  => 'POST',
        'callback' => function_exists('knx_rest_wrap') ? knx_rest_wrap('knx_v2_ops_push_subscribe') : 'knx_v2_ops_push_subscribe',
        'permission_callback' => function_exists('knx_rest_permission_roles')
            ? knx_rest_permission_roles(['super_admin', 'manager'])
            : '__return_true',
    ]);

    register_rest_route('knx/v2', '/ops/push/unsubscribe', [
        'methods'  => 'POST',
        'callback' => function_exists('knx_rest_wrap') ? knx_rest_wrap('knx_v2_ops_push_unsubscribe') : 'knx_v2_ops_push_unsubscribe',
        'permission_callback' => function_exists('knx_rest_permission_roles')
            ? knx_rest_permission_roles(['super_admin', 'manager'])
            : '__return_true',
    ]);
});

function knx_ops_push_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_push_subscriptions';

    // Optional table resolver
    if (function_exists('knx_table')) {
        $maybe = knx_table('push_subscriptions');
        if (is_string($maybe) && $maybe !== '') $table = $maybe;
    }
    return $table;
}

function knx_ops_db_cols($table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    global $wpdb;
    $cols = [];
    try {
        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        if ($rows) {
            foreach ($rows as $r) {
                if (!empty($r->Field)) $cols[$r->Field] = true;
            }
        }
    } catch (Throwable $e) {}
    $cache[$table] = $cols;
    return $cols;
}

function knx_ops_session_user_id() {
    if (!function_exists('knx_get_session')) return 0;
    $s = knx_get_session();
    if (!$s) return 0;

    $id = (int) ($s->id ?? 0);
    if ($id > 0) return $id;

    $id = (int) ($s->user_id ?? 0);
    if ($id > 0) return $id;

    return 0;
}

function knx_v2_ops_push_subscribe(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_ops_rest(false, 'Invalid request format.', null, 400);
    }

    $audience = isset($body['audience']) ? sanitize_text_field($body['audience']) : 'ops_orders';
    if ($audience !== 'ops_orders') {
        return knx_ops_rest(false, 'Invalid audience for OPS.', null, 400);
    }

    $sub = $body['subscription'] ?? null;
    if (!$sub || !is_array($sub)) {
        return knx_ops_rest(false, 'Missing subscription.', null, 400);
    }

    $endpoint = isset($sub['endpoint']) ? esc_url_raw($sub['endpoint']) : '';
    $keys = isset($sub['keys']) && is_array($sub['keys']) ? $sub['keys'] : [];
    $p256dh = isset($keys['p256dh']) ? sanitize_text_field($keys['p256dh']) : '';
    $auth   = isset($keys['auth']) ? sanitize_text_field($keys['auth']) : '';

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return knx_ops_rest(false, 'Invalid subscription payload.', null, 400);
    }

    $table = knx_ops_push_table();
    $cols  = knx_ops_db_cols($table);

    if (!$cols) {
        return knx_ops_rest(false, 'Push table not found or inaccessible.', [
            'table' => $table
        ], 500);
    }

    $user_id = knx_ops_session_user_id();
    if ($user_id <= 0) {
        return knx_ops_rest(false, 'Missing session user.', null, 401);
    }

    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = (string) ($session->role ?? 'manager');

    // Build row (best-effort compatible with existing schemas)
    $now = current_time('mysql');
    $json = wp_json_encode([
        'audience' => 'ops_orders',
        'subscription' => $sub,
    ]);

    // Upsert by endpoint (canonical)
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE endpoint = %s",
        $endpoint
    ));

    if ($exists) {
        $update = [];
        $where  = ['endpoint' => $endpoint];

        if (isset($cols['user_id'])) $update['user_id'] = $user_id;
        if (isset($cols['role'])) $update['role'] = $role;
        if (isset($cols['audience'])) $update['audience'] = 'ops_orders';
        if (isset($cols['p256dh'])) $update['p256dh'] = $p256dh;
        if (isset($cols['auth'])) $update['auth'] = $auth;
        if (isset($cols['subscription_json'])) $update['subscription_json'] = $json;
        if (isset($cols['json'])) $update['json'] = $json;
        if (isset($cols['status'])) $update['status'] = 'active';
        if (isset($cols['updated_at'])) $update['updated_at'] = $now;

        $ok = $wpdb->update($table, $update, $where);

        if ($ok === false) {
            return knx_ops_rest(false, 'Failed to update subscription.', null, 500);
        }

        return knx_ops_rest(true, 'OPS subscription updated.', [
            'audience' => 'ops_orders',
            'endpoint' => $endpoint,
        ], 200);
    }

    // Insert new
    $insert = [];

    if (isset($cols['user_id'])) $insert['user_id'] = $user_id;
    if (isset($cols['role'])) $insert['role'] = $role;
    if (isset($cols['audience'])) $insert['audience'] = 'ops_orders';

    // Always insert endpoint if present in schema
    $insert['endpoint'] = $endpoint;

    if (isset($cols['p256dh'])) $insert['p256dh'] = $p256dh;
    if (isset($cols['auth'])) $insert['auth'] = $auth;

    if (isset($cols['subscription_json'])) $insert['subscription_json'] = $json;
    if (isset($cols['json'])) $insert['json'] = $json;

    if (isset($cols['status'])) $insert['status'] = 'active';

    if (isset($cols['created_at'])) $insert['created_at'] = $now;
    if (isset($cols['updated_at'])) $insert['updated_at'] = $now;

    $ok = $wpdb->insert($table, $insert);
    if (!$ok) {
        return knx_ops_rest(false, 'Failed to create subscription.', [
            'hint' => 'Check table columns: endpoint,user_id,role,audience,p256dh,auth,created_at,updated_at'
        ], 500);
    }

    return knx_ops_rest(true, 'OPS subscription created.', [
        'audience' => 'ops_orders',
        'endpoint' => $endpoint,
        'id' => (int) $wpdb->insert_id,
    ], 201);
}

function knx_v2_ops_push_unsubscribe(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_ops_rest(false, 'Invalid request format.', null, 400);
    }

    $audience = isset($body['audience']) ? sanitize_text_field($body['audience']) : 'ops_orders';
    if ($audience !== 'ops_orders') {
        return knx_ops_rest(false, 'Invalid audience for OPS.', null, 400);
    }

    $endpoint = isset($body['endpoint']) ? esc_url_raw($body['endpoint']) : '';
    if ($endpoint === '') {
        return knx_ops_rest(false, 'Missing endpoint.', null, 400);
    }

    $table = knx_ops_push_table();
    $cols  = knx_ops_db_cols($table);

    if (!$cols) {
        return knx_ops_rest(false, 'Push table not found or inaccessible.', [
            'table' => $table
        ], 500);
    }

    // If schema has status, soft-disable; else hard delete
    if (isset($cols['status'])) {
        $ok = $wpdb->update($table, [
            'status' => 'inactive',
            'updated_at' => current_time('mysql'),
        ], [
            'endpoint' => $endpoint,
        ]);

        if ($ok === false) {
            return knx_ops_rest(false, 'Failed to disable subscription.', null, 500);
        }

        return knx_ops_rest(true, 'OPS subscription disabled.', [
            'audience' => 'ops_orders',
            'endpoint' => $endpoint,
        ], 200);
    }

    $ok = $wpdb->delete($table, ['endpoint' => $endpoint]);
    if ($ok === false) {
        return knx_ops_rest(false, 'Failed to delete subscription.', null, 500);
    }

    return knx_ops_rest(true, 'OPS subscription removed.', [
        'audience' => 'ops_orders',
        'endpoint' => $endpoint,
    ], 200);
}

/**
 * Standard response wrapper (uses knx_rest_response if available).
 */
function knx_ops_rest($success, $message, $data = null, $code = 200) {
    if (function_exists('knx_rest_response')) {
        return knx_rest_response((bool) $success, (string) $message, $data, (int) $code);
    }
    return new WP_REST_Response([
        'success' => (bool) $success,
        'message' => (string) $message,
        'data'    => $data,
    ], (int) $code);
}
