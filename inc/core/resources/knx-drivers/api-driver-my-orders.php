<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Drivers — Driver My Orders (SEALED MVP) (robust)
 * GET /wp-json/knx/v1/driver/my-orders
 *
 * - Fails closed when ops table missing (returns empty list + note).
 * - Normalizes output: { success:true, data:{ can_view:true, orders:[], note:'' } }
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver/my-orders', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_driver_my_orders'),
        'permission_callback' => knx_rest_permission_driver_context(),
    ]);
});

function knx_api_driver_my_orders(WP_REST_Request $req) {
    global $wpdb;
    // --- KNX: Driver context (dual-mode) — fail-closed ---
    if (!function_exists('knx_get_driver_context')) {
        return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);
    }

    $ctx = knx_get_driver_context();
    if (!$ctx) {
        return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);
    }

    $driver_user_id = (int) $ctx->driver_id;
    $hub_ids = is_array($ctx->hubs) ? array_values(array_map('intval', $ctx->hubs)) : [];

    // Resolve canonical user_id for filtering orders.
    // ctx->driver_id may be either the users.user_id (session id) or drivers.id (internal PK).
    // Prefer session user_id when available; otherwise attempt a safe lookup in drivers table.
    $session_user_id = 0;
    if (function_exists('knx_get_session')) {
        $sess = knx_get_session();
        if ($sess && isset($sess->user_id)) {
            $session_user_id = (int) $sess->user_id;
        }
    }

    $resolved_user_id = $driver_user_id;
    if ($session_user_id > 0 && $driver_user_id !== $session_user_id) {
        // Attempt safe lookup: drivers table may map drivers.id -> user_id
        $drivers_table = $wpdb->prefix . 'knx_drivers';
        if (function_exists('knx_table')) {
            $maybe = knx_table('drivers');
            if (is_string($maybe) && $maybe !== '') $drivers_table = $maybe;
        }

        $found = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$drivers_table} WHERE id = %d LIMIT 1", $driver_user_id));
        if ($found && (int)$found > 0) {
            $resolved_user_id = (int)$found;
        } else {
            // Fallback to session user id if lookup failed
            $resolved_user_id = $session_user_id;
        }
    }

    if ($resolved_user_id <= 0) {
        return new WP_Error('forbidden', 'Driver context required', ['status' => 403]);
    }


    // NOTE: Do NOT early-return when hubs are empty — assigned orders must
    // still be returned even if the driver has no hubs. The hub filter is
    // only for discovery; explicit assignment overrides hub membership.

    // No admin override: use driver context only (driver flows only)

    $ops_table = $wpdb->prefix . 'knx_driver_ops';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_ops');
        if (is_string($maybe) && $maybe !== '') $ops_table = $maybe;
    }

    $orders_table = $wpdb->prefix . 'knx_orders';

    // If history table exists, we'll exclude orders that were already archived
    $history_table = $wpdb->prefix . 'knx_driver_orders_history';
    if (function_exists('knx_table')) {
        $maybe = knx_table('driver_orders_history'); if (is_string($maybe) && $maybe !== '') $history_table = $maybe;
    }
    $history_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table));

    // If ops table doesn't exist, fail-closed with a clear response (no fatals)
    $ops_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ops_table));
    if (!$ops_exists) {
        return knx_rest_response(true, 'OK', [
            'can_view' => true,
            'orders'   => [],
            'note'     => 'driver_ops_table_missing'
        ], 200);
    }

        // Build query: filter out historical/unassigned rows and restrict to acting driver/hubs
        $has_hub_col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$ops_table} LIKE %s", 'hub_id'));

        if ($has_hub_col && count($hub_ids) > 0) {
            // build IN placeholders for hubs
            $placeholders = implode(',', array_fill(0, count($hub_ids), '%d'));
            // We must allow explicitly-assigned orders to bypass hub filter. The clause
            // below returns rows where hub_id IN (...) OR the row is explicitly assigned
            // to the resolved driver_user_id.
            $params = array_merge([$resolved_user_id], $hub_ids, [$resolved_user_id]);
            // Exclude terminal ops statuses and restrict to today's orders; if history exists exclude archived orders
            $sql = "SELECT
                        o.id AS order_id,
                        o.total,
                        o.fulfillment_type,
                        o.delivery_address,
                        o.delivery_address_id,
                        o.status AS order_status,
                        op.ops_status,
                        op.assigned_at,
                        op.updated_at
                 FROM {$ops_table} op
                 JOIN {$orders_table} o ON o.id = op.order_id
                 WHERE op.driver_user_id = %d
                     AND (op.hub_id IN ({$placeholders}) OR op.driver_user_id = %d)
                     AND op.ops_status NOT IN ('completed','delivered')
                     AND (
                         (o.estimated_delivery_at IS NULL AND DATE(o.created_at) = CURDATE())
                         OR (o.estimated_delivery_at IS NOT NULL AND DATE(o.estimated_delivery_at) = CURDATE())
                     )
                ";

            if ($history_exists) {
                $sql .= " AND op.order_id NOT IN (SELECT order_id FROM {$history_table}) ";
            }

            $sql .= " ORDER BY op.updated_at DESC, op.assigned_at DESC LIMIT 200";

                $query = $wpdb->prepare($sql, $params);
            } else {
                // No hubs provided or hub column missing: fall back to driver-only filter
                // (this still returns assigned rows for the resolved driver_user_id).
                // No hubs provided: driver-only filter. Also hide terminal ops and restrict to today's orders and exclude history if present
                $sql = "SELECT
                                o.id AS order_id,
                                o.total,
                                o.fulfillment_type,
                                o.delivery_address,
                                o.delivery_address_id,
                                o.status AS order_status,
                                op.ops_status,
                                op.assigned_at,
                                op.updated_at
                         FROM {$ops_table} op
                         JOIN {$orders_table} o ON o.id = op.order_id
                         WHERE op.driver_user_id = %d
                            AND op.ops_status NOT IN ('completed','delivered')
                            AND (
                                (o.estimated_delivery_at IS NULL AND DATE(o.created_at) = CURDATE())
                                OR (o.estimated_delivery_at IS NOT NULL AND DATE(o.estimated_delivery_at) = CURDATE())
                            )
                ";
                if ($history_exists) {
                    $sql .= " AND op.order_id NOT IN (SELECT order_id FROM {$history_table}) ";
                }
                $sql .= " ORDER BY op.updated_at DESC, op.assigned_at DESC LIMIT 200";
                $query = $wpdb->prepare($sql, $resolved_user_id);
        }

    // (debug logs removed)

    $rows = $wpdb->get_results($query);

    return knx_rest_response(true, 'OK', [
        'can_view' => true,
        'orders'   => $rows ? $rows : [],
    ], 200);
}
