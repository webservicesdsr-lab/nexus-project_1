<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Save Item Category (v1.4 - Canonical)
 * ----------------------------------------------------------
 * ✅ 100% REST Real
 * ✅ Add or Update category
 * ✅ Auto-assigns next sort_order if missing
 * ✅ Prevents duplicates (by name per hub)
 * ✅ Compatible with dynamic prefix (Z7E_ / default)
 * Route: POST /wp-json/knx/v1/save-item-category
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/save-item-category', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_save_item_category'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_api_save_item_category(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_items_categories_table();

    $hub_id = intval($r->get_param('hub_id'));
    $id     = intval($r->get_param('id'));
    $name   = sanitize_text_field($r->get_param('name'));
    $nonce  = sanitize_text_field($r->get_param('knx_nonce'));

    if (!$hub_id || !$name) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_parameters'], 400);
    }

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT id FROM $table WHERE hub_id = %d AND name = %s AND id != %d
    ", $hub_id, $name, $id));

    if ($exists) {
        return new WP_REST_Response(['success' => false, 'error' => 'Category name already exists'], 409);
    }

    $next_order = $wpdb->get_var($wpdb->prepare("
        SELECT COALESCE(MAX(sort_order), 0) + 1 FROM $table WHERE hub_id = %d
    ", $hub_id));

    if ($id > 0) {
        $updated = $wpdb->update(
            $table,
            [
                'name'        => $name,
                'updated_at'  => current_time('mysql')
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_REST_Response(['success' => false, 'error' => 'db_update_failed'], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Category updated successfully',
            'id'      => $id,
            'hub_id'  => $hub_id
        ], 200);
    }

    $inserted = $wpdb->insert(
        $table,
        [
            'hub_id'      => $hub_id,
            'name'        => $name,
            'sort_order'  => $next_order,
            'status'      => 'active',
            'created_at'  => current_time('mysql'),
            'updated_at'  => current_time('mysql')
        ],
        ['%d', '%s', '%d', '%s', '%s', '%s']
    );

    if ($inserted === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'db_insert_failed'], 500);
    }

    return new WP_REST_Response([
        'success'    => true,
        'message'    => 'Category added successfully',
        'id'         => $wpdb->insert_id,
        'hub_id'     => $hub_id,
        'sort_order' => $next_order
    ], 200);
}
