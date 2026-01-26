<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Get Hub (v4.0 Production - Canonical)
 * ----------------------------------------------------------
 * Retrieves hub data by ID for edit-hub template.
 * Supports city_id for dropdown and dynamic table prefixes.
 * Route: GET /wp-json/knx/v1/get-hub?id={hub_id}
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/get-hub', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_api_get_hub_v40'),
        'permission_callback' => '__return_true',
    ]);
});

function knx_api_get_hub_v40(WP_REST_Request $r) {
    global $wpdb;

    /** Dynamic table names with current WP prefix */
    $table_hubs   = $wpdb->prefix . 'knx_hubs';
    $table_cities = $wpdb->prefix . 'knx_cities';
    $table_cats   = $wpdb->prefix . 'knx_hub_categories';

    /** Get hub ID */
    $hub_id = intval($r->get_param('id'));
    if (!$hub_id) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'missing_id'
        ], 400);
    }

    /** Fetch hub data */
        $hub = $wpdb->get_row($wpdb->prepare(
            "
            SELECT h.*, c.name AS city_name, cat.name AS category_name
            FROM {$table_hubs} h
            LEFT JOIN {$table_cities} c ON h.city_id = c.id
            LEFT JOIN {$table_cats} cat ON h.category_id = cat.id
            WHERE h.id = %d
            LIMIT 1
            ",
            $hub_id
        ));

    if (!$hub) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'hub_not_found'
        ], 404);
    }

    /** Get delivery zones (polygons) if available */
    $zones_table = $wpdb->prefix . 'knx_delivery_zones';
    $delivery_zones = $wpdb->get_results($wpdb->prepare(
        "SELECT id, zone_name, polygon_points, fill_color, fill_opacity, stroke_color, stroke_weight, is_active 
         FROM $zones_table 
         WHERE hub_id = %d AND is_active = 1
         ORDER BY created_at ASC",
        $hub_id
    ));

    // Decode JSON polygon_points for each zone
    $zones_formatted = [];
    if ($delivery_zones) {
        foreach ($delivery_zones as $zone) {
            $zones_formatted[] = [
                'id'            => intval($zone->id),
                'zone_name'     => $zone->zone_name,
                'polygon_points'=> json_decode($zone->polygon_points, true) ?: [],
                'fill_color'    => $zone->fill_color,
                'fill_opacity'  => floatval($zone->fill_opacity),
                'stroke_color'  => $zone->stroke_color,
                'stroke_weight' => intval($zone->stroke_weight),
            ];
        }
    }

    /** Normalize response */
    $response = [
        'id'                 => intval($hub->id),
        'name'               => stripslashes($hub->name),
        'email'              => $hub->email,
        'phone'              => $hub->phone,
        'status'             => $hub->status,
        'city_id'            => intval($hub->city_id ?? 0),
        'city_name'          => $hub->city_name ?? '',
        'address'            => $hub->address,
        'lat'                => floatval($hub->latitude ?? 0),
        'lng'                => floatval($hub->longitude ?? 0),
        'logo_url'           => $hub->logo_url,
        'category_id'        => intval($hub->category_id ?? 0),
        'category_name'      => $hub->category_name ?? '',
        'delivery_radius'    => floatval($hub->delivery_radius ?? 3),
        'delivery_zone_type' => $hub->delivery_zone_type ?? 'radius',
        'delivery_zones'     => $zones_formatted,
        'timezone'           => $hub->timezone,
        'currency'           => $hub->currency,
        'is_featured'        => intval($hub->is_featured ?? 0),
    ];

    return new WP_REST_Response([
        'success' => true,
        'hub'     => $response
    ], 200);
}


