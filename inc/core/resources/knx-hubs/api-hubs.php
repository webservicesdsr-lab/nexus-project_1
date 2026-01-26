<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Hubs Public API (Canonical)
 * ----------------------------------------------------------
 * Simple endpoint sin calcular horarios (por ahora)
 * Route: GET /wp-json/knx/v1/hubs
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/hubs', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_api_get_hubs'),
        'permission_callback' => '__return_true',
    ]);
});

function knx_api_get_hubs(WP_REST_Request $request) {
    global $wpdb;

    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $table_cats = $wpdb->prefix . 'knx_hub_categories';

    $hubs = $wpdb->get_results("
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
            cat.name AS category_name
        FROM {$table_hubs} h
        LEFT JOIN {$table_cats} cat ON h.category_id = cat.id
        WHERE h.status = 'active'
        ORDER BY h.rating DESC, h.name ASC
    ");

    if (!$hubs) {
        return new WP_REST_Response([
            'success' => true,
            'hubs' => []
        ], 200);
    }

    // Formatear datos
    foreach ($hubs as &$hub) {
        $hub->is_open = true; // Por ahora todos abiertos
        $hub->category_id = $hub->category_id ? intval($hub->category_id) : null;
        $hub->rating = floatval($hub->rating);
        $hub->delivery_available = (bool) $hub->delivery_available;
        $hub->pickup_available = (bool) $hub->pickup_available;
    }

    return new WP_REST_Response([
        'success' => true,
        'hubs' => $hubs
    ], 200);
}
