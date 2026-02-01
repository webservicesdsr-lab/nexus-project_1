<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Unassign Driver (OPS v1)
 * Endpoint: POST /wp-json/knx/v1/ops/unassign-driver
 *
 * Contract:
 * - Body (JSON) or params:
 *   - order_id (int, required)
 *
 * Rules:
 * - Fail-closed: requires session + role (super_admin|manager)
 * - Manager is city-scoped (order.city_id must be within manager allowed cities)
 * - Allowed only when order.status is: assigned, in_progress
 * - Driver must be currently assigned
 * - Idempotent: if already NULL/0 -> 200 updated:false
 * - Writes driver_id = NULL (TRUE NULL; never coerced to 0)
 * - Writes updated_at if column exists
 * - Best-effort audit (non-blocking)
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
if (!function_exists('knx_ops_unassign_driver_audit')) {
    function knx_ops_unassign_driver_audit($order_id, $event_type, $message, array $meta, $session) {
        global $wpdb;

        $order_id = (int)$order_id;
        if ($order_id <= 0) return;

        // Prefer a shared audit helper if the Assign Driver endpoint already provided one.
        if (function_exists('knx_ops_assign_driver_audit')) {
            try {
                knx_ops_assign_driver_audit($order_id, (string)$event_type, (string)$message, $meta, $session);
            } catch (\Throwable $e) {
                // Non-blocking
            }
            return;
        }

        static $cache = [
            'table' => null,
            'cols'  => null,
        ];

        if ($cache['table'] === null) {
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

            $cache['table'] = $table ?: false;

            if ($cache['table']) {
                $cols = $wpdb->get_col("SHOW COLUMNS FROM {$cache['table']}");
                $cache['cols'] = is_array($cols) ? array_map('strtolower', $cols) : [];
            } else {
                $cache['cols'] = [];
            }
        }

        if (!$cache['table']) return;
        $cols = $cache['cols'];
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
        if (in_array('actor_user_id', $cols, true)) { $data['actor_user_id'] = $actor_user; $fmt[] = '%d'; }
        elseif (in_array('user_id', $cols, true)) { $data['user_id'] = $actor_user; $fmt[] = '%d'; }

        if (in_array('created_at', $cols, true)) { $data['created_at'] = $now; $fmt[] = '%s'; }
        elseif (in_array('created', $cols, true)) { $data['created'] = $now; $fmt[] = '%s'; }

        // If the table doesn't have enough expected columns, skip.
        if (count($data) < 2) return;

        try {
            $wpdb->insert($cache['table'], $data, $fmt);
        } catch (\Throwable $e) {
            // Non-blocking
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

    $orders_table = $wpdb->prefix . 'knx_orders';

    // Orders table must exist (fail-closed)
    $orders_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
    if (empty($orders_table_exists)) {
        return knx_rest_error('Orders not configured', 409);
    }

    // Fetch order (fail-closed)
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, city_id, status, driver_id
         FROM {$orders_table}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));
    if (!$order) return knx_rest_error('Order not found', 404);

    $order_city_id  = (int)$order->city_id;
    $order_status   = strtolower(trim((string)$order->status));
    $current_driver = isset($order->driver_id) ? (int)$order->driver_id : 0;

    // Only allow unassign from allowed statuses
    $allowed_statuses = ['assigned', 'in_progress'];
    if (!in_array($order_status, $allowed_statuses, true)) {
        return knx_rest_error('Order status does not allow unassign', 409);
    }

    // Manager scope (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed_cities = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed_cities)) return knx_rest_error('Manager city assignment not configured', 403);

        if ($order_city_id <= 0 || !in_array($order_city_id, $allowed_cities, true)) {
            return knx_rest_error('Forbidden: order city outside manager scope', 403);
        }
    }

    // Idempotent: already unassigned
    if ($current_driver <= 0) {
        knx_ops_unassign_driver_audit($order_id, 'unassign_driver_noop', 'No driver to unassign', [
            'order_id' => $order_id,
            'city_id'  => $order_city_id,
            'status'   => $order_status,
        ], $session);

        return knx_rest_response(true, 'No change', [
            'updated'    => false,
            'unassigned' => true,
        ], 200);
    }

    // updated_at best-effort
    $has_updated_at = (bool)$wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$orders_table} LIKE %s",
        'updated_at'
    ));

    // IMPORTANT: enforce TRUE NULL assignment (avoid wpdb format coercion to 0)
    if ($has_updated_at) {
        $prepared = $wpdb->prepare(
            "UPDATE {$orders_table}
             SET driver_id = NULL, updated_at = %s
             WHERE id = %d",
            current_time('mysql'),
            $order_id
        );
    } else {
        $prepared = $wpdb->prepare(
            "UPDATE {$orders_table}
             SET driver_id = NULL
             WHERE id = %d",
            $order_id
        );
    }

    $ok = $wpdb->query($prepared);
    if ($ok === false) return knx_rest_error('Failed to unassign driver', 500);

    // Best-effort audit (non-blocking)
    knx_ops_unassign_driver_audit($order_id, 'unassign_driver', 'Driver unassigned', [
        'order_id'       => $order_id,
        'city_id'        => $order_city_id,
        'status'         => $order_status,
        'from_driver_id' => $current_driver,
        'to_driver_id'   => null,
    ], $session);

    return knx_rest_response(true, 'Driver unassigned', [
        'updated'    => true,
        'unassigned' => true,
    ], 200);
}
