<?php
// File: inc/core/knx-orders/api-order-messages.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Order Messages API (Driver ↔ Customer) — v2.3 (sealed)
 * ----------------------------------------------------------
 * Endpoints:
 *   GET  /knx/v1/orders/{id}/messages
 *     - Optional: ?after_id=123&limit=60
 *     - Returns: messages[], my_role, unread_count, server_last_id
 *
 *   POST /knx/v1/orders/{id}/messages
 *     - Body: { "body": "..." }
 *     - Allowed ONLY for driver/customer/guest(participant as customer)
 *
 *   POST /knx/v1/orders/{id}/messages/read
 *     - Marks OTHER side messages as read (system excluded)
 *     - Allowed ONLY for driver/customer/guest
 *
 * Participants:
 *   - driver   (must match order.driver_id via driver profile id)
 *   - customer (must match order.customer_id)
 *   - guest    (must match order.session_token) => treated as "customer"
 *
 * OPS Visibility:
 *   - super_admin MAY read-only (GET) for observability in OPS view-order
 *   - super_admin cannot POST; /read is a no-op success
 *
 * Rules:
 *   - Seed: if thread is empty, insert ONE system welcome message.
 *   - Send blocked on terminal statuses: completed, cancelled.
 *   - Fail-closed: unauthorized returns 404 (no existence leak).
 * ==========================================================
 */

add_action('rest_api_init', function () {

    // GET — list messages (supports incremental)
    register_rest_route('knx/v1', '/orders/(?P<order_id>\d+)/messages', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_order_messages_list'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required'          => true,
                'validate_callback' => function ($p) { return is_numeric($p) && (int)$p > 0; },
            ],
            'after_id' => [
                'required'          => false,
                'validate_callback' => function ($p) {
                    if ($p === null || $p === '') return true;
                    return is_numeric($p) && (int)$p >= 0;
                },
            ],
            'limit' => [
                'required'          => false,
                'validate_callback' => function ($p) {
                    if ($p === null || $p === '') return true;
                    return is_numeric($p) && (int)$p >= 1;
                },
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
                'validate_callback' => function ($p) { return is_numeric($p) && (int)$p > 0; },
            ],
            'body' => [
                'required'          => true,
                'validate_callback' => function ($p) { return is_string($p) && mb_strlen(trim($p)) >= 1; },
                'sanitize_callback' => function ($p) { return mb_substr(trim(sanitize_textarea_field($p)), 0, 1000); },
            ],
        ],
    ]);

    // POST — mark as read
    register_rest_route('knx/v1', '/orders/(?P<order_id>\d+)/messages/read', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_order_messages_mark_read'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required'          => true,
                'validate_callback' => function ($p) { return is_numeric($p) && (int)$p > 0; },
            ],
        ],
    ]);
});

/* ──────────────────────────────────────────────────────────
   INTERNAL: table + helpers
   ────────────────────────────────────────────────────────── */

function knx_order_messages_table() {
    global $wpdb;
    return $wpdb->prefix . 'knx_order_messages';
}

function knx_order_messages_table_exists() {
    global $wpdb;
    $t = knx_order_messages_table();
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
    return $found === $t;
}

/**
 * Ensures table exists (safety net). Canon install should still use schema/installer.
 */
function knx_order_messages_ensure_table() {
    if (knx_order_messages_table_exists()) return;

    global $wpdb;
    $t = knx_order_messages_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$t} (
      `id`             bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `order_id`       bigint UNSIGNED NOT NULL,
      `sender_user_id` bigint UNSIGNED DEFAULT NULL,
      `sender_role`    enum('driver','customer','system') NOT NULL DEFAULT 'system',
      `body`           text NOT NULL,
      `read_at`        datetime DEFAULT NULL,
      `created_at`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_order_id_id` (`order_id`, `id`),
      KEY `idx_order_id_created_at` (`order_id`, `created_at`),
      KEY `idx_order_id_read_at` (`order_id`, `read_at`),
      KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Seed system message ONLY if thread is empty.
 */
function knx_order_messages_seed_system_message($order_id) {
    global $wpdb;
    $t = knx_order_messages_table();

    $has_any = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE order_id = %d",
        (int)$order_id
    ));
    if ($has_any > 0) return;

    $wpdb->insert(
        $t,
        [
            'order_id'       => (int)$order_id,
            'sender_user_id' => null,
            'sender_role'    => 'system',
            'body'           => '👋 You can use this chat to communicate with your driver. Keep messages clear and brief.',
            'read_at'        => null,
            'created_at'     => current_time('mysql', true),
        ],
        ['%d', '%d', '%s', '%s', '%s', '%s']
    );
}

/* ──────────────────────────────────────────────────────────
   ACCESS CONTROL
   Returns object:
     - participant_role: 'driver'|'customer'|'ops'
     - user_id: int
     - session_token: string|null
   Null => not authorized
   ────────────────────────────────────────────────────────── */

function knx_order_messages_resolve_participant($order, $session) {
    $role   = isset($session->role) ? (string)$session->role : '';
    $uid    = isset($session->user_id) ? (int)$session->user_id : 0;
    $stoken = isset($session->token) ? (string)$session->token : '';

    // super_admin read-only (OPS observability)
    if ($role === 'super_admin') {
        if ($uid <= 0) return null;
        return (object)[
            'participant_role' => 'ops',
            'user_id'          => $uid,
            'session_token'    => null,
        ];
    }

    // Driver: must match assigned driver_id (driver profile id)
    if ($role === 'driver') {
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

        return (object)[
            'participant_role' => 'driver',
            'user_id'          => $uid,
            'session_token'    => null,
        ];
    }

    // Customer (logged-in): must match customer_id
    if ($role === 'customer') {
        $order_customer_id = (int)($order->customer_id ?? 0);
        if ($uid <= 0 || $order_customer_id <= 0) return null;
        if ($uid !== $order_customer_id) return null;

        return (object)[
            'participant_role' => 'customer',
            'user_id'          => $uid,
            'session_token'    => null,
        ];
    }

    // Guest: must match session_token (treated as customer)
    if ($role === 'guest') {
        $order_session = (string)($order->session_token ?? '');
        if (!$stoken || !$order_session) return null;
        if (!hash_equals($order_session, $stoken)) return null;

        return (object)[
            'participant_role' => 'customer',
            'user_id'          => 0,
            'session_token'    => $stoken,
        ];
    }

    return null;
}

/* ──────────────────────────────────────────────────────────
   SHARED: load + authorize order (fail-closed)
   ────────────────────────────────────────────────────────── */

function knx_order_messages_load_order($order_id, $session) {
    global $wpdb;
    $orders = $wpdb->prefix . 'knx_orders';

    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, customer_id, driver_id, status, session_token
         FROM {$orders}
         WHERE id = %d
         LIMIT 1",
        (int)$order_id
    ));

    if (!$order) return ['error' => 'not-found', 'status' => 404];

    $participant = knx_order_messages_resolve_participant($order, $session);
    if (!$participant) return ['error' => 'not-found', 'status' => 404];

    return ['order' => $order, 'participant' => $participant];
}

/* ──────────────────────────────────────────────────────────
   FORMAT
   ────────────────────────────────────────────────────────── */

function knx_order_messages_format($row) {
    return [
        'id'          => (int)$row->id,
        'sender_role' => (string)$row->sender_role,
        'body'        => (string)$row->body,
        'read_at'     => $row->read_at ? (string)$row->read_at : null,
        'created_at'  => (string)$row->created_at,
    ];
}

/* ──────────────────────────────────────────────────────────
   GET /orders/{id}/messages
   Supports incremental polling: after_id + limit
   ────────────────────────────────────────────────────────── */

function knx_api_order_messages_list(WP_REST_Request $req) {
    global $wpdb;

    $order_id = (int)$req['order_id'];

    $after_raw = $req->get_param('after_id');
    $after_id  = (is_numeric($after_raw) && (int)$after_raw >= 0) ? (int)$after_raw : 0;

    $limit_raw = $req->get_param('limit');
    $limit_in  = (is_numeric($limit_raw) && (int)$limit_raw >= 1) ? (int)$limit_raw : 60;
    $limit     = max(1, min(200, $limit_in));

    $session = knx_get_session();
    if (!$session) return new WP_REST_Response(['success' => false, 'error' => 'unauthorized'], 401);

    $resolved = knx_order_messages_load_order($order_id, $session);
    if (isset($resolved['error'])) {
        return new WP_REST_Response(['success' => false, 'error' => $resolved['error']], (int)$resolved['status']);
    }

    knx_order_messages_ensure_table();
    knx_order_messages_seed_system_message($order_id);

    $t = knx_order_messages_table();

    if ($after_id > 0) {
        // Incremental: only messages after checkpoint
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender_role, body, read_at, created_at
             FROM {$t}
             WHERE order_id = %d AND id > %d
             ORDER BY id ASC
             LIMIT %d",
            $order_id, $after_id, $limit
        ));
    } else {
        // First load: last N messages (not whole history)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender_role, body, read_at, created_at
             FROM {$t}
             WHERE order_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $order_id, $limit
        ));
        $rows = array_reverse($rows ?: []);
    }

    $messages = array_map('knx_order_messages_format', $rows ?: []);

    $server_last_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(MAX(id),0) FROM {$t} WHERE order_id = %d",
        $order_id
    ));

    $my_role = (string)$resolved['participant']->participant_role; // driver|customer|ops

    // Unread is meaningful only for driver/customer
    $unread_count = 0;
    if ($my_role === 'driver' || $my_role === 'customer') {
        $other_role = ($my_role === 'driver') ? 'customer' : 'driver';
        $unread_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$t}
             WHERE order_id = %d
               AND sender_role = %s
               AND read_at IS NULL",
            $order_id, $other_role
        ));
    }

    return new WP_REST_Response([
        'success'        => true,
        'messages'       => $messages,
        'my_role'        => $my_role,
        'unread_count'   => $unread_count,
        'server_last_id' => $server_last_id,
    ], 200);
}

/* ──────────────────────────────────────────────────────────
   POST /orders/{id}/messages
   Allowed ONLY for driver/customer participants
   ────────────────────────────────────────────────────────── */

function knx_api_order_messages_send(WP_REST_Request $req) {
    global $wpdb;

    $order_id = (int)$req['order_id'];
    $body     = (string)$req->get_param('body');
    $body     = mb_substr(trim($body), 0, 1000);

    if ($body === '') {
        return new WP_REST_Response(['success' => false, 'error' => 'empty-body'], 422);
    }

    $session = knx_get_session();
    if (!$session) return new WP_REST_Response(['success' => false, 'error' => 'unauthorized'], 401);

    $resolved = knx_order_messages_load_order($order_id, $session);
    if (isset($resolved['error'])) {
        return new WP_REST_Response(['success' => false, 'error' => $resolved['error']], (int)$resolved['status']);
    }

    $participant = $resolved['participant'];
    $my_role = (string)$participant->participant_role;

    // OPS read-only (fail-closed without leaking)
    if ($my_role !== 'driver' && $my_role !== 'customer') {
        return new WP_REST_Response(['success' => false, 'error' => 'not-found'], 404);
    }

    $order  = $resolved['order'];
    $status = strtolower((string)($order->status ?? ''));
    if ($status === 'completed' || $status === 'cancelled') {
        return new WP_REST_Response(['success' => false, 'error' => 'order-terminal'], 422);
    }

    knx_order_messages_ensure_table();
    knx_order_messages_seed_system_message($order_id);

    $t = knx_order_messages_table();

    $inserted = $wpdb->insert(
        $t,
        [
            'order_id'       => $order_id,
            'sender_user_id' => ((int)$participant->user_id > 0) ? (int)$participant->user_id : null,
            'sender_role'    => $my_role,
            'body'           => $body,
            'read_at'        => null,
            'created_at'     => current_time('mysql', true),
        ],
        ['%d', '%d', '%s', '%s', '%s', '%s']
    );

    if ($inserted === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'db-error'], 500);
    }

    $new_id = (int)$wpdb->insert_id;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, sender_role, body, read_at, created_at
         FROM {$t}
         WHERE id = %d
         LIMIT 1",
        $new_id
    ));

    return new WP_REST_Response([
        'success' => true,
        'message' => $row ? knx_order_messages_format($row) : null,
    ], 201);
}

/* ──────────────────────────────────────────────────────────
   POST /orders/{id}/messages/read
   Allowed ONLY for driver/customer participants
   ────────────────────────────────────────────────────────── */

function knx_api_order_messages_mark_read(WP_REST_Request $req) {
    global $wpdb;

    $order_id = (int)$req['order_id'];

    $session = knx_get_session();
    if (!$session) return new WP_REST_Response(['success' => false, 'error' => 'unauthorized'], 401);

    $resolved = knx_order_messages_load_order($order_id, $session);
    if (isset($resolved['error'])) {
        return new WP_REST_Response(['success' => false, 'error' => $resolved['error']], (int)$resolved['status']);
    }

    $my_role = (string)$resolved['participant']->participant_role;

    // OPS read-only: no-op success (does not change DB)
    if ($my_role !== 'driver' && $my_role !== 'customer') {
        return new WP_REST_Response(['success' => true], 200);
    }

    $other_role = ($my_role === 'driver') ? 'customer' : 'driver';

    knx_order_messages_ensure_table();

    $t   = knx_order_messages_table();
    $now = current_time('mysql', true);

    // Marks OTHER side messages; system excluded because sender_role=system != other_role
    $wpdb->query($wpdb->prepare(
        "UPDATE {$t}
         SET read_at = %s
         WHERE order_id = %d
           AND sender_role = %s
           AND read_at IS NULL",
        $now, $order_id, $other_role
    ));

    return new WP_REST_Response(['success' => true], 200);
}