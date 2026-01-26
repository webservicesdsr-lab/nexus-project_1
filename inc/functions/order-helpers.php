<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX ORDER HELPERS (KNX-A0.9.2)
 * ----------------------------------------------------------
 * Single Source of Truth: Order vs Cart enforcement
 * 
 * After order creation, the system NEVER reads cart again.
 * All data must come from:
 * - knx_orders table
 * - knx_order_items table
 * - totals_snapshot (JSON)
 * - cart_snapshot (JSON)
 * 
 * PROHIBITED after order creation:
 * - Reading knx_carts
 * - Reading knx_cart_items
 * - Recalculating totals
 * ==========================================================
 */

/**
 * KNX-A0.9.2: Validate that order is canonical source of truth.
 * 
 * Checks that order has complete snapshot and cart is detached.
 * Fail-closed: Returns false if any validation fails.
 * 
 * @param int $order_id Order ID
 * @return array Validation result
 */
if (!function_exists('knx_validate_order_canonical_state')) {
    function knx_validate_order_canonical_state($order_id) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return [
                'valid'  => false,
                'reason' => 'INVALID_ORDER_ID',
            ];
        }

        $table_orders = $wpdb->prefix . 'knx_orders';

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, totals_snapshot, cart_snapshot, session_token
             FROM {$table_orders}
             WHERE id = %d
             LIMIT 1",
            $order_id
        ));

        if (!$order) {
            return [
                'valid'  => false,
                'reason' => 'ORDER_NOT_FOUND',
            ];
        }

        // Validate totals snapshot exists
        if (empty($order->totals_snapshot)) {
            return [
                'valid'  => false,
                'reason' => 'TOTALS_SNAPSHOT_MISSING',
            ];
        }

        // Validate cart snapshot exists
        if (empty($order->cart_snapshot)) {
            return [
                'valid'  => false,
                'reason' => 'CART_SNAPSHOT_MISSING',
            ];
        }

        // Parse totals snapshot
        $totals = json_decode($order->totals_snapshot, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($totals)) {
            return [
                'valid'  => false,
                'reason' => 'TOTALS_SNAPSHOT_CORRUPT',
            ];
        }

        // KNX-A0.9.3: Validate readiness flags
        if (!isset($totals['is_snapshot_locked']) || $totals['is_snapshot_locked'] !== true) {
            return [
                'valid'  => false,
                'reason' => 'SNAPSHOT_NOT_LOCKED',
            ];
        }

        if (!isset($totals['is_cart_detached']) || $totals['is_cart_detached'] !== true) {
            return [
                'valid'  => false,
                'reason' => 'CART_NOT_DETACHED',
            ];
        }

        // Validate cart is converted (detached)
        $table_carts = $wpdb->prefix . 'knx_carts';
        $cart = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status
             FROM {$table_carts}
             WHERE session_token = %s
             ORDER BY updated_at DESC
             LIMIT 1",
            $order->session_token
        ));

        if ($cart && (string) $cart->status !== 'converted') {
            return [
                'valid'  => false,
                'reason' => 'CART_NOT_CONVERTED',
                'cart_status' => $cart->status,
            ];
        }

        return [
            'valid'         => true,
            'order_id'      => $order_id,
            'snapshot_version' => $totals['version'] ?? 'unknown',
        ];
    }
}

/**
 * KNX-A0.9.2: Get order totals from snapshot (SSOT).
 * 
 * NEVER recalculate. Always read from totals_snapshot.
 * Fail-closed: Returns null on error.
 * 
 * @param int $order_id Order ID
 * @return array|null Totals breakdown or null
 */
if (!function_exists('knx_get_order_totals_from_snapshot')) {
    function knx_get_order_totals_from_snapshot($order_id) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return null;
        }

        $table_orders = $wpdb->prefix . 'knx_orders';

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT totals_snapshot
             FROM {$table_orders}
             WHERE id = %d
             LIMIT 1",
            $order_id
        ));

        if (!$order || empty($order->totals_snapshot)) {
            return null;
        }

        $totals = json_decode($order->totals_snapshot, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($totals)) {
            return null;
        }

        return $totals;
    }
}

/**
 * KNX-A0.9.2: Get order items from snapshot (SSOT).
 * 
 * NEVER read from cart_items. Always use cart_snapshot.
 * Fail-closed: Returns empty array on error.
 * 
 * @param int $order_id Order ID
 * @return array Order items from snapshot
 */
if (!function_exists('knx_get_order_items_from_snapshot')) {
    function knx_get_order_items_from_snapshot($order_id) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return [];
        }

        $table_orders = $wpdb->prefix . 'knx_orders';

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT cart_snapshot
             FROM {$table_orders}
             WHERE id = %d
             LIMIT 1",
            $order_id
        ));

        if (!$order || empty($order->cart_snapshot)) {
            return [];
        }

        $cart_snapshot = json_decode($order->cart_snapshot, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($cart_snapshot)) {
            return [];
        }

        return isset($cart_snapshot['items']) && is_array($cart_snapshot['items']) 
            ? $cart_snapshot['items'] 
            : [];
    }
}

/**
 * KNX-A0.9.4: Check if cart is already converted (used for order).
 * 
 * HARD guard against re-entry.
 * 
 * @param string $session_token Cart session token
 * @return bool True if cart is converted (unusable)
 */
if (!function_exists('knx_is_cart_converted')) {
    function knx_is_cart_converted($session_token) {
        global $wpdb;

        $session_token = sanitize_text_field($session_token);
        if ($session_token === '') {
            return false;
        }

        $table_carts = $wpdb->prefix . 'knx_carts';

        $cart = $wpdb->get_row($wpdb->prepare(
            "SELECT status
             FROM {$table_carts}
             WHERE session_token = %s
             ORDER BY updated_at DESC
             LIMIT 1",
            $session_token
        ));

        return $cart && (string) $cart->status === 'converted';
    }
}

/**
 * KNX-A0.9.4: Check if order can be modified.
 * 
 * HARD guard: Only 'placed' orders can be modified by certain operations.
 * After confirmation, orders become immutable.
 * 
 * @param int $order_id Order ID
 * @return array Modification permission result
 */
if (!function_exists('knx_can_modify_order')) {
    function knx_can_modify_order($order_id) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return [
                'allowed' => false,
                'reason'  => 'INVALID_ORDER_ID',
            ];
        }

        $table_orders = $wpdb->prefix . 'knx_orders';

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status
             FROM {$table_orders}
             WHERE id = %d
             LIMIT 1",
            $order_id
        ));

        if (!$order) {
            return [
                'allowed' => false,
                'reason'  => 'ORDER_NOT_FOUND',
            ];
        }

        // KNX-A1.3: Post-Payment Order Lockdown
        // Only 'placed' status allows modification
        // After 'confirmed', order is READ ONLY (locked for fulfillment)
        if ((string) $order->status !== 'placed') {
            error_log("[KNX-A1.3] Order modification blocked: order_id=$order_id status={$order->status}");
            return [
                'allowed' => false,
                'reason'  => 'ORDER_ALREADY_CONFIRMED',
                'status'  => $order->status,
            ];
        }

        return [
            'allowed' => true,
            'status'  => 'placed',
        ];
    }
}
