<?php
// File: inc/core/resources/knx-ops/api-unassign-driver.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Unassign Driver (OPS v1) [CANON]
 * Endpoint: POST /wp-json/knx/v1/ops/unassign-driver
 *
 * Contract (JSON body or params):
 * - order_id (int, required)
 *
 * Canon:
 * - Writes unassignment to {prefix}knx_driver_ops (SSOT operational assignment)
 *   - driver_user_id = NULL
 *   - ops_status = 'unassigned'
 *   - assigned_at = NULL (best-effort)
 *
 * Rules:
 * - Fail-closed: requires session + role (super_admin|manager)
 * - Manager is city-scoped via {prefix}knx_manager_cities (fail-closed)
 * - Allowed only when order.status is: assigned, in_progress
 * - Idempotent: if already unassigned -> 200 updated:false
 * - Best-effort audit (non-blocking)
 *
 * Notes:
 * - Best-effort: also NULLs orders.driver_id if column exists (legacy compatibility)
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

/**
 * Resolve manager allowed city IDs based on {prefix}knx_manager_cities.
 * Defined defensively (only if not already defined elsewhere).
 *
 * @param int $manager_user_id
 * @return array<int>
 */
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

/**
 * Parse JSON body defensively.
 *
 * @param WP_REST_Request $request
 * @return array
 */
if (!function_exists('knx_ops_unassign_driver_read_json_body')) {
    function knx_ops_unassign_driver_read_json_body(WP_REST_Request $request) {
        $raw = (string)$request->get_body();
        if ($raw === '') return [];
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
}

/**
 * Column exists helper (shared with assign if loaded elsewhere).
 *
 * @param string $table
 * @param string $col
 * @return bool
 */
if (!function_exists('knx_ops__col_exists')) {
    function knx_ops__col_exists($table, $col) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        return !empty($found);
    }
}

/**
 * Best-effort audit helper:
 * - Prefer knx_ops_assign_driver_audit if available to keep logs consistent.
 * - Otherwise do nothing (non-blocking).
 *
 * @param int    $order_id
 * @param string $event_type
 * @param string $message
 * @param array  $meta
 * @param object $session
 * @return void
 */
if (!function_exists('knx_ops_unassign_driver_audit')) {
    function knx_ops_unassign_driver_audit($order_id, $event_type, $message, array $meta, $session) {
        if (function_exists('knx_ops_assign_driver_audit')) {
            try {
                knx_ops_assign_driver_audit((int)$order_id, (string)$event_type, (string)$message, $meta, $session);
            } catch (\Throwable $e) {
                // Non-blocking
            }
        }
    }
}

function knx_ops_unassign_driver(WP_REST_Request $request) {
    global $wpdb;

    // Require session + role (fail-closed)
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role    = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    // Optional nonce enforcement (centralized)
    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    $body = knx_ops_unassign_driver_read_json_body($request);
    $order_id = (int)($request->get_param('order_id') ?? ($body['order_id'] ?? 0));
    if ($order_id <= 0) return knx_rest_error('order_id is required', 400);

    $orders_table     = $wpdb->prefix . 'knx_orders';
    $driver_ops_table = $wpdb->prefix . 'knx_driver_ops';

    // Tables must exist (fail-closed)
    $orders_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
    if (empty($orders_exists)) return knx_rest_error('Orders not configured', 409);

    $ops_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $driver_ops_table));
    if (empty($ops_exists)) return knx_rest_error('Driver ops not configured', 409);

    // Fetch order (fail-closed)
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, city_id, status, driver_id
         FROM {$orders_table}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));
    if (!$order) return knx_rest_error('Order not found', 404);

    $order_city_id = (int)$order->city_id;
    $order_status  = strtolower(trim((string)$order->status));

    // Only allow unassign from allowed statuses (OPS v1)
    if (!in_array($order_status, ['assigned', 'in_progress'], true)) {
        return knx_rest_error('Order status does not allow unassign', 409);
    }

    // Manager scope (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed_cities = knx_ops_manager_city_ids($user_id);
        if (empty($allowed_cities)) return knx_rest_error('Manager city assignment not configured', 403);

        if ($order_city_id <= 0 || !in_array($order_city_id, $allowed_cities, true)) {
            return knx_rest_error('Forbidden: order city outside manager scope', 403);
        }
    }

    // Read ops row (if none, treat as already unassigned)
    $ops_row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, order_id, driver_user_id, ops_status
         FROM {$driver_ops_table}
         WHERE order_id = %d
         LIMIT 1",
        $order_id
    ));

    $current_driver_user_id = $ops_row && isset($ops_row->driver_user_id) ? (int)$ops_row->driver_user_id : 0;
    $current_ops_status = $ops_row && isset($ops_row->ops_status) ? strtolower(trim((string)$ops_row->ops_status)) : 'unassigned';

    // Idempotent: already unassigned (either no row or null driver_user_id or ops_status unassigned)
    $already_unassigned = (!$ops_row) || ($current_driver_user_id <= 0) || ($current_ops_status === 'unassigned');
    if ($already_unassigned) {
        knx_ops_unassign_driver_audit($order_id, 'unassign_driver_noop', 'No driver to unassign', [
            'order_id' => $order_id,
            'city_id'  => $order_city_id,
            'order_status' => $order_status,
            'ops_status' => $current_ops_status,
        ], $session);

        return knx_rest_response(true, 'No change', [
            'updated' => false,
            'unassigned' => true,
            'order_id' => $order_id,
        ], 200);
    }

    // Update ops row to unassigned (SSOT)
    $ok = $wpdb->update(
        $driver_ops_table,
        [
            'driver_user_id' => null,
            'ops_status'     => 'unassigned',
            'assigned_at'    => null,
            // updated_at auto-updated by schema
        ],
        ['order_id' => $order_id],
        ['%s', '%s', '%s'], // wpdb accepts nulls; format strings are ignored for NULL
        ['%d']
    );

    if ($ok === false) return knx_rest_error('Failed to unassign driver (ops)', 500);

    // Best-effort: keep legacy orders.driver_id in sync if column exists
    if (knx_ops__col_exists($orders_table, 'driver_id')) {
        $now = current_time('mysql');
        $updated_at_exists = knx_ops__col_exists($orders_table, 'updated_at');

        try {
            if ($updated_at_exists) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$orders_table}
                     SET driver_id = NULL, updated_at = %s
                     WHERE id = %d",
                    $now,
                    $order_id
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$orders_table}
                     SET driver_id = NULL
                     WHERE id = %d",
                    $order_id
                ));
            }
        } catch (\Throwable $e) {
            // Non-blocking legacy sync
        }
    }

    // Best-effort audit
    knx_ops_unassign_driver_audit($order_id, 'unassign_driver', 'Driver unassigned', [
        'order_id' => $order_id,
        'city_id'  => $order_city_id,
        'order_status' => $order_status,
        'from_driver_user_id' => $current_driver_user_id,
        'from_ops_status' => $current_ops_status,
        'to_driver_user_id' => null,
        'to_ops_status' => 'unassigned',
    ], $session);

    return knx_rest_response(true, 'Driver unassigned', [
        'updated' => true,
        'unassigned' => true,
        'order_id' => $order_id,
    ], 200);
}
