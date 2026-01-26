<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Customers — List Customers (PHASE 2.BETA+)
 * ----------------------------------------------------------
 * Endpoint:
 * - GET /wp-json/knx/v2/admin/customers
 *
 * Security:
 * - Route-level: super_admin | manager
 * - Wrapped with knx_rest_wrap
 *
 * Returns:
 * - Paginated list of customers
 * - Supports search (name, email, phone)
 *
 * BLOQUE E1 — READ-ONLY (no edit, no delete, no toggle)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v2', '/admin/customers', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_v2_admin_list_customers')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

function knx_v2_admin_list_customers(WP_REST_Request $request) {
    global $wpdb;

    // Session + role required (defense-in-depth)
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $users_table = $wpdb->prefix . 'knx_users';

    // Pagination
    $page = max(1, intval($request->get_param('page')));
    $per_page = max(1, min(100, intval($request->get_param('per_page') ?: 20)));
    $offset = ($page - 1) * $per_page;

    // Search
    $search = sanitize_text_field($request->get_param('search'));

    // Base query
    $where = "role IN ('customer', 'user')";

    // Search filter
    if (!empty($search)) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= $wpdb->prepare(
            " AND (name LIKE %s OR email LIKE %s OR phone LIKE %s OR username LIKE %s)",
            $like, $like, $like, $like
        );
    }

    // Count total
    $total = $wpdb->get_var("SELECT COUNT(*) FROM {$users_table} WHERE {$where}");

    // Fetch customers
    $customers = $wpdb->get_results($wpdb->prepare(
        "SELECT id, username, name, phone, email, status, created_at
         FROM {$users_table}
         WHERE {$where}
         ORDER BY created_at DESC
         LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    return knx_rest_response(true, 'Customers list', [
        'customers' => $customers ?: [],
        'pagination' => [
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => (int) $total,
            'total_pages' => ceil($total / $per_page),
        ]
    ]);
}
