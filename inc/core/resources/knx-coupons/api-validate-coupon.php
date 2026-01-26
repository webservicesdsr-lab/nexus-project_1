<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Coupon Validation Endpoint (PUBLIC, Read-only)
 * ----------------------------------------------------------
 * POST /wp-json/knx/v1/coupons/validate
 *
 * Purpose:
 * - Soft validation for cart/checkout UX (preview only)
 * - DOES NOT increment used_count
 * - DOES NOT apply coupon to cart
 *
 * Response data mirrors knx_resolve_coupon() contract:
 * - valid, reason, message, discount_amount, snapshot
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/coupons/validate', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_v1_coupons_validate'),
        'permission_callback' => '__return_true', // Public
    ]);
});

/**
 * Validate coupon (read-only preview).
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function knx_v1_coupons_validate(WP_REST_Request $req) {

    if (!function_exists('knx_rest_response')) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Response helpers unavailable.',
        ], 503);
    }

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', [
            'valid'           => false,
            'reason'          => 'invalid_request',
            'discount_amount' => 0.00,
            'snapshot'        => null,
        ], 400);
    }

    $code = isset($body['code']) ? strtoupper(trim(sanitize_text_field($body['code']))) : '';
    $subtotal = isset($body['subtotal']) && is_numeric($body['subtotal'])
        ? (float) $body['subtotal']
        : 0.00;

    $subtotal = max(0.00, $subtotal);

    if ($code === '') {
        return knx_rest_response(false, 'Coupon code is required.', [
            'valid'           => false,
            'reason'          => 'empty_code',
            'discount_amount' => 0.00,
            'snapshot'        => null,
        ], 400);
    }

    if (!function_exists('knx_resolve_coupon')) {
        return knx_rest_response(false, 'Coupon engine unavailable.', [
            'valid'           => false,
            'reason'          => 'coupon_engine_missing',
            'discount_amount' => 0.00,
            'snapshot'        => null,
        ], 503);
    }

    // Read-only preview (lock=false)
    $result = knx_resolve_coupon($code, $subtotal, false);

    $valid = !empty($result['valid']);
    $discount_amount = isset($result['discount_amount']) ? (float) $result['discount_amount'] : 0.00;
    $discount_amount = round(max(0.00, $discount_amount), 2);

    $payload = [
        'valid'           => (bool) $valid,
        'reason'          => isset($result['reason']) ? (string) $result['reason'] : ($valid ? 'ok' : 'invalid'),
        'message'         => isset($result['message']) ? (string) $result['message'] : ($valid ? 'Coupon valid.' : 'Coupon invalid.'),
        'discount_amount' => $discount_amount,
        'snapshot'        => $valid && isset($result['snapshot']) ? $result['snapshot'] : null,
    ];

    // NOTE: Preview endpoint can return success=true even when valid=false.
    return knx_rest_response(true, 'OK', $payload, 200);
}
