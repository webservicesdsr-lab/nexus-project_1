<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Reorder Hub Item (v2.5 - Canonical)
 * ----------------------------------------------------------
 * POST /wp-json/knx/v1/reorder-item
 * Body: hub_id, item_id, move (up|down), knx_nonce
 * Reorders within same category when possible, otherwise across all items in hub.
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/reorder-item', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_reorder_item_v25'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_api_reorder_item_v25(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_table('hub_items');

    $hub_id   = intval($r->get_param('hub_id'));
    $item_id  = intval($r->get_param('item_id'));
    $move     = sanitize_text_field($r->get_param('move'));
    $nonce    = sanitize_text_field($r->get_param('knx_nonce'));

    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }
    
    if (!$hub_id || !$item_id || !in_array($move, ['up','down'], true)) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_request'], 400);
    }

    $item = $wpdb->get_row($wpdb->prepare("
        SELECT id, category_id, sort_order 
        FROM {$table} 
        WHERE id = %d AND hub_id = %d
        LIMIT 1
    ", $item_id, $hub_id));

    if (!$item) {
        return new WP_REST_Response(['success' => false, 'error' => 'item_not_found'], 404);
    }

    $operator = ($move === 'up') ? '<' : '>';
    $order    = ($move === 'up') ? 'DESC' : 'ASC';

    $neighbor = null;
    if (!empty($item->category_id)) {
        $neighbor = $wpdb->get_row($wpdb->prepare("
            SELECT id, sort_order
            FROM {$table}
            WHERE hub_id = %d AND category_id = %d AND sort_order {$operator} %d
            ORDER BY sort_order {$order}
            LIMIT 1
        ", $hub_id, $item->category_id, $item->sort_order));
    }

    if (!$neighbor) {
        $neighbor = $wpdb->get_row($wpdb->prepare("
            SELECT id, sort_order
            FROM {$table}
            WHERE hub_id = %d AND sort_order {$operator} %d
            ORDER BY sort_order {$order}
            LIMIT 1
        ", $hub_id, $item->sort_order));
        
        if (!$neighbor) {
            return new WP_REST_Response(['success' => false, 'error' => 'no_neighbor'], 400);
        }
    }

    $wpdb->query('START TRANSACTION');
    try {
        $wpdb->update($table, ['sort_order' => (int)$neighbor->sort_order], ['id' => (int)$item->id], ['%d'], ['%d']);
        $wpdb->update($table, ['sort_order' => (int)$item->sort_order], ['id' => (int)$neighbor->id], ['%d'], ['%d']);
        $wpdb->query('COMMIT');
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['success' => false, 'error' => 'transaction_failed'], 500);
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Item reordered successfully',
        'moved'   => $move,
        'item_id' => (int) $item_id
    ], 200);
}
