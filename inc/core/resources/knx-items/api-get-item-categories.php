<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Get Item Categories (v1.3 - Canonical)
 * ----------------------------------------------------------
 * ✅ 100% REST Real
 * ✅ Returns categories sorted by sort_order ASC
 * ✅ Auto-repair for missing sort_order values
 * ✅ Works with dynamic prefix (Z7E_ / default)
 * Route: GET /wp-json/knx/v1/get-item-categories
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/get-item-categories', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_get_item_categories'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_api_get_item_categories(WP_REST_Request $r) {
    global $wpdb;

    $table = knx_items_categories_table();

    $hub_id = intval($r->get_param('hub_id'));
    if (!$hub_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_hub_id'], 400);
    }

    $categories = $wpdb->get_results($wpdb->prepare("
        SELECT id, hub_id, name, sort_order, status, created_at, updated_at
        FROM $table
        WHERE hub_id = %d
        ORDER BY sort_order ASC, id ASC
    ", $hub_id));

    $needs_fix = false;
    foreach ($categories as $c) {
        if ($c->sort_order == 0) { 
            $needs_fix = true; 
            break; 
        }
    }

    if ($needs_fix) {
        $i = 1;
        foreach ($categories as $c) {
            $wpdb->update($table, ['sort_order' => $i], ['id' => $c->id], ['%d'], ['%d']);
            $c->sort_order = $i;
            $i++;
        }
    }

    return new WP_REST_Response([
        'success'    => true,
        'hub_id'     => $hub_id,
        'total'      => count($categories),
        'categories' => $categories
    ], 200);
}
