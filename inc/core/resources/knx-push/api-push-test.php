<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/push/test', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_push_test'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_api_push_test(WP_REST_Request $req) {
    global $wpdb;

    $session = knx_rest_get_session();
    if (!$session) return knx_rest_response(false, 'unauthorized', null, 401);

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;
    if ($user_id <= 0) return knx_rest_response(false, 'forbidden', null, 403);

    $body = $req->get_json_params();
    $target = isset($body['target']) ? (string)$body['target'] : '';
    if ($target === '') $target = strtolower($role);

    // Only allow driver/manager/super_admin targets
    if (!in_array($target, ['driver','manager','super_admin'], true)) {
        return knx_rest_response(false, 'invalid_target', null, 400);
    }

    // Find subscription for this user/role
    $table = function_exists('knx_push_table_name') ? knx_push_table_name() : $wpdb->prefix . 'knx_push_subscriptions';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) return knx_rest_response(false, 'push_table_missing', null, 500);

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d AND role = %s AND revoked_at IS NULL LIMIT 1", $user_id, $role), ARRAY_A);
    if (!$row) return knx_rest_response(false, 'no_active_subscription', null, 409);

    // NOTE: Actual Web Push delivery requires a push sender (VAPID keys + web-push library).
    // For MVP, validate subscription exists and return ok (UI will show result). If a send helper exists, use it.
    if (function_exists('knx_push_send')) {
        $sent = knx_push_send($row, [ 'title' => 'Nexus', 'body' => 'Push notifications are working 🚀' ]);
        if (empty($sent['success'])) return knx_rest_response(false, 'push_send_failed', $sent, 500);
        return knx_rest_response(true, 'OK', ['sent' => true], 200);
    }

    return knx_rest_response(true, 'OK', ['mock' => true, 'message' => 'Subscription found; push sender not configured in server.'], 200);
}
