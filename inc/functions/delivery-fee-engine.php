<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Delivery Fee Engine (SSOT)
 * Phase 4.5: KNX-A4.5 Delivery Fee Calculator
 * ==========================================================
 * Purpose:
 *   Calculate delivery fees based on configurable rules.
 *   Rules priority: zone > hub > city
 *   Supports: flat, distance-based, subtotal-based, tiered
 *   Fail-closed: Returns error if no rule found.
 * 
 * Functions:
 *   knx_calculate_delivery_fee($hub_id, $distance_km, $subtotal, $zone_id)
 *   knx_get_delivery_fee_rule($hub_id, $zone_id, $city_id)
 *   knx_apply_fee_rule($rule, $distance_km, $subtotal)
 * 
 * Returns:
 *   ['ok' => bool, 'fee' => float, 'rule_id' => int, 'rule_name' => string, 'reason' => string, 'is_free' => bool]
 * 
 * Reason codes:
 *   - CALCULATED: Fee successfully calculated
 *   - FREE_DELIVERY_SUBTOTAL: Free delivery (subtotal threshold met)
 *   - FREE_DELIVERY_DISTANCE: Free delivery (within free distance)
 *   - MAX_DISTANCE_EXCEEDED: Beyond deliverable distance
 *   - NO_RULE_FOUND: No delivery fee rule configured
 *   - INVALID_HUB_ID: Hub ID is invalid
 *   - INVALID_INPUTS: Distance or subtotal is invalid
 * ==========================================================
 */

/**
 * Calculate delivery fee for an order
 * 
 * @param int $hub_id Hub ID
 * @param float $distance_km Distance in kilometers
 * @param float $subtotal Order subtotal
 * @param int|null $zone_id Delivery zone ID (optional)
 * @return array Fee calculation result
 */
function knx_calculate_delivery_fee($hub_id, $distance_km, $subtotal, $zone_id = null) {
    // ----------------------------------------
    // 1) Input validation
    // ----------------------------------------
    $hub_id = (int) $hub_id;
    $distance_km = (float) $distance_km;
    $subtotal = (float) $subtotal;
    $zone_id = $zone_id ? (int) $zone_id : null;
    
    if ($hub_id <= 0) {
        return [
            'ok' => false,
            'fee' => 0,
            'rule_id' => null,
            'rule_name' => null,
            'reason' => 'INVALID_HUB_ID',
            'is_free' => false
        ];
    }
    
    if ($distance_km < 0 || $subtotal < 0) {
        return [
            'ok' => false,
            'fee' => 0,
            'rule_id' => null,
            'rule_name' => null,
            'reason' => 'INVALID_INPUTS',
            'is_free' => false
        ];
    }
    
    // ----------------------------------------
    // 2) Get applicable delivery fee rule
    // ----------------------------------------
    $rule = knx_get_delivery_fee_rule($hub_id, $zone_id);
    
    if (!$rule) {
        return [
            'ok' => false,
            'fee' => 0,
            'rule_id' => null,
            'rule_name' => null,
            'reason' => 'NO_RULE_FOUND',
            'is_free' => false
        ];
    }
    
    // ----------------------------------------
    // 3) Check max distance constraint
    // ----------------------------------------
    $max_distance = isset($rule->max_distance_km) ? (float) $rule->max_distance_km : null;
    if ($max_distance && $distance_km > $max_distance) {
        return [
            'ok' => false,
            'fee' => 0,
            'rule_id' => (int) $rule->id,
            'rule_name' => $rule->rule_name,
            'reason' => 'MAX_DISTANCE_EXCEEDED',
            'is_free' => false
        ];
    }
    
    // ----------------------------------------
    // 4) Apply fee calculation rule
    // ----------------------------------------
    return knx_apply_fee_rule($rule, $distance_km, $subtotal);
}

/**
 * Get applicable delivery fee rule (priority: zone > hub > city)
 * 
 * @param int $hub_id Hub ID
 * @param int|null $zone_id Zone ID
 * @param int|null $city_id City ID (optional, can be fetched from hub)
 * @return object|null Rule object or null
 */
function knx_get_delivery_fee_rule($hub_id, $zone_id = null, $city_id = null) {
    global $wpdb;
    
    $table_rules = $wpdb->prefix . 'knx_delivery_fee_rules';
    $hub_id = (int) $hub_id;
    $zone_id = $zone_id ? (int) $zone_id : null;
    
    // Build priority query: zone > hub > city
    $where_clauses = [];
    $params = [];
    
    if ($zone_id) {
        $where_clauses[] = "(zone_id = %d AND is_active = 1)";
        $params[] = $zone_id;
    }
    
    $where_clauses[] = "(hub_id = %d AND is_active = 1)";
    $params[] = $hub_id;
    
    if ($city_id) {
        $where_clauses[] = "(city_id = %d AND is_active = 1)";
        $params[] = $city_id;
    }
    
    $where_sql = implode(' OR ', $where_clauses);
    
    $rule = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_rules}
         WHERE {$where_sql}
         ORDER BY 
           CASE 
             WHEN zone_id IS NOT NULL THEN 3
             WHEN hub_id IS NOT NULL THEN 2
             WHEN city_id IS NOT NULL THEN 1
             ELSE 0
           END DESC,
           priority DESC,
           id DESC
         LIMIT 1",
        ...$params
    ));
    
    return $rule;
}

/**
 * Apply fee calculation rule based on rule type
 * 
 * @param object $rule Fee rule object
 * @param float $distance_km Distance in kilometers
 * @param float $subtotal Order subtotal
 * @return array Fee calculation result
 */
function knx_apply_fee_rule($rule, $distance_km, $subtotal) {
    $fee_type = isset($rule->fee_type) ? $rule->fee_type : 'flat';
    $calculated_fee = 0;
    $is_free = false;
    $reason = 'CALCULATED';
    
    // ----------------------------------------
    // Check free delivery conditions
    // ----------------------------------------
    $min_subtotal_free = isset($rule->min_subtotal_free_delivery) ? (float) $rule->min_subtotal_free_delivery : null;
    $free_distance = isset($rule->free_delivery_distance) ? (float) $rule->free_delivery_distance : null;
    
    if ($min_subtotal_free && $subtotal >= $min_subtotal_free) {
        return [
            'ok' => true,
            'fee' => 0,
            'rule_id' => (int) $rule->id,
            'rule_name' => $rule->rule_name,
            'reason' => 'FREE_DELIVERY_SUBTOTAL',
            'is_free' => true
        ];
    }
    
    if ($free_distance && $distance_km <= $free_distance) {
        return [
            'ok' => true,
            'fee' => 0,
            'rule_id' => (int) $rule->id,
            'rule_name' => $rule->rule_name,
            'reason' => 'FREE_DELIVERY_DISTANCE',
            'is_free' => true
        ];
    }
    
    // ----------------------------------------
    // Calculate fee based on type
    // ----------------------------------------
    switch ($fee_type) {
        case 'flat':
            $calculated_fee = isset($rule->flat_fee) ? (float) $rule->flat_fee : 0;
            break;
            
        case 'distance_based':
            $base_fee = isset($rule->base_fee) ? (float) $rule->base_fee : 0;
            $per_km_rate = isset($rule->per_km_rate) ? (float) $rule->per_km_rate : 0;
            $calculated_fee = $base_fee + ($distance_km * $per_km_rate);
            break;
            
        case 'subtotal_based':
            $percentage = isset($rule->subtotal_percentage) ? (float) $rule->subtotal_percentage : 0;
            $calculated_fee = ($subtotal * $percentage) / 100;
            break;
            
        case 'tiered':
            // Tiered pricing (future implementation)
            // For now, fallback to distance_based
            $base_fee = isset($rule->base_fee) ? (float) $rule->base_fee : 0;
            $per_km_rate = isset($rule->per_km_rate) ? (float) $rule->per_km_rate : 0;
            $calculated_fee = $base_fee + ($distance_km * $per_km_rate);
            break;
            
        default:
            $calculated_fee = 0;
    }
    
    // ----------------------------------------
    // Apply min/max constraints
    // ----------------------------------------
    $min_fee = isset($rule->min_fee) ? (float) $rule->min_fee : null;
    $max_fee = isset($rule->max_fee) ? (float) $rule->max_fee : null;
    
    if ($min_fee && $calculated_fee < $min_fee) {
        $calculated_fee = $min_fee;
    }
    
    if ($max_fee && $calculated_fee > $max_fee) {
        $calculated_fee = $max_fee;
    }
    
    return [
        'ok' => true,
        'fee' => round($calculated_fee, 2),
        'rule_id' => (int) $rule->id,
        'rule_name' => $rule->rule_name,
        'reason' => $reason,
        'is_free' => false
    ];
}

/**
 * Admin helper: Save delivery fee rule
 * 
 * @param array $data Rule data
 * @return int|false Rule ID on success, false on failure
 */
function knx_save_delivery_fee_rule($data) {
    global $wpdb;
    
    $table_rules = $wpdb->prefix . 'knx_delivery_fee_rules';
    $rule_id = isset($data['id']) ? (int) $data['id'] : 0;
    
    $rule_data = [
        'hub_id' => isset($data['hub_id']) ? (int) $data['hub_id'] : null,
        'city_id' => isset($data['city_id']) ? (int) $data['city_id'] : null,
        'zone_id' => isset($data['zone_id']) ? (int) $data['zone_id'] : null,
        'rule_name' => isset($data['rule_name']) ? sanitize_text_field($data['rule_name']) : '',
        'fee_type' => isset($data['fee_type']) ? $data['fee_type'] : 'distance_based',
        'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        'priority' => isset($data['priority']) ? (int) $data['priority'] : 0,
        'flat_fee' => isset($data['flat_fee']) ? (float) $data['flat_fee'] : null,
        'base_fee' => isset($data['base_fee']) ? (float) $data['base_fee'] : null,
        'per_km_rate' => isset($data['per_km_rate']) ? (float) $data['per_km_rate'] : null,
        'min_fee' => isset($data['min_fee']) ? (float) $data['min_fee'] : null,
        'max_fee' => isset($data['max_fee']) ? (float) $data['max_fee'] : null,
        'max_distance_km' => isset($data['max_distance_km']) ? (float) $data['max_distance_km'] : null,
        'min_subtotal_free_delivery' => isset($data['min_subtotal_free_delivery']) ? (float) $data['min_subtotal_free_delivery'] : null,
    ];
    
    // Validation
    if (empty($rule_data['rule_name'])) {
        return false;
    }
    
    $format = ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%f', '%f'];
    
    if ($rule_id > 0) {
        // Update existing rule
        $wpdb->update($table_rules, $rule_data, ['id' => $rule_id], $format, ['%d']);
        return $rule_id;
    } else {
        // Create new rule
        $wpdb->insert($table_rules, $rule_data, $format);
        return $wpdb->insert_id;
    }
}

/**
 * Admin helper: Delete delivery fee rule
 * 
 * @param int $rule_id Rule ID
 * @return bool Success
 */
function knx_delete_delivery_fee_rule($rule_id) {
    global $wpdb;
    
    $table_rules = $wpdb->prefix . 'knx_delivery_fee_rules';
    $rule_id = (int) $rule_id;
    
    if ($rule_id <= 0) {
        return false;
    }
    
    $deleted = $wpdb->delete($table_rules, ['id' => $rule_id], ['%d']);
    
    return $deleted !== false;
}
