<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Order Messages API (Driver ↔ Customer)
 * ----------------------------------------------------------
 * Endpoints:
 *   GET  /knx/v1/orders/{id}/messages        — list thread
 *   POST /knx/v1/orders/{id}/messages        — send message
 *   POST /knx/v1/orders/{id}/messages/read   — mark as read
 *
 * Participants:
 *   - driver  (role = driver, driver_id matches order)
 *   - customer (role = customer/guest, customer_id or session_token match)
 *
 * Rules:
 *   - First message ever is always a system welcome message (inserted on first GET).
 *   - Sending is blocked on terminal statuses (completed, cancelled).
 *   - Fail-closed: no existence leak (404 for unauthorized).
 * ==========================================================
 */

add_action('rest_api_init', function () {

    // GET — list messages
    register_rest_route('knx/v1', '/orders/(?P<order_id>\d+)/messages', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_order_messages_list'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required'          => true,
                'validate_callback' => function($p) { return is_numeric($p) && (int)$p > 0; },
            ],
        ],
    ]);

    // POST — send message
    register_rest_route('knx/v1', '/orders/(?P<order_id>\d+)/messages', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_order_messages_send'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required'          => true,
                'validate_callback' => function($p) { return is_numeric($p) && (int)$p > 0; },
            ],
            'body' => [
                'required'          => true,
                'validate_callback' => function($p) { return is_string($p) && mb_strlen(trim($p)) >= 1; },
                'sanitize_callback' => function($p) { return mb_substr(trim(sanitize_text_field($p)), 0, 1000); },
            ],
        ],
    ]);

    // POST — mark messages as read
    register_rest_route('knx/v1', '/orders/(?P<order_id>\d+)/messages/read', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_order_messages_mark_read'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required'          => true,
                'validate_callback' => function($p) { return is_numeric($p) && (int)$p > 0; },
            ],
        ],
    ]);
});

// ──────────────────────────────────────────────────────────
// INTERNAL: ensure table exists + seed system message
// ──────────────────────────────────────────────────────────

function knx_order_messages_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'knx_order_messages';
}

function knx_order_messages_ensure_table(): void {
    global $wpdb;
    $t = knx_order_messages_table();
    if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") === $t) return;

    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$t} (
        `id`              bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        `order_id`        bigint UNSIGNED NOT NULL,
        `sender_user_id`  bigint UNSIGNED DEFAULT NULL,
        `sender_role`     enum('driver','customer','system') NOT NULL DEFAULT 'system',
        `body`            text NOT NULL,
        `read_at`         datetime DEFAULT NULL,
        `created_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_order_id` (`order_id`),
        KEY `idx_created_at` (`created_at`),
        KEY `idx_read_at` (`read_at`)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function knx_order_messages_seed_system_message(int $order_id): void {
    global $wpdb;
    $t = knx_order_messages_table();

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t} WHERE order_id = %d AND sender_role = 'system' LIMIT 1",
        $order_id
    ));
    if ($exists) return;

    $wpdb->insert($t, [
        'order_id'       => $order_id,
        'sender_user_id' => null,
        'sender_role'    => 'system',
        'body'           => '👋 You can use this chat to communicate with your driver. Keep messages clear and brief.',
        'read_at'        => null,
        'created_at'     => current_time('mysql', true),
    ], ['%d', '%s', '%s', '%s', '%s', '%s']);
}

// ──────────────────────────────────────────────────────────
// ACCESS CONTROL: resolve participant or return null
// Returns object { role: 'driver'|'customer', user_id: int, session_token: string|null }
// null = not authorized for this order
// ──────────────────────────────────────────────────────────

function knx_order_messages_resolve_participant(object $order, object $session): ?object {
    $role   = isset($session->role) ? (string)$session->role : '';
    $uid    = isset($session->user_id) ? (int)$session->user_id : 0;
    $stoken = isset($session->token) ? (string)$session->token : '';

    // ── Driver: must be the assigned driver on this order ──
    if ($role === 'driver') {
        // Resolve driver profile id
        $driver_profile_id = 0;
        if (function_exists('knx_get_driver_context')) {
            $ctx = knx_get_driver_context();
            if ($ctx && isset($ctx->driver->id)) {
                $driver_profile_id = (int)$ctx->driver->id;
            }
        }
        $order_driver_id = (int)($order->driver_id ?? 0);
        if ($driver_profile_id <= 0 || $order_driver_id <= 0) return null;
        if ($driver_profile_id !== $order_driver_id) return null;

        return (object)['role' => 'driver', 'user_id' => $uid, 'session_token' => null];
    }

    // ── Customer / Guest: must own the order ──
    if ($role === 'customer') {
        $order_customer_id = (int)($order->customer_id ?? 0);
        if ($uid <= 0 || $order_customer_id <= 0) return null;
        if ($uid !== $order_customer_id) return null;
        return (object)['role' => 'customer', 'user_id' => $uid, 'session_token' => null];
    }

    if ($role === 'guest') {
        $order_session = (string)($order->session_token ?? '');
        if (!$stoken || !$order_session || !hash_equals($order_session, $stoken)) return null;
        return (object)['role' => 'customer', 'user_id' => 0, 'session_token' => $stoken];
    }

    return null;
}

// ──────────────────────────────────────────────────────────
// SHARED: load & authorize order
// ──────────────────────────────────────────────────────────

function knx_order_messages_load_order(int $order_id, object $session): array {
    global $wpdb;
    $table_orders = $wpdb->prefix . 'knx_orders';

    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, hub_id, customer_id, driver_id, status, session_token FROM {$table_orders} WHERE id = %d LIMIT 1",
        $order_id
    ));

    if (!$order) {
        return ['error' => 'not-found', 'status' => 404];
    }

    $participant = knx_order_messages_resolve_participant($order, $session);
    if (!$participant) {
        return ['error' => 'not-found', 'status' => 404]; // no existence leak
    }

    return ['order' => $order, 'participant' => $participant];
}

// ──────────────────────────────────────────────────────────
// FORMAT: single message row → array
// ──────────────────────────────────────────────────────────

function knx_order_messages_format(object $row): array {
    return [
        'id'          => (int)$row->id,
        'sender_role' => (string)$row->sender_role,
        'body'        => (string)$row->body,
        'read_at'     => $row->read_at,
        'created_at'  => (string)$row->created_at,
    ];
}

// ──────────────────────────────────────────────────────────
// HANDLER: GET /orders/{id}/messages
// ──────────────────────────────────────────────────────────

function knx_api_order_messages_list(WP_REST_Request $req): WP_REST_Response {
    global $wpdb;

    $order_id = (int)$req['order_id'];
    $session  = knx_get_session();
    if (!$session) return new WP_REST_Response(['success' => false, 'error' => 'unauthorized'], 401);

    $resolved = knx_order_messages_load_order($order_id, $session);
    if (isset($resolved['error'])) {
        return new WP_REST_Response(['success' => false, 'error' => $resolved['error']], $resolved['status']);
    }

    // Ensure table exists and seed system welcome message on first access
    knx_order_messages_ensure_table();
    knx_order_messages_seed_system_message($order_id);

    $t    = knx_order_messages_table();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, sender_role, body, read_at, created_at FROM {$t} WHERE order_id = %d ORDER BY created_at ASC, id ASC",
        $order_id
    ));

    $messages = array_map('knx_order_messages_format', $rows ?: []);

    // Count unread messages NOT sent by this participant
    $my_role = $resolved['participant']->role; // 'driver' or 'customer'
    $other_role = ($my_role === 'driver') ? 'customer' : 'driver';
    $unread_count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE order_id = %d AND sender_role = %s AND read_at IS NULL",
        $order_id, $other_role
    ));

    return new WP_REST_Response([
        'success'      => true,
        'messages'     => $messages,
        'my_role'      => $my_role,
        'unread_count' => $unread_count,
    ], 200);
}

// ──────────────────────────────────────────────────────────
// HANDLER: POST /orders/{id}/messages
// ──────────────────────────────────────────────────────────

function knx_api_order_messages_send(WP_REST_Request $req): WP_REST_Response {
    global $wpdb;

    $order_id = (int)$req['order_id'];
    $body     = (string)$req->get_param('body');

    if (mb_strlen($body) === 0) {
        return new WP_REST_Response(['success' => false, 'error' => 'empty-body'], 422);
    }

    $session = knx_get_session();
    if (!$session) return new WP_REST_Response(['success' => false, 'error' => 'unauthorized'], 401);

    $resolved = knx_order_messages_load_order($order_id, $session);
    if (isset($resolved['error'])) {
        return new WP_REST_Response(['success' => false, 'error' => $resolved['error']], $resolved['status']);
    }

    $order       = $resolved['order'];
    $participant = $resolved['participant'];

    // Block sending on terminal statuses
    $terminal = ['completed', 'cancelled'];
    if (in_array((string)$order->status, $terminal, true)) {
        return new WP_REST_Response(['success' => false, 'error' => 'order-terminal'], 422);
    }

    knx_order_messages_ensure_table();

    $t      = knx_order_messages_table();
    $result = $wpdb->insert($t, [
        'order_id'       => $order_id,
        'sender_user_id' => $participant->user_id > 0 ? $participant->user_id : null,
        'sender_role'    => $participant->role,
        'body'           => $body,
        'read_at'        => null,
        'created_at'     => current_time('mysql', true),
    ], ['%d', '%s', '%s', '%s', '%s', '%s']);

    if ($result === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'db-error'], 500);
    }

    $new_id = (int)$wpdb->insert_id;
    $row    = $wpdb->get_row($wpdb->prepare(
        "SELECT id, sender_role, body, read_at, created_at FROM {$t} WHERE id = %d",
        $new_id
    ));

    return new WP_REST_Response([
        'success' => true,
        'message' => $row ? knx_order_messages_format($row) : null,
    ], 201);
}

// ──────────────────────────────────────────────────────────
// HANDLER: POST /orders/{id}/messages/read
// ──────────────────────────────────────────────────────────

function knx_api_order_messages_mark_read(WP_REST_Request $req): WP_REST_Response {
    global $wpdb;

    $order_id = (int)$req['order_id'];
    $session  = knx_get_session();
    if (!$session) return new WP_REST_Response(['success' => false, 'error' => 'unauthorized'], 401);

    $resolved = knx_order_messages_load_order($order_id, $session);
    if (isset($resolved['error'])) {
        return new WP_REST_Response(['success' => false, 'error' => $resolved['error']], $resolved['status']);
    }

    $participant = $resolved['participant'];
    $my_role     = $participant->role;
    $other_role  = ($my_role === 'driver') ? 'customer' : 'driver';

    knx_order_messages_ensure_table();

    $t = knx_order_messages_table();
    $wpdb->query($wpdb->prepare(
        "UPDATE {$t} SET read_at = %s WHERE order_id = %d AND sender_role = %s AND read_at IS NULL",
        current_time('mysql', true), $order_id, $other_role
    ));

    return new WP_REST_Response(['success' => true], 200);
}
