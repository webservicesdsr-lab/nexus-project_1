<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Coupons CRUD API (PHASE 3.3 + ADMIN)
 * ----------------------------------------------------------
 * Endpoints:
 *   GET    /knx/v2/coupons/list
 *   POST   /knx/v2/coupons/create
 *   POST   /knx/v2/coupons/update
 *   POST   /knx/v2/coupons/toggle
 *
 * Access: super_admin, manager only
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v2', '/coupons/list', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_coupons_list'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v2', '/coupons/create', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_coupons_create'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v2', '/coupons/update', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_coupons_update'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v2', '/coupons/toggle', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_coupons_toggle'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * List coupons with search/pagination
 */
function knx_v2_coupons_list(WP_REST_Request $req) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'knx_coupons';
    
    // Pagination
    $page = max(1, (int) $req->get_param('page'));
    $per_page = max(1, min(100, (int) ($req->get_param('per_page') ?: 20)));
    $offset = ($page - 1) * $per_page;
    
    // Filters
    $q = sanitize_text_field($req->get_param('q'));
    $status = sanitize_text_field($req->get_param('status'));
    
    // Build WHERE
    $where = ['1=1'];
    $where_values = [];
    
    if ($q) {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where[] = "code LIKE %s";
        $where_values[] = $like;
    }
    
    if ($status && in_array($status, ['active', 'inactive'], true)) {
        $where[] = "status = %s";
        $where_values[] = $status;
    }
    
    $where_sql = implode(' AND ', $where);
    
    // Count total
    $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    if ($where_values) {
        $count_query = $wpdb->prepare($count_query, ...$where_values);
    }
    $total = (int) $wpdb->get_var($count_query);
    
    // Fetch coupons
    $query = "SELECT *
              FROM {$table}
              WHERE {$where_sql}
              ORDER BY created_at DESC
              LIMIT %d OFFSET %d";
    
    $query_values = array_merge($where_values, [$per_page, $offset]);
    $coupons = $wpdb->get_results($wpdb->prepare($query, ...$query_values));
    
    return knx_rest_response(true, 'Coupons list', [
        'coupons' => $coupons ?: [],
        'pagination' => [
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => ceil($total / $per_page),
        ]
    ]);
}

/**
 * Create new coupon
 */
function knx_v2_coupons_create(WP_REST_Request $req) {
    global $wpdb;
    
    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }
    
    $table = $wpdb->prefix . 'knx_coupons';
    
    // Required fields
    $code = isset($body['code']) ? strtoupper(trim(sanitize_text_field($body['code']))) : '';
    $type = isset($body['type']) ? sanitize_text_field($body['type']) : 'percent';
    $value = isset($body['value']) ? (float) $body['value'] : 0.00;
    
    // Optional fields
    $min_subtotal = isset($body['min_subtotal']) && $body['min_subtotal'] !== '' ? (float) $body['min_subtotal'] : null;
    $status = isset($body['status']) ? sanitize_text_field($body['status']) : 'active';
    $starts_at = isset($body['starts_at']) && $body['starts_at'] ? sanitize_text_field($body['starts_at']) : null;
    $expires_at = isset($body['expires_at']) && $body['expires_at'] ? sanitize_text_field($body['expires_at']) : null;
    $usage_limit = isset($body['usage_limit']) && $body['usage_limit'] !== '' ? (int) $body['usage_limit'] : null;
    
    // Validation
    if (empty($code)) {
        return knx_rest_response(false, 'Coupon code is required.', null, 400);
    }
    
    if (!in_array($type, ['percent', 'fixed'], true)) {
        return knx_rest_response(false, 'Invalid type. Must be percent or fixed.', null, 400);
    }
    
    if ($value < 0) {
        return knx_rest_response(false, 'Value cannot be negative.', null, 400);
    }
    
    if ($type === 'percent' && $value > 100) {
        return knx_rest_response(false, 'Percent value cannot exceed 100.', null, 400);
    }
    
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }
    
    if ($usage_limit !== null && $usage_limit < 0) {
        return knx_rest_response(false, 'Usage limit cannot be negative.', null, 400);
    }
    
    if ($starts_at && $expires_at && strtotime($expires_at) <= strtotime($starts_at)) {
        return knx_rest_response(false, 'Expiration date must be after start date.', null, 400);
    }
    
    // Check code uniqueness (case-insensitive)
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE UPPER(code) = %s",
        $code
    ));
    
    if ($exists) {
        return knx_rest_response(false, 'Coupon code already exists.', null, 409);
    }
    
    // Insert
    $now = current_time('mysql');
    $inserted = $wpdb->insert($table, [
        'code'         => $code,
        'type'         => $type,
        'value'        => $value,
        'min_subtotal' => $min_subtotal,
        'status'       => $status,
        'starts_at'    => $starts_at,
        'expires_at'   => $expires_at,
        'usage_limit'  => $usage_limit,
        'used_count'   => 0,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    
    if (!$inserted) {
        return knx_rest_response(false, 'Failed to create coupon.', null, 500);
    }
    
    return knx_rest_response(true, 'Coupon created successfully.', [
        'coupon_id' => (int) $wpdb->insert_id,
    ], 201);
}

/**
 * Update existing coupon
 */
function knx_v2_coupons_update(WP_REST_Request $req) {
    global $wpdb;
    
    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }
    
    $table = $wpdb->prefix . 'knx_coupons';
    $coupon_id = isset($body['coupon_id']) ? (int) $body['coupon_id'] : 0;
    
    if ($coupon_id <= 0) {
        return knx_rest_response(false, 'Valid coupon_id is required.', null, 400);
    }
    
    // Check existence
    $coupon = $wpdb->get_row($wpdb->prepare(
        "SELECT id, code FROM {$table} WHERE id = %d LIMIT 1",
        $coupon_id
    ));
    
    if (!$coupon) {
        return knx_rest_response(false, 'Coupon not found.', null, 404);
    }
    
    // Build update data
    $data = ['updated_at' => current_time('mysql')];
    
    if (isset($body['code'])) {
        $code = strtoupper(trim(sanitize_text_field($body['code'])));
        if ($code !== $coupon->code) {
            // Check uniqueness
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE UPPER(code) = %s AND id != %d",
                $code,
                $coupon_id
            ));
            
            if ($exists) {
                return knx_rest_response(false, 'Coupon code already exists.', null, 409);
            }
            
            $data['code'] = $code;
        }
    }
    
    if (isset($body['type']) && in_array($body['type'], ['percent', 'fixed'], true)) {
        $data['type'] = $body['type'];
    }
    
    if (isset($body['value'])) {
        $value = (float) $body['value'];
        if ($value < 0) {
            return knx_rest_response(false, 'Value cannot be negative.', null, 400);
        }
        $data['value'] = $value;
    }
    
    if (isset($body['min_subtotal'])) {
        $data['min_subtotal'] = $body['min_subtotal'] !== '' ? (float) $body['min_subtotal'] : null;
    }
    
    if (isset($body['status']) && in_array($body['status'], ['active', 'inactive'], true)) {
        $data['status'] = $body['status'];
    }
    
    if (isset($body['starts_at'])) {
        $data['starts_at'] = $body['starts_at'] ? sanitize_text_field($body['starts_at']) : null;
    }
    
    if (isset($body['expires_at'])) {
        $data['expires_at'] = $body['expires_at'] ? sanitize_text_field($body['expires_at']) : null;
    }
    
    if (isset($body['usage_limit'])) {
        $data['usage_limit'] = $body['usage_limit'] !== '' ? (int) $body['usage_limit'] : null;
    }
    
    // Update
    $updated = $wpdb->update($table, $data, ['id' => $coupon_id]);
    
    if ($updated === false) {
        return knx_rest_response(false, 'Failed to update coupon.', null, 500);
    }
    
    return knx_rest_response(true, 'Coupon updated successfully.');
}

/**
 * Toggle coupon status (active <-> inactive)
 */
function knx_v2_coupons_toggle(WP_REST_Request $req) {
    global $wpdb;
    
    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }
    
    $table = $wpdb->prefix . 'knx_coupons';
    $coupon_id = isset($body['coupon_id']) ? (int) $body['coupon_id'] : 0;
    
    if ($coupon_id <= 0) {
        return knx_rest_response(false, 'Valid coupon_id is required.', null, 400);
    }
    
    // Fetch current status
    $status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$table} WHERE id = %d LIMIT 1",
        $coupon_id
    ));
    
    if (!$status) {
        return knx_rest_response(false, 'Coupon not found.', null, 404);
    }
    
    $new_status = $status === 'active' ? 'inactive' : 'active';
    
    $updated = $wpdb->update(
        $table,
        ['status' => $new_status, 'updated_at' => current_time('mysql')],
        ['id' => $coupon_id]
    );
    
    if ($updated === false) {
        return knx_rest_response(false, 'Failed to toggle status.', null, 500);
    }
    
    return knx_rest_response(true, 'Coupon status updated.', [
        'status' => $new_status,
    ]);
}
