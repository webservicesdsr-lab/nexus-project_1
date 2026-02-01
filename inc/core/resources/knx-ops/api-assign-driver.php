<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Assign Driver (OPS v1)
 * Endpoint: POST /wp-json/knx/v1/ops/assign-driver
 *
 * Contract:
 * - Body (JSON) or params:
 *   - order_id (int, required)
 *   - driver_id (int, required)
 *
 * Rules:
 * - Fail-closed: requires session + role
 * - Manager is city-scoped (order.city_id must be within manager allowed cities)
 * - Only operationally-active orders can be assigned (no completed/cancelled)
 * - Driver must exist (and must be active if schema supports an active/status column)
 *
 * Notes:
 * - Uses $wpdb->prefix always
 * - Uses knx_rest_wrap + permission callback roles
 * - Audit trail is best-effort and non-blocking (does not fail the write if audit insert fails)
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
 * Resolve manager allowed city IDs based on hubs.manager_user_id.
 * Defined defensively (only if not already defined elsewhere).
 *
 * @param int $manager_user_id
 * @return array<int>
 */
if (!function_exists('knx_ops_live_orders_manager_city_ids')) {
    function knx_ops_live_orders_manager_city_ids($manager_user_id) {
        global $wpdb;

        $hubs_table = $wpdb->prefix . 'knx_hubs';

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
 * Best-effort audit insert. Non-blocking.
 *
 * Tries common audit tables and inserts only columns that exist.
 *
 * @param int    $order_id
 * @param string $event_type
 * @param string $message
 * @param array  $meta
 * @param object $session
 * @return void
 */
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
    $now  = current_time('mysql');

    $actor_role = isset($session->role) ? (string)$session->role : '';
    $actor_user = isset($session->user_id) ? (int)$session->user_id : 0;

    $data = [];
    $fmt  = [];

    // Required-ish
    if (in_array('order_id', $cols, true)) { $data['order_id'] = $order_id; $fmt[] = '%d'; }

    // Type
    if (in_array('event_type', $cols, true)) { $data['event_type'] = (string)$event_type; $fmt[] = '%s'; }
    elseif (in_array('type', $cols, true))   { $data['type'] = (string)$event_type; $fmt[] = '%s'; }

    // Message
    if (in_array('message', $cols, true)) { $data['message'] = (string)$message; $fmt[] = '%s'; }
    elseif (in_array('note', $cols, true)) { $data['note'] = (string)$message; $fmt[] = '%s'; }

    // Meta JSON
    $meta_json = wp_json_encode($meta);
    if (in_array('meta_json', $cols, true)) { $data['meta_json'] = $meta_json; $fmt[] = '%s'; }
    elseif (in_array('payload_json', $cols, true)) { $data['payload_json'] = $meta_json; $fmt[] = '%s'; }
    elseif (in_array('payload', $cols, true)) { $data['payload'] = $meta_json; $fmt[] = '%s'; }

    // Actor
    if (in_array('actor_role', $cols, true)) { $data['actor_role'] = $actor_role; $fmt[] = '%s'; }
    if (in_array('actor_user_id', $cols, true)) { $data['actor_user_id'] = $actor_user; $fmt[] = '%d'; }
    elseif (in_array('user_id', $cols, true)) { $data['user_id'] = $actor_user; $fmt[] = '%d'; }

    // Timestamp
    if (in_array('created_at', $cols, true)) { $data['created_at'] = $now; $fmt[] = '%s'; }
    elseif (in_array('created', $cols, true)) { $data['created'] = $now; $fmt[] = '%s'; }

    // If the table doesn't have any of the expected columns, skip.
    if (count($data) < 2) return;

    // Non-blocking insert
    try {
        $wpdb->insert($table, $data, $fmt);
    } catch (\Throwable $e) {
        // Intentionally non-blocking
    }
}

/**
 * Parse JSON body if needed. WP_REST_Request get_param() can miss JSON if Content-Type is off.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function knx_ops_assign_driver_read_json_body(WP_REST_Request $request) {
    $raw = (string)$request->get_body();
    if ($raw === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function knx_ops_assign_driver(WP_REST_Request $request) {
    global $wpdb;

    // Require session + allowed roles (fail-closed)
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    // Optional nonce enforcement if your core provides it (keep centralized behavior if present)
    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    // Params (support JSON body or form params)
    $body = knx_ops_assign_driver_read_json_body($request);

    $order_id  = (int)($request->get_param('order_id') ?? ($body['order_id'] ?? 0));
    $driver_id = (int)($request->get_param('driver_id') ?? ($body['driver_id'] ?? 0));

    if ($order_id <= 0) return knx_rest_error('order_id is required', 400);
    if ($driver_id <= 0) return knx_rest_error('driver_id is required', 400);

    $orders_table  = $wpdb->prefix . 'knx_orders';
    $drivers_table = $wpdb->prefix . 'knx_drivers';

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
    $order_status  = (string)$order->status;
    $current_driver_id = (int)$order->driver_id;

    // Only active operational statuses (OPS v1)
    $allowed_statuses = ['placed', 'confirmed', 'preparing', 'assigned', 'in_progress'];
    if (!in_array($order_status, $allowed_statuses, true)) {
        return knx_rest_error('Order is not in an assignable status', 409);
    }

    // Manager city scope enforcement (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed_cities = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed_cities)) {
            return knx_rest_error('Manager city assignment not configured', 403);
        }

        if ($order_city_id <= 0 || !in_array($order_city_id, $allowed_cities, true)) {
            return knx_rest_error('Forbidden: order city outside manager scope', 403);
        }
    }

    // Verify drivers table exists (fail-closed)
    $drivers_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drivers_table));
    if (empty($drivers_table_exists)) {
        return knx_rest_error('Drivers not configured', 409);
    }

    // Detect optional "active" columns for stricter validation
    $driver_cols = $wpdb->get_col("SHOW COLUMNS FROM {$drivers_table}");
    $driver_cols = is_array($driver_cols) ? array_map('strtolower', $driver_cols) : [];

    $driver = $wpdb->get_row($wpdb->prepare(
        "SELECT *
         FROM {$drivers_table}
         WHERE id = %d
         LIMIT 1",
        $driver_id
    ));

    if (!$driver) return knx_rest_error('Driver not found', 404);

    // Enforce active if schema supports it
    $is_active_ok = true;

    if (in_array('is_active', $driver_cols, true)) {
        $is_active_ok = ((int)$driver->is_active === 1);
    } elseif (in_array('active', $driver_cols, true)) {
        $is_active_ok = ((int)$driver->active === 1);
    } elseif (in_array('status', $driver_cols, true)) {
        $st = strtolower((string)$driver->status);
        $is_active_ok = in_array($st, ['active', 'enabled', 'on', '1'], true);
    }

    if (!$is_active_ok) {
        return knx_rest_error('Driver is not active', 409);
    }

    // Enforce driver <-> city relationship if driver cities mapping table exists.
    // If driver-city mapping exists, require that the driver serves the order's city.
    // If no reliable relation exists, fail-closed for managers (only super_admin may proceed).
    $driver_cities_table = $wpdb->prefix . 'knx_driver_cities';
    $driver_cities_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $driver_cities_table));
    if ($driver_cities_table_exists) {
        $mapped_city_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT city_id FROM {$driver_cities_table} WHERE driver_id = %d",
            $driver_id
        ));
        $mapped_city_ids = array_map('intval', (array)$mapped_city_ids);
        $mapped_city_ids = array_values(array_filter($mapped_city_ids, static function ($v) { return $v > 0; }));

        if (empty($mapped_city_ids)) {
            // Driver exists but has no city mappings — treat as not allowed for managers
            if ($role === 'manager') {
                return knx_rest_error('Forbidden: driver not assigned to any city', 403);
            }
            // super_admin may proceed
        } else {
            if ($order_city_id <= 0 || !in_array($order_city_id, $mapped_city_ids, true)) {
                // Driver does not serve this city
                if ($role === 'manager') {
                    return knx_rest_error('Forbidden: driver does not serve order city', 403);
                }
                // super_admin may proceed
            }
        }
    } else {
        // No driver-city mapping table available. Fail-closed for managers: only super_admin can assign.
        if ($role === 'manager') {
            return knx_rest_error('Forbidden: cannot verify driver-city relationship', 403);
        }
    }

    // Idempotency: if already assigned to same driver, return success.
    if ($current_driver_id === $driver_id) {
        return knx_rest_response(true, 'Driver already assigned', [
            'assigned' => true,
        ], 200);
    }

    // Update order driver_id (fail-closed on write failure)
    $updated_at_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$orders_table} LIKE %s",
        'updated_at'
    ));

    $data = ['driver_id' => $driver_id];
    $where = ['id' => $order_id];

    $data_fmt = ['%d'];
    $where_fmt = ['%d'];

    if (!empty($updated_at_exists)) {
        $data['updated_at'] = current_time('mysql');
        $data_fmt[] = '%s';
    }

    $ok = $wpdb->update($orders_table, $data, $where, $data_fmt, $where_fmt);
    if ($ok === false) {
        return knx_rest_error('Failed to assign driver', 500);
    }

    // Best-effort audit (non-blocking)
    knx_ops_assign_driver_audit($order_id, 'assign_driver', 'Driver assigned', [
        'order_id' => $order_id,
        'city_id' => $order_city_id,
        'status' => $order_status,
        'from_driver_id' => $current_driver_id,
        'to_driver_id' => $driver_id,
    ], $session);

    return knx_rest_response(true, 'Driver assigned', [
        'assigned' => true,
    ], 200);
}
