<?php
if (!defined('ABSPATH')) exit;

/**
 * KNX Drivers — Assign OP to driver (secure)
 * POST /wp-json/knx/v1/ops/assign
 * Body: { op_id: int, assign_to_user_id: int }
 * Only manager/super_admin may assign; scope-limited by hubs in knx_get_driver_context().
 */

add_action('rest_api_init', function () {
    // Use knx_rest_wrap if available, otherwise register the handler directly
    if (function_exists('knx_rest_wrap')) {
        register_rest_route('knx/v1', '/ops/assign', [
            'methods'             => 'POST',
            'callback'            => knx_rest_wrap('knx_api_ops_assign'),
            'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
        ]);
    } else {
        register_rest_route('knx/v1', '/ops/assign', [
            'methods'             => 'POST',
            'callback'            => 'knx_api_ops_assign',
            'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
        ]);
    }
});

function knx_api_ops_assign(WP_REST_Request $req) {
    global $wpdb;

    // Require session and admin role (permission callback already ensured this,
    // but validate here defensively).
    $session = knx_rest_get_session();
    if (!$session) return knx_rest_error('forbidden', 403);

    $role = isset($session->role) ? (string) $session->role : '';
    if (!in_array($role, ['manager', 'super_admin'], true)) {
        return knx_rest_error('forbidden', 403);
    }

    // Compute allowed hubs for the acting admin.
    // super_admin => all hubs; manager => hubs mapped in common candidate tables.
    $allowed_hubs = [];
    if ($role === 'super_admin') {
        $hubs_table = $wpdb->prefix . 'knx_hubs';
        if (function_exists('knx_table')) {
            $maybe = knx_table('hubs');
            if (is_string($maybe) && $maybe !== '') $hubs_table = $maybe;
        }
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hubs_table));
        if ($exists) {
            $found = $wpdb->get_col("SELECT id FROM {$hubs_table}");
            if ($found && is_array($found)) $allowed_hubs = array_map('intval', $found);
        }
    } else {
        // manager: try common mapping tables
        $candidates = [
            ['table' => 'hub_managers', 'user_col' => 'manager_id', 'hub_col' => 'hub_id'],
            ['table' => 'hub_admins',   'user_col' => 'user_id',    'hub_col' => 'hub_id'],
            ['table' => 'hub_users',    'user_col' => 'user_id',    'hub_col' => 'hub_id'],
        ];
        $uid = isset($session->user_id) ? (int) $session->user_id : 0;
        foreach ($candidates as $cand) {
            $maybe_table = $wpdb->prefix . 'knx_' . $cand['table'];
            if (function_exists('knx_table')) {
                $tmp = knx_table($cand['table']);
                if (is_string($tmp) && $tmp !== '') $maybe_table = $tmp;
            }
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $maybe_table));
            if (!$exists) continue;
            $safe_sql = $wpdb->prepare("SELECT {$cand['hub_col']} FROM {$maybe_table} WHERE {$cand['user_col']} = %d", $uid);
            $found = $wpdb->get_col($safe_sql);
            if ($found && is_array($found) && count($found) > 0) {
                $allowed_hubs = array_map('intval', $found);
                break;
            }
        }
    }

    $body = $req->get_json_params();
    if (!is_array($body)) {
        return knx_rest_error('invalid_request', 400);
    }

    $op_id = isset($body['op_id']) ? (int) $body['op_id'] : 0;
    $assign_to = isset($body['assign_to_user_id']) ? (int) $body['assign_to_user_id'] : 0;

    if ($op_id <= 0) {
        return knx_rest_error('op_id_required', 400);
    }

    $ops_table = $wpdb->prefix . 'knx_driver_ops';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_ops');
        if (is_string($maybe) && $maybe !== '') $ops_table = $maybe;
    }

    $ops_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ops_table));
    if (!$ops_exists) {
        return knx_rest_error('driver_ops_table_missing', 500);
    }

    $op = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ops_table} WHERE id = %d LIMIT 1", $op_id));
    if (!$op) {
        return knx_rest_error('operation_not_found', 404);
    }

    // Confirm hub scope
    $hub_id = isset($op->hub_id) ? (int) $op->hub_id : 0;
    if ($hub_id > 0 && !in_array($hub_id, $allowed_hubs, true)) {
        // audit log
        error_log('[KNX-ASSIGN] actor_user_id=' . intval($session->user_id) . ' role=' . $role . ' op_id=' . $op_id . ' hub_id=' . $hub_id . ' assign_to=' . $assign_to . ' allowed=0 reason=hub_not_in_scope');
        return knx_rest_error('forbidden', 403);
    }

    // Allow unassign (assign_to = 0/null) — use direct SQL to set NULL safely
    if ($assign_to <= 0) {
        $sql = $wpdb->prepare("UPDATE {$ops_table} SET driver_user_id = NULL WHERE id = %d", $op_id);
        $updated = $wpdb->query($sql);
        error_log('[KNX-ASSIGN] actor_user_id=' . intval($session->user_id) . ' role=' . $role . ' op_id=' . $op_id . ' hub_id=' . $hub_id . ' assign_to=null allowed=1 driver_key_used=' . ($driver_key ?? 'unknown') . ' mapping_key_used=' . ($dh_key ?? 'none'));
        if ($updated === false) return knx_rest_error('db_update_failed', 500);
        return knx_rest_response(true, 'OK', ['updated' => ($updated > 0), 'op_id' => $op_id], 200);
    }

    // Validate assign_to exists as a driver according to schema (user_id or id)
    $drivers_table = $wpdb->prefix . 'knx_drivers';
    if (function_exists('knx_table')) {
        $maybe = knx_table('drivers');
        if (is_string($maybe) && $maybe !== '') $drivers_table = $maybe;
    }

    $drivers_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drivers_table));
    if (!$drivers_exists) {
        return knx_rest_error('drivers_table_missing', 500);
    }

    // Detect driver key (user_id vs id)
    $driver_key = 'id';
    $cols = $wpdb->get_results("SHOW COLUMNS FROM {$drivers_table}", ARRAY_A);
    if ($cols && is_array($cols)) {
        $col_names = array_map(function($c){ return $c['Field']; }, $cols);
        if (in_array('user_id', $col_names, true)) {
            $driver_key = 'user_id';
        }
    }

    $driver = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$drivers_table} WHERE {$driver_key} = %d LIMIT 1", $assign_to));
    if (!$driver) {
        error_log('[KNX-ASSIGN] actor_user_id=' . intval($session->user_id) . ' role=' . $role . ' op_id=' . $op_id . ' hub_id=' . $hub_id . ' assign_to=' . $assign_to . ' allowed=0 reason=driver_not_found driver_key_used=' . $driver_key);
        return knx_rest_error('invalid_driver', 422);
    }

    // Enforce availability: if availability table exists, driver must have status 'on'
    $availability_table = $wpdb->prefix . 'knx_driver_availability';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_availability');
        if (is_string($maybe) && $maybe !== '') $availability_table = $maybe;
    }
    $av_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $availability_table));
    if ($av_exists) {
        $check_id = isset($driver->user_id) ? intval($driver->user_id) : (isset($driver->id) ? intval($driver->id) : $assign_to);
        $av_row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$availability_table} WHERE driver_user_id = %d LIMIT 1", $check_id), ARRAY_A);
        if (!$av_row || !isset($av_row['status']) || (string)$av_row['status'] !== 'on') {
            error_log('[KNX-ASSIGN] blocked_assign_driver_off_duty actor=' . intval($session->user_id) . ' assign_to=' . $assign_to . ' check_id=' . $check_id . ' op_id=' . $op_id);
            return knx_rest_error('Driver is off duty', 403);
        }
    }

    // If mapping table exists, ensure driver is mapped to hub
    $driver_hubs_table = $wpdb->prefix . 'knx_driver_hubs';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_hubs');
        if (is_string($maybe) && $maybe !== '') $driver_hubs_table = $maybe;
    }

    $dh_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $driver_hubs_table));
    $mapped = true;
    if ($dh_exists) {
        // Detect mapping key
        $dh_cols = $wpdb->get_results("SHOW COLUMNS FROM {$driver_hubs_table}", ARRAY_A);
        $dh_names = $dh_cols ? array_map(function($c){ return $c['Field']; }, $dh_cols) : [];
        $dh_key = in_array('driver_id', $dh_names, true) ? 'driver_id' : (in_array('user_id', $dh_names, true) ? 'user_id' : null);
        if ($dh_key) {
            $found = $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$driver_hubs_table} WHERE {$dh_key} = %d AND hub_id = %d", $assign_to, $hub_id));
            $mapped = ($found && intval($found) > 0);
        }
    }

    if (!$mapped) {
        error_log('[KNX-ASSIGN] actor_user_id=' . intval($session->user_id) . ' role=' . $role . ' op_id=' . $op_id . ' hub_id=' . $hub_id . ' assign_to=' . $assign_to . ' allowed=0 reason=driver_not_mapped mapping_key_used=' . ($dh_key ?? 'none'));
        return knx_rest_error('driver_not_mapped_to_hub', 422);
    }

    // Perform update
    // Choose stored value based on drivers table key. If driver_key is 'user_id', store assign_to (WP user id).
    $store_value = $assign_to;
    if ($driver_key !== 'user_id') {
        // drivers table uses internal id as PK; ensure we store that.
        $store_value = isset($driver->id) ? intval($driver->id) : $assign_to;
    }

    $updated = $wpdb->update($ops_table, ['driver_user_id' => $store_value], ['id' => $op_id], ['%d'], ['%d']);
    if ($updated === false) {
        return knx_rest_error('db_update_failed', 500);
    }

    error_log('[KNX-ASSIGN] actor_user_id=' . intval($session->user_id) . ' role=' . $role . ' op_id=' . $op_id . ' hub_id=' . $hub_id . ' assign_to=' . $assign_to . ' allowed=1 driver_key_used=' . $driver_key . ' mapping_key_used=' . ($dh_key ?? 'none'));

    return knx_rest_response(true, 'OK', ['updated' => ($updated > 0), 'op_id' => $op_id, 'assign_to' => $assign_to], 200);
}
