<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KINGDOM NEXUS — TOTALS ENGINE (Server-side SSOT)
 * ----------------------------------------------------------
 * - Resolve software fees (hub override > city SSOT)
 * - Resolve coupons (validation + calculation; optional row lock)
 * - Quote totals (soft preview; not order creation)
 * ==========================================================
 */

/**
 * ==========================================================
 * PHASE 3.1.B — Resolve Software Fee (Hub > City)
 * ----------------------------------------------------------
 * DB Contract (knx_software_fees):
 * - scope      ENUM('city','hub')
 * - city_id    BIGINT UNSIGNED (required)
 * - hub_id     BIGINT UNSIGNED (0 for city scope)
 * - fee_amount DECIMAL(10,2)   (fixed amount)
 * - status     'active'|'inactive'
 *
 * Priority cascade:
 *   1) Hub-specific active fee (optional override)
 *   2) City-specific active fee (SSOT)
 *   3) No fee (fail-closed → 0.00)
 *
 * @param int   $city_id City ID from hub (required for SSOT)
 * @param float $subtotal Cart subtotal (not used for fixed fee, kept for forward-compat)
 * @param int   $hub_id Hub ID for optional hub-level override
 * @return array Normalized fee structure
 * ==========================================================
 */
if (!function_exists('knx_resolve_software_fee')) {
    function knx_resolve_software_fee($city_id, $subtotal = 0.00, $hub_id = 0) {
        global $wpdb;

        $city_id  = (int) $city_id;
        $hub_id   = (int) $hub_id;
        $subtotal = max(0.00, (float) $subtotal);

        $table_fees = $wpdb->prefix . 'knx_software_fees';

        $row = null;

        // Priority 1: Hub override (optional)
        if ($hub_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, scope, city_id, hub_id, fee_amount
                 FROM {$table_fees}
                 WHERE scope = 'hub'
                   AND hub_id = %d
                   AND status = 'active'
                 ORDER BY id DESC
                 LIMIT 1",
                $hub_id
            ));
        }

        // Priority 2: City SSOT
        if (!$row && $city_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, scope, city_id, hub_id, fee_amount
                 FROM {$table_fees}
                 WHERE scope = 'city'
                   AND city_id = %d
                   AND hub_id = 0
                   AND status = 'active'
                 ORDER BY id DESC
                 LIMIT 1",
                $city_id
            ));
        }

        // Fail-closed (no fee)
        if (!$row) {
            return [
                'applied'    => false,
                'scope'      => null,
                'city_id'    => null,
                'hub_id'     => null,
                'fee_amount' => 0.00,
                'label'      => null,
            ];
        }

        $fee_amount = isset($row->fee_amount) ? (float) $row->fee_amount : 0.00;
        $fee_amount = round(max(0.00, $fee_amount), 2);

        return [
            'applied'    => true,
            'scope'      => (string) $row->scope,
            'city_id'    => (int) $row->city_id,
            'hub_id'     => (int) $row->hub_id,
            'fee_amount' => $fee_amount,
            'label'      => 'Service Fee ($' . number_format($fee_amount, 2) . ')',
        ];
    }
}

/**
 * ==========================================================
 * PHASE 3.3.A/B — Resolve Coupon (Validation + Calculation)
 * ----------------------------------------------------------
 * Read-only validator + calculator.
 * Optional row lock for order creation transactions.
 * ==========================================================
 */
if (!function_exists('knx_resolve_coupon')) {
    function knx_resolve_coupon($code, $subtotal = 0.00, $lock = false) {
        global $wpdb;

        $code     = strtoupper(trim((string) $code));
        $subtotal = max(0.00, (float) $subtotal);

        if ($code === '') {
            return [
                'valid'           => false,
                'reason'          => 'invalid',
                'message'         => 'Coupon code is required.',
                'discount_amount' => 0.00,
                'snapshot'        => null,
            ];
        }

        $table_coupons = $wpdb->prefix . 'knx_coupons';

        // NOTE: FOR UPDATE works only inside a transaction (InnoDB).
        $lock_sql = $lock ? ' FOR UPDATE' : '';

        $coupon = $wpdb->get_row($wpdb->prepare(
            "SELECT id, code, type, value, min_subtotal, status,
                    starts_at, expires_at, usage_limit, used_count
             FROM {$table_coupons}
             WHERE UPPER(code) = %s
             LIMIT 1{$lock_sql}",
            $code
        ));

        if (!$coupon) {
            return [
                'valid'           => false,
                'reason'          => 'invalid',
                'message'         => 'Invalid coupon code.',
                'discount_amount' => 0.00,
                'snapshot'        => null,
            ];
        }

        if ((string) $coupon->status !== 'active') {
            return [
                'valid'           => false,
                'reason'          => 'inactive',
                'message'         => 'This coupon is no longer active.',
                'discount_amount' => 0.00,
                'snapshot'        => null,
            ];
        }

        $now_ts = (int) current_time('timestamp');

        if ($coupon->starts_at !== null) {
            $starts_ts = strtotime((string) $coupon->starts_at);
            if ($starts_ts !== false && $now_ts < (int) $starts_ts) {
                return [
                    'valid'           => false,
                    'reason'          => 'not_started',
                    'message'         => 'This coupon is not yet active.',
                    'discount_amount' => 0.00,
                    'snapshot'        => null,
                ];
            }
        }

        if ($coupon->expires_at !== null) {
            $expires_ts = strtotime((string) $coupon->expires_at);
            if ($expires_ts !== false && $now_ts > (int) $expires_ts) {
                return [
                    'valid'           => false,
                    'reason'          => 'expired',
                    'message'         => 'This coupon has expired.',
                    'discount_amount' => 0.00,
                    'snapshot'        => null,
                ];
            }
        }

        if ($coupon->usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit) {
            return [
                'valid'           => false,
                'reason'          => 'limit_reached',
                'message'         => 'This coupon has reached its usage limit.',
                'discount_amount' => 0.00,
                'snapshot'        => null,
            ];
        }

        if ($coupon->min_subtotal !== null && $subtotal < (float) $coupon->min_subtotal) {
            return [
                'valid'           => false,
                'reason'          => 'min_subtotal',
                'message'         => sprintf('Minimum order of $%.2f required for this coupon.', (float) $coupon->min_subtotal),
                'discount_amount' => 0.00,
                'snapshot'        => null,
            ];
        }

        $type  = (string) $coupon->type;
        $value = (float) $coupon->value;

        if (!in_array($type, ['percent', 'fixed'], true)) {
            return [
                'valid'           => false,
                'reason'          => 'invalid_type',
                'message'         => 'Coupon configuration invalid.',
                'discount_amount' => 0.00,
                'snapshot'        => null,
            ];
        }

        if ($value < 0) $value = 0.00;
        if ($type === 'percent' && $value > 100) $value = 100.00;

        $discount_amount = 0.00;
        if ($type === 'percent') {
            $discount_amount = round(($subtotal * $value) / 100, 2);
        } else {
            $discount_amount = round($value, 2);
        }

        $discount_amount = min($discount_amount, $subtotal);
        $discount_amount = max(0.00, $discount_amount);

        $snapshot = [
            'coupon_id'  => (int) $coupon->id,
            'code'       => (string) $coupon->code,
            'type'       => $type,
            'value'      => $value,
            'amount'     => $discount_amount,
            'applied_at' => current_time('mysql'),
        ];

        return [
            'valid'           => true,
            'reason'          => 'ok',
            'message'         => sprintf('Coupon applied: $%.2f off', $discount_amount),
            'discount_amount' => $discount_amount,
            'snapshot'        => $snapshot,
        ];
    }
}

/**
 * ==========================================================
 * Legacy Quote Totals (Soft Preview)
 * ----------------------------------------------------------
 * Not used for order creation; UI preview only.
 * Keeps SSOT resolver calls to avoid duplicated rules.
 * ==========================================================
 */
if (!function_exists('knx_totals_quote')) {
    function knx_totals_quote($params) {
        global $wpdb;

        if (!is_array($params)) {
            return ['success' => false, 'error' => 'invalid_params'];
        }

        $hub_id           = isset($params['hub_id']) ? (int) $params['hub_id'] : 0;
        $city_id          = isset($params['city_id']) ? (int) $params['city_id'] : 0;
        $fulfillment_type = isset($params['fulfillment_type']) ? (string) $params['fulfillment_type'] : 'delivery';
        $subtotal         = isset($params['subtotal']) ? (float) $params['subtotal'] : 0.00;
        $item_count       = isset($params['item_count']) ? (int) $params['item_count'] : 0;
        $tip_amount       = isset($params['tip_amount']) ? (float) $params['tip_amount'] : 0.00;

        $delivery_lat = array_key_exists('delivery_lat', $params) ? $params['delivery_lat'] : null;
        $delivery_lng = array_key_exists('delivery_lng', $params) ? $params['delivery_lng'] : null;
        $hub_lat      = array_key_exists('hub_lat', $params) ? $params['hub_lat'] : null;
        $hub_lng      = array_key_exists('hub_lng', $params) ? $params['hub_lng'] : null;

        if (!in_array($fulfillment_type, ['delivery', 'pickup'], true)) {
            $fulfillment_type = 'delivery';
        }

        if ($tip_amount < 0) $tip_amount = 0.00;

        if ($subtotal <= 0 || $item_count <= 0) {
            return ['success' => false, 'error' => 'cart_empty'];
        }

        $table_hubs = $wpdb->prefix . 'knx_hubs';
        $hub = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_rate, min_order, latitude, longitude
             FROM {$table_hubs}
             WHERE id = %d
             LIMIT 1",
            $hub_id
        ));

        if (!$hub) {
            return ['success' => false, 'error' => 'hub_not_found'];
        }

        $min_order = isset($hub->min_order) ? (float) $hub->min_order : 0.00;
        if ($min_order > 0 && $subtotal < $min_order) {
            return [
                'success'   => false,
                'error'     => 'min_order_not_met',
                'min_order' => $min_order,
            ];
        }

        $tax_rate = isset($hub->tax_rate) ? (float) $hub->tax_rate : 0.00;

        // Fetch delivery rate configuration (for distance unit + ETA knobs)
        $table_rates = $wpdb->prefix . 'knx_delivery_rates';
        $delivery_rate_config = null;
        if ($city_id > 0) {
            $delivery_rate_config = $wpdb->get_row($wpdb->prepare(
                "SELECT distance_unit, eta_base_minutes, eta_per_distance_minutes, eta_buffer_minutes,
                        flat_rate, rate_per_distance
                 FROM {$table_rates}
                 WHERE city_id = %d AND status = 'active'
                 LIMIT 1",
                $city_id
            ));
        }

        $distance_unit = 'mile';
        if ($delivery_rate_config && isset($delivery_rate_config->distance_unit)) {
            $unit = strtolower(trim((string) $delivery_rate_config->distance_unit));
            if (in_array($unit, ['mile', 'kilometer'], true)) {
                $distance_unit = $unit;
            }
        }

        $totals = [
            'subtotal'     => round($subtotal, 2),
            'tax_rate'     => round($tax_rate, 2),
            'tax_amount'   => 0.00,
            'delivery_fee' => 0.00,
            'software_fee' => 0.00,
            'tip_amount'   => round($tip_amount, 2),
            'total'        => 0.00,
        ];

        // Tax (legacy preview)
        $totals['tax_amount'] = round(($totals['subtotal'] * $totals['tax_rate']) / 100, 2);
        if ($totals['tax_amount'] < 0) $totals['tax_amount'] = 0.00;

        // ======================================================
        // DELIVERY DISTANCE SSOT (compute once, reuse for fee + snapshot)
        // KNX-A0.6: Use canonical knx_calculate_distance() for measurement
        // ======================================================
        $distance_result = null;
        $canonical_distance = null;

        // KNX-A0.6: Canonical distance measurement (Haversine)
        if ($fulfillment_type === 'delivery' && function_exists('knx_calculate_distance_v2')) {
            if ($hub_lat !== null && $hub_lng !== null && $delivery_lat !== null && $delivery_lng !== null) {
                $canonical_distance = knx_calculate_distance_v2($hub_lat, $hub_lng, $delivery_lat, $delivery_lng);
            }
        }

        // Legacy distance engine (for ETA calculation)
        if ($fulfillment_type === 'delivery' && function_exists('knx_delivery_distance_decision')) {
            $distance_result = knx_delivery_distance_decision([
                'hub_lat'       => $hub_lat,
                'hub_lng'       => $hub_lng,
                'delivery_lat'  => $delivery_lat,
                'delivery_lng'  => $delivery_lng,
                'distance_unit' => $distance_unit
            ]);
        }

        // ======================================================
        // KNX-A0.7: DELIVERY FEE SSOT (Snapshot-Hard)
        // ======================================================
        $delivery_fee_result = null;
        if (function_exists('knx_calculate_delivery_fee')) {
            // Prepare rate config from DB row
            $rate_config = null;
            if ($delivery_rate_config) {
                $rate_config = [
                    'flat_rate'          => isset($delivery_rate_config->flat_rate) ? (float) $delivery_rate_config->flat_rate : 0.00,
                    'rate_per_distance'  => isset($delivery_rate_config->rate_per_distance) ? (float) $delivery_rate_config->rate_per_distance : 0.00,
                    'distance_unit'      => $distance_unit,
                ];
            }

            // Extract canonical distance (KNX-A0.6)
            $distance_km = null;
            if ($canonical_distance && !empty($canonical_distance['ok'])) {
                $distance_km = $canonical_distance['distance_km'];
            }

            // Call SSOT fee calculator
            $delivery_fee_result = knx_calculate_delivery_fee([
                'hub_id'           => $hub_id,
                'city_id'          => $city_id,
                'fulfillment_type' => $fulfillment_type,
                'distance_km'      => $distance_km,
                'rate_config'      => $rate_config,
            ]);

            // Apply fee to totals
            if ($delivery_fee_result && !empty($delivery_fee_result['ok'])) {
                $totals['delivery_fee'] = $delivery_fee_result['fee'];
            }
        }

        // Software fee (delegate to SSOT resolver)
        $software_rule = null;
        if (function_exists('knx_resolve_software_fee')) {
            $resolved = knx_resolve_software_fee($city_id, $totals['subtotal'], $hub_id);
            if (!empty($resolved['applied'])) {
                $totals['software_fee'] = round((float) ($resolved['fee_amount'] ?? 0.00), 2);
                if ($totals['software_fee'] < 0) $totals['software_fee'] = 0.00;

                $software_rule = [
                    'scope'      => (string) ($resolved['scope'] ?? ''),
                    'city_id'    => isset($resolved['city_id']) ? (int) $resolved['city_id'] : null,
                    'hub_id'     => isset($resolved['hub_id']) ? (int) $resolved['hub_id'] : null,
                    'fee_amount' => isset($resolved['fee_amount']) ? (float) $resolved['fee_amount'] : null,
                    'label'      => (string) ($resolved['label'] ?? ''),
                ];
            }
        }

        $totals['total'] = round(
            $totals['subtotal']
            + $totals['tax_amount']
            + $totals['delivery_fee']
            + $totals['software_fee']
            + $totals['tip_amount'],
            2
        );

        if ($totals['total'] < 0) $totals['total'] = 0.00;

        $snapshot = [
            'calculated_at'     => current_time('mysql'),
            'hub_id'            => $hub_id,
            'city_id'           => $city_id,
            'fulfillment_type'  => $fulfillment_type,
            'subtotal'          => $totals['subtotal'],
            'tax_rate'          => $totals['tax_rate'],
            'tax_amount'        => $totals['tax_amount'],
            'delivery_fee'      => $totals['delivery_fee'],
            'software_fee'      => $totals['software_fee'],
            'software_fee_rule' => $software_rule,
            'tip_amount'        => $totals['tip_amount'],
            'total'             => $totals['total'],
        ];

        // Add delivery distance & ETA (only for delivery mode)
        // KNX-A0.6: Include canonical distance in snapshot
        // KNX-A0.7: Include canonical fee breakdown (snapshot-hard)
        if ($fulfillment_type === 'delivery') {
            $delivery_snapshot = [];

            // Canonical distance (KNX-A0.6 SSOT)
            if ($canonical_distance && $canonical_distance['ok']) {
                $delivery_snapshot['distance_km'] = $canonical_distance['distance_km'];
                $delivery_snapshot['distance_miles'] = $canonical_distance['distance_miles'];
                $delivery_snapshot['method'] = $canonical_distance['method'];
            }

            // KNX-A0.7: Canonical delivery fee breakdown (SNAPSHOT-HARD)
            // This is the frozen fee authority used by create-order
            if ($delivery_fee_result && $delivery_fee_result['ok']) {
                $delivery_snapshot['delivery_fee'] = $delivery_fee_result['fee'];
                $delivery_snapshot['fee_method'] = $delivery_fee_result['method'];
                $delivery_snapshot['rate'] = [
                    'flat_rate'        => $delivery_fee_result['flat_rate'],
                    'per_distance'     => $delivery_fee_result['rate_per_distance'],
                    'unit'             => $delivery_fee_result['distance_unit'],
                ];
            }

            // Legacy distance + ETA (for backward compatibility)
            if ($distance_result && function_exists('knx_delivery_eta_decision')) {
                // Compute ETA using legacy distance
                $eta_result = knx_delivery_eta_decision([
                    'distance'                   => $distance_result['distance'],
                    'eta_base_minutes'           => $delivery_rate_config ? $delivery_rate_config->eta_base_minutes : null,
                    'eta_per_distance_minutes'   => $delivery_rate_config ? $delivery_rate_config->eta_per_distance_minutes : null,
                    'eta_buffer_minutes'         => $delivery_rate_config ? $delivery_rate_config->eta_buffer_minutes : null
                ]);

                // Legacy format (keep for backward compatibility)
                $delivery_snapshot['distance'] = [
                    'value'       => $distance_result['distance'],
                    'unit'        => $distance_result['unit'],
                    'ok'          => $distance_result['can_compute'],
                    'reason_code' => $distance_result['reason_code']
                ];

                $delivery_snapshot['eta'] = [
                    'duration_minutes'       => $eta_result['duration_minutes'],
                    'estimated_delivery_at'  => $eta_result['estimated_delivery_at'],
                    'ok'                     => $eta_result['can_estimate'],
                    'reason_code'            => $eta_result['reason_code']
                ];
            }

            if (!empty($delivery_snapshot)) {
                $snapshot['delivery'] = $delivery_snapshot;
            }
        }

        return [
            'success'  => true,
            'totals'   => $totals,
            'snapshot' => $snapshot,
        ];
    }
}
