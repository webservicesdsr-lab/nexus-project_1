<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX-A1.0 — PAYMENT INTENT CREATION (SNAPSHOT-ONLY)
 *
 * Endpoint: POST /wp-json/knx/v1/payments/create-intent
 *
 * Canon rules:
 * - Amount/currency come ONLY from orders.totals_snapshot (SSOT).
 * - Order MUST belong to the session user.
 * - Order status must be eligible for payment:
 *     pending_payment OR placed
 * - orders.payment_status must NOT be paid.
 * - knx_payments.status canonical:
 *     intent_created, authorized, paid, failed, cancelled
 *
 * Retry behavior:
 * - If there is an existing knx_payments row for this order with status intent_created,
 *   we retrieve the existing Stripe PaymentIntent and return its client_secret.
 * - If there are only failed/cancelled rows, we create a new intent and new payment row.
 *
 * DB requirements:
 * - knx_payments.checkout_attempt_key is NOT NULL UNIQUE -> we generate a new one per creation.
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/payments/create-intent', [
        'methods'  => 'POST',
        'callback' => function ($req) {
            if (function_exists('knx_rest_wrap')) {
                $wrapped = knx_rest_wrap('knx_api_create_payment_intent');
                return $wrapped($req);
            }
            return knx_api_create_payment_intent($req);
        },
        'permission_callback' => function () {
            return function_exists('knx_rest_permission_session')
                ? knx_rest_permission_session()()
                : true;
        },
    ]);
});

if (!function_exists('knx_api_create_payment_intent')) {
    function knx_api_create_payment_intent(WP_REST_Request $request) {
        global $wpdb;

        if (!function_exists('knx_get_session')) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Authentication system unavailable', 503)
                : new WP_REST_Response(['success' => false, 'message' => 'Authentication system unavailable'], 503);
        }

        $session = knx_get_session();
        if (!$session || empty($session->user_id)) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Authentication required', 401)
                : new WP_REST_Response(['success' => false, 'message' => 'Authentication required'], 401);
        }

        $user_id = (int) $session->user_id;

        $order_id = $request->get_param('order_id');
        if (empty($order_id) || !is_numeric($order_id)) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('order_id is required and must be numeric', 400)
                : new WP_REST_Response(['success' => false, 'message' => 'order_id is required'], 400);
        }
        $order_id = (int) $order_id;

        if (!function_exists('knx_stripe_init') || !knx_stripe_init()) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Payment system unavailable', 503)
                : new WP_REST_Response(['success' => false, 'message' => 'Payment system unavailable'], 503);
        }

        $orders_table   = $wpdb->prefix . 'knx_orders';
        $payments_table = $wpdb->prefix . 'knx_payments';

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, order_number, customer_id, totals_snapshot, status, payment_status
             FROM {$orders_table}
             WHERE id = %d
             LIMIT 1",
            $order_id
        ));

        if (!$order) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Order not found', 404)
                : new WP_REST_Response(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (!empty($order->customer_id) && (int) $order->customer_id !== $user_id) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Access denied', 403)
                : new WP_REST_Response(['success' => false, 'message' => 'Access denied'], 403);
        }

        $order_status = (string) ($order->status ?? '');
        $payment_status = strtolower((string) ($order->payment_status ?? ''));

        if ($payment_status === 'paid') {
            return function_exists('knx_rest_response')
                ? knx_rest_response(true, 'Order already paid', [
                    'already_paid' => true,
                    'order_id'     => $order_id,
                ], 200)
                : new WP_REST_Response(['success' => true, 'already_paid' => true, 'order_id' => $order_id], 200);
        }

        // Eligible order states for creating/retrying payments
        $eligible_order_states = ['pending_payment', 'placed'];
        if (!in_array($order_status, $eligible_order_states, true)) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Order not eligible for payment', 409)
                : new WP_REST_Response(['success' => false, 'message' => 'Order not eligible for payment'], 409);
        }

        if (empty($order->totals_snapshot)) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Order snapshot missing', 409)
                : new WP_REST_Response(['success' => false, 'message' => 'Order snapshot missing'], 409);
        }

        $snapshot = json_decode((string) $order->totals_snapshot, true);
        if (!is_array($snapshot)) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Order snapshot invalid', 409)
                : new WP_REST_Response(['success' => false, 'message' => 'Order snapshot invalid'], 409);
        }

        if (empty($snapshot['is_snapshot_locked'])) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Snapshot not locked', 409)
                : new WP_REST_Response(['success' => false, 'message' => 'Snapshot not locked'], 409);
        }

        $total = isset($snapshot['total']) ? (float) $snapshot['total'] : 0.0;
        if ($total <= 0) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Invalid order total', 409)
                : new WP_REST_Response(['success' => false, 'message' => 'Invalid order total'], 409);
        }

        $currency = isset($snapshot['currency']) ? strtolower((string) $snapshot['currency']) : 'usd';
        if ($currency === '') $currency = 'usd';

        $amount_cents = (int) round($total * 100);

        // If an existing intent_created payment exists, retrieve PI and reuse.
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, provider_intent_id, amount, currency, status
             FROM {$payments_table}
             WHERE order_id = %d
               AND provider = 'stripe'
               AND status = 'intent_created'
             ORDER BY created_at DESC
             LIMIT 1",
            $order_id
        ));

        if ($existing && !empty($existing->provider_intent_id)) {
            try {
                $pi = \Stripe\PaymentIntent::retrieve((string) $existing->provider_intent_id);

                // Defensive: ensure amount/currency still match
                $pi_amount = isset($pi->amount) ? (int) $pi->amount : null;
                $pi_currency = isset($pi->currency) ? strtolower((string) $pi->currency) : null;

                if ($pi_amount === $amount_cents && $pi_currency === $currency && !empty($pi->client_secret)) {
                    return function_exists('knx_rest_response')
                        ? knx_rest_response(true, 'Payment intent reused', [
                            'payment_intent_id' => (string) $existing->provider_intent_id,
                            'client_secret'     => (string) $pi->client_secret,
                            'intent_reused'     => true,
                        ], 200)
                        : new WP_REST_Response([
                            'success' => true,
                            'payment_intent_id' => (string) $existing->provider_intent_id,
                            'client_secret' => (string) $pi->client_secret,
                            'intent_reused' => true,
                        ], 200);
                }

                // If mismatch, fall through to create a new intent (do not overwrite old record).

            } catch (\Throwable $e) {
                // Fall through to create new intent
            }
        }

        // Create NEW Stripe PaymentIntent + persist knx_payments row.
        $checkout_attempt_key = 'atk_' . bin2hex(random_bytes(16));

        try {
            $stripe_mode = function_exists('knx_get_stripe_mode') ? knx_get_stripe_mode() : 'unknown';

            $intent = \Stripe\PaymentIntent::create([
                'amount'               => $amount_cents,
                'currency'             => $currency,
                'payment_method_types' => ['card'],
                'metadata'             => [
                    'order_id'            => (string) $order_id,
                    'order_number'        => (string) ($order->order_number ?? ''),
                    'checkout_attempt_key'=> (string) $checkout_attempt_key,
                    'mode'                => (string) $stripe_mode,
                    'integration'         => 'knx',
                ],
            ], [
                // Idempotency should be per-attempt (so retries of same request don't create multiple intents).
                'idempotency_key' => 'knx_pi_' . $checkout_attempt_key,
            ]);

            $provider_intent_id = (string) $intent->id;
            $client_secret      = (string) $intent->client_secret;

            if ($provider_intent_id === '' || $client_secret === '') {
                return function_exists('knx_rest_error')
                    ? knx_rest_error('Unable to initialize payment', 500)
                    : new WP_REST_Response(['success' => false, 'message' => 'Unable to initialize payment'], 500);
            }

        } catch (\Throwable $e) {
            return function_exists('knx_rest_error')
                ? knx_rest_error('Unable to initialize payment. Please try again.', 500)
                : new WP_REST_Response(['success' => false, 'message' => 'Unable to initialize payment. Please try again.'], 500);
        }

        // Persist payment record (amount in cents).
        $now = current_time('mysql');

        // Prefer helper if present (keeps race guards centralized).
        if (function_exists('knx_create_payment_record')) {
            $payment_id = knx_create_payment_record([
                'order_id'            => $order_id,
                'provider'            => 'stripe',
                'provider_intent_id'  => $provider_intent_id,
                'checkout_attempt_key'=> $checkout_attempt_key,
                'amount'              => $amount_cents,
                'currency'            => $currency,
                'status'              => 'intent_created',
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            if (!$payment_id) {
                return function_exists('knx_rest_error')
                    ? knx_rest_error('Failed to create payment record', 500)
                    : new WP_REST_Response(['success' => false, 'message' => 'Failed to create payment record'], 500);
            }
        } else {
            $ok = $wpdb->insert($payments_table, [
                'order_id'            => $order_id,
                'provider'            => 'stripe',
                'provider_intent_id'  => $provider_intent_id,
                'checkout_attempt_key'=> $checkout_attempt_key,
                'amount'              => $amount_cents,
                'currency'            => $currency,
                'status'              => 'intent_created',
                'created_at'          => $now,
                'updated_at'          => $now,
            ], ['%d','%s','%s','%s','%d','%s','%s','%s','%s']);

            if ($ok === false) {
                return function_exists('knx_rest_error')
                    ? knx_rest_error('Failed to create payment record', 500)
                    : new WP_REST_Response(['success' => false, 'message' => 'Failed to create payment record'], 500);
            }
        }

        return function_exists('knx_rest_response')
            ? knx_rest_response(true, 'Payment intent created', [
                'payment_intent_id' => $provider_intent_id,
                'client_secret'     => $client_secret,
                'checkout_attempt_key' => $checkout_attempt_key,
            ], 200)
            : new WP_REST_Response([
                'success' => true,
                'payment_intent_id' => $provider_intent_id,
                'client_secret' => $client_secret,
                'checkout_attempt_key' => $checkout_attempt_key,
            ], 200);
    }
}
