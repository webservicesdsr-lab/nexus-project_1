<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Coverage Engine (SSOT)
 * Phase 4.3: KNX-A4.3 Polygon Coverage Authority
 * ==========================================================
 * Purpose:
 *   Server-side point-in-polygon validation.
 *   Determines if a customer address is deliverable by a hub.
 *   NO frontend trust. Backend is SSOT for coverage decisions.
 * 
 * Functions:
 *   knx_check_coverage($hub_id, $lat, $lng)
 *   knx_point_in_polygon($point, $polygon)
 *   knx_get_delivery_zones($hub_id, $active_only)
 * 
 * Returns:
 *   ['ok' => bool, 'zone_id' => int|null, 'reason' => string]
 * 
 * Reason codes:
 *   - DELIVERABLE: Address is within coverage
 *   - OUT_OF_COVERAGE: Not in any active zone
 *   - NO_ACTIVE_ZONE: Hub has no active zones
 *   - DELIVERY_DISABLED: Zone exists but is_active=0
 *   - ZONE_NOT_POLYGON: Zone type is not polygon (radius not implemented yet)
 *   - INVALID_POLYGON: Polygon GeoJSON is malformed
 *   - INVALID_JSON: Cannot parse GeoJSON
 *   - MISSING_POLYGON: Zone has no polygon data
 *   - HUB_NOT_FOUND: Hub does not exist
 *   - INVALID_HUB_ID: Hub ID is invalid
 *   - MISSING_COORDS: Latitude or longitude is missing/zero
 * ==========================================================
 */

/**
 * Check if a coordinate is within delivery coverage for a hub
 * 
 * @param int $hub_id Hub ID
 * @param float $lat Customer latitude
 * @param float $lng Customer longitude
 * @return array ['ok' => bool, 'zone_id' => int|null, 'reason' => string, 'zone_name' => string|null]
 */
function knx_check_coverage($hub_id, $lat, $lng) {
    global $wpdb;
    
    // ----------------------------------------
    // 1) Input validation
    // ----------------------------------------
    $hub_id = (int) $hub_id;
    $lat = (float) $lat;
    $lng = (float) $lng;
    
    // Log DB info once per session for troubleshooting
    static $db_logged = false;
    if (!$db_logged) {
        error_log('[KNX-COVERAGE][DBINFO] DB_NAME=' . (defined('DB_NAME') ? DB_NAME : 'undef') . ' wpdb_dbname=' . ($wpdb->dbname ?? 'na') . ' prefix=' . $wpdb->prefix);
        $db_logged = true;
    }
    
    if ($hub_id <= 0) {
        return [
            'ok' => false,
            'zone_id' => null,
            'reason' => 'INVALID_HUB_ID',
            'zone_name' => null
        ];
    }
    
    if ($lat == 0.0 || $lng == 0.0) {
        return [
            'ok' => false,
            'zone_id' => null,
            'reason' => 'MISSING_COORDS',
            'zone_name' => null
        ];
    }
    
    // ----------------------------------------
    // 2) Verify hub exists and get fallback data
    // ----------------------------------------
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, latitude, longitude, delivery_radius, delivery_zone_type
         FROM {$table_hubs}
         WHERE id = %d
         LIMIT 1",
        $hub_id
    ));
    
    if (!$hub) {
        return [
            'ok' => false,
            'zone_id' => null,
            'reason' => 'HUB_NOT_FOUND',
            'zone_name' => null
        ];
    }
    
    // ----------------------------------------
    // 3) Fetch active delivery zones (priority order)
    // ----------------------------------------
    $zones = knx_get_delivery_zones($hub_id, true);
    
    error_log("[KNX-COVERAGE] Hub {$hub_id}: Found " . count($zones) . " zones");
    error_log("[KNX-COVERAGE] Hub radius: {$hub->delivery_radius}, has coords: " . (!empty($hub->latitude) ? 'yes' : 'no'));
    
    if (empty($zones)) {
        error_log("[KNX-COVERAGE] No zones found, checking radius fallback...");
        // No zones configured - use hub radius fallback
        // Only if hub has coordinates and radius > 0
        if (!empty($hub->latitude) && !empty($hub->longitude) && $hub->delivery_radius > 0) {
            error_log("[KNX-COVERAGE] Calculating distance with Haversine...");
            // Use knx_haversine_distance from distance-calculator.php (returns km or mi)
            $distance_km = knx_haversine_distance($lat, $lng, $hub->latitude, $hub->longitude, 'km');
            $distance_mi = knx_haversine_distance($lat, $lng, $hub->latitude, $hub->longitude, 'mi');
            
            error_log("[KNX-COVERAGE] Distance: {$distance_mi} mi, Radius: {$hub->delivery_radius} mi");
            
            if ($distance_mi <= $hub->delivery_radius) {
                error_log("[KNX-COVERAGE] ✓ Within radius! RADIUS_FALLBACK");
                return [
                    'ok' => true,
                    'zone_id' => null,
                    'reason' => 'RADIUS_FALLBACK',
                    'zone_name' => 'Hub Radius',
                    'distance_mi' => round($distance_mi, 2),
                    'radius_mi' => (float) $hub->delivery_radius
                ];
            } else {
                error_log("[KNX-COVERAGE] ✗ Out of radius! OUT_OF_RADIUS");
                return [
                    'ok' => false,
                    'zone_id' => null,
                    'reason' => 'OUT_OF_RADIUS',
                    'zone_name' => null,
                    'distance_mi' => round($distance_mi, 2),
                    'radius_mi' => (float) $hub->delivery_radius
                ];
            }
        }
        
        error_log("[KNX-COVERAGE] No radius fallback available - NO_ACTIVE_ZONE");
        return [
            'ok' => false,
            'zone_id' => null,
            'reason' => 'NO_ACTIVE_ZONE',
            'zone_name' => null
        ];
    }
    
    // ----------------------------------------
    // 4) Check each zone (highest priority first)
    // ----------------------------------------
    $has_valid_zones = false;
    
    foreach ($zones as $zone) {
        $zone_type = isset($zone->zone_type) ? $zone->zone_type : 'polygon';
        
        // Skip disabled zones (should be filtered by query, but defensive)
        if (empty($zone->is_active)) {
            continue;
        }
        
        // Handle polygon zones
        if ($zone_type === 'polygon') {
            // Two possible sources: `polygon_geojson` (preferred) or `polygon_points` (legacy)
            $polygon_source = '';
            $decoded = null;

            if (!empty($zone->polygon_geojson)) {
                $polygon_source = 'geojson';
                $polygon_source_raw = $zone->polygon_geojson;
            } elseif (!empty($zone->polygon_points)) {
                $polygon_source = 'points';
                $polygon_source_raw = $zone->polygon_points;
            } else {
                error_log("[KNX-COVERAGE] Zone {$zone->id} skipped: missing polygon data");
                continue; // Skip zones without polygon data
            }

            // Decode JSON
            $decoded = json_decode($polygon_source_raw);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                error_log("[KNX-COVERAGE] Zone {$zone->id} skipped: invalid JSON ({$polygon_source})");
                continue;
            }

            // Use internal parser which normalizes both GeoJSON and legacy array formats
            $polygon = knx_coverage_parse_polygon_internal($decoded);

            if (!$polygon) {
                // Get parser error and log a helpful message
                $err = knx_coverage_get_last_error_internal();
                $msg = $err ? $err : 'INVALID_POLYGON';
                error_log("[KNX-COVERAGE] Zone {$zone->id} skipped: {$msg}");
                continue;
            }

            // Parser returned normalized points (array of objects {lat,lng})
            if (count($polygon) < 3) {
                error_log("[KNX-COVERAGE] Zone {$zone->id} skipped: < 3 points");
                continue;
            }

            // We have at least one valid polygon zone
            $has_valid_zones = true;

            // Point-in-polygon check using internal ray-casting implementation
            if (knx_within_area_internal((object)['lat' => $lat, 'lng' => $lng], $polygon, count($polygon))) {
                return [
                    'ok' => true,
                    'zone_id' => (int) $zone->id,
                    'reason' => 'DELIVERABLE',
                    'zone_name' => isset($zone->zone_name) ? $zone->zone_name : null
                ];
            }
        }
        
        // Handle radius zones (future implementation)
        if ($zone_type === 'radius') {
            // TODO: Implement radius check using Haversine formula
            // For now, skip radius zones
            continue;
        }
    }
    
    // ----------------------------------------
    // 5) No valid zones matched - try radius fallback
    // ----------------------------------------
    if (!$has_valid_zones) {
        // No valid polygon zones found - use radius fallback
        if (!empty($hub->latitude) && !empty($hub->longitude) && $hub->delivery_radius > 0) {
            $distance_km = knx_haversine_distance($lat, $lng, $hub->latitude, $hub->longitude, 'km');
            $distance_mi = knx_haversine_distance($lat, $lng, $hub->latitude, $hub->longitude, 'mi');
            
            if ($distance_mi <= $hub->delivery_radius) {
                return [
                    'ok' => true,
                    'zone_id' => null,
                    'reason' => 'RADIUS_FALLBACK',
                    'zone_name' => 'Hub Radius',
                    'distance_mi' => round($distance_mi, 2),
                    'radius_mi' => (float) $hub->delivery_radius
                ];
            } else {
                return [
                    'ok' => false,
                    'zone_id' => null,
                    'reason' => 'OUT_OF_RADIUS',
                    'zone_name' => null,
                    'distance_mi' => round($distance_mi, 2),
                    'radius_mi' => (float) $hub->delivery_radius
                ];
            }
        }
    }
    
    return [
        'ok' => false,
        'zone_id' => null,
        'reason' => 'OUT_OF_COVERAGE',
        'zone_name' => null
    ];
}

/**
 * Ray-casting algorithm for point-in-polygon test
 * 
 * @param array $point ['lat' => float, 'lng' => float]
 * @param array $polygon Array of [lng, lat] coordinate pairs
 * @return bool True if point is inside polygon
 */
function knx_point_in_polygon($point, $polygon) {
    $lat = $point['lat'];
    $lng = $point['lng'];
    
    $inside = false;
    $num_vertices = count($polygon);
    
    for ($i = 0, $j = $num_vertices - 1; $i < $num_vertices; $j = $i++) {
        // Polygon vertices: [lng, lat] (GeoJSON format)
        $lng_i = (float) $polygon[$i][0];
        $lat_i = (float) $polygon[$i][1];
        $lng_j = (float) $polygon[$j][0];
        $lat_j = (float) $polygon[$j][1];
        
        // Ray-casting algorithm
        if ((($lat_i > $lat) != ($lat_j > $lat)) &&
            ($lng < ($lng_j - $lng_i) * ($lat - $lat_i) / ($lat_j - $lat_i) + $lng_i)) {
            $inside = !$inside;
        }
    }
    
    return $inside;
}

/**
 * Get delivery zones for a hub
 * 
 * @param int $hub_id Hub ID
 * @param bool $active_only Only return active zones
 * @return array Array of zone objects
 */
function knx_get_delivery_zones($hub_id, $active_only = true) {
    global $wpdb;
    
    $table_zones = $wpdb->prefix . 'knx_delivery_zones';
    $hub_id = (int) $hub_id;
    
    // Debug logging for troubleshooting
    error_log('[KNX-COVERAGE][DB] zones_table=' . $table_zones . ' hub_id=' . $hub_id . ' active_only=' . ($active_only ? '1' : '0'));
    
    $where = "hub_id = %d";
    $params = [$hub_id];
    
    if ($active_only) {
        $where .= " AND is_active = 1";
    }
    
    $sql = $wpdb->prepare(
        "SELECT * FROM {$table_zones}
         WHERE {$where}
         ORDER BY id ASC",
        ...$params
    );
    
    error_log('[KNX-COVERAGE][DB] zones_sql=' . $sql);
    
    $zones = $wpdb->get_results($sql);
    
    error_log('[KNX-COVERAGE][DB] zones_rows=' . (is_array($zones) ? count($zones) : 0));
    if (!empty($zones) && isset($zones[0])) {
        error_log('[KNX-COVERAGE][DB] zones_first_row_cols=' . implode(',', array_keys(get_object_vars($zones[0]))));
    }
    
    return $zones ? $zones : [];
}

/**
 * Admin helper: Create or update a delivery zone
 * 
 * @param array $data Zone data
 * @return int|false Zone ID on success, false on failure
 */
function knx_save_delivery_zone($data) {
    global $wpdb;
    
    $table_zones = $wpdb->prefix . 'knx_delivery_zones';
    $zone_id = isset($data['id']) ? (int) $data['id'] : 0;
    
    $zone_data = [
        'hub_id' => isset($data['hub_id']) ? (int) $data['hub_id'] : 0,
        'zone_name' => isset($data['zone_name']) ? sanitize_text_field($data['zone_name']) : '',
        'zone_type' => isset($data['zone_type']) ? $data['zone_type'] : 'polygon',
        'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        'polygon_geojson' => isset($data['polygon_geojson']) ? $data['polygon_geojson'] : null,
        'priority' => isset($data['priority']) ? (int) $data['priority'] : 0,
    ];
    
    // Validation
    if ($zone_data['hub_id'] <= 0 || empty($zone_data['zone_name'])) {
        return false;
    }
    
    if ($zone_id > 0) {
        // Update existing zone
        $wpdb->update(
            $table_zones,
            $zone_data,
            ['id' => $zone_id],
            ['%d', '%s', '%s', '%d', '%s', '%d'],
            ['%d']
        );
        return $zone_id;
    } else {
        // Create new zone
        $wpdb->insert(
            $table_zones,
            $zone_data,
            ['%d', '%s', '%s', '%d', '%s', '%d']
        );
        return $wpdb->insert_id;
    }
}

/**
 * Admin helper: Delete a delivery zone
 * 
 * @param int $zone_id Zone ID
 * @return bool Success
 */
function knx_delete_delivery_zone($zone_id) {
    global $wpdb;
    
    $table_zones = $wpdb->prefix . 'knx_delivery_zones';
    $zone_id = (int) $zone_id;
    
    if ($zone_id <= 0) {
        return false;
    }
    
    $deleted = $wpdb->delete(
        $table_zones,
        ['id' => $zone_id],
        ['%d']
    );
    
    return $deleted !== false;
}
