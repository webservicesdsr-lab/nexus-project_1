<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Management — Hub Orders REST API
 * ----------------------------------------------------------
 * GET  /knx/v1/hub-management/orders         → list hub's orders
 * POST /knx/v1/hub-management/orders/signal   → send "ready for pickup" signal
 * GET  /knx/v1/hub-management/orders/signals  → check signals (for drivers)
 *
 * IMPORTANT — "Ready for Pickup" is a FAKE signal.
 * It does NOT change the order status enum. It only writes to
 * y05_knx_hub_order_signals so drivers see a visual chip.
 * Drivers remain the ONLY role that can change the real status.
 *
 * Security: session + hub ownership (fail-closed)
 * ==========================================================
 */

add_action('rest_api_init', function () {

    // List orders for hub_management's hub(s)
    register_rest_route('knx/v1', '/hub-management/orders', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_hub_orders_list_handler'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // Send "ready for pickup" signal (hub → driver visual only)
    register_rest_route('knx/v1', '/hub-management/orders/signal', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_hub_orders_signal_handler'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // Check signals for a set of order IDs (used by driver-active-orders)
    register_rest_route('knx/v1', '/hub-management/orders/signals', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_hub_orders_check_signals_handler'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

/**
 * Ensure signals table exists (auto-create on first use).
 */
function knx_hub_ensure_signals_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hub_order_signals';
    $charset = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
        return $table;
    }

    $sql = "CREATE TABLE {$table} (
        id         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id   bigint UNSIGNED NOT NULL,
        hub_id     bigint UNSIGNED NOT NULL,
        signal_type varchar(50) NOT NULL DEFAULT 'ready_for_pickup',
        signaled_by bigint UNSIGNED NOT NULL COMMENT 'user_id of hub manager who pressed the button',
        created_at  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_order_signal (order_id, signal_type),
        KEY idx_hub_id (hub_id),
        KEY idx_created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    return $table;
}

/**
 * GET /hub-management/orders
 * Lists paid orders for the hub_management user's assigned hub(s).
 * Accepts: ?status=confirmed,preparing,prepared (optional filter)
 */
function knx_hub_orders_list_handler(WP_REST_Request $request) {
    global $wpdb;

    // Ownership guard
    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $orders_table  = $wpdb->prefix . 'knx_orders';
    $signals_table = knx_hub_ensure_signals_table();

    // Status filter (optional)
    $status_param = $request->get_param('status');
    $allowed_statuses = [
        'confirmed', 'accepted_by_driver', 'accepted_by_hub',
        'preparing', 'prepared', 'picked_up', 'completed', 'cancelled',
    ];

    $status_filter = [];
    if ($status_param) {
        $parts = array_map('trim', explode(',', $status_param));
        foreach ($parts as $s) {
            if (in_array($s, $allowed_statuses, true)) {
                $status_filter[] = $s;
            }
        }
    }

    // Default: show active orders (not completed/cancelled)
    if (empty($status_filter)) {
        $status_filter = ['confirmed', 'accepted_by_driver', 'accepted_by_hub', 'preparing', 'prepared', 'picked_up'];
    }

    $ph = implode(',', array_fill(0, count($status_filter), '%s'));

    // Time window: last 48 hours for active, or user-specified
    $hours = (int) ($request->get_param('hours') ?: 48);
    if ($hours <= 0) $hours = 48;
    if ($hours > 168) $hours = 168;
    $since = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));

    $params = array_merge([$hub_id], $status_filter, [$since]);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT
            o.id AS order_id,
            o.order_number,
            o.customer_name,
            o.customer_phone,
            o.fulfillment_type,
            o.delivery_address,
            o.subtotal,
            o.total,
            o.tip_amount,
            o.status,
            o.payment_status,
            o.notes,
            o.created_at,
            o.updated_at,
            s.id AS signal_id,
            s.signal_type,
            s.created_at AS signaled_at
         FROM {$orders_table} o
         LEFT JOIN {$signals_table} s
            ON s.order_id = o.id AND s.signal_type = 'ready_for_pickup'
         WHERE o.hub_id = %d
           AND o.payment_status = 'paid'
           AND o.status IN ({$ph})
           AND o.created_at >= %s
         ORDER BY
            FIELD(o.status, 'confirmed','accepted_by_driver','accepted_by_hub','preparing','prepared','picked_up','completed','cancelled'),
            o.created_at DESC
         LIMIT 100",
        ...$params
    ));

    $orders = [];
    foreach ($rows as $r) {
        $orders[] = [
            'order_id'         => (int) $r->order_id,
            'order_number'     => $r->order_number,
            'customer_name'    => $r->customer_name,
            'customer_phone'   => $r->customer_phone,
            'fulfillment_type' => $r->fulfillment_type,
            'delivery_address' => $r->delivery_address,
            'subtotal'         => (float) $r->subtotal,
            'total'            => (float) $r->total,
            'tip_amount'       => (float) $r->tip_amount,
            'status'           => $r->status,
            'payment_status'   => $r->payment_status,
            'notes'            => $r->notes,
            'created_at'       => $r->created_at,
            'updated_at'       => $r->updated_at,
            'hub_signaled_ready' => !empty($r->signal_id),
            'signaled_at'        => $r->signaled_at,
        ];
    }

    return knx_rest_response(true, 'OK', [
        'orders' => $orders,
        'meta'   => [
            'hub_id' => $hub_id,
            'count'  => count($orders),
        ],
    ]);
}

/**
 * POST /hub-management/orders/signal
 * Hub manager presses "Ready for Pickup" — writes a signal row.
 * Does NOT change order.status.
 *
 * Body: { order_id: int }
 */
function knx_hub_orders_signal_handler(WP_REST_Request $request) {
    global $wpdb;

    // Ownership guard
    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $order_id = (int) $request->get_param('order_id');
    if ($order_id <= 0) {
        return knx_rest_error('order_id is required', 400);
    }

    // Verify order belongs to this hub
    $orders_table = $wpdb->prefix . 'knx_orders';
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, hub_id, status, payment_status FROM {$orders_table} WHERE id = %d LIMIT 1",
        $order_id
    ));

    if (!$order || (int) $order->hub_id !== $hub_id) {
        return knx_rest_error('Order not found or not owned by this hub', 404);
    }

    if ($order->payment_status !== 'paid') {
        return knx_rest_error('Order is not paid', 400);
    }

    // Only allow signal on active statuses
    $active_statuses = ['confirmed', 'accepted_by_driver', 'accepted_by_hub', 'preparing', 'prepared'];
    if (!in_array($order->status, $active_statuses, true)) {
        return knx_rest_error('Order is not in an active status', 400);
    }

    $signals_table = knx_hub_ensure_signals_table();
    $user_id = (int) $session->user_id;

    // Check if already signaled
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$signals_table} WHERE order_id = %d AND signal_type = 'ready_for_pickup' LIMIT 1",
        $order_id
    ));

    if ($exists) {
        return knx_rest_response(true, 'Already signaled', [
            'order_id'    => $order_id,
            'already_sent' => true,
        ]);
    }

    $wpdb->insert($signals_table, [
        'order_id'    => $order_id,
        'hub_id'      => $hub_id,
        'signal_type' => 'ready_for_pickup',
        'signaled_by' => $user_id,
    ], ['%d', '%d', '%s', '%d']);

    if ($wpdb->insert_id) {
        return knx_rest_response(true, 'Signal sent', [
            'order_id'    => $order_id,
            'signal_type' => 'ready_for_pickup',
        ]);
    }

    return knx_rest_error('Failed to send signal', 500);
}

/**
 * GET /hub-management/orders/signals?order_ids=1,2,3
 * Returns which order IDs have a "ready_for_pickup" signal.
 * Used by driver-active-orders to show the warning chip.
 * Accessible by: driver, super_admin, manager
 */
function knx_hub_orders_check_signals_handler(WP_REST_Request $request) {
    global $wpdb;

    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $allowed_roles = ['driver', 'super_admin', 'manager', 'hub_management'];
    $role = $session->role ?? '';
    if (!in_array($role, $allowed_roles, true)) {
        return knx_rest_error('Forbidden', 403);
    }

    $raw = $request->get_param('order_ids');
    if (!$raw) {
        return knx_rest_response(true, 'OK', ['signals' => []]);
    }

    $ids = array_map('intval', explode(',', $raw));
    $ids = array_filter($ids, function($id) { return $id > 0; });
    if (empty($ids)) {
        return knx_rest_response(true, 'OK', ['signals' => []]);
    }

    // Cap at 100 IDs
    $ids = array_slice($ids, 0, 100);

    $signals_table = knx_hub_ensure_signals_table();
    $ph = implode(',', array_fill(0, count($ids), '%d'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT order_id, signal_type, created_at
         FROM {$signals_table}
         WHERE order_id IN ({$ph})
           AND signal_type = 'ready_for_pickup'",
        ...$ids
    ));

    $signals = [];
    foreach ($rows as $r) {
        $signals[(int) $r->order_id] = [
            'signal_type' => $r->signal_type,
            'signaled_at' => $r->created_at,
        ];
    }

    return knx_rest_response(true, 'OK', ['signals' => $signals]);
}
