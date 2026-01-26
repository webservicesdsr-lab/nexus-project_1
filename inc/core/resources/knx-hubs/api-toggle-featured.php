<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * API: Toggle Featured Status for Hub (Canonical)
 * ----------------------------------------------------------
 * POST /wp-json/knx/v1/toggle-featured
 * Security: Nonce + Role check (super_admin, manager only)
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/toggle-featured', [
        'methods' => 'POST',
        'callback' => knx_rest_wrap('knx_api_toggle_featured'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

function knx_api_toggle_featured($request) {
    global $wpdb;
    
    // Verify nonce
    $nonce = $request->get_header('X-WP-Nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error('invalid_nonce', 'Security check failed', ['status' => 403]);
    }
    
    // Get hub_id from request
    $hub_id = absint($request->get_param('hub_id'));
    if (!$hub_id) {
        return new WP_Error('missing_hub', 'Hub ID is required', ['status' => 400]);
    }
    
    // Verify hub exists and get current featured status
    $table = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, is_featured FROM {$table} WHERE id = %d",
        $hub_id
    ));
    
    if (!$hub) {
        return new WP_Error('hub_not_found', 'Hub not found', ['status' => 404]);
    }
    
    // Toggle featured status (0 → 1, 1 → 0)
    $new_status = $hub->is_featured == 1 ? 0 : 1;
    
    // Update database with prepared statement
    $updated = $wpdb->update(
        $table,
        ['is_featured' => $new_status],
        ['id' => $hub_id],
        ['%d'],
        ['%d']
    );
    
    if ($updated === false) {
        return new WP_Error('update_failed', 'Failed to update hub', ['status' => 500]);
    }
    
    // Get count of featured hubs
    $featured_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_featured = 1 AND status = 'active'");
    
    // Log action
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $session = knx_get_session();
        error_log(sprintf(
            'Hub %d (%s) featured status changed to %d by %s',
            $hub_id,
            $hub->name,
            $new_status,
            $session->username
        ));
    }
    
    return [
        'success' => true,
        'hub_id' => $hub_id,
        'is_featured' => $new_status,
        'featured_count' => intval($featured_count),
        'message' => $new_status == 1 
            ? 'Hub added to "Locals Love These"' 
            : 'Hub removed from "Locals Love These"'
    ];
}
