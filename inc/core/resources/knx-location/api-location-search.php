<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Location Search API (Canonical)
 * Provides autocomplete functionality for city/location search
 * 
 * Endpoint: GET /knx/v1/location-search?q={query}
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/location-search', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_location_search'),
        'permission_callback' => '__return_true',
        'args' => [
            'q' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Search query for city/location',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);
});

/**
 * Location search callback
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_api_location_search($request) {
    global $wpdb;
    
    $query = $request->get_param('q');
    
    // Minimum 2 characters for search
    if (strlen($query) < 2) {
        return new WP_REST_Response([
            'success' => true,
            'items' => []
        ], 200);
    }
    
    // Query cities table
    $table_cities = $wpdb->prefix . 'knx_cities';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_cities'") != $table_cities) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Cities table not found'
        ], 500);
    }
    
    // Search cities with LIKE
    $search_term = '%' . $wpdb->esc_like($query) . '%';
    $cities = $wpdb->get_results($wpdb->prepare(
        "SELECT id, city_name, state_code 
         FROM $table_cities 
         WHERE city_name LIKE %s 
         ORDER BY city_name ASC 
         LIMIT 10",
        $search_term
    ));
    
    if ($wpdb->last_error) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Database error'
        ], 500);
    }
    
    // Format results
    $items = array_map(function($city) {
        return [
            'id' => (int) $city->id,
            'name' => $city->city_name,
            'state' => $city->state_code,
            'display' => $city->city_name . ', ' . $city->state_code
        ];
    }, $cities);
    
    return new WP_REST_Response([
        'success' => true,
        'items' => $items
    ], 200);
}
