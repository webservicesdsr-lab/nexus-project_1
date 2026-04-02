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
    $map_table    = $wpdb->prefix . 'knx_hubs_categories';

    $data = json_decode($request->get_body(), true);

    if (empty($data['knx_nonce']) || !wp_verify_nonce($data['knx_nonce'], 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $hub_id       = intval($data['hub_id'] ?? 0);
    $city_id      = intval($data['city_id'] ?? 0);
    $category_id  = intval($data['category_id'] ?? 0);
    $category_ids = is_array($data['category_ids']) ? array_map('intval', $data['category_ids']) : null;
    $email       = sanitize_email($data['email'] ?? '');
    $phone       = sanitize_text_field($data['phone'] ?? '');
    $status      = in_array($data['status'] ?? 'active', ['active', 'inactive'])
                    ? $data['status']
                    : 'active';

    // Type-only update (e.g. Food Truck toggle) — skip full validation
    $is_type_only = !empty($data['type']) && in_array($data['type'], ['Restaurant', 'Food Truck', 'Cottage Food'], true)
                    && empty($email) && empty($phone);

    if (!$hub_id || (!$is_type_only && empty($email))) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_fields'], 400);
    }

    // Fast path: type-only update
    if ($is_type_only) {
        $wpdb->update(
            $table_hubs,
            ['type' => $data['type'], 'updated_at' => current_time('mysql')],
            ['id' => $hub_id],
            ['%s', '%s'],
            ['%d']
        );
        return knx_rest_response(true, 'Hub type updated');
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

    // Validate single category if provided
    if ($category_id > 0) {
        $cat_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_cats} WHERE id = %d AND status = 'active'",
            $category_id
        ));
        if (!$cat_exists) {
            return new WP_REST_Response(['success' => false, 'error' => 'invalid_category'], 404);
        }
    }

    // Validate multiple category ids if provided
    if (is_array($category_ids)) {
        foreach ($category_ids as $cid) {
            if ($cid <= 0) continue;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_cats} WHERE id = %d AND status = 'active'",
                $cid
            ));
            if (!$exists) {
                return new WP_REST_Response(['success' => false, 'error' => 'invalid_category'], 404);
            }
        }
    }

    $city_id     = $city_id     > 0 ? $city_id     : null;
    $category_id = $category_id > 0 ? $category_id : null;

    // If the pivot table for hub<->categories exists and category_ids was provided,
    // we will sync the mapping table. Otherwise, we update the legacy `category_id` field.
    $update_data = [
        'email'      => $email,
        'phone'      => $phone,
        'status'     => $status,
        'city_id'    => $city_id,
        'updated_at' => current_time('mysql')
    ];

    // Update hub type if provided (Food Truck toggle)
    $allowed_types = ['Restaurant', 'Food Truck', 'Cottage Food'];
    if (!empty($data['type']) && in_array($data['type'], $allowed_types, true)) {
        $update_data['type'] = $data['type'];
    }

    // If no pivot table, preserve legacy single-column update
    $use_pivot = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($map_table)) );
    if (!$use_pivot) {
        $update_data['category_id'] = $category_id;
        $formats = ['%s', '%s', '%s', '%d', '%d', '%s'];
        $updated = $wpdb->update(
            $table_hubs,
            $update_data,
            ['id' => $hub_id],
            $formats,
            ['%d']
        );
    } else {
        // When using pivot, set legacy field to null (optional) and update other hub fields
        $update_data['category_id'] = null;
        $formats = ['%s', '%s', '%s', '%d', '%s'];
        $updated = $wpdb->update(
            $table_hubs,
            $update_data,
            ['id' => $hub_id],
            $formats,
            ['%d']
        );

        // Sync pivot if category_ids present
        if (is_array($category_ids)) {
            // Delete existing mappings for hub
            $wpdb->delete($map_table, ['hub_id' => $hub_id], ['%d']);

            // Insert new mappings
            $i = 0;
            foreach ($category_ids as $cid) {
                if ($cid <= 0) continue;
                $wpdb->insert($map_table, [
                    'hub_id' => $hub_id,
                    'category_id' => $cid,
                    'sort_order' => $i
                ], ['%d', '%d', '%d']);
                $i++;
            }
        }
    }

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
