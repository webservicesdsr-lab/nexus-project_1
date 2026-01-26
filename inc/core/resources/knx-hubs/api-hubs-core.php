<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Core API (v4.4 Production - Canonical)
 * ----------------------------------------------------------
 * REST API for Global Hubs Management (CRUD level)
 * ✅ Add Hub (with city_id)
 * ✅ Toggle Hub Status
 * ✅ Update Temporary Closure
 * ----------------------------------------------------------
 * Routes:
 *   - POST /wp-json/knx/v1/add-hub
 *   - POST /wp-json/knx/v1/update-hub-closure
 *   - POST /wp-json/knx/v1/toggle-hub
 * ==========================================================
 */

add_action('rest_api_init', function() {

    register_rest_route('knx/v1', '/add-hub', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_add_hub_v44'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/update-hub-closure', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_update_hub_closure_v44'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/toggle-hub', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_toggle_hub_status_v44'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

/**
 * Add Hub (v4.4)
 */
function knx_api_add_hub_v44(WP_REST_Request $r) {
    global $wpdb;

    $table_hubs   = $wpdb->prefix . 'knx_hubs';
    $table_cities = $wpdb->prefix . 'knx_cities';

    $name    = sanitize_text_field($r['name']);
    $phone   = sanitize_text_field($r['phone']);
    $email   = sanitize_email($r['email']);
    $city_id = intval($r['city_id']);
    $nonce   = sanitize_text_field($r['knx_nonce']);

    if (!wp_verify_nonce($nonce, 'knx_add_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    if (empty($name) || empty($email)) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_fields'], 400);
    }

    if ($city_id > 0) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_cities} WHERE id = %d", $city_id));
        if (!$exists) {
            return new WP_REST_Response(['success' => false, 'error' => 'invalid_city'], 404);
        }
    }

    $slug = knx_slugify_hub_name($name);
    
    $inserted = $wpdb->insert(
        $table_hubs,
        [
            'name'       => $name,
            'phone'      => $phone,
            'email'      => $email,
            'city_id'    => $city_id ?: null,
            'slug'       => $slug,
            'status'     => 'active',
            'created_at' => current_time('mysql')
        ],
        ['%s', '%s', '%s', '%d', '%s', '%s', '%s']
    );

    if (!$inserted) {
        return new WP_REST_Response(['success' => false, 'error' => 'db_error'], 500);
    }

    return new WP_REST_Response([
        'success' => true,
        'hub_id'  => $wpdb->insert_id,
        'message' => '✅ Hub added successfully'
    ], 200);
}

/**
 * Update Temporary Closure (v4.4)
 */
function knx_api_update_hub_closure_v44(WP_REST_Request $r) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hubs';

    $hub_id = intval($r['hub_id']);
    $nonce  = sanitize_text_field($r['knx_nonce']);
    
    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    if (!$hub_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_id'], 400);
    }

    $is_temp_closed     = intval($r['is_temp_closed']);
    $temp_close_message = sanitize_textarea_field($r['temp_close_message']);
    $temp_reopen_at     = sanitize_text_field($r['temp_reopen_at']);

    $wpdb->update(
        $table,
        [
            'is_temp_closed'     => $is_temp_closed,
            'temp_close_message' => $temp_close_message,
            'temp_reopen_at'     => $temp_reopen_at ?: null,
            'updated_at'         => current_time('mysql')
        ],
        ['id' => $hub_id],
        ['%d', '%s', '%s', '%s'],
        ['%d']
    );

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Temporary closure updated successfully'
    ], 200);
}

/**
 * Toggle Hub Status (v4.4)
 */
function knx_api_toggle_hub_status_v44(WP_REST_Request $r) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hubs';

    $hub_id = intval($r['hub_id'] ?? $r['id'] ?? 0);
    $status = sanitize_text_field($r['status'] ?? '');
    $nonce  = sanitize_text_field($r['knx_nonce'] ?? $r['nonce'] ?? '');

    if (
        !wp_verify_nonce($nonce, 'knx_toggle_hub_nonce') &&
        !wp_verify_nonce($nonce, 'knx_edit_hub_nonce')
    ) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    if (!$hub_id || !in_array($status, ['active', 'inactive'])) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_data'], 400);
    }

    $wpdb->update(
        $table,
        ['status' => $status],
        ['id' => $hub_id],
        ['%s'],
        ['%d']
    );

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Hub status updated successfully',
        'hub_id'  => $hub_id,
        'status'  => $status
    ], 200);
}
