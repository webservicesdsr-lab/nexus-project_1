<?php
// File: inc/core/resources/knx-ops/api-assign-driver.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Assign Driver (OPS v1) [CANON]
 * Endpoint: POST /wp-json/knx/v1/ops/assign-driver
 *
 * Contract (JSON body or params):
 * - order_id  (int, required)
 * - driver_id (int, required)  // knx_drivers.id
 *
 * Canon:
 * - Writes assignment to {prefix}knx_driver_ops (SSOT operational assignment)
 *   - driver_user_id (WP user id for the driver)
 *   - assigned_by (actor user id)
 *   - ops_status = 'assigned'
 *   - assigned_at = NOW()
 *
 * Rules:
 * - Fail-closed: requires session + role (super_admin|manager)
 * - Manager is city-scoped via {prefix}knx_manager_cities (fail-closed)
 * - Order must be operationally-active (not completed/cancelled)
 * - Driver must exist; must be active if schema supports it
 * - Driver must resolve to a driver_user_id (fail-closed)
 * - Optional driver-city mapping:
 *   - If {prefix}knx_driver_cities exists: driver must serve order city for managers
 *   - If missing: managers are blocked (403), super_admin allowed
 *
 * Notes:
 * - Best-effort: also updates orders.driver_id if that column exists (legacy compatibility)
 * - Audit is best-effort and non-blocking
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
if (!function_exists('knx_ops_assign_driver_read_json_body')) {
    function knx_ops_assign_driver_read_json_body(WP_REST_Request $request) {
        $raw = (string)$request->get_body();
        if ($raw === '') return [];
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
}

/**
 * Best-effort audit insert. Non-blocking.
 * Tries common audit tables and inserts only columns that exist.
 *
 * @param int    $order_id
 * @param string $event_type
 * @param string $message
 * @param array  $meta
 * @param object $session
 * @return void
 */
if (!function_exists('knx_ops_assign_driver_audit')) {
    function knx_ops_assign_driver_audit($order_id, $event_type, $message, array $meta, $session) {
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

        $now = current_time('mysql');
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

        if (count($data) < 2) return;

        try {
            $wpdb->insert($table, $data, $fmt);
        } catch (\Throwable $e) {
            // Non-blocking by design
        }
    }
}

/**
 * Detect if a column exists on a table.
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
 * Resolve driver_user_id from knx_drivers row by checking common column names.
 *
 * @param object $driver_row
 * @param array<int,string> $driver_cols_lc
 * @return int
 */
if (!function_exists('knx_ops__resolve_driver_user_id')) {
    function knx_ops__resolve_driver_user_id($driver_row, array $driver_cols_lc) {
        $candidates = ['user_id', 'wp_user_id', 'driver_user_id'];
        foreach ($candidates as $c) {
            if (in_array($c, $driver_cols_lc, true) && isset($driver_row->{$c})) {
                $v = (int)$driver_row->{$c};
                if ($v > 0) return $v;
            }
        }
        return 0;
    }
}

function knx_ops_assign_driver(WP_REST_Request $request) {
    global $wpdb;

    // Require session + allowed roles (fail-closed)
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

    $body = knx_ops_assign_driver_read_json_body($request);

    $order_id  = (int)($request->get_param('order_id') ?? ($body['order_id'] ?? 0));
    $driver_id = (int)($request->get_param('driver_id') ?? ($body['driver_id'] ?? 0));

    if ($order_id <= 0) return knx_rest_error('order_id is required', 400);
    if ($driver_id <= 0) return knx_rest_error('driver_id is required', 400);

    $orders_table      = $wpdb->prefix . 'knx_orders';
    $drivers_table     = $wpdb->prefix . 'knx_drivers';
    $driver_ops_table  = $wpdb->prefix . 'knx_driver_ops';
    $driver_cities_tbl = $wpdb->prefix . 'knx_driver_cities';

    // Orders table must exist (fail-closed)
    $orders_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
    if (empty($orders_exists)) return knx_rest_error('Orders not configured', 409);

    // Driver ops table must exist (fail-closed)
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

    // Only operationally-active orders (no completed/cancelled)
    if (in_array($order_status, ['completed', 'cancelled'], true)) {
        return knx_rest_error('Order is in a terminal status', 409);
    }

    // OPS v1 assignable statuses
    $allowed_statuses = ['placed', 'confirmed', 'preparing', 'assigned', 'in_progress'];
    if (!in_array($order_status, $allowed_statuses, true)) {
        return knx_rest_error('Order is not in an assignable status', 409);
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

    // Drivers table must exist (fail-closed)
    $drivers_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drivers_table));
    if (empty($drivers_exists)) return knx_rest_error('Drivers not configured', 409);

    // Load driver row
    $driver = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$drivers_table} WHERE id = %d LIMIT 1",
        $driver_id
    ));
    if (!$driver) return knx_rest_error('Driver not found', 404);

    // Detect optional "active" columns
    $driver_cols = $wpdb->get_col("SHOW COLUMNS FROM {$drivers_table}");
    $driver_cols = is_array($driver_cols) ? array_map('strtolower', $driver_cols) : [];

    $is_active_ok = true;
    if (in_array('is_active', $driver_cols, true) && isset($driver->is_active)) {
        $is_active_ok = ((int)$driver->is_active === 1);
    } elseif (in_array('active', $driver_cols, true) && isset($driver->active)) {
        $is_active_ok = ((int)$driver->active === 1);
    } elseif (in_array('status', $driver_cols, true) && isset($driver->status)) {
        $st = strtolower((string)$driver->status);
        $is_active_ok = in_array($st, ['active', 'enabled', 'on', '1'], true);
    }
    if (!$is_active_ok) return knx_rest_error('Driver is not active', 409);

    // Resolve driver_user_id (required for knx_driver_ops canonical assignment)
    $driver_user_id = knx_ops__resolve_driver_user_id($driver, $driver_cols);
    if ($driver_user_id <= 0) {
        // Fail-closed: cannot perform canonical assignment without driver_user_id
        return knx_rest_error('Driver is missing user linkage (driver_user_id)', 409);
    }

    // Optional driver-city mapping enforcement
    $driver_cities_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $driver_cities_tbl));
    if (!empty($driver_cities_exists)) {
        $mapped_city_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT city_id FROM {$driver_cities_tbl} WHERE driver_id = %d",
            $driver_id
        ));
        $mapped_city_ids = array_map('intval', (array)$mapped_city_ids);
        $mapped_city_ids = array_values(array_filter($mapped_city_ids, static function ($v) { return $v > 0; }));

        if (!empty($mapped_city_ids)) {
            if ($order_city_id <= 0 || !in_array($order_city_id, $mapped_city_ids, true)) {
                if ($role === 'manager') return knx_rest_error('Forbidden: driver does not serve order city', 403);
            }
        } else {
            if ($role === 'manager') return knx_rest_error('Forbidden: driver not assigned to any city', 403);
        }
    } else {
        if ($role === 'manager') return knx_rest_error('Forbidden: cannot verify driver-city relationship', 403);
    }

    // Read current ops row (if any)
    $ops_row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, order_id, driver_user_id, ops_status
         FROM {$driver_ops_table}
         WHERE order_id = %d
         LIMIT 1",
        $order_id
    ));

    $current_driver_user_id = $ops_row && isset($ops_row->driver_user_id) ? (int)$ops_row->driver_user_id : 0;
    $current_ops_status = $ops_row && isset($ops_row->ops_status) ? strtolower(trim((string)$ops_row->ops_status)) : 'unassigned';

    // Idempotent: already assigned to same driver_user_id and status assigned
    if ($current_driver_user_id === $driver_user_id && $current_driver_user_id > 0 && $current_ops_status === 'assigned') {
        return knx_rest_response(true, 'Driver already assigned', [
            'updated' => false,
            'assigned' => true,
            'order_id' => $order_id,
            'driver_id' => $driver_id,
            'driver_user_id' => $driver_user_id,
        ], 200);
    }

    // Write to knx_driver_ops (SSOT)
    $now = current_time('mysql');

    if ($ops_row && isset($ops_row->id)) {
        $ok = $wpdb->update(
            $driver_ops_table,
            [
                'driver_user_id' => $driver_user_id,
                'assigned_by'    => $user_id ?: null,
                'ops_status'     => 'assigned',
                'assigned_at'    => $now,
                // updated_at is auto-updated by schema
            ],
            ['order_id' => $order_id],
            ['%d', '%d', '%s', '%s'],
            ['%d']
        );
        if ($ok === false) return knx_rest_error('Failed to assign driver (ops)', 500);
    } else {
        $ok = $wpdb->insert(
            $driver_ops_table,
            [
                'order_id'       => $order_id,
                'driver_user_id' => $driver_user_id,
                'assigned_by'    => $user_id ?: null,
                'ops_status'     => 'assigned',
                'assigned_at'    => $now,
                // updated_at default/auto
            ],
            ['%d', '%d', '%d', '%s', '%s']
        );
        if (!$ok) return knx_rest_error('Failed to assign driver (ops insert)', 500);
    }

    // Best-effort: keep legacy orders.driver_id in sync if column exists
    if (knx_ops__col_exists($orders_table, 'driver_id')) {
        $updated_at_exists = knx_ops__col_exists($orders_table, 'updated_at');

        $data = ['driver_id' => $driver_id];
        $data_fmt = ['%d'];
        if ($updated_at_exists) {
            $data['updated_at'] = $now;
            $data_fmt[] = '%s';
        }

        try {
            $wpdb->update($orders_table, $data, ['id' => $order_id], $data_fmt, ['%d']);
        } catch (\Throwable $e) {
            // Non-blocking legacy sync
        }
    }

    // Best-effort audit
    knx_ops_assign_driver_audit($order_id, 'assign_driver', 'Driver assigned', [
        'order_id' => $order_id,
        'city_id' => $order_city_id,
        'order_status' => $order_status,
        'driver_id' => $driver_id,
        'driver_user_id' => $driver_user_id,
        'from_driver_user_id' => $current_driver_user_id,
        'from_ops_status' => $current_ops_status,
        'to_ops_status' => 'assigned',
    ], $session);

    return knx_rest_response(true, 'Driver assigned', [
        'updated' => true,
        'assigned' => true,
        'order_id' => $order_id,
        'driver_id' => $driver_id,
        'driver_user_id' => $driver_user_id,
    ], 200);
}
