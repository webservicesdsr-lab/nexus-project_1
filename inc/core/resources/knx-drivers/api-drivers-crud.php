<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Drivers Admin CRUD (v2.1) — Custom DB SSOT
 * ----------------------------------------------------------
 * SSOT Identity:  {prefix}knx_users
 * Driver profile: {prefix}knx_drivers
 *
 * Link strategy (NO schema changes):
 * - knx_drivers.id === knx_users.id  (1:1)
 *
 * Endpoints (admin only - super_admin, manager):
 *   GET   /knx/v2/drivers/list
 *   POST  /knx/v2/drivers/create
 *   GET   /knx/v2/drivers/(?P<id>\d+)
 *   POST  /knx/v2/drivers/(?P<id>\d+)/update
 *   POST  /knx/v2/drivers/(?P<id>\d+)/toggle
 *   POST  /knx/v2/drivers/(?P<id>\d+)/reset-password
 *
 * Security:
 * - permission_callback: knx_rest_permission_roles(['super_admin','manager'])
 * - write ops require wp_verify_nonce(knx_nonce, 'knx_nonce')
 * - WP REST cookie auth requires X-WP-Nonce header (wp_rest) on POST
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/drivers/list', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_drivers_list'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/drivers/create', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_drivers_create'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/drivers/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_drivers_get'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/drivers/(?P<id>\d+)/update', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_drivers_update'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/drivers/(?P<id>\d+)/toggle', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_drivers_toggle'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/drivers/(?P<id>\d+)/reset-password', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_drivers_reset_password'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Canonical response helper (fallback-safe).
 */
function knx_v2_drivers_resp($success, $message, $data = null, $status = 200) {
    if (function_exists('knx_rest_response')) {
        return knx_rest_response((bool)$success, (string)$message, $data, (int)$status);
    }
    return new WP_REST_Response([
        'success' => (bool)$success,
        'message' => (string)$message,
        'data'    => $data,
    ], (int)$status);
}

function knx_v2_table_users() {
    global $wpdb;
    return $wpdb->prefix . 'knx_users';
}

function knx_v2_table_drivers() {
    global $wpdb;
    return $wpdb->prefix . 'knx_drivers';
}

/**
 * Read JSON body safely (supports JSON + form).
 */
function knx_v2_body(WP_REST_Request $req) {
    $json = $req->get_json_params();
    if (is_array($json) && !empty($json)) return $json;
    $params = $req->get_params();
    return is_array($params) ? $params : [];
}

/**
 * Write nonce gate (keeps pattern aligned to other modules).
 */
function knx_v2_require_knx_nonce($body) {
    $nonce = isset($body['knx_nonce']) ? sanitize_text_field((string)$body['knx_nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'knx_nonce')) {
        return knx_v2_drivers_resp(false, 'Invalid nonce.', null, 403);
    }
    return null;
}

/**
 * Username helpers (custom DB, not WP users).
 */
function knx_v2_users_username_exists($username) {
    global $wpdb;
    $t = knx_v2_table_users();
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE username = %s", $username)) > 0;
}

function knx_v2_users_email_exists($email, $exclude_id = 0) {
    global $wpdb;
    $t = knx_v2_table_users();
    $exclude_id = (int)$exclude_id;
    if ($exclude_id > 0) {
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE email = %s AND id != %d", $email, $exclude_id)) > 0;
    }
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE email = %s", $email)) > 0;
}

function knx_v2_drivers_email_exists($email, $exclude_id = 0) {
    global $wpdb;
    $t = knx_v2_table_drivers();
    $exclude_id = (int)$exclude_id;
    if ($exclude_id > 0) {
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE email = %s AND id != %d", $email, $exclude_id)) > 0;
    }
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE email = %s", $email)) > 0;
}

function knx_v2_make_username_base($email, $full_name) {
    $email = is_string($email) ? trim($email) : '';
    $full_name = is_string($full_name) ? trim($full_name) : '';

    $base = '';
    if ($email !== '' && strpos($email, '@') !== false) {
        $base = substr($email, 0, strpos($email, '@'));
    }
    if ($base === '' && $full_name !== '') {
        $base = preg_replace('/\s+/', '', strtolower($full_name));
    }
    $base = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$base);
    if ($base === '') $base = 'driver';

    // keep reasonable length
    $base = substr($base, 0, 32);
    return $base;
}

function knx_v2_unique_username($email, $full_name) {
    $base = knx_v2_make_username_base($email, $full_name);
    $username = $base;
    $i = 1;

    while (knx_v2_users_username_exists($username)) {
        $i++;
        $username = $base . $i;
        if ($i > 9999) {
            $username = $base . '_' . wp_generate_password(4, false, false);
            if (!knx_v2_users_username_exists($username)) break;
        }
    }

    return $username;
}

/**
 * ==========================================================
 * GET LIST
 * ==========================================================
 */
function knx_v2_drivers_list(WP_REST_Request $req) {
    global $wpdb;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $page = max(1, (int)$req->get_param('page'));
    $per_page = max(1, min(100, (int)($req->get_param('per_page') ?: 20)));
    $offset = ($page - 1) * $per_page;

    $q = sanitize_text_field((string)$req->get_param('q'));
    $status = sanitize_text_field((string)$req->get_param('status'));

    $where = ['1=1'];
    $vals = [];

    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where[] = "(d.full_name LIKE %s OR d.email LIKE %s OR d.phone LIKE %s OR u.username LIKE %s OR d.id = %d)";
        $vals[] = $like;
        $vals[] = $like;
        $vals[] = $like;
        $vals[] = $like;
        $vals[] = (int)$q;
    }

    if ($status && in_array($status, ['active','inactive'], true)) {
        $where[] = "d.status = %s";
        $vals[] = $status;
    }

    $where_sql = implode(' AND ', $where);

    $count_sql = "SELECT COUNT(*) FROM {$tD} d LEFT JOIN {$tU} u ON u.id = d.id WHERE {$where_sql}";
    $count_sql = $vals ? $wpdb->prepare($count_sql, ...$vals) : $count_sql;
    $total = (int)$wpdb->get_var($count_sql);

    $sql = "SELECT
              d.id,
              d.full_name,
              d.phone,
              d.email,
              d.vehicle_info,
              d.status,
              d.created_at,
              d.updated_at,
              u.username,
              u.role,
              u.status AS user_status
            FROM {$tD} d
            LEFT JOIN {$tU} u ON u.id = d.id
            WHERE {$where_sql}
            ORDER BY d.created_at DESC
            LIMIT %d OFFSET %d";

    $sql_vals = array_merge($vals, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$sql_vals));

    $drivers = [];
    foreach (($rows ?: []) as $r) {
        $drivers[] = [
            'id'           => (int)$r->id,
            'full_name'    => (string)$r->full_name,
            'phone'        => (string)($r->phone ?? ''),
            'email'        => (string)($r->email ?? ''),
            'vehicle_info' => (string)($r->vehicle_info ?? ''),
            'status'       => (string)$r->status,
            'created_at'   => (string)$r->created_at,
            'updated_at'   => (string)$r->updated_at,
            'user' => [
                'username' => (string)($r->username ?? ''),
                'role'     => (string)($r->role ?? ''),
                'status'   => (string)($r->user_status ?? ''),
            ],
        ];
    }

    return knx_v2_drivers_resp(true, 'Drivers list', [
        'drivers' => $drivers,
        'pagination' => [
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total ? (int)ceil($total / $per_page) : 0,
        ],
    ]);
}

/**
 * ==========================================================
 * POST CREATE
 * Creates:
 * - knx_users row (role=driver)
 * - knx_drivers row with SAME id
 * ==========================================================
 */
function knx_v2_drivers_create(WP_REST_Request $req) {
    global $wpdb;

    $body = knx_v2_body($req);

    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tU = knx_v2_table_users();
    $tD = knx_v2_table_drivers();

    $full_name = isset($body['full_name']) ? trim(sanitize_text_field($body['full_name'])) : '';
    $email     = isset($body['email']) ? trim(sanitize_email($body['email'])) : '';
    $phone     = isset($body['phone']) ? trim(sanitize_text_field($body['phone'])) : '';
    $vehicle   = isset($body['vehicle_info']) ? trim(sanitize_text_field($body['vehicle_info'])) : '';
    $status    = (isset($body['status']) && in_array($body['status'], ['active','inactive'], true)) ? $body['status'] : 'active';

    if ($full_name === '') {
        return knx_v2_drivers_resp(false, 'full_name is required.', null, 400);
    }
    if ($email === '' || !is_email($email)) {
        return knx_v2_drivers_resp(false, 'Valid email is required.', null, 400);
    }

    // Uniqueness checks (both tables)
    if (knx_v2_users_email_exists($email)) {
        return knx_v2_drivers_resp(false, 'Email already exists in knx_users.', null, 409);
    }
    if (knx_v2_drivers_email_exists($email)) {
        return knx_v2_drivers_resp(false, 'Email already exists in knx_drivers.', null, 409);
    }

    $username = knx_v2_unique_username($email, $full_name);

    $temp_password_plain = wp_generate_password(14, true, true);
    $temp_password_hash  = password_hash($temp_password_plain, PASSWORD_BCRYPT);

    $now = current_time('mysql');

    // 1) Insert knx_users
    $okU = $wpdb->insert($tU, [
        'username'   => $username,
        'email'      => $email,
        'name'       => $full_name,
        'phone'      => ($phone !== '' ? $phone : null),
        'password'   => $temp_password_hash,
        'role'       => 'driver',
        'status'     => $status,
        'created_at' => $now,
        'updated_at' => null,
    ], ['%s','%s','%s','%s','%s','%s','%s','%s','%s']);

    if (!$okU) {
        return knx_v2_drivers_resp(false, 'Failed to create knx_user.', null, 500);
    }

    $user_id = (int)$wpdb->insert_id;
    if ($user_id <= 0) {
        return knx_v2_drivers_resp(false, 'Failed to resolve new user_id.', null, 500);
    }

    // 2) Insert knx_drivers with SAME id
    $okD = $wpdb->insert($tD, [
        'id'           => $user_id,
        'full_name'    => $full_name,
        'phone'        => ($phone !== '' ? $phone : null),
        'email'        => $email,
        'vehicle_info' => ($vehicle !== '' ? $vehicle : null),
        'status'       => $status,
        'created_at'   => $now,
        'updated_at'   => $now,
    ], ['%d','%s','%s','%s','%s','%s','%s','%s']);

    if (!$okD) {
        // Rollback user (fail-closed)
        $wpdb->delete($tU, ['id' => $user_id], ['%d']);
        return knx_v2_drivers_resp(false, 'Failed to create driver profile. Rolled back user.', null, 500);
    }

    return knx_v2_drivers_resp(true, 'Driver created.', [
        'driver_id' => $user_id,
        'user_id'   => $user_id,
        'username'  => $username,
        'temp_password' => $temp_password_plain,
    ], 201);
}

/**
 * ==========================================================
 * GET SINGLE
 * ==========================================================
 */
function knx_v2_drivers_get(WP_REST_Request $req) {
    global $wpdb;

    $id = (int)$req->get_param('id');
    if ($id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT
           d.id, d.full_name, d.phone, d.email, d.vehicle_info, d.status, d.created_at, d.updated_at,
           u.username, u.role, u.status AS user_status
         FROM {$tD} d
         LEFT JOIN {$tU} u ON u.id = d.id
         WHERE d.id = %d
         LIMIT 1",
        $id
    ));

    if (!$row) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    return knx_v2_drivers_resp(true, 'Driver', [
        'id'           => (int)$row->id,
        'full_name'    => (string)$row->full_name,
        'phone'        => (string)($row->phone ?? ''),
        'email'        => (string)($row->email ?? ''),
        'vehicle_info' => (string)($row->vehicle_info ?? ''),
        'status'       => (string)$row->status,
        'created_at'   => (string)$row->created_at,
        'updated_at'   => (string)$row->updated_at,
        'user' => [
            'id'       => (int)$row->id,
            'username' => (string)($row->username ?? ''),
            'role'     => (string)($row->role ?? ''),
            'status'   => (string)($row->user_status ?? ''),
        ],
    ]);
}

/**
 * ==========================================================
 * POST UPDATE
 * Updates BOTH tables (same id) to keep SSOT aligned.
 * Allowed:
 * - full_name, email, phone, vehicle_info, status
 * ==========================================================
 */
function knx_v2_drivers_update(WP_REST_Request $req) {
    global $wpdb;

    $id = (int)$req->get_param('id');
    if ($id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $curD = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tD} WHERE id = %d LIMIT 1", $id));
    $curU = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tU} WHERE id = %d LIMIT 1", $id));

    if (!$curD) return knx_v2_drivers_resp(false, 'Driver not found.', null, 404);
    if (!$curU) return knx_v2_drivers_resp(false, 'User not found for this driver id.', null, 404);

    $updD = [];
    $updU = [];

    if (isset($body['full_name'])) {
        $v = trim(sanitize_text_field((string)$body['full_name']));
        if ($v === '') return knx_v2_drivers_resp(false, 'full_name cannot be empty.', null, 400);
        $updD['full_name'] = $v;
        $updU['name'] = $v;
    }

    if (isset($body['phone'])) {
        $v = trim(sanitize_text_field((string)$body['phone']));
        $updD['phone'] = ($v !== '' ? $v : null);
        $updU['phone'] = ($v !== '' ? $v : null);
    }

    if (isset($body['vehicle_info'])) {
        $v = trim(sanitize_text_field((string)$body['vehicle_info']));
        $updD['vehicle_info'] = ($v !== '' ? $v : null);
    }

    if (isset($body['status']) && in_array($body['status'], ['active','inactive'], true)) {
        $updD['status'] = $body['status'];
        $updU['status'] = $body['status'];
    }

    $new_email = null;
    if (isset($body['email'])) {
        $v = trim(sanitize_email((string)$body['email']));
        if ($v === '' || !is_email($v)) return knx_v2_drivers_resp(false, 'Valid email required.', null, 400);
        $new_email = $v;

        // uniqueness across both tables excluding current id
        if (knx_v2_users_email_exists($new_email, $id)) {
            return knx_v2_drivers_resp(false, 'Email already exists in knx_users.', null, 409);
        }
        if (knx_v2_drivers_email_exists($new_email, $id)) {
            return knx_v2_drivers_resp(false, 'Email already exists in knx_drivers.', null, 409);
        }

        $updD['email'] = $new_email;
        $updU['email'] = $new_email;
    }

    if (empty($updD) && empty($updU)) {
        return knx_v2_drivers_resp(false, 'Nothing to update.', null, 400);
    }

    // Updated timestamps
    $updD['updated_at'] = current_time('mysql');
    $updU['updated_at'] = current_time('mysql');

    // Update driver
    $okD = $wpdb->update($tD, $updD, ['id' => $id]);
    if ($okD === false) return knx_v2_drivers_resp(false, 'Failed to update driver (DB).', null, 500);

    // Update user
    $okU = $wpdb->update($tU, $updU, ['id' => $id]);
    if ($okU === false) return knx_v2_drivers_resp(false, 'Failed to update user (DB).', null, 500);

    return knx_v2_drivers_resp(true, 'Driver updated.', ['id' => $id]);
}

/**
 * ==========================================================
 * POST TOGGLE (active <-> inactive) — updates BOTH tables
 * ==========================================================
 */
function knx_v2_drivers_toggle(WP_REST_Request $req) {
    global $wpdb;

    $id = (int)$req->get_param('id');
    if ($id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $row = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$tD} WHERE id = %d LIMIT 1", $id));
    if (!$row) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    $new_status = ((string)$row->status === 'active') ? 'inactive' : 'active';
    $now = current_time('mysql');

    $okD = $wpdb->update($tD, ['status' => $new_status, 'updated_at' => $now], ['id' => $id], ['%s','%s'], ['%d']);
    if ($okD === false) return knx_v2_drivers_resp(false, 'Failed to toggle driver status.', null, 500);

    $okU = $wpdb->update($tU, ['status' => $new_status, 'updated_at' => $now], ['id' => $id], ['%s','%s'], ['%d']);
    if ($okU === false) return knx_v2_drivers_resp(false, 'Failed to toggle user status.', null, 500);

    return knx_v2_drivers_resp(true, 'Status toggled.', ['status' => $new_status]);
}

/**
 * ==========================================================
 * POST RESET PASSWORD — updates knx_users.password only
 * ==========================================================
 */
function knx_v2_drivers_reset_password(WP_REST_Request $req) {
    global $wpdb;

    $id = (int)$req->get_param('id');
    if ($id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tU = knx_v2_table_users();

    $user = $wpdb->get_row($wpdb->prepare("SELECT id, username, email FROM {$tU} WHERE id = %d LIMIT 1", $id));
    if (!$user) return knx_v2_drivers_resp(false, 'User not found.', null, 404);

    $new_plain = wp_generate_password(14, true, true);
    $new_hash  = password_hash($new_plain, PASSWORD_BCRYPT);

    $ok = $wpdb->update($tU, [
        'password'   => $new_hash,
        'updated_at' => current_time('mysql'),
    ], ['id' => $id], ['%s','%s'], ['%d']);

    if ($ok === false) return knx_v2_drivers_resp(false, 'Failed to reset password.', null, 500);

    return knx_v2_drivers_resp(true, 'Password reset.', [
        'id'            => (int)$user->id,
        'username'      => (string)$user->username,
        'email'         => (string)$user->email,
        'temp_password' => $new_plain,
    ]);
}
