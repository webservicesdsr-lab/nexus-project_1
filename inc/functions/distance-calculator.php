<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Distance Calculator (SSOT)
 * Phase 4.4: KNX-A4.4 Distance Hub → Customer
 * ==========================================================
 * Purpose:
 *   Calculate delivery distance using Haversine formula.
 *   Server-side only, deterministic, NO frontend trust.
 *   Base for delivery fees and ETA estimation.
 * 
 * Functions:
 *   knx_calculate_delivery_distance($hub_id, $customer_lat, $customer_lng)
 *   knx_haversine_distance($lat1, $lng1, $lat2, $lng2, $unit)
 *   knx_estimate_delivery_eta($distance_km, $traffic_factor)
 * 
 * Returns:
 *   ['ok' => bool, 'distance_km' => float, 'distance_mi' => float, 'eta_minutes' => int, 'reason' => string]
 * 
 * Reason codes:
 *   - CALCULATED: Distance successfully calculated
 *   - HUB_NOT_FOUND: Hub does not exist
 *   - MISSING_HUB_COORDS: Hub has no latitude/longitude
 *   - MISSING_CUSTOMER_COORDS: Customer coordinates missing
 *   - INVALID_HUB_ID: Hub ID is invalid
 * ==========================================================
 */

/**
 * Calculate delivery distance from hub to customer
 * 
 * @param int $hub_id Hub ID
 * @param float $customer_lat Customer latitude
 * @param float $customer_lng Customer longitude
 * @return array ['ok' => bool, 'distance_km' => float, 'distance_mi' => float, 'eta_minutes' => int, 'reason' => string]
 */
function knx_calculate_delivery_distance($hub_id, $customer_lat, $customer_lng) {
    global $wpdb;
    
    // ----------------------------------------
    // 1) Input validation
    // ----------------------------------------
    $hub_id = (int) $hub_id;
    $customer_lat = (float) $customer_lat;
    $customer_lng = (float) $customer_lng;
    
    if ($hub_id <= 0) {
        return [
            'ok' => false,
            'distance_km' => 0,
            'distance_mi' => 0,
            'eta_minutes' => 0,
            'reason' => 'INVALID_HUB_ID'
        ];
    }
    
    if ($customer_lat == 0.0 || $customer_lng == 0.0) {
        return [
            'ok' => false,
            'distance_km' => 0,
            'distance_mi' => 0,
            'eta_minutes' => 0,
            'reason' => 'MISSING_CUSTOMER_COORDS'
        ];
    }
    
    // ----------------------------------------
    // 2) Get hub coordinates
    // ----------------------------------------
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, latitude, longitude FROM {$table_hubs} WHERE id = %d",
        $hub_id
    ));
    
    if (!$hub) {
        return [
            'ok' => false,
            'distance_km' => 0,
            'distance_mi' => 0,
            'eta_minutes' => 0,
            'reason' => 'HUB_NOT_FOUND'
        ];
    }
    
    $hub_lat = isset($hub->latitude) ? (float) $hub->latitude : 0.0;
    $hub_lng = isset($hub->longitude) ? (float) $hub->longitude : 0.0;
    
    if ($hub_lat == 0.0 || $hub_lng == 0.0) {
        return [
            'ok' => false,
            'distance_km' => 0,
            'distance_mi' => 0,
            'eta_minutes' => 0,
            'reason' => 'MISSING_HUB_COORDS'
        ];
    }
    
    // ----------------------------------------
    // 3) Calculate distance using Haversine
    // ----------------------------------------
    $distance_km = knx_haversine_distance($hub_lat, $hub_lng, $customer_lat, $customer_lng, 'km');
    $distance_mi = knx_haversine_distance($hub_lat, $hub_lng, $customer_lat, $customer_lng, 'mi');
    
    // ----------------------------------------
    // 4) Estimate ETA
    // ----------------------------------------
    $eta_minutes = knx_estimate_delivery_eta($distance_km);
    
    return [
        'ok' => true,
        'distance_km' => round($distance_km, 2),
        'distance_mi' => round($distance_mi, 2),
        'eta_minutes' => $eta_minutes,
        'reason' => 'CALCULATED'
    ];
}

/**
 * Haversine formula: Calculate distance between two coordinates
 * 
 * @param float $lat1 Starting latitude
 * @param float $lng1 Starting longitude
 * @param float $lat2 Ending latitude
 * @param float $lng2 Ending longitude
 * @param string $unit 'km' (kilometers) or 'mi' (miles)
 * @return float Distance in specified unit
 */
function knx_haversine_distance($lat1, $lng1, $lat2, $lng2, $unit = 'km') {
    $earth_radius_km = 6371; // Earth's radius in kilometers
    $earth_radius_mi = 3959; // Earth's radius in miles
    
    // Convert degrees to radians
    $lat1_rad = deg2rad($lat1);
    $lng1_rad = deg2rad($lng1);
    $lat2_rad = deg2rad($lat2);
    $lng2_rad = deg2rad($lng2);
    
    // Haversine formula
    $delta_lat = $lat2_rad - $lat1_rad;
    $delta_lng = $lng2_rad - $lng1_rad;
    
    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
         cos($lat1_rad) * cos($lat2_rad) *
         sin($delta_lng / 2) * sin($delta_lng / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    if ($unit === 'mi') {
        return $earth_radius_mi * $c;
    }
    
    return $earth_radius_km * $c;
}

/**
 * Estimate delivery ETA based on distance
 * 
 * @param float $distance_km Distance in kilometers
 * @param float $traffic_factor Traffic multiplier (1.0 = normal, 1.5 = heavy traffic)
 * @return int Estimated time in minutes
 */
function knx_estimate_delivery_eta($distance_km, $traffic_factor = 1.2) {
    // Base assumptions:
    // - Preparation time: 15-20 minutes
    // - Average delivery speed: 30 km/h in city
    // - Traffic factor: 1.2x (20% slower due to stops/lights)
    
    $prep_time_minutes = 15;
    $average_speed_kmh = 30;
    $travel_time_minutes = ($distance_km / $average_speed_kmh) * 60 * $traffic_factor;
    
    $total_minutes = $prep_time_minutes + $travel_time_minutes;
    
    // Round to nearest 5 minutes for better UX
    return (int) (ceil($total_minutes / 5) * 5);
}

/**
 * Get distance between two addresses (helper for admin/reporting)
 * 
 * @param int $address_id_1 First address ID
 * @param int $address_id_2 Second address ID
 * @return array|false Distance data or false on error
 */
function knx_get_distance_between_addresses($address_id_1, $address_id_2) {
    global $wpdb;
    
    $table_addresses = $wpdb->prefix . 'knx_addresses';
    
    $addr1 = $wpdb->get_row($wpdb->prepare(
        "SELECT latitude, longitude FROM {$table_addresses} WHERE id = %d",
        (int) $address_id_1
    ));
    
    $addr2 = $wpdb->get_row($wpdb->prepare(
        "SELECT latitude, longitude FROM {$table_addresses} WHERE id = %d",
        (int) $address_id_2
    ));
    
    if (!$addr1 || !$addr2) {
        return false;
    }
    
    $lat1 = (float) $addr1->latitude;
    $lng1 = (float) $addr1->longitude;
    $lat2 = (float) $addr2->latitude;
    $lng2 = (float) $addr2->longitude;
    
    if ($lat1 == 0.0 || $lng1 == 0.0 || $lat2 == 0.0 || $lng2 == 0.0) {
        return false;
    }
    
    return [
        'distance_km' => round(knx_haversine_distance($lat1, $lng1, $lat2, $lng2, 'km'), 2),
        'distance_mi' => round(knx_haversine_distance($lat1, $lng1, $lat2, $lng2, 'mi'), 2),
    ];
}
