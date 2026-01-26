<?php
if (!defined('ABSPATH')) exit;

/**
 * Minimal driver runtime helpers (no DB schema changes).
 * Identity: driver_user_id is the session user id (knx_get_driver_context()->driver_id).
 */
if (!function_exists('knx_get_driver_user_id')) {
    function knx_get_driver_user_id() {
        if (!function_exists('knx_get_driver_context')) return 0;
        $ctx = knx_get_driver_context();
        if (!$ctx) return 0;
        return isset($ctx->driver_id) ? (int) $ctx->driver_id : 0;
    }
}

if (!function_exists('knx_archive_order_to_history')) {
    function knx_archive_order_to_history($order_id, $actor = 'system', $actor_id = 0) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) return ['success' => false, 'code' => 'invalid_order_id'];

        $ops_table = $wpdb->prefix . 'knx_driver_ops';
        $orders_table = $wpdb->prefix . 'knx_orders';
        $history_table = $wpdb->prefix . 'knx_driver_orders_history';
        if (function_exists('knx_table')) {
            $maybe = knx_table('driver_ops'); if (is_string($maybe) && $maybe !== '') $ops_table = $maybe;
            $maybe = knx_table('orders'); if (is_string($maybe) && $maybe !== '') $orders_table = $maybe;
            $maybe = knx_table('driver_orders_history'); if (is_string($maybe) && $maybe !== '') $history_table = $maybe;
        }

        // History table must exist (do NOT create at runtime)
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table));
        if (!$exists) return ['success' => false, 'code' => 'history_table_missing'];

        // If already archived, return success idempotent
        $already = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$history_table} WHERE order_id = %d", $order_id));
        if ($already > 0) return ['success' => true, 'code' => 'already_archived'];

        // Find latest ops row for metadata (driver_user_id, hub_id)
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ops_table} WHERE order_id = %d ORDER BY updated_at DESC LIMIT 1", $order_id));
        $driver_user_id = $row && isset($row->driver_user_id) ? (int)$row->driver_user_id : null;
        $hub_id = $row && isset($row->hub_id) ? (int)$row->hub_id : null;

        // Snapshot the canonical order row
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders_table} WHERE id = %d LIMIT 1", $order_id), ARRAY_A);
        $snapshot = $order ? wp_json_encode($order) : null;

        $now = current_time('mysql');

        $insert = $wpdb->insert($history_table, [
            'order_id' => $order_id,
            'driver_user_id' => $driver_user_id,
            'hub_id' => $hub_id,
            'completed_at' => $now,
            'total' => isset($order['total']) ? $order['total'] : null,
            'snapshot_json' => $snapshot,
        ], ['%d','%d','%d','%s','%s','%s']);

        if ($insert === false) return ['success' => false, 'code' => 'history_insert_failed'];

        // Mark pipeline row as terminal (completed) but preserve driver_user_id
        $upd = $wpdb->update($ops_table, ['ops_status' => 'completed', 'updated_at' => $now], ['order_id' => $order_id]);

        return ['success' => true, 'code' => 'archived'];
    }
}
