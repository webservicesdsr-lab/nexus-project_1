<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Assign Driver (OPS v1) — DB-CANON
 * Endpoint: POST /wp-json/knx/v1/ops/assign-driver
 *
 * Input JSON:
 * - order_id (int)
 * - driver_id (int)  // This is knx_drivers.id (as returned by /ops/drivers)
 *
 * Behavior:
 * - Fail-closed: requires KNX session + role (super_admin|manager)
 * - Manager is city-scoped via {prefix}knx_manager_cities (fail-closed)
 * - Requires order.payment_status='paid'
 * - Requires order.status in live pipeline (confirmed..picked_up)
 * - Writes:
 *   - orders.driver_id = driver_id (knx_drivers.id)
 *   - upsert {prefix}knx_driver_ops row:
 *       order_id, driver_user_id (from drivers row), assigned_by, ops_status='assigned', assigned_at
 *
 * Notes:
 * - If driver-city mapping table exists, enforce it.
 * - If mapping table is missing: managers are blocked; super_admin allowed.
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/assign-driver', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_ops_assign_driver')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Resolve manager allowed city IDs based on knx_manager_cities pivot.
 * Fail-closed if missing or empty.
 *
 * @param int $manager_user_id
 * @return array<int>
 */
function knx_ops_assign_driver_manager_city_ids($manager_user_id) {
    global $wpdb;

    $manager_user_id = (int)$manager_user_id;
    if ($manager_user_id <= 0) return [];

    $pivot = $wpdb->prefix . 'knx_manager_cities';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pivot));
    if (empty($exists)) return [];

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id
         FROM {$pivot}
         WHERE manager_user_id = %d
           AND city_id IS NOT NULL",
        $manager_user_id
    ));

    $ids = array_map('intval', (array)$ids);
    $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));
    return $ids;
}

/**
 * Read JSON body helper (fail-safe).
 *
 * @param WP_REST_Request $request
 * @return array
 */
function knx_ops_assign_driver_json_body(WP_REST_Request $request) {
    $body = $request->get_json_params();
    return (is_array($body) ? $body : []);
}

/**
 * Find driver_user_id from drivers row.
 *
 * Priority:
 * - driver_user_id
 * - user_id
 * - id (fallback)
 *
 * @param object $driver_row
 * @return int
 */
function knx_ops_assign_driver_resolve_driver_user_id($driver_row) {
    if (!$driver_row) return 0;

    if (isset($driver_row->driver_user_id) && (int)$driver_row->driver_user_id > 0) {
        return (int)$driver_row->driver_user_id;
    }
    if (isset($driver_row->user_id) && (int)$driver_row->user_id > 0) {
        return (int)$driver_row->user_id;
    }
    if (isset($driver_row->id) && (int)$driver_row->id > 0) {
        return (int)$driver_row->id;
    }

    return 0;
}

function knx_ops_assign_driver(WP_REST_Request $request) {
    global $wpdb;

    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;
    if ($user_id <= 0) return knx_rest_error('Unauthorized', 401);

    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    $body = knx_ops_assign_driver_json_body($request);

    $order_id = isset($body['order_id']) ? (int)$body['order_id'] : (int)$request->get_param('order_id');
    $driver_id = isset($body['driver_id']) ? (int)$body['driver_id'] : (int)$request->get_param('driver_id');

    if ($order_id <= 0) return knx_rest_error('order_id is required', 400);
    if ($driver_id <= 0) return knx_rest_error('driver_id is required', 400);

    $orders_table  = $wpdb->prefix . 'knx_orders';
    $drivers_table = $wpdb->prefix . 'knx_drivers';
    $ops_table     = $wpdb->prefix . 'knx_driver_ops';

    // Fetch order (fail-closed)
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, city_id, status, payment_status, driver_id
         FROM {$orders_table}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));

    if (!$order) return knx_rest_error('Order not found', 404);

    // Enforce manager scope
    if ($role === 'manager') {
        $allowed = knx_ops_assign_driver_manager_city_ids($user_id);
        if (empty($allowed)) return knx_rest_error('Manager city assignment not configured', 403);

        $city_id = (int)($order->city_id ?? 0);
        if ($city_id <= 0 || !in_array($city_id, $allowed, true)) {
            return knx_rest_error('Forbidden: city outside manager scope', 403);
        }
    }

    // Payment must be paid
    $ps = strtolower((string)($order->payment_status ?? ''));
    if ($ps !== 'paid') {
        return knx_rest_error('Order is not paid', 409);
    }

    // Order must be in live pipeline
    $live = ['confirmed','accepted_by_driver','accepted_by_hub','preparing','prepared','picked_up'];
    $st = (string)($order->status ?? '');
    if (!in_array($st, $live, true)) {
        return knx_rest_error('Order not eligible for driver assignment', 409);
    }

    // Driver row must exist and be active (when column exists)
    $driver = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$drivers_table} WHERE id = %d LIMIT 1",
        $driver_id
    ));
    if (!$driver) return knx_rest_error('Driver not found', 404);

    // Active enforcement (supports status col)
    if (isset($driver->status) && strtolower((string)$driver->status) !== 'active') {
        return knx_rest_error('Driver inactive', 409);
    }

    $driver_user_id = knx_ops_assign_driver_resolve_driver_user_id($driver);
    if ($driver_user_id <= 0) return knx_rest_error('Driver linkage invalid', 409);

    // Enforce driver-city mapping if table exists
    $driver_cities_table = $wpdb->prefix . 'knx_driver_cities';
    $mapping_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $driver_cities_table));

    if (!empty($mapping_exists)) {
        $city_id = (int)($order->city_id ?? 0);
        if ($city_id <= 0) return knx_rest_error('Order city missing', 409);

        $ok_map = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$driver_cities_table}
             WHERE driver_id = %d AND city_id = %d
             LIMIT 1",
            $driver_id,
            $city_id
        ));

        if (empty($ok_map)) {
            return knx_rest_error('Driver not allowed for this city', 403);
        }
    } else {
        // Fail-closed for managers if mapping table is missing
        if ($role === 'manager') {
            return knx_rest_error('Forbidden: cannot validate driver-city mapping', 403);
        }
    }

    $now = current_time('mysql');

    $wpdb->query('START TRANSACTION');

    try {
        // Lock order row to prevent races
        $locked = $wpdb->get_row($wpdb->prepare(
            "SELECT id, driver_id, status, payment_status FROM {$orders_table} WHERE id = %d FOR UPDATE",
            $order_id
        ));
        if (!$locked) throw new Exception('ORDER_LOCK_FAILED');

        $ps_locked = strtolower((string)($locked->payment_status ?? ''));
        if ($ps_locked !== 'paid') throw new Exception('ORDER_NOT_PAID');

        $st_locked = (string)($locked->status ?? '');
        if (!in_array($st_locked, $live, true)) throw new Exception('ORDER_NOT_ELIGIBLE');

        // Update orders.driver_id = drivers.id
        $u1 = $wpdb->update(
            $orders_table,
            [
                'driver_id'   => $driver_id,
                'updated_at'  => $now,
            ],
            ['id' => $order_id],
            ['%d','%s'],
            ['%d']
        );
        if ($u1 === false) throw new Exception('ORDER_UPDATE_FAILED');

        // Upsert driver_ops (unique by order_id)
        $existing_ops_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$ops_table} WHERE order_id = %d LIMIT 1",
            $order_id
        ));

        if (!empty($existing_ops_id)) {
            $u2 = $wpdb->update(
                $ops_table,
                [
                    'driver_user_id' => $driver_user_id,
                    'assigned_by'    => $user_id,
                    'ops_status'     => 'assigned',
                    'assigned_at'    => $now,
                    'updated_at'     => $now,
                ],
                ['order_id' => $order_id],
                ['%d','%d','%s','%s','%s'],
                ['%d']
            );
            if ($u2 === false) throw new Exception('OPS_UPDATE_FAILED');
        } else {
            $i2 = $wpdb->insert(
                $ops_table,
                [
                    'order_id'       => $order_id,
                    'driver_user_id' => $driver_user_id,
                    'assigned_by'    => $user_id,
                    'ops_status'     => 'assigned',
                    'assigned_at'    => $now,
                    'updated_at'     => $now,
                ],
                ['%d','%d','%d','%s','%s','%s']
            );
            if ($i2 === false) throw new Exception('OPS_INSERT_FAILED');
        }

        $wpdb->query('COMMIT');

    } catch (\Throwable $e) {
        $wpdb->query('ROLLBACK');
        return knx_rest_error('Assign failed', 500);
    }

    return knx_rest_response(true, 'Driver assigned', [
        'order_id' => $order_id,
        'driver_id' => $driver_id,              // knx_drivers.id
        'driver_user_id' => $driver_user_id,    // knx_users.id typically
        'ops_status' => 'assigned',
        'assigned_at' => $now,
    ], 200);
}