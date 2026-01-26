<?php
/**
 * ████████████████████████████████████████████████████████████████
 * █ KNX-A1.1 — PAYMENT STATUS (READ-ONLY, KNX AUTH) — SEALED
 * ████████████████████████████████████████████████████████████████
 *
 * ENDPOINTS:
 * - GET  /wp-json/knx/v1/payments/status?order_id=123
 * - POST /wp-json/knx/v1/payments/status
 *
 * PURPOSE:
 * - Provide minimal payment/order status for frontend polling
 * - READ-ONLY endpoint
 * - KNX session is the ONLY authority (SSOT)
 *
 * RULES:
 * - NO WordPress auth (permission_callback is open)
 * - NO cart access
 * - NO recalculation
 * - NO sensitive data (amounts, secrets)
 * - Fail-closed on any anomaly
 *
 * SEALED STATUS CONTRACT (CANON):
 * - Always returns { success, message, data } (wrapper-compatible)
 * - Also promotes key fields to top-level for polling robustness:
 *     status, order_id, order_status, payment_status, payment_intent_id, redirect_url
 *
 * @package KingdomNexus
 * @since A1.1
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Payment Status Endpoint
 */
add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/payments/status', [
        'methods'  => ['GET', 'POST'],
        'callback' => 'knx_api_payment_status',
        // Auth is enforced manually via KNX session (fail-closed)
        'permission_callback' => '__return_true',
    ]);
});

if (!function_exists('knx_api_payment_status')) {
    /**
     * API: Payment Status (KNX Session Authority)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    function knx_api_payment_status($request) {
        global $wpdb;

        // ───────────────────────────────────────────────────────────
        // STEP 1: Resolve order_id (GET or POST)
        // ───────────────────────────────────────────────────────────
        $order_id = $request->get_param('order_id');

        if (empty($order_id) || !is_numeric($order_id)) {
            // Keep knx_rest_error for consistency (JS parses JSON even on non-2xx)
            return knx_rest_error('order_id is required and must be numeric', 400);
        }

        $order_id = (int) $order_id;

        // ───────────────────────────────────────────────────────────
        // STEP 2: Resolve KNX Session (SSOT)
        // ───────────────────────────────────────────────────────────
        if (!function_exists('knx_get_session')) {
            return knx_rest_error('Authentication system unavailable', 500);
        }

        $session = knx_get_session();

        if (!$session) {
            return knx_rest_error('Authentication required', 401);
        }

        // Prefer KNX customer_id if available (canonical), else fallback to user_id
        $session_customer_id = 0;
        if (!empty($session->customer_id) && is_numeric($session->customer_id)) {
            $session_customer_id = (int) $session->customer_id;
        } elseif (!empty($session->user_id) && is_numeric($session->user_id)) {
            $session_customer_id = (int) $session->user_id;
        }

        if ($session_customer_id <= 0) {
            return knx_rest_error('Authentication required', 401);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 3: Fetch Order (READ-ONLY)
        // ───────────────────────────────────────────────────────────
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id,
                    customer_id,
                    status,
                    payment_status,
                    payment_transaction_id
             FROM {$wpdb->prefix}knx_orders
             WHERE id = %d
             LIMIT 1",
            $order_id
        ));

        if (!$order) {
            return knx_rest_error('Order not found', 404);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 4: Ownership Validation (KNX user)
        // ───────────────────────────────────────────────────────────
        // orders.customer_id references knx_users.id (KNX customer id)
        if (!empty($order->customer_id) && (int) $order->customer_id !== $session_customer_id) {
            return knx_rest_error('Access denied', 403);
        }

        // ───────────────────────────────────────────────────────────
        // STEP 5: Normalize statuses (defensive + KNX-A2 mapping)
        // ───────────────────────────────────────────────────────────
        $order_status   = strtolower(trim($order->status ?? ''));
        $payment_status = strtolower(trim($order->payment_status ?? ''));

        if ($order_status === '') $order_status = 'unknown';
        if ($payment_status === '') $payment_status = 'pending';

        // Fallback intent id from payments table if missing on order
        $payment_intent_id = (string) ($order->payment_transaction_id ?? '');

        if ($payment_intent_id === '') {
            $fallback_intent = $wpdb->get_var($wpdb->prepare(
                "SELECT provider_intent_id
                 FROM {$wpdb->prefix}knx_payments
                 WHERE order_id = %d AND provider = 'stripe'
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1",
                $order_id
            ));
            if (!empty($fallback_intent)) {
                $payment_intent_id = (string) $fallback_intent;
            }
        }

        // KNX-A2 Canon status mapping (frontend reads this)
        $normalized_status = 'pending';
        $redirect_url = null;

        if ($order_status === 'confirmed' || $payment_status === 'paid' || $payment_status === 'succeeded') {
            $normalized_status = 'confirmed';
            $redirect_url = home_url('/');
        } elseif ($payment_status === 'failed' || $payment_status === 'canceled' || $payment_status === 'cancelled') {
            $normalized_status = 'failed';
        } else {
            $normalized_status = 'pending';
        }

        // ───────────────────────────────────────────────────────────
        // STEP 6: SEALED RESPONSE CONTRACT
        // - Keep wrapper compatibility: { success, message, data }
        // - Also promote key fields to top-level in THIS endpoint
        // ───────────────────────────────────────────────────────────
        $data = [
            'order_id'          => $order_id,
            'order_status'      => $order_status,
            'payment_status'    => $payment_status,
            'payment_intent_id' => $payment_intent_id,
            'status'            => $normalized_status,
        ];

        if ($redirect_url) {
            $data['redirect_url'] = $redirect_url;
        }

        $body = [
            'success'           => true,
            'message'           => 'Payment status',
            'status'            => $normalized_status,
            'order_id'          => $order_id,
            'order_status'      => $order_status,
            'payment_status'    => $payment_status,
            'payment_intent_id' => $payment_intent_id,
            'redirect_url'      => $redirect_url,
            'data'              => $data,
        ];

        // Return WP_REST_Response directly to avoid changing global knx_rest_response()
        return new WP_REST_Response($body, 200);
    }
}
