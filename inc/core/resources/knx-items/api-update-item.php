<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Update Hub Item (v1.0 - Canonical)
 * ----------------------------------------------------------
 * ✅ Updates item fields (name, desc, price, category, image)
 * ✅ Get item details for editing
 * ✅ REST Real
 * ✅ Secure nonce verification
 * ✅ Portable prefix (Z7E_ / default)
 * ✅ Compatible with edit-item.js
 * Routes:
 *   - GET  /wp-json/knx/v1/get-item-details
 *   - POST /wp-json/knx/v1/update-item
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/get-item-details', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_get_item_details'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/update-item', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_update_item'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

/**
 * ==========================================================
 * GET Item Details
 * ==========================================================
 */
function knx_api_get_item_details(WP_REST_Request $r) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hub_items';

    $hub_id  = intval($r->get_param('hub_id'));
    $item_id = intval($r->get_param('id'));

    if (!$hub_id || !$item_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_parameters'], 400);
    }

    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE id=%d AND hub_id=%d LIMIT 1",
        $item_id, $hub_id
    ));

    if (!$item) {
        return new WP_REST_Response(['success' => false, 'error' => 'item_not_found'], 404);
    }

    return new WP_REST_Response(['success' => true, 'item' => $item], 200);
}

/**
 * ==========================================================
 * Update Item
 * ==========================================================
 */
function knx_api_update_item(WP_REST_Request $r) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hub_items';

    $hub_id      = intval($r->get_param('hub_id'));
    $id          = intval($r->get_param('id'));
    $name        = sanitize_text_field($r->get_param('name'));
    $desc        = sanitize_textarea_field($r->get_param('description'));
    $category_id = intval($r->get_param('category_id'));
    $price       = floatval($r->get_param('price'));
    $status      = sanitize_text_field($r->get_param('status'));
    $nonce       = sanitize_text_field($r->get_param('knx_nonce'));

    if (!$hub_id || !$id || !$name) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_parameters'], 400);
    }

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE id=%d AND hub_id=%d LIMIT 1",
        $id, $hub_id
    ));
    
    if (!$current) {
        return new WP_REST_Response(['success' => false, 'error' => 'item_not_found'], 404);
    }

    $image_url = $current->image_url;
    if (!empty($_FILES['item_image']) && $_FILES['item_image']['size'] > 0) {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']) . 'knx-items/' . $hub_id . '/';
        $base_url = trailingslashit($upload_dir['baseurl']) . 'knx-items/' . $hub_id . '/';

        if (!file_exists($base_dir)) wp_mkdir_p($base_dir);
        if (!file_exists($base_dir . 'index.html')) file_put_contents($base_dir . 'index.html', '');

        $file = $_FILES['item_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file['type'], $allowed)) {
            return new WP_REST_Response(['success' => false, 'error' => 'invalid_image_type'], 400);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $new_name = uniqid('item_', true) . '.' . $ext;
        $target = $base_dir . $new_name;

        if (move_uploaded_file($file['tmp_name'], $target)) {
            $image_url = $base_url . $new_name;
        }
    }

    $updated = $wpdb->update(
        $table,
        [
            'name'        => $name,
            'description' => $desc,
            'category_id' => $category_id,
            'price'       => $price,
            'status'      => in_array($status, ['active', 'inactive']) ? $status : 'active',
            'image_url'   => esc_url_raw($image_url),
            'updated_at'  => current_time('mysql'),
        ],
        ['id' => $id, 'hub_id' => $hub_id],
        ['%s', '%s', '%d', '%f', '%s', '%s', '%s'],
        ['%d', '%d']
    );

    if ($updated === false) {
        return new WP_REST_Response(['success' => false, 'error' => 'db_update_failed'], 500);
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Item updated successfully',
        'id'      => $id,
        'hub_id'  => $hub_id,
        'image'   => $image_url
    ], 200);
}
