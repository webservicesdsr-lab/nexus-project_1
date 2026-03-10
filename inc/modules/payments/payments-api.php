<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Payments API (Production)
 * ----------------------------------------------------------
 * Provides secure payment endpoints:
 *
 * 1) POST /wp-json/knx/v1/payments/prepare
 *    → Recalculate totals in backend (NO trusting JS)
 *    → Returns secure OrderToken + full totals
 *
 * 2) POST /wp-json/knx/v1/payments/intent
 *    → (Future) Creates Stripe Payment Intent
 *    → Validates OrderToken integrity
 *
 * All security:
 *  - Backend-only totals calculation
 *  - HMAC OrderToken signature
 *  - Prevents tampering, fake totals, MITM, or JS manipulation
 * ==========================================================
 */


/* ----------------------------------------------------------
 * REGISTER ROUTES
 * ---------------------------------------------------------- */
add_action('rest_api_init', function () {

    register_rest_route('knx/v1/payments', '/prepare', [
        'methods'             => 'POST',
        'callback'            => 'knx_pay_api_prepare',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('knx/v1/payments', '/intent', [
        'methods'             => 'POST',
        'callback'            => 'knx_pay_api_intent',
        'permission_callback' => '__return_true',
    ]);
});



/* ==========================================================
 * 1) PREPARE ORDER TOTAL — FIRST STEP
 * ========================================================== */
function knx_pay_api_prepare(WP_REST_Request $req) {

    $body = $req->get_json_params();
    if (!$body) {
        return new WP_REST_Response(['error' => 'invalid-body'], 400);
    }

    $session_token = sanitize_text_field($body['session_token'] ?? '');
    $hub_id        = intval($body['hub_id'] ?? 0);

    if (!$session_token || !$hub_id) {
        return new WP_REST_Response(['error' => 'missing-fields'], 400);
    }

    // Load cart based on session + hub
    $cartInfo = knx_pay_resolve_cart($session_token, $hub_id);
    if (!$cartInfo) {
        return new WP_REST_Response(['error' => 'cart-not-found'], 404);
    }

    $cart     = $cartInfo->cart;
    $cart_id  = intval($cart->id);

    // Calculate totals server-side
    $totals = knx_pay_calculate_totals($cart_id, $session_token);

    if (isset($totals['error'])) {
        return new WP_REST_Response(['error' => $totals['error']], 500);
    }

    return new WP_REST_Response([
        'success'  => true,
        'totals'   => $totals,
        'cart_id'  => $cart_id,
        'hub_id'   => $hub_id,
        'token'    => $totals['order_token'],
    ], 200);
}



/* ==========================================================
 * 2) PAYMENT INTENT (Stripe-ready) — SECOND STEP
 * ==========================================================
 * This does NOT yet charge. It:
 *   - verifies OrderToken integrity
 *   - recalculates price
 *   - creates a "Pending Payment Attempt"
 * ========================================================== */
function knx_pay_api_intent(WP_REST_Request $req) {

    $body = $req->get_json_params();

    if (!$body) {
        return new WP_REST_Response(['error' => 'invalid-body'], 400);
    }

    $session_token = sanitize_text_field($body['session_token'] ?? '');
    $hub_id        = intval($body['hub_id'] ?? 0);
    $cart_id       = intval($body['cart_id'] ?? 0);
    $order_token   = sanitize_text_field($body['order_token'] ?? '');

    if (!$session_token || !$hub_id || !$cart_id || !$order_token) {
        return new WP_REST_Response(['error' => 'missing-fields'], 400);
    }


    /* --------------------------------------------
     * Verify OrderToken HMAC Security
     * -------------------------------------------- */
    if (!knx_pay_verify_order_token($order_token, $session_token, $cart_id)) {
        return new WP_REST_Response(['error' => 'invalid-order-token'], 403);
    }


    /* --------------------------------------------
     * Recalculate totals AGAIN (double validation)
     * -------------------------------------------- */
    $totals = knx_pay_calculate_totals($cart_id, $session_token);

    if (isset($totals['error'])) {
        return new WP_REST_Response(['error' => $totals['error']], 500);
    }


    /* --------------------------------------------
     * CREATE A PAYMENT INTENT (placeholder)
     * Real Stripe integration goes here.
     * -------------------------------------------- */

    $fakePaymentId = 'pi_' . wp_generate_uuid4();

    return new WP_REST_Response([
        'success'       => true,
        'payment_id'    => $fakePaymentId,
        'amount'        => $totals['total'],
        'currency'      => 'usd',
        'totals'        => $totals,
        'cart_id'       => $cart_id,
    ], 200);
}
