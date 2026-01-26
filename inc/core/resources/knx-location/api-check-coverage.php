<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Check Delivery Coverage API (Canonical)
 * REST Endpoint: /wp-json/knx/v1/check-coverage
 * Method: POST
 * Payload: {lat: float, lng: float}
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/check-coverage', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_check_coverage'),
        'permission_callback' => '__return_true'
    ]);
});

function knx_api_check_coverage($request) {
    global $wpdb;
    
    // Rate limiting
    $ip = $_SERVER['REMOTE_ADDR'];
    $transient_key = 'knx_geo_limit_' . md5($ip);
    $attempts = get_transient($transient_key) ?: 0;
    
    if ($attempts > 20) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'rate_limit',
            'message' => 'Too many requests. Please try again in 1 hour.'
        ], 429);
    }
    
    set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
    
    // Get user location
    $lat = floatval($request->get_param('lat'));
    $lng = floatval($request->get_param('lng'));
    
    if (!$lat || !$lng) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'invalid_coords',
            'message' => 'Invalid coordinates provided.'
        ], 400);
    }
    
    // Get all active hubs
    $hubs_table = $wpdb->prefix . 'knx_hubs';
    $zones_table = $wpdb->prefix . 'knx_delivery_zones';
    
    $hubs = $wpdb->get_results($wpdb->prepare(
        "SELECT h.id, h.name, h.address, h.latitude as lat, h.longitude as lng,
                h.delivery_zone_type, h.delivery_radius,
                GROUP_CONCAT(z.id) as zone_ids,
                GROUP_CONCAT(z.polygon_points) as zone_polygons
         FROM {$hubs_table} h
         LEFT JOIN {$zones_table} z ON h.id = z.hub_id AND z.is_active = 1
         WHERE h.status = %s
         GROUP BY h.id",
        'active'
    ));
    
    if (!$hubs) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'no_hubs',
            'message' => 'No active hubs found.'
        ], 404);
    }
    
    $matches = [];
    $nearby_hubs = [];
    
    foreach ($hubs as $hub) {
        // Calculate distance to hub center
        $distance = knx_calculate_distance($lat, $lng, floatval($hub->lat), floatval($hub->lng));
        
        // Generate proper slug from name
        $slug = knx_slugify_hub_name($hub->name, $hub->id);
        
        $hub_data = [
            'id' => intval($hub->id),
            'name' => $hub->name,
            'slug' => $slug,
            'address' => $hub->address,
            'lat' => floatval($hub->lat),
            'lng' => floatval($hub->lng),
            'distance' => round($distance, 2)
        ];
        
        // Check if user is within delivery zone
        $is_covered = false;
        
        if ($hub->delivery_zone_type === 'polygon' && $hub->zone_polygons) {
            // Get first polygon (we can enhance this later for multiple zones)
            $polygons = explode(',', $hub->zone_polygons);
            foreach ($polygons as $polygon_json) {
                $polygon = json_decode($polygon_json, true);
                if ($polygon && knx_api_point_in_polygon_internal($lat, $lng, $polygon)) {
                    $is_covered = true;
                    break;
                }
            }
        } elseif ($hub->delivery_zone_type === 'radius') {
            $radius_miles = floatval($hub->delivery_radius ?: 3);
            if ($distance <= $radius_miles) {
                $is_covered = true;
            }
        }
        
        if ($is_covered) {
            $matches[] = $hub_data;
        } elseif ($distance <= 80) {
            // Within 80 miles - add to nearby
            $nearby_hubs[] = $hub_data;
        }
    }
    
    // If multiple matches, return the closest one
    if (count($matches) > 0) {
        usort($matches, function($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        
        return new WP_REST_Response([
            'success' => true,
            'found' => true,
            'hub' => $matches[0],
            'alternatives' => array_slice($matches, 1, 2) // Show up to 2 alternatives
        ], 200);
    }
    
    // No coverage, return nearby hubs
    usort($nearby_hubs, function($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });
    
    return new WP_REST_Response([
        'success' => true,
        'found' => false,
        'nearby_hubs' => array_slice($nearby_hubs, 0, 5), // Top 5 nearest
        'message' => count($nearby_hubs) > 0 
            ? 'No coverage at your location, but we found nearby hubs.' 
            : 'No hubs found within 80 miles of your location.'
    ], 200);
}

/**
 * Local point-in-polygon helper for this endpoint only.
 * 
 * @internal This is endpoint-specific logic for discovery UX.
 * For canonical coverage validation, use knx_check_coverage() from coverage-engine.php.
 * 
 * TODO: Refactor to use coverage-engine.php instead of inline implementation.
 */
function knx_api_point_in_polygon_internal($lat, $lng, $polygon) {
    if (!is_array($polygon) || count($polygon) < 3) {
        return false;
    }
    
    $j = count($polygon) - 1;
    $oddNodes = false;
    
    for ($i = 0; $i < count($polygon); $i++) {
        $vi_lat = floatval($polygon[$i]['lat']);
        $vi_lng = floatval($polygon[$i]['lng']);
        $vj_lat = floatval($polygon[$j]['lat']);
        $vj_lng = floatval($polygon[$j]['lng']);
        
        if ((($vi_lat < $lat && $vj_lat >= $lat) || ($vj_lat < $lat && $vi_lat >= $lat))
            && ($vi_lng <= $lng || $vj_lng <= $lng)) {
            
            if ($vi_lng + ($lat - $vi_lat) / ($vj_lat - $vi_lat) * ($vj_lng - $vi_lng) < $lng) {
                $oddNodes = !$oddNodes;
            }
        }
        
        $j = $i;
    }
    
    return $oddNodes;
}
