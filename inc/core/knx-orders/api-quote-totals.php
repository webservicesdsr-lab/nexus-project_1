<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('knx_debug_log')) {
    function knx_debug_log($msg) {
        if (defined('KNX_DEBUG') && KNX_DEBUG) {
            error_log($msg);
        }
    }
}

/**
 * ==========================================================
 * KINGDOM NEXUS — QUOTE TOTALS API (v5 - Snapshot Contract)
 * ==========================================================
 *
 * Endpoint:
 * - POST /wp-json/knx/v1/orders/quote-totals
 *
 * Rules:
 * - Read-only: NO writes, NO redemption increments
 * - Subtotal SSOT: SUM(knx_cart_items.line_total)
 * - Tax SSOT: hub.tax_rate via knx_resolve_tax()
 * - IMPORTANT: Service Fee must NOT affect tax base.
 * - Delivery snapshot (v4.6) is computed HERE (quote) and frozen
 * - Create-order MUST be snapshot-driven (no delivery calc there)
 * ==========================================================
 */

/**
 * Best-effort engine loader for REST context.
 * Prevents "fee = 0" caused by missing includes in REST requests.
 *
 * NOTE: This does NOT replace canonical bootstrap; it only reduces REST drift.
 */
function knx_quote_totals_try_load_engines() {
    $root = null;

    if (defined('KNX_PATH')) {
        $root = rtrim(KNX_PATH, '/') . '/';
    } else {
        // api-quote-totals.php is in /inc/core/knx-orders/
        $root = rtrim(dirname(__DIR__, 3), '/') . '/';
    }

    $candidates = [
        // Addresses
        $root . 'inc/functions/addresses-engine.php',
        $root . 'inc/functions/address-engine.php',

        // Fees / software fee engines
        $root . 'inc/functions/software-fee-engine.php',
        $root . 'inc/functions/software-fees-engine.php',
        $root . 'inc/functions/fees-engine.php',
        $root . 'inc/functions/software-fee.php',

        // Coupons
        $root . 'inc/functions/coupons-engine.php',
        $root . 'inc/functions/coupon-engine.php',

        // Taxes
        $root . 'inc/functions/taxes-engine.php',

        // Delivery (Phase 4.3-4.5)
        $root . 'inc/functions/coverage-engine.php',
        $root . 'inc/functions/distance-calculator.php',
        $root . 'inc/functions/delivery-fee-engine.php',
    ];

    foreach ($candidates as $file) {
        if (is_readable($file)) {
            require_once $file;
        }
    }
}

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/orders/quote-totals', [
        'methods'             => 'POST',
        'callback'            => function ($req) {
            knx_quote_totals_try_load_engines();

            if (function_exists('knx_rest_wrap')) {
                $wrapped = knx_rest_wrap('knx_api_quote_totals_handler');
                return $wrapped($req);
            }

            return knx_api_quote_totals_handler($req);
        },
        'permission_callback' => function () {
            return function_exists('knx_rest_permission_session')
                ? knx_rest_permission_session()()
                : true;
        },
        'args' => [
            'tip_amount' => [
                'type'              => 'number',
                'validate_callback' => function ($value) {
                    return is_numeric($value) && $value >= 0 && $value <= 100;
                },
                'sanitize_callback' => function ($value) {
                    return round(max(0.00, min(100.00, (float) $value)), 2);
                },
            ],
            'coupon_code' => [
                'type'              => 'string',
                'validate_callback' => function ($value) {
                    return is_string($value) && strlen($value) <= 50;
                },
                'sanitize_callback' => function ($value) {
                    return substr(sanitize_text_field(trim((string) $value)), 0, 50);
                },
            ],
            'fulfillment_type' => [
                'type'              => 'string',
                'validate_callback' => function ($value) {
                    if (!is_string($value)) return false;
                    $v = strtolower(trim($value));
                    return in_array($v, ['delivery', 'pickup'], true);
                },
                'sanitize_callback' => function ($value) {
                    $v = strtolower(trim((string) $value));
                    return in_array($v, ['delivery', 'pickup'], true) ? $v : 'delivery';
                },
            ],
            'address_id' => [
                'type' => 'integer',
                'validate_callback' => function ($value) {
                    return is_numeric($value) && (int)$value >= 0;
                },
                'sanitize_callback' => function ($value) {
                    return (int)$value;
                }
            ],
            'address' => [
                'type' => 'object',
                'validate_callback' => function ($value) {
                    return is_array($value) || is_object($value);
                },
                'sanitize_callback' => function ($value) {
                    return $value;
                }
            ],
        ],
    ]);
});

/**
 * Quote totals handler.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function knx_api_quote_totals_handler(WP_REST_Request $req) {
    global $wpdb;

    $table_carts      = $wpdb->prefix . 'knx_carts';
    $table_cart_items = $wpdb->prefix . 'knx_cart_items';
    $table_hubs       = $wpdb->prefix . 'knx_hubs';

    /* ======================================================
     * AUTH — HARD
     * ====================================================== */
    if (!function_exists('knx_get_session')) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SESSION_ENGINE_MISSING',
            'message' => 'System unavailable.',
        ], 503);
    }

    $session = knx_get_session();
    if (!$session || empty($session->user_id)) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'AUTH_REQUIRED',
            'message' => 'Please login to continue.',
        ], 401);
    }

    $user_id = (int) $session->user_id;

    /* ======================================================
     * CART RESOLUTION — COOKIE ONLY
     * ====================================================== */
    $session_token = isset($_COOKIE['knx_cart_token'])
        ? sanitize_text_field($_COOKIE['knx_cart_token'])
        : '';

    if ($session_token === '') {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_TOKEN_MISSING',
            'message' => 'No active cart found.',
        ], 409);
    }

    $cart = $wpdb->get_row($wpdb->prepare(
        "SELECT *
         FROM {$table_carts}
         WHERE session_token = %s
           AND status = 'active'
         ORDER BY updated_at DESC
         LIMIT 1",
        $session_token
    ));

    if (!$cart) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_NOT_FOUND',
            'message' => 'No active cart found.',
        ], 409);
    }

    $cart_id = (int) $cart->id;

    // Ownership
    if (!empty($cart->customer_id) && (int) $cart->customer_id !== $user_id) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_FORBIDDEN',
            'message' => 'This cart does not belong to you.',
        ], 403);
    }

    /* ======================================================
     * HUB — HARD
     * ====================================================== */
    $hub_id = (int) $cart->hub_id;
    if ($hub_id <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'HUB_MISSING',
            'message' => 'No restaurant selected.',
        ], 409);
    }

    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, city_id, status
         FROM {$table_hubs}
         WHERE id = %d
         LIMIT 1",
        $hub_id
    ));

    if (!$hub || (string) $hub->status !== 'active') {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'HUB_INACTIVE',
            'message' => 'Restaurant unavailable.',
        ], 409);
    }

    /* ======================================================
     * CART ITEMS + SUBTOTAL SSOT
     * ====================================================== */
    $item_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id
         FROM {$table_cart_items}
         WHERE cart_id = %d
         LIMIT 1",
        $cart_id
    ));

    if (!$item_exists) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_EMPTY',
            'message' => 'Your cart is empty.',
        ], 409);
    }

    $subtotal_result = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(line_total), 0.00)
         FROM {$table_cart_items}
         WHERE cart_id = %d",
        $cart_id
    ));

    $subtotal = max(0.00, round((float) $subtotal_result, 2));

    if ($subtotal <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_EMPTY',
            'message' => 'Your cart is empty.',
        ], 409);
    }

    /* ======================================================
     * INPUTS
     * ====================================================== */
    $tip_amount  = $req->has_param('tip_amount') ? round((float) $req->get_param('tip_amount'), 2) : 0.00;
    if ($tip_amount < 0) $tip_amount = 0.00;

    $coupon_code = $req->has_param('coupon_code') ? trim((string) $req->get_param('coupon_code')) : '';

    $fulfillment_type = $req->has_param('fulfillment_type')
        ? strtolower(trim((string) $req->get_param('fulfillment_type')))
        : 'delivery';

    if (!in_array($fulfillment_type, ['delivery', 'pickup'], true)) {
        $fulfillment_type = 'delivery';
    }

    // City id (prefer cart.city_id if present, fallback hub.city_id)
    $city_id = 0;
    if (isset($cart->city_id)) $city_id = (int) $cart->city_id;
    if ($city_id <= 0 && isset($hub->city_id)) $city_id = (int) $hub->city_id;

    /* ======================================================
     * DELIVERY (Phase 4.3-4.6) — ONLY IF fulfillment_type=delivery
     * - Fail-closed on missing engines
     * - Fail-closed on missing address / coords
     * - Fail-closed on out-of-coverage
     * - Fail-closed on fee calc failure (no silent zero)
     * ====================================================== */
    $delivery_info = null;
    $delivery_fee_amount = 0.00;
    $delivery_snapshot_v46 = null;

    $selected_address = null;
    $selected_address_id = 0;

    if ($fulfillment_type === 'delivery') {

        // Address source precedence (preference A): explicit `address_id` param
        $input_address_id = $req->has_param('address_id') ? (int) $req->get_param('address_id') : 0;
        $input_address_obj = $req->has_param('address') ? $req->get_param('address') : null;

        // If address_id is provided, resolve via address engine (quote may read address engine)
        if ($input_address_id > 0) {
            if (!function_exists('knx_get_address_by_id')) {
                return new WP_REST_Response([
                    'success' => false,
                    'reason'  => 'ADDRESS_ENGINE_MISSING',
                    'message' => 'Delivery address system unavailable.',
                ], 503);
            }

            $selected_address = knx_get_address_by_id($input_address_id, $user_id);
            if (!$selected_address) {
                return new WP_REST_Response([
                    'success' => false,
                    'reason'  => 'ADDRESS_NOT_FOUND',
                    'message' => 'Provided address not found.',
                ], 409);
            }
            $selected_address_id = $input_address_id;

        } elseif (is_array($input_address_obj) || is_object($input_address_obj)) {
            // If frontend provided a plain address object, use it (no DB read)
            $addr = (array) $input_address_obj;
            $label = isset($addr['label']) ? trim((string) $addr['label']) : '';
            $lat = isset($addr['lat']) ? (float) $addr['lat'] : 0.0;
            $lng = isset($addr['lng']) ? (float) $addr['lng'] : 0.0;

            if ($label === '' || !is_finite($lat) || !is_finite($lng) || $lat == 0.0 || $lng == 0.0) {
                return new WP_REST_Response([
                    'success' => false,
                    'reason'  => 'ADDRESS_INVALID',
                    'message' => 'Invalid address provided for delivery.',
                ], 409);
            }

            // Build a minimal selected_address-like structure for downstream engines
            $selected_address = (object) [
                'id' => 0,
                'label' => $label,
                'latitude' => $lat,
                'longitude' => $lng,
            ];

        } else {
            // Fallback: use session-selected address id (legacy behavior)
            if (!function_exists('knx_addresses_get_selected_id_for_customer')) {
                return new WP_REST_Response([
                    'success' => false,
                    'reason'  => 'ADDRESS_ENGINE_MISSING',
                    'message' => 'Delivery address system unavailable.',
                ], 503);
            }

            $selected_address_id = (int) knx_addresses_get_selected_id_for_customer($user_id);

            if ($selected_address_id <= 0) {
                return new WP_REST_Response([
                    'success' => false,
                    'reason'  => 'ADDRESS_REQUIRED',
                    'message' => 'Please select a delivery address to get delivery totals.',
                ], 409);
            }

            if (!function_exists('knx_get_address_by_id')) {
                return new WP_REST_Response([
                    'success' => false,
                    'reason'  => 'ADDRESS_ENGINE_MISSING',
                    'message' => 'Delivery address system unavailable.',
                ], 503);
            }

            $selected_address = knx_get_address_by_id($selected_address_id, $user_id);
            if (!$selected_address) {
                return new WP_REST_Response([
                    'success' => false,
                    'reason'  => 'ADDRESS_NOT_FOUND',
                    'message' => 'Selected address not found.',
                ], 409);
            }
        }

        $customer_lat = isset($selected_address->latitude) ? (float) $selected_address->latitude : 0.0;
        $customer_lng = isset($selected_address->longitude) ? (float) $selected_address->longitude : 0.0;

        if ($customer_lat == 0.0 || $customer_lng == 0.0) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'MISSING_COORDS',
                'message' => 'Delivery address must have valid coordinates.',
            ], 409);
        }

        if (!function_exists('knx_check_coverage')) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'COVERAGE_ENGINE_MISSING',
                'message' => 'Delivery coverage system unavailable.',
            ], 503);
        }

        if (!function_exists('knx_calculate_delivery_distance')) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DISTANCE_ENGINE_MISSING',
                'message' => 'Delivery distance system unavailable.',
            ], 503);
        }

        if (!function_exists('knx_calculate_delivery_fee')) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'FEE_ENGINE_MISSING',
                'message' => 'Delivery fee system unavailable.',
            ], 503);
        }

        // Coverage (A4.3)
        $coverage = knx_check_coverage($hub_id, $customer_lat, $customer_lng);
        $coverage_ok = !empty($coverage['ok']);
        $coverage_reason = (string) ($coverage['reason'] ?? 'UNKNOWN');

        if ($coverage_ok !== true) {
            knx_debug_log("[KNX-QUOTE] ABORT: Coverage failed hub_id={$hub_id} reason={$coverage_reason}");
            return new WP_REST_Response([
                'success'         => false,
                'reason'          => 'OUT_OF_COVERAGE',
                'coverage_reason' => $coverage_reason,
                'message'         => 'Your address is outside our delivery coverage area.',
            ], 409);
        }

        // Distance (A4.4)
        $distance_result = knx_calculate_delivery_distance($hub_id, $customer_lat, $customer_lng);
        if (empty($distance_result['ok'])) {
            $dist_reason = (string) ($distance_result['reason'] ?? 'DISTANCE_FAILED');
            return new WP_REST_Response([
                'success'     => false,
                'reason'      => 'DISTANCE_CALCULATION_FAILED',
                'dist_reason' => $dist_reason,
                'message'     => 'Unable to calculate delivery distance.',
            ], 409);
        }

        $distance_km = (float) ($distance_result['distance_km'] ?? 0.0);
        $distance_mi = (float) ($distance_result['distance_mi'] ?? 0.0);
        $eta_minutes = (int) ($distance_result['eta_minutes'] ?? 0);

        // Fee (A4.5)
        $fee_result = knx_calculate_delivery_fee($hub_id, $distance_km, $subtotal);
        if (empty($fee_result['ok'])) {
            $fee_reason = (string) ($fee_result['reason'] ?? 'NO_FEE_RULE');
            return new WP_REST_Response([
                'success'    => false,
                'reason'     => 'FEE_CALCULATION_FAILED',
                'fee_reason' => $fee_reason,
                'message'    => 'Unable to calculate delivery fee.',
            ], 409);
        }

        $delivery_fee_amount = round((float) ($fee_result['fee'] ?? 0.00), 2);
        if ($delivery_fee_amount < 0) $delivery_fee_amount = 0.00;

        $delivery_info = [
            'distance_km'       => $distance_km,
            'distance_mi'       => $distance_mi,
            'eta_minutes'       => $eta_minutes,
            'delivery_fee'      => $delivery_fee_amount,
            'is_free_delivery'  => !empty($fee_result['is_free']),
            'coverage_ok'       => true,
            'fee_rule_name'     => $fee_result['rule_name'] ?? null,
        ];

        // Build sealed delivery snapshot v4.6 (frozen data for create-order)
        $addr_label = null;
        if (function_exists('knx_addresses_format_one_line')) {
            $addr_label = knx_addresses_format_one_line($selected_address);
        } else {
            $parts = array_filter([
                $selected_address->line1 ?? '',
                $selected_address->line2 ?? '',
                $selected_address->city ?? '',
                $selected_address->state ?? '',
                $selected_address->postal_code ?? '',
            ]);
            $addr_label = implode(', ', $parts);
        }

        $now = current_time('mysql');

        $delivery_snapshot_v46 = [
            'version' => 'v4.6_sealed',
            'address' => [
                'address_id' => $selected_address_id,
                'label'      => $addr_label,
                'lat'        => $customer_lat,
                'lng'        => $customer_lng,
            ],
            'coverage' => [
                'zone_id'    => isset($coverage['zone_id']) ? $coverage['zone_id'] : null,
                'zone_name'  => isset($coverage['zone_name']) ? $coverage['zone_name'] : null,
                'reason'     => 'DELIVERABLE',
                'checked_at' => $now,
            ],
            'distance' => [
                'km'            => $distance_km,
                'miles'         => $distance_mi,
                'eta_minutes'   => $eta_minutes,
                'calculated_at' => $now,
            ],
            'delivery_fee' => [
                'amount'           => $delivery_fee_amount,
                'rule_name'        => $delivery_info['fee_rule_name'],
                'is_free_delivery' => !empty($delivery_info['is_free_delivery']),
                'calculated_at'    => $now,
            ],
            'snapshot_created_at' => $now,
        ];

        // Attach sealed snapshot inside delivery object (contract for create-order)
        $delivery_info['delivery_snapshot_v46'] = $delivery_snapshot_v46;
    }

    /* ======================================================
     * FEES — SOFTWARE + DELIVERY
     * ====================================================== */
    $fees = [];
    $fees_total = 0.00;

    // Software Fee (Service Fee)
    $software_fee_amount = 0.00;

    if (function_exists('knx_resolve_software_fee')) {
        $software_fee = null;

        try {
            $rf = new ReflectionFunction('knx_resolve_software_fee');
            $argc = $rf->getNumberOfParameters();

            if ($argc >= 3) {
                $software_fee = knx_resolve_software_fee($city_id, $subtotal, $hub_id);
            } elseif ($argc === 2) {
                $software_fee = knx_resolve_software_fee($city_id, $subtotal);
            } else {
                $software_fee = knx_resolve_software_fee($subtotal);
            }
        } catch (Exception $e) {
            $software_fee = null;
        }

        if (is_array($software_fee) && !empty($software_fee['applied'])) {
            $fee_amount = round((float) ($software_fee['fee_amount'] ?? 0.00), 2);
            if ($fee_amount < 0) $fee_amount = 0.00;

            $software_fee_amount = $fee_amount;

            $fees[] = [
                'type'     => 'software',
                'label'    => (string) ($software_fee['label'] ?? 'Service Fee'),
                'scope'    => (string) ($software_fee['scope'] ?? ''),
                'scope_id' => isset($software_fee['scope_id']) ? $software_fee['scope_id'] : null,
                'amount'   => $fee_amount,
            ];

            $fees_total += $fee_amount;
        }
    }

    // Delivery Fee (only if delivery)
    if ($fulfillment_type === 'delivery') {
        $fees[] = [
            'type'     => 'delivery',
            'label'    => 'Delivery Fee',
            'scope'    => 'hub',
            'scope_id' => $hub_id,
            'amount'   => $delivery_fee_amount, // may be 0 for free delivery
        ];
        $fees_total += $delivery_fee_amount;
    }

    /* ======================================================
     * DISCOUNTS (Coupon) — lock=false (quote is read-only)
     * ====================================================== */
    $discounts = [];
    $discounts_total = 0.00;
    $coupon_info_for_response = null;

    if ($coupon_code !== '' && function_exists('knx_resolve_coupon')) {
        $coupon_result = knx_resolve_coupon($coupon_code, $subtotal, false);

        if (is_array($coupon_result) && !empty($coupon_result['valid'])) {
            $snap = is_array($coupon_result['snapshot'] ?? null) ? $coupon_result['snapshot'] : null;

            $discount_amount = round((float) ($coupon_result['discount_amount'] ?? 0.00), 2);
            if ($discount_amount < 0) $discount_amount = 0.00;
            if ($discount_amount > $subtotal) $discount_amount = $subtotal;

            if ($snap && $discount_amount > 0) {
                $discounts[] = [
                    'type'          => 'coupon',
                    'code'          => (string) ($snap['code'] ?? $coupon_code),
                    'coupon_id'     => isset($snap['coupon_id']) ? (int) $snap['coupon_id'] : null,
                    'discount_type' => (string) ($snap['type'] ?? ''),
                    'value'         => isset($snap['value']) ? (float) $snap['value'] : null,
                    'amount'        => $discount_amount,
                ];
                $discounts_total += $discount_amount;

                $coupon_info_for_response = [
                    'applied' => true,
                    'reason'  => 'ok',
                    'message' => (string) ($coupon_result['message'] ?? 'Coupon applied.'),
                    'code'    => (string) ($snap['code'] ?? $coupon_code),
                ];
            } else {
                $coupon_info_for_response = [
                    'applied' => false,
                    'reason'  => 'zero_discount',
                    'message' => 'Coupon produced no discount.',
                    'code'    => $coupon_code,
                ];
            }
        } else {
            $coupon_info_for_response = [
                'applied' => false,
                'reason'  => (string) ($coupon_result['reason'] ?? 'invalid'),
                'message' => (string) ($coupon_result['message'] ?? 'Coupon not applied.'),
                'code'    => $coupon_code,
            ];
        }
    }

    /* ======================================================
     * TAX — HUB SSOT (FAIL-CLOSED)
     * IMPORTANT: Tax base excludes fees + tip.
     * Tax base = subtotal - discounts
     * ====================================================== */
    if (!function_exists('knx_resolve_tax')) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'TAX_ENGINE_MISSING',
            'message' => 'Tax system unavailable.',
        ], 503);
    }

    $tax_base = round($subtotal - $discounts_total, 2);
    if ($tax_base < 0) $tax_base = 0.00;

    $tax_details = [
        'amount' => 0.00,
        'rate'   => 0.00,
        'source' => 'hub_setting',
        'hub_id' => $hub_id,
    ];
    $tax_amount = 0.00;

    $tax_result = knx_resolve_tax($tax_base, $hub_id);

    if (is_array($tax_result) && !empty($tax_result['applied'])) {
        $tax_amount = round((float) ($tax_result['amount'] ?? 0.00), 2);

        if ($tax_amount < 0) {
            knx_debug_log(sprintf('[KNX-A3.4] ABORT: Negative tax_amount=%.2f hub_id=%d', $tax_amount, $hub_id));
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'TAX_CALCULATION_INVALID',
                'message' => 'Unable to calculate taxes at this time.',
            ], 500);
        }

        $tax_details = [
            'amount' => $tax_amount,
            'rate'   => round((float) ($tax_result['rate'] ?? 0.00), 2),
            'source' => (string) ($tax_result['source'] ?? 'hub_setting'),
            'hub_id' => (int) ($tax_result['hub_id'] ?? $hub_id),
        ];
    }

    /* ======================================================
     * TOTAL
     * total = subtotal + fees - discounts + tip + tax
     * ====================================================== */
    $total = round($subtotal + $fees_total - $discounts_total + $tip_amount + $tax_amount, 2);
    if ($total < 0) $total = 0.00;

    $breakdown_v5 = [
        'version'   => 'v5',
        'currency'  => 'USD',
        'subtotal'  => $subtotal,
        'fees'      => $fees,
        'discounts' => $discounts,
        'tip'       => $tip_amount,
        'tax'       => $tax_details,
        'total'     => $total,
    ];

    /* ======================================================
     * SNAPSHOT — Contract for create-order (KNX-A0.8)
     * ====================================================== */
    $order_snapshot = [
        'subtotal'        => $subtotal,
        'total'           => $total,

        'tax_amount'      => $tax_amount,
        'tax_rate'        => $tax_details['rate'] ?? 0.00,

        'delivery_fee'    => ($fulfillment_type === 'delivery') ? $delivery_fee_amount : 0.00,
        'software_fee'    => $software_fee_amount,
        'tip_amount'      => $tip_amount,
        'discount_amount' => $discounts_total,

        'fulfillment_type' => $fulfillment_type,

        // MUST be an array for delivery, and must include:
        // - delivery_fee
        // - delivery_snapshot_v46
        'delivery'        => ($fulfillment_type === 'delivery') ? $delivery_info : null,

        'calculated_at'   => current_time('mysql'),
    ];

    // Note: Address MUST live inside delivery_snapshot_v46.address (single source of truth).
    // Do NOT add top-level $order_snapshot['address'] — delivery snapshot is authoritative.

    // Snapshot consistency guard: ensure delivery fee matches sealed snapshot
    if ($fulfillment_type === 'delivery' && is_array($delivery_snapshot_v46)) {
        $ds_fee = isset($delivery_snapshot_v46['delivery_fee']['amount']) ? (float) $delivery_snapshot_v46['delivery_fee']['amount'] : null;
        $os_fee = (float) ($order_snapshot['delivery_fee'] ?? 0.00);
        $do_fee = (is_array($order_snapshot['delivery']) && isset($order_snapshot['delivery']['delivery_fee']))
            ? (float) $order_snapshot['delivery']['delivery_fee']
            : null;

        if ($ds_fee === null || $do_fee === null) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'SNAPSHOT_INCONSISTENT',
                'message' => 'Internal pricing inconsistency. Please re-run quote.',
            ], 409);
        }

        if (abs($ds_fee - $os_fee) > 0.01 || abs($ds_fee - $do_fee) > 0.01) {
            knx_debug_log(sprintf('[KNX-QUOTE] ABORT: Snapshot inconsistency ds_fee=%.2f os_fee=%.2f do_fee=%.2f', $ds_fee, $os_fee, $do_fee));
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'SNAPSHOT_INCONSISTENT',
                'message' => 'Internal pricing inconsistency. Please re-run quote.',
            ], 409);
        }
    }

    return new WP_REST_Response([
        'success' => true,
        'cart_id' => $cart_id,
        'hub_id'  => $hub_id,
        'totals'  => [
            'subtotal'  => $subtotal,
            'total'     => $total,
            'currency'  => 'USD',
            'breakdown' => $breakdown_v5,
        ],
        'fulfillment_type' => $fulfillment_type,
        'delivery' => $delivery_info, // includes delivery_snapshot_v46 when delivery
        'coupon'   => $coupon_info_for_response,
        'snapshot' => $order_snapshot, // KNX-A0.8: Pass this to create-order
    ], 200);
}
