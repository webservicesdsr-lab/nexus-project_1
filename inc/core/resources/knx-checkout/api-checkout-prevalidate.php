<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Checkout Pre-Validation API (Canonical)
 * Endpoint:
 *   POST /wp-json/knx/v1/checkout/prevalidate
 * ----------------------------------------------------------
 * Secures the checkout by validating:
 * - Session token (guest or logged-in)
 * - Cart existence in DB
 * - Cart has items
 * - Hub exists
 * - Hub availability enforced via canonical Availability Engine
 * - Subtotal integrity
 *
 * Returns a safe payload used by checkout-payment-flow.js
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/checkout/prevalidate', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_checkout_prevalidate'),
        'permission_callback' => '__return_true',
    ]);
});


function knx_api_checkout_prevalidate(WP_REST_Request $req) {
    global $wpdb;

    $table_carts      = $wpdb->prefix . 'knx_carts';
    $table_cart_items = $wpdb->prefix . 'knx_cart_items';
    $table_hubs       = $wpdb->prefix . 'knx_hubs';

    // ----------------------------------------
    // 1) Parse and validate fulfillment_type (CANON)
    // ----------------------------------------
    $body = $req->get_json_params();
    
    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE START - Request body: ' . json_encode($body));
    
    $fulfillment_type = isset($body['fulfillment_type']) ? (string) $body['fulfillment_type'] : 'delivery';
    $fulfillment_type = strtolower(trim($fulfillment_type));
    
    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Fulfillment type: ' . $fulfillment_type);
    
    if (!in_array($fulfillment_type, ['delivery', 'pickup'], true)) {
        error_log('[KNX-CHECKOUT-3.5] PREVALIDATE GATE - Invalid fulfillment_type: ' . $fulfillment_type);
        return new WP_REST_Response([
            'success'      => true,
            'can_checkout' => false,
            'gate'         => 'FULFILLMENT',
            'reason'       => 'INVALID_FULFILLMENT_TYPE',
            'message'      => 'Invalid fulfillment type. Must be delivery or pickup.',
            'redirect_to'  => site_url('/cart')
        ], 200);
    }

    // ----------------------------------------
    // 1.5) NEXUS 4.D.3: Address validation for delivery
    // ----------------------------------------
    $delivery_address = null; // Will be used for coverage gate
    
    if ($fulfillment_type === 'delivery') {
        error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Checking delivery address...');
        $customer_id = 0;
        
        // Canonical customer resolver (same used in addresses)
        if (function_exists('knx_addresses_get_customer_id')) {
            $customer_id = knx_addresses_get_customer_id();
        }
        
        error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Customer ID: ' . $customer_id);

        $payload_address_id = isset($body['address_id']) ? (int) $body['address_id'] : 0;

        if ($customer_id > 0 && function_exists('knx_session_get_selected_address_id')) {
            $selected_id = $payload_address_id > 0 ? $payload_address_id : knx_session_get_selected_address_id();
            
            error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Selected address ID: ' . $selected_id);

            // HARD GATE: Delivery requires valid address (UX AUTHORITY)
            if ($selected_id <= 0) {
                error_log('[KNX-CHECKOUT-3.5] PREVALIDATE GATE - No address selected for delivery');
                return new WP_REST_Response([
                    'success'      => true,
                    'can_checkout' => false,
                    'gate'         => 'ADDRESS',
                    'reason'       => 'ADDRESS_REQUIRED',
                    'message'      => 'Please select a delivery address to continue.',
                    'redirect_to'  => site_url('/cart')
                ], 200);
            }

            // Verify selected address is valid
            if (function_exists('knx_get_address_by_id')) {
                $addr = knx_get_address_by_id($selected_id, $customer_id);
                if (!$addr) {
                    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE GATE - Address not found: ' . $selected_id);
                    return new WP_REST_Response([
                        'success'      => true,
                        'can_checkout' => false,
                        'gate'         => 'ADDRESS',
                        'reason'       => 'ADDRESS_NOT_FOUND',
                        'message'      => 'Selected address not found. Please select another.',
                        'redirect_to'  => site_url('/cart')
                    ], 200);
                }

                $lat = isset($addr->latitude) ? (float) $addr->latitude : 0.0;
                $lng = isset($addr->longitude) ? (float) $addr->longitude : 0.0;
                
                error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Address coords: ' . $lat . ', ' . $lng);

                if ($lat == 0.0 || $lng == 0.0) {
                    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE GATE - Missing coordinates');
                    return new WP_REST_Response([
                        'success'      => true,
                        'can_checkout' => false,
                        'gate'         => 'ADDRESS',
                        'reason'       => 'MISSING_COORDS',
                        'message'      => 'Please pin your delivery location on the map.',
                        'redirect_to'  => site_url('/cart')
                    ], 200);
                }
                
                // Store for coverage gate
                $delivery_address = $addr;
                error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Delivery address OK');
            }
        }
    }

    // ----------------------------------------
    // 2) Resolve cart token (CANONICAL - FIX 1 + FIX 2)
    // ----------------------------------------
    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Resolving cart token...');
    $token_resolution = knx_resolve_cart_token($req);
    
    // Block token mismatch (security hardening)
    if ($token_resolution['mismatch']) {
        error_log('[KNX-CHECKOUT-3.5] PREVALIDATE SECURITY - Token mismatch');
        return new WP_REST_Response([
            'success' => false,
            'ok'      => false,
            'error'   => 'token_mismatch',
            'message' => 'Cart token mismatch detected.'
        ], 403);
    }
    
    $session_token = $token_resolution['token'];
    
    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Cart token: ' . $session_token);
    
    if ($session_token === null || $session_token === '') {
        error_log('[KNX-CHECKOUT-3.5] PREVALIDATE SECURITY - No cart token');
        return new WP_REST_Response([
            'success' => false,
            'ok'      => false,
            'error'   => 'missing_session',
            'message' => 'No cart token provided.'
        ], 400);
    }

    // ----------------------------------------
    // 3) Get active cart for this session
    // ----------------------------------------
    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Fetching cart...');
    $cart = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_carts}
         WHERE session_token = %s AND status = 'active'
         ORDER BY updated_at DESC
         LIMIT 1",
        $session_token
    ));

    if (!$cart) {
        error_log('[KNX-CHECKOUT-3.5] PREVALIDATE GATE - Cart not found for token: ' . $session_token);
        return new WP_REST_Response([
            'success'      => true,
            'can_checkout' => false,
            'gate'         => 'CART',
            'reason'       => 'CART_NOT_FOUND',
            'message'      => 'Cart does not exist or expired.',
            'redirect_to'  => site_url('/cart')
        ], 200);
    }
    
    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Cart found: ID=' . $cart->id . ', Hub=' . $cart->hub_id);

    // ----------------------------------------
    // 4) Fetch cart items
    // ----------------------------------------
    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_cart_items}
             WHERE cart_id = %d
             ORDER BY id ASC",
            $cart->id
        )
    );

    if (empty($items)) {
        error_log('[KNX-CHECKOUT-3.5] PREVALIDATE GATE - Empty cart: ' . $cart->id);
        return new WP_REST_Response([
            'success'      => true,
            'can_checkout' => false,
            'gate'         => 'CART',
            'reason'       => 'EMPTY_CART',
            'message'      => 'The cart has no items.',
            'redirect_to'  => site_url('/cart')
        ], 200);
    }
    
    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Cart has ' . count($items) . ' items');

    // ----------------------------------------
    // 4) Fetch hub info
    // ----------------------------------------
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name
         FROM {$table_hubs}
         WHERE id = %d",
        $cart->hub_id
    ));

    if (!$hub) {
        error_log('[KNX-CHECKOUT-3.5] PREVALIDATE GATE - Hub not found: ' . $cart->hub_id);
        return new WP_REST_Response([
            'success'      => true,
            'can_checkout' => false,
            'gate'         => 'HUB',
            'reason'       => 'HUB_NOT_FOUND',
            'message'      => 'Hub does not exist.',
            'redirect_to'  => site_url('/cart')
        ], 200);
    }
    
    error_log('[KNX-CHECKOUT-3.5] PREVALIDATE - Hub found: ' . $hub->name);

    // ================================================================
    // PHASE 4.3 / KNX-A0.4: COVERAGE HARD GATE (CHECKOUT)
    // ================================================================
    // HARD enforcement: Delivery MUST be within coverage.
    // Only runs for delivery (not pickup).
    // Uses SSOT coverage authority (knx_check_coverage).
    // Does NOT mutate state, does NOT write DB.
    // Returns 200 OK with can_checkout=false to block UI gracefully.
    // ================================================================
    
    if ($fulfillment_type === 'delivery' && $delivery_address !== null) {
        $hub_id = (int) $cart->hub_id;
        $lat = isset($delivery_address->latitude) ? (float) $delivery_address->latitude : 0.0;
        $lng = isset($delivery_address->longitude) ? (float) $delivery_address->longitude : 0.0;

        // Coverage enforcement (only if valid context)
        if ($hub_id > 0 && $lat !== 0.0 && $lng !== 0.0) {
            
            // Coverage SSOT missing → fail-open by design (system degradation)
            if (!function_exists('knx_check_coverage')) {
                // Intentionally allow checkout to proceed
                // Coverage will be enforced again at order creation (Phase 4.6)
            } else {
                $coverage = knx_check_coverage($hub_id, $lat, $lng);

                // Fail-closed: Block checkout if not deliverable
                if (!$coverage['ok']) {
                    $reason = $coverage['reason'] ?? 'UNKNOWN';

                    // Map technical reason to user-safe message (CANONICAL - same as KNX-A0.3)
                    $user_messages = [
                        'OUT_OF_COVERAGE'    => 'This address is outside our delivery area.',
                        'NO_ACTIVE_ZONE'     => 'Delivery is not available for this restaurant yet.',
                        'DELIVERY_DISABLED'  => 'Delivery is currently unavailable.',
                        'ZONE_NOT_POLYGON'   => 'Delivery coverage is temporarily unavailable.',
                        'INVALID_POLYGON'    => 'Delivery coverage is temporarily unavailable.',
                        'INVALID_JSON'       => 'Delivery coverage is temporarily unavailable.',
                        'MISSING_POLYGON'    => 'Delivery coverage is temporarily unavailable.',
                        'HUB_NOT_FOUND'      => 'Restaurant not found.',
                        'INVALID_HUB_ID'     => 'Restaurant not found.',
                        'MISSING_COORDS'     => 'Address location is missing.',
                    ];

                    $message = $user_messages[$reason] ?? 'Delivery is not available to this address.';

                    // Return 200 OK (not error) with can_checkout=false
                    return new WP_REST_Response([
                        'success'      => true,  // Request succeeded
                        'can_checkout' => false, // But cannot proceed
                        'gate'         => 'COVERAGE',
                        'reason'       => $reason,
                        'message'      => $message,
                        'redirect_to'  => site_url('/cart')
                    ], 200);
                }
            }
        }
    }

    // ----------------------------------------
    // 5) Validate hub availability (HARD GATE - Phase 1.5.C)
    // ----------------------------------------
    if (!function_exists('knx_availability_decision')) {
        return new WP_REST_Response([
            'success' => false,  // FIX 3: Both flags
            'ok'      => false,
            'code'    => 'KNX_NOT_ORDERABLE',
            'reason'  => 'SYSTEM_UNAVAILABLE',
            'message' => 'Ordering is temporarily unavailable.',
            'meta'    => [  // FIX 4: Safe meta keys
                'reopen_at' => null,
                'source'    => 'availability_engine_missing',
                'severity'  => null,
                'timezone'  => null
            ]
        ], 503);
    }

    $decision = knx_availability_decision((int) $cart->hub_id);

    if ($decision['can_order'] === false) {
        return new WP_REST_Response([
            'success'      => true,
            'can_checkout' => false,
            'gate'         => 'AVAILABILITY',
            'reason'       => isset($decision['reason']) ? $decision['reason'] : 'UNKNOWN',
            'message'      => isset($decision['message']) ? $decision['message'] : 'Not available for orders.',
            'meta'         => [
                'reopen_at' => isset($decision['reopen_at']) ? $decision['reopen_at'] : null,
                'source'    => isset($decision['source']) ? $decision['source'] : null,
                'severity'  => isset($decision['severity']) ? $decision['severity'] : null,
                'timezone'  => isset($decision['timezone']) ? $decision['timezone'] : null
            ],
            'redirect_to' => site_url('/cart')
        ], 200);
    }

    // ----------------------------------------
    // 6) Validate subtotal consistency
    // ----------------------------------------
    $computed_subtotal = 0.0;

    foreach ($items as $line) {
        $line_total = isset($line->line_total)
            ? (float) $line->line_total
            : 0;

        $computed_subtotal += $line_total;
    }

    // Allow small floating discrepancies (Stripe level)
    if (abs($computed_subtotal - (float)$cart->subtotal) > 0.05) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'subtotal_mismatch',
            'message' => 'Subtotal mismatch detected.'
        ], 400);
    }

    // ----------------------------------------
    // 7) Build sanitized response for frontend
    // ----------------------------------------
    return new WP_REST_Response([
        'success'         => true,
        'ok'              => true,
        'cart_id'         => (int) $cart->id,
        'hub_id'          => (int) $hub->id,
        'hub_name'        => $hub->name,
        'subtotal'        => round($computed_subtotal, 2),
        'fulfillment_type'=> $fulfillment_type,  // CANON: Include in response
        'next_step'       => 'ready_for_payment',
        'message'         => 'Cart validated successfully.',
        'availability'    => $decision  // Include availability for consistency
    ], 200);
}
