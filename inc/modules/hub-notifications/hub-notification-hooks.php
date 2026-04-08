<?php
/**
 * ==========================================================
 * KNX Hub Notifications — Hooks
 * ==========================================================
 * Binds WordPress action hooks to the hub notification engine.
 *
 * Listens for:
 * - knx_order_confirmed (int $order_id)
 *   Fired after webhook commits confirmed status.
 *   Triggers hub-scoped broadcast (same hub that owns the order).
 *
 * This file only registers the listener.
 * It does NOT fire the hook.
 * The hook is fired from api-update-order-status.php.
 *
 * Non-blocking:
 * - Entire broadcast is wrapped in try/catch.
 * - If notification fails, the webhook is not affected.
 * - No output, no error responses, no side effects on caller.
 *
 * Priority 20 ensures this runs AFTER driver notifications (priority 10).
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

add_action('knx_order_confirmed', 'knx_hn_on_order_confirmed', 20, 1);

/**
 * Handler for knx_order_confirmed action — hub notification.
 *
 * @param int $order_id
 * @return void
 */
function knx_hn_on_order_confirmed($order_id) {
    $order_id = (int) $order_id;
    if ($order_id <= 0) return;

    try {
        knx_hn_broadcast_hub_new_order($order_id);
    } catch (\Throwable $e) {
        // Non-blocking. Notification failure must never affect order processing.
        return;
    }
}
