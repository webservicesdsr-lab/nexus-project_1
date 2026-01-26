<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — OPS Orders MVP (v1.2) — Operational SSOT
 * ----------------------------------------------------------
 * SSOT (OPS pipeline): knx_driver_ops
 * Details: JOIN knx_orders (read-only details)
 *
 * Endpoints (roles: super_admin, manager):
 * - GET  /knx/v2/ops/orders/list
 * - POST /knx/v2/ops/orders/assign
 * - POST /knx/v2/ops/orders/unassign
 * - POST /knx/v2/ops/orders/cancel
 * - POST /knx/v2/ops/orders/force-status (super_admin only)
 * - GET  /knx/v2/ops/drivers/active   (includes availability status when table exists)
 *
 * Fixes in v1.2:
 * - Orders list no longer depends on pipeline row existing (LEFT JOIN + default ops_status).
 * - Auto-seeds missing knx_driver_ops rows for listed orders (idempotent).
 * - Drivers endpoint joins availability safely (no d.user_id when column missing).
 * ==========================================================
 */

/**
 * Load Driver OPS Sync engine (SSOT) - fail-closed integration point.
 * If engine functions exist, we use them as authority for writes.
 */
if (defined('KNX_PATH') && file_exists(KNX_PATH . 'inc/core/functions/knx-driver-ops-sync.php')) {
    require_once KNX_PATH . 'inc/core/functions/knx-driver-ops-sync.php';
}

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/ops/orders/list', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_ops_orders_list'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/ops/orders/assign', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_ops_orders_assign'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/ops/orders/unassign', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_ops_orders_unassign'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/ops/orders/cancel', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_ops_orders_cancel'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    // super_admin only
    register_rest_route('knx/v2', '/ops/orders/force-status', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_ops_orders_force_status'),
        'permission_callback' => knx_rest_permission_roles(['super_admin']),
    ]);

    register_rest_route('knx/v2', '/ops/drivers/active', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_ops_drivers_active'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Table resolver helpers (fail-closed but flexible).
 */
function knx_v2_ops_table($key, $fallback) {
    global $wpdb;
    $table = $wpdb->prefix . $fallback;
    if (function_exists('knx_table')) {
        $maybe = knx_table($key);
        if (is_string($maybe) && $maybe !== '') {
            $table = $maybe;
        }
    }
    return $table;
}

function knx_v2_ops_orders_table() {
    return knx_v2_ops_table('orders', 'knx_orders');
}

function knx_v2_ops_drivers_table() {
    return knx_v2_ops_table('drivers', 'knx_drivers');
}

function knx_v2_ops_driver_ops_table() {
    return knx_v2_ops_table('driver_ops', 'knx_driver_ops');
}

function knx_v2_ops_users_table() {
    return knx_v2_ops_table('users', 'knx_users');
}

function knx_v2_ops_availability_table() {
    return knx_v2_ops_table('driver_availability', 'knx_driver_availability');
}

/**
 * Detect columns safely (cached per request).
 */
function knx_v2_ops_get_columns($table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    global $wpdb;
    $cols = [];
    $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
    if ($rows) {
        foreach ($rows as $r) {
            $cols[strtolower((string) $r->Field)] = true;
        }
    }
    $cache[$table] = $cols;
    return $cols;
}

/**
 * Find a column from a whitelist of candidates.
 */
function knx_v2_ops_pick_col($cols_map, $candidates) {
    foreach ($candidates as $c) {
        $k = strtolower($c);
        if (isset($cols_map[$k])) return $c;
    }
    return '';
}

/**
 * Canonical OPS nonce actions (module-level).
 */
function knx_v2_ops_verify_nonce($nonce, $action) {
    $nonce = is_string($nonce) ? $nonce : '';
    return (bool) wp_verify_nonce($nonce, $action);
}

/**
 * Check if a table exists (exact match).
 */
function knx_v2_ops_table_exists($table_name) {
    global $wpdb;
    $pattern = $wpdb->esc_like($table_name);
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pattern));
    return (!empty($found) && strtolower((string)$found) === strtolower((string)$table_name));
}

/**
 * List active drivers.
 *
 * - Uses knx_drivers when available (status='active').
 * - Optionally joins availability table safely (no assumptions about d.user_id).
 * - Returns availability_status when table exists; UI can filter to 'on'.
 */
function knx_v2_ops_drivers_active(WP_REST_Request $req) {
    global $wpdb;

    $drivers = knx_v2_ops_drivers_table();
    $out = [];

    if (knx_v2_ops_table_exists($drivers)) {
        $cols = knx_v2_ops_get_columns($drivers);

        $id_col     = knx_v2_ops_pick_col($cols, ['id']); // internal id always preferred here
        $user_id_col = knx_v2_ops_pick_col($cols, ['user_id']); // optional
        $name_col   = knx_v2_ops_pick_col($cols, ['full_name', 'name']);
        $email_col  = knx_v2_ops_pick_col($cols, ['email']);
        $phone_col  = knx_v2_ops_pick_col($cols, ['phone']);
        $st_col     = knx_v2_ops_pick_col($cols, ['status']);

        $availability = knx_v2_ops_availability_table();
        $has_av = knx_v2_ops_table_exists($availability);

        if ($id_col && $name_col && $st_col) {
            // Decide how availability joins to drivers:
            // - If drivers has user_id column, join on that.
            // - Otherwise join on drivers.id (internal id).
            $join_key = ($has_av && $user_id_col) ? "d.{$user_id_col}" : "d.{$id_col}";

            $select_parts = [];
            $select_parts[] = "d.{$id_col} AS internal_id";
            $select_parts[] = ($user_id_col ? "d.{$user_id_col} AS user_id" : "0 AS user_id");
            $select_parts[] = "d.{$name_col} AS full_name";
            $select_parts[] = ($email_col ? "d.{$email_col} AS email" : "'' AS email");
            $select_parts[] = ($phone_col ? "d.{$phone_col} AS phone" : "'' AS phone");
            $select_parts[] = "d.{$st_col} AS status";

            if ($has_av) {
                $select_parts[] = "a.status AS availability_status";
                $select_parts[] = "a.updated_at AS availability_updated_at";
            } else {
                $select_parts[] = "'' AS availability_status";
                $select_parts[] = "'' AS availability_updated_at";
            }

            $sql = "SELECT " . implode(', ', $select_parts) . "
                    FROM {$drivers} d " .
                    ($has_av ? "LEFT JOIN {$availability} a ON a.driver_user_id = {$join_key} " : "") .
                    "WHERE d.{$st_col} = 'active'
                    ORDER BY d.{$name_col} ASC";

            $rows = $wpdb->get_results($sql);
            if ($rows) {
                foreach ($rows as $r) {
                    $out[] = [
                        'id' => (int)($r->internal_id ?? 0),
                        'user_id' => (int)($r->user_id ?? 0),
                        'internal_id' => (int)($r->internal_id ?? 0),
                        'full_name' => (string)($r->full_name ?? ''),
                        'email' => (string)($r->email ?? ''),
                        'phone' => (string)($r->phone ?? ''),
                        'status' => (string)($r->status ?? ''),
                        'availability_status' => (string)($r->availability_status ?? ''),
                        'availability_updated_at' => (string)($r->availability_updated_at ?? ''),
                    ];
                }
            }
        }
    }

    // Minimal fallback: include current session user (custom knx session)
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $me_id = $session && isset($session->user_id) ? (int) $session->user_id : 0;
    if ($me_id > 0) {
        $me_name = 'Current User';
        $me_email = '';
        // Try to read from knx_users table if available
        $users_table = knx_v2_ops_users_table();
        if (knx_v2_ops_table_exists($users_table)) {
            $ucols = knx_v2_ops_get_columns($users_table);
            $name_col = knx_v2_ops_pick_col($ucols, ['display_name', 'full_name', 'name', 'user_login']);
            $email_col = knx_v2_ops_pick_col($ucols, ['email', 'user_email']);
            if ($name_col) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT {$name_col} AS name, " . ($email_col ? "{$email_col} AS email" : "'' AS email") . " FROM {$users_table} WHERE id = %d LIMIT 1",
                    $me_id
                ));
                if ($row) {
                    $me_name = (string)($row->name ?? $me_name);
                    $me_email = (string)($row->email ?? '');
                }
            }
        }

        $already = false;
        foreach ($out as $d) {
            if ((int)($d['id'] ?? 0) === $me_id) { $already = true; break; }
        }
        if (!$already) {
            array_unshift($out, [
                'id' => $me_id,
                'user_id' => $me_id,
                'internal_id' => $me_id,
                'full_name' => $me_name,
                'email' => $me_email,
                'phone' => '',
                'status' => 'active',
                'availability_status' => '',
                'availability_updated_at' => '',
            ]);
        }
    }

    return knx_rest_response(true, 'Active drivers', [
        'drivers' => $out,
    ]);
}

/**
 * Orders list (OPS scope) — OPERATIONAL SSOT
 *
 * Fix: show orders even if pipeline row does not exist.
 * Strategy:
 * - Drive the list from orders (o)
 * - LEFT JOIN driver_ops (dop)
 * - Default ops_status to 'unassigned' when dop is missing
 * - Auto-seed missing pipeline rows for the listed page (idempotent)
 *
 * Query params:
 * - status: order status (from orders table when possible)
 * - ops_status: operational status (from driver_ops)
 * - q: numeric order id
 * - page, per_page
 */
function knx_v2_ops_orders_list(WP_REST_Request $req) {
    global $wpdb;

    $ops_table    = knx_v2_ops_driver_ops_table();
    $orders_table = knx_v2_ops_orders_table();

    if (!knx_v2_ops_table_exists($orders_table)) {
        return knx_rest_response(false, 'Orders table missing.', null, 500);
    }

    // Detect columns in orders table for JOIN / output
    $orders_cols = knx_v2_ops_get_columns($orders_table);
    $oid_col     = knx_v2_ops_pick_col($orders_cols, ['id']);
    $status_col  = knx_v2_ops_pick_col($orders_cols, ['status', 'order_status']);
    $created_col = knx_v2_ops_pick_col($orders_cols, ['created_at', 'created']);
    $updated_col = knx_v2_ops_pick_col($orders_cols, ['updated_at', 'updated']);
    $hub_col     = knx_v2_ops_pick_col($orders_cols, ['hub_id']);
    $total_col   = knx_v2_ops_pick_col($orders_cols, ['total', 'grand_total', 'total_amount']);

    if (!$oid_col) {
        return knx_rest_response(false, 'Orders table missing id column.', null, 500);
    }

    $has_ops = knx_v2_ops_table_exists($ops_table);

    // Pagination
    $page     = max(1, (int) $req->get_param('page'));
    $per_page = max(1, min(100, (int) ($req->get_param('per_page') ?: 20)));
    $offset   = ($page - 1) * $per_page;

    // Filters
    $q = sanitize_text_field((string) $req->get_param('q'));
    $status_filter = sanitize_text_field((string) $req->get_param('status'));
    $ops_status_filter = sanitize_text_field((string) $req->get_param('ops_status'));

    $where = ['1=1'];
    $vals  = [];

    if ($q !== '' && ctype_digit($q)) {
        $where[] = "o.{$oid_col} = %d";
        $vals[]  = (int) $q;
    }

    if ($status_filter !== '' && $status_col) {
        $where[] = "o.{$status_col} = %s";
        $vals[]  = $status_filter;
    }

    if ($ops_status_filter !== '' && $has_ops) {
        // If ops table exists, filter by dop.ops_status; if dop missing, treat as 'unassigned'
        if ($ops_status_filter === 'unassigned') {
            $where[] = "(dop.ops_status = 'unassigned' OR dop.ops_status IS NULL)";
        } else {
            $where[] = "dop.ops_status = %s";
            $vals[]  = $ops_status_filter;
        }
    }

    $where_sql = implode(' AND ', $where);

    // Count (LEFT JOIN if ops exists)
    $count_sql = "SELECT COUNT(*)
                  FROM {$orders_table} o " .
                  ($has_ops ? "LEFT JOIN {$ops_table} dop ON dop.order_id = o.{$oid_col} " : "") . "
                  WHERE {$where_sql}";
    $count_sql = $vals ? $wpdb->prepare($count_sql, ...$vals) : $count_sql;
    $total = (int) $wpdb->get_var($count_sql);

    // Main query
    $select = [];
    $select[] = "o.{$oid_col} AS id";
    $select[] = ($status_col ? "o.{$status_col} AS order_status" : "'' AS order_status");
    $select[] = ($created_col ? "o.{$created_col} AS created_at" : "'' AS created_at");
    $select[] = ($updated_col ? "o.{$updated_col} AS order_updated_at" : "'' AS order_updated_at");
    $select[] = ($hub_col ? "o.{$hub_col} AS hub_id" : "0 AS hub_id");
    $select[] = ($total_col ? "o.{$total_col} AS total" : "NULL AS total");

    if ($has_ops) {
        $select[] = "dop.ops_status AS ops_status";
        $select[] = "dop.driver_user_id AS driver_id";
        $select[] = "dop.assigned_at";
        $select[] = "dop.updated_at AS ops_updated_at";
    } else {
        $select[] = "'' AS ops_status";
        $select[] = "0 AS driver_id";
        $select[] = "'' AS assigned_at";
        $select[] = "'' AS ops_updated_at";
    }

    $order_by = $has_ops ? "COALESCE(dop.updated_at, o." . ($updated_col ?: $created_col ?: $oid_col) . ") DESC" : "o." . ($updated_col ?: $created_col ?: $oid_col) . " DESC";

    $sql = "SELECT " . implode(",\n       ", $select) . "
            FROM {$orders_table} o " .
            ($has_ops ? "LEFT JOIN {$ops_table} dop ON dop.order_id = o.{$oid_col} " : "") . "
            WHERE {$where_sql}
            ORDER BY {$order_by}
            LIMIT %d OFFSET %d";

    $query_vals = array_merge($vals, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$query_vals));

    // Auto-seed missing pipeline rows for this page (idempotent) when ops table exists
    $missing_ids = [];
    if ($has_ops && $rows) {
        foreach ($rows as $r) {
            // If LEFT JOIN produced NULL ops_status AND NULL driver_id, we consider it missing
            $ops_status_val = isset($r->ops_status) ? (string)$r->ops_status : '';
            $driver_val = isset($r->driver_id) ? (int)$r->driver_id : 0;

            // If ops_status is empty and driver_id is 0, dop likely missing
            if ($ops_status_val === '' && $driver_val === 0) {
                $oid = (int)($r->id ?? 0);
                if ($oid > 0) {
                    $missing_ids[] = $oid;
                }
            }
        }
    }

    if ($has_ops && !empty($missing_ids)) {
        // Only seed for non-terminal orders to keep pipeline clean
        $terminal = ['cancelled', 'canceled', 'completed', 'delivered', 'refunded'];
        $seed_ids = [];

        foreach ($rows as $r) {
            $oid = (int)($r->id ?? 0);
            if ($oid <= 0) continue;
            if (!in_array($oid, $missing_ids, true)) continue;

            $st = strtolower((string)($r->order_status ?? ''));
            if ($st !== '' && in_array($st, $terminal, true)) {
                continue;
            }
            $seed_ids[] = $oid;
        }

        if (!empty($seed_ids)) {
            $now = current_time('mysql');
            $values_sql = [];
            $values_args = [];
            foreach ($seed_ids as $oid) {
                // order_id, driver_user_id, ops_status, assigned_by, assigned_at, updated_at
                $values_sql[] = "(%d, NULL, 'unassigned', NULL, NULL, %s)";
                $values_args[] = $oid;
                $values_args[] = $now;
            }

            // Attempt bulk insert; ignore duplicates via ON DUPLICATE KEY UPDATE
            $ins_sql = "INSERT INTO {$ops_table} (order_id, driver_user_id, ops_status, assigned_by, assigned_at, updated_at)
                        VALUES " . implode(", ", $values_sql) . "
                        ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)";
            $wpdb->query($wpdb->prepare($ins_sql, ...$values_args));
        }
    }

    // Enrich driver_name using knx_users (custom users table)
    $out = [];
    if ($rows) {
        foreach ($rows as $r) {
            $did = (int)($r->driver_id ?? 0);
            $dname = '';

            // If dop missing, default ops_status to 'unassigned'
            $ops_status_val = isset($r->ops_status) ? (string)$r->ops_status : '';
            if ($has_ops && $ops_status_val === '') {
                $ops_status_val = 'unassigned';
            }

            if ($did > 0) {
                $users_table = knx_v2_ops_users_table();
                if (knx_v2_ops_table_exists($users_table)) {
                    $ucols = knx_v2_ops_get_columns($users_table);
                    $name_col = knx_v2_ops_pick_col($ucols, ['display_name', 'full_name', 'name', 'user_login']);
                    if ($name_col) {
                        $rowu = $wpdb->get_row($wpdb->prepare("SELECT {$name_col} AS name FROM {$users_table} WHERE id = %d LIMIT 1", $did));
                        if ($rowu) $dname = (string)($rowu->name ?? '');
                    }
                }
            }

            $out[] = [
                'id' => (int)($r->id ?? 0),
                'status' => (string)($r->order_status ?? ''),          // canonical order status
                'ops_status' => $ops_status_val,                       // operational status
                'created_at' => (string)($r->created_at ?? ''),
                'updated_at' => (string)($r->ops_updated_at ?? ($r->order_updated_at ?? '')),
                'hub_id' => (int)($r->hub_id ?? 0),
                'driver_id' => $did,
                'driver_name' => $dname,
                'total' => $r->total,
            ];
        }
    }

    return knx_rest_response(true, 'OPS orders list (pipeline)', [
        'orders' => $out,
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total ? (int) ceil($total / $per_page) : 0,
        ],
    ]);
}

/**
 * Assign/Reassign driver (SSOT: knx_driver_ops)
 * JSON: { order_id, driver_id?, knx_nonce }
 *
 * Notes:
 * - If driver_id is missing/0, self-assign current user.
 * - Does NOT depend on knx_orders.status or knx_orders.driver_id column.
 */
function knx_v2_ops_orders_assign(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }

    $nonce = sanitize_text_field((string) ($body['knx_nonce'] ?? ''));
    if (!knx_v2_ops_verify_nonce($nonce, 'knx_ops_assign_driver_nonce')) {
        return knx_rest_response(false, 'Invalid nonce.', null, 403);
    }

    $order_id  = (int) ($body['order_id'] ?? 0);
    $driver_id = (int) ($body['driver_id'] ?? 0);

    // Use custom session for actor/implicit self-assign
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $session_user_id = $session && isset($session->user_id) ? (int) $session->user_id : 0;
    if ($driver_id <= 0) {
        $driver_id = $session_user_id; // self-assign using knx session user id
    }

    if ($order_id <= 0 || $driver_id <= 0) {
        return knx_rest_response(false, 'order_id and driver_id are required.', null, 400);
    }

    // Disallow assigning if order already archived in history
    $history_table = knx_v2_ops_table('driver_orders_history', 'knx_driver_orders_history');
    if (knx_v2_ops_table_exists($history_table)) {
        $in_hist = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$history_table} WHERE order_id = %d", $order_id));
        if ($in_hist > 0) {
            return knx_rest_response(false, 'Cannot assign: order archived', ['order_id' => $order_id], 409);
        }
    }

    $ops_table    = knx_v2_ops_driver_ops_table();
    $orders_table = knx_v2_ops_orders_table();

    if (!knx_v2_ops_table_exists($ops_table)) {
        return knx_rest_response(false, 'Driver ops table missing. Pipeline not initialized.', null, 500);
    }
    if (!knx_v2_ops_table_exists($orders_table)) {
        return knx_rest_response(false, 'Orders table missing.', null, 500);
    }

    // Ensure order exists in orders table
    $orders_cols = knx_v2_ops_get_columns($orders_table);
    $oid_col     = knx_v2_ops_pick_col($orders_cols, ['id']);
    if (!$oid_col) {
        return knx_rest_response(false, 'Orders table missing id column.', null, 500);
    }

    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$orders_table} WHERE {$oid_col} = %d",
        $order_id
    ));
    if (!$exists) {
        return knx_rest_response(false, 'Order not found.', null, 404);
    }

    // Enforce order terminality / validity before assigning
    $status_col = knx_v2_ops_pick_col($orders_cols, ['status', 'order_status']);
    if (!$status_col) {
        return knx_rest_response(false, 'Orders table missing status column; cannot enforce terminality.', null, 500);
    }
    $cur_status = strtolower((string) $wpdb->get_var($wpdb->prepare(
        "SELECT {$status_col} FROM {$orders_table} WHERE {$oid_col} = %d LIMIT 1",
        $order_id
    )));
    $terminal = ['cancelled', 'canceled', 'delivered', 'completed', 'refunded'];
    if (in_array($cur_status, $terminal, true)) {
        return knx_rest_response(false, 'Cannot assign driver: order is terminal.', ['status' => $cur_status], 409);
    }
    if ($cur_status === '' || $cur_status === 'unknown') {
        return knx_rest_response(false, 'Order status unknown: manual review required before assigning.', ['status' => $cur_status], 409);
    }

    // Ensure pipeline row exists (idempotent seed)
    $has_ops = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$ops_table} WHERE order_id = %d",
        $order_id
    ));
    if (!$has_ops) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$ops_table} (order_id, driver_user_id, ops_status, assigned_by, assigned_at, updated_at)
             VALUES (%d, NULL, 'unassigned', NULL, NULL, %s)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
            $order_id,
            current_time('mysql')
        ));
    }

    // Preferred: use sync engine if available
    if (function_exists('knx_driver_ops_sync_assign')) {
        $sync = knx_driver_ops_sync_assign($order_id, $driver_id, [
            'actor' => 'ops',
            'assigned_by' => $session_user_id,
        ]);

        if (empty($sync['success'])) {
            // Attempt safe fallback update if engine failed on duplicate insert or similar.
            $code = is_array($sync) && isset($sync['code']) ? (string)$sync['code'] : '';
            $msg = is_array($sync) && isset($sync['message']) ? (string)$sync['message'] : '';
            if ($code === 'assign_insert_failed' || $code === 'assign_update_failed' || strpos(strtolower($msg), 'duplicate') !== false) {
                $update_sql = $wpdb->prepare(
                    "UPDATE {$ops_table}
                     SET driver_user_id = %d, ops_status = %s, assigned_by = %d, assigned_at = %s, updated_at = %s
                     WHERE order_id = %d",
                    $driver_id,
                    'assigned',
                    $session_user_id,
                    current_time('mysql'),
                    current_time('mysql'),
                    $order_id
                );
                $res = $wpdb->query($update_sql);
                if ($res === false) {
                    return knx_rest_response(false, 'Driver ops sync failed and fallback update failed', [
                        'order_id' => $order_id,
                        'driver_id' => $driver_id,
                        'sync' => $sync,
                    ], 500);
                }

                if (function_exists('knx_driver_ops_log')) {
                    knx_driver_ops_log('warning', 'assign_sync_recovered', [
                        'order_id' => $order_id,
                        'driver_user_id' => $driver_id,
                        'reason' => $msg,
                        'code' => $code,
                    ]);
                }

                return knx_rest_response(true, 'Driver assigned (recovered from sync duplicate).', [
                    'order_id' => $order_id,
                    'driver_id' => $driver_id,
                    'sync' => $sync,
                ]);
            }

            return knx_rest_response(false, 'Driver ops sync failed', [
                'order_id' => $order_id,
                'driver_id' => $driver_id,
                'sync' => $sync,
            ], 500);
        }

        return knx_rest_response(true, 'Driver assigned.', [
            'order_id' => $order_id,
            'driver_id' => $driver_id,
            'sync' => $sync,
        ]);
    }

    // Fallback (only if engine missing): direct write
    $ok = $wpdb->update(
        $ops_table,
        [
            'driver_user_id' => $driver_id,
            'ops_status' => 'assigned',
            'assigned_by' => $session_user_id,
            'assigned_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ],
        ['order_id' => $order_id]
    );

    if ($ok === false) {
        return knx_rest_response(false, 'Failed to assign driver (no sync engine).', null, 500);
    }

    return knx_rest_response(true, 'Driver assigned (direct).', [
        'order_id' => $order_id,
        'driver_id' => $driver_id,
    ]);
}

/**
 * Unassign driver (SSOT: knx_driver_ops)
 * JSON: { order_id, knx_nonce }
 */
function knx_v2_ops_orders_unassign(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }

    $nonce = sanitize_text_field((string) ($body['knx_nonce'] ?? ''));
    if (!knx_v2_ops_verify_nonce($nonce, 'knx_ops_unassign_driver_nonce')) {
        return knx_rest_response(false, 'Invalid nonce.', null, 403);
    }

    $order_id = (int) ($body['order_id'] ?? 0);
    if ($order_id <= 0) {
        return knx_rest_response(false, 'order_id is required.', null, 400);
    }

    $ops_table = knx_v2_ops_driver_ops_table();
    if (!knx_v2_ops_table_exists($ops_table)) {
        return knx_rest_response(false, 'Driver ops table missing. Pipeline not initialized.', null, 500);
    }

    // Ensure pipeline row exists
    $has_ops = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$ops_table} WHERE order_id = %d",
        $order_id
    ));
    if (!$has_ops) {
        return knx_rest_response(false, 'Pipeline row missing for order_id.', [
            'order_id' => $order_id,
        ], 409);
    }

    // Disallow unassign when order archived
    if (knx_v2_ops_table_exists($history_table)) {
        $in_hist = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$history_table} WHERE order_id = %d", $order_id));
        if ($in_hist > 0) {
            return knx_rest_response(false, 'Cannot unassign: order archived', ['order_id' => $order_id], 409);
        }
    }

    if (function_exists('knx_driver_ops_sync_unassign')) {
        $session = function_exists('knx_get_session') ? knx_get_session() : null;
        $session_user_id = $session && isset($session->user_id) ? (int) $session->user_id : 0;
        $sync = knx_driver_ops_sync_unassign($order_id, [
            'actor' => 'ops',
            'unassigned_by' => $session_user_id,
        ]);

        if (empty($sync['success'])) {
            return knx_rest_response(false, 'Driver ops unassign failed', [
                'order_id' => $order_id,
                'sync' => $sync,
            ], 500);
        }

        return knx_rest_response(true, 'Driver unassigned.', [
            'order_id' => $order_id,
            'sync' => $sync,
        ]);
    }

    // Use direct SQL update to ensure driver_user_id becomes SQL NULL
    $update_sql = $wpdb->prepare(
        "UPDATE {$ops_table}
         SET driver_user_id = NULL, ops_status = %s, updated_at = %s
         WHERE order_id = %d",
        'unassigned',
        current_time('mysql'),
        $order_id
    );
    $res = $wpdb->query($update_sql);
    if ($res === false) {
        return knx_rest_response(false, 'Failed to unassign driver (no sync engine).', null, 500);
    }

    return knx_rest_response(true, 'Driver unassigned (direct).', [
        'order_id' => $order_id,
    ]);
}

/**
 * Cancel order (status update only)
 * JSON: { order_id, knx_nonce }
 */
function knx_v2_ops_orders_cancel(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }

    $nonce = sanitize_text_field((string) ($body['knx_nonce'] ?? ''));
    if (!knx_v2_ops_verify_nonce($nonce, 'knx_ops_cancel_order_nonce')) {
        return knx_rest_response(false, 'Invalid nonce.', null, 403);
    }

    $order_id = (int) ($body['order_id'] ?? 0);
    if ($order_id <= 0) {
        return knx_rest_response(false, 'order_id is required.', null, 400);
    }

    $orders = knx_v2_ops_orders_table();
    if (!knx_v2_ops_table_exists($orders)) {
        return knx_rest_response(false, 'Orders table missing.', null, 500);
    }

    $ocols  = knx_v2_ops_get_columns($orders);
    $id_col     = knx_v2_ops_pick_col($ocols, ['id']);
    $status_col = knx_v2_ops_pick_col($ocols, ['status', 'order_status']);
    $updated_col = knx_v2_ops_pick_col($ocols, ['updated_at', 'updated']);

    if (!$id_col || !$status_col) {
        return knx_rest_response(false, 'Orders table missing required columns (id/status).', null, 500);
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT {$id_col} AS id, {$status_col} AS status FROM {$orders} WHERE {$id_col} = %d LIMIT 1",
        $order_id
    ));
    if (!$row) {
        return knx_rest_response(false, 'Order not found.', null, 404);
    }

    $cur = strtolower((string) ($row->status ?? ''));
    $terminal = ['cancelled', 'canceled', 'delivered', 'completed', 'refunded'];
    if (in_array($cur, $terminal, true)) {
        return knx_rest_response(false, 'Order is already terminal.', [
            'status' => $cur,
        ], 409);
    }

    $new = 'cancelled';

    $data = [$status_col => $new];
    if ($updated_col) $data[$updated_col] = current_time('mysql');

    $ok = $wpdb->update($orders, $data, [$id_col => $order_id]);
    if ($ok === false) {
        return knx_rest_response(false, 'Failed to cancel order.', null, 500);
    }

    return knx_rest_response(true, 'Order cancelled.', [
        'order_id' => $order_id,
        'status' => $new,
    ]);
}

/**
 * Force status (super_admin only): pending | assigned
 * JSON: { order_id, status, knx_nonce }
 */
function knx_v2_ops_orders_force_status(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }

    $nonce = sanitize_text_field((string) ($body['knx_nonce'] ?? ''));
    if (!knx_v2_ops_verify_nonce($nonce, 'knx_ops_force_status_nonce')) {
        return knx_rest_response(false, 'Invalid nonce.', null, 403);
    }

    $order_id = (int) ($body['order_id'] ?? 0);
    $status   = strtolower(sanitize_text_field((string) ($body['status'] ?? '')));

    if ($order_id <= 0 || !in_array($status, ['pending', 'assigned'], true)) {
        return knx_rest_response(false, 'order_id and valid status are required (pending|assigned).', null, 400);
    }

    $orders = knx_v2_ops_orders_table();
    if (!knx_v2_ops_table_exists($orders)) {
        return knx_rest_response(false, 'Orders table missing.', null, 500);
    }

    $ocols  = knx_v2_ops_get_columns($orders);
    $id_col     = knx_v2_ops_pick_col($ocols, ['id']);
    $status_col = knx_v2_ops_pick_col($ocols, ['status', 'order_status']);
    $updated_col = knx_v2_ops_pick_col($ocols, ['updated_at', 'updated']);

    if (!$id_col || !$status_col) {
        return knx_rest_response(false, 'Orders table missing required columns (id/status).', null, 500);
    }

    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$orders} WHERE {$id_col} = %d",
        $order_id
    ));
    if (!$exists) {
        return knx_rest_response(false, 'Order not found.', null, 404);
    }

    $data = [$status_col => $status];
    if ($updated_col) $data[$updated_col] = current_time('mysql');

    $ok = $wpdb->update($orders, $data, [$id_col => $order_id]);
    if ($ok === false) {
        return knx_rest_response(false, 'Failed to force status.', null, 500);
    }

    return knx_rest_response(true, 'Status forced.', [
        'order_id' => $order_id,
        'status' => $status,
    ]);
}
