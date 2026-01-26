<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Availability Engine (SEALED v2.0)
 * ----------------------------------------------------------
 * Single canonical source of truth for Hub order availability.
 *
 * CONTRACT (LOCKED):
 * - One public function only: knx_availability_decision($hub_id)
 * - Pure read-only (no DB writes, no caching, no hooks)
 * - Deterministic, fail-closed
 * - Uses Hub timezone ONLY (no WP/server/user fallback)
 * - No overnight support (all hours same calendar day)
 * - 15-minute closing cutoff (hard-coded)
 *
 * AVAILABILITY BLOCK CONTRACT (TASK 01):
 * - Standardized error response for checkout/orders
 * - success=false, error="availability_block", HTTP 409
 * - Includes: reason, message, can_order=false, availability details
 * - SSOT: One helper builds response (TASK 02)
 *
 * Implements Phase 1.5.A Availability Authority Design.
 * Implements Phase 1.6.C Closing Cutoff Rule.
 * ==========================================================
 */

/**
 * TASK 02: Helper to build standardized availability block response.
 * 
 * CANONICAL CONTRACT (do not modify without updating all consumers):
 * - Used by: api-checkout-quote.php, api-create-order-mvp.php
 * - HTTP Status: 409 Conflict
 * - Structure: Fixed keys, fail-closed
 * 
 * @param array $availability_decision Result from knx_availability_decision()
 * @return WP_REST_Response Standardized 409 response
 */
if (!function_exists('knx_rest_availability_block')) {
    function knx_rest_availability_block($availability_decision) {
        return new WP_REST_Response([
            'success'        => false,
            'error'          => 'availability_block',
            'can_order'      => false,
            'can_place_order' => false, // UX alias
            'reason'         => isset($availability_decision['reason']) ? (string) $availability_decision['reason'] : 'UNKNOWN',
            'message'        => isset($availability_decision['message']) ? (string) $availability_decision['message'] : 'Restaurant unavailable',
            'availability'   => $availability_decision,
        ], 409);
    }
}

/**
 * Determine if a Hub can accept orders right now.
 *
 * @param int $hub_id Required. Hub ID to evaluate.
 *
 * @return array Availability decision (exact 6 keys, strict contract):
 *   [
 *     'can_order' => bool,
 *     'reason'    => string,      // ENUM (see cascade below)
 *     'message'   => string,      // Status descriptor (stable, generic)
 *     'reopen_at' => string|null, // ISO 8601 ONLY for HUB_TEMP_CLOSED, else null
 *     'source'    => string,      // city|hub|hours
 *     'severity'  => string       // always 'hard'
 *   ]
 *
 * Priority Cascade (SEALED - do not modify):
 *   1. CITY_NOT_OPERATIONAL
 *   2. CITY_INACTIVE
 *   3. HUB_INACTIVE
 *   4. HUB_CLOSED_INDEFINITELY
 *   5. HUB_TEMP_CLOSED
 *   6. HUB_NO_HOURS_SET
 *   7. HUB_OUTSIDE_HOURS
 *   8. HUB_CLOSING_SOON
 *   9. AVAILABLE
 */
function knx_availability_decision($hub_id) {
    global $wpdb;

    // ------------------------------------------------------
    // Local helper: create a contract-compliant response
    // (Anonymous closure to avoid introducing extra functions)
    // ------------------------------------------------------
    $resp = function($can_order, $reason, $message, $reopen_at, $source) {
        return [
            'can_order' => (bool) $can_order,
            'reason'    => (string) $reason,
            'message'   => (string) $message,
            'reopen_at' => $reopen_at === null ? null : (string) $reopen_at,
            'source'    => (string) $source,
            'severity'  => 'hard',
        ];
    };

    // Validate hub_id
    $hub_id = (int) $hub_id;
    if ($hub_id <= 0) {
        return $resp(false, 'HUB_INACTIVE', 'This restaurant is currently unavailable.', null, 'hub');
    }

    $table_hubs   = $wpdb->prefix . 'knx_hubs';
    $table_cities = $wpdb->prefix . 'knx_cities';

    // Fetch hub (minimal required fields only)
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status, city_id, timezone, closure_until, closure_reason,
                hours_monday, hours_tuesday, hours_wednesday, hours_thursday,
                hours_friday, hours_saturday, hours_sunday
         FROM {$table_hubs}
         WHERE id = %d
         LIMIT 1",
        $hub_id
    ));

    if (!$hub) {
        return $resp(false, 'HUB_INACTIVE', 'This restaurant is currently unavailable.', null, 'hub');
    }

    // ------------------------------------------------------
    // Timezone: Hub timezone ONLY. Missing/invalid => fail closed.
    // ------------------------------------------------------
    $timezone_name = !empty($hub->timezone) ? trim((string) $hub->timezone) : '';
    if ($timezone_name === '') {
        return $resp(false, 'HUB_CONFIGURATION_ERROR', 'This restaurant is currently unavailable.', null, 'hub');
    }

    try {
        $tz = new DateTimeZone($timezone_name);
    } catch (Exception $e) {
        return $resp(false, 'HUB_CONFIGURATION_ERROR', 'This restaurant is currently unavailable.', null, 'hub');
    }

    $now = new DateTime('now', $tz);

    // ------------------------------------------------------
    // City: must exist if hub has city_id; missing city => fail closed.
    // ------------------------------------------------------
    $city_id = isset($hub->city_id) ? (int) $hub->city_id : 0;
    if ($city_id <= 0) {
        return $resp(false, 'HUB_CONFIGURATION_ERROR', 'This restaurant is currently unavailable.', null, 'hub');
    }

    $city = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status, is_operational
         FROM {$table_cities}
         WHERE id = %d
         LIMIT 1",
        $city_id
    ));

    if (!$city) {
        // Explicit requirement: city missing => treat as CITY_INACTIVE (fail closed).
        return $resp(false, 'CITY_INACTIVE', 'This area is currently unavailable.', null, 'city');
    }

    // ======================================================
    // PRIORITY 1: CITY_NOT_OPERATIONAL
    // ======================================================
    if (isset($city->is_operational) && (int) $city->is_operational === 0) {
        return $resp(false, 'CITY_NOT_OPERATIONAL', 'Orders are temporarily paused in this city.', null, 'city');
    }

    // ======================================================
    // PRIORITY 2: CITY_INACTIVE
    // ======================================================
    $city_status = isset($city->status) ? (string) $city->status : '';
    if ($city_status !== 'active') {
        return $resp(false, 'CITY_INACTIVE', 'This area is currently unavailable.', null, 'city');
    }

    // ======================================================
    // PRIORITY 3: HUB_INACTIVE
    // ======================================================
    $hub_status = isset($hub->status) ? (string) $hub->status : '';
    if ($hub_status !== 'active') {
        return $resp(false, 'HUB_INACTIVE', 'This restaurant is currently unavailable.', null, 'hub');
    }

    // Normalize closure fields
    $closure_reason = !empty($hub->closure_reason) ? trim((string) $hub->closure_reason) : '';
    $closure_until_raw = !empty($hub->closure_until) ? trim((string) $hub->closure_until) : '';

    // ======================================================
    // PRIORITY 4: HUB_CLOSED_INDEFINITELY
    // closure_reason present + closure_until empty
    // ======================================================
    if ($closure_reason !== '' && $closure_until_raw === '') {
        return $resp(false, 'HUB_CLOSED_INDEFINITELY', 'Temporarily closed.', null, 'hub');
    }

    // ======================================================
    // PRIORITY 5: HUB_TEMP_CLOSED
    // closure_until parseable + now <= closure_until
    // Invalid closure_until => HUB_CONFIGURATION_ERROR (fail closed)
    // ======================================================
    if ($closure_until_raw !== '') {
        try {
            $closure_until_dt = new DateTime($closure_until_raw, $tz);
        } catch (Exception $e) {
            return $resp(false, 'HUB_CONFIGURATION_ERROR', 'This restaurant is currently unavailable.', null, 'hub');
        }

        if ($now <= $closure_until_dt) {
            $reopen_iso = $closure_until_dt->format(DATE_ATOM);
            return $resp(false, 'HUB_TEMP_CLOSED', 'Temporarily closed.', $reopen_iso, 'hub');
        }
    }

    // ======================================================
    // PRIORITY 6-8: HOURS VALIDATION (same-day only)
    // - No overnight support (all intervals same calendar day)
    // - Strict JSON parsing
    // - Accept only intervals with open/close in HH:MM 24h
    // - close < open treated as configuration error
    // ======================================================
    $day = strtolower($now->format('l')); // monday..sunday
    $col = 'hours_' . $day;
    $hours_json = isset($hub->{$col}) ? (string) $hub->{$col} : '';

    $intervals = [];
    if ($hours_json !== '') {
        $decoded = json_decode($hours_json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach ($decoded as $interval) {
                if (!is_array($interval)) continue;

                $open  = isset($interval['open']) ? trim((string) $interval['open']) : '';
                $close = isset($interval['close']) ? trim((string) $interval['close']) : '';

                $open_ok  = (bool) preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $open);
                $close_ok = (bool) preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $close);

                // Reject overnight intervals (close < open)
                if ($open_ok && $close_ok && $close > $open) {
                    $intervals[] = ['open' => $open, 'close' => $close];
                }
            }
        }
    }

    // ======================================================
    // PRIORITY 6: HUB_NO_HOURS_SET
    // ======================================================
    if (empty($intervals)) {
        return $resp(false, 'HUB_NO_HOURS_SET', 'Hours not available today.', null, 'hours');
    }

    // ======================================================
    // PRIORITY 7: HUB_OUTSIDE_HOURS
    // ======================================================
    $current_time = $now->format('H:i');
    $is_open_now = false;
    $active_interval = null;

    foreach ($intervals as $intv) {
        $open_time  = $intv['open'];
        $close_time = $intv['close'];

        // Simple same-day comparison
        if ($current_time >= $open_time && $current_time <= $close_time) {
            $is_open_now = true;
            $active_interval = $intv;
            break;
        }
    }

    if (!$is_open_now) {
        return $resp(false, 'HUB_OUTSIDE_HOURS', 'Closed now.', null, 'hours');
    }

    // ======================================================
    // PRIORITY 8: HUB_CLOSING_SOON (Cutoff Rule)
    // Stop accepting orders 15 minutes before closing time.
    // reopen_at = null (no future scheduling here)
    // ======================================================
    $cutoff_minutes = 15;

    if ($active_interval !== null) {
        $close_time = $active_interval['close'];
        
        try {
            $close_dt = new DateTime($now->format('Y-m-d') . ' ' . $close_time, $tz);
            $last_order_dt = clone $close_dt;
            $last_order_dt->modify("-{$cutoff_minutes} minutes");
            
            if ($now >= $last_order_dt) {
                return $resp(false, 'HUB_CLOSING_SOON', 'This restaurant is closing soon and is no longer accepting orders.', null, 'hours');
            }
        } catch (Exception $e) {
            return $resp(false, 'HUB_CONFIGURATION_ERROR', 'This restaurant is currently unavailable.', null, 'hub');
        }
    }

    // ======================================================
    // PRIORITY 9: AVAILABLE
    // ======================================================
    return $resp(true, 'AVAILABLE', 'Available for orders.', null, 'hub');
}
