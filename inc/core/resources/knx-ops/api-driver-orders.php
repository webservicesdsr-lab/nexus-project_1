<?php
/**
 * ==========================================================
 * Kingdom Nexus — Driver Orders API (v2, DRIVER-ONLY)
 * ----------------------------------------------------------
 * Canon rules:
 * - Driver and Manager mutate the SAME DB status: knx_orders.status (ENUM DB-canon).
 * - No ops_status parallel truth. "Order Created" is UI-only label for confirmed using created_at timestamp.
 * - Auto-assign MUST transition confirmed -> accepted_by_driver (SSOT + history).
 *
 * Routes:
 * - GET  /wp-json/knx/v2/driver/orders/available
 * - GET  /wp-json/knx/v2/driver/orders/active
 * - POST /wp-json/knx/v2/driver/orders/{id}/assign
 * - POST /wp-json/knx/v2/driver/orders/{id}/ops-status   (name kept; payload is DB status)
 * - POST /wp-json/knx/v2/driver/orders/{id}/release
 * ==========================================================
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/driver/orders/available', array(
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'knx_v2_driver_orders_available',
        'permission_callback' => 'knx_v2_driver_ops_permission',
    ));

    register_rest_route('knx/v2', '/driver/orders/active', array(
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'knx_v2_driver_orders_active',
        'permission_callback' => 'knx_v2_driver_ops_permission',
    ));

    register_rest_route('knx/v2', '/driver/orders/(?P<id>\d+)', array(
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'knx_v2_driver_order_detail',
        'permission_callback' => 'knx_v2_driver_ops_permission',
        'args' => array(
            'id' => array('required' => true, 'type' => 'integer'),
        ),
    ));

    register_rest_route('knx/v2', '/driver/orders/(?P<id>\d+)/assign', array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'knx_v2_driver_order_assign',
        'permission_callback' => 'knx_v2_driver_ops_permission',
        'args' => array(
            'id' => array('required' => true, 'type' => 'integer'),
        ),
    ));

    register_rest_route('knx/v2', '/driver/orders/(?P<id>\d+)/ops-status', array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'knx_v2_driver_order_ops_status',
        'permission_callback' => 'knx_v2_driver_ops_permission',
        'args' => array(
            'id' => array('required' => true, 'type' => 'integer'),
        ),
    ));

    register_rest_route('knx/v2', '/driver/orders/(?P<id>\d+)/release', array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'knx_v2_driver_order_release',
        'permission_callback' => 'knx_v2_driver_ops_permission',
        'args' => array(
            'id' => array('required' => true, 'type' => 'integer'),
        ),
    ));
});

function knx_v2_driver_ops_permission() {
    if (!function_exists('knx_get_driver_context')) {
        return new WP_Error('knx_missing_driver_context', 'Driver context helper missing.', array('status' => 500));
    }
    $ctx = knx_get_driver_context();
    if (empty($ctx) || empty($ctx->session) || empty($ctx->session->user_id)) {
        return new WP_Error('knx_forbidden', 'Driver context not available.', array('status' => 403));
    }
    if (isset($ctx->session->role) && (string)$ctx->session->role !== 'driver') {
        return new WP_Error('knx_forbidden', 'Driver role required.', array('status' => 403));
    }
    return true;
}

function knx_driver_ops__resp($success, $data = array(), $status = 200) {
    return new WP_REST_Response([
        'success' => (bool)$success,
        'ok'      => (bool)$success,
        'data'    => $data,
    ], (int)$status);
}

function knx_driver_ops__json_body(WP_REST_Request $request) {
    $params = $request->get_json_params();
    return (is_array($params) ? $params : array());
}

function knx_driver_ops__get_knx_nonce(WP_REST_Request $request) {
    $body = knx_driver_ops__json_body($request);
    if (isset($body['knx_nonce']) && is_string($body['knx_nonce'])) return trim($body['knx_nonce']);
    $p = $request->get_param('knx_nonce');
    return (is_string($p) ? trim($p) : '');
}

function knx_driver_ops__table_has_column($table, $column) {
    static $cache = array();
    if (!isset($cache[$table])) $cache[$table] = array();
    if (array_key_exists($column, $cache[$table])) return (bool)$cache[$table][$column];

    global $wpdb;
    $like = $wpdb->esc_like($column);
    $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $like), ARRAY_A);
    $cache[$table][$column] = !empty($row);
    return (bool)$cache[$table][$column];
}

function knx_driver_ops__pick_ts_column($table, $preferred) {
    foreach ($preferred as $col) {
        if (knx_driver_ops__table_has_column($table, $col)) return $col;
    }
    return '';
}

function knx_driver_ops__resolve_driver_profile_id($ctx, $driver_user_id) {
    global $wpdb;

    if (is_object($ctx) && isset($ctx->driver) && is_object($ctx->driver) && isset($ctx->driver->id)) {
        $maybe = (int)$ctx->driver->id;
        if ($maybe > 0) return $maybe;
    }

    $drivers_table = function_exists('knx_table') ? knx_table('drivers') : ($wpdb->prefix . 'knx_drivers');
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drivers_table));
    if (empty($exists)) return 0;

    $cols = $wpdb->get_results("SHOW COLUMNS FROM {$drivers_table}", ARRAY_A);
    $names = $cols ? array_map(function($c){ return $c['Field']; }, $cols) : array();

    $conds = array();
    $args  = array();

    if (in_array('driver_user_id', $names, true)) { $conds[] = "driver_user_id = %d"; $args[] = (int)$driver_user_id; }
    if (in_array('user_id', $names, true))        { $conds[] = "user_id = %d";        $args[] = (int)$driver_user_id; }
    if (in_array('id', $names, true))             { $conds[] = "id = %d";             $args[] = (int)$driver_user_id; }

    if (empty($conds)) return 0;

    $sql = "SELECT id FROM {$drivers_table} WHERE " . implode(' OR ', $conds) . " LIMIT 1";
    $id = (int)$wpdb->get_var($wpdb->prepare($sql, $args));
    return ($id > 0 ? $id : 0);
}

function knx_driver_ops__load_driver_city_ids($driver_profile_id, $driver_user_id) {
    global $wpdb;

    $t = $wpdb->prefix . 'knx_driver_cities';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
    if (empty($exists)) return array();

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id
         FROM {$t}
         WHERE driver_id = %d
           AND city_id IS NOT NULL",
        (int)$driver_profile_id
    ));

    $ids = array_values(array_filter(array_map('intval', (array)$ids), function($v){ return $v > 0; }));

    if (empty($ids) && (int)$driver_user_id > 0) {
        $ids2 = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id
             FROM {$t}
             WHERE driver_id = %d
               AND city_id IS NOT NULL",
            (int)$driver_user_id
        ));
        $ids2 = array_values(array_filter(array_map('intval', (array)$ids2), function($v){ return $v > 0; }));
        if (!empty($ids2)) $ids = $ids2;
    }

    return array_values(array_unique($ids));
}

function knx_driver_ops__derive_hub_ids_from_cities(array $city_ids) {
    global $wpdb;

    $city_ids = array_values(array_filter(array_map('intval', $city_ids), function($v){ return $v > 0; }));
    if (empty($city_ids)) return array();

    $t = $wpdb->prefix . 'knx_hubs';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
    if (empty($exists)) return array();

    $placeholders = implode(',', array_fill(0, count($city_ids), '%d'));
    $args = $city_ids;

    $where_status = '';
    if (knx_driver_ops__table_has_column($t, 'status')) {
        $where_status = " AND status = 'active' ";
    }

    $sql = "SELECT id
            FROM {$t}
            WHERE city_id IN ({$placeholders})
              {$where_status}";

    $hub_ids = $wpdb->get_col($wpdb->prepare($sql, $args));
    $hub_ids = array_values(array_filter(array_map('intval', (array)$hub_ids), function($v){ return $v > 0; }));
    return array_values(array_unique($hub_ids));
}

function knx_driver_ops__resolve_ctx_and_scope() {

    if (!function_exists('knx_get_driver_context')) {
        return array('ok' => false, 'reason' => 'driver_context_missing');
    }

    $ctx = knx_get_driver_context();
    if (empty($ctx) || empty($ctx->session) || empty($ctx->session->user_id)) {
        return array('ok' => false, 'reason' => 'driver_context_unavailable');
    }

    $driver_user_id = (int)$ctx->session->user_id;
    $driver_profile_id = knx_driver_ops__resolve_driver_profile_id($ctx, $driver_user_id);

    if ($driver_user_id <= 0) return array('ok' => false, 'reason' => 'driver_user_id_invalid');
    if ($driver_profile_id <= 0) return array('ok' => false, 'reason' => 'driver_profile_missing');

    $allowed_city_ids = knx_driver_ops__load_driver_city_ids($driver_profile_id, $driver_user_id);
    $allowed_hub_ids  = knx_driver_ops__derive_hub_ids_from_cities($allowed_city_ids);

    return array(
        'ok' => true,
        'driver_user_id' => $driver_user_id,
        'driver_profile_id' => $driver_profile_id,
        'allowed_city_ids' => $allowed_city_ids,
        'allowed_hub_ids'  => $allowed_hub_ids,
    );
}

function knx_driver_ops__require_knx_nonce(WP_REST_Request $request) {
    $knx_nonce = knx_driver_ops__get_knx_nonce($request);
    if (!$knx_nonce || !wp_verify_nonce($knx_nonce, 'knx_nonce')) {
        return knx_driver_ops__resp(false, array('reason' => 'invalid_knx_nonce'), 403);
    }
    return true;
}

function knx_driver_ops__order_in_scope($order_city_id, $order_hub_id, array $allowed_city_ids, array $allowed_hub_ids) {
    $order_city_id = (int)$order_city_id;
    $order_hub_id  = (int)$order_hub_id;

    if ($order_city_id > 0 && !empty($allowed_city_ids) && in_array($order_city_id, $allowed_city_ids, true)) return true;
    if ($order_hub_id > 0 && !empty($allowed_hub_ids) && in_array($order_hub_id, $allowed_hub_ids, true)) return true;

    return false;
}

function knx_driver_ops__payment_is_paid($payment_status) {
    return strtolower((string)$payment_status) === 'paid';
}

function knx_driver_ops__is_terminal_order_status($status) {
    $s = strtolower((string)$status);
    return in_array($s, array('completed', 'cancelled'), true);
}

/**
 * GET /driver/orders/available
 */
function knx_v2_driver_orders_available(WP_REST_Request $request) {

    $ctx = knx_driver_ops__resolve_ctx_and_scope();
    if (empty($ctx['ok'])) return knx_driver_ops__resp(false, array('reason' => $ctx['reason']), 403);

    $driver_user_id    = (int)$ctx['driver_user_id'];
    $driver_profile_id = (int)$ctx['driver_profile_id'];
    $allowed_city_ids  = $ctx['allowed_city_ids'];
    $allowed_hub_ids   = $ctx['allowed_hub_ids'];

    if (empty($allowed_city_ids) && empty($allowed_hub_ids)) {
        return knx_driver_ops__resp(true, array(
            'orders' => array(),
            'meta' => array(
                'allowed_city_ids' => $allowed_city_ids,
                'allowed_hub_ids'  => $allowed_hub_ids,
                'driver_user_id' => $driver_user_id,
                'driver_profile_id' => $driver_profile_id,
            ),
        ));
    }

    $limit  = max(1, min(100, (int)($request->get_param('limit') ?: 50)));
    $offset = max(0, (int)($request->get_param('offset') ?: 0));

    $days = (int)($request->get_param('days') ?: 7);
    $days = max(1, min(60, $days));

    $no_after_filter = (string)($request->get_param('no_after_filter') ?: '') === '1';
    $after_mysql = '';
    if (!$no_after_filter) $after_mysql = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

    $statuses = $request->get_param('statuses');
    if (is_string($statuses) && trim($statuses) !== '') {
        $statuses = array_filter(array_map('trim', explode(',', $statuses)));
    }
    if (!is_array($statuses) || empty($statuses)) {
        $statuses = array('confirmed','accepted_by_hub','preparing','prepared');
    }
    $statuses = array_values(array_unique(array_map('strval', $statuses)));

    if (!function_exists('knx_ops_get_available_orders')) {
        return knx_driver_ops__resp(false, array('reason' => 'availability_engine_missing'), 500);
    }

    $avail = knx_ops_get_available_orders(array(
        'limit' => $limit,
        'offset' => $offset,
        'days' => $days,
        'statuses' => $statuses,
        'no_after_filter' => $no_after_filter,
        'after_mysql' => $after_mysql,
        'allowed_city_ids' => $allowed_city_ids,
        'allowed_hub_ids' => $allowed_hub_ids,
        'require_driver_null' => true,
        'require_ops_unassigned' => true,
        'require_payment_valid' => true,
        'relaxed' => false,
    ));

    $rows = isset($avail['orders']) && is_array($avail['orders']) ? $avail['orders'] : array();
    $meta = isset($avail['meta']) && is_array($avail['meta']) ? $avail['meta'] : array();

    $meta['driver_user_id'] = $driver_user_id;
    $meta['driver_profile_id'] = $driver_profile_id;

    return knx_driver_ops__resp(true, array('orders' => $rows, 'meta' => $meta));
}

/**
 * GET /driver/orders/active
 * Returns ONLY DB-canon status from knx_orders.status.
 */
function knx_v2_driver_orders_active(WP_REST_Request $request) {
    global $wpdb;

    $ctx = knx_driver_ops__resolve_ctx_and_scope();
    if (empty($ctx['ok'])) return knx_driver_ops__resp(false, array('reason' => $ctx['reason']), 403);

    $driver_user_id    = (int)$ctx['driver_user_id'];
    $driver_profile_id = (int)$ctx['driver_profile_id'];
    $allowed_city_ids  = $ctx['allowed_city_ids'];
    $allowed_hub_ids   = $ctx['allowed_hub_ids'];

    $limit  = max(1, min(100, (int)($request->get_param('limit') ?: 50)));
    $offset = max(0, (int)($request->get_param('offset') ?: 0));
    $include_terminal = ((string)($request->get_param('include_terminal') ?: '') === '1');

    $orders_table = $wpdb->prefix . 'knx_orders';

    if (empty($allowed_city_ids) && empty($allowed_hub_ids)) {
        return knx_driver_ops__resp(true, array('orders' => array(), 'meta' => array('limit'=>$limit,'offset'=>$offset)));
    }

    $where = array();
    $args  = array();

    $where[] = "o.driver_id = %d";
    $args[]  = $driver_profile_id;

    $scopeParts = array();
    if (!empty($allowed_city_ids)) {
        $ph = implode(',', array_fill(0, count($allowed_city_ids), '%d'));
        $scopeParts[] = "(o.city_id IS NOT NULL AND o.city_id IN ({$ph}))";
        $args = array_merge($args, $allowed_city_ids);
    }
    if (!empty($allowed_hub_ids)) {
        $ph = implode(',', array_fill(0, count($allowed_hub_ids), '%d'));
        $scopeParts[] = "(o.hub_id IN ({$ph}))";
        $args = array_merge($args, $allowed_hub_ids);
    }
    if (!empty($scopeParts)) $where[] = "(" . implode(" OR ", $scopeParts) . ")";

    $where[] = "LOWER(o.payment_status) = 'paid'";
    $where[] = "LOWER(o.status) <> 'pending_payment'";

    if (!$include_terminal) {
        $where[] = "LOWER(o.status) NOT IN ('completed','cancelled')";
    }

    $sql_where = implode(" AND ", $where);

    $sql = "
        SELECT
            o.id,
            o.order_number,
            o.hub_id,
            o.city_id,
            o.status,
            o.payment_status,
            o.total,
            o.customer_name,
            o.customer_phone,
            o.delivery_address,
            o.delivery_lat,
            o.delivery_lng,
            o.created_at,
            o.updated_at
        FROM {$orders_table} o
        WHERE {$sql_where}
        ORDER BY o.updated_at DESC, o.id DESC
        LIMIT %d OFFSET %d
    ";

    $args[] = $limit;
    $args[] = $offset;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    if (!is_array($rows)) $rows = array();

    return knx_driver_ops__resp(true, array(
        'orders' => $rows,
        'meta' => array(
            'limit' => $limit,
            'offset' => $offset,
            'include_terminal' => $include_terminal,
            'driver_user_id' => $driver_user_id,
            'driver_profile_id' => $driver_profile_id,
        ),
    ));
}

/**
 * POST /driver/orders/{id}/assign
 * Auto-assign must transition confirmed -> accepted_by_driver (SSOT).
 */
function knx_v2_driver_order_assign(WP_REST_Request $request) {
    global $wpdb;

    $ctx = knx_driver_ops__resolve_ctx_and_scope();
    if (empty($ctx['ok'])) return knx_driver_ops__resp(false, array('reason' => $ctx['reason']), 403);

    $driver_user_id    = (int)$ctx['driver_user_id'];
    $driver_profile_id = (int)$ctx['driver_profile_id'];
    $allowed_city_ids  = $ctx['allowed_city_ids'];
    $allowed_hub_ids   = $ctx['allowed_hub_ids'];

    if (empty($allowed_city_ids) && empty($allowed_hub_ids)) {
        return knx_driver_ops__resp(false, array('reason' => 'driver_scope_empty'), 403);
    }

    $nonceRes = knx_driver_ops__require_knx_nonce($request);
    if ($nonceRes !== true) return $nonceRes;

    if (!function_exists('knx_orders_apply_status_change_db_canon')) {
        return knx_driver_ops__resp(false, array('reason' => 'orders_status_ssot_missing'), 500);
    }

    $order_id = (int)$request->get_param('id');
    if ($order_id <= 0) return knx_driver_ops__resp(false, array('reason' => 'invalid_order_id'), 400);

    $orders_table = $wpdb->prefix . 'knx_orders';
    $now = current_time('mysql');

    $assignable_statuses = array('confirmed','accepted_by_hub','preparing','prepared','picked_up');

    $wpdb->query('START TRANSACTION');

    try {
        $order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, driver_id, status, payment_status, city_id, hub_id
                 FROM {$orders_table}
                 WHERE id = %d
                 FOR UPDATE",
                $order_id
            ),
            ARRAY_A
        );

        if (!$order) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
        }

        $status = strtolower((string)($order['status'] ?? ''));
        $ps     = strtolower((string)($order['payment_status'] ?? ''));

        if ($status === 'pending_payment') { $wpdb->query('ROLLBACK'); return knx_driver_ops__resp(false, array('reason' => 'order_pending_payment'), 409); }
        if (!knx_driver_ops__payment_is_paid($ps)) { $wpdb->query('ROLLBACK'); return knx_driver_ops__resp(false, array('reason' => 'payment_not_paid'), 409); }
        if (knx_driver_ops__is_terminal_order_status($status)) { $wpdb->query('ROLLBACK'); return knx_driver_ops__resp(false, array('reason' => 'order_terminal', 'status' => $status), 409); }
        if (!in_array($status, $assignable_statuses, true)) { $wpdb->query('ROLLBACK'); return knx_driver_ops__resp(false, array('reason' => 'status_not_assignable', 'status' => $status), 409); }

        $order_hub  = !empty($order['hub_id'])  ? (int)$order['hub_id']  : 0;
        $order_city = !empty($order['city_id']) ? (int)$order['city_id'] : 0;

        if (!knx_driver_ops__order_in_scope($order_city, $order_hub, $allowed_city_ids, $allowed_hub_ids)) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
        }

        $current_driver_id = !empty($order['driver_id']) ? (int)$order['driver_id'] : 0;
        if ($current_driver_id > 0 && $current_driver_id !== $driver_profile_id) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'already_assigned'), 409);
        }

        // Set driver_id (assignment)
        $ok_order = $wpdb->update(
            $orders_table,
            array('driver_id' => $driver_profile_id, 'updated_at' => $now),
            array('id' => $order_id),
            array('%d','%s'),
            array('%d')
        );
        if ($ok_order === false) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'db_order_update_failed'), 500);
        }

        // Auto-transition: confirmed -> accepted_by_driver via SSOT
        $status_after = $status;
        $did_transition = false;

        if ($status === 'confirmed') {
            $res = knx_orders_apply_status_change_db_canon($order_id, 'accepted_by_driver', $driver_user_id, $now);
            if (is_wp_error($res)) {
                $wpdb->query('ROLLBACK');
                $status_code = (int)($res->get_error_data()['status'] ?? 400);
                return knx_driver_ops__resp(false, array(
                    'reason' => $res->get_error_code(),
                    'message' => $res->get_error_message(),
                ), $status_code);
            }
            $status_after = (string)$res['status'];
            $did_transition = true;
        }

        $wpdb->query('COMMIT');

        return knx_driver_ops__resp(true, array(
            'assigned' => true,
            'order_id' => $order_id,
            'driver_user_id' => $driver_user_id,
            'driver_profile_id' => $driver_profile_id,
            'status_before' => $status,
            'status_after'  => $status_after,
            'status_changed' => (bool)$did_transition,
            'server_time' => $now,
        ));

    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return knx_driver_ops__resp(false, array('reason' => 'exception'), 500);
    }
}

/**
 * POST /driver/orders/{id}/ops-status
 * Payload is DB-canon status: {status:"..."} or {to_status:"..."}
 * Rejects ops_status param (no parallel truth).
 */
function knx_v2_driver_order_ops_status(WP_REST_Request $request) {
    global $wpdb;

    $ctx = knx_driver_ops__resolve_ctx_and_scope();
    if (empty($ctx['ok'])) return knx_driver_ops__resp(false, array('reason' => $ctx['reason']), 403);

    $driver_user_id    = (int)$ctx['driver_user_id'];
    $driver_profile_id = (int)$ctx['driver_profile_id'];
    $allowed_city_ids  = $ctx['allowed_city_ids'];
    $allowed_hub_ids   = $ctx['allowed_hub_ids'];

    $nonceRes = knx_driver_ops__require_knx_nonce($request);
    if ($nonceRes !== true) return $nonceRes;

    if (!function_exists('knx_orders_apply_status_change_db_canon') || !function_exists('knx_orders_allowed_statuses_db_canon')) {
        return knx_driver_ops__resp(false, array('reason' => 'orders_status_ssot_missing'), 500);
    }

    $order_id = (int)$request->get_param('id');
    if ($order_id <= 0) return knx_driver_ops__resp(false, array('reason' => 'invalid_order_id'), 400);

    $body = knx_driver_ops__json_body($request);

    // Hard reject old param
    if (isset($body['ops_status']) || $request->get_param('ops_status')) {
        return knx_driver_ops__resp(false, array('reason' => 'invalid_param', 'message' => 'Use status/to_status (DB-canon). ops_status is not supported.'), 400);
    }

    $new_status = isset($body['status']) ? (string)$body['status'] : (string)$request->get_param('status');
    if ($new_status === '') $new_status = isset($body['to_status']) ? (string)$body['to_status'] : (string)$request->get_param('to_status');
    $new_status = sanitize_text_field($new_status);

    $allowed = knx_orders_allowed_statuses_db_canon();
    if (!in_array($new_status, $allowed, true)) {
        return knx_driver_ops__resp(false, array('reason' => 'invalid-status', 'allowed' => $allowed), 400);
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $now = current_time('mysql');

    // Scope + ownership lock
    $order = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, status, payment_status, driver_id, city_id, hub_id
             FROM {$orders_table}
             WHERE id = %d
             LIMIT 1",
            $order_id
        ),
        ARRAY_A
    );

    if (!$order) return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);

    $order_hub  = !empty($order['hub_id'])  ? (int)$order['hub_id']  : 0;
    $order_city = !empty($order['city_id']) ? (int)$order['city_id'] : 0;

    if (!knx_driver_ops__order_in_scope($order_city, $order_hub, $allowed_city_ids, $allowed_hub_ids)) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    $order_driver = (int)($order['driver_id'] ?? 0);
    if ($order_driver <= 0 || $order_driver !== $driver_profile_id) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // Apply SSOT (payment gating + transition matrix + history)
    $res = knx_orders_apply_status_change_db_canon($order_id, $new_status, $driver_user_id, $now);

    if (is_wp_error($res)) {
        $status_code = (int)($res->get_error_data()['status'] ?? 400);
        return knx_driver_ops__resp(false, array(
            'reason' => $res->get_error_code(),
            'message' => $res->get_error_message(),
            'from' => (string)($order['status'] ?? ''),
            'to' => $new_status,
        ), $status_code);
    }

    return knx_driver_ops__resp(true, array(
        'order_id' => $order_id,
        'status_before' => (string)$res['from_status'],
        'status_after'  => (string)$res['status'],
        'server_time' => $now,
    ));
}

/**
 * POST /driver/orders/{id}/release
 * Unassigns and if status == accepted_by_driver revert to confirmed via SSOT.
 */
function knx_v2_driver_order_release(WP_REST_Request $request) {
    global $wpdb;

    $ctx = knx_driver_ops__resolve_ctx_and_scope();
    if (empty($ctx['ok'])) return knx_driver_ops__resp(false, array('reason' => $ctx['reason']), 403);

    $driver_user_id    = (int)$ctx['driver_user_id'];
    $driver_profile_id = (int)$ctx['driver_profile_id'];
    $allowed_city_ids  = $ctx['allowed_city_ids'];
    $allowed_hub_ids   = $ctx['allowed_hub_ids'];

    $nonceRes = knx_driver_ops__require_knx_nonce($request);
    if ($nonceRes !== true) return $nonceRes;

    if (!function_exists('knx_orders_apply_status_change_db_canon')) {
        return knx_driver_ops__resp(false, array('reason' => 'orders_status_ssot_missing'), 500);
    }

    $order_id = (int)$request->get_param('id');
    if ($order_id <= 0) return knx_driver_ops__resp(false, array('reason' => 'invalid_order_id'), 400);

    $orders_table = $wpdb->prefix . 'knx_orders';
    $now = current_time('mysql');

    $order = $wpdb->get_row(
        $wpdb->prepare("SELECT id, status, payment_status, driver_id, city_id, hub_id FROM {$orders_table} WHERE id = %d LIMIT 1", $order_id),
        ARRAY_A
    );
    if (!$order) return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);

    $order_hub  = !empty($order['hub_id'])  ? (int)$order['hub_id']  : 0;
    $order_city = !empty($order['city_id']) ? (int)$order['city_id'] : 0;

    if (!knx_driver_ops__order_in_scope($order_city, $order_hub, $allowed_city_ids, $allowed_hub_ids)) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    $order_driver = (int)($order['driver_id'] ?? 0);
    if ($order_driver <= 0 || $order_driver !== $driver_profile_id) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // Unassign
    $ok = $wpdb->update(
        $orders_table,
        array('driver_id' => null, 'updated_at' => $now),
        array('id' => $order_id),
        array('%s','%s'),
        array('%d')
    );

    if ($ok === false) return knx_driver_ops__resp(false, array('reason' => 'db_order_update_failed'), 500);

    // Revert accepted_by_driver -> confirmed via SSOT
    $status_before = strtolower((string)($order['status'] ?? ''));
    $status_after = $status_before;
    $did_change = false;

    if ($status_before === 'accepted_by_driver') {
        $res = knx_orders_apply_status_change_db_canon($order_id, 'confirmed', $driver_user_id, $now);
        if (is_wp_error($res)) {
            return knx_driver_ops__resp(false, array(
                'reason' => $res->get_error_code(),
                'message' => $res->get_error_message(),
            ), (int)($res->get_error_data()['status'] ?? 400));
        }
        $status_after = (string)$res['status'];
        $did_change = true;
    }

    return knx_driver_ops__resp(true, array(
        'order_id' => $order_id,
        'unassigned' => true,
        'status_before' => $status_before,
        'status_after'  => $status_after,
        'status_changed' => (bool)$did_change,
        'server_time' => $now,
    ));
}

/**
 * GET /wp-json/knx/v2/driver/orders/{id}
 * Driver-only, DB-canon only.
 * Fail-closed:
 * - Not found or not assigned => 404
 * - Out of scope => 404
 */
function knx_v2_driver_order_detail(WP_REST_Request $request) {
    global $wpdb;

    $ctx = knx_driver_ops__resolve_ctx_and_scope();
    if (empty($ctx['ok'])) {
        return knx_driver_ops__resp(false, array('reason' => $ctx['reason']), 403);
    }

    $driver_user_id    = (int)$ctx['driver_user_id'];
    $driver_profile_id = (int)$ctx['driver_profile_id'];
    $allowed_city_ids  = $ctx['allowed_city_ids'];
    $allowed_hub_ids   = $ctx['allowed_hub_ids'];

    $order_id = (int)$request->get_param('id');
    if ($order_id <= 0) {
        return knx_driver_ops__resp(false, array('reason' => 'invalid_order_id'), 400);
    }

    $orders_table  = $wpdb->prefix . 'knx_orders';
    $history_table = $wpdb->prefix . 'knx_order_status_history';

    $order = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                id,
                order_number,
                hub_id,
                city_id,
                driver_id,
                status,
                payment_status,
                total,
                customer_name,
                customer_phone,
                delivery_address,
                delivery_lat,
                delivery_lng,
                created_at,
                updated_at
             FROM {$orders_table}
             WHERE id = %d
             LIMIT 1",
            $order_id
        ),
        ARRAY_A
    );

    if (!$order) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // Payment gating (canon)
    $status = strtolower((string)($order['status'] ?? ''));
    $ps     = strtolower((string)($order['payment_status'] ?? ''));

    if ($status === 'pending_payment') {
        return knx_driver_ops__resp(false, array('reason' => 'order_pending_payment'), 409);
    }
    if (!knx_driver_ops__payment_is_paid($ps)) {
        return knx_driver_ops__resp(false, array('reason' => 'payment_not_paid'), 409);
    }

    // Ownership: must be assigned to this driver (fail-closed)
    $order_driver = !empty($order['driver_id']) ? (int)$order['driver_id'] : 0;
    if ($order_driver <= 0 || $order_driver !== $driver_profile_id) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // Scope
    $order_hub  = !empty($order['hub_id'])  ? (int)$order['hub_id']  : 0;
    $order_city = !empty($order['city_id']) ? (int)$order['city_id'] : 0;

    if (!knx_driver_ops__order_in_scope($order_city, $order_hub, $allowed_city_ids, $allowed_hub_ids)) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // Status history (best effort)
    $history = array();
    $hist_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table));
    if (!empty($hist_exists)) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, changed_by, created_at
                 FROM {$history_table}
                 WHERE order_id = %d
                 ORDER BY id ASC",
                $order_id
            ),
            ARRAY_A
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $history[] = array(
                    'status' => (string)($r['status'] ?? ''),
                    'changed_by' => (int)($r['changed_by'] ?? 0),
                    'created_at' => (string)($r['created_at'] ?? ''),
                );
            }
        }
    }

    return knx_driver_ops__resp(true, array(
        'order' => $order,
        'status_history' => $history,
        'meta' => array(
            'driver_user_id' => $driver_user_id,
            'driver_profile_id' => $driver_profile_id,
            'server_gmt' => gmdate('Y-m-d H:i:s'),
        ),
    ));
}