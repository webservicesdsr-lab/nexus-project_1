<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Update Hub Settings (v1.0 - Canonical)
 * ----------------------------------------------------------
 * Updates timezone, currency, tax_rate, and min_order fields.
 * Secure via REST + nonce validation.
 * Route: POST /wp-json/knx/v1/update-hub-settings
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/update-hub-settings', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_update_hub_settings'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

function knx_api_update_hub_settings(WP_REST_Request $r) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hubs';

    $nonce = sanitize_text_field($r['knx_nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $hub_id    = intval($r['hub_id']);
    $timezone  = sanitize_text_field($r['timezone']);
    $currency  = sanitize_text_field($r['currency']);
    $tax_rate  = floatval($r['tax_rate']);
    $min_order = floatval($r['min_order']);

    if (!$hub_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_hub_id'], 400);
    }

    $updated = $wpdb->update(
        $table,
        [
            'timezone'   => $timezone,
            'currency'   => $currency,
            'tax_rate'   => $tax_rate,
            'min_order'  => $min_order,
            'updated_at' => current_time('mysql')
        ],
        ['id' => $hub_id],
        ['%s', '%s', '%f', '%f', '%s'],
        ['%d']
    );

    if ($updated === false) {
        return new WP_REST_Response([
            'success' => false, 
            'error'   => 'db_error',
            'detail'  => $wpdb->last_error
        ], 500);
    }

    return new WP_REST_Response(['success' => true], 200);
}
