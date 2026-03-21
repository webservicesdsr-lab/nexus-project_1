<?php
/**
 * ==========================================================
 * KNX Driver Notifications — Hooks
 * ==========================================================
 * Binds WordPress action hooks to the notification engine.
 *
 * Listens for:
 * - knx_order_confirmed (int $order_id)
 *   Fired after webhook commits confirmed status.
 *   Triggers city-scoped driver broadcast.
 *
 * This file only registers the listener.
 * It does NOT fire the hook.
 * The hook is fired from api-payment-webhook.php.
 *
 * Non-blocking:
 * - Entire broadcast is wrapped in try/catch.
 * - If notification fails, the webhook is not affected.
 * - No output, no error responses, no side effects on caller.
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

add_action('knx_order_confirmed', 'knx_dn_on_order_confirmed', 10, 1);

/**
 * Handler for knx_order_confirmed action.
 *
 * @param int $order_id
 * @return void
 */
function knx_dn_on_order_confirmed($order_id) {
    $order_id = (int) $order_id;
    if ($order_id <= 0) return;

    try {
        knx_dn_broadcast_order_available($order_id);
    } catch (\Throwable $e) {
        // Non-blocking. Notification failure must never affect order processing.
        // No logging framework available per project constraints.
        return;
    }
}
