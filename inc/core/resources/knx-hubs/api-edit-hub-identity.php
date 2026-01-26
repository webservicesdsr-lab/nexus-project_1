<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Edit Hub Identity API (v4.5 - Canonical)
 * ----------------------------------------------------------
 * - Correct handling of category_id & city_id with NULL values
 * - Proper WPDB formats (NO "NULL" in formats array)
 * - Accurate "changes made" detection
 * - Full validation: nonce, roles, active status
 * Route: POST /wp-json/knx/v1/update-hub-identity
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/update-hub-identity', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_update_hub_identity_v45'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager', 'hub_management', 'menu_uploader', 'vendor_owner']),
    ]);
});

function knx_update_hub_identity_v45(WP_REST_Request $request) {
    global $wpdb;

    $table_hubs   = $wpdb->prefix . 'knx_hubs';
    $table_cities = $wpdb->prefix . 'knx_cities';
    $table_cats   = $wpdb->prefix . 'knx_hub_categories';

    $data = json_decode($request->get_body(), true);

    if (empty($data['knx_nonce']) || !wp_verify_nonce($data['knx_nonce'], 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $hub_id      = intval($data['hub_id'] ?? 0);
    $city_id     = intval($data['city_id'] ?? 0);
    $category_id = intval($data['category_id'] ?? 0);
    $email       = sanitize_email($data['email'] ?? '');
    $phone       = sanitize_text_field($data['phone'] ?? '');
    $status      = in_array($data['status'] ?? 'active', ['active', 'inactive'])
                    ? $data['status']
                    : 'active';

    if (!$hub_id || empty($email)) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_fields'], 400);
    }

    if ($city_id > 0) {
        $city_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_cities} WHERE id = %d AND status = 'active'",
            $city_id
        ));
        if (!$city_exists) {
            return new WP_REST_Response(['success' => false, 'error' => 'invalid_city'], 404);
        }
    }

    if ($category_id > 0) {
        $cat_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_cats} WHERE id = %d AND status = 'active'",
            $category_id
        ));
        if (!$cat_exists) {
            return new WP_REST_Response(['success' => false, 'error' => 'invalid_category'], 404);
        }
    }

    $city_id     = $city_id     > 0 ? $city_id     : null;
    $category_id = $category_id > 0 ? $category_id : null;

    $update_data = [
        'email'       => $email,
        'phone'       => $phone,
        'status'      => $status,
        'city_id'     => $city_id,
        'category_id' => $category_id,
        'updated_at'  => current_time('mysql')
    ];

    $formats = ['%s', '%s', '%s', '%d', '%d', '%s'];

    $updated = $wpdb->update(
        $table_hubs,
        $update_data,
        ['id' => $hub_id],
        $formats,
        ['%d']
    );

    if ($updated === false) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'db_error'
        ], 500);
    }

    return new WP_REST_Response([
        'success' => true,
        'hub_id'  => $hub_id,
        'message' => $updated ? 'Hub identity updated successfully' : 'No changes made'
    ], 200);
}
