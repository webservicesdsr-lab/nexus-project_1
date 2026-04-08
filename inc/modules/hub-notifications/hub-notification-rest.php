<?php
/**
 * ==========================================================
 * KNX Hub Notifications — REST Endpoints
 * ==========================================================
 * Endpoints:
 * - GET  /knx/v1/hub-soft-push/poll      — browser soft-push poll for hub managers
 * - POST /knx/v1/hub-soft-push/ack       — acknowledge a soft-push notification
 * - GET  /knx/v1/hub-notifications/prefs  — read hub notification prefs (ntfy/email)
 * - POST /knx/v1/hub-notifications/prefs  — write hub notification prefs
 * - POST /knx/v1/hub-notifications/test-ntfy — test ntfy for hub
 *
 * All endpoints use session + hub_management role + ownership guard.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

if (!defined('KNX_HUB_SOFT_PUSH_LEASE_SECONDS')) {
    define('KNX_HUB_SOFT_PUSH_LEASE_SECONDS', 30);
}

add_action('rest_api_init', function () {

    // ── Soft-Push Poll ────────────────────────────────────
    register_rest_route('knx/v1', '/hub-soft-push/poll', [
        'methods'             => 'GET',
        'callback'            => 'knx_hn_soft_push_poll',
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // ── Soft-Push Ack ─────────────────────────────────────
    register_rest_route('knx/v1', '/hub-soft-push/ack', [
        'methods'             => 'POST',
        'callback'            => 'knx_hn_soft_push_ack',
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // ── Prefs GET ─────────────────────────────────────────
    register_rest_route('knx/v1', '/hub-notifications/prefs', [
        'methods'             => 'GET',
        'callback'            => 'knx_hn_prefs_get',
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // ── Prefs POST ────────────────────────────────────────
    register_rest_route('knx/v1', '/hub-notifications/prefs', [
        'methods'             => 'POST',
        'callback'            => 'knx_hn_prefs_post',
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // ── Test ntfy ─────────────────────────────────────────
    register_rest_route('knx/v1', '/hub-notifications/test-ntfy', [
        'methods'             => 'POST',
        'callback'            => 'knx_hn_test_ntfy',
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

/**
 * Resolve hub_management context from session.
 * Returns [session, hub_id, user_id] or WP_REST_Response error.
 */
function knx_hn_resolve_hub_context() {
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $role = isset($session->role) ? (string) $session->role : '';
    if (!preg_match('/\bhub(?:[_\-\s]?(?:management|owner|staff|manager))\b/i', $role)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $user_id = isset($session->user_id) ? (int) $session->user_id : 0;
    if ($user_id <= 0) {
        return new WP_REST_Response(['ok' => false, 'error' => 'invalid_session'], 401);
    }

    if (!function_exists('knx_get_managed_hub_ids')) {
        return new WP_REST_Response(['ok' => false, 'error' => 'ownership_engine_missing'], 500);
    }

    $hub_ids = knx_get_managed_hub_ids($user_id);
    if (empty($hub_ids)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'no_hubs_assigned'], 403);
    }

    $hub_id = $hub_ids[0];

    return [$session, $hub_id, $user_id];
}

// ─────────────────────────────────────────────────────────
// Soft-Push Poll
// ─────────────────────────────────────────────────────────
function knx_hn_soft_push_poll(WP_REST_Request $request) {
    $ctx = knx_hn_resolve_hub_context();
    if ($ctx instanceof WP_REST_Response) return $ctx;
    [$session, $hub_id, $user_id] = $ctx;

    if (!knx_hn_ensure_table()) {
        return new WP_REST_Response(['ok' => false, 'error' => 'table_missing'], 500);
    }

    global $wpdb;
    $table       = knx_hn_table_name();
    $now_mysql   = current_time('mysql');
    $lease_until = date('Y-m-d H:i:s', current_time('timestamp') + (int) KNX_HUB_SOFT_PUSH_LEASE_SECONDS);

    // Find a pending soft-push row for THIS hub where the payload targets THIS user
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, payload_json, status, available_at
         FROM {$table}
         WHERE hub_id = %d
           AND channel = 'soft-push'
           AND (
                status = 'pending'
                OR (
                    status = 'processing'
                    AND available_at IS NOT NULL
                    AND available_at <= %s
                )
           )
           AND payload_json LIKE %s
         ORDER BY created_at ASC, id ASC
         LIMIT 1",
        $hub_id,
        $now_mysql,
        '%"user_id":' . $user_id . '%'
    ));

    if (!$row) {
        return new WP_REST_Response(['ok' => true, 'has' => false], 200);
    }

    $payload = json_decode($row->payload_json, true);
    if (!is_array($payload) || empty($payload['title']) || empty($payload['body']) || empty($payload['url'])) {
        knx_hn_update_status((int) $row->id, 'failed', false);
        return new WP_REST_Response(['ok' => true, 'has' => false], 200);
    }

    // Claim with lease
    $updated = $wpdb->update(
        $table,
        ['status' => 'processing', 'available_at' => $lease_until],
        ['id' => (int) $row->id, 'status' => (string) $row->status],
        ['%s', '%s'],
        ['%d', '%s']
    );

    if ($updated === false || $updated < 1) {
        return new WP_REST_Response(['ok' => true, 'has' => false], 200);
    }

    $safe_payload = [
        'title'           => (string) $payload['title'],
        'body'            => (string) $payload['body'],
        'url'             => esc_url_raw((string) $payload['url']),
        'notification_id' => (int) $row->id,
    ];

    if (isset($payload['order_id'])) $safe_payload['order_id'] = (int) $payload['order_id'];
    if (isset($payload['hub_id']))   $safe_payload['hub_id']   = (int) $payload['hub_id'];

    return new WP_REST_Response([
        'ok'      => true,
        'has'     => true,
        'payload' => $safe_payload,
    ], 200);
}

// ─────────────────────────────────────────────────────────
// Soft-Push Ack
// ─────────────────────────────────────────────────────────
function knx_hn_soft_push_ack(WP_REST_Request $request) {
    $ctx = knx_hn_resolve_hub_context();
    if ($ctx instanceof WP_REST_Response) return $ctx;
    [$session, $hub_id, $user_id] = $ctx;

    $body = $request->get_json_params();
    $nid  = isset($body['notification_id']) ? (int) $body['notification_id'] : 0;

    if ($nid <= 0) {
        return new WP_REST_Response(['ok' => false, 'error' => 'invalid_id'], 400);
    }

    global $wpdb;
    $table = knx_hn_table_name();

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, hub_id, channel, status FROM {$table} WHERE id = %d LIMIT 1",
        $nid
    ));

    if (!$row) {
        return new WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);
    }

    if ((int) $row->hub_id !== $hub_id) {
        return new WP_REST_Response(['ok' => false, 'error' => 'not_owner'], 403);
    }

    if ((string) $row->channel !== 'soft-push') {
        return new WP_REST_Response(['ok' => false, 'error' => 'invalid_channel'], 400);
    }

    if ((string) $row->status === 'delivered') {
        return new WP_REST_Response(['ok' => true, 'already_delivered' => true], 200);
    }

    $wpdb->update(
        $table,
        ['status' => 'delivered', 'sent_at' => current_time('mysql'), 'available_at' => null],
        ['id' => $nid, 'hub_id' => $hub_id, 'channel' => 'soft-push'],
        ['%s', '%s', '%s'],
        ['%d', '%d', '%s']
    );

    return new WP_REST_Response(['ok' => true], 200);
}

// ─────────────────────────────────────────────────────────
// Prefs GET
// ─────────────────────────────────────────────────────────
function knx_hn_prefs_get(WP_REST_Request $request) {
    $ctx = knx_hn_resolve_hub_context();
    if ($ctx instanceof WP_REST_Response) return $ctx;
    [$session, $hub_id, $user_id] = $ctx;

    return knx_rest_response(true, 'OK', [
        'hub_id'        => $hub_id,
        'ntfy_enabled'  => knx_hn_get_hub_setting($hub_id, 'ntfy_enabled') ?: '0',
        'ntfy_topic'    => knx_hn_get_hub_setting($hub_id, 'ntfy_topic') ?: '',
        'email_enabled' => knx_hn_get_hub_setting($hub_id, 'email_enabled') ?: '0',
        'email_to'      => knx_hn_get_hub_setting($hub_id, 'email_to') ?: '',
    ]);
}

// ─────────────────────────────────────────────────────────
// Prefs POST
// ─────────────────────────────────────────────────────────
function knx_hn_prefs_post(WP_REST_Request $request) {
    $ctx = knx_hn_resolve_hub_context();
    if ($ctx instanceof WP_REST_Response) return $ctx;
    [$session, $hub_id, $user_id] = $ctx;

    $body = $request->get_json_params();
    if (!is_array($body)) $body = [];

    if (isset($body['ntfy_enabled'])) {
        knx_hn_set_hub_setting($hub_id, 'ntfy_enabled', $body['ntfy_enabled'] ? '1' : '0');
    }

    if (isset($body['ntfy_topic'])) {
        $topic = sanitize_text_field(trim($body['ntfy_topic']));
        knx_hn_set_hub_setting($hub_id, 'ntfy_topic', $topic);
    }

    if (isset($body['email_enabled'])) {
        knx_hn_set_hub_setting($hub_id, 'email_enabled', $body['email_enabled'] ? '1' : '0');
    }

    if (isset($body['email_to'])) {
        // Sanitize comma-separated email list
        $raw = (string) $body['email_to'];
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $clean = [];
        foreach ($parts as $p) {
            $addr = sanitize_email($p);
            if (is_email($addr)) $clean[] = $addr;
        }
        knx_hn_set_hub_setting($hub_id, 'email_to', implode(',', $clean));
    }

    return knx_rest_response(true, 'Notification settings saved');
}

// ─────────────────────────────────────────────────────────
// Test ntfy
// ─────────────────────────────────────────────────────────
function knx_hn_test_ntfy(WP_REST_Request $request) {
    $ctx = knx_hn_resolve_hub_context();
    if ($ctx instanceof WP_REST_Response) return $ctx;
    [$session, $hub_id, $user_id] = $ctx;

    $ntfy_enabled = knx_hn_get_hub_setting($hub_id, 'ntfy_enabled');
    if ($ntfy_enabled !== '1') {
        return new WP_REST_Response(['ok' => false, 'error' => 'ntfy_disabled'], 400);
    }

    $body = $request->get_json_params();
    $topic = '';
    if (!empty($body['ntfy_topic'])) {
        $topic = sanitize_text_field($body['ntfy_topic']);
    } else {
        $topic = knx_hn_get_hub_setting($hub_id, 'ntfy_topic');
    }

    if (empty($topic)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'missing_topic'], 400);
    }

    $row = (object) [
        'id'           => 0,
        'hub_id'       => $hub_id,
        'payload_json' => wp_json_encode([
            'title'      => 'Test Notification',
            'body'       => 'Hub notifications are working correctly.',
            'url'        => site_url('/hub-orders'),
            'ntfy_topic' => $topic,
        ]),
    ];

    $res = knx_hn_ntfy_send($row);
    if ($res === true) {
        return knx_rest_response(true, 'Test notification sent successfully');
    }

    if (is_wp_error($res)) {
        return knx_rest_error($res->get_error_message(), 500);
    }

    return knx_rest_error('Failed to send test notification', 500);
}
