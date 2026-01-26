<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX CHECKOUT QUOTE API (MVP CANONICAL)
 * Endpoint: POST /wp-json/knx/v1/checkout/quote
 *
 * Responsibilities:
 * - Resolve cart token (SSOT)
 * - HARD availability gate (knx_availability_decision)
 * - Delivery coverage gate (polygon-only) when delivery (KNX-A0.5)
 * - ADDRESS VALIDATION GATE (NEXUS 4.D.3)
 * - Totals calculation ONLY via knx_totals_quote()
 * - READ-ONLY (no DB writes)
 *
 * Rule:
 * - Never creates orders (use /orders/create-mvp)
 * - Always returns 200 OK with can_checkout flag
 * ==========================================================
 */

/**
 * Helper: Block quote with 200-safe response contract.
 * Used for gates (address, coverage, availability, etc.)
 * 
 * @param string $gate Gate identifier (ADDRESS|COVERAGE|AVAILABILITY)
 * @param string $reason Technical reason code
 * @param string $message User-safe message
 * @return WP_REST_Response
 */
function knx_quote_block($gate, $reason, $message) {
    return new WP_REST_Response([
        'success'      => true,  // Request succeeded
        'can_checkout' => false, // But cannot proceed
        'gate'         => $gate,
        'reason'       => $reason,
        'message'      => $message,
        'redirect_to'  => site_url('/cart')
    ], 200);
}

/**
 * Resolve and validate selected delivery address for quote.
 * Returns [address|null, address_id:int, can_checkout:bool, reason_code|null]
 */
function knx_checkout_resolve_address_for_quote($customer_id, $fulfillment_type, $payload_address_id = 0) {
    $customer_id = (int) $customer_id;
    $payload_address_id = (int) $payload_address_id;

    $selected = 0;
    if ($payload_address_id > 0) {
        $selected = $payload_address_id;
    } elseif (function_exists('knx_session_get_selected_address_id')) {
        $selected = (int) knx_session_get_selected_address_id();
    }

    if ($fulfillment_type !== 'delivery') {
        return [null, 0, true, null];
    }

    if ($selected <= 0) {
        return [null, 0, false, 'ADDRESS_REQUIRED'];
    }

    $addr = function_exists('knx_get_address_by_id') ? knx_get_address_by_id($selected, $customer_id) : null;
    if (!$addr) {
        return [null, 0, false, 'ADDRESS_NOT_FOUND'];
    }

    $lat = isset($addr->latitude) ? (float) $addr->latitude : 0.0;
    $lng = isset($addr->longitude) ? (float) $addr->longitude : 0.0;

    // Require coordinates for full delivery quote (distance/ETA)
    if ($lat == 0.0 || $lng == 0.0) {
        return [$addr, $selected, false, 'MISSING_COORDS'];
    }

    return [$addr, $selected, true, null];
}

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/checkout/quote', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_checkout_quote'),
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Checkout Quote (MVP).
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function knx_api_checkout_quote(WP_REST_Request $req) {
    global $wpdb;

    $table_carts      = $wpdb->prefix . 'knx_carts';
    $table_cart_items = $wpdb->prefix . 'knx_cart_items';
    $table_hubs       = $wpdb->prefix . 'knx_hubs';

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_error('invalid_request', 400);
    }

    // ------------------------------------------------------
    // Resolve cart token
    // ------------------------------------------------------
    if (!function_exists('knx_resolve_cart_token')) {
        return knx_rest_error('token_resolver_missing', 503);
    }

    $token_resolution = knx_resolve_cart_token($req);
    if (!empty($token_resolution['mismatch'])) {
        return knx_rest_error('token_mismatch', 403);
    }

    $session_token = isset($token_resolution['token']) ? (string) $token_resolution['token'] : '';
    if ($session_token === '') {
        return knx_rest_error('missing_session_token', 401);
    }

    // ------------------------------------------------------
    // Parse request
    // ------------------------------------------------------
    $hub_id = isset($body['hub_id']) ? (int) $body['hub_id'] : 0;
    if ($hub_id <= 0) {
        return knx_rest_error('hub_id_required', 400);
    }

    $fulfillment_type = isset($body['fulfillment_type']) ? (string) $body['fulfillment_type'] : 'delivery';
    $fulfillment_type = strtolower(trim($fulfillment_type));
    if (!in_array($fulfillment_type, ['delivery', 'pickup'], true)) {
        return knx_quote_block('FULFILLMENT', 'INVALID_FULFILLMENT_TYPE', 'Invalid fulfillment type. Must be delivery or pickup.');
    }

    // NEXUS 4.D.3: Resolve address_id from payload (optional override)
    $payload_address_id = isset($body['address_id']) ? (int) $body['address_id'] : 0;

    // Legacy delivery_address support (optional text)
    $delivery_address = isset($body['delivery_address']) ? sanitize_textarea_field($body['delivery_address']) : '';

    $delivery_lat = null;
    if (array_key_exists('delivery_lat', $body) && is_numeric($body['delivery_lat'])) {
        $delivery_lat = (float) $body['delivery_lat'];
    }

    $delivery_lng = null;
    if (array_key_exists('delivery_lng', $body) && is_numeric($body['delivery_lng'])) {
        $delivery_lng = (float) $body['delivery_lng'];
    }

    $tip_amount = isset($body['tip_amount']) ? (float) $body['tip_amount'] : 0.00;
    if ($tip_amount < 0) $tip_amount = 0.00;

    // ======================================================
    // NEXUS 4.D.3: ADDRESS RESOLUTION + VALIDATION GATE
    // ======================================================
    $selected_address = null;
    $selected_address_id = 0;
    $can_checkout = true;
    $address_reason_code = null;

    // Get customer_id from session for address ownership (CANONICAL)
    $customer_id = 0;
    if (function_exists('knx_addresses_get_customer_id')) {
        $customer_id = knx_addresses_get_customer_id();
    }

    if ($customer_id > 0 && function_exists('knx_checkout_resolve_address_for_quote')) {
        list($addr, $addr_id, $can_check, $reason) = knx_checkout_resolve_address_for_quote(
            $customer_id,
            $fulfillment_type,
            $payload_address_id
        );

        $selected_address = $addr;
        $selected_address_id = $addr_id;
        $can_checkout = $can_check;
        $address_reason_code = $reason;

        // If address resolved with coords, use them for calculations
        if ($addr && isset($addr->latitude) && isset($addr->longitude)) {
            $lat = (float) $addr->latitude;
            $lng = (float) $addr->longitude;
            if ($lat != 0.0 && $lng != 0.0) {
                $delivery_lat = $lat;
                $delivery_lng = $lng;

                // Also set delivery_address from address one-line if available
                if (function_exists('knx_addresses_format_one_line')) {
                    $delivery_address = knx_addresses_format_one_line($addr);
                }
            }
        }

        // HARD GATE: If delivery and cannot checkout, block with 200-safe response
        if ($fulfillment_type === 'delivery' && !$can_checkout) {
            // Canonical message mapping (same as KNX-A0.3/A0.4)
            $ux_messages = [
                'ADDRESS_REQUIRED'  => 'Please select a delivery address to continue.',
                'ADDRESS_NOT_FOUND' => 'Selected address not found. Please select another.',
                'MISSING_COORDS'    => 'Please pin your delivery location on the map.',
            ];

            $message = isset($ux_messages[$address_reason_code])
                ? $ux_messages[$address_reason_code]
                : 'Please add a valid delivery address to continue.';

            return knx_quote_block('ADDRESS', $address_reason_code, $message);
        }

        // Store selected address in session SSOT
        if ($selected_address_id > 0 && function_exists('knx_session_set_selected_address_id')) {
            knx_session_set_selected_address_id($selected_address_id);
        }
    }

    // ======================================================
    // KNX-A0.5: COVERAGE GATE (Delivery Mode Only)
    // Uses SSOT coverage authority (knx_check_coverage).
    // Enforced AFTER address validation, BEFORE cart fetch.
    // ======================================================
    if ($fulfillment_type === 'delivery') {
        // Resolve coordinates from validated address or payload
        $lat = null;
        $lng = null;

        if ($selected_address && isset($selected_address->latitude) && isset($selected_address->longitude)) {
            $lat = (float) $selected_address->latitude;
            $lng = (float) $selected_address->longitude;
        } elseif ($delivery_lat !== null && $delivery_lng !== null) {
            $lat = (float) $delivery_lat;
            $lng = (float) $delivery_lng;
        }

        // Only enforce coverage if we have valid coordinates and hub
        if ($hub_id > 0 && $lat !== null && $lng !== null && $lat != 0.0 && $lng != 0.0) {
            
            // Coverage SSOT missing → fail-open by design (system degradation)
            // Allows checkout to proceed when coverage system unavailable
            if (!function_exists('knx_check_coverage')) {
                // Intentionally allow checkout to proceed
                // Coverage will be enforced again at order creation (Phase 4.6)
            } else {
                $coverage = knx_check_coverage($hub_id, $lat, $lng);

                // Fail-closed: Block quote if not deliverable
                if (!$coverage['ok']) {
                    $reason = $coverage['reason'] ?? 'UNKNOWN';

                    // Canonical message mapping (same as KNX-A0.3/A0.4)
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

                    return knx_quote_block('COVERAGE', $reason, $message);
                }
            }
        }
    }

    // ------------------------------------------------------
    // Fetch active cart for token + hub
    // ------------------------------------------------------
    $cart = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_carts}
         WHERE session_token = %s
           AND hub_id = %d
           AND status = 'active'
         ORDER BY updated_at DESC
         LIMIT 1",
        $session_token,
        $hub_id
    ));

    if (!$cart) {
        return knx_quote_block('CART', 'CART_NOT_FOUND', 'Cart does not exist or expired.');
    }

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_cart_items}
         WHERE cart_id = %d
         ORDER BY id ASC",
        (int) $cart->id
    ));

    if (empty($items)) {
        return knx_quote_block('CART', 'EMPTY_CART', 'The cart has no items.');
    }

    // ------------------------------------------------------
    // SSOT subtotal + item_count from DB
    // ------------------------------------------------------
    $subtotal = 0.00;
    $item_count = 0;

    foreach ($items as $it) {
        $line_total = isset($it->line_total) ? (float) $it->line_total : 0.00;
        $qty = isset($it->quantity) ? (int) $it->quantity : 0;

        if ($qty < 0) $qty = 0;
        if ($line_total < 0) $line_total = 0.00;

        $subtotal += $line_total;
        $item_count += $qty;
    }

    if ($subtotal <= 0 || $item_count <= 0) {
        return knx_quote_block('CART', 'EMPTY_CART', 'The cart has no items.');
    }

    // ======================================================
    // HARD GATE: Availability BEFORE totals
    // Phase 1.5.C enforcement: No totals if unavailable
    // ======================================================
    if (!function_exists('knx_availability_decision')) {
        return knx_rest_error('availability_engine_missing', 503);
    }

    $availability = knx_availability_decision($hub_id);

    if (empty($availability['can_order'])) {
        if (function_exists('knx_rest_availability_block')) {
            return knx_rest_availability_block($availability);
        }

        return new WP_REST_Response([
            'success'         => true,
            'can_checkout'    => false,
            'gate'            => 'AVAILABILITY',
            'reason'          => isset($availability['reason']) ? (string) $availability['reason'] : 'UNKNOWN',
            'message'         => isset($availability['message']) ? (string) $availability['message'] : 'Restaurant unavailable',
            'meta'            => $availability,
            'redirect_to'     => site_url('/cart')
        ], 200);
    }

    // ------------------------------------------------------
    // Fetch hub (minimal identity + location)
    // ------------------------------------------------------
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, address, city_id, latitude, longitude
         FROM {$table_hubs}
         WHERE id = %d
         LIMIT 1",
        $hub_id
    ));

    if (!$hub) {
        return knx_quote_block('HUB', 'HUB_NOT_FOUND', 'Hub does not exist.');
    }

    // ------------------------------------------------------
    // Totals SSOT (never trust client)
    // ------------------------------------------------------
    if (!function_exists('knx_totals_quote')) {
        return knx_rest_error('totals_engine_missing', 503);
    }

    $totals_result = knx_totals_quote([
        'hub_id'           => (int) $hub_id,
        'city_id'          => isset($hub->city_id) ? (int) $hub->city_id : 0,
        'fulfillment_type' => (string) $fulfillment_type,
        'subtotal'         => (float) $subtotal,
        'item_count'       => (int) $item_count,
        'tip_amount'       => (float) $tip_amount,
        'delivery_lat'     => $delivery_lat,
        'delivery_lng'     => $delivery_lng,
        'hub_lat'          => isset($hub->latitude) ? (float) $hub->latitude : null,
        'hub_lng'          => isset($hub->longitude) ? (float) $hub->longitude : null,
    ]);

    if (empty($totals_result['success'])) {
        return new WP_REST_Response([
            'success'   => false,
            'error'     => $totals_result['error'] ?? 'totals_failed',
            'min_order' => $totals_result['min_order'] ?? null
        ], 400);
    }

    // Build unified 200-safe response contract
    $response_data = [
        'success'         => true,
        'can_checkout'    => true,
        'can_place_order' => true,
        'gate'            => null,
        'reason'          => null,
        'message'         => null,
        'availability'    => $availability,
        'cart' => [
            'id'         => (int) $cart->id,
            'hub_id'     => (int) $cart->hub_id,
            'subtotal'   => (float) round($subtotal, 2),
            'item_count' => (int) $item_count,
            'status'     => (string) $cart->status,
        ],
        'hub' => [
            'id'      => (int) $hub->id,
            'name'    => isset($hub->name) ? (string) $hub->name : '',
            'address' => isset($hub->address) ? (string) $hub->address : '',
        ],
        'checkout' => [
            'fulfillment_type'     => (string) $fulfillment_type,
            'requires_address'     => ($fulfillment_type === 'delivery'),
            'selected_address_id'  => (int) $selected_address_id,
        ],
        'totals'   => $totals_result['totals'],
        'snapshot' => $totals_result['snapshot'],
    ];

    // Include selected address snapshot for UI
    if ($selected_address && function_exists('knx_addresses_format')) {
        $response_data['selected_address'] = knx_addresses_format($selected_address);
    }

    // Include delivery distance & ETA if delivery mode
    if ($fulfillment_type === 'delivery' && isset($totals_result['snapshot']['delivery'])) {
        $response_data['delivery'] = $totals_result['snapshot']['delivery'];
    }

    return knx_rest_response(true, 'OK', $response_data, 200);
}
