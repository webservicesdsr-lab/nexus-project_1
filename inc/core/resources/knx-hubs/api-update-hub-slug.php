<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Update Hub Slug API (Canonical)
 * ----------------------------------------------------------
 * Manages hub slug updates with uniqueness validation
 * Route: POST /wp-json/knx/v1/update-hub-slug
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/update-hub-slug', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_update_hub_slug'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager', 'hub_management']),
    ]);
});

function knx_api_update_hub_slug($request) {
    global $wpdb;
    
    $data = $request->get_json_params();
    $hub_id = intval($data['hub_id'] ?? 0);
    $slug = sanitize_title($data['slug'] ?? '');
    $nonce = sanitize_text_field($data['nonce'] ?? '');
    
    // Validate nonce
    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'message' => 'Invalid nonce'], 403);
    }
    
    if (!$hub_id || !$slug) {
        return new WP_REST_Response(['success' => false, 'message' => 'Hub ID and slug are required'], 400);
    }
    
    // Validate slug format
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Slug can only contain lowercase letters, numbers, and hyphens'], 400);
    }
    
    // Check if hub exists
    $hub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}knx_hubs WHERE id = %d", $hub_id));
    if (!$hub) {
        return new WP_REST_Response(['success' => false, 'message' => 'Hub not found'], 404);
    }
    
    // Check slug uniqueness (excluding current hub)
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}knx_hubs WHERE slug = %s AND id != %d",
        $slug, $hub_id
    ));
    
    if ($existing) {
        return new WP_REST_Response(['success' => false, 'message' => 'This slug is already in use by another hub'], 409);
    }
    
    // Update slug
    $result = $wpdb->update(
        $wpdb->prefix . 'knx_hubs',
        ['slug' => $slug],
        ['id' => $hub_id],
        ['%s'],
        ['%d']
    );
    
    if ($result === false) {
        return new WP_REST_Response(['success' => false, 'message' => 'Failed to update slug'], 500);
    }
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Slug updated successfully',
        'slug' => $slug
    ]);
}
