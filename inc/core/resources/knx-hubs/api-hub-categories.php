<?php
// File: inc/core/resources/knx-hubs/api-hub-categories.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - API: Hub Categories (v1.1 - Canonical)
 * ----------------------------------------------------------
 * CRUD (Cities-style):
 * - GET    /knx/v1/get-hub-categories
 * - POST   /knx/v1/add-hub-category
 * - POST   /knx/v1/toggle-hub-category
 * - POST   /knx/v1/update-hub-category
 * - POST   /knx/v1/delete-hub-category
 *
 * Rules:
 * - Always wrapped with knx_rest_wrap()
 * - Permission: knx_rest_permission_session()
 * - Nonces required for write ops
 * - Prepared statements / sanitized inputs
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v1', '/get-hub-categories', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_api_get_hub_categories'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/add-hub-category', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_api_add_hub_category'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    register_rest_route('knx/v1', '/toggle-hub-category', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_api_toggle_hub_category'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // NEW: Update name
    register_rest_route('knx/v1', '/update-hub-category', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_api_update_hub_category'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // NEW: Hard delete
    register_rest_route('knx/v1', '/delete-hub-category', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_api_delete_hub_category'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

/**
 * Get all hub categories (ordered by sort_order ASC, then id DESC)
 */
function knx_api_get_hub_categories() {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hub_categories';

    $sql  = "SELECT id, name, status, sort_order, created_at, updated_at
             FROM {$table}
             ORDER BY sort_order ASC, id DESC";
    $rows = $wpdb->get_results($sql);

    return new WP_REST_Response([
        'success' => true,
        'categories' => $rows ?: [],
    ], 200);
}

/**
 * Add a new hub category
 */
function knx_api_add_hub_category(WP_REST_Request $r) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hub_categories';

    $name  = sanitize_text_field($r['name']);
    $nonce = sanitize_text_field($r['knx_nonce']);

    if (!wp_verify_nonce($nonce, 'knx_add_hub_category_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    if (empty($name)) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_name'], 400);
    }

    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE name = %s",
        $name
    ));
    if ($exists) {
        return new WP_REST_Response(['success' => false, 'error' => 'duplicate_category'], 409);
    }

    $next_sort = (int) $wpdb->get_var("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table}");

    $now = current_time('mysql');

    $insert = $wpdb->insert($table, [
        'name'       => $name,
        'status'     => 'active',
        'sort_order' => $next_sort,
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%s', '%s', '%d', '%s', '%s']);

    if (!$insert) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'db_error',
            'message' => 'Database error',
        ], 500);
    }

    $id = (int) $wpdb->insert_id;

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Category added',
        'category' => [
            'id' => $id,
            'name' => $name,
            'status' => 'active',
            'sort_order' => $next_sort,
        ],
    ], 200);
}

/**
 * Toggle a hub category active/inactive
 * Expected JSON:
 * { "id": 12, "status": "active|inactive", "knx_nonce": "..." }
 */
function knx_api_toggle_hub_category(WP_REST_Request $r) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hub_categories';

    $data   = json_decode($r->get_body(), true);
    $id     = (int) ($data['id'] ?? 0);
    $status = sanitize_text_field($data['status'] ?? 'active');
    $nonce  = sanitize_text_field($data['knx_nonce'] ?? '');

    if (!wp_verify_nonce($nonce, 'knx_toggle_hub_category_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    if ($id <= 0) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_id'], 400);
    }

    $status = (strtolower($status) === 'inactive') ? 'inactive' : 'active';

    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE id = %d",
        $id
    ));
    if (!$exists) {
        return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);
    }

    $ok = $wpdb->update($table, [
        'status' => $status,
        'updated_at' => current_time('mysql'),
    ], [
        'id' => $id,
    ], ['%s', '%s'], ['%d']);

    return new WP_REST_Response([
        'success' => ($ok !== false),
        'message' => ($ok === false) ? 'Database error' : 'Category status updated',
        'status'  => $status,
    ], ($ok === false) ? 500 : 200);
}

/**
 * Update hub category name
 * Expected JSON:
 * { "id": 12, "name": "New Name", "knx_nonce": "..." }
 */
function knx_api_update_hub_category(WP_REST_Request $r) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hub_categories';

    $data  = json_decode($r->get_body(), true);
    $id    = (int) ($data['id'] ?? 0);
    $name  = sanitize_text_field($data['name'] ?? '');
    $nonce = sanitize_text_field($data['knx_nonce'] ?? '');

    if (!wp_verify_nonce($nonce, 'knx_update_hub_category_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    if ($id <= 0) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_id'], 400);
    }

    if (empty($name)) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_name'], 400);
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name FROM {$table} WHERE id = %d",
        $id
    ));

    if (!$row) {
        return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);
    }

    $dup = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE name = %s AND id != %d",
        $name,
        $id
    ));
    if ($dup) {
        return new WP_REST_Response(['success' => false, 'error' => 'duplicate_category'], 409);
    }

    $ok = $wpdb->update($table, [
        'name' => $name,
        'updated_at' => current_time('mysql'),
    ], [
        'id' => $id,
    ], ['%s', '%s'], ['%d']);

    return new WP_REST_Response([
        'success' => ($ok !== false),
        'message' => ($ok === false) ? 'Database error' : 'Category updated',
        'category' => [
            'id' => $id,
            'name' => $name,
        ],
    ], ($ok === false) ? 500 : 200);
}

/**
 * Hard delete hub category
 * Expected JSON:
 * { "id": 12, "knx_nonce": "..." }
 */
function knx_api_delete_hub_category(WP_REST_Request $r) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_hub_categories';

    $data  = json_decode($r->get_body(), true);
    $id    = (int) ($data['id'] ?? 0);
    $nonce = sanitize_text_field($data['knx_nonce'] ?? '');

    if (!wp_verify_nonce($nonce, 'knx_delete_hub_category_nonce')) {
        return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
    }

    if ($id <= 0) {
        return new WP_REST_Response(['success' => false, 'error' => 'missing_id'], 400);
    }

    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE id = %d",
        $id
    ));
    if (!$exists) {
        return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);
    }

    $ok = $wpdb->delete($table, ['id' => $id], ['%d']);

    return new WP_REST_Response([
        'success' => ($ok !== false),
        'message' => ($ok === false) ? 'Database error' : 'Category deleted',
        'deleted_id' => $id,
    ], ($ok === false) ? 500 : 200);
}
