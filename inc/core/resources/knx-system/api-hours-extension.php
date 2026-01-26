<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Hours API Extension (Canonical)
 * ----------------------------------------------------------
 * Additional endpoints that extend existing APIs with hours data
 * WITHOUT modifying the original endpoints.
 * 
 * Endpoints:
 * - GET /knx/v1/hub-status/{id}
 * - GET /knx/v1/hubs-with-hours
 * - POST /knx/v1/hubs-status
 * ==========================================================
 */

add_action('rest_api_init', function() {
    
    // Get hub status (single hub)
    register_rest_route('knx/v1', '/hub-status/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_hub_status'),
        'permission_callback' => '__return_true',
        'args' => [
            'id' => [
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);
    
    // Enhanced hubs list with hours
    register_rest_route('knx/v1', '/hubs-with-hours', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_hubs_with_hours'),
        'permission_callback' => '__return_true',
    ]);
    
    // Batch status check for multiple hubs
    register_rest_route('knx/v1', '/hubs-status', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_hubs_status_batch'),
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Get status for a single hub
 */
function knx_api_hub_status(WP_REST_Request $request) {
    $hub_id = intval($request['id']);
    
    if (!$hub_id) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'invalid_hub_id'
        ], 400);
    }
    
    $hub = knx_hours_get_hub($hub_id);
    
    if (!$hub) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'hub_not_found'
        ], 404);
    }
    
    $status = knx_hours_get_status($hub);
    $weekly = knx_hours_format_weekly($hub);
    
    return new WP_REST_Response([
        'success' => true,
        'hub_id' => $hub_id,
        'status' => $status,
        'weekly_hours' => $weekly
    ], 200);
}

/**
 * Enhanced version of /hubs that includes hours data
 */
function knx_api_hubs_with_hours(WP_REST_Request $request) {
    global $wpdb;
    
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $table_cats = $wpdb->prefix . 'knx_hub_categories';
    
    // Get query params
    $open_only = intval($request->get_param('open_only') ?? 0);
    $limit = intval($request->get_param('limit') ?? 50);
    $offset = intval($request->get_param('offset') ?? 0);
    
    $limit = min($limit, 100); // Cap at 100
    
    $hubs = $wpdb->get_results($wpdb->prepare("
        SELECT 
            h.id,
            h.slug,
            h.name,
            h.tagline,
            h.address,
            h.logo_url,
            h.hero_img,
            h.type,
            h.rating,
            h.delivery_available,
            h.pickup_available,
            h.category_id,
            h.status,
            h.timezone,
            h.closure_start,
            h.closure_end,
            h.hours_monday,
            h.hours_tuesday,
            h.hours_wednesday,
            h.hours_thursday,
            h.hours_friday,
            h.hours_saturday,
            h.hours_sunday,
            cat.name AS category_name
        FROM {$table_hubs} h
        LEFT JOIN {$table_cats} cat ON h.category_id = cat.id
        WHERE h.status = 'active'
        ORDER BY h.rating DESC, h.name ASC
        LIMIT %d OFFSET %d
    ", $limit, $offset));
    
    if (!$hubs) {
        return new WP_REST_Response([
            'success' => true,
            'hubs' => []
        ], 200);
    }
    
    // Enrich with hours data
    knx_hours_enrich_hubs($hubs);
    
    // Filter by open_only if requested
    if ($open_only) {
        $hubs = array_filter($hubs, function($hub) {
            return $hub->is_open;
        });
        $hubs = array_values($hubs); // Re-index
    }
    
    // Format response (clean up internal fields)
    foreach ($hubs as &$hub) {
        // Remove internal hours columns from response
        unset($hub->hours_monday, $hub->hours_tuesday, $hub->hours_wednesday,
              $hub->hours_thursday, $hub->hours_friday, $hub->hours_saturday,
              $hub->hours_sunday, $hub->closure_start, $hub->closure_end);
        
        // Format other fields
        $hub->category_id = $hub->category_id ? intval($hub->category_id) : null;
        $hub->rating = floatval($hub->rating);
        $hub->delivery_available = (bool) $hub->delivery_available;
        $hub->pickup_available = (bool) $hub->pickup_available;
    }
    
    return new WP_REST_Response([
        'success' => true,
        'hubs' => $hubs,
        'total' => count($hubs)
    ], 200);
}

/**
 * Batch status check for multiple hubs
 */
function knx_api_hubs_status_batch(WP_REST_Request $request) {
    $hub_ids = $request->get_param('hub_ids');
    
    if (!is_array($hub_ids) || empty($hub_ids)) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'hub_ids_required'
        ], 400);
    }
    
    // Limit to 50 hubs per request
    $hub_ids = array_slice(array_map('intval', $hub_ids), 0, 50);
    
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hubs';
    
    $placeholders = implode(',', array_fill(0, count($hub_ids), '%d'));
    
    $hubs = $wpdb->get_results($wpdb->prepare("
        SELECT id, name, status, timezone, closure_start, closure_end,
               hours_monday, hours_tuesday, hours_wednesday, 
               hours_thursday, hours_friday, hours_saturday, hours_sunday
        FROM {$table} 
        WHERE id IN ({$placeholders}) AND status = 'active'
    ", ...$hub_ids));
    
    $statuses = [];
    
    foreach ($hubs as $hub) {
        $status = knx_hours_get_status($hub);
        $statuses[intval($hub->id)] = [
            'is_open' => $status['is_open'],
            'status_text' => $status['status_text'],
            'hours_today' => $status['hours_today']
        ];
    }
    
    return new WP_REST_Response([
        'success' => true,
        'statuses' => $statuses
    ], 200);
}
