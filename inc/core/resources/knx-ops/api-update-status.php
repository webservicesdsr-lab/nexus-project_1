<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Update Status (OPS v1)
 * Endpoint: POST /wp-json/knx/v1/ops/update-status
 *
 * Minimal controlled transitions for OPS v1 (fail-closed).
 * - Roles: super_admin, manager
 * - Manager is city-scoped (fail-closed)
 * - Idempotent if current == to_status
 * - Audit trail: best-effort, non-blocking
 *
 * Allowed transitions:
 * - placed -> confirmed
 * - confirmed -> preparing
 * - preparing -> assigned
 * - assigned -> in_progress
 * - in_progress -> completed
 * - any non-terminal -> cancelled (requires reason)
 * - terminal (completed/cancelled) cannot transition (except idempotent)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/update-status', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_ops_update_status')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Defensive manager city resolver (if not already defined by live-orders resource).
 *
 * @param int $manager_user_id
 * @return array<int>
 */
if (!function_exists('knx_ops_live_orders_manager_city_ids')) {
    function knx_ops_live_orders_manager_city_ids($manager_user_id) {
        global $wpdb;

        $hubs_table = $wpdb->prefix . 'knx_hubs';

        // Fail-closed if column doesn't exist
        $col = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM {$hubs_table} LIKE %s",
            'manager_user_id'
        ));
        if (empty($col)) return [];

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id
             FROM {$hubs_table}
             WHERE manager_user_id = %d
               AND city_id IS NOT NULL",
            (int)$manager_user_id
        ));

        $ids = array_map('intval', (array)$ids);
        $ids = array_values(array_filter($ids, static function ($v) {
            return $v > 0;
        }));

        return $ids;
    }
}

/**
 * Read JSON body defensively (in case Content-Type is off).
 *
 * @param WP_REST_Request $request
 * @return array
 */
function knx_ops_update_status_read_json_body(WP_REST_Request $request) {
    $raw = (string)$request->get_body();
    if ($raw === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

/**
 * Best-effort audit insert (non-blocking).
 *
 * @param int    $order_id
 * @param string $event_type
 * @param string $message
 * @param array  $meta
 * @param object $session
 * @return void
 */
function knx_ops_update_status_audit($order_id, $event_type, $message, array $meta, $session) {
    global $wpdb;

    $order_id = (int)$order_id;
    if ($order_id <= 0) return;

    $candidates = [
        $wpdb->prefix . 'knx_order_events',
        $wpdb->prefix . 'knx_orders_events',
        $wpdb->prefix . 'knx_order_audit',
        $wpdb->prefix . 'knx_orders_audit',
        $wpdb->prefix . 'knx_order_timeline',
    ];

    $table = null;
    foreach ($candidates as $t) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
        if (!empty($exists)) { $table = $t; break; }
    }
    if (!$table) return;

    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
    if (!is_array($cols) || empty($cols)) return;
    $cols = array_map('strtolower', $cols);

    $now  = current_time('mysql');
    $actor_role = isset($session->role) ? (string)$session->role : '';
    $actor_user = isset($session->user_id) ? (int)$session->user_id : 0;

    $data = [];
    $fmt  = [];

    if (in_array('order_id', $cols, true)) { $data['order_id'] = $order_id; $fmt[] = '%d'; }

    if (in_array('event_type', $cols, true)) { $data['event_type'] = (string)$event_type; $fmt[] = '%s'; }
    elseif (in_array('type', $cols, true))   { $data['type'] = (string)$event_type; $fmt[] = '%s'; }

    if (in_array('message', $cols, true)) { $data['message'] = (string)$message; $fmt[] = '%s'; }
    elseif (in_array('note', $cols, true)) { $data['note'] = (string)$message; $fmt[] = '%s'; }

    $meta_json = wp_json_encode($meta);

    if (in_array('meta_json', $cols, true)) { $data['meta_json'] = $meta_json; $fmt[] = '%s'; }
    elseif (in_array('payload_json', $cols, true)) { $data['payload_json'] = $meta_json; $fmt[] = '%s'; }
    elseif (in_array('payload', $cols, true)) { $data['payload'] = $meta_json; $fmt[] = '%s'; }

    if (in_array('actor_role', $cols, true)) { $data['actor_role'] = $actor_role; $fmt[] = '%s'; }
    if (in_array('actor_user_id', $cols, true)) { $data['actor_user_id'] = $actor_user; $fmt[] = '%d'; }
    elseif (in_array('user_id', $cols, true)) { $data['user_id'] = $actor_user; $fmt[] = '%d'; }

    if (in_array('created_at', $cols, true)) { $data['created_at'] = $now; $fmt[] = '%s'; }
    elseif (in_array('created', $cols, true)) { $data['created'] = $now; $fmt[] = '%s'; }

    // If we barely have anything meaningful, skip.
    if (count($data) < 2) return;

    try {
        $wpdb->insert($table, $data, $fmt);
    } catch (\Throwable $e) {
        // Non-blocking by design
    }
}

function knx_ops_update_status(WP_REST_Request $request) {
    global $wpdb;

    // Require session + role (fail-closed)
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    // Optional nonce enforcement (centralized)
    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    // Params (support JSON body or form params)
    $body = knx_ops_update_status_read_json_body($request);

    $order_id = (int)($request->get_param('order_id') ?? ($body['order_id'] ?? 0));

    $to_status_raw = $request->get_param('to_status');
    if ($to_status_raw === null) $to_status_raw = ($body['to_status'] ?? '');
    $to_status = strtolower(trim((string)$to_status_raw));

    $reason_raw = $request->get_param('reason');
    if ($reason_raw === null) $reason_raw = ($body['reason'] ?? '');
    $reason = trim((string)$reason_raw);

    if ($order_id <= 0) return knx_rest_error('order_id is required', 400);
    if ($to_status === '') return knx_rest_error('to_status is required', 400);

    $allowed_to = ['confirmed', 'preparing', 'assigned', 'in_progress', 'completed', 'cancelled'];
    if (!in_array($to_status, $allowed_to, true)) {
        return knx_rest_error('Invalid to_status', 400);
    }

    if ($to_status === 'cancelled') {
        if (strlen($reason) < 3) return knx_rest_error('reason is required for cancellation (min 3 chars)', 400);
        if (strlen($reason) > 250) $reason = substr($reason, 0, 250);
    }

    $orders_table = $wpdb->prefix . 'knx_orders';

    // Fetch order (fail-closed)
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, city_id, status, driver_id
         FROM {$orders_table}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));
    if (!$order) return knx_rest_error('Order not found', 404);

    $current_status = strtolower(trim((string)$order->status));
    $order_city_id  = (int)$order->city_id;

    // Idempotent
    if ($current_status === $to_status) {
        knx_ops_update_status_audit($order_id, 'status_noop', 'No status change', [
            'order_id' => $order_id,
            'city_id' => $order_city_id,
            'from_status' => $current_status,
            'to_status' => $to_status,
        ], $session);

        return knx_rest_response(true, 'No change', [
            'updated' => false,
            'from_status' => $current_status,
            'to_status' => $to_status,
        ], 200);
    }

    // Terminal states cannot transition (fail-closed)
    if (in_array($current_status, ['completed', 'cancelled'], true)) {
        return knx_rest_error('Order is in a terminal status', 409);
    }

    // Manager city-scope enforcement (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed_cities = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed_cities)) return knx_rest_error('Manager city assignment not configured', 403);

        if ($order_city_id <= 0 || !in_array($order_city_id, $allowed_cities, true)) {
            return knx_rest_error('Forbidden: order city outside manager scope', 403);
        }
    }

    // Transition guard (OPS v1)
    // Cancellation: allowed from ANY non-terminal status (with reason)
    if ($to_status !== 'cancelled') {
        $transitions = [
            'placed'      => ['confirmed'],
            'confirmed'   => ['preparing'],
            'preparing'   => ['assigned'],
            'assigned'    => ['in_progress'],
            'in_progress' => ['completed'],
        ];

        if (!isset($transitions[$current_status])) {
            return knx_rest_error('Invalid current status for OPS transition', 409);
        }

        if (!in_array($to_status, $transitions[$current_status], true)) {
            return knx_rest_error(sprintf('Invalid transition from %s to %s', $current_status, $to_status), 409);
        }
    }

    // Prepare update
    $data = ['status' => $to_status];
    $data_fmt = ['%s'];

    $updated_at_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$orders_table} LIKE %s",
        'updated_at'
    ));
    if (!empty($updated_at_exists)) {
        $data['updated_at'] = current_time('mysql');
        $data_fmt[] = '%s';
    }

    // Best-effort: persist cancel reason if a known column exists
    $cancel_col = null;
    if ($to_status === 'cancelled') {
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$orders_table}");
        $cols = is_array($cols) ? array_map('strtolower', $cols) : [];
        foreach (['cancel_reason', 'cancellation_reason', 'cancelled_reason'] as $cname) {
            if (in_array($cname, $cols, true)) { $cancel_col = $cname; break; }
        }
        if ($cancel_col) {
            $data[$cancel_col] = $reason;
            $data_fmt[] = '%s';
        }
    }

    $ok = $wpdb->update(
        $orders_table,
        $data,
        ['id' => $order_id],
        $data_fmt,
        ['%d']
    );
    if ($ok === false) return knx_rest_error('Failed to update order status', 500);

    // Audit (best-effort)
    $meta = [
        'order_id' => $order_id,
        'city_id' => $order_city_id,
        'from_status' => $current_status,
        'to_status' => $to_status,
        'reason' => ($to_status === 'cancelled') ? $reason : null,
        'driver_id' => isset($order->driver_id) ? (int)$order->driver_id : null,
    ];
    knx_ops_update_status_audit($order_id, 'update_status', 'Order status updated', $meta, $session);

    return knx_rest_response(true, 'Status updated', [
        'updated' => true,
        'from_status' => $current_status,
        'to_status' => $to_status,
    ], 200);
}
