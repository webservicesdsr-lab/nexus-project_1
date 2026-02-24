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
    global $wpdb;

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

    $statuses = $request->get_param('statuses');
    if (is_string($statuses) && trim($statuses) !== '') {
        $statuses = array_filter(array_map('trim', explode(',', $statuses)));
    }
    if (!is_array($statuses) || empty($statuses)) {
        // All states before picked_up are valid for assignment (after release)
        $statuses = array('confirmed','accepted_by_driver','accepted_by_hub','preparing','prepared');
    }
    $statuses = array_values(array_unique(array_map('strval', $statuses)));

    $orders_table = $wpdb->prefix . 'knx_orders';
    $driver_ops_table = $wpdb->prefix . 'knx_driver_ops';
    $hubs_table = $wpdb->prefix . 'knx_hubs';

    // Build WHERE clause
    $where = array();
    $params = array();

    // Status filter (DB canon)
    if (!empty($statuses)) {
        $status_ph = implode(',', array_fill(0, count($statuses), '%s'));
        $where[] = "o.status IN ($status_ph)";
        foreach ($statuses as $s) $params[] = (string)$s;
    }

    // Scope filter (hubs OR cities)
    $scope_parts = array();
    if (!empty($allowed_hub_ids)) {
        $hub_ph = implode(',', array_fill(0, count($allowed_hub_ids), '%d'));
        $scope_parts[] = "o.hub_id IN ($hub_ph)";
        foreach ($allowed_hub_ids as $hid) $params[] = (int)$hid;
    }
    if (!empty($allowed_city_ids)) {
        $city_ph = implode(',', array_fill(0, count($allowed_city_ids), '%d'));
        $scope_parts[] = "o.city_id IN ($city_ph)";
        foreach ($allowed_city_ids as $cid) $params[] = (int)$cid;
    }
    if (!empty($scope_parts)) {
        $where[] = '(' . implode(' OR ', $scope_parts) . ')';
    }

    // Available = unassigned (includes both NEW and RELEASED orders)
    $where[] = "(o.driver_id IS NULL OR o.driver_id = 0)";
    $where[] = "(COALESCE(dop.ops_status, 'unassigned') = 'unassigned')";

    // Payment gate
    $where[] = "LOWER(o.payment_status) = 'paid'";
    $where[] = "LOWER(o.status) <> 'pending_payment'";

    // No time filter for available orders (show all unassigned regardless of creation time)
    // This ensures released orders always appear

    $where_sql = implode(' AND ', $where);

    // Query with hub JOIN (provides hub_name, pickup_address for frontend)
    $sql = "
        SELECT
            o.id,
            o.order_number,
            o.hub_id,
            o.city_id,
            o.fulfillment_type,
            o.customer_name,
            o.customer_phone,
            o.customer_email,
            o.delivery_address,
            o.cart_snapshot,
            o.subtotal,
            o.tax_amount,
            o.delivery_fee,
            o.software_fee,
            o.tip_amount,
            o.discount_amount,
            o.total,
            o.status,
            o.payment_method,
            o.payment_status,
            o.created_at,
            o.updated_at,
            h.name AS hub_name_live,
            h.address AS pickup_address_live,
            h.latitude AS pickup_lat_live,
            h.longitude AS pickup_lng_live,
            COALESCE(dop.ops_status, 'unassigned') AS ops_status,
            dop.assigned_at,
            dop.driver_user_id,
            dop.assigned_by,
            dop.updated_at AS ops_updated_at
        FROM {$orders_table} o
        LEFT JOIN {$driver_ops_table} dop ON dop.order_id = o.id
        LEFT JOIN {$hubs_table} h ON h.id = o.hub_id
        WHERE {$where_sql}
        ORDER BY o.created_at DESC
        LIMIT %d OFFSET %d
    ";

    $params[] = $limit;
    $params[] = $offset;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if (!is_array($rows)) $rows = array();

    // Post-process: prefer snapshot hub (v5) if available, else fallback to live hub join
    foreach ($rows as &$row) {
        $snapshot = null;
        $snapshot_version = null;

        if (!empty($row['cart_snapshot'])) {
            $decoded = json_decode($row['cart_snapshot'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $snapshot = $decoded;
                $snapshot_version = isset($decoded['version']) ? $decoded['version'] : 'legacy';
            }
        }

        // Hub name and pickup address (snapshot > live)
        if ($snapshot_version === 'v5' && isset($snapshot['hub']) && is_array($snapshot['hub'])) {
            $hub = $snapshot['hub'];
            $row['hub_name'] = isset($hub['name']) ? $hub['name'] : null;
            $row['pickup_address_text'] = isset($hub['address']) ? (function_exists('knx_clean_driver_address') ? knx_clean_driver_address($hub['address']) : $hub['address']) : null;
            $row['pickup_lat'] = isset($hub['lat']) ? $hub['lat'] : null;
            $row['pickup_lng'] = isset($hub['lng']) ? $hub['lng'] : null;
            $row['address_source'] = 'snapshot';
        } elseif ($snapshot && isset($snapshot['hub_name'])) {
            // legacy flat snapshot
            $row['hub_name'] = $snapshot['hub_name'];
            $row['pickup_address_text'] = isset($snapshot['hub_address']) ? (function_exists('knx_clean_driver_address') ? knx_clean_driver_address($snapshot['hub_address']) : $snapshot['hub_address']) : null;
            $row['pickup_lat'] = isset($snapshot['hub_latitude']) ? $snapshot['hub_latitude'] : null;
            $row['pickup_lng'] = isset($snapshot['hub_longitude']) ? $snapshot['hub_longitude'] : null;
            $row['address_source'] = 'snapshot_legacy';
        } else {
            // fallback to live hub
            $row['hub_name'] = isset($row['hub_name_live']) ? $row['hub_name_live'] : null;
            $row['pickup_address_text'] = isset($row['pickup_address_live']) ? (function_exists('knx_clean_driver_address') ? knx_clean_driver_address($row['pickup_address_live']) : $row['pickup_address_live']) : null;
            $row['pickup_lat'] = isset($row['pickup_lat_live']) ? $row['pickup_lat_live'] : null;
            $row['pickup_lng'] = isset($row['pickup_lng_live']) ? $row['pickup_lng_live'] : null;
            $row['address_source'] = 'live';
        }

        // Clean delivery address for search
        if (!empty($row['delivery_address'])) {
            $row['delivery_address_text'] = function_exists('knx_clean_driver_address') 
                ? knx_clean_driver_address($row['delivery_address']) 
                : $row['delivery_address'];
        }

        // Remove heavy snapshot to keep response small
        unset($row['cart_snapshot']);
        unset($row['hub_name_live']);
        unset($row['pickup_address_live']);
        unset($row['pickup_lat_live']);
        unset($row['pickup_lng_live']);
    }
    unset($row);

    $meta = array(
        'limit' => $limit,
        'offset' => $offset,
        'statuses' => $statuses,
        'driver_user_id' => $driver_user_id,
        'driver_profile_id' => $driver_profile_id,
        'allowed_city_ids' => $allowed_city_ids,
        'allowed_hub_ids' => $allowed_hub_ids,
    );

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
    $hubs_table = $wpdb->prefix . 'knx_hubs';

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
            o.cart_snapshot,
            h.name AS hub_name_live,
            h.address AS pickup_address_live,
            h.latitude AS pickup_lat_live,
            h.longitude AS pickup_lng_live,
            o.created_at,
            o.updated_at
        FROM {$orders_table} o
        LEFT JOIN {$hubs_table} h ON h.id = o.hub_id
        WHERE {$sql_where}
        ORDER BY o.updated_at DESC, o.id DESC
        LIMIT %d OFFSET %d
    ";

    $args[] = $limit;
    $args[] = $offset;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    if (!is_array($rows)) $rows = array();

    // Post-process: prefer snapshot hub (v5) if available, else fallback to live hub join
    foreach ($rows as &$row) {
        $snapshot = null;
        $snapshot_version = null;

        if (!empty($row['cart_snapshot'])) {
            $decoded = json_decode($row['cart_snapshot'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $snapshot = $decoded;
                $snapshot_version = isset($decoded['version']) ? $decoded['version'] : 'legacy';
            }
        }

        if ($snapshot_version === 'v5' && isset($snapshot['hub']) && is_array($snapshot['hub'])) {
            $hub = $snapshot['hub'];
            $row['hub_name'] = isset($hub['name']) ? $hub['name'] : null;
            $row['pickup_address_text'] = isset($hub['address']) ? (function_exists('knx_clean_driver_address') ? knx_clean_driver_address($hub['address']) : $hub['address']) : null;
            $row['pickup_lat'] = isset($hub['lat']) ? $hub['lat'] : null;
            $row['pickup_lng'] = isset($hub['lng']) ? $hub['lng'] : null;
            $row['address_source'] = 'snapshot';
        } elseif ($snapshot && isset($snapshot['hub_name'])) {
            // legacy flat snapshot
            $row['hub_name'] = $snapshot['hub_name'];
            $row['pickup_address_text'] = isset($snapshot['hub_address']) ? (function_exists('knx_clean_driver_address') ? knx_clean_driver_address($snapshot['hub_address']) : $snapshot['hub_address']) : null;
            $row['pickup_lat'] = isset($snapshot['hub_latitude']) ? $snapshot['hub_latitude'] : null;
            $row['pickup_lng'] = isset($snapshot['hub_longitude']) ? $snapshot['hub_longitude'] : null;
            $row['address_source'] = 'snapshot_legacy';
        } else {
            // fallback to live hub
            $row['hub_name'] = isset($row['hub_name_live']) ? $row['hub_name_live'] : null;
            $row['pickup_address_text'] = isset($row['pickup_address_live']) ? (function_exists('knx_clean_driver_address') ? knx_clean_driver_address($row['pickup_address_live']) : $row['pickup_address_live']) : null;
            $row['pickup_lat'] = isset($row['pickup_lat_live']) ? $row['pickup_lat_live'] : null;
            $row['pickup_lng'] = isset($row['pickup_lng_live']) ? $row['pickup_lng_live'] : null;
            $row['address_source'] = 'live';
        }

        // Clean delivery address if present
        if (!empty($row['delivery_address'])) {
            if (function_exists('knx_clean_driver_address')) {
                $row['delivery_address_text'] = knx_clean_driver_address($row['delivery_address']);
            } else {
                $row['delivery_address_text'] = $row['delivery_address'];
            }
        }

        // remove heavy snapshot to keep response small
        unset($row['cart_snapshot']);
        unset($row['hub_name_live']);
        unset($row['pickup_address_live']);
        unset($row['pickup_lat_live']);
        unset($row['pickup_lng_live']);
    }
    unset($row);

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
 * Unassigns driver and reverts status to confirmed if accepted_by_driver (matches manager unassign logic).
 * Uses transaction + FOR UPDATE lock for atomicity (prevents race conditions).
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

    $order_id = (int)$request->get_param('id');
    if ($order_id <= 0) return knx_driver_ops__resp(false, array('reason' => 'invalid_order_id'), 400);

    $orders_table = $wpdb->prefix . 'knx_orders';
    $driver_ops_table = $wpdb->prefix . 'knx_driver_ops';
    $history_table = $wpdb->prefix . 'knx_order_status_history';
    $now = current_time('mysql');

    // Preflight check (no lock yet)
    $order = $wpdb->get_row(
        $wpdb->prepare("SELECT id, status, payment_status, driver_id, city_id, hub_id FROM {$orders_table} WHERE id = %d LIMIT 1", $order_id),
        ARRAY_A
    );
    if (!$order) return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);

    // Scope check
    $order_hub  = !empty($order['hub_id'])  ? (int)$order['hub_id']  : 0;
    $order_city = !empty($order['city_id']) ? (int)$order['city_id'] : 0;

    if (!knx_driver_ops__order_in_scope($order_city, $order_hub, $allowed_city_ids, $allowed_hub_ids)) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // Ownership check
    $order_driver = (int)($order['driver_id'] ?? 0);
    if ($order_driver <= 0 || $order_driver !== $driver_profile_id) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // Block release after picked_up (order is physically with driver)
    $status_before = strtolower((string)($order['status'] ?? ''));
    $no_release_statuses = array('picked_up', 'completed', 'cancelled');
    
    if (in_array($status_before, $no_release_statuses, true)) {
        return knx_driver_ops__resp(false, array(
            'reason' => 'cannot_release',
            'message' => 'Cannot release order after picked_up. Status: ' . $status_before,
            'status' => $status_before,
        ), 409);
    }

    // Start transaction (match manager unassign pattern)
    $wpdb->query('START TRANSACTION');

    try {
        // Lock order for update (prevents concurrent modifications)
        $locked = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status, payment_status, driver_id, city_id, hub_id
                 FROM {$orders_table}
                 WHERE id = %d
                 FOR UPDATE",
                $order_id
            ),
            ARRAY_A
        );

        if (!$locked) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'order_lock_failed'), 500);
        }

        // Recheck payment status under lock
        $ps_locked = strtolower((string)($locked['payment_status'] ?? ''));
        if ($ps_locked !== 'paid') {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'payment_not_paid'), 409);
        }

        $st_locked = strtolower((string)($locked['status'] ?? ''));
        if (in_array($st_locked, array('picked_up', 'completed', 'cancelled'), true)) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'cannot_release', 'status' => $st_locked), 409);
        }

        // Upsert knx_driver_ops to 'unassigned' (match manager pattern exactly)
        $existing_ops_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$driver_ops_table} WHERE order_id = %d LIMIT 1",
            $order_id
        ));

        if (!empty($existing_ops_id)) {
            // Update existing record to unassigned
            $ok_ops = $wpdb->update(
                $driver_ops_table,
                array(
                    'driver_user_id' => null,
                    'ops_status'     => 'unassigned',
                    'assigned_at'    => null,
                    'updated_at'     => $now,
                ),
                array('order_id' => $order_id),
                array('%s','%s','%s','%s'),
                array('%d')
            );
            if ($ok_ops === false) {
                $wpdb->query('ROLLBACK');
                return knx_driver_ops__resp(false, array('reason' => 'ops_update_failed'), 500);
            }
        } else {
            // Insert new unassigned record
            $ok_ops = $wpdb->insert(
                $driver_ops_table,
                array(
                    'order_id'       => $order_id,
                    'driver_user_id' => null,
                    'assigned_by'    => $driver_user_id,
                    'ops_status'     => 'unassigned',
                    'assigned_at'    => null,
                    'updated_at'     => $now,
                ),
                array('%d','%s','%d','%s','%s','%s')
            );
            if ($ok_ops === false) {
                $wpdb->query('ROLLBACK');
                return knx_driver_ops__resp(false, array('reason' => 'ops_insert_failed'), 500);
            }
        }

        // Status transition logic (match manager unassign)
        // If status is 'accepted_by_driver', revert to 'confirmed' (order goes back to waiting pool)
        $status_after = $st_locked;
        $did_status_change = false;

        if ($st_locked === 'accepted_by_driver') {
            $status_after = 'confirmed';
            $did_status_change = true;
        }

        // Update knx_orders (driver + optional status change)
        $update_data = array(
            'driver_id'  => null,
            'updated_at' => $now,
        );
        $update_fmt = array('%s', '%s');

        if ($did_status_change) {
            $update_data['status'] = $status_after;
            $update_fmt[] = '%s';
        }

        $ok_order = $wpdb->update(
            $orders_table,
            $update_data,
            array('id' => $order_id),
            $update_fmt,
            array('%d')
        );

        if ($ok_order === false) {
            $wpdb->query('ROLLBACK');
            return knx_driver_ops__resp(false, array('reason' => 'order_update_failed'), 500);
        }

        // Insert status history if status changed (audit trail)
        if ($did_status_change && $st_locked !== $status_after) {
            $ok_hist = $wpdb->insert(
                $history_table,
                array(
                    'order_id'   => $order_id,
                    'status'     => $status_after,
                    'changed_by' => $driver_user_id,
                    'created_at' => $now,
                ),
                array('%d','%s','%d','%s')
            );
            if ($ok_hist === false) {
                $wpdb->query('ROLLBACK');
                return knx_driver_ops__resp(false, array('reason' => 'history_insert_failed'), 500);
            }
        }

        $wpdb->query('COMMIT');

        return knx_driver_ops__resp(true, array(
            'order_id' => $order_id,
            'unassigned' => true,
            'status_before' => $st_locked,
            'status_after'  => $status_after,
            'status_changed' => (bool)$did_status_change,
            'server_time' => $now,
        ));

    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return knx_driver_ops__resp(false, array('reason' => 'release_failed', 'message' => 'Transaction error'), 500);
    }
}

/**
 * GET /wp-json/knx/v2/driver/orders/{id}
 * Driver-only, DB-canon only.
 * Fail-closed:
 * - Not found or not assigned => 404
 * - Out of scope => 404
 */
/**
 * GET /driver/orders/{id}
 *
 * Returns SAME rich structure as v1/ops/view-order (manager) so the
 * driver-view-order frontend can render 1:1 identical UX.
 *
 * Contract mirrors knx_ops_view_order():
 * {
 *   success, data: {
 *     order: {
 *       order_id, status, created_at, created_at_iso, created_age_seconds, created_human,
 *       city_id,
 *       restaurant:{name,phone,email,address,logo_url,location:{lat,lng,view_url}},
 *       customer:{name,phone,email},
 *       delivery:{method,address,time_slot},
 *       payment:{method,status},
 *       totals:{total,tip,quote},
 *       driver:{assigned,driver_id,name},
 *       location:{lat,lng,view_url},
 *       raw:{items:{items:[],source},notes},
 *       status_history:[{status,label,is_done,is_current,created_at,created_at_iso,changed_by,changed_by_label}]
 *     },
 *     meta:{role,generated_at,generated_at_iso}
 *   }
 * }
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
    $hubs_table    = $wpdb->prefix . 'knx_hubs';
    $users_table   = $wpdb->prefix . 'knx_users';
    $drivers_table = $wpdb->prefix . 'knx_drivers';
    $items_table   = $wpdb->prefix . 'knx_order_items';
    $history_table = $wpdb->prefix . 'knx_order_status_history';

    // ── Detect lat/lng columns (orders) ──
    $latlng = null;
    $select_latlng = '';
    $latlng_candidates = [
        ['lat' => 'delivery_lat', 'lng' => 'delivery_lng'],
        ['lat' => 'address_lat',  'lng' => 'address_lng'],
        ['lat' => 'lat',          'lng' => 'lng'],
    ];
    foreach ($latlng_candidates as $pair) {
        if (knx_driver_ops__table_has_column($orders_table, $pair['lat']) &&
            knx_driver_ops__table_has_column($orders_table, $pair['lng'])) {
            $latlng = $pair;
            $select_latlng = ", o.`{$pair['lat']}` AS delivery_lat, o.`{$pair['lng']}` AS delivery_lng";
            break;
        }
    }

    // ── Detect lat/lng columns (hubs) ──
    $hub_latlng = null;
    $select_hub_latlng = ', NULL AS hub_lat, NULL AS hub_lng';
    $hubs_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hubs_table));
    if (!empty($hubs_exists)) {
        $hub_candidates = [
            ['lat' => 'latitude',  'lng' => 'longitude'],
            ['lat' => 'lat',       'lng' => 'lng'],
            ['lat' => 'hub_lat',   'lng' => 'hub_lng'],
        ];
        foreach ($hub_candidates as $pair) {
            if (knx_driver_ops__table_has_column($hubs_table, $pair['lat']) &&
                knx_driver_ops__table_has_column($hubs_table, $pair['lng'])) {
                $hub_latlng = $pair;
                $select_hub_latlng = ", h.`{$pair['lat']}` AS hub_lat, h.`{$pair['lng']}` AS hub_lng";
                break;
            }
        }
    }

    // ── Main query: order + hub ──
    $sql = "
        SELECT
            o.id,
            o.city_id,
            o.hub_id,
            o.customer_id,
            o.customer_name,
            o.customer_phone,
            o.customer_email,
            o.fulfillment_type,
            o.delivery_address,
            o.delivery_address_id,
            o.payment_method,
            o.payment_status,
            o.subtotal,
            o.tax_amount,
            o.delivery_fee,
            o.software_fee,
            o.tip_amount,
            o.discount_amount,
            o.total,
            o.status,
            o.driver_id,
            o.order_number,
            o.notes,
            o.totals_snapshot,
            o.cart_snapshot,
            o.created_at,
            o.updated_at
            {$select_latlng},
            h.name      AS hub_name,
            h.phone     AS hub_phone,
            h.email     AS hub_email,
            h.address   AS hub_address,
            h.logo_url  AS hub_logo_url
            {$select_hub_latlng}
        FROM {$orders_table} o
        LEFT JOIN {$hubs_table} h ON o.hub_id = h.id
        WHERE o.id = %d
        LIMIT 1
    ";
    $row = $wpdb->get_row($wpdb->prepare($sql, $order_id));

    if (!$row) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // ── Payment gating ──
    $status = strtolower((string)($row->status ?? ''));
    $ps     = strtolower((string)($row->payment_status ?? ''));

    if ($status === 'pending_payment') {
        return knx_driver_ops__resp(false, array('reason' => 'order_pending_payment'), 409);
    }
    if (!knx_driver_ops__payment_is_paid($ps)) {
        return knx_driver_ops__resp(false, array('reason' => 'payment_not_paid'), 409);
    }

    // ── Ownership: must be assigned to this driver ──
    $order_driver = !empty($row->driver_id) ? (int)$row->driver_id : 0;
    if ($order_driver <= 0 || $order_driver !== $driver_profile_id) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // ── Scope ──
    $order_hub  = !empty($row->hub_id)  ? (int)$row->hub_id  : 0;
    $order_city = !empty($row->city_id) ? (int)$row->city_id : 0;

    if (!knx_driver_ops__order_in_scope($order_city, $order_hub, $allowed_city_ids, $allowed_hub_ids)) {
        return knx_driver_ops__resp(false, array('reason' => 'order_not_found'), 404);
    }

    // ── Restaurant (hub) ──
    $hub_lat = (isset($row->hub_lat) && $row->hub_lat !== null) ? (float)$row->hub_lat : null;
    $hub_lng = (isset($row->hub_lng) && $row->hub_lng !== null) ? (float)$row->hub_lng : null;
    if ($hub_lat === 0.0 && $hub_lng === 0.0) { $hub_lat = null; $hub_lng = null; }

    $hub_view_url = null;
    if ($hub_lat !== null && $hub_lng !== null) {
        $hub_view_url = 'https://www.google.com/maps?q=' . rawurlencode($hub_lat . ',' . $hub_lng);
    }

    $restaurant = [
        'name'     => (string)($row->hub_name ?? ''),
        'phone'    => isset($row->hub_phone) ? (string)$row->hub_phone : null,
        'email'    => isset($row->hub_email) ? (string)$row->hub_email : null,
        'address'  => isset($row->hub_address) ? (string)$row->hub_address : null,
        'logo_url' => isset($row->hub_logo_url) ? (string)$row->hub_logo_url : null,
        'location' => [
            'lat'      => $hub_lat,
            'lng'      => $hub_lng,
            'view_url' => $hub_view_url,
        ],
    ];

    // ── Customer (SSOT = orders columns; fallback = knx_users) ──
    $customer_id    = isset($row->customer_id) ? (int)$row->customer_id : 0;
    $customer_name  = isset($row->customer_name)  ? trim((string)$row->customer_name)  : '';
    $customer_phone = isset($row->customer_phone) ? trim((string)$row->customer_phone) : '';
    $customer_email = isset($row->customer_email) ? trim((string)$row->customer_email) : '';

    if ($customer_id > 0 && ($customer_name === '' || $customer_phone === '' || $customer_email === '')) {
        $u_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $users_table));
        if (!empty($u_exists)) {
            $u = $wpdb->get_row($wpdb->prepare(
                "SELECT name, phone, email, username FROM {$users_table} WHERE id = %d LIMIT 1",
                $customer_id
            ));
            if ($u) {
                if ($customer_name === '')  { $nm = trim((string)($u->name ?? '')); if ($nm === '') $nm = trim((string)($u->username ?? '')); if ($nm !== '') $customer_name = $nm; }
                if ($customer_phone === '') { $ph = trim((string)($u->phone ?? '')); if ($ph !== '') $customer_phone = $ph; }
                if ($customer_email === '') { $em = trim((string)($u->email ?? '')); if ($em !== '') $customer_email = $em; }
            }
        }
    }

    $customer = [
        'name'  => ($customer_name  !== '' ? $customer_name  : null),
        'phone' => ($customer_phone !== '' ? $customer_phone : null),
        'email' => ($customer_email !== '' ? $customer_email : null),
    ];

    // ── Delivery ──
    $delivery_method = isset($row->fulfillment_type) ? (string)$row->fulfillment_type : 'delivery';
    if ($delivery_method !== 'delivery' && $delivery_method !== 'pickup') $delivery_method = 'delivery';

    $delivery = [
        'method'    => $delivery_method,
        'address'   => isset($row->delivery_address) ? (string)$row->delivery_address : null,
        'time_slot' => null,
    ];

    // ── Payment ──
    $payment = [
        'method' => isset($row->payment_method) ? (string)$row->payment_method : null,
        'status' => isset($row->payment_status) ? (string)$row->payment_status : null,
    ];

    // ── Totals ──
    $totals_snapshot = null;
    if (isset($row->totals_snapshot) && is_string($row->totals_snapshot) && trim($row->totals_snapshot) !== '') {
        $decoded = json_decode($row->totals_snapshot, true);
        if (json_last_error() === JSON_ERROR_NONE) $totals_snapshot = $decoded;
    }

    $quote = null;
    if (is_array($totals_snapshot)) {
        $quote = [
            'taxes_and_fees' => isset($totals_snapshot['taxes_and_fees']) ? (float)$totals_snapshot['taxes_and_fees'] : (isset($row->tax_amount) ? (float)$row->tax_amount : null),
            'delivery_fee'   => isset($totals_snapshot['delivery_fee'])   ? (float)$totals_snapshot['delivery_fee']   : (isset($row->delivery_fee) ? (float)$row->delivery_fee : null),
            'discount'       => isset($totals_snapshot['discount'])       ? (float)$totals_snapshot['discount']       : (isset($row->discount_amount) ? (float)$row->discount_amount : null),
        ];
    } else {
        $quote = [
            'taxes_and_fees' => isset($row->tax_amount) ? (float)$row->tax_amount : null,
            'delivery_fee'   => isset($row->delivery_fee) ? (float)$row->delivery_fee : null,
            'discount'       => isset($row->discount_amount) ? (float)$row->discount_amount : null,
        ];
    }

    $totals = [
        'total' => (float)($row->total ?? 0),
        'tip'   => (float)($row->tip_amount ?? 0),
        'quote' => $quote,
    ];

    // ── Driver info ──
    $driver_id_val = isset($row->driver_id) ? (int)$row->driver_id : 0;
    $driver_name = null;

    $drivers_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drivers_table));
    if ($driver_id_val > 0 && !empty($drivers_exists)) {
        $d = $wpdb->get_row($wpdb->prepare(
            "SELECT full_name, user_id FROM {$drivers_table} WHERE id = %d LIMIT 1",
            $driver_id_val
        ));
        if ($d && isset($d->full_name) && trim((string)$d->full_name) !== '') {
            $driver_name = trim((string)$d->full_name);
        } elseif ($d && isset($d->user_id) && (int)$d->user_id > 0) {
            $u = $wpdb->get_row($wpdb->prepare("SELECT name, username FROM {$users_table} WHERE id = %d LIMIT 1", (int)$d->user_id));
            if ($u) { $nm = trim((string)($u->name ?? '')); if ($nm === '') $nm = trim((string)($u->username ?? '')); if ($nm !== '') $driver_name = $nm; }
        }
    }

    $driver = [
        'assigned'  => ($driver_id_val > 0),
        'driver_id' => ($driver_id_val > 0 ? $driver_id_val : null),
        'name'      => ($driver_name !== '' ? $driver_name : null),
    ];

    // ── Location (delivery coords) ──
    $lat = null; $lng = null;
    if ($latlng) {
        $lat = isset($row->delivery_lat) ? (float)$row->delivery_lat : null;
        $lng = isset($row->delivery_lng) ? (float)$row->delivery_lng : null;
    }

    $view_url = null;
    if ($lat !== null && $lng !== null && ($lat != 0.0 || $lng != 0.0)) {
        $view_url = 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);
    }

    $location = [
        'lat'      => $lat,
        'lng'      => $lng,
        'view_url' => $view_url,
    ];

    // ── Raw items (prefer order_items table, fallback cart_snapshot) ──
    $raw_items = [];
    $items_source = 'none';

    $items_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $items_table));
    if (!empty($items_exists)) {
        $items_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT name_snapshot, image_snapshot, quantity, unit_price, line_total, modifiers_json
             FROM {$items_table}
             WHERE order_id = %d
             ORDER BY id ASC",
            $order_id
        ));

        foreach ((array)$items_rows as $it) {
            $mods = null;
            if (isset($it->modifiers_json) && is_string($it->modifiers_json) && trim($it->modifiers_json) !== '') {
                $decoded = json_decode((string)$it->modifiers_json, true);
                if (json_last_error() === JSON_ERROR_NONE) $mods = $decoded;
            }
            $raw_items[] = [
                'name_snapshot'  => (string)($it->name_snapshot ?? ''),
                'image_snapshot' => isset($it->image_snapshot) ? (string)$it->image_snapshot : null,
                'quantity'       => (int)($it->quantity ?? 1),
                'unit_price'     => (float)($it->unit_price ?? 0),
                'line_total'     => (float)($it->line_total ?? 0),
                'modifiers'      => $mods,
            ];
        }
        $items_source = 'order_items';
    }

    if (empty($raw_items)) {
        $cart_snapshot = null;
        if (isset($row->cart_snapshot) && is_string($row->cart_snapshot) && trim($row->cart_snapshot) !== '') {
            $decoded = json_decode($row->cart_snapshot, true);
            if (json_last_error() === JSON_ERROR_NONE) $cart_snapshot = $decoded;
        }
        if (is_array($cart_snapshot) && isset($cart_snapshot['items']) && is_array($cart_snapshot['items'])) {
            foreach ($cart_snapshot['items'] as $it) {
                if (!is_array($it)) continue;
                $raw_items[] = [
                    'name_snapshot'  => (string)($it['name_snapshot'] ?? ''),
                    'image_snapshot' => isset($it['image_snapshot']) ? (string)$it['image_snapshot'] : null,
                    'quantity'       => (int)($it['quantity'] ?? 1),
                    'unit_price'     => (float)($it['unit_price'] ?? 0),
                    'line_total'     => (float)($it['line_total'] ?? 0),
                    'modifiers'      => isset($it['modifiers']) ? $it['modifiers'] : null,
                ];
            }
            $items_source = 'cart_snapshot';
        }
    }

    // Compute subtotal from items
    $subtotal = null;
    if (isset($row->subtotal) && $row->subtotal !== null) {
        $subtotal = (float)$row->subtotal;
    } elseif (!empty($raw_items)) {
        $subtotal = 0.0;
        foreach ($raw_items as $it) { $subtotal += (float)($it['line_total'] ?? 0); }
    }

    $raw = [
        'items' => [
            'items'    => $raw_items,
            'source'   => $items_source,
            'subtotal' => $subtotal,
        ],
        'notes' => isset($row->notes) ? $row->notes : null,
    ];

    // ── Status history (canon timeline — reuse manager helper if available) ──
    $hist_rows = [];
    $hist_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table));
    if (!empty($hist_exists)) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, changed_by, created_at
             FROM {$history_table}
             WHERE order_id = %d
             ORDER BY created_at ASC, id ASC",
            $order_id
        ), ARRAY_A);

        foreach ((array)$rows as $h) {
            $hist_rows[] = [
                'status'     => (string)($h['status'] ?? ''),
                'changed_by' => isset($h['changed_by']) ? (int)$h['changed_by'] : null,
                'created_at' => (string)($h['created_at'] ?? ''),
            ];
        }
    }

    // Build canon timeline (reuse manager's builder if loaded)
    $timeline = [];
    if (function_exists('knx_ops_view_order_build_timeline')) {
        $timeline = knx_ops_view_order_build_timeline(
            (string)($row->status ?? ''),
            (string)($row->created_at ?? current_time('mysql')),
            $hist_rows
        );
    } else {
        // Inline fallback: raw history with labels
        foreach ($hist_rows as $h) {
            $st = strtolower(trim((string)($h['status'] ?? '')));
            if ($st === '' || $st === 'pending_payment') continue;
            $timeline[] = [
                'status'           => $st,
                'label'            => ucwords(str_replace('_', ' ', $st)),
                'is_done'          => true,
                'is_current'       => ($st === strtolower(trim((string)($row->status ?? '')))),
                'created_at'       => $h['created_at'],
                'created_at_iso'   => $h['created_at'] ? date('c', strtotime($h['created_at'])) : null,
                'changed_by'       => $h['changed_by'],
                'changed_by_label' => null,
            ];
        }
    }

    // ── Created fields ──
    $created_at = (string)($row->created_at ?? current_time('mysql'));
    $created_ts = strtotime($created_at);
    $now_ts     = (int)current_time('timestamp');
    $age_seconds = ($created_ts > 0) ? max(0, $now_ts - $created_ts) : 0;
    $created_human = '';
    if ($age_seconds <= 60) $created_human = 'Just now';
    elseif (function_exists('human_time_diff')) $created_human = human_time_diff($created_ts, $now_ts) . ' ago';
    else $created_human = max(1, (int)floor($age_seconds / 60)) . ' min ago';

    // ── Assemble order object (1:1 with manager contract) ──
    $order = [
        'order_id'            => (int)$row->id,
        'order_number'        => isset($row->order_number) ? (string)$row->order_number : (string)$row->id,
        'status'              => (string)($row->status ?? ''),
        'created_at'          => $created_at,
        'created_at_iso'      => date('c', strtotime($created_at)),
        'created_age_seconds' => (int)$age_seconds,
        'created_human'       => (string)$created_human,

        'city_id'             => isset($row->city_id) ? (int)$row->city_id : null,

        'restaurant'          => $restaurant,
        'customer'            => $customer,
        'delivery'            => $delivery,
        'payment'             => $payment,
        'totals'              => $totals,
        'driver'              => $driver,
        'location'            => $location,
        'raw'                 => $raw,

        'status_history'      => $timeline,
    ];

    $now = current_time('mysql');

    return knx_driver_ops__resp(true, [
        'order' => $order,
        'meta'  => [
            'role'             => 'driver',
            'driver_user_id'   => $driver_user_id,
            'driver_profile_id'=> $driver_profile_id,
            'generated_at'     => $now,
            'generated_at_iso' => date('c', strtotime($now)),
        ],
    ]);
}