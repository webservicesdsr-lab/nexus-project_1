<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Delete Item Category (v1.2 - Canonical)
 * ----------------------------------------------------------
 * ✅ REST Real
 * ✅ Deletes category by ID and reorders automatically
 * ✅ Safe rollback on failure
 * ✅ Portable with dynamic prefix (Z7E_ / default)
 * Route: POST /wp-json/knx/v1/delete-item-category
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/delete-item-category', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_delete_item_category'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_api_delete_item_category(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_items_categories_table();

    $hub_id = intval($r->get_param('hub_id'));
    $id     = intval($r->get_param('category_id'));
    $nonce  = sanitize_text_field($r->get_param('knx_nonce'));

    if (!$hub_id || !$id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_parameters'], 400);
    }

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $wpdb->query('START TRANSACTION');

    try {
        $deleted = $wpdb->delete($table, ['id' => $id, 'hub_id' => $hub_id], ['%d', '%d']);

        if ($deleted === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['success' => false, 'error' => 'db_delete_failed'], 500);
        }

        $remaining = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM $table 
            WHERE hub_id = %d 
            ORDER BY sort_order ASC, id ASC
        ", $hub_id));

        $i = 1;
        foreach ($remaining as $c) {
            $wpdb->update($table, ['sort_order' => $i], ['id' => $c->id], ['%d'], ['%d']);
            $i++;
        }

        $wpdb->query('COMMIT');
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['success' => false, 'error' => 'transaction_failed'], 500);
    }

    return new WP_REST_Response([
        'success'    => true,
        'message'    => 'Category deleted and sort_order normalized',
        'hub_id'     => $hub_id,
        'deleted_id' => $id
    ], 200);
}
