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

    // Provide address_label and metadata if available. Use WP options as a safe fallback
    $meta_key = 'knx_hub_location_meta_' . intval($hub_id);
    $meta = get_option($meta_key, null);

    // address_label preference: prefer explicit column if present, otherwise fallback to option or address
    $address_label = null;
    if (isset($hub->address_label)) {
        $address_label = $hub->address_label;
    } elseif (is_array($meta) && !empty($meta['address_label'])) {
        $address_label = $meta['address_label'];
    } else {
        $address_label = $hub->address;
    }

    $location_source = null;
    if (isset($hub->location_source)) {
        $location_source = $hub->location_source;
    } elseif (is_array($meta) && !empty($meta['location_source'])) {
        $location_source = $meta['location_source'];
    }

    $address_resolution_status = null;
    if (isset($hub->address_resolution_status)) {
        $address_resolution_status = $hub->address_resolution_status;
    } elseif (is_array($meta) && !empty($meta['address_resolution_status'])) {
        $address_resolution_status = $meta['address_resolution_status'];
    }

    // Response
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'hub_id'                 => intval($hub->id),
            'hub_name'               => $hub->name,
            'address'                => $hub->address,
            'address_label'          => $address_label,
            'lat'                    => floatval($hub->latitude ?? 0),
            'lng'                    => floatval($hub->longitude ?? 0),
            'delivery_radius'        => floatval($hub->delivery_radius ?? 5),
            'delivery_zone_type'     => $hub->delivery_zone_type ?? 'radius',
            'delivery_zones'         => $zones_formatted,
            'location_source'        => $location_source,
            'address_resolution_status' => $address_resolution_status,
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
    $address_label = sanitize_text_field($r->get_param('address_label'));
    $location_source = sanitize_text_field($r->get_param('location_source'));
    $address_resolution_status = sanitize_text_field($r->get_param('address_resolution_status'));
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

    // Normalize incoming polygon shapes to legacy array-of-arrays [lat, lng]
    // Support incoming shapes:
    // - array of objects [{lat:..., lng:...}, ...]
    // - array of associative arrays [['lat'=>..., 'lng'=>...], ...]
    // - already array-of-arrays [[lat, lng], ...]
    if (is_array($polygon_points) && count($polygon_points) > 0) {
        $first = $polygon_points[0];
        $need_normalize = false;
        if (is_object($first) || (is_array($first) && (isset($first['lat']) || isset($first['lng'])))) {
            $need_normalize = true;
        }

        if ($need_normalize) {
            $normalized = [];
            foreach ($polygon_points as $p) {
                $plat = null; $plng = null;
                if (is_object($p)) {
                    if (isset($p->lat)) $plat = $p->lat;
                    if (isset($p->lng)) $plng = $p->lng;
                } elseif (is_array($p)) {
                    if (isset($p['lat'])) $plat = $p['lat'];
                    if (isset($p['lng'])) $plng = $p['lng'];
                }

                if (!is_numeric($plat) || !is_numeric($plng)) {
                    // malformed point — abort normalization and keep original to allow later validation to fail-closed
                    $normalized = null;
                    break;
                }

                $normalized[] = [(float)$plat, (float)$plng];
            }

            if (is_array($normalized)) {
                $polygon_points = $normalized;
            }
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

    // Determine effective address to validate: prefer address_label when provided
    $effective_address = !empty($address_label) ? $address_label : $address;

    if (empty($effective_address) || $lat === 0.0 || $lng === 0.0) {
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
    // Attempt to save address_label and metadata if hub table has columns, otherwise fall back to updating
    // the main `address` column and store metadata in WP options for compatibility.
    $has_col = function($col) use ($wpdb, $table_hubs) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table_hubs,
            $col
        ));
        return intval($count) > 0;
    };

    $update_data = [
        'latitude'           => $lat,
        'longitude'          => $lng,
        'delivery_radius'    => $radius,
        'delivery_zone_type' => $zone_type,
        'updated_at'         => current_time('mysql'),
    ];

    if ($has_col('address_label')) {
        $update_data['address_label'] = $address_label ? $address_label : $address;
    } else {
        // fallback: overwrite existing address field with address_label when provided
        $update_data['address'] = $address_label ? $address_label : $address;
    }

    // Persist location_source and address_resolution_status if columns exist, else keep them in options
    $meta_to_store = [];
    if ($has_col('location_source')) {
        $update_data['location_source'] = $location_source;
    } else {
        if (!empty($location_source)) $meta_to_store['location_source'] = $location_source;
    }

    if ($has_col('address_resolution_status')) {
        $update_data['address_resolution_status'] = $address_resolution_status;
    } else {
        if (!empty($address_resolution_status)) $meta_to_store['address_resolution_status'] = $address_resolution_status;
    }

    // Build formats array to match update_data
    $formats = [];
    foreach ($update_data as $k => $v) {
        if (in_array($k, ['address', 'address_label', 'location_source', 'address_resolution_status', 'delivery_zone_type', 'updated_at'])) $formats[] = '%s';
        else $formats[] = '%f';
    }

    $updated = $wpdb->update(
        $table_hubs,
        $update_data,
        ['id' => $hub_id],
        $formats,
        ['%d']
    );

    // If any metadata couldn't be stored in columns, persist safely in WP options
    if (!empty($meta_to_store)) {
        $existing_meta = get_option('knx_hub_location_meta_' . intval($hub_id), []);
        if (!is_array($existing_meta)) $existing_meta = [];
        $existing_meta = array_merge($existing_meta, $meta_to_store);
        update_option('knx_hub_location_meta_' . intval($hub_id), $existing_meta);
    }

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
