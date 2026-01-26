<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Toggle Item Category (v1.0 - Canonical)
 * ----------------------------------------------------------
 * ✅ 100% REST Real
 * ✅ Uses knx_edit_hub_nonce for validation
 * ✅ Safe toggle between active/inactive
 * ✅ Portable with dynamic prefix (Z7E_ / default)
 * Route: POST /wp-json/knx/v1/toggle-item-category
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/toggle-item-category', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_toggle_item_category'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_api_toggle_item_category(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_items_categories_table();

    $hub_id      = intval($r->get_param('hub_id'));
    $category_id = intval($r->get_param('category_id'));
    $status      = sanitize_text_field($r->get_param('status'));
    $nonce       = sanitize_text_field($r->get_param('knx_nonce'));

    if (!$hub_id || !$category_id || !in_array($status, ['active', 'inactive'])) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_request'], 400);
    }

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE id=%d AND hub_id=%d",
        $category_id, $hub_id
    ));
    
    if (!$exists) {
        return new WP_REST_Response(['success' => false, 'error' => 'category_not_found'], 404);
    }

    $updated = $wpdb->update(
        $table,
        [
            'status'     => $status,
            'updated_at' => current_time('mysql')
        ],
        ['id' => $category_id, 'hub_id' => $hub_id],
        ['%s', '%s'],
        ['%d', '%d']
    );

    if ($updated === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'db_update_failed'], 500);
    }

    return new WP_REST_Response([
        'success'     => true,
        'message'     => "Category status updated successfully",
        'category_id' => $category_id,
        'hub_id'      => $hub_id,
        'new_status'  => $status
    ], 200);
}
