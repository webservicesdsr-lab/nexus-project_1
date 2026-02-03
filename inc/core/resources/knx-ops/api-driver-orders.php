<?php
/**
 * ==========================================================
 * Kingdom Nexus — Driver OPS Orders API (canonical)
 * ----------------------------------------------------------
 * Routes:
 * - GET  /wp-json/knx/v2/driver/orders/available
 * - POST /wp-json/knx/v2/driver/orders/{id}/assign
 *
 * Response contract (canonical):
 * {
 *   "success": true|false,
 *   "ok": true|false,              // compatibility alias
 *   "data": { ... }                // when success
 * }
 *
 * Notes:
 * - Fail-closed for scope (cities/hubs).
 * - Transactional assign with row locks.
 * - Uses $wpdb->prefix (never hardcoded).
 * ==========================================================
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/driver/orders/available', array(
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'knx_v2_driver_orders_available',
        'permission_callback' => 'knx_v2_driver_ops_permission',
    ));

    register_rest_route('knx/v2', '/driver/orders/(?P<id>\d+)/assign', array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'knx_v2_driver_order_assign',
        'permission_callback' => 'knx_v2_driver_ops_permission',
        'args' => array(
            'id' => array('required' => true, 'type' => 'integer'),
        ),
    ));
});

/**
 * Permission callback: strict driver context.
 * Returns WP_Error with status for REST.
 */
function knx_v2_driver_ops_permission() {
    if (!function_exists('knx_get_driver_context')) {
        return new WP_Error('knx_missing_driver_context', 'Driver context helper missing.', array('status' => 500));
    }
    $ctx = knx_get_driver_context();
    if (empty($ctx) || empty($ctx->session) || empty($ctx->session->user_id)) {
        return new WP_Error('knx_forbidden', 'Driver context not available.', array('status' => 403));
    }
    return true;
}

function knx_driver_ops__resp($success, $data = array(), $status = 200) {
    $payload = array(
        'success' => (bool)$success,
        'ok'      => (bool)$success, // compatibility alias
        'data'    => $data,
    );
    return new WP_REST_Response($payload, (int)$status);
}

function knx_driver_ops__table_has_column($table, $column) {
    static $cache = array(); // [table => [col => bool]]
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

/**
 * Resolve driver identity and scope.
 * Returns array: [driver_user_id, driver_profile_id, allowed_city_ids, allowed_hub_ids]
 */
function knx_driver_ops__resolve_ctx_and_scope() {

    if (!function_exists('knx_get_driver_context')) {
        return array('ok' => false, 'reason' => 'driver_context_missing');
    }

    $ctx = knx_get_driver_context();
    if (empty($ctx) || empty($ctx->session) || empty($ctx->session->user_id)) {
        return array('ok' => false, 'reason' => 'driver_context_unavailable');
    }

    $driver_user_id = (int)$ctx->session->user_id;
    $driver_profile_id = !empty($ctx->driver_id) ? (int)$ctx->driver_id : $driver_user_id;

    $allowed_city_ids = array();
    $allowed_hub_ids  = array();

    // Canonical scope helper (preferred)
    if (function_exists('knx_do__load_driver_scope')) {
        $scope = knx_do__load_driver_scope($driver_profile_id);
        if (is_array($scope)) {
            $allowed_city_ids = !empty($scope['city_ids']) && is_array($scope['city_ids']) ? $scope['city_ids'] : array();
            $allowed_hub_ids  = !empty($scope['hub_ids'])  && is_array($scope['hub_ids'])  ? $scope['hub_ids']  : array();
        }
    }

    // Normalize ints + unique
    $allowed_city_ids = array_values(array_unique(array_map('intval', $allowed_city_ids)));
    $allowed_hub_ids  = array_values(array_unique(array_map('intval', $allowed_hub_ids)));

    return array(
        'ok' => true,
        'driver_user_id' => $driver_user_id,
        'driver_profile_id' => $driver_profile_id,
        'allowed_city_ids' => $allowed_city_ids,
        'allowed_hub_ids'  => $allowed_hub_ids,
    );
}

/**
 * GET /driver/orders/available
 */
function knx_v2_driver_orders_available(WP_REST_Request $request) {
    global $wpdb;

    $ctx = knx_driver_ops__resolve_ctx_and_scope();
    if (empty($ctx['ok'])) {
        return knx_driver_ops__resp(false, array('reason' => $ctx['reason']), 403);
    }

    $driver_user_id    = (int)$ctx['driver_user_id'];
    $driver_profile_id = (int)$ctx['driver_profile_id'];
    $allowed_city_ids  = $ctx['allowed_city_ids'];
    $allowed_hub_ids   = $ctx['allowed_hub_ids'];

    // Fail-closed: no scope => no orders.
    if (empty($allowed_city_ids) && empty($allowed_hub_ids)) {
        return knx_driver_ops__resp(true, array(
            'orders' => array(),
            'meta' => array(
                'range' => 'recent',
                'days' => 7,
                'after_mysql' => '',
                'no_after_filter' => false,
                'limit' => 50,
                'offset' => 0,
                'statuses' => array('placed','confirmed','preparing','ready','out_for_delivery'),
                'allowed_city_ids' => $allowed_city_ids,
                'allowed_hub_ids'  => $allowed_hub_ids,
                'driver_user_id' => $driver_user_id,
                'driver_profile_id' => $driver_profile_id,
                'server_gmt' => gmdate('Y-m-d H:i:s'),
            ),
        ));
    }

    $limit  = max(1, min(100, (int)($request->get_param('limit') ?: 50)));
    $offset = max(0, (int)($request->get_param('offset') ?: 0));

    $days = (int)($request->get_param('days') ?: 7);
    $days = max(1, min(60, $days));

    $range = (string)($request->get_param('range') ?: 'recent');
    if ($range !== 'recent') $range = 'recent';

    $statuses = $request->get_param('statuses');
    if (is_string($statuses) && trim($statuses) !== '') {
        $statuses = array_filter(array_map('trim', explode(',', $statuses)));
    }
    if (!is_array($statuses) || empty($statuses)) {
        $statuses = array('placed','confirmed','preparing','ready','out_for_delivery');
    }
    $statuses = array_values(array_unique(array_map('strval', $statuses)));

    $no_after_filter = (string)($request->get_param('no_after_filter') ?: '') === '1';
    $after_mysql = '';
    if (!$no_after_filter) {
        $after_mysql = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $ops_table    = $wpdb->prefix . 'knx_driver_ops';
    // Use canonical availability engine with driver-specific filters (fail-closed)
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

    // Add driver info to meta
    $meta['driver_user_id'] = $driver_user_id;
    $meta['driver_profile_id'] = $driver_profile_id;

    return knx_driver_ops__resp(true, array('orders' => $rows, 'meta' => $meta));
}

/**
 * POST /driver/orders/{id}/assign
 */
function knx_v2_driver_order_assign(WP_REST_Request $request) {
    global $wpdb;

    $ctx = knx_driver_ops__resolve_ctx_and_scope();
    if (empty($ctx['ok'])) {
        return knx_driver_ops__resp(false, array('reason' => $ctx['reason']), 403);
    }

    $driver_user_id    = (int)$ctx['driver_user_id'];
    $driver_profile_id = (int)$ctx['driver_profile_id'];
    $allowed_city_ids  = $ctx['allowed_city_ids'];
    $allowed_hub_ids   = $ctx['allowed_hub_ids'];

    // Fail-closed scope
    if (empty($allowed_city_ids) && empty($allowed_hub_ids)) {
        return knx_driver_ops__resp(false, array('reason' => 'driver_scope_empty'), 403);
    }

    // Require knx_nonce (consistent with your secured POST patterns)
    $params = $request->get_json_params();
    $knx_nonce = '';
    if (is_array($params) && isset($params['knx_nonce'])) $knx_nonce = (string)$params['knx_nonce'];
    if (!$knx_nonce) $knx_nonce = (string)$request->get_param('knx_nonce');

    if (!$knx_nonce || !wp_verify_nonce($knx_nonce, 'knx_nonce')) {
        return knx_driver_ops__resp(false, array('reason' => 'invalid_knx_nonce'), 403);
    }

    $order_id = (int)$request->get_param('id');
    if ($order_id <= 0) {
        return knx_driver_ops__resp(false, array('reason' => 'invalid_order_id'), 400);
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $ops_table    = $wpdb->prefix . 'knx_driver_ops';

    // Columns compatibility
    $ops_ts_col = knx_driver_ops__pick_ts_column($ops_table, array('ops_updated_at','updated_at','modified_at'));
    $orders_ts_col = knx_driver_ops__pick_ts_column($orders_table, array('updated_at','modified_at'));

    $now_gmt = gmdate('Y-m-d H:i:s');

    // Assignable statuses for "available" pool
    $assignable_statuses = array('placed','confirmed','preparing','ready','out_for_delivery');

    $wpdb->query('START TRANSACTION');

    try {
        // Lock order row
        $order = $wpdb->get_row(
            $wpdb->prepare("SELECT id, driver_id, status, city_id, hub_id FROM {$orders_table} WHERE id = %d FOR UPDATE", $order_id),
            ARRAY_A
        );

        if (!$order) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
        }

        $current_driver_id = !empty($order['driver_id']) ? (int)$order['driver_id'] : 0;
        if ($current_driver_id > 0) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'already_assigned'), 409);
        }

        $status = (string)($order['status'] ?? '');
        if (!$status || !in_array($status, $assignable_statuses, true)) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'status_not_assignable', 'status' => $status), 409);
        }

        $order_hub  = !empty($order['hub_id'])  ? (int)$order['hub_id']  : 0;
        $order_city = !empty($order['city_id']) ? (int)$order['city_id'] : 0;

        $in_scope = false;
        if ($order_hub > 0 && !empty($allowed_hub_ids) && in_array($order_hub, $allowed_hub_ids, true)) $in_scope = true;
        if (!$in_scope && $order_city > 0 && !empty($allowed_city_ids) && in_array($order_city, $allowed_city_ids, true)) $in_scope = true;

        if (!$in_scope) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'forbidden_scope'), 403);
        }

        // Lock ops row
        $ops = $wpdb->get_row(
            $wpdb->prepare("SELECT order_id, driver_user_id, ops_status FROM {$ops_table} WHERE order_id = %d FOR UPDATE", $order_id),
            ARRAY_A
        );

        $ops_driver_user_id = $ops && !empty($ops['driver_user_id']) ? (int)$ops['driver_user_id'] : 0;
        $ops_status = $ops && isset($ops['ops_status']) ? (string)$ops['ops_status'] : '';

        // If another driver already has it
        if ($ops_driver_user_id > 0 && $ops_driver_user_id !== $driver_user_id && $ops_status !== 'unassigned') {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array(
                'reason' => 'already_assigned',
                'assigned_driver_user_id' => $ops_driver_user_id,
            ), 409);
        }

        // Idempotent: already assigned to you
        if ($ops_driver_user_id === $driver_user_id && $ops_status !== 'unassigned') {
            $wpdb->query('COMMIT');
            return knx_driver_ops__resp(true, array(
                'assigned' => false,
                'already_assigned' => true,
                'assigned_to_you' => true,
                'order_id' => $order_id,
                'driver_user_id' => $driver_user_id,
                'driver_profile_id' => $driver_profile_id,
            ));
        }

        // Upsert ops row
        $ops_data = array(
            'driver_user_id' => $driver_user_id,
            'assigned_by'    => $driver_user_id,
            'ops_status'     => 'assigned',
            'assigned_at'    => $now_gmt,
        );

        if ($ops_ts_col) $ops_data[$ops_ts_col] = $now_gmt;

        if ($ops) {
            $ok = $wpdb->update(
                $ops_table,
                $ops_data,
                array('order_id' => $order_id)
            );
            if ($ok === false) {
                $wpdb->query('ROLLBACK');
                return knx_driver_ops__resp(false, array('reason' => 'db_ops_update_failed'), 500);
            }
        } else {
            $ops_insert = array_merge(array('order_id' => $order_id), $ops_data);
            $ok = $wpdb->insert($ops_table, $ops_insert);
            if ($ok === false) {
                $wpdb->query('ROLLBACK');
                return knx_driver_ops__resp(false, array('reason' => 'db_ops_insert_failed'), 500);
            }
        }

        // Update order: set driver_id to DRIVER PROFILE ID (canonical linkage)
        $order_update = array('driver_id' => $driver_profile_id);
        if ($orders_ts_col) $order_update[$orders_ts_col] = $now_gmt;

        $ok_order = $wpdb->update($orders_table, $order_update, array('id' => $order_id));
        if ($ok_order === false) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'db_order_update_failed'), 500);
        }

        $wpdb->query('COMMIT');

        return knx_driver_ops__resp(true, array(
            'assigned' => true,
            'order_id' => $order_id,
            'driver_user_id' => $driver_user_id,
            'driver_profile_id' => $driver_profile_id,
            'ops_status' => 'assigned',
            'server_gmt' => gmdate('Y-m-d H:i:s'),
        ));

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return knx_driver_ops__resp(false, array('reason' => 'exception', 'message' => $e->getMessage()), 500);
    }
}
