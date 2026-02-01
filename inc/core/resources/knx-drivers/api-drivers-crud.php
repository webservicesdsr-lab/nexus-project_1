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

    // Allowed cities for current session (SSOT for UIs)
    register_rest_route('knx/v2', '/drivers/allowed-cities', [
        'methods'  => 'GET',
        'callback' => knx_rest_wrap('knx_v2_drivers_allowed_cities'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    // Soft-delete (mark only)
    register_rest_route('knx/v2', '/drivers/(?P<id>\d+)/delete', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_v2_drivers_soft_delete'),
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

function knx_v2_table_driver_cities() {
    global $wpdb;
    return $wpdb->prefix . 'knx_driver_cities';
}

/**
 * Return allowed cities for current session (SSOT)
 * - super_admin => all cities
 * - manager => cities where manager has hubs
 * - else => [] (but permission callback should prevent)
 */
function knx_v2_drivers_allowed_cities(WP_REST_Request $req) {
    global $wpdb;

    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    if (!$session) return knx_v2_drivers_resp(false, 'Session required', null, 401);

    $role = $session->role ?? 'guest';

    $tC = $wpdb->prefix . 'knx_cities';
    $tH = $wpdb->prefix . 'knx_hubs';

    if ($role === 'super_admin') {
        // defensive: include deleted_at filter only if column exists
        $has_deleted_at = (bool)$wpdb->get_var("SHOW COLUMNS FROM {$tC} LIKE 'deleted_at'");
        $where = $has_deleted_at ? "WHERE deleted_at IS NULL" : "";
        $rows = $wpdb->get_results("SELECT id, name FROM {$tC} {$where} ORDER BY name ASC");
        $cities = [];
        foreach (($rows ?: []) as $r) $cities[] = ['id' => (int)$r->id, 'name' => (string)$r->name];
        return knx_v2_drivers_resp(true, 'Cities', ['cities' => $cities]);
    }

    if ($role === 'manager') {
        $manager_id = (int)$session->id;
        // hubs where manager_user_id = manager_id
        $hub_city_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT city_id FROM {$tH} WHERE manager_user_id = %d AND city_id IS NOT NULL", $manager_id));
        if (empty($hub_city_ids)) {
            return knx_v2_drivers_resp(false, 'No allowed cities for manager', ['cities' => []], 403);
        }
        $placeholders = implode(',', array_fill(0, count($hub_city_ids), '%d'));
        // defensive: only filter by deleted_at if column exists
        $has_deleted_at = (bool)$wpdb->get_var("SHOW COLUMNS FROM {$tC} LIKE 'deleted_at'");
        $deleted_where = $has_deleted_at ? 'AND deleted_at IS NULL' : '';
        $sql = $wpdb->prepare("SELECT id, name FROM {$tC} WHERE id IN ({$placeholders}) {$deleted_where} ORDER BY name ASC", ...$hub_city_ids);
        $rows = $wpdb->get_results($sql);
        $cities = [];
        foreach (($rows ?: []) as $r) $cities[] = ['id' => (int)$r->id, 'name' => (string)$r->name];
        return knx_v2_drivers_resp(true, 'Cities', ['cities' => $cities]);
    }

    return knx_v2_drivers_resp(false, 'Forbidden', null, 403);
}

/**
 * Helper: get city_ids for a given driver
 */
function knx_v2_get_driver_city_ids($driver_id) {
    global $wpdb;
    $t = knx_v2_table_driver_cities();
    $rows = $wpdb->get_col($wpdb->prepare("SELECT city_id FROM {$t} WHERE driver_id = %d", (int)$driver_id));
    return array_map('intval', $rows ?: []);
}

/**
 * Helper: allowed city ids for current session (returns array)
 * - super_admin => all city ids
 * - manager => city ids where they manage hubs
 */
function knx_v2_allowed_city_ids_for_session() {
    global $wpdb;
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    if (!$session) return [];
    $role = $session->role ?? 'guest';
    $tC = $wpdb->prefix . 'knx_cities';
    $tH = $wpdb->prefix . 'knx_hubs';

    if ($role === 'super_admin') {
        // defensive: only filter by deleted_at when the column exists
        $has_deleted_at = (bool)$wpdb->get_var("SHOW COLUMNS FROM {$tC} LIKE 'deleted_at'");
        $where = $has_deleted_at ? "WHERE deleted_at IS NULL" : "";
        $rows = $wpdb->get_col("SELECT id FROM {$tC} {$where}");
        return array_map('intval', $rows ?: []);
    }

    if ($role === 'manager') {
        $manager_id = (int)$session->id;
        $rows = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT city_id FROM {$tH} WHERE manager_user_id = %d AND city_id IS NOT NULL", $manager_id));
        return array_map('intval', $rows ?: []);
    }

    return [];
}

/**
 * Helper: set city_ids for driver (delete previous mappings, insert new). Returns true on success
 */
function knx_v2_set_driver_city_ids($driver_id, array $city_ids) {
    global $wpdb;
    $t = knx_v2_table_driver_cities();

    // delete existing
    $wpdb->delete($t, ['driver_id' => (int)$driver_id], ['%d']);

    if (empty($city_ids)) return true;

    foreach ($city_ids as $cid) {
        $ok = $wpdb->insert($t, ['driver_id' => (int)$driver_id, 'city_id' => (int)$cid, 'created_at' => current_time('mysql')], ['%d','%d','%s']);
        if ($ok === false) return false;
    }
    return true;
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

    // exclude soft-deleted drivers
    $where[] = "d.deleted_at IS NULL";

    // enforce manager scoping (fail-closed). If session is manager, restrict to drivers mapped to allowed cities
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';
    if ($role === 'manager') {
        $allowed = knx_v2_allowed_city_ids_for_session();
        if (empty($allowed)) {
            return knx_v2_drivers_resp(false, 'No allowed cities for manager', ['drivers' => []], 403);
        }
        $tDC = knx_v2_table_driver_cities();
        $placeholders = implode(',', array_fill(0, count($allowed), '%d'));
        $where[] = "d.id IN (SELECT DISTINCT driver_id FROM {$tDC} WHERE city_id IN ({$placeholders}))";
        $vals = array_merge($vals, $allowed);
    }

    $where_sql = implode(' AND ', $where);

    $count_sql = "SELECT COUNT(DISTINCT d.id) FROM {$tD} d LEFT JOIN {$tU} u ON u.id = d.id WHERE {$where_sql}";
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
            GROUP BY d.id
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
            'city_ids'     => knx_v2_get_driver_city_ids($r->id),
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

    // city_ids is required by contract
    if (!isset($body['city_ids']) || !is_array($body['city_ids']) || empty($body['city_ids'])) {
        return knx_v2_drivers_resp(false, 'city_ids is required.', null, 400);
    }

    // 1) Insert knx_users
    // start DB transaction to ensure atomicity across multiple tables
    $wpdb->query('START TRANSACTION');
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
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to create driver profile.', null, 500);
    }

    // persist city mappings (required)
    $city_ids = array_map('intval', array_values($body['city_ids']));
    // enforce session scoping
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';
    if ($role === 'manager') {
        $allowed = knx_v2_allowed_city_ids_for_session();
        if (empty($allowed)) {
            $wpdb->query('ROLLBACK');
            return knx_v2_drivers_resp(false, 'No allowed cities for manager', null, 403);
        }
        foreach ($city_ids as $cid) {
            if (!in_array((int)$cid, $allowed, true)) {
                $wpdb->query('ROLLBACK');
                return knx_v2_drivers_resp(false, 'City id not allowed for manager', null, 403);
            }
        }
    }

    $okMap = knx_v2_set_driver_city_ids($user_id, $city_ids);
    if ($okMap === false) {
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to persist driver city mappings. Rolled back.', null, 500);
    }

    $wpdb->query('COMMIT');

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
         WHERE d.id = %d AND d.deleted_at IS NULL
         LIMIT 1",
        $id
    ));

    if (!$row) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    // Manager scoping: ensure manager can view this driver
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';
    if ($role === 'manager') {
        $allowed = knx_v2_allowed_city_ids_for_session();
        if (empty($allowed)) return knx_v2_drivers_resp(false, 'No allowed cities for manager', null, 403);
        $driver_city_ids = knx_v2_get_driver_city_ids($id);
        $intersect = array_intersect($allowed, $driver_city_ids);
        if (empty($intersect)) return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

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
        'city_ids' => knx_v2_get_driver_city_ids($id),
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

    // fail-closed: ensure not soft-deleted
    if (!empty($curD->deleted_at)) return knx_v2_drivers_resp(false, 'Driver not found.', null, 404);

    // Manager scoping: ensure manager can operate on this driver
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';
    if ($role === 'manager') {
        $allowed = knx_v2_allowed_city_ids_for_session();
        if (empty($allowed)) return knx_v2_drivers_resp(false, 'No allowed cities for manager', null, 403);
        $driver_city_ids = knx_v2_get_driver_city_ids($id);
        $intersect = array_intersect($allowed, $driver_city_ids);
        if (empty($intersect)) return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

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

    // Keep previous state for rollback if needed
    // Use DB transaction for update + mapping to ensure atomicity
    $wpdb->query('START TRANSACTION');

    $okD = $wpdb->update($tD, $updD, ['id' => $id]);
    if ($okD === false) {
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to update driver (DB).', null, 500);
    }

    $okU = $wpdb->update($tU, $updU, ['id' => $id]);
    if ($okU === false) {
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to update user (DB).', null, 500);
    }

    // Handle city_ids transactionally if provided
    if (isset($body['city_ids']) && is_array($body['city_ids'])) {
        $new_city_ids = array_map('intval', array_values($body['city_ids']));
        // manager may not assign outside their allowed cities
        if ($role === 'manager') {
            $allowed = knx_v2_allowed_city_ids_for_session();
            foreach ($new_city_ids as $cid) {
                if (!in_array((int)$cid, $allowed, true)) {
                    $wpdb->query('ROLLBACK');
                    return knx_v2_drivers_resp(false, 'City id not allowed for manager', null, 403);
                }
            }
        }

        $okMap = knx_v2_set_driver_city_ids($id, $new_city_ids);
        if ($okMap === false) {
            $wpdb->query('ROLLBACK');
            return knx_v2_drivers_resp(false, 'Failed to persist driver city mappings. Rolled back.', null, 500);
        }
    }

    $wpdb->query('COMMIT');

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

    $row = $wpdb->get_row($wpdb->prepare("SELECT id, status, deleted_at FROM {$tD} WHERE id = %d LIMIT 1", $id));
    if (!$row) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    // fail-closed: cannot operate on soft-deleted
    if (!empty($row->deleted_at)) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    // Manager scoping
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';
    if ($role === 'manager') {
        $allowed = knx_v2_allowed_city_ids_for_session();
        if (empty($allowed)) return knx_v2_drivers_resp(false, 'No allowed cities for manager', null, 403);
        $driver_city_ids = knx_v2_get_driver_city_ids($id);
        $intersect = array_intersect($allowed, $driver_city_ids);
        if (empty($intersect)) return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

    $new_status = ((string)$row->status === 'active') ? 'inactive' : 'active';
    $now = current_time('mysql');

    // Prevent activating a driver that has no assigned cities
    if ($new_status === 'active') {
        $assigned = knx_v2_get_driver_city_ids($id);
        if (empty($assigned)) {
            return knx_v2_drivers_resp(false, 'Driver has no assigned cities.', null, 409);
        }
    }

    $okD = $wpdb->update($tD, ['status' => $new_status, 'updated_at' => $now], ['id' => $id], ['%s','%s'], ['%d']);
    if ($okD === false) return knx_v2_drivers_resp(false, 'Failed to toggle driver status.', null, 500);

    $okU = $wpdb->update($tU, ['status' => $new_status, 'updated_at' => $now], ['id' => $id], ['%s','%s'], ['%d']);
    if ($okU === false) return knx_v2_drivers_resp(false, 'Failed to toggle user status.', null, 500);

    return knx_v2_drivers_resp(true, 'Status toggled.', ['status' => $new_status]);
}

/**
 * ==========================================================
 * POST SOFT-DELETE — mark driver as deleted (soft)
 * ==========================================================
 */
function knx_v2_drivers_soft_delete(WP_REST_Request $req) {
    global $wpdb;

    $id = (int)$req->get_param('id');
    if ($id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $row = $wpdb->get_row($wpdb->prepare("SELECT id, deleted_at FROM {$tD} WHERE id = %d LIMIT 1", $id));
    if (!$row) return knx_v2_drivers_resp(false, 'Not found.', null, 404);
    if (!empty($row->deleted_at)) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    // Manager scoping
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';
    if ($role === 'manager') {
        $allowed = knx_v2_allowed_city_ids_for_session();
        if (empty($allowed)) return knx_v2_drivers_resp(false, 'No allowed cities for manager', null, 403);
        $driver_city_ids = knx_v2_get_driver_city_ids($id);
        $intersect = array_intersect($allowed, $driver_city_ids);
        if (empty($intersect)) return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

    $now = current_time('mysql');
    $deleted_by = $session ? (int)($session->id ?? 0) : 0;
    $reason = isset($body['reason']) ? trim(sanitize_text_field((string)$body['reason'])) : null;

    $ok = $wpdb->update($tD, [
        'deleted_at'    => $now,
        'deleted_by'    => $deleted_by,
        'deleted_reason'=> ($reason !== '' ? $reason : null),
        'updated_at'    => $now,
    ], ['id' => $id], ['%s','%d','%s','%s'], ['%d']);

    if ($ok === false) return knx_v2_drivers_resp(false, 'Failed to soft-delete driver.', null, 500);

    // Also mark user as inactive to prevent login
    $wpdb->update($tU, ['status' => 'inactive', 'updated_at' => $now], ['id' => $id]);

    return knx_v2_drivers_resp(true, 'Driver soft-deleted.', ['id' => $id]);
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

    // fail-closed: ensure driver exists and is not soft-deleted
    $tD = knx_v2_table_drivers();
    $driver = $wpdb->get_row($wpdb->prepare("SELECT id, deleted_at FROM {$tD} WHERE id = %d LIMIT 1", $id));
    if (!$driver) return knx_v2_drivers_resp(false, 'Not found.', null, 404);
    if (!empty($driver->deleted_at)) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

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
