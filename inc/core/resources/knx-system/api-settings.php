<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Core Settings API (v4.0 CANONICAL)
 * ----------------------------------------------------------
 * Handles Google Maps API key management using WordPress wp_options.
 * Provides save and clear functionality for dual map system testing.
 * 
 * Endpoint:
 * - POST /knx/v1/update-settings
 * 
 * Storage:
 * - Uses wp_options table (WordPress standard)
 * - Option key: knx_google_maps_key
 * - Empty string or null clears the key
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/update-settings', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_update_settings'),
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ]);
});

/**
 * Save or clear the Google Maps API key using WordPress wp_options
 */
function knx_api_update_settings(WP_REST_Request $r) {
    // Validate parameter exists (can be empty string)
    if (!isset($r['google_maps_api'])) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'missing_parameter',
            'message' => 'google_maps_api parameter is required (can be empty string to clear).'
        ], 400);
    }

    $google_maps_api = sanitize_text_field($r['google_maps_api']);

    // Optional: require_email_verification flag - coerce to boolean-like and persist as '1'/'0'
    if (isset($r['require_email_verification'])) {
        $raw_flag = $r['require_email_verification'];
        $flag = filter_var($raw_flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        // if null (unrecognized), default to true; otherwise store '1' or '0'
        if ($flag === null) {
            $flag = true;
        }
        update_option('knx_require_email_verification', $flag ? '1' : '0');
    }

    // Update or clear the option
    if (empty($google_maps_api)) {
        // Clear the key - delete from wp_options
        delete_option('knx_google_maps_key');
        // Optionally clear or set provider if passed
        if (isset($r['location_provider'])) {
            $lp = sanitize_text_field($r['location_provider']);
            $allowed = ['auto','google','nominatim'];
            if (!in_array($lp, $allowed, true)) $lp = 'auto';
            update_option('knx_location_provider', $lp);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'API key cleared. System will use OpenStreetMap (Leaflet).',
            'google_maps_api' => '',
            'require_email_verification' => get_option('knx_require_email_verification', '1')
        ], 200);
    } else {
        // Save the key
        update_option('knx_google_maps_key', $google_maps_api);
        // Optionally save provider
        if (isset($r['location_provider'])) {
            $lp = sanitize_text_field($r['location_provider']);
            $allowed = ['auto','google','nominatim'];
            if (!in_array($lp, $allowed, true)) $lp = 'auto';
            update_option('knx_location_provider', $lp);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Google Maps API key saved successfully.',
            'google_maps_api' => $google_maps_api,
            'require_email_verification' => get_option('knx_require_email_verification', '1')
        ], 200);
    }
}

/**
 * Helper: Get the current Google Maps API key (for other modules)
 */
function knx_get_google_maps_key() {
    // Use WordPress options as SSOT for Google Maps key
    return get_option('knx_google_maps_key', '');
}

