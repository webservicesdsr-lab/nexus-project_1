<?php
// File: inc/core/resources/knx-ops/api-unassign-driver.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Unassign Driver (OPS v1) — DB-CANON v1.2
 * Endpoint: POST /wp-json/knx/v1/ops/unassign-driver
 *
 * Input JSON:
 * - order_id (int, required)
 *
 * Canon:
 * - SSOT for operational assignment is {prefix}knx_driver_ops
 *   - driver_user_id = NULL
 *   - ops_status = 'unassigned'
 *   - assigned_at = NULL
 *
 * Rules:
 * - Fail-closed: requires session + role (super_admin|manager)
 * - Manager is city-scoped via {prefix}knx_manager_cities (fail-closed)
 * - Requires payment_status='paid'
 * - Allowed for non-terminal orders (excluding pending_payment).
 *
 * CANON STATUS RULE:
 * - If current status is 'accepted_by_driver', releasing driver returns the order to 'confirmed'
 *   (Waiting for driver) and inserts a real status_history row for 'confirmed'.
 * - If status is later (accepted_by_hub/preparing/prepared/picked_up), we do NOT downgrade status
 *   (driver can be swapped without destroying pipeline).
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/unassign-driver', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_ops_unassign_driver')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

if (!function_exists('knx_ops_manager_city_ids')) {
    function knx_ops_manager_city_ids($manager_user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'knx_manager_cities';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (empty($exists)) return [];

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id
             FROM {$table}
             WHERE manager_user_id = %d
               AND city_id IS NOT NULL",
            (int)$manager_user_id
        ));

        $ids = array_map('intval', (array)$ids);
        $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));
        return $ids;
    }
}

function knx_ops_unassign_driver_json_body(WP_REST_Request $request) {
    $body = $request->get_json_params();
    return (is_array($body) ? $body : []);
}

function knx_ops_unassign_driver(WP_REST_Request $request) {
    global $wpdb;

    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role    = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;
    if ($user_id <= 0) return knx_rest_error('Unauthorized', 401);

    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    $body = knx_ops_unassign_driver_json_body($request);
    $order_id = isset($body['order_id']) ? (int)$body['order_id'] : (int)$request->get_param('order_id');
    if ($order_id <= 0) return knx_rest_error('order_id is required', 400);

    $orders_table     = $wpdb->prefix . 'knx_orders';
    $ops_table        = $wpdb->prefix . 'knx_driver_ops';
    $history_table    = $wpdb->prefix . 'knx_order_status_history';

    // Fail-closed: tables must exist
    foreach ([$orders_table, $ops_table, $history_table] as $t) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
        if (empty($exists)) return knx_rest_error('System not configured', 409);
    }

    // Fetch order preflight
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, city_id, status, payment_status, driver_id
         FROM {$orders_table}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));
    if (!$order) return knx_rest_error('Order not found', 404);

    // Manager scope
    if ($role === 'manager') {
        $allowed = knx_ops_manager_city_ids($user_id);
        if (empty($allowed)) return knx_rest_error('Manager city assignment not configured', 403);

        $city_id = (int)($order->city_id ?? 0);
        if ($city_id <= 0 || !in_array($city_id, $allowed, true)) {
            return knx_rest_error('Forbidden: order city outside manager scope', 403);
        }
    }

    // Payment must be paid
    $ps = strtolower((string)($order->payment_status ?? ''));
    if ($ps !== 'paid') {
        return knx_rest_error('Order is not paid', 409);
    }

    $status = strtolower((string)($order->status ?? ''));

    // Block pending_payment + terminal
    if ($status === 'pending_payment') {
        return knx_rest_error('Order is pending payment', 409);
    }
    if (in_array($status, ['completed','cancelled'], true)) {
        return knx_rest_error('Order is terminal', 409);
    }

    $now = current_time('mysql');

    $wpdb->query('START TRANSACTION');

    try {
        // Lock order
        $locked = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, payment_status, driver_id
             FROM {$orders_table}
             WHERE id = %d
             FOR UPDATE",
            $order_id
        ));
        if (!$locked) throw new Exception('ORDER_LOCK_FAILED');

        $ps_locked = strtolower((string)($locked->payment_status ?? ''));
        if ($ps_locked !== 'paid') throw new Exception('ORDER_NOT_PAID');

        $st_locked = strtolower((string)($locked->status ?? ''));
        if ($st_locked === 'pending_payment') throw new Exception('ORDER_PENDING_PAYMENT');
        if (in_array($st_locked, ['completed','cancelled'], true)) throw new Exception('ORDER_TERMINAL');

        // Upsert ops row to "unassigned"
        $existing_ops_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$ops_table} WHERE order_id = %d LIMIT 1",
            $order_id
        ));

        if (!empty($existing_ops_id)) {
            $ok_ops = $wpdb->update(
                $ops_table,
                [
                    'driver_user_id' => null,
                    'ops_status'     => 'unassigned',
                    'assigned_at'    => null,
                    'updated_at'     => $now,
                ],
                ['order_id' => $order_id],
                ['%s','%s','%s','%s'],
                ['%d']
            );
            if ($ok_ops === false) throw new Exception('OPS_UPDATE_FAILED');
        } else {
            $ok_ops = $wpdb->insert(
                $ops_table,
                [
                    'order_id'       => $order_id,
                    'driver_user_id' => null,
                    'assigned_by'    => $user_id,
                    'ops_status'     => 'unassigned',
                    'assigned_at'    => null,
                    'updated_at'     => $now,
                ],
                ['%d','%s','%d','%s','%s','%s']
            );
            if ($ok_ops === false) throw new Exception('OPS_INSERT_FAILED');
        }

        // Determine if we reset status back to confirmed
        $status_before = $st_locked;
        $status_after = $st_locked;
        $did_status_change = false;

        if ($st_locked === 'accepted_by_driver') {
            $status_after = 'confirmed';
            $did_status_change = true;
        }

        // Always clear orders.driver_id
        $update_data = [
            'driver_id'  => null,
            'updated_at' => $now,
        ];
        $update_fmt = ['%s','%s'];

        if ($did_status_change) {
            $update_data['status'] = $status_after;
            $update_fmt[] = '%s';
        }

        $ok_order = $wpdb->update(
            $orders_table,
            $update_data,
            ['id' => $order_id],
            $update_fmt,
            ['%d']
        );
        if ($ok_order === false) throw new Exception('ORDER_UPDATE_FAILED');

        // Insert history only when we actually changed status
        if ($did_status_change && $status_before !== $status_after) {
            $ok_hist = $wpdb->insert(
                $history_table,
                [
                    'order_id'   => $order_id,
                    'status'     => $status_after,
                    'changed_by' => $user_id,
                    'created_at' => $now,
                ],
                ['%d','%s','%d','%s']
            );
            if ($ok_hist === false) throw new Exception('HISTORY_INSERT_FAILED');
        }

        $wpdb->query('COMMIT');

        return knx_rest_response(true, 'Driver unassigned', [
            'order_id'          => $order_id,
            'unassigned'        => true,
            'ops_status'        => 'unassigned',
            'status_before'     => $status_before,
            'status_after'      => $status_after,
            'status_changed'    => (bool)$did_status_change,
            'updated_at'        => $now,
        ], 200);

    } catch (\Throwable $e) {
        $wpdb->query('ROLLBACK');
        return knx_rest_error('Unassign failed', 500);
    }
}