<?php
if (!defined('ABSPATH')) exit;

// KNX-A0.8.1: Snapshot version authority (centralized, guarded)
// v5 = Namespaced snapshot structure with explicit version enforcement
// - cart_snapshot.version: 'v5' (explicit versioning)
// - cart_snapshot.hub: { id, city_id, name, address, lat, lng } (namespaced hub data)
// - Immutable: pickup address frozen at order creation
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
 * KNX-A0.9 — ORDER FINALIZATION & POST-CREATE CANONICALIZATION
 * ----------------------------------------------------------
 * ⚠️ FINAL AUTHORITY FOR ORDER CREATION (NEXUS CANON) ⚠️
 *
 * DO NOT DUPLICATE THIS ENDPOINT
 * DO NOT BYPASS THIS VALIDATION CASCADE
 * DO NOT CREATE ORDERS OUTSIDE THIS FILE
 *
 * This is the ONLY authorized path for order creation.
 * All order creation MUST flow through this endpoint.
 * ----------------------------------------------------------
 *
 * KNX-A0.8 ENFORCEMENT (Snapshot-Hard):
 * - Orders are IMMUTABLE snapshots from checkout/quote
 * - NO fee recalculation (delivery, software, tax)
 * - NO distance recalculation
 * - NO totals recomputation
 * - Quote decides, Order executes
 * - Fail-closed on missing snapshot data
 *
 * KNX-A0.9 ENFORCEMENT (Post-Create Canonicalization):
 * - Cart becomes UNUSABLE after order creation (status = converted)
 * - Order is SINGLE SOURCE OF TRUTH for all future operations
 * - NO cart reads after order creation
 * - Readiness flags: is_snapshot_locked, is_cart_detached
 * - Hard guards against re-entry and modification
 * - Canonical logging for audit trail
 *
 * DB SCHEMA ALIGNMENT (2026-01-03):
 * - REMOVED: orders.cart_id (column does not exist)
 * - REMOVED: order_status_history.notes (column does not exist)
 * - Idempotency: Time-windowed duplicate prevention (10-min window)
 *
 * VALIDATION CASCADE (SEALED - DO NOT REORDER):
 *   A1) AUTH — Session required
 *   A2) CART RESOLUTION — From cookie only
 *   A3) CART OWNERSHIP — session_token + customer_id match
 *   A4) CART STATE — status = 'active'
 *   A5) CART ITEMS — ≥ 1 item
 *   A6) HUB VALIDATION — exists + active
 *   A7) PROFILE MIN — knx_profile_status() complete
 *   A8) AVAILABILITY — knx_availability_decision() can_order
 *   A9) DELIVERY ADDRESS — HARD (v1.8 Addresses)
 *   A10) SNAPSHOT VALIDATION — HARD (KNX-A0.8)
 *   B) IDEMPOTENCY — Time-window + composite key
 *   C) SNAPSHOT ENFORCEMENT — All totals from quote snapshot
 *   D) CART SNAPSHOT — items immutable
 *   E) ATOMIC TRANSACTION — all writes or rollback
 *   F) POST-CREATE FINALIZATION — KNX-A0.9
 *
 * ECONOMICS (Snapshot-Locked):
 * - Delivery Fee: From snapshot.delivery.delivery_fee (KNX-A0.7)
 * - Software Fee: From snapshot.software_fee (Phase 3.1)
 * - Taxes: From snapshot.tax_amount (Phase 4)
 * - Tips: From snapshot.tip_amount
 * - Total: From snapshot.total
 *
 * PROHIBITED:
 * - knx_calculate_delivery_fee()
 * - knx_calculate_distance()
 * - knx_totals_quote()
 * - knx_resolve_software_fee()
 * - Any fee recalculation
 * - Reading cart after order creation
 * - Modifying placed orders
 * ==========================================================
 */

/**
 * Register Create Order endpoint (WRAPPED + SECURED).
 * KNX-A0.8.1: No engine loading, no economic inputs.
 */
add_action('rest_api_init', function () {

    register_rest_route('knx/v1', '/orders/create', [
        'methods'             => 'POST',
        'callback'            => function ($req) {
            // Use canonical wrapper when available.
            if (function_exists('knx_rest_wrap')) {
                $wrapped = knx_rest_wrap('knx_api_create_order_mvp');
                return $wrapped($req);
            }

            // Fallback (should not happen in Nexus)
            return knx_api_create_order_mvp($req);
        },
        'permission_callback' => function () {
            return function_exists('knx_rest_permission_session')
                ? knx_rest_permission_session()()
                : true;
        },
    ]);
});

/**
 * Create Order Handler (SEALED).
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
if (!function_exists('knx_api_create_order_mvp')) {
function knx_api_create_order_mvp(WP_REST_Request $req) {
    global $wpdb;

    $table_carts         = $wpdb->prefix . 'knx_carts';
    $table_cart_items    = $wpdb->prefix . 'knx_cart_items';
    $table_hubs          = $wpdb->prefix . 'knx_hubs';
    $table_orders        = $wpdb->prefix . 'knx_orders';
    $table_order_items   = $wpdb->prefix . 'knx_order_items';
    $table_order_history = $wpdb->prefix . 'knx_order_status_history';
    $table_coupons       = $wpdb->prefix . 'knx_coupons'; // reserved for future locking

    // KNX-GUARD: create-order must never trigger live coverage or recalculation
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
    
    /* ======================================================
     * AUTH ROLE HARD BLOCK — NO STAFF/DRIVER/ADMIN
     * =====================================================
     */
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

    /**
     * IMPORTANT:
     * - Do NOT filter by status here, so we can return canonical reasons:
     *   - converted => ORDER_ALREADY_FINALIZED
     *   - other states => CART_INVALID_STATE
     */
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
    $hub_id = (int) $cart->hub_id; // KNX-409: Define early for A4 converted check

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
     * A4) CART STATE — HARD (KNX-A0.9.4: Guard against re-entry)
     * ====================================================== */
    if ((string) $cart->status === 'converted') {
        // Cart already converted - find the existing order and return it
        // This allows idempotent order creation (frontend can proceed with payment)
        // KNX-409: Use cart->updated_at as temporal anchor (10-min window)
        $cart_updated_at = $cart->updated_at; // Anchor time when cart was converted
        
        $existing_order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, order_number, status, created_at
             FROM {$table_orders}
             WHERE session_token = %s
               AND hub_id = %d
               AND customer_id = %d
               AND status IN ('placed', 'pending_payment', 'confirmed', 'preparing', 'ready', 'out_for_delivery')
               AND created_at >= DATE_SUB(%s, INTERVAL 10 MINUTE)
             ORDER BY created_at DESC
             LIMIT 1",
            $session_token,
            $hub_id,
            $user_id,
            $cart_updated_at
        ));

        if ($existing_order) {
            knx_debug_log(sprintf(
                '[KNX-409] A4-converted: cart_id=%d cart_status=%s hub_id=%d order_id=%d order_status=%s session_token=%s',
                $cart_id,
                (string) $cart->status,
                $hub_id,
                (int) $existing_order->id,
                (string) $existing_order->status,
                $session_token
            ));

            return new WP_REST_Response([
                'success'        => true,
                'already_exists' => true,
                'order_id'       => (int) $existing_order->id,
                'order_number'   => (string) $existing_order->order_number,
                'reason'         => 'ORDER_ALREADY_FINALIZED',
                'message'        => 'Order already exists for this cart.',
            ], 200);
        }

        // Cart is converted but we can't find the order (should never happen)
        knx_debug_log(sprintf(
            '[KNX-409] CRITICAL: A4-converted orphan: cart_id=%d cart_status=%s hub_id=%d session_token=%s',
            $cart_id,
            (string) $cart->status,
            $hub_id,
            $session_token
        ));

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
    // KNX-409: $hub_id already defined before A4
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
     * A9) DELIVERY ADDRESS — NOTE: Address must come from
     * the provided order snapshot (see A10). We do NOT read
     * or validate live address tables here.
     * ======================================================
     */
    $body = $req->get_json_params();
    $fulfillment_type = isset($body['fulfillment_type']) ? strtolower(trim($body['fulfillment_type'])) : 'delivery';

    $delivery_address_snapshot = null;
    $delivery_lat = null;
    $delivery_lng = null;

    /* ======================================================
     * A9.1) DELIVERY SNAPSHOT — HARD GATE (KNX-A4.6)
     * ======================================================
     * NOTE:
     * - Delivery snapshot computation belongs to quote.
     * - Create-order is snapshot-driven and must NOT compute delivery.
     * ====================================================== */

    /* ======================================================
     * A10) SNAPSHOT VALIDATION — HARD (KNX-A0.8)
     * ====================================================== */
    $snapshot = isset($body['snapshot']) && is_array($body['snapshot']) ? $body['snapshot'] : null;

    if ($snapshot === null) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SNAPSHOT_REQUIRED',
            'message' => 'Order snapshot is required. Please re-quote your order.',
        ], 409);
    }

    // Fail-closed: Require critical snapshot fields
    if (!isset($snapshot['subtotal']) || !isset($snapshot['total'])) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SNAPSHOT_INCOMPLETE',
            'message' => 'Order snapshot is incomplete. Please re-quote your order.',
        ], 409);
    }

    /* ======================================================
     * A9) ADDRESS SNAPSHOT VALIDATION (SEALED v4.6 CANON)
     * - Address snapshot is stored inside:
     *   snapshot.delivery.delivery_snapshot_v46.address
     * - We DO NOT read knx_addresses here.
     * - Required fields: label, lat, lng
     * ======================================================
     */
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

        // Required minimal fields
        if (!isset($addr_snap['label']) || !isset($addr_snap['lat']) || !isset($addr_snap['lng'])) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'ADDRESS_SNAPSHOT_INCOMPLETE',
                'message' => 'Delivery address snapshot is incomplete. Please re-quote your order.',
            ], 409);
        }

        // Convert coords (basic sanity only, do not revalidate against live DB)
        $delivery_lat = (float) $addr_snap['lat'];
        $delivery_lng = (float) $addr_snap['lng'];

        if (!is_numeric($delivery_lat) || !is_numeric($delivery_lng)) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'ADDRESS_SNAPSHOT_INVALID',
                'message' => 'Address coordinates are invalid in snapshot.',
            ], 409);
        }

        // Prepare a simple display label for legacy DB column
        $delivery_address_snapshot = (string) $addr_snap['label'];
    }

    // Delivery mode: Require delivery snapshot objects
    $delivery_snapshot_v46 = null;

    if ($fulfillment_type === 'delivery') {
        if (!isset($snapshot['delivery']) || !is_array($snapshot['delivery'])) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_SNAPSHOT_MISSING',
                'message' => 'Delivery information is missing. Please re-quote your order.',
            ], 409);
        }

        // Required: delivery_fee within delivery object (matches quote structure)
        if (!isset($snapshot['delivery']['delivery_fee'])) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_FEE_MISSING',
                'message' => 'Delivery fee is missing. Please re-quote your order.',
            ], 409);
        }

        // Required: v4.6 sealed delivery snapshot inside delivery object
        if (!isset($snapshot['delivery']['delivery_snapshot_v46']) || !is_array($snapshot['delivery']['delivery_snapshot_v46'])) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_SNAPSHOT_V46_MISSING',
                'message' => 'Delivery snapshot (v4.6) is missing. Please re-quote your order.',
            ], 409);
        }

        $delivery_snapshot_v46 = $snapshot['delivery']['delivery_snapshot_v46'];

        // Required: fee amount inside sealed snapshot
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

        // Coherence check:
        // - delivery.delivery_fee must match sealed snapshot fee
        $delivery_fee_in_delivery_obj = (float) $snapshot['delivery']['delivery_fee'];
        if (abs($ds_fee - $delivery_fee_in_delivery_obj) > 0.01) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_SNAPSHOT_MISMATCH',
                'message' => 'Delivery snapshot does not match quoted fee. Please re-quote your order.',
            ], 409);
        }

        // Optional but strongly recommended:
        // - top-level delivery_fee (if present) must match too
        if (isset($snapshot['delivery_fee'])) {
            $delivery_fee_top = (float) $snapshot['delivery_fee'];
            if (abs($ds_fee - $delivery_fee_top) > 0.01) {
                return new WP_REST_Response([
                    'success' => false,
                    'reason'  => 'DELIVERY_SNAPSHOT_MISMATCH',
                    'message' => 'Delivery snapshot does not match quoted fee. Please re-quote your order.',
                ], 409);
            }
        }
    }

    // ==========================================================
    // KNX — DELIVERY SNAPSHOT AUTHORITY (SEALED)
    // ==========================================================
    // Enforce that create-order uses only the sealed delivery snapshot
    // produced by the quote endpoint. Do NOT call coverage engines,
    // do NOT query zones, and do NOT recalculate radius/fees here.
    if ($fulfillment_type === 'delivery') {
        $delivery = isset($snapshot['delivery']['delivery_snapshot_v46']) && is_array($snapshot['delivery']['delivery_snapshot_v46'])
            ? $snapshot['delivery']['delivery_snapshot_v46']
            : null;

        if (
            !$delivery ||
            empty($delivery['address']) ||
            empty($delivery['address']['address_id'])
        ) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_SNAPSHOT_MISSING',
                'message' => 'Delivery snapshot missing or invalid during order creation',
            ], 409);
        }

        // Enforce immutable snapshot fields
        $address_id   = (int) $delivery['address']['address_id'];
        $delivery_fee = isset($delivery['delivery_fee']['amount']) ? (float) $delivery['delivery_fee']['amount'] : null;
        $coverage     = isset($delivery['coverage']) ? $delivery['coverage'] : null;

        if ($delivery_fee === null || $coverage === null) {
            return new WP_REST_Response([
                'success' => false,
                'reason'  => 'DELIVERY_SNAPSHOT_INCOMPLETE',
                'message' => 'Delivery snapshot incomplete (fee or coverage missing)',
            ], 409);
        }

        // Do NOT perform any live coverage or DB zone lookups here.
        // The presence of KNX_CREATE_ORDER_CONTEXT should be checked by
        // coverage engines to avoid accidental recalculation.
    }

    /* ======================================================
     * B) IDEMPOTENCY — Time-windowed duplicate prevention
     * ====================================================== */
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, order_number, created_at
         FROM {$table_orders}
         WHERE session_token = %s
           AND hub_id = %d
           AND customer_id = %d
           AND status IN ('placed', 'confirmed', 'preparing', 'ready', 'out_for_delivery')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
         ORDER BY created_at DESC
         LIMIT 1",
        $session_token,
        $hub_id,
        $user_id
    ));

    if ($existing) {
        knx_debug_log(sprintf(
            '[KNX-409] B-idempotency: cart_id=%d cart_status=%s hub_id=%d order_id=%d order_created_at=%s session_token=%s',
            $cart_id,
            (string) $cart->status,
            $hub_id,
            (int) $existing->id,
            (string) $existing->created_at,
            $session_token
        ));
        
        return new WP_REST_Response([
            'success'        => true,
            'already_exists' => true,
            'order_id'       => (int) $existing->id,
            'order_number'   => (string) $existing->order_number,
            'reason'         => 'DUPLICATE_ORDER_PREVENTED',
            'message'        => 'Order already exists for this session.',
        ], 200);
    }

    /* ======================================================
     * C) SNAPSHOT ENFORCEMENT — All totals from quote (KNX-A0.8)
     * ====================================================== */

    // KNX-A3.4: HARD GATE — Tax fields MUST exist in snapshot
    if (!isset($snapshot['tax_amount']) || !isset($snapshot['tax_rate'])) {
        knx_debug_log(sprintf(
            '[KNX-A3.4] ABORT: Missing tax fields in snapshot. cart_id=%d hub_id=%d',
            $cart_id, $hub_id
        ));
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SNAPSHOT_INCOMPLETE',
            'message' => 'Order snapshot is missing required tax information. Please re-quote your order.',
        ], 409);
    }

    // Extract ALL monetary values from snapshot (fail-closed)
    $subtotal     = isset($snapshot['subtotal']) ? (float) $snapshot['subtotal'] : 0.00;
    $tax_amount   = (float) $snapshot['tax_amount']; // Required by gate above
    $tax_rate     = (float) $snapshot['tax_rate'];   // Required by gate above
    $delivery_fee = isset($snapshot['delivery_fee']) ? (float) $snapshot['delivery_fee'] : 0.00;
    $software_fee = isset($snapshot['software_fee']) ? (float) $snapshot['software_fee'] : 0.00;
    $tip_amount   = isset($snapshot['tip_amount']) ? (float) $snapshot['tip_amount'] : 0.00;
    $total        = isset($snapshot['total']) ? (float) $snapshot['total'] : 0.00;

    // KNX-A3.4: Validate tax_amount is non-negative
    if ($tax_amount < 0) {
        knx_debug_log(sprintf(
            '[KNX-A3.4] ABORT: Negative tax_amount=%.2f in snapshot. cart_id=%d',
            $tax_amount, $cart_id
        ));
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'INVALID_TAX_AMOUNT',
            'message' => 'Invalid tax amount in order snapshot. Please re-quote your order.',
        ], 409);
    }

    knx_debug_log(sprintf(
        '[KNX-A3.4] Tax snapshot validated: tax_rate=%.2f%% tax_amount=%.2f cart_id=%d',
        $tax_rate, $tax_amount, $cart_id
    ));

    // Validate subtotal coherence with cart items
    $cart_subtotal = 0.00;
    foreach ($cart_items as $it) {
        $cart_subtotal += isset($it->line_total) ? (float) $it->line_total : 0.00;
    }
    $cart_subtotal = round($cart_subtotal, 2);

    // Tolerance check: Allow $0.01 rounding difference
    if (abs($cart_subtotal - $subtotal) > 0.01) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SUBTOTAL_MISMATCH',
            'message' => 'Cart has changed. Please re-quote your order.',
        ], 409);
    }

    // Fail-closed: Validate minimum order total
    if ($total <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'INVALID_TOTAL',
            'message' => 'Order total is invalid.',
        ], 409);
    }

    // Extract delivery snapshot (KNX-A0.7 fee lock)
    $delivery_snapshot = null;
    if ($fulfillment_type === 'delivery' && isset($snapshot['delivery']) && is_array($snapshot['delivery'])) {
        $delivery_snapshot = $snapshot['delivery'];

        // Canon: delivery_fee comes from delivery snapshot object (if present)
        if (isset($delivery_snapshot['delivery_fee'])) {
            $delivery_fee = (float) $delivery_snapshot['delivery_fee'];
        }
    }

    // Extract discount_amount from snapshot (KNX-A0.8.1)
    $discount_amount = isset($snapshot['discount_amount']) ? (float) $snapshot['discount_amount'] : 0.00;

    // Prepare immutable totals snapshot for DB (canonical version)
    // KNX-A0.9.3: Added readiness flags for Phase 5 (payments, fulfillment)
    // KNX-A4.6: Added delivery_snapshot_v46 for Phase 4.6 (delivery data freeze)
    $breakdown_v5 = [
        'version'            => KNX_ORDER_SNAPSHOT_VERSION,
        'currency'           => 'USD',
        'subtotal'           => $subtotal,
        'tax_rate'           => $tax_rate,
        'tax_amount'         => $tax_amount,
        'delivery_fee'       => $delivery_fee,
        'software_fee'       => $software_fee,
        'discount_amount'    => $discount_amount,
        'tip_amount'         => $tip_amount,
        'total'              => $total,
        'delivery'           => $delivery_snapshot,
        'calculated_at'      => isset($snapshot['calculated_at']) ? $snapshot['calculated_at'] : current_time('mysql'),
        'source'             => 'checkout_quote',
        // KNX-A0.9.3: Readiness flags (internal enforcement)
        'is_snapshot_locked' => true,
        'is_cart_detached'   => true,
        'finalized_at'       => current_time('mysql'),
    ];

    // KNX-A4.6: Delivery snapshot (coverage, distance, fee) - IMMUTABLE
    if ($fulfillment_type === 'delivery') {
        $breakdown_v5['delivery_snapshot_v46'] = $delivery_snapshot_v46;
    }

    // Attach sealed address snapshot (from provided snapshot) — immutable record
    if ($fulfillment_type === 'delivery' && isset($addr_snap) && is_array($addr_snap)) {
        $breakdown_v5['address'] = [
            'version'    => 'v1',
            'address_id' => isset($addr_snap['address_id']) ? $addr_snap['address_id'] : null,
            'label'      => (string) ($addr_snap['label'] ?? ''),
            'lat'        => (float) ($addr_snap['lat'] ?? 0.0),
            'lng'        => (float) ($addr_snap['lng'] ?? 0.0),
            'frozen_at'  => (string) ($addr_snap['frozen_at'] ?? current_time('mysql')),
        ];
    }

    $now = current_time('mysql');
    $order_number = 'ORD-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));

    /* ======================================================
     * E) ATOMIC TRANSACTION (Snapshot Persistence Only)
     * ====================================================== */
    $wpdb->query('START TRANSACTION');

    try {

        /* ======================================================
         * D) CART SNAPSHOT — IMMUTABLE
         * ====================================================== */
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

        // Insert order with snapshot-locked totals
        $wpdb->insert($table_orders, [
            'order_number'     => $order_number,
            'hub_id'           => $hub_id,
            'city_id'          => (int) ($hub->city_id ?? 0),
            'session_token'    => $session_token,
            'customer_id'      => $user_id,

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

            'totals_snapshot'  => wp_json_encode($breakdown_v5),
            'cart_snapshot'    => wp_json_encode($cart_snapshot),

            'status'           => 'placed',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $order_id = (int) $wpdb->insert_id;
        if ($order_id <= 0) {
            throw new Exception('ORDER_INSERT_FAILED');
        }

        // Insert order items (snapshot-locked)
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

        // Status history (no notes column)
        $wpdb->insert($table_order_history, [
            'order_id'   => $order_id,
            'status'     => 'placed',
            'changed_by' => $user_id,
            'created_at' => $now,
        ]);

        // KNX-A0.9.1: Convert cart to 'converted' status (HARD - cart becomes unusable)
        $cart_updated = $wpdb->update(
            $table_carts,
            ['status' => 'converted', 'updated_at' => $now],
            ['id' => $cart_id],
            ['%s', '%s'],
            ['%d']
        );

        // Fail-closed: must actually detach exactly one cart row
        if ($cart_updated === false || (int)$cart_updated < 1) {
            throw new Exception('CART_DETACHMENT_FAILED');
        }

        $wpdb->query('COMMIT');

        /* ======================================================
         * F) POST-CREATE FINALIZATION (KNX-A0.9)
         * ====================================================== */

        // KNX-A0.9.1: Validate canonical state after commit
        $order_check = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, total FROM {$table_orders} WHERE id = %d LIMIT 1",
            $order_id
        ));

        if (!$order_check || (string) $order_check->status !== 'placed') {
            knx_debug_log(sprintf(
                '[KNX-A0.9] CRITICAL: Order state validation failed. order_id=%d expected_status=placed actual_status=%s',
                $order_id,
                $order_check ? (string)$order_check->status : 'NULL'
            ));
        }

        $cart_check = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table_carts} WHERE id = %d LIMIT 1",
            $cart_id
        ));

        if (!$cart_check || (string) $cart_check->status !== 'converted') {
            knx_debug_log(sprintf(
                '[KNX-A0.9] CRITICAL: Cart detachment validation failed. cart_id=%d expected_status=converted actual_status=%s',
                $cart_id,
                $cart_check ? (string)$cart_check->status : 'NULL'
            ));
        }

        // KNX-A0.9.5: Canonical logging (minimal, no dumps)
        knx_debug_log(sprintf(
            '[KNX-A0.9] Order finalized: order_id=%d order_number=%s hub_id=%d customer_id=%d total=%.2f snapshot_version=%s',
            $order_id,
            $order_number,
            $hub_id,
            $user_id,
            $total,
            KNX_ORDER_SNAPSHOT_VERSION
        ));

        return new WP_REST_Response([
            'success'      => true,
            'order_id'     => $order_id,
            'order_number' => $order_number,
            'totals'       => [
                'subtotal'     => $subtotal,
                'delivery_fee' => $delivery_fee,
                'software_fee' => $software_fee,
                'tax_amount'   => $tax_amount,
                'tip_amount'   => $tip_amount,
                'total'        => $total,
                'currency'     => 'USD',
                'snapshot'     => $breakdown_v5,
            ],
            // KNX-A0.9: Order readiness metadata (safe for frontend)
            'order_status' => 'placed',
            'is_finalized' => true,
        ], 201);

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');

        // Generic error response (no technical leakage)
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'ORDER_CREATE_FAILED',
            'message' => 'Failed to create order. Please try again.',
        ], 500);
    }
}
} // end if function_exists
