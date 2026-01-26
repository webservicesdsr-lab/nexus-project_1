<?php
/**
 * ████████████████████████████████████████████████████████████████
 * █ KNX-A1.0 — PAYMENT INTENT CREATION (SNAPSHOT-ONLY)
 * ████████████████████████████████████████████████████████████████
 * 
 * ENDPOINT: POST /wp-json/knx/v1/payments/create-intent
 * 
 * PURPOSE:
 * - Create Payment Intent EXCLUSIVELY from Order's totals_snapshot
 * - Zero recalculation, zero cart access, zero address validation
 * - Fail-closed on ANY validation error
 * 
 * RULES:
 * - Payment amount = totals_snapshot.total (IMMUTABLE)
 * - Currency = totals_snapshot.currency if present, else 'usd' (normalized lowercase)
 * - Order must be 'placed' status
 * - Snapshot must be locked (is_snapshot_locked === true)
 * - One payment per order (enforced by payment-helpers with race guard)
 * - Idempotency key generated deterministically per order_id
 * 
 * VALIDATIONS (HARD):
 * A. Order exists
 * B. Order status = placed
 * C. Snapshot exists
 * D. is_snapshot_locked === true
 * E. Total > 0
 * F. No existing active payment
 * 
 * PROHIBITED:
 * - Recalculating totals
 * - Reading cart/cart_items
 * - Modifying snapshot
 * - Introducing fees/taxes logic
 * - Creating dependencies with UI
 * 
 * RESPONSE:
 * {
 *   "success": true,
 *   "payment_intent_id": "...",
 *   "client_secret": "..."
 * }
 * 
 * @package KingdomNexus
 * @since A1.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Payment Intent Creation Endpoint
 */
add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/payments/create-intent', [
        'methods' => 'POST',
        'callback' => 'knx_api_create_payment_intent',
        // Use KNX session authority (not WP auth)
        'permission_callback' => function () {
            return function_exists('knx_rest_permission_session')
                ? knx_rest_permission_session()()
                : true;
        }
    ]);
});

/**
 * API: Create Payment Intent
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
if (!function_exists('knx_api_create_payment_intent')) {
    function knx_api_create_payment_intent($request) {
        global $wpdb;
        
        // ═══════════════════════════════════════════════════════════
        // VALIDATION 0: Stripe Initialization (SDK + API Key)
        // ═══════════════════════════════════════════════════════════
        if (!function_exists('knx_stripe_init') || !knx_stripe_init()) {
            if (function_exists('knx_stripe_log')) {
                knx_stripe_log('ERROR', 'Create intent failed: Stripe not initialized (SDK or API key missing)');
            }
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Payment system temporarily unavailable',
            ], 503);
        }
        
        // ═══════════════════════════════════════════════════════════
        // VALIDATION A: Extract order_id from request
        // ═══════════════════════════════════════════════════════════
        $order_id = $request->get_param('order_id');
        
        if (empty($order_id) || !is_numeric($order_id)) {
            return knx_rest_error('order_id is required and must be numeric', 400);
        }
        
        $order_id = intval($order_id);

        // ═══════════════════════════════════════════════════════════
        // VALIDATION A2: Require KNX session and ownership
        // ═══════════════════════════════════════════════════════════
        if (!function_exists('knx_get_session')) {
            return knx_rest_error('Authentication system unavailable', 500);
        }

        $session = knx_get_session();
        if (!$session || empty($session->user_id)) {
            return knx_rest_error('Authentication required', 401);
        }
        $session_user_id = (int) $session->user_id;
        
        // ═══════════════════════════════════════════════════════════
        // FETCH ORDER (needed for ownership guard BEFORE helper checks)
        // ═══════════════════════════════════════════════════════════
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, order_number, customer_id, totals_snapshot, status, payment_status
             FROM {$wpdb->prefix}knx_orders
             WHERE id = %d
             LIMIT 1",
            $order_id
        ));

        if (!$order) {
            return knx_rest_error('Order not found', 404);
        }

        // Ownership guard first (A2.9.1)
        if (!empty($order->customer_id) && (int)$order->customer_id !== $session_user_id) {
            return knx_rest_error('Access denied', 403);
        }

        // ═══════════════════════════════════════════════════════════
        // VALIDATION B-F: Can create payment? (after ownership guard)
        // ═══════════════════════════════════════════════════════════
        $can_create = knx_can_create_payment_for_order($order_id);

        if (!$can_create['ok']) {
            error_log("[KNX-A1.0] Cannot create payment intent: order_id=$order_id reason={$can_create['error']}");
            return knx_rest_error($can_create['error'], 400);
        }

        // Defensive guard: snapshot must exist
        if (empty($order->totals_snapshot)) {
            knx_payments_safe_authority_log('error', 'order_snapshot_missing', 'Create-intent failed - totals_snapshot missing', [
                'order_id' => $order_id
            ]);
            return knx_rest_error('Unable to create payment at this time. Please try again.', 500);
        }

        // KNX-A2.9: ANTI-DUPLICATE GUARD - Check if order already confirmed/paid
        $order_status = strtolower(trim($order->status ?? 'placed'));
        $payment_status = strtolower(trim($order->payment_status ?? ''));
        
        if ($order_status === 'confirmed' || $payment_status === 'paid' || $payment_status === 'succeeded') {
            knx_payments_safe_authority_log('info', 'already_paid_blocked', 'Order already confirmed/paid - blocking intent creation', [
                'order_id' => $order_id,
                'order_status' => $order_status,
                'payment_status' => $payment_status
            ]);
            
            return knx_rest_response(true, 'Order already paid', [
                'already_paid' => true,
                'order_id' => $order_id,
                'redirect_url' => home_url('/')
            ], 200);
        }

        // KNX-A2.9: IDEMPOTENCY - Check if payment intent already exists for this order
        $existing_payment = $wpdb->get_row($wpdb->prepare(
            "SELECT provider_intent_id, status FROM {$wpdb->prefix}knx_payments 
            WHERE order_id = %d AND provider = 'stripe' AND status IN ('intent_created', 'processing', 'pending')
            ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));

        if ($existing_payment && !empty($existing_payment->provider_intent_id)) {
            knx_payments_safe_authority_log('info', 'intent_reused', 'Reusing existing payment intent', [
                'order_id' => $order_id,
                'intent_id' => $existing_payment->provider_intent_id,
                'intent_status' => $existing_payment->status
            ]);
            
            // Return existing intent (frontend will proceed with polling or confirmation)
            return knx_rest_response(true, 'Payment intent already exists', [
                'payment_intent_id' => $existing_payment->provider_intent_id,
                'intent_reused' => true,
                'order_id' => $order_id
            ], 200);
        }
        
        $snapshot = json_decode($order->totals_snapshot, true);
        $total = $snapshot['total'];
        $currency = strtolower($snapshot['currency'] ?? 'usd');
        $amount_cents = intval(round($total * 100)); // SSOT: Convert to cents
        
        // ═══════════════════════════════════════════════════════════
        // CREATE REAL STRIPE PAYMENT INTENT
        // ═══════════════════════════════════════════════════════════
        try {
            $stripe_mode = knx_get_stripe_mode();
            
            // Generate deterministic idempotency key
            $idempotency_key = 'knx_order_' . $order_id . '_intent';
            
            $intent = \Stripe\PaymentIntent::create([
                'amount' => $amount_cents,
                'currency' => $currency,
                // Enforce cards-only for live rollout (cards-only plan)
                'payment_method_types' => ['card'],
                'metadata' => [
                    'order_id' => (string)$order_id,
                    'order_number' => $order->order_number,
                    'integration' => 'kingdom-nexus',
                    'mode' => $stripe_mode
                ]
            ], [
                'idempotency_key' => $idempotency_key
            ]);
            
            $provider_intent_id = $intent->id;
            $client_secret = $intent->client_secret;
            
            // KNX-A2.9: Log successful NEW intent creation
            knx_payments_safe_authority_log('info', 'intent_created_new', 'New PaymentIntent created', [
                'order_id' => $order_id,
                'intent_id' => $provider_intent_id,
                'amount' => $total,
                'currency' => $currency,
                'mode' => $stripe_mode
            ]);
            
        } catch (\Stripe\Exception\CardException $e) {
            knx_stripe_log(
                KNX_STRIPE_LOG_ERROR,
                'Card exception during intent creation',
                ['order_id' => $order_id, 'reason' => 'card_error']
            );
            
            return knx_rest_error('Card error. Please check your payment details.', 400);
            
        } catch (\Stripe\Exception\RateLimitException $e) {
            knx_stripe_log(
                KNX_STRIPE_LOG_ERROR,
                'Rate limit exceeded',
                ['order_id' => $order_id]
            );
            
            return knx_rest_error('Too many requests. Please try again in a moment.', 429);
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            knx_stripe_log(
                KNX_STRIPE_LOG_ERROR,
                'Invalid Stripe request',
                ['order_id' => $order_id]
            );
            
            return knx_rest_error('Invalid payment request. Please contact support.', 400);
            
        } catch (\Stripe\Exception\AuthenticationException $e) {
            knx_stripe_log(
                KNX_STRIPE_LOG_SECURITY,
                'Stripe authentication failed',
                ['order_id' => $order_id]
            );
            
            return knx_rest_error('Payment system authentication error.', 500);
            
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            knx_stripe_log(
                KNX_STRIPE_LOG_ERROR,
                'Stripe API connection failed',
                ['order_id' => $order_id]
            );
            
            return knx_rest_error('Unable to connect to payment processor. Please try again.', 503);
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            knx_stripe_log(
                KNX_STRIPE_LOG_ERROR,
                'Stripe API error',
                ['order_id' => $order_id]
            );
            
            return knx_rest_error('Payment processing error. Please try again.', 500);
            
        } catch (\Exception $e) {
            knx_stripe_log(
                KNX_STRIPE_LOG_ERROR,
                'Unexpected error during PaymentIntent creation',
                ['order_id' => $order_id]
            );
            
            return knx_rest_error('Unable to initialize payment. Please try again.', 500);
        }
        
        // ═══════════════════════════════════════════════════════════
        // PERSIST PAYMENT RECORD (amount in cents)
        // ═══════════════════════════════════════════════════════════
        $payment_id = knx_create_payment_record([
            'order_id' => $order_id,
            'provider' => 'stripe',
            'provider_intent_id' => $provider_intent_id,
            'amount' => $amount_cents,
            'currency' => $currency,
            'status' => 'intent_created'
        ]);
        
        if (!$payment_id) {
            knx_stripe_log(
                KNX_STRIPE_LOG_ERROR,
                'Failed to persist payment record',
                ['order_id' => $order_id, 'intent' => $provider_intent_id]
            );
            
            return knx_rest_error('Failed to create payment record', 500);
        }
        
        // ═══════════════════════════════════════════════════════════
        // SUCCESS RESPONSE (MINIMAL)
        // ═══════════════════════════════════════════════════════════
        knx_stripe_log(
            KNX_STRIPE_LOG_INTENT,
            'Payment record persisted',
            [
                'payment_id' => $payment_id,
                'order_id' => $order_id,
                'intent' => $provider_intent_id
            ]
        );
        
        return knx_rest_response(true, 'Payment intent created', [
            'client_secret' => $client_secret,
            'payment_intent_id' => $provider_intent_id
        ], 200);
    }
}
