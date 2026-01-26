<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Hub Location API (v5.0 CANONICAL)
 * ----------------------------------------------------------
 * Routes:
 *   GET  /wp-json/knx/v1/hub-location/{hub_id}
 *   POST /wp-json/knx/v1/hub-location
 * 
 * Features:
 * - Dual Maps Support (Google Maps + Leaflet fallback)
 * - Polygon delivery zones with radius fallback
 * - Secure nonce validation
 * - Consistent response structure
 * ==========================================================
 */

add_action('rest_api_init', function() {
    // GET hub location data
    register_rest_route('knx/v1', '/hub-location/(?P<hub_id>\d+)', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_api_get_hub_location_v5'),
        'permission_callback' => '__return_true',
        'args' => [
            'hub_id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && intval($param) > 0;
                }
            ]
        ]
    ]);

    // POST update hub location
    register_rest_route('knx/v1', '/hub-location', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_api_update_hub_location_v5'),
        'permission_callback' => knx_rest_permission_roles([
            'super_admin', 'manager', 'hub_management', 'menu_uploader', 'vendor_owner'
        ]),
    ]);
});

/**
 * GET Hub Location Data
 * Returns hub coordinates, delivery zones, and polygon data
 */
function knx_api_get_hub_location_v5(WP_REST_Request $r) {
    global $wpdb;

    $hub_id = intval($r->get_param('hub_id'));
    
    // Get hub data
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, address, latitude, longitude, 
                delivery_radius, delivery_zone_type
         FROM {$table_hubs}
         WHERE id = %d
         LIMIT 1",
        $hub_id
    ));

    if (!$hub) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'hub_not_found',
            'message' => 'Hub not found'
        ], 404);
    }

    // Get delivery zones (polygons)
    $table_zones = $wpdb->prefix . 'knx_delivery_zones';
    $zones = $wpdb->get_results($wpdb->prepare(
        "SELECT id, zone_name, polygon_points, 
                fill_color, fill_opacity, stroke_color, stroke_weight
         FROM {$table_zones}
         WHERE hub_id = %d AND is_active = 1
         ORDER BY created_at ASC",
        $hub_id
    ));

    $zones_formatted = [];
    if ($zones) {
        foreach ($zones as $zone) {
            $points = json_decode($zone->polygon_points, true);
            $zones_formatted[] = [
                'id'            => intval($zone->id),
                'zone_name'     => $zone->zone_name,
                'polygon_points'=> is_array($points) ? $points : [],
                'fill_color'    => $zone->fill_color,
                'fill_opacity'  => floatval($zone->fill_opacity),
                'stroke_color'  => $zone->stroke_color,
                'stroke_weight' => intval($zone->stroke_weight),
            ];
        }
    }

    // Response
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'hub_id'             => intval($hub->id),
            'hub_name'           => $hub->name,
            'address'            => $hub->address,
            'lat'                => floatval($hub->latitude ?? 0),
            'lng'                => floatval($hub->longitude ?? 0),
            'delivery_radius'    => floatval($hub->delivery_radius ?? 5),
            'delivery_zone_type' => $hub->delivery_zone_type ?? 'radius',
            'delivery_zones'     => $zones_formatted,
        ]
    ], 200);
}

/**
 * POST Update Hub Location
 * Updates hub coordinates, address, and delivery zones
 */
function knx_api_update_hub_location_v5(WP_REST_Request $r) {
    global $wpdb;

    // Extract and validate parameters
    $hub_id  = intval($r->get_param('hub_id'));
    $address = sanitize_text_field($r->get_param('address'));
    $lat     = floatval($r->get_param('lat'));
    $lng     = floatval($r->get_param('lng'));
    $radius  = floatval($r->get_param('delivery_radius'));
    $zone_type = sanitize_text_field($r->get_param('delivery_zone_type'));
    $polygon_points = $r->get_param('polygon_points');

    // Defensive normalization: polygon_points may arrive as JSON string in some clients
    if (is_string($polygon_points) && $polygon_points !== '') {
        $decoded = json_decode($polygon_points, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $polygon_points = $decoded;
        }
    }

    // Validation
    if ($hub_id <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'invalid_hub_id',
            'message' => 'Invalid hub ID'
        ], 400);
    }

    if (empty($address) || $lat === 0.0 || $lng === 0.0) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'missing_required_fields',
            'message' => 'Address, latitude, and longitude are required'
        ], 400);
    }

    if (!in_array($zone_type, ['radius', 'polygon'], true)) {
        $zone_type = 'radius';
    }

    // Update hub location
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $updated = $wpdb->update(
        $table_hubs,
        [
            'address'            => $address,
            'latitude'           => $lat,
            'longitude'          => $lng,
            'delivery_radius'    => $radius,
            'delivery_zone_type' => $zone_type,
            'updated_at'         => current_time('mysql'),
        ],
        ['id' => $hub_id],
        ['%s', '%f', '%f', '%f', '%s', '%s'],
        ['%d']
    );

    if ($updated === false) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'db_update_failed',
            'message' => 'Failed to update hub location',
            'db_error' => $wpdb->last_error
        ], 500);
    }

    // Handle polygon zones
    $table_zones = $wpdb->prefix . 'knx_delivery_zones';
    
    // Debug logging for polygon save diagnostics
    if ($zone_type === 'polygon') {
        error_log('[KNX-ZONES][DEBUG] polygon_points_type=' . gettype($polygon_points) . ' is_array=' . (is_array($polygon_points) ? '1' : '0') . ' count=' . (is_array($polygon_points) ? count($polygon_points) : -1));
    }
    
    if ($zone_type === 'polygon' && is_array($polygon_points) && count($polygon_points) >= 3) {
        error_log('[KNX-ZONES][WRITE] attempting insert hub_id=' . $hub_id . ' zone_name=Main Delivery Area points_len=' . count($polygon_points));
        error_log('[KNX-ZONES][WRITE] table_zones=' . $table_zones);
        
        // Delete existing zones for this hub
        $deleted = $wpdb->delete($table_zones, ['hub_id' => $hub_id], ['%d']);
        error_log('[KNX-ZONES][WRITE] deleted_existing_zones=' . ($deleted === false ? 'ERROR' : $deleted));
        
        // Insert new polygon zone
        $inserted = $wpdb->insert(
            $table_zones,
            [
                'hub_id'         => $hub_id,
                'zone_name'      => 'Main Delivery Area',
                'polygon_points' => json_encode($polygon_points),
                'fill_color'     => '#0b793a',
                'fill_opacity'   => 0.35,
                'stroke_color'   => '#0b793a',
                'stroke_weight'  => 2,
                'is_active'      => 1,
                'created_at'     => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s']
        );

        error_log('[KNX-ZONES][WRITE] insert_ok=' . ($inserted ? '1' : '0') . ' rows=' . $wpdb->rows_affected . ' err=' . $wpdb->last_error . ' insert_id=' . $wpdb->insert_id);
        error_log('[KNX-ZONES][WRITE] last_query=' . $wpdb->last_query);
        
        if ($inserted === false) {
            error_log('[KNX-LOCATION] Failed to insert polygon: ' . $wpdb->last_error);
        }
    } else {
        // If switching to radius mode, clear polygon zones
        if ($zone_type === 'radius') {
            $wpdb->delete($table_zones, ['hub_id' => $hub_id], ['%d']);
        }
    }

    // Success response
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Hub location updated successfully',
        'data' => [
            'hub_id'             => $hub_id,
            'address'            => $address,
            'lat'                => $lat,
            'lng'                => $lng,
            'delivery_radius'    => $radius,
            'delivery_zone_type' => $zone_type,
            'polygon_saved'      => ($zone_type === 'polygon' && isset($inserted) && $inserted !== false)
        ]
    ], 200);
}
