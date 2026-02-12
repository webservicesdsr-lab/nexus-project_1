<?php
if (!defined('ABSPATH')) exit;

// KNX-A0.8.1: Snapshot version authority (centralized, guarded)
if (!defined('KNX_ORDER_SNAPSHOT_VERSION')) {
    define('KNX_ORDER_SNAPSHOT_VERSION', 'v5');
}

if (!function_exists('knx_debug_log')) {
    function knx_debug_log($msg) {
        if (defined('KNX_DEBUG') && KNX_DEBUG) {
            error_log($msg);
        }
    }
}

/**
 * ==========================================================
 * KNX-A0.8 — CREATE ORDER (Snapshot-Locked Economics)
 * ----------------------------------------------------------
 * CANON ORDER CREATION AUTHORITY
 *
 * IMPORTANT CHANGE (PAYMENTS HARDENING):
 * - Orders are created as: status = 'pending_payment'
 * - payment_method is forced to 'stripe' (Nexus never accepts cash)
 * - OPS must never display unpaid orders (OPS should list 'confirmed' only)
 * - Webhook promotes to 'confirmed' on payment success
 * - payment_failed orders are retryable (create new intent)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/orders/create', [
        'methods'             => 'POST',
        'callback'            => function ($req) {
            if (function_exists('knx_rest_wrap')) {
                $wrapped = knx_rest_wrap('knx_api_create_order_mvp');
                return $wrapped($req);
            }
            return knx_api_create_order_mvp($req);
        },
        'permission_callback' => function () {
            return function_exists('knx_rest_permission_session')
                ? knx_rest_permission_session()()
                : true;
        },
    ]);
});

if (!function_exists('knx_api_create_order_mvp')) {
function knx_api_create_order_mvp(WP_REST_Request $req) {
    global $wpdb;

    $table_carts         = $wpdb->prefix . 'knx_carts';
    $table_cart_items    = $wpdb->prefix . 'knx_cart_items';
    $table_hubs          = $wpdb->prefix . 'knx_hubs';
    $table_orders        = $wpdb->prefix . 'knx_orders';
    $table_order_items   = $wpdb->prefix . 'knx_order_items';
    $table_order_history = $wpdb->prefix . 'knx_order_status_history';

    if (!defined('KNX_CREATE_ORDER_CONTEXT')) {
        define('KNX_CREATE_ORDER_CONTEXT', true);
    }

    /* ======================================================
     * A1) AUTH — HARD
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
            'message' => 'Please login to place an order.',
        ], 401);
    }

    $user_id = (int) $session->user_id;

    // Role hard-block (customer-only)
    $blocked_roles = ['hub_owner', 'hub_staff', 'menu_uploader', 'driver', 'admin'];
    if (!empty($session->role) && in_array($session->role, $blocked_roles, true)) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'ROLE_FORBIDDEN',
            'message' => 'Your account is not permitted to create orders.',
        ], 403);
    }

    /* ======================================================
     * A2) CART RESOLUTION — HARD (COOKIE ONLY)
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
    $hub_id  = (int) $cart->hub_id;

    /* ======================================================
     * A3) CART OWNERSHIP — HARD
     * ====================================================== */
    if ((string) $cart->session_token !== (string) $session_token) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_FORBIDDEN',
            'message' => 'This cart does not belong to you.',
        ], 403);
    }

    if (!empty($cart->customer_id) && (int) $cart->customer_id !== $user_id) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_FORBIDDEN',
            'message' => 'This cart does not belong to you.',
        ], 403);
    }

    /* ======================================================
     * A4) CART STATE — HARD (Guard against re-entry)
     * ====================================================== */
    if ((string) $cart->status === 'converted') {

        $cart_updated_at = $cart->updated_at;

        // IMPORTANT: include pending_payment + payment_failed so we can retry safely
        $existing_order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, order_number, status, created_at
             FROM {$table_orders}
             WHERE session_token = %s
               AND hub_id = %d
               AND customer_id = %d
               AND status IN ('pending_payment','payment_failed','confirmed','preparing','ready','out_for_delivery')
               AND created_at >= DATE_SUB(%s, INTERVAL 10 MINUTE)
             ORDER BY created_at DESC
             LIMIT 1",
            $session_token,
            $hub_id,
            $user_id,
            $cart_updated_at
        ));

        if ($existing_order) {
            return new WP_REST_Response([
                'success'        => true,
                'already_exists' => true,
                'order_id'       => (int) $existing_order->id,
                'order_number'   => (string) $existing_order->order_number,
                'reason'         => 'ORDER_ALREADY_FINALIZED',
                'message'        => 'Order already exists for this cart.',
                'order_status'   => (string) $existing_order->status,
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'ORDER_ALREADY_FINALIZED',
            'message' => 'This cart has already been used to create an order.',
        ], 409);
    }

    if ((string) $cart->status !== 'active') {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_INVALID_STATE',
            'message' => 'Cart is no longer active.',
        ], 409);
    }

    /* ======================================================
     * A5) CART ITEMS — HARD
     * ====================================================== */
    $cart_items = $wpdb->get_results($wpdb->prepare(
        "SELECT *
         FROM {$table_cart_items}
         WHERE cart_id = %d
         ORDER BY id ASC",
        $cart_id
    ));

    if (empty($cart_items)) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'CART_EMPTY',
            'message' => 'Your cart is empty.',
        ], 409);
    }

    /* ======================================================
     * A6) HUB VALIDATION — HARD
     * ====================================================== */
    if ($hub_id <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'HUB_MISSING',
            'message' => 'No restaurant selected.',
        ], 409);
    }

    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, city_id, status, address, latitude, longitude
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
     * A7) PROFILE MIN — HARD
     * ====================================================== */
    if (!function_exists('knx_profile_status')) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'PROFILE_ENGINE_MISSING',
            'message' => 'System unavailable.',
        ], 503);
    }

    $profile = knx_profile_status($user_id);

    if (!empty($profile['schema_missing'])) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'PROFILE_SCHEMA_MISSING',
            'message' => 'Profile system unavailable.',
        ], 409);
    }

    if (empty($profile['complete'])) {
        return new WP_REST_Response([
            'success'        => false,
            'reason'         => 'PROFILE_INCOMPLETE',
            'message'        => 'Please complete your profile before checkout.',
            'missing_fields' => $profile['missing'] ?? [],
        ], 409);
    }

    /* ======================================================
     * A8) AVAILABILITY — HARD FINAL
     * ====================================================== */
    if (!function_exists('knx_availability_decision')) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'AVAILABILITY_ENGINE_MISSING',
            'message' => 'System unavailable.',
        ], 503);
    }

    $availability = knx_availability_decision($hub_id);

    if (empty($availability['can_order'])) {
        return new WP_REST_Response([
            'success'      => false,
            'reason'       => $availability['reason'] ?? 'UNAVAILABLE',
            'message'      => $availability['message'] ?? 'Restaurant unavailable.',
            'reopen_at'    => $availability['reopen_at'] ?? null,
            'availability' => $availability,
        ], 409);
    }

    /* ======================================================
     * A9/A10) SNAPSHOT + ADDRESS — HARD
     * ====================================================== */
    $body = $req->get_json_params();
    $fulfillment_type = isset($body['fulfillment_type']) ? strtolower(trim($body['fulfillment_type'])) : 'delivery';

    // Nexus currently supports delivery + pickup only.
    if (!in_array($fulfillment_type, ['delivery', 'pickup'], true)) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'INVALID_FULFILLMENT',
            'message' => 'Invalid fulfillment type.',
        ], 409);
    }

    $snapshot = isset($body['snapshot']) && is_array($body['snapshot']) ? $body['snapshot'] : null;

    if ($snapshot === null) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SNAPSHOT_REQUIRED',
            'message' => 'Order snapshot is required. Please re-quote your order.',
        ], 409);
    }

    if (!isset($snapshot['subtotal']) || !isset($snapshot['total'])) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SNAPSHOT_INCOMPLETE',
            'message' => 'Order snapshot is incomplete. Please re-quote your order.',
        ], 409);
    }

    $delivery_address_snapshot = null;
    $delivery_lat = null;
    $delivery_lng = null;

    $delivery_snapshot_v46 = null;

    if ($fulfillment_type === 'delivery') {

        if (
            !isset($snapshot['delivery']) ||
            !is_array($snapshot['delivery']) ||
            !isset($snapshot['delivery']['delivery_snapshot_v46']) ||
            !is_array($snapshot['delivery']['delivery_snapshot_v46']) ||
            !isset($snapshot['delivery']['delivery_snapshot_v46']['address']) ||
            !is_array($snapshot['delivery']['delivery_snapshot_v46']['address'])
        ) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_SNAPSHOT_MISSING',
                'message' => 'Delivery snapshot missing or invalid. Please re-quote your order.',
            ], 409);
        }

        $addr_snap = $snapshot['delivery']['delivery_snapshot_v46']['address'];

        if (!isset($addr_snap['label']) || !isset($addr_snap['lat']) || !isset($addr_snap['lng'])) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'ADDRESS_SNAPSHOT_INCOMPLETE',
                'message' => 'Delivery address snapshot is incomplete. Please re-quote your order.',
            ], 409);
        }

        $delivery_lat = (float) $addr_snap['lat'];
        $delivery_lng = (float) $addr_snap['lng'];

        if (!is_numeric($delivery_lat) || !is_numeric($delivery_lng)) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'ADDRESS_SNAPSHOT_INVALID',
                'message' => 'Address coordinates are invalid in snapshot.',
            ], 409);
        }

        $delivery_address_snapshot = (string) $addr_snap['label'];

        // Delivery fee + coherence
        if (!isset($snapshot['delivery']['delivery_fee'])) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_FEE_MISSING',
                'message' => 'Delivery fee is missing. Please re-quote your order.',
            ], 409);
        }

        $delivery_snapshot_v46 = $snapshot['delivery']['delivery_snapshot_v46'];

        $ds_fee = isset($delivery_snapshot_v46['delivery_fee']['amount'])
            ? (float) $delivery_snapshot_v46['delivery_fee']['amount']
            : null;

        if ($ds_fee === null) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_SNAPSHOT_INVALID',
                'message' => 'Delivery snapshot missing fee amount. Please re-quote your order.',
            ], 409);
        }

        $delivery_fee_in_delivery_obj = (float) $snapshot['delivery']['delivery_fee'];

        if (abs($ds_fee - $delivery_fee_in_delivery_obj) > 0.01) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_SNAPSHOT_MISMATCH',
                'message' => 'Delivery snapshot does not match quoted fee. Please re-quote your order.',
            ], 409);
        }
    }

    /* ======================================================
     * B) IDEMPOTENCY — prevent duplicate orders in 10-min window
     * IMPORTANT: include pending_payment/payment_failed so retries are safe
     * ====================================================== */
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, order_number, created_at, status
         FROM {$table_orders}
         WHERE session_token = %s
           AND hub_id = %d
           AND customer_id = %d
           AND status IN ('pending_payment','payment_failed','confirmed','preparing','ready','out_for_delivery')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
         ORDER BY created_at DESC
         LIMIT 1",
        $session_token,
        $hub_id,
        $user_id
    ));

    if ($existing) {
        return new WP_REST_Response([
            'success'        => true,
            'already_exists' => true,
            'order_id'       => (int) $existing->id,
            'order_number'   => (string) $existing->order_number,
            'reason'         => 'DUPLICATE_ORDER_PREVENTED',
            'message'        => 'Order already exists for this session.',
            'order_status'   => (string) $existing->status,
        ], 200);
    }

    /* ======================================================
     * C) SNAPSHOT ENFORCEMENT — totals from quote snapshot only
     * ====================================================== */
    if (!isset($snapshot['tax_amount']) || !isset($snapshot['tax_rate'])) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SNAPSHOT_INCOMPLETE',
            'message' => 'Order snapshot is missing required tax information. Please re-quote your order.',
        ], 409);
    }

    $subtotal     = isset($snapshot['subtotal']) ? (float) $snapshot['subtotal'] : 0.00;
    $tax_amount   = (float) $snapshot['tax_amount'];
    $tax_rate     = (float) $snapshot['tax_rate'];
    $delivery_fee = isset($snapshot['delivery_fee']) ? (float) $snapshot['delivery_fee'] : 0.00;
    $software_fee = isset($snapshot['software_fee']) ? (float) $snapshot['software_fee'] : 0.00;
    $tip_amount   = isset($snapshot['tip_amount']) ? (float) $snapshot['tip_amount'] : 0.00;
    $total        = isset($snapshot['total']) ? (float) $snapshot['total'] : 0.00;
    $discount_amount = isset($snapshot['discount_amount']) ? (float) $snapshot['discount_amount'] : 0.00;

    if ($tax_amount < 0) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'INVALID_TAX_AMOUNT',
            'message' => 'Invalid tax amount in order snapshot. Please re-quote your order.',
        ], 409);
    }

    $cart_subtotal = 0.00;
    foreach ($cart_items as $it) {
        $cart_subtotal += isset($it->line_total) ? (float) $it->line_total : 0.00;
    }
    $cart_subtotal = round($cart_subtotal, 2);

    if (abs($cart_subtotal - $subtotal) > 0.01) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SUBTOTAL_MISMATCH',
            'message' => 'Cart has changed. Please re-quote your order.',
        ], 409);
    }

    if ($total <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'INVALID_TOTAL',
            'message' => 'Order total is invalid.',
        ], 409);
    }

    // Prepare totals snapshot
    $now = current_time('mysql');

    $breakdown_v5 = [
        'version'            => KNX_ORDER_SNAPSHOT_VERSION,
        'currency'           => 'usd',
        'subtotal'           => $subtotal,
        'tax_rate'           => $tax_rate,
        'tax_amount'         => $tax_amount,
        'delivery_fee'       => $delivery_fee,
        'software_fee'       => $software_fee,
        'discount_amount'    => $discount_amount,
        'tip_amount'         => $tip_amount,
        'total'              => $total,
        'delivery'           => isset($snapshot['delivery']) ? $snapshot['delivery'] : null,
        'calculated_at'      => isset($snapshot['calculated_at']) ? $snapshot['calculated_at'] : $now,
        'source'             => 'checkout_quote',
        'is_snapshot_locked' => true,
        'is_cart_detached'   => true,
        'finalized_at'       => $now,
    ];

    if ($fulfillment_type === 'delivery') {
        $breakdown_v5['delivery_snapshot_v46'] = $delivery_snapshot_v46;
    }

    if ($fulfillment_type === 'delivery' && isset($addr_snap) && is_array($addr_snap)) {
        $breakdown_v5['address'] = [
            'version'    => 'v1',
            'address_id' => isset($addr_snap['address_id']) ? $addr_snap['address_id'] : null,
            'label'      => (string) ($addr_snap['label'] ?? ''),
            'lat'        => (float) ($addr_snap['lat'] ?? 0.0),
            'lng'        => (float) ($addr_snap['lng'] ?? 0.0),
            'frozen_at'  => (string) ($addr_snap['frozen_at'] ?? $now),
        ];
    }

    // Build cart snapshot
    $snapshot_items = [];
    $item_count = 0;

    foreach ($cart_items as $it) {
        $qty = max(0, (int) ($it->quantity ?? 0));
        $item_count += $qty;

        $mods = null;
        if (!empty($it->modifiers_json)) {
            $decoded = json_decode($it->modifiers_json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $mods = $decoded;
            }
        }

        $snapshot_items[] = [
            'item_id'        => !empty($it->item_id) ? (int) $it->item_id : null,
            'name_snapshot'  => (string) ($it->name_snapshot ?? ''),
            'image_snapshot' => !empty($it->image_snapshot) ? (string) $it->image_snapshot : null,
            'quantity'       => $qty,
            'unit_price'     => (float) ($it->unit_price ?? 0),
            'line_total'     => (float) ($it->line_total ?? 0),
            'modifiers'      => $mods,
        ];
    }

    $cart_snapshot = [
        'version'       => 'v5',
        'hub' => [
            'id'      => $hub_id,
            'city_id' => (int) ($hub->city_id ?? 0),
            'name'    => (string) ($hub->name ?? ''),
            'address' => isset($hub->address) ? (string) $hub->address : null,
            'lat'     => isset($hub->latitude) ? (float) $hub->latitude : null,
            'lng'     => isset($hub->longitude) ? (float) $hub->longitude : null,
        ],
        'session_token' => (string) $session_token,
        'items'         => $snapshot_items,
        'subtotal'      => $subtotal,
        'item_count'    => $item_count,
        'created_at'    => $now,
    ];

    $order_number = 'ORD-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));

    /* ======================================================
     * E) ATOMIC TRANSACTION
     * ====================================================== */
    $wpdb->query('START TRANSACTION');

    try {

        // IMPORTANT: Nexus never accepts cash. Force Stripe fields explicitly.
        $wpdb->insert($table_orders, [
            'order_number'     => $order_number,
            'hub_id'           => $hub_id,
            'city_id'          => (int) ($hub->city_id ?? 0),
            'session_token'    => $session_token,
            'customer_id'      => $user_id,

            'fulfillment_type' => $fulfillment_type,

            'delivery_address' => $delivery_address_snapshot,
            'delivery_lat'     => $delivery_lat,
            'delivery_lng'     => $delivery_lng,

            'subtotal'         => $subtotal,
            'tax_rate'         => $tax_rate,
            'tax_amount'       => $tax_amount,
            'delivery_fee'     => $delivery_fee,
            'software_fee'     => $software_fee,
            'tip_amount'       => $tip_amount,
            'discount_amount'  => $discount_amount,
            'gift_card_amount' => 0.00,
            'total'            => $total,

            'payment_method'   => 'stripe',
            'payment_status'   => 'pending',

            'totals_snapshot'  => wp_json_encode($breakdown_v5),
            'cart_snapshot'    => wp_json_encode($cart_snapshot),

            // CRITICAL: unpaid orders must never be 'placed'
            'status'           => 'pending_payment',

            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $order_id = (int) $wpdb->insert_id;
        if ($order_id <= 0) {
            throw new Exception('ORDER_INSERT_FAILED');
        }

        foreach ($cart_items as $it) {
            $wpdb->insert($table_order_items, [
                'order_id'       => $order_id,
                'item_id'        => !empty($it->item_id) ? (int) $it->item_id : null,
                'name_snapshot'  => (string) ($it->name_snapshot ?? ''),
                'image_snapshot' => !empty($it->image_snapshot) ? (string) $it->image_snapshot : null,
                'quantity'       => (int) ($it->quantity ?? 0),
                'unit_price'     => (float) ($it->unit_price ?? 0),
                'line_total'     => (float) ($it->line_total ?? 0),
                'modifiers_json' => !empty($it->modifiers_json) ? (string) $it->modifiers_json : null,
                'created_at'     => $now,
            ]);
        }

        $wpdb->insert($table_order_history, [
            'order_id'   => $order_id,
            'status'     => 'pending_payment',
            'changed_by' => $user_id,
            'created_at' => $now,
        ]);

        $cart_updated = $wpdb->update(
            $table_carts,
            ['status' => 'converted', 'updated_at' => $now],
            ['id' => $cart_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($cart_updated === false || (int)$cart_updated < 1) {
            throw new Exception('CART_DETACHMENT_FAILED');
        }

        $wpdb->query('COMMIT');

        return new WP_REST_Response([
            'success'      => true,
            'order_id'     => $order_id,
            'order_number' => $order_number,
            'order_status' => 'pending_payment',
            'payment'      => [
                'method' => 'stripe',
                'status' => 'pending',
            ],
            'totals'       => [
                'subtotal'     => $subtotal,
                'delivery_fee' => $delivery_fee,
                'software_fee' => $software_fee,
                'tax_amount'   => $tax_amount,
                'tip_amount'   => $tip_amount,
                'total'        => $total,
                'currency'     => 'usd',
            ],
        ], 201);

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');

        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'ORDER_CREATE_FAILED',
            'message' => 'Failed to create order. Please try again.',
        ], 500);
    }
}
}
