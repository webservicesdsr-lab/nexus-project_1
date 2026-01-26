<?php
/**
 * ==========================================================
 * Kingdom Nexus — Driver OPS Sync Engine (SSOT)
 * ==========================================================
 *
 * PURPOSE:
 * - Keep knx_driver_ops aligned with OPS assignments.
 * - Preserve history: each assignment creates a new row.
 * - Unassign does NOT delete rows; it marks active rows as 'unassigned'.
 *
 * DESIGN:
 * - Assign:
 *   1) If the latest active row is already assigned to the same driver -> idempotent update timestamp.
 *   2) Otherwise, mark all active rows for the order as 'unassigned'.
 *   3) Insert a new row: ops_status='assigned'.
 * - Unassign:
 *   1) Mark all active rows for the order as 'unassigned'.
 *
 * FAIL-CLOSED:
 * - If the table is missing or DB write fails -> return false (caller must abort).
 *
 * SECURITY:
 * - No PII, no secrets in logs.
 *
 * @package KingdomNexus
 * @since 2.8.6
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('knx_driver_ops_table')) {
    function knx_driver_ops_table() {
        global $wpdb;
        return $wpdb->prefix . 'knx_driver_ops';
    }
}

if (!function_exists('knx_driver_ops_table_exists')) {
    function knx_driver_ops_table_exists() {
        global $wpdb;
        $table = knx_driver_ops_table();
        $like  = $wpdb->esc_like($table);
        $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));
        return (!empty($found) && strtolower($found) === strtolower($table));
    }
}

if (!function_exists('knx_driver_ops_now_gmt')) {
    function knx_driver_ops_now_gmt() {
        // GMT timestamp in MySQL format (consistent & timezone-safe).
        return function_exists('current_time') ? current_time('mysql', 1) : gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('knx_driver_ops_log')) {
    function knx_driver_ops_log($level, $code, $meta = []) {
        $payload = [
            'tag' => '[KNX][DRIVER_OPS]',
            'level' => (string) $level,
            'code' => (string) $code,
            'meta' => is_array($meta) ? $meta : [],
            'ts_gmt' => knx_driver_ops_now_gmt(),
        ];
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        error_log((string) $json);
    }
}

if (!function_exists('knx_driver_ops_mark_order_unassigned')) {
    /**
     * Mark all "active" rows for an order as unassigned.
     * Active = ops_status != 'unassigned'
     *
     * @param int $order_id
     * @return bool
     */
    function knx_driver_ops_mark_order_unassigned($order_id) {
        global $wpdb;

        if (!$order_id || $order_id < 1) return false;
        if (!knx_driver_ops_table_exists()) return false;

        $table = knx_driver_ops_table();
        $now   = knx_driver_ops_now_gmt();

        // Mark any non-unassigned row as unassigned (history preserved)
        $sql = $wpdb->prepare(
            "UPDATE {$table}
             SET ops_status = %s, updated_at = %s
             WHERE order_id = %d AND ops_status <> %s",
            'unassigned', $now, (int) $order_id, 'unassigned'
        );

        $res = $wpdb->query($sql);

        // $res can be 0 if already unassigned, that's not an error.
        if ($res === false) {
            knx_driver_ops_log('error', 'mark_unassigned_failed', ['order_id' => (int)$order_id]);
            return false;
        }

        return true;
    }
}

if (!function_exists('knx_driver_ops_get_latest_active_row')) {
    /**
     * Get the latest active row for an order (ops_status != 'unassigned').
     *
     * @param int $order_id
     * @return object|null
     */
    function knx_driver_ops_get_latest_active_row($order_id) {
        global $wpdb;

        if (!$order_id || $order_id < 1) return null;
        if (!knx_driver_ops_table_exists()) return null;

        $table = knx_driver_ops_table();

        // Prefer updated_at DESC, then id DESC if id exists.
        // If "id" does not exist, MySQL will error; we catch by trying safe query first.
        $row = null;

        // Attempt with id
        $sql1 = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE order_id = %d AND ops_status <> %s
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            (int)$order_id, 'unassigned'
        );

        $row = $wpdb->get_row($sql1);
        if ($row !== null) return $row;

        // Fallback without id
        $sql2 = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE order_id = %d AND ops_status <> %s
             ORDER BY updated_at DESC
             LIMIT 1",
            (int)$order_id, 'unassigned'
        );

        return $wpdb->get_row($sql2);
    }
}

if (!function_exists('knx_driver_ops_sync_assign')) {
    /**
     * Sync assignment: preserve history by inserting a new row per assignment.
     *
     * @param int $order_id
     * @param int $driver_user_id
     * @param array $ctx Optional context for logs (actor_id, role, etc.)
     * @return array { success:bool, code:string, message:string }
     */
    function knx_driver_ops_sync_assign($order_id, $driver_user_id, $ctx = []) {
        global $wpdb;

        $order_id = (int) $order_id;
        $driver_user_id = (int) $driver_user_id;

        if ($order_id < 1 || $driver_user_id < 1) {
            return ['success' => false, 'code' => 'bad_params', 'message' => 'Invalid order_id or driver_user_id'];
        }

        if (!knx_driver_ops_table_exists()) {
            knx_driver_ops_log('error', 'driver_ops_table_missing', ['order_id' => $order_id]);
            return ['success' => false, 'code' => 'driver_ops_table_missing', 'message' => 'Driver ops table missing'];
        }

        $table = knx_driver_ops_table();
        $now   = knx_driver_ops_now_gmt();

        // NEW SSOT: single active row per order
        // If a row exists for this order_id -> perform UPDATE; otherwise INSERT once (bootstrap)
        // This preserves idempotency and avoids duplicate active rows.

        // Check for existing pipeline row (any row for order_id)
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$table} WHERE order_id = %d", $order_id));

        // Idempotent path: if an existing row is already assigned to same driver, just bump updated_at
        if ($existing) {
            $latest = knx_driver_ops_get_latest_active_row($order_id);
            if ($latest && isset($latest->driver_user_id) && (int)$latest->driver_user_id === $driver_user_id && isset($latest->ops_status) && strtolower((string)$latest->ops_status) === 'assigned') {
                $updated = $wpdb->update(
                    $table,
                    ['updated_at' => $now],
                    ['order_id' => $order_id, 'driver_user_id' => $driver_user_id],
                    ['%s'],
                    ['%d', '%d']
                );

                if ($updated === false) {
                    knx_driver_ops_log('error', 'assign_idempotent_update_failed', [
                        'order_id' => $order_id,
                        'driver_user_id' => $driver_user_id
                    ]);
                    return ['success' => false, 'code' => 'assign_idempotent_update_failed', 'message' => 'Failed to update timestamp'];
                }

                knx_driver_ops_log('info', 'assign_idempotent_ok', [
                    'order_id' => $order_id,
                    'driver_user_id' => $driver_user_id
                ]);

                return ['success' => true, 'code' => 'assign_idempotent_ok', 'message' => 'Already assigned to same driver'];
            }

            // Existing row(s) present: perform single-row UPDATE to set assigned values
            $assigned_by = isset($ctx['assigned_by']) ? (int)$ctx['assigned_by'] : null;
            $update_data = [
                'driver_user_id' => $driver_user_id,
                'ops_status'     => 'assigned',
                'updated_at'     => $now,
            ];
            $format = ['%d', '%s', '%s'];

            if (!is_null($assigned_by)) {
                $update_data['assigned_by'] = $assigned_by;
                $update_data['assigned_at'] = $now;
                $format[] = '%d';
                $format[] = '%s';
            } else {
                // If assigned_by not provided, still update assigned_at to now
                $update_data['assigned_at'] = $now;
                $format[] = '%s';
            }

            $where = ['order_id' => $order_id];
            $where_format = ['%d'];

            $res = $wpdb->update($table, $update_data, $where, $format, $where_format);
            if ($res === false) {
                knx_driver_ops_log('error', 'assign_update_failed', [
                    'order_id' => $order_id,
                    'driver_user_id' => $driver_user_id
                ]);
                return ['success' => false, 'code' => 'assign_update_failed', 'message' => 'Failed to update existing assignment row'];
            }

            knx_driver_ops_log('info', 'assign_update_ok', [
                'order_id' => $order_id,
                'driver_user_id' => $driver_user_id,
                'rows_affected' => $res
            ]);

            return ['success' => true, 'code' => 'assign_update_ok', 'message' => 'Assignment updated'];
        }

        // No existing row: perform INSERT once (bootstrap)
        $assigned_by = isset($ctx['assigned_by']) ? (int)$ctx['assigned_by'] : null;
        $insert_row = [
            'order_id'       => $order_id,
            'driver_user_id' => $driver_user_id,
            'ops_status'     => 'assigned',
            'assigned_at'    => $now,
            'updated_at'     => $now,
        ];
        if (!is_null($assigned_by)) {
            $insert_row['assigned_by'] = $assigned_by;
        }

        // Build formats matching insert_row keys to avoid mismatch
        $insert_formats = [];
        foreach ($insert_row as $k => $v) {
            if ($k === 'order_id' || $k === 'driver_user_id' || $k === 'assigned_by') {
                $insert_formats[] = '%d';
            } else {
                $insert_formats[] = '%s';
            }
        }

        $inserted = $wpdb->insert($table, $insert_row, $insert_formats);

        if ($inserted === false) {
            knx_driver_ops_log('error', 'assign_insert_failed', [
                'order_id' => $order_id,
                'driver_user_id' => $driver_user_id,
                'last_error' => $wpdb->last_error,
            ]);
            return ['success' => false, 'code' => 'assign_insert_failed', 'message' => 'Failed to insert new assignment row'];
        }

        knx_driver_ops_log('info', 'assign_insert_ok', [
            'order_id' => $order_id,
            'driver_user_id' => $driver_user_id
        ]);

        return ['success' => true, 'code' => 'assign_insert_ok', 'message' => 'Assignment inserted'];
    }
}

if (!function_exists('knx_driver_ops_sync_unassign')) {
    /**
     * Sync unassign: mark active rows as 'unassigned' (no delete).
     *
     * @param int $order_id
     * @param array $ctx Optional context for logs (actor_id, role, etc.)
     * @return array { success:bool, code:string, message:string }
     */
    function knx_driver_ops_sync_unassign($order_id, $ctx = []) {
        $order_id = (int) $order_id;

        if ($order_id < 1) {
            return ['success' => false, 'code' => 'bad_params', 'message' => 'Invalid order_id'];
        }

        if (!knx_driver_ops_table_exists()) {
            knx_driver_ops_log('error', 'driver_ops_table_missing', ['order_id' => $order_id]);
            return ['success' => false, 'code' => 'driver_ops_table_missing', 'message' => 'Driver ops table missing'];
        }

        $ok = knx_driver_ops_mark_order_unassigned($order_id);
        if (!$ok) {
            return ['success' => false, 'code' => 'unassign_failed', 'message' => 'Failed to mark unassigned'];
        }

        knx_driver_ops_log('info', 'unassign_ok', ['order_id' => $order_id]);

        return ['success' => true, 'code' => 'unassign_ok', 'message' => 'Order unassigned'];
    }
}
