<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Item Addons (v1.0 - Canonical)
 * ----------------------------------------------------------
 * Vincular addon groups a items específicos
 * Routes:
 *   - GET  /wp-json/knx/v1/get-item-addon-groups
 *   - POST /wp-json/knx/v1/assign-addon-group-to-item
 *   - POST /wp-json/knx/v1/remove-addon-group-from-item
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v1', '/get-item-addon-groups', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_get_item_addon_groups'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/assign-addon-group-to-item', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_assign_addon_group_to_item'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager', 'hub_management', 'menu_uploader']),
    ]);

    register_rest_route('knx/v1', '/remove-addon-group-from-item', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_remove_addon_group_from_item'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager', 'hub_management', 'menu_uploader']),
    ]);
});

/**
 * ==========================================================
 * GET grupos asignados a un item (con addons incluidos)
 * ==========================================================
 */
function knx_api_get_item_addon_groups(WP_REST_Request $r) {
    global $wpdb;
    
    $rel_table    = $wpdb->prefix . 'knx_item_addon_groups';
    $groups_table = $wpdb->prefix . 'knx_addon_groups';
    $addons_table = $wpdb->prefix . 'knx_addons';

    $item_id = intval($r->get_param('item_id'));
    if (!$item_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_item_id'], 400);
    }

    $groups = $wpdb->get_results($wpdb->prepare("
        SELECT g.*
        FROM {$groups_table} g
        INNER JOIN {$rel_table} r ON r.group_id = g.id
        WHERE r.item_id = %d
        ORDER BY g.sort_order ASC, g.name ASC
    ", $item_id));

    foreach ($groups as $group) {
        $group->addons = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$addons_table}
            WHERE group_id = %d AND status = 'active'
            ORDER BY sort_order ASC, name ASC
        ", $group->id));
    }

    return new WP_REST_Response(['success' => true, 'groups' => $groups ?: []], 200);
}

/**
 * ==========================================================
 * POST asignar addon group a item
 * ==========================================================
 */
function knx_api_assign_addon_group_to_item(WP_REST_Request $r) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'knx_item_addon_groups';

    $nonce = sanitize_text_field($r->get_param('knx_nonce'));
    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $item_id  = intval($r->get_param('item_id'));
    $group_id = intval($r->get_param('group_id'));

    if (!$item_id || !$group_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_fields'], 400);
    }

    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$table}
        WHERE item_id = %d AND group_id = %d
    ", $item_id, $group_id));

    if ($exists) {
        return new WP_REST_Response(['success' => false, 'error' => 'already_assigned'], 400);
    }

    $wpdb->insert($table, [
        'item_id'  => $item_id,
        'group_id' => $group_id,
    ], ['%d', '%d']);

    return new WP_REST_Response(['success' => true, 'message' => 'Addon group assigned'], 200);
}

/**
 * ==========================================================
 * POST remover addon group de item
 * ==========================================================
 */
function knx_api_remove_addon_group_from_item(WP_REST_Request $r) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'knx_item_addon_groups';

    $nonce = sanitize_text_field($r->get_param('knx_nonce'));
    if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    $item_id  = intval($r->get_param('item_id'));
    $group_id = intval($r->get_param('group_id'));

    if (!$item_id || !$group_id) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_fields'], 400);
    }

    $wpdb->delete($table, [
        'item_id'  => $item_id,
        'group_id' => $group_id,
    ], ['%d', '%d']);

    return new WP_REST_Response(['success' => true, 'message' => 'Addon group removed'], 200);
}
