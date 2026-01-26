<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Payments Helpers (Production)
 * ----------------------------------------------------------
 * Provides:
 *   - Secure OrderToken generation (HMAC SHA256)
 *   - Expiration handling
 *   - Verification to prevent price tampering
 *   - DB fetch helpers
 *
 * Tokens ensure:
 *   - cart_id cannot be swapped
 *   - session_token cannot be forged
 *   - totals cannot be manipulated
 *   - tokens expire safely (default 10 min)
 * ==========================================================
 */


/* ----------------------------------------------------------
 * SECRET KEY (use WP Salt + plugin seed)
 * ---------------------------------------------------------- */
function knx_pay_secret_key() {
    return hash('sha256', AUTH_SALT . SECURE_AUTH_SALT . 'KNX_PAYMENT_V1');
}



/* ==========================================================
 * CREATE ORDER TOKEN
 * ----------------------------------------------------------
 * OrderToken = base64(json) + "." + HMAC_SHA256(signature)
 *
 * Payload contains:
 *   - cart_id
 *   - session_token
 *   - ts  (timestamp)
 *   - exp (expires time)
 *
 * NO TOTALS are included in token → totals MUST be recalculated.
 * ==========================================================
 */
function knx_pay_build_order_token($cart_id, $session_token) {

    $now  = time();
    $exp  = $now + (10 * 60); // 10 minutes

    $payload = [
        'cart_id'        => intval($cart_id),
        'session_token'  => $session_token,
        'ts'             => $now,
        'exp'            => $exp,
    ];

    $json = wp_json_encode($payload);
    $b64  = base64_encode($json);

    $secret = knx_pay_secret_key();
    $sig    = hash_hmac('sha256', $b64, $secret);

    return $b64 . '.' . $sig;
}



/* ==========================================================
 * VERIFY ORDER TOKEN
 * ----------------------------------------------------------
 * Steps:
 *   1) Split token
 *   2) Recompute HMAC
 *   3) Compare signatures
 *   4) Validate expiration
 *   5) Validate cart_id + session_token match input
 *
 * Returns TRUE if valid.
 * ==========================================================
 */
function knx_pay_verify_order_token($token, $session_token, $cart_id) {

    if (!$token || strpos($token, '.') === false) {
        return false;
    }

    list($b64, $sig) = explode('.', $token, 2);

    $secret = knx_pay_secret_key();
    $check  = hash_hmac('sha256', $b64, $secret);

    // SIGNATURE MISMATCH = TAMPERING
    if (!hash_equals($check, $sig)) {
        return false;
    }

    $json = base64_decode($b64, true);
    if (!$json) return false;

    $payload = json_decode($json, true);
    if (!is_array($payload)) return false;

    // Expired?
    if (($payload['exp'] ?? 0) < time()) {
        return false;
    }

    // Validate cart_id + session_token match request
    if (intval($payload['cart_id']) !== intval($cart_id)) {
        return false;
    }

    if (($payload['session_token'] ?? '') !== $session_token) {
        return false;
    }

    return true;
}



/* ==========================================================
 * FETCH CART + ITEMS FROM DB
 * ----------------------------------------------------------
 * This ensures all calculations are done server-side ONLY.
 * ==========================================================
 */
function knx_pay_fetch_cart_items($cart_id) {
    global $wpdb;

    $table_carts      = $wpdb->prefix . 'knx_carts';
    $table_cart_items = $wpdb->prefix . 'knx_cart_items';

    $cart = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_carts WHERE id = %d LIMIT 1", $cart_id)
    );

    if (!$cart) return null;

    $items = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table_cart_items WHERE cart_id = %d", $cart_id)
    );

    return (object)[
        'cart'  => $cart,
        'items' => $items
    ];
}



/* ==========================================================
 * RESOLVE CART USING SESSION TOKEN + HUB
 * ----------------------------------------------------------
 * Used for initial prepare-step in payments API.
 * ==========================================================
 */
function knx_pay_resolve_cart($session_token, $hub_id) {
    global $wpdb;

    $table_carts = $wpdb->prefix . 'knx_carts';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_carts
             WHERE session_token = %s
             AND hub_id = %d
             AND status = 'active'
             ORDER BY updated_at DESC
             LIMIT 1",
            $session_token,
            $hub_id
        )
    );

    return $row ? (object)['cart' => $row] : null;
}



/* ==========================================================
 * CALCULATE TOTALS SECURELY
 * ----------------------------------------------------------
 * This is the heart of price security.
 *
 * It:
 *   - Loads DB items
 *   - Runs backend pricing rules
 *   - Adds taxes & fees (future)
 *   - Builds an OrderToken
 *
 * Returns:
 *   [
 *     'items'  => [...],
 *     'subtotal' => float,
 *     'tax' => float,
 *     'delivery_fee' => float,
 *     'service_fee' => float,
 *     'total' => float,
 *     'order_token' => string,
 *   ]
 * ==========================================================
 */
function knx_pay_calculate_totals($cart_id, $session_token) {

    $data = knx_pay_fetch_cart_items($cart_id);
    if (!$data) {
        return ['error' => 'cart-not-found'];
    }

    $cart  = $data->cart;
    $items = $data->items;
    $hub_id = intval($cart->hub_id);

    $subtotal = 0;

    foreach ($items as $line) {
        $subtotal += floatval($line->line_total);
    }

    // Placeholder backend rules (customize later)
    $tax_rate = 0.00;
    $delivery_fee = 0.00;
    $service_fee = 0.00;

    $tax = round($subtotal * $tax_rate, 2);
    $total = $subtotal + $tax + $delivery_fee + $service_fee;

    // Secure token
    $orderToken = knx_pay_build_order_token($cart_id, $session_token);

    return [
        'items'         => $items,
        'subtotal'      => round($subtotal, 2),
        'tax'           => $tax,
        'delivery_fee'  => $delivery_fee,
        'service_fee'   => $service_fee,
        'total'         => round($total, 2),
        'order_token'   => $orderToken,
    ];
}

