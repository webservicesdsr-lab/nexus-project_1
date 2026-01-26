<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Customers CRUD API (PHASE 2.1)
 * ----------------------------------------------------------
 * Endpoints:
 *   GET    /knx/v2/customers/list
 *   POST   /knx/v2/customers/create
 *   POST   /knx/v2/customers/update
 *   POST   /knx/v2/customers/toggle
 *   GET    /knx/v2/customers/get
 *
 * Access: super_admin, manager only
 * Target table: knx_users (role IN 'customer', 'user')
 * ==========================================================
 */

add_action('rest_api_init', function() {
    register_rest_route('knx/v2', '/customers/list', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_customers_list'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v2', '/customers/create', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_customers_create'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v2', '/customers/update', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_customers_update'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v2', '/customers/toggle', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_customers_toggle'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
    
    register_rest_route('knx/v2', '/customers/get', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_customers_get'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * List customers with search/pagination
 */
function knx_v2_customers_list(WP_REST_Request $req) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'knx_users';
    
    // Pagination
    $page = max(1, (int) $req->get_param('page'));
    $per_page = max(1, min(100, (int) ($req->get_param('per_page') ?: 20)));
    $offset = ($page - 1) * $per_page;
    
    // Filters
    $q = sanitize_text_field($req->get_param('q'));
    $status = sanitize_text_field($req->get_param('status'));
    
    // Build WHERE
    $where = ["role IN ('customer', 'user')"];
    $where_values = [];
    
    if ($q) {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where[] = "(name LIKE %s OR email LIKE %s OR phone LIKE %s OR username LIKE %s)";
        $where_values[] = $like;
        $where_values[] = $like;
        $where_values[] = $like;
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
    
    // Fetch customers
    $query = "SELECT id, username, name, phone, email, status, created_at
              FROM {$table}
              WHERE {$where_sql}
              ORDER BY created_at DESC
              LIMIT %d OFFSET %d";
    
    $query_values = array_merge($where_values, [$per_page, $offset]);
    $customers = $wpdb->get_results($wpdb->prepare($query, ...$query_values));
    
    return knx_rest_response(true, 'Customers list', [
        'customers' => $customers ?: [],
        'pagination' => [
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => ceil($total / $per_page),
        ]
    ]);
}

/**
 * Create new customer
 */
function knx_v2_customers_create(WP_REST_Request $req) {
    global $wpdb;
    
    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }
    
    $table = $wpdb->prefix . 'knx_users';
    
    // Required fields
    $email = isset($body['email']) ? sanitize_email($body['email']) : '';
    $name = isset($body['name']) ? sanitize_text_field($body['name']) : '';
    $phone = isset($body['phone']) ? sanitize_text_field($body['phone']) : '';
    
    // Optional fields
    $username = isset($body['username']) ? sanitize_user($body['username']) : '';
    $password = isset($body['password']) ? sanitize_text_field($body['password']) : '';
    $status = isset($body['status']) ? sanitize_text_field($body['status']) : 'active';
    $role = isset($body['role']) ? sanitize_text_field($body['role']) : 'customer';
    
    // Validation
    if (empty($email) || !is_email($email)) {
        return knx_rest_response(false, 'Valid email is required.', null, 400);
    }
    
    if (empty($name)) {
        return knx_rest_response(false, 'Name is required.', null, 400);
    }
    
    if (empty($phone)) {
        return knx_rest_response(false, 'Phone is required.', null, 400);
    }
    
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }
    
    if (!in_array($role, ['customer', 'user', 'manager', 'super_admin'], true)) {
        $role = 'customer';
    }
    
    // Check email uniqueness
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE email = %s",
        $email
    ));
    
    if ($exists) {
        return knx_rest_response(false, 'Email already exists.', null, 409);
    }
    
    // Check username uniqueness (if provided)
    if ($username) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE username = %s",
            $username
        ));
        
        if ($exists) {
            return knx_rest_response(false, 'Username already exists.', null, 409);
        }
    } else {
        // Auto-generate username from email
        $username = sanitize_user(explode('@', $email)[0] . '_' . wp_generate_password(6, false));
    }
    
    // Hash password (if not provided, generate random)
    if (empty($password)) {
        $password = wp_generate_password(12, true);
    }
    $hashed = password_hash($password, PASSWORD_BCRYPT);
    
    // Insert
    $now = current_time('mysql');
    $inserted = $wpdb->insert($table, [
        'username'   => $username,
        'email'      => $email,
        'password'   => $hashed,
        'name'       => $name,
        'phone'      => $phone,
        'role'       => $role,
        'status'     => $status,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    
    if (!$inserted) {
        return knx_rest_response(false, 'Failed to create customer.', null, 500);
    }
    
    return knx_rest_response(true, 'Customer created successfully.', [
        'customer_id' => (int) $wpdb->insert_id,
    ], 201);
}

/**
 * Update existing customer
 */
function knx_v2_customers_update(WP_REST_Request $req) {
    global $wpdb;
    
    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }
    
    $table = $wpdb->prefix . 'knx_users';
    $user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
    
    if ($user_id <= 0) {
        return knx_rest_response(false, 'Valid user_id is required.', null, 400);
    }
    
    // Check existence
    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT id, email, username FROM {$table} WHERE id = %d LIMIT 1",
        $user_id
    ));
    
    if (!$user) {
        return knx_rest_response(false, 'Customer not found.', null, 404);
    }
    
    // Build update data
    $data = ['updated_at' => current_time('mysql')];
    
    if (isset($body['name'])) {
        $data['name'] = sanitize_text_field($body['name']);
    }
    
    if (isset($body['phone'])) {
        $data['phone'] = sanitize_text_field($body['phone']);
    }
    
    if (isset($body['email'])) {
        $email = sanitize_email($body['email']);
        if (is_email($email) && $email !== $user->email) {
            // Check uniqueness
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE email = %s AND id != %d",
                $email,
                $user_id
            ));
            
            if ($exists) {
                return knx_rest_response(false, 'Email already exists.', null, 409);
            }
            
            $data['email'] = $email;
        }
    }
    
    if (isset($body['status']) && in_array($body['status'], ['active', 'inactive'], true)) {
        $data['status'] = $body['status'];
    }
    
    if (isset($body['password']) && !empty($body['password'])) {
        $data['password'] = password_hash(sanitize_text_field($body['password']), PASSWORD_BCRYPT);
    }
    
    // Update
    $updated = $wpdb->update($table, $data, ['id' => $user_id]);
    
    if ($updated === false) {
        return knx_rest_response(false, 'Failed to update customer.', null, 500);
    }
    
    return knx_rest_response(true, 'Customer updated successfully.');
}

/**
 * Toggle customer status (active <-> inactive)
 */
function knx_v2_customers_toggle(WP_REST_Request $req) {
    global $wpdb;
    
    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'Invalid request format.', null, 400);
    }
    
    $table = $wpdb->prefix . 'knx_users';
    $user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
    
    if ($user_id <= 0) {
        return knx_rest_response(false, 'Valid user_id is required.', null, 400);
    }
    
    // Fetch current status
    $status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$table} WHERE id = %d LIMIT 1",
        $user_id
    ));
    
    if (!$status) {
        return knx_rest_response(false, 'Customer not found.', null, 404);
    }
    
    $new_status = $status === 'active' ? 'inactive' : 'active';
    
    $updated = $wpdb->update(
        $table,
        ['status' => $new_status, 'updated_at' => current_time('mysql')],
        ['id' => $user_id]
    );
    
    if ($updated === false) {
        return knx_rest_response(false, 'Failed to toggle status.', null, 500);
    }
    
    return knx_rest_response(true, 'Customer status updated.', [
        'status' => $new_status,
    ]);
}

/**
 * Get single customer
 */
function knx_v2_customers_get(WP_REST_Request $req) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'knx_users';
    $user_id = (int) $req->get_param('user_id');
    
    if ($user_id <= 0) {
        return knx_rest_response(false, 'Valid user_id is required.', null, 400);
    }
    
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT id, username, name, phone, email, role, status, created_at, updated_at
         FROM {$table}
         WHERE id = %d
         LIMIT 1",
        $user_id
    ));
    
    if (!$customer) {
        return knx_rest_response(false, 'Customer not found.', null, 404);
    }
    
    return knx_rest_response(true, 'Customer found.', [
        'customer' => $customer,
    ]);
}
