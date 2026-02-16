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
 * Domain transition (DB enum aligned):
 * - orders.status: confirmed -> accepted_by_driver (strict)
 *
 * Notes:
 * - Best-effort: updates orders.driver_id if that column exists (legacy compatibility)
 * - History insert uses created_at (NOT changed_at) when available
 * - Audit is best-effort and non-blocking
 * ==========================================================
 */

add_action('rest_api_init', function () {

    $permission_cb = function_exists('knx_rest_permission_roles')
        ? knx_rest_permission_roles(['super_admin', 'manager'])
        : '__return_false';

    register_rest_route('knx/v1', '/ops/assign-driver', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $request) {
            if (function_exists('knx_rest_wrap')) {
                return knx_rest_wrap('knx_ops_assign_driver')($request);
            }
            return knx_ops_assign_driver($request);
        },
        'permission_callback' => $permission_cb,
    ]);
});

/**
 * Check whether a DB table exists (safe for underscores/wildcards).
 *
 * @param string $table
 * @return bool
 */
if (!function_exists('knx_ops__table_exists')) {
    function knx_ops__table_exists($table) {
        global $wpdb;

        $like = $wpdb->esc_like($table);
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));

        return ((string)$exists === (string)$table);
    }
}

/**
 * Get table columns (lowercased) with static cache.
 *
 * @param string $table
 * @return array<int,string>
 */
if (!function_exists('knx_ops__table_columns_lc')) {
    function knx_ops__table_columns_lc($table) {
        global $wpdb;

        static $cache = [];
        $key = (string)$table;

        if (isset($cache[$key])) return $cache[$key];

        if (!knx_ops__table_exists($table)) {
            $cache[$key] = [];
            return [];
        }

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        $cols = is_array($cols) ? array_map('strtolower', $cols) : [];

        $cache[$key] = $cols;
        return $cols;
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
        $cols = knx_ops__table_columns_lc($table);
        return in_array(strtolower((string)$col), $cols, true);
    }
}

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

        if (!knx_ops__table_exists($table)) return [];

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
        $params = $request->get_json_params();
        if (is_array($params)) return $params;

        $raw = (string)$request->get_body();
        if ($raw === '') return [];

        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
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
            if (knx_ops__table_exists($t)) { $table = $t; break; }
        }
        if (!$table) return;

        $cols = knx_ops__table_columns_lc($table);
        if (empty($cols)) return;

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

        if ($actor_user > 0) {
            if (in_array('actor_user_id', $cols, true)) { $data['actor_user_id'] = $actor_user; $fmt[] = '%d'; }
            elseif (in_array('user_id', $cols, true)) { $data['user_id'] = $actor_user; $fmt[] = '%d'; }
        }

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
 * Handler: Assign driver (OPS).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_ops_assign_driver(WP_REST_Request $request) {
    global $wpdb;

    // Require session + allowed roles (fail-closed)
    if (!function_exists('knx_rest_require_session') || !function_exists('knx_rest_require_role')) {
        return function_exists('knx_rest_error')
            ? knx_rest_error('System unavailable', 503)
            : new WP_REST_Response(['success' => false, 'message' => 'System unavailable'], 503);
    }

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
    $history_table     = $wpdb->prefix . 'knx_order_status_history';

    // Tables must exist (fail-closed)
    if (!knx_ops__table_exists($orders_table)) return knx_rest_error('Orders not configured', 409);
    if (!knx_ops__table_exists($driver_ops_table)) return knx_rest_error('Driver ops not configured', 409);
    if (!knx_ops__table_exists($drivers_table)) return knx_rest_error('Drivers not configured', 409);

    // Load driver row
    $driver = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$drivers_table} WHERE id = %d LIMIT 1",
        $driver_id
    ));
    if (!$driver) return knx_rest_error('Driver not found', 404);

    // Detect optional "active" columns
    $driver_cols = knx_ops__table_columns_lc($drivers_table);

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

    // Resolve driver_user_id (required)
    $driver_user_id = knx_ops__resolve_driver_user_id($driver, $driver_cols);
    if ($driver_user_id <= 0) {
        return knx_rest_error('Driver is missing user linkage (driver_user_id)', 409);
    }

    // ====================================================================
    // ATOMIC TRANSACTION: Domain transition + driver assignment
    // ====================================================================
    $wpdb->query('START TRANSACTION');

    try {
        // Lock order row
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, city_id, status, driver_id
             FROM {$orders_table}
             WHERE id = %d
             FOR UPDATE",
            $order_id
        ));
        if (!$order) {
            $wpdb->query('ROLLBACK');
            return knx_rest_error('Order not found', 404);
        }

        $order_city_id = (int)($order->city_id ?? 0);
        $order_status  = strtolower(trim((string)($order->status ?? '')));

        // Terminal statuses cannot be assigned
        if (in_array($order_status, ['completed', 'cancelled'], true)) {
            $wpdb->query('ROLLBACK');
            return knx_rest_error('Order is in a terminal status', 409);
        }

        // Manager scope (fail-closed)
        if ($role === 'manager') {
            if ($user_id <= 0) {
                $wpdb->query('ROLLBACK');
                return knx_rest_error('Unauthorized', 401);
            }

            $allowed_cities = knx_ops_manager_city_ids($user_id);
            if (empty($allowed_cities)) {
                $wpdb->query('ROLLBACK');
                return knx_rest_error('Manager city assignment not configured', 403);
            }

            if ($order_city_id <= 0 || !in_array($order_city_id, $allowed_cities, true)) {
                $wpdb->query('ROLLBACK');
                return knx_rest_error('Forbidden: order city outside manager scope', 403);
            }
        }

        // Optional driver-city mapping enforcement
        if (knx_ops__table_exists($driver_cities_tbl)) {
            $mapped_city_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT city_id FROM {$driver_cities_tbl} WHERE driver_id = %d",
                $driver_id
            ));
            $mapped_city_ids = array_map('intval', (array)$mapped_city_ids);
            $mapped_city_ids = array_values(array_filter($mapped_city_ids, static function ($v) { return $v > 0; }));

            if (!empty($mapped_city_ids)) {
                if ($order_city_id <= 0 || !in_array($order_city_id, $mapped_city_ids, true)) {
                    if ($role === 'manager') {
                        $wpdb->query('ROLLBACK');
                        return knx_rest_error('Forbidden: driver does not serve order city', 403);
                    }
                }
            } else {
                if ($role === 'manager') {
                    $wpdb->query('ROLLBACK');
                    return knx_rest_error('Forbidden: driver not assigned to any city', 403);
                }
            }
        } else {
            if ($role === 'manager') {
                $wpdb->query('ROLLBACK');
                return knx_rest_error('Forbidden: cannot verify driver-city relationship', 403);
            }
        }

        // Lock ops row (if any)
        $ops_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, order_id, driver_user_id, ops_status
             FROM {$driver_ops_table}
             WHERE order_id = %d
             FOR UPDATE",
            $order_id
        ));

        $current_driver_user_id = ($ops_row && isset($ops_row->driver_user_id)) ? (int)$ops_row->driver_user_id : 0;
        $current_ops_status     = ($ops_row && isset($ops_row->ops_status)) ? strtolower(trim((string)$ops_row->ops_status)) : 'unassigned';

        // Idempotent: already assigned + already transitioned
        if (
            $current_driver_user_id === $driver_user_id &&
            $current_driver_user_id > 0 &&
            $current_ops_status === 'assigned' &&
            $order_status === 'accepted_by_driver'
        ) {
            $wpdb->query('COMMIT');

            return function_exists('knx_rest_response')
                ? knx_rest_response(true, 'Driver already assigned', [
                    'updated'        => false,
                    'assigned'       => true,
                    'status_changed' => false,
                    'order_id'       => $order_id,
                    'driver_id'      => $driver_id,
                    'driver_user_id' => $driver_user_id,
                    'status'         => $order_status,
                ], 200)
                : new WP_REST_Response(['success' => true, 'message' => 'Driver already assigned'], 200);
        }

        // STRICT: Only 'confirmed' orders can be assigned
        if ($order_status !== 'confirmed') {
            $wpdb->query('ROLLBACK');
            return knx_rest_error('Order must be in confirmed status to assign driver', 409, [
                'reason'          => 'INVALID_STATE_TRANSITION',
                'current_status'  => $order_status,
                'required_status' => 'confirmed',
                'next_status'     => 'accepted_by_driver',
            ]);
        }

        $now         = current_time('mysql');
        $from_status = $order_status;
        $to_status   = 'accepted_by_driver';

        // 1) Update order status + driver_id (best-effort updated_at)
        $order_cols = knx_ops__table_columns_lc($orders_table);

        $update_data = [];
        $update_fmt  = [];

        if (in_array('status', $order_cols, true)) {
            $update_data['status'] = $to_status;
            $update_fmt[] = '%s';
        } else {
            $wpdb->query('ROLLBACK');
            return knx_rest_error('Orders schema missing status column', 409);
        }

        if (in_array('driver_id', $order_cols, true)) {
            $update_data['driver_id'] = $driver_id;
            $update_fmt[] = '%d';
        }

        if (in_array('updated_at', $order_cols, true)) {
            $update_data['updated_at'] = $now;
            $update_fmt[] = '%s';
        }

        $ok = $wpdb->update(
            $orders_table,
            $update_data,
            ['id' => $order_id],
            $update_fmt,
            ['%d']
        );

        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return knx_rest_error('Failed to update order status', 500);
        }

        // 2) Insert status history (created_at is canonical)
        if (knx_ops__table_exists($history_table)) {
            $hist_cols = knx_ops__table_columns_lc($history_table);

            $hist_data = [];
            $hist_fmt  = [];

            if (in_array('order_id', $hist_cols, true)) { $hist_data['order_id'] = $order_id; $hist_fmt[] = '%d'; }
            if (in_array('status', $hist_cols, true))   { $hist_data['status'] = $to_status; $hist_fmt[] = '%s'; }

            if (in_array('changed_by', $hist_cols, true) && $user_id > 0) {
                $hist_data['changed_by'] = $user_id;
                $hist_fmt[] = '%d';
            }

            // IMPORTANT FIX: use created_at (not changed_at)
            if (in_array('created_at', $hist_cols, true)) {
                $hist_data['created_at'] = $now;
                $hist_fmt[] = '%s';
            } elseif (in_array('changed_at', $hist_cols, true)) {
                // Compatibility fallback only if legacy table uses changed_at
                $hist_data['changed_at'] = $now;
                $hist_fmt[] = '%s';
            }

            if (count($hist_data) >= 2) {
                $ok = $wpdb->insert($history_table, $hist_data, $hist_fmt);
                if (!$ok) {
                    $wpdb->query('ROLLBACK');
                    return knx_rest_error('Failed to insert status history', 500);
                }
            }
        }

        // 3) Upsert driver ops assignment
        $ops_cols = knx_ops__table_columns_lc($driver_ops_table);

        $ops_data = [];
        $ops_fmt  = [];

        if (in_array('driver_user_id', $ops_cols, true)) { $ops_data['driver_user_id'] = $driver_user_id; $ops_fmt[] = '%d'; }
        if (in_array('assigned_by', $ops_cols, true) && $user_id > 0) { $ops_data['assigned_by'] = $user_id; $ops_fmt[] = '%d'; }
        if (in_array('ops_status', $ops_cols, true)) { $ops_data['ops_status'] = 'assigned'; $ops_fmt[] = '%s'; }
        if (in_array('assigned_at', $ops_cols, true)) { $ops_data['assigned_at'] = $now; $ops_fmt[] = '%s'; }
        if (in_array('updated_at', $ops_cols, true)) { $ops_data['updated_at'] = $now; $ops_fmt[] = '%s'; }

        // Ensure order_id exists for inserts
        if (!$ops_row || !isset($ops_row->id)) {
            if (in_array('order_id', $ops_cols, true)) {
                $ops_data_insert = ['order_id' => $order_id] + $ops_data;
                $ops_fmt_insert  = ['%d'];
                foreach ($ops_fmt as $f) $ops_fmt_insert[] = $f;

                $ok = $wpdb->insert($driver_ops_table, $ops_data_insert, $ops_fmt_insert);
                if (!$ok) {
                    $wpdb->query('ROLLBACK');
                    return knx_rest_error('Failed to assign driver (ops insert)', 500);
                }
            } else {
                $wpdb->query('ROLLBACK');
                return knx_rest_error('Driver ops schema missing order_id column', 409);
            }
        } else {
            if (!empty($ops_data)) {
                $ok = $wpdb->update(
                    $driver_ops_table,
                    $ops_data,
                    ['order_id' => $order_id],
                    $ops_fmt,
                    ['%d']
                );
                if ($ok === false) {
                    $wpdb->query('ROLLBACK');
                    return knx_rest_error('Failed to assign driver (ops update)', 500);
                }
            }
        }

        $wpdb->query('COMMIT');

        // Best-effort audit (outside transaction)
        knx_ops_assign_driver_audit($order_id, 'assign_driver', 'Driver assigned with status transition', [
            'order_id'             => $order_id,
            'city_id'              => $order_city_id,
            'from_status'          => $from_status,
            'to_status'            => $to_status,
            'driver_id'            => $driver_id,
            'driver_user_id'       => $driver_user_id,
            'from_driver_user_id'  => $current_driver_user_id,
            'from_ops_status'      => $current_ops_status,
            'to_ops_status'        => 'assigned',
        ], $session);

        return function_exists('knx_rest_response')
            ? knx_rest_response(true, 'Driver assigned and status updated', [
                'updated'        => true,
                'assigned'       => true,
                'status_changed' => true,
                'from_status'    => $from_status,
                'to_status'      => $to_status,
                'order_id'       => $order_id,
                'driver_id'      => $driver_id,
                'driver_user_id' => $driver_user_id,
            ], 200)
            : new WP_REST_Response(['success' => true, 'message' => 'Driver assigned and status updated'], 200);

    } catch (\Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('[knx_ops_assign_driver] Transaction failed: ' . $e->getMessage());

        return function_exists('knx_rest_error')
            ? knx_rest_error('Failed to assign driver', 500, ['error' => substr($e->getMessage(), 0, 220)])
            : new WP_REST_Response(['success' => false, 'message' => 'Failed to assign driver'], 500);
    }
}
