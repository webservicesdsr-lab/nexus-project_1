<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Managers CRUD API (v1.0 - Production)
 * ----------------------------------------------------------
 * Endpoints for SuperAdmin/Manager to manage hub_management users
 * and their hub assignments.
 *
 * Routes:
 *   GET    /knx/v1/hub-managers              → list all managers (optionally filter by hub_id)
 *   GET    /knx/v1/hub-managers/users        → list users with role hub_management
 *   POST   /knx/v1/hub-managers/create-user  → create new hub_management user
 *   POST   /knx/v1/hub-managers/assign       → assign user to hub
 *   POST   /knx/v1/hub-managers/unassign     → remove user from hub
 *   DELETE /knx/v1/hub-managers              → delete hub_management user entirely
 *
 * Security: super_admin and manager only
 * ==========================================================
 */

add_action('rest_api_init', function () {

    // List hub managers (with their assigned hubs)
    register_rest_route('knx/v1', '/hub-managers', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_list_hub_managers'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    // List users with hub_management role
    register_rest_route('knx/v1', '/hub-managers/users', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_list_hub_management_users'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    // Create new hub_management user
    register_rest_route('knx/v1', '/hub-managers/create-user', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_create_hub_manager_user'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    // Assign user to hub
    register_rest_route('knx/v1', '/hub-managers/assign', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_assign_hub_manager'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    // Unassign user from hub
    register_rest_route('knx/v1', '/hub-managers/unassign', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_unassign_hub_manager'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    // Delete hub_management user entirely
    register_rest_route('knx/v1', '/hub-managers', [
        'methods'             => 'DELETE',
        'callback'            => knx_rest_wrap('knx_api_delete_hub_manager_user'),
        'permission_callback' => knx_rest_permission_roles(['super_admin']), // Only super_admin can delete users
    ]);
});

/**
 * GET /hub-managers
 * List all hub managers with their assigned hubs
 * Optional: ?hub_id=X to filter by specific hub
 */
function knx_api_list_hub_managers(WP_REST_Request $request) {
    global $wpdb;

    $users_table    = $wpdb->prefix . 'knx_users';
    $managers_table = $wpdb->prefix . 'knx_hub_managers';
    $hubs_table     = $wpdb->prefix . 'knx_hubs';

    $hub_id = intval($request->get_param('hub_id'));

    // Get all hub_management users with their assignments
    $sql = "
        SELECT 
            u.id AS user_id,
            u.username,
            u.email,
            u.name,
            u.status AS user_status,
            u.created_at AS user_created,
            GROUP_CONCAT(DISTINCT hm.hub_id) AS hub_ids,
            GROUP_CONCAT(DISTINCT h.name SEPARATOR ', ') AS hub_names
        FROM {$users_table} u
        LEFT JOIN {$managers_table} hm ON u.id = hm.user_id
        LEFT JOIN {$hubs_table} h ON hm.hub_id = h.id
        WHERE u.role = 'hub_management'
    ";

    if ($hub_id > 0) {
        $sql .= $wpdb->prepare(" AND hm.hub_id = %d", $hub_id);
    }

    $sql .= " GROUP BY u.id ORDER BY u.name ASC, u.username ASC";

    $results = $wpdb->get_results($sql);

    // Format results
    $managers = [];
    foreach ($results as $row) {
        $hub_ids_arr = $row->hub_ids ? array_map('intval', explode(',', $row->hub_ids)) : [];
        $managers[] = [
            'user_id'      => (int) $row->user_id,
            'username'     => $row->username,
            'email'        => $row->email,
            'name'         => $row->name ?: $row->username,
            'status'       => $row->user_status,
            'created_at'   => $row->user_created,
            'hub_ids'      => $hub_ids_arr,
            'hub_names'    => $row->hub_names ?: 'No hubs assigned',
            'hubs_count'   => count($hub_ids_arr),
        ];
    }

    return knx_rest_response(true, 'OK', [
        'managers' => $managers,
        'total'    => count($managers),
    ]);
}

/**
 * GET /hub-managers/users
 * List all users with hub_management role (for dropdown selection)
 */
function knx_api_list_hub_management_users(WP_REST_Request $request) {
    global $wpdb;

    $users_table = $wpdb->prefix . 'knx_users';

    $users = $wpdb->get_results("
        SELECT id, username, email, name, status, created_at
        FROM {$users_table}
        WHERE role = 'hub_management'
        ORDER BY name ASC, username ASC
    ");

    return knx_rest_response(true, 'OK', [
        'users' => $users,
        'total' => count($users),
    ]);
}

/**
 * POST /hub-managers/create-user
 * Create a new user with hub_management role
 * Body: { username, email, password, name, hub_id (optional - auto-assign) }
 */
function knx_api_create_hub_manager_user(WP_REST_Request $request) {
    global $wpdb;

    $users_table    = $wpdb->prefix . 'knx_users';
    $managers_table = $wpdb->prefix . 'knx_hub_managers';

    // Validate nonce
    $nonce = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_managers_nonce')) {
        return knx_rest_error('Invalid security token', 403);
    }

    // Get and sanitize inputs
    $username = sanitize_user($request->get_param('username') ?? '', true);
    $email    = sanitize_email($request->get_param('email') ?? '');
    $password = $request->get_param('password') ?? '';
    $name     = sanitize_text_field($request->get_param('name') ?? '');
    $hub_id   = intval($request->get_param('hub_id'));

    // Validations
    if (strlen($username) < 3) {
        return knx_rest_error('Username must be at least 3 characters', 400);
    }

    if (!is_email($email)) {
        return knx_rest_error('Invalid email address', 400);
    }

    if (strlen($password) < 8) {
        return knx_rest_error('Password must be at least 8 characters', 400);
    }

    // Check username uniqueness
    $existing_username = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$users_table} WHERE username = %s LIMIT 1",
        $username
    ));
    if ($existing_username) {
        return knx_rest_error('Username already exists', 400);
    }

    // Check email uniqueness
    $existing_email = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$users_table} WHERE email = %s LIMIT 1",
        $email
    ));
    if ($existing_email) {
        return knx_rest_error('Email already exists', 400);
    }

    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Insert user
    $inserted = $wpdb->insert($users_table, [
        'username'   => $username,
        'email'      => $email,
        'password'   => $password_hash,
        'name'       => $name ?: $username,
        'role'       => 'hub_management',
        'status'     => 'active',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    if (!$inserted) {
        return knx_rest_error('Failed to create user', 500);
    }

    $user_id = $wpdb->insert_id;

    // Auto-assign to hub if hub_id provided
    $assigned_hub = null;
    if ($hub_id > 0) {
        $wpdb->insert($managers_table, [
            'hub_id'     => $hub_id,
            'user_id'    => $user_id,
            'created_at' => current_time('mysql'),
        ], ['%d', '%d', '%s']);

        // Get hub name
        $hubs_table = $wpdb->prefix . 'knx_hubs';
        $assigned_hub = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$hubs_table} WHERE id = %d",
            $hub_id
        ));
    }

    return knx_rest_response(true, 'User created successfully', [
        'user_id'      => $user_id,
        'username'     => $username,
        'email'        => $email,
        'assigned_hub' => $assigned_hub,
    ]);
}

/**
 * POST /hub-managers/assign
 * Assign a hub_management user to a hub
 * Body: { user_id, hub_id }
 */
function knx_api_assign_hub_manager(WP_REST_Request $request) {
    global $wpdb;

    $managers_table = $wpdb->prefix . 'knx_hub_managers';
    $users_table    = $wpdb->prefix . 'knx_users';
    $hubs_table     = $wpdb->prefix . 'knx_hubs';

    $nonce   = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    $user_id = intval($request->get_param('user_id'));
    $hub_id  = intval($request->get_param('hub_id'));

    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_managers_nonce')) {
        return knx_rest_error('Invalid security token', 403);
    }

    if ($user_id <= 0 || $hub_id <= 0) {
        return knx_rest_error('Invalid user_id or hub_id', 400);
    }

    // Verify user exists and is hub_management
    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT id, role FROM {$users_table} WHERE id = %d LIMIT 1",
        $user_id
    ));

    if (!$user) {
        return knx_rest_error('User not found', 404);
    }

    if ($user->role !== 'hub_management') {
        return knx_rest_error('User is not a hub manager', 400);
    }

    // Verify hub exists
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name FROM {$hubs_table} WHERE id = %d LIMIT 1",
        $hub_id
    ));

    if (!$hub) {
        return knx_rest_error('Hub not found', 404);
    }

    // Check if already assigned
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$managers_table} WHERE user_id = %d AND hub_id = %d LIMIT 1",
        $user_id, $hub_id
    ));

    if ($existing) {
        return knx_rest_error('User is already assigned to this hub', 400);
    }

    // Insert assignment
    $wpdb->insert($managers_table, [
        'hub_id'     => $hub_id,
        'user_id'    => $user_id,
        'created_at' => current_time('mysql'),
    ], ['%d', '%d', '%s']);

    return knx_rest_response(true, 'User assigned to hub', [
        'user_id'  => $user_id,
        'hub_id'   => $hub_id,
        'hub_name' => $hub->name,
    ]);
}

/**
 * POST /hub-managers/unassign
 * Remove a hub_management user from a hub
 * Body: { user_id, hub_id }
 */
function knx_api_unassign_hub_manager(WP_REST_Request $request) {
    global $wpdb;

    $managers_table = $wpdb->prefix . 'knx_hub_managers';

    $nonce   = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    $user_id = intval($request->get_param('user_id'));
    $hub_id  = intval($request->get_param('hub_id'));

    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_managers_nonce')) {
        return knx_rest_error('Invalid security token', 403);
    }

    if ($user_id <= 0 || $hub_id <= 0) {
        return knx_rest_error('Invalid user_id or hub_id', 400);
    }

    $deleted = $wpdb->delete($managers_table, [
        'user_id' => $user_id,
        'hub_id'  => $hub_id,
    ], ['%d', '%d']);

    if ($deleted === false) {
        return knx_rest_error('Failed to remove assignment', 500);
    }

    if ($deleted === 0) {
        return knx_rest_error('Assignment not found', 404);
    }

    return knx_rest_response(true, 'User removed from hub', [
        'user_id' => $user_id,
        'hub_id'  => $hub_id,
    ]);
}

/**
 * DELETE /hub-managers
 * Delete a hub_management user entirely (super_admin only)
 * Body: { user_id }
 */
function knx_api_delete_hub_manager_user(WP_REST_Request $request) {
    global $wpdb;

    $users_table    = $wpdb->prefix . 'knx_users';
    $managers_table = $wpdb->prefix . 'knx_hub_managers';
    $sessions_table = $wpdb->prefix . 'knx_sessions';

    $nonce   = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    $user_id = intval($request->get_param('user_id'));

    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_managers_nonce')) {
        return knx_rest_error('Invalid security token', 403);
    }

    if ($user_id <= 0) {
        return knx_rest_error('Invalid user_id', 400);
    }

    // Verify user exists and is hub_management
    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT id, role, username FROM {$users_table} WHERE id = %d LIMIT 1",
        $user_id
    ));

    if (!$user) {
        return knx_rest_error('User not found', 404);
    }

    if ($user->role !== 'hub_management') {
        return knx_rest_error('Can only delete hub_management users via this endpoint', 400);
    }

    // Delete hub assignments first (foreign key)
    $wpdb->delete($managers_table, ['user_id' => $user_id], ['%d']);

    // Delete user sessions
    $wpdb->delete($sessions_table, ['user_id' => $user_id], ['%d']);

    // Delete user
    $deleted = $wpdb->delete($users_table, ['id' => $user_id], ['%d']);

    if (!$deleted) {
        return knx_rest_error('Failed to delete user', 500);
    }

    return knx_rest_response(true, 'User deleted', [
        'user_id'  => $user_id,
        'username' => $user->username,
    ]);
}
