<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Drivers Admin CRUD (v2.2) — Production Canon
 * ----------------------------------------------------------
 * SSOT Identity:  {prefix}knx_users
 * Driver profile: {prefix}knx_drivers
 *
 * Canon link strategy (NO schema changes):
 * - knx_drivers.driver_user_id === knx_users.id   (1:1)
 * - knx_drivers.id is its own AUTO_INCREMENT PK (driver_id)
 * - knx_driver_cities.driver_id references knx_drivers.id
 *
 * Endpoints (admin only - super_admin, manager):
 *   GET   /knx/v2/drivers/list
 *   POST  /knx/v2/drivers/create
 *   GET   /knx/v2/drivers/(?P<id>\d+)                 (id = driver_id)
 *   POST  /knx/v2/drivers/(?P<id>\d+)/update          (id = driver_id)
 *   POST  /knx/v2/drivers/(?P<id>\d+)/toggle          (id = driver_id)
 *   POST  /knx/v2/drivers/(?P<id>\d+)/reset-password  (id = driver_id)
 *   GET   /knx/v2/drivers/allowed-cities
 *   POST  /knx/v2/drivers/(?P<id>\d+)/delete          (id = driver_id)
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

/** Table helpers */
function knx_v2_table_users() { global $wpdb; return $wpdb->prefix . 'knx_users'; }
function knx_v2_table_drivers() { global $wpdb; return $wpdb->prefix . 'knx_drivers'; }
function knx_v2_table_driver_cities() { global $wpdb; return $wpdb->prefix . 'knx_driver_cities'; }
function knx_v2_table_cities() { global $wpdb; return $wpdb->prefix . 'knx_cities'; }
function knx_v2_table_hubs() { global $wpdb; return $wpdb->prefix . 'knx_hubs'; }

/**
 * Column exists helper (cached).
 */
function knx_v2_db_has_column($table, $column) {
    global $wpdb;
    static $cache = [];
    $k = $table . '::' . $column;
    if (array_key_exists($k, $cache)) return (bool)$cache[$k];
    $cache[$k] = (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
    return (bool)$cache[$k];
}

/**
 * Driver link mode helper.
 * Canon expects driver_user_id, but this remains defensive for environments mid-migration.
 */
function knx_v2_drivers_link_col() {
    $tD = knx_v2_table_drivers();
    if (knx_v2_db_has_column($tD, 'driver_user_id')) return 'driver_user_id';
    // legacy fallback (NOT preferred)
    if (knx_v2_db_has_column($tD, 'user_id')) return 'user_id';
    return 'id';
}

/**
 * Resolve user_id from driver row (fail-closed).
 */
function knx_v2_driver_user_id_from_row($driver_row) {
    if (!$driver_row) return 0;
    $col = knx_v2_drivers_link_col();
    $uid = (int)($driver_row->$col ?? 0);
    return $uid > 0 ? $uid : 0;
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
 * Return allowed cities for current session (SSOT)
 * - super_admin => all cities
 * - manager => cities where manager has hubs
 */
function knx_v2_drivers_allowed_cities(WP_REST_Request $req) {
    global $wpdb;

    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    if (!$session) return knx_v2_drivers_resp(false, 'Session required', null, 401);

    $role = $session->role ?? 'guest';
    $tC = knx_v2_table_cities();
    $tH = knx_v2_table_hubs();

    $has_deleted_at = knx_v2_db_has_column($tC, 'deleted_at');

    if ($role === 'super_admin') {
        $where = $has_deleted_at ? "WHERE deleted_at IS NULL" : "";
        $rows = $wpdb->get_results("SELECT id, name FROM {$tC} {$where} ORDER BY name ASC");
        $cities = [];
        foreach (($rows ?: []) as $r) $cities[] = ['id' => (int)$r->id, 'name' => (string)$r->name];
        return knx_v2_drivers_resp(true, 'Cities', ['cities' => $cities]);
    }

    if ($role === 'manager') {
        $manager_id = (int)($session->id ?? 0);
        $hub_city_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id FROM {$tH} WHERE manager_user_id = %d AND city_id IS NOT NULL",
            $manager_id
        ));

        if (empty($hub_city_ids)) {
            return knx_v2_drivers_resp(false, 'No allowed cities for manager', ['cities' => []], 403);
        }

        $placeholders = implode(',', array_fill(0, count($hub_city_ids), '%d'));
        $deleted_where = $has_deleted_at ? 'AND deleted_at IS NULL' : '';
        $sql = $wpdb->prepare(
            "SELECT id, name FROM {$tC} WHERE id IN ({$placeholders}) {$deleted_where} ORDER BY name ASC",
            ...array_map('intval', $hub_city_ids)
        );

        $rows = $wpdb->get_results($sql);
        $cities = [];
        foreach (($rows ?: []) as $r) $cities[] = ['id' => (int)$r->id, 'name' => (string)$r->name];
        return knx_v2_drivers_resp(true, 'Cities', ['cities' => $cities]);
    }

    return knx_v2_drivers_resp(false, 'Forbidden', null, 403);
}

/**
 * Helper: get city_ids for a given driver_id (knx_drivers.id)
 */
function knx_v2_get_driver_city_ids($driver_id) {
    global $wpdb;
    $t = knx_v2_table_driver_cities();
    $rows = $wpdb->get_col($wpdb->prepare("SELECT city_id FROM {$t} WHERE driver_id = %d", (int)$driver_id));
    return array_values(array_unique(array_map('intval', $rows ?: [])));
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
    $tC = knx_v2_table_cities();
    $tH = knx_v2_table_hubs();

    if ($role === 'super_admin') {
        $has_deleted_at = knx_v2_db_has_column($tC, 'deleted_at');
        $where = $has_deleted_at ? "WHERE deleted_at IS NULL" : "";
        $rows = $wpdb->get_col("SELECT id FROM {$tC} {$where}");
        return array_map('intval', $rows ?: []);
    }

    if ($role === 'manager') {
        $manager_id = (int)($session->id ?? 0);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id FROM {$tH} WHERE manager_user_id = %d AND city_id IS NOT NULL",
            $manager_id
        ));
        return array_map('intval', $rows ?: []);
    }

    return [];
}

/**
 * Helper: validate that city ids exist (and not deleted when deleted_at exists).
 */
function knx_v2_cities_exist(array $city_ids) {
    global $wpdb;

    $city_ids = array_values(array_unique(array_filter(array_map('intval', $city_ids), function($v){ return $v > 0; })));
    if (empty($city_ids)) return false;

    $tC = knx_v2_table_cities();
    $has_deleted_at = knx_v2_db_has_column($tC, 'deleted_at');
    $placeholders = implode(',', array_fill(0, count($city_ids), '%d'));
    $deleted_where = $has_deleted_at ? 'AND deleted_at IS NULL' : '';

    $sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$tC} WHERE id IN ({$placeholders}) {$deleted_where}",
        ...$city_ids
    );
    $found = (int)$wpdb->get_var($sql);
    return $found === count($city_ids);
}

/**
 * Helper: set city_ids for driver (delete previous mappings, insert new). Returns true on success
 */
function knx_v2_set_driver_city_ids($driver_id, array $city_ids) {
    global $wpdb;
    $t = knx_v2_table_driver_cities();

    $driver_id = (int)$driver_id;
    $city_ids = array_values(array_unique(array_filter(array_map('intval', $city_ids), function($v){ return $v > 0; })));

    // delete existing
    $wpdb->delete($t, ['driver_id' => $driver_id], ['%d']);

    if (empty($city_ids)) return true;

    foreach ($city_ids as $cid) {
        $ok = $wpdb->insert(
            $t,
            ['driver_id' => $driver_id, 'city_id' => (int)$cid, 'created_at' => current_time('mysql')],
            ['%d','%d','%s']
        );
        if ($ok === false) return false;
    }

    return true;
}

/**
 * Username helpers (custom DB, not WP users).
 */
function knx_v2_users_username_exists($username) {
    global $wpdb;
    $t = knx_v2_table_users();
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE username = %s", $username)) > 0;
}

function knx_v2_users_email_exists($email, $exclude_user_id = 0) {
    global $wpdb;
    $t = knx_v2_table_users();
    $exclude_user_id = (int)$exclude_user_id;

    if ($exclude_user_id > 0) {
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE email = %s AND id != %d",
            $email, $exclude_user_id
        )) > 0;
    }

    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE email = %s",
        $email
    )) > 0;
}

function knx_v2_drivers_email_exists($email, $exclude_driver_id = 0) {
    global $wpdb;
    $t = knx_v2_table_drivers();
    $exclude_driver_id = (int)$exclude_driver_id;

    if ($exclude_driver_id > 0) {
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE email = %s AND id != %d",
            $email, $exclude_driver_id
        )) > 0;
    }

    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE email = %s",
        $email
    )) > 0;
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
    return substr($base, 0, 32);
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
 * Helper: load driver row by driver_id (fail-closed).
 */
function knx_v2_driver_row_by_id($driver_id) {
    global $wpdb;
    $tD = knx_v2_table_drivers();
    $driver_id = (int)$driver_id;

    if ($driver_id <= 0) return null;

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tD} WHERE id = %d LIMIT 1",
        $driver_id
    ));
}

/**
 * Helper: manager scoping by driver_id (fail-closed)
 */
function knx_v2_driver_in_manager_scope($driver_id) {
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';
    if ($role !== 'manager') return true;

    $allowed = knx_v2_allowed_city_ids_for_session();
    if (empty($allowed)) return false;

    $driver_city_ids = knx_v2_get_driver_city_ids((int)$driver_id);
    return !empty(array_intersect($allowed, $driver_city_ids));
}

/**
 * ==========================================================
 * GET LIST (returns driver_id + linked user)
 * ==========================================================
 */
function knx_v2_drivers_list(WP_REST_Request $req) {
    global $wpdb;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();
    $link_col = knx_v2_drivers_link_col();

    $page = max(1, (int)$req->get_param('page'));
    $per_page = max(1, min(100, (int)($req->get_param('per_page') ?: 20)));
    $offset = ($page - 1) * $per_page;

    $q = sanitize_text_field((string)$req->get_param('q'));
    $status = sanitize_text_field((string)$req->get_param('status'));

    $where = ['1=1'];
    $vals = [];

    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $q_int = (int)$q;

        $where[] = "(d.full_name LIKE %s OR d.email LIKE %s OR d.phone LIKE %s OR u.username LIKE %s OR d.id = %d OR d.{$link_col} = %d)";
        $vals[] = $like;
        $vals[] = $like;
        $vals[] = $like;
        $vals[] = $like;
        $vals[] = $q_int;
        $vals[] = $q_int;
    }

    if ($status && in_array($status, ['active','inactive'], true)) {
        $where[] = "d.status = %s";
        $vals[] = $status;
    }

    // exclude soft-deleted drivers
    if (knx_v2_db_has_column($tD, 'deleted_at')) {
        $where[] = "d.deleted_at IS NULL";
    }

    // enforce manager scoping (fail-closed)
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

    $count_sql = "SELECT COUNT(*) FROM {$tD} d LEFT JOIN {$tU} u ON u.id = d.{$link_col} WHERE {$where_sql}";
    $count_sql = $vals ? $wpdb->prepare($count_sql, ...$vals) : $count_sql;
    $total = (int)$wpdb->get_var($count_sql);

    $sql = "SELECT
              d.id,
              d.{$link_col} AS driver_user_id,
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
            LEFT JOIN {$tU} u ON u.id = d.{$link_col}
            WHERE {$where_sql}
            ORDER BY d.created_at DESC
            LIMIT %d OFFSET %d";

    $sql_vals = array_merge($vals, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$sql_vals));

    $drivers = [];
    foreach (($rows ?: []) as $r) {
        $driver_id = (int)$r->id;
        $drivers[] = [
            'id'           => $driver_id,
            'driver_user_id' => (int)($r->driver_user_id ?? 0),
            'full_name'    => (string)$r->full_name,
            'phone'        => (string)($r->phone ?? ''),
            'email'        => (string)($r->email ?? ''),
            'vehicle_info' => (string)($r->vehicle_info ?? ''),
            'status'       => (string)$r->status,
            'created_at'   => (string)$r->created_at,
            'updated_at'   => (string)$r->updated_at,
            'user' => [
                'id'       => (int)($r->driver_user_id ?? 0),
                'username' => (string)($r->username ?? ''),
                'role'     => (string)($r->role ?? ''),
                'status'   => (string)($r->user_status ?? ''),
            ],
            'city_ids'     => knx_v2_get_driver_city_ids($driver_id),
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
 * - knx_users row (role=driver) -> user_id
 * - knx_drivers row (AUTO id) linked via driver_user_id=user_id
 * - knx_driver_cities mappings (driver_id = knx_drivers.id)
 * ==========================================================
 */
function knx_v2_drivers_create(WP_REST_Request $req) {
    global $wpdb;

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tU = knx_v2_table_users();
    $tD = knx_v2_table_drivers();

    $full_name = isset($body['full_name']) ? trim(sanitize_text_field((string)$body['full_name'])) : '';
    $email     = isset($body['email']) ? trim(sanitize_email((string)$body['email'])) : '';
    $phone     = isset($body['phone']) ? trim(sanitize_text_field((string)$body['phone'])) : '';
    $vehicle   = isset($body['vehicle_info']) ? trim(sanitize_text_field((string)$body['vehicle_info'])) : '';
    $status    = (isset($body['status']) && in_array($body['status'], ['active','inactive'], true)) ? (string)$body['status'] : 'active';

    if ($full_name === '') return knx_v2_drivers_resp(false, 'full_name is required.', null, 400);
    if ($email === '' || !is_email($email)) return knx_v2_drivers_resp(false, 'Valid email is required.', null, 400);
    if ($phone === '') return knx_v2_drivers_resp(false, 'phone is required.', null, 400);

    if (!isset($body['city_ids']) || !is_array($body['city_ids']) || empty($body['city_ids'])) {
        return knx_v2_drivers_resp(false, 'city_ids is required.', null, 400);
    }

    $city_ids = array_values(array_unique(array_filter(array_map('intval', $body['city_ids']), function($v){ return $v > 0; })));
    if (empty($city_ids)) return knx_v2_drivers_resp(false, 'city_ids is required.', null, 400);

    // enforce session scoping (manager cannot assign outside their allowed cities)
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';
    if ($role === 'manager') {
        $allowed = knx_v2_allowed_city_ids_for_session();
        if (empty($allowed)) return knx_v2_drivers_resp(false, 'No allowed cities for manager', null, 403);
        foreach ($city_ids as $cid) {
            if (!in_array((int)$cid, $allowed, true)) return knx_v2_drivers_resp(false, 'City id not allowed for manager', null, 403);
        }
    }

    if (!knx_v2_cities_exist($city_ids)) {
        return knx_v2_drivers_resp(false, 'One or more city_ids do not exist.', null, 409);
    }

    // Uniqueness checks (both tables)
    if (knx_v2_users_email_exists($email)) return knx_v2_drivers_resp(false, 'Email already exists in knx_users.', null, 409);
    if (knx_v2_drivers_email_exists($email)) return knx_v2_drivers_resp(false, 'Email already exists in knx_drivers.', null, 409);

    $username = knx_v2_unique_username($email, $full_name);

    $temp_password_plain = wp_generate_password(14, true, true);
    $temp_password_hash  = password_hash($temp_password_plain, PASSWORD_BCRYPT);

    $now = current_time('mysql');

    $wpdb->query('START TRANSACTION');

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
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to create knx_user.', null, 500);
    }

    $user_id = (int)$wpdb->insert_id;
    if ($user_id <= 0) {
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to resolve new user_id.', null, 500);
    }

    // 2) Insert knx_drivers (AUTO driver_id) linked by driver_user_id
    $driver_insert = [
        'full_name'    => $full_name,
        'phone'        => $phone, // drivers.phone is NOT NULL in prod schema
        'email'        => $email,
        'vehicle_info' => ($vehicle !== '' ? $vehicle : null),
        'status'       => $status,
        'created_at'   => $now,
        'updated_at'   => $now,
    ];

    // Canon: driver_user_id
    $driver_insert['driver_user_id'] = $user_id;

    // Optional legacy bridge: keep user_id aligned if column exists
    if (knx_v2_db_has_column($tD, 'user_id')) {
        $driver_insert['user_id'] = $user_id;
    }

    $formats = [
        '%s','%s','%s','%s','%s','%s','%s',
        '%d'
    ];
    if (array_key_exists('user_id', $driver_insert)) $formats[] = '%d';

    $okD = $wpdb->insert($tD, $driver_insert, $formats);

    if (!$okD) {
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to create driver profile.', null, 500);
    }

    $driver_id = (int)$wpdb->insert_id;
    if ($driver_id <= 0) {
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to resolve new driver_id.', null, 500);
    }

    // 3) Persist city mappings (required)
    $okMap = knx_v2_set_driver_city_ids($driver_id, $city_ids);
    if ($okMap === false) {
        $wpdb->query('ROLLBACK');
        return knx_v2_drivers_resp(false, 'Failed to persist driver city mappings.', null, 500);
    }

    $wpdb->query('COMMIT');

    return knx_v2_drivers_resp(true, 'Driver created.', [
        'driver_id'      => $driver_id,
        'driver_user_id' => $user_id,
        'username'       => $username,
        'temp_password'  => $temp_password_plain,
    ], 201);
}

/**
 * ==========================================================
 * GET SINGLE (id = driver_id)
 * ==========================================================
 */
function knx_v2_drivers_get(WP_REST_Request $req) {
    global $wpdb;

    $driver_id = (int)$req->get_param('id');
    if ($driver_id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();
    $link_col = knx_v2_drivers_link_col();

    $has_deleted_at = knx_v2_db_has_column($tD, 'deleted_at');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT
           d.id,
           d.{$link_col} AS driver_user_id,
           d.full_name,
           d.phone,
           d.email,
           d.vehicle_info,
           d.status,
           d.created_at,
           d.updated_at,
           d.deleted_at,
           u.username,
           u.role,
           u.status AS user_status
         FROM {$tD} d
         LEFT JOIN {$tU} u ON u.id = d.{$link_col}
         WHERE d.id = %d " . ($has_deleted_at ? "AND d.deleted_at IS NULL " : "") . "
         LIMIT 1",
        $driver_id
    ));

    if (!$row) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    // Manager scoping
    if (!knx_v2_driver_in_manager_scope($driver_id)) {
        return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

    return knx_v2_drivers_resp(true, 'Driver', [
        'id'             => (int)$row->id,
        'driver_user_id' => (int)($row->driver_user_id ?? 0),
        'full_name'      => (string)$row->full_name,
        'phone'          => (string)($row->phone ?? ''),
        'email'          => (string)($row->email ?? ''),
        'vehicle_info'   => (string)($row->vehicle_info ?? ''),
        'status'         => (string)$row->status,
        'created_at'     => (string)$row->created_at,
        'updated_at'     => (string)$row->updated_at,
        'user' => [
            'id'       => (int)($row->driver_user_id ?? 0),
            'username' => (string)($row->username ?? ''),
            'role'     => (string)($row->role ?? ''),
            'status'   => (string)($row->user_status ?? ''),
        ],
        'city_ids' => knx_v2_get_driver_city_ids($driver_id),
    ]);
}

/**
 * ==========================================================
 * POST UPDATE (id = driver_id)
 * Updates driver row + linked user row.
 * Allowed:
 * - full_name, email, phone, vehicle_info, status, city_ids
 * ==========================================================
 */
function knx_v2_drivers_update(WP_REST_Request $req) {
    global $wpdb;

    $driver_id = (int)$req->get_param('id');
    if ($driver_id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $curD = knx_v2_driver_row_by_id($driver_id);
    if (!$curD) return knx_v2_drivers_resp(false, 'Driver not found.', null, 404);

    if (knx_v2_db_has_column($tD, 'deleted_at') && !empty($curD->deleted_at)) {
        return knx_v2_drivers_resp(false, 'Driver not found.', null, 404);
    }

    // Manager scoping
    if (!knx_v2_driver_in_manager_scope($driver_id)) {
        return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

    $user_id = knx_v2_driver_user_id_from_row($curD);
    if ($user_id <= 0) return knx_v2_drivers_resp(false, 'Driver link is invalid (missing driver_user_id).', null, 409);

    $curU = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tU} WHERE id = %d LIMIT 1", $user_id));
    if (!$curU) return knx_v2_drivers_resp(false, 'Linked user not found for this driver.', null, 404);

    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $role = $session->role ?? 'guest';

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
        if ($v === '') return knx_v2_drivers_resp(false, 'phone cannot be empty.', null, 400);
        $updD['phone'] = $v; // NOT NULL in drivers schema
        $updU['phone'] = $v;
    }

    if (isset($body['vehicle_info'])) {
        $v = trim(sanitize_text_field((string)$body['vehicle_info']));
        $updD['vehicle_info'] = ($v !== '' ? $v : null);
    }

    if (isset($body['status']) && in_array($body['status'], ['active','inactive'], true)) {
        $updD['status'] = (string)$body['status'];
        $updU['status'] = (string)$body['status'];
    }

    if (isset($body['email'])) {
        $v = trim(sanitize_email((string)$body['email']));
        if ($v === '' || !is_email($v)) return knx_v2_drivers_resp(false, 'Valid email required.', null, 400);

        // uniqueness across both tables excluding current records
        if (knx_v2_users_email_exists($v, $user_id)) {
            return knx_v2_drivers_resp(false, 'Email already exists in knx_users.', null, 409);
        }
        if (knx_v2_drivers_email_exists($v, $driver_id)) {
            return knx_v2_drivers_resp(false, 'Email already exists in knx_drivers.', null, 409);
        }

        $updD['email'] = $v;
        $updU['email'] = $v;
    }

    $has_city_update = (isset($body['city_ids']) && is_array($body['city_ids']));

    if (empty($updD) && empty($updU) && !$has_city_update) {
        return knx_v2_drivers_resp(false, 'Nothing to update.', null, 400);
    }

    $now = current_time('mysql');
    $updD['updated_at'] = $now;
    $updU['updated_at'] = $now;

    $wpdb->query('START TRANSACTION');

    if (!empty($updD)) {
        $okD = $wpdb->update($tD, $updD, ['id' => $driver_id]);
        if ($okD === false) {
            $wpdb->query('ROLLBACK');
            return knx_v2_drivers_resp(false, 'Failed to update driver (DB).', null, 500);
        }
    }

    if (!empty($updU)) {
        $okU = $wpdb->update($tU, $updU, ['id' => $user_id]);
        if ($okU === false) {
            $wpdb->query('ROLLBACK');
            return knx_v2_drivers_resp(false, 'Failed to update user (DB).', null, 500);
        }
    }

    // Handle city_ids transactionally if provided
    if ($has_city_update) {
        $new_city_ids = array_values(array_unique(array_filter(array_map('intval', $body['city_ids']), function($v){ return $v > 0; })));

        if (empty($new_city_ids)) {
            $wpdb->query('ROLLBACK');
            return knx_v2_drivers_resp(false, 'city_ids cannot be empty.', null, 400);
        }

        // manager may not assign outside their allowed cities
        if ($role === 'manager') {
            $allowed = knx_v2_allowed_city_ids_for_session();
            if (empty($allowed)) {
                $wpdb->query('ROLLBACK');
                return knx_v2_drivers_resp(false, 'No allowed cities for manager', null, 403);
            }
            foreach ($new_city_ids as $cid) {
                if (!in_array((int)$cid, $allowed, true)) {
                    $wpdb->query('ROLLBACK');
                    return knx_v2_drivers_resp(false, 'City id not allowed for manager', null, 403);
                }
            }
        }

        if (!knx_v2_cities_exist($new_city_ids)) {
            $wpdb->query('ROLLBACK');
            return knx_v2_drivers_resp(false, 'One or more city_ids do not exist.', null, 409);
        }

        $okMap = knx_v2_set_driver_city_ids($driver_id, $new_city_ids);
        if ($okMap === false) {
            $wpdb->query('ROLLBACK');
            return knx_v2_drivers_resp(false, 'Failed to persist driver city mappings.', null, 500);
        }
    }

    $wpdb->query('COMMIT');

    return knx_v2_drivers_resp(true, 'Driver updated.', [
        'driver_id'      => $driver_id,
        'driver_user_id' => $user_id
    ]);
}

/**
 * ==========================================================
 * POST TOGGLE (active <-> inactive) — updates driver + linked user
 * id = driver_id
 * ==========================================================
 */
function knx_v2_drivers_toggle(WP_REST_Request $req) {
    global $wpdb;

    $driver_id = (int)$req->get_param('id');
    if ($driver_id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $row = knx_v2_driver_row_by_id($driver_id);
    if (!$row) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    if (knx_v2_db_has_column($tD, 'deleted_at') && !empty($row->deleted_at)) {
        return knx_v2_drivers_resp(false, 'Not found.', null, 404);
    }

    // Manager scoping
    if (!knx_v2_driver_in_manager_scope($driver_id)) {
        return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

    $user_id = knx_v2_driver_user_id_from_row($row);
    if ($user_id <= 0) return knx_v2_drivers_resp(false, 'Driver link is invalid (missing driver_user_id).', null, 409);

    $new_status = ((string)($row->status ?? 'inactive') === 'active') ? 'inactive' : 'active';
    $now = current_time('mysql');

    // Prevent activating a driver that has no assigned cities
    if ($new_status === 'active') {
        $assigned = knx_v2_get_driver_city_ids($driver_id);
        if (empty($assigned)) {
            return knx_v2_drivers_resp(false, 'Driver has no assigned cities.', null, 409);
        }
    }

    $okD = $wpdb->update($tD, ['status' => $new_status, 'updated_at' => $now], ['id' => $driver_id], ['%s','%s'], ['%d']);
    if ($okD === false) return knx_v2_drivers_resp(false, 'Failed to toggle driver status.', null, 500);

    $okU = $wpdb->update($tU, ['status' => $new_status, 'updated_at' => $now], ['id' => $user_id], ['%s','%s'], ['%d']);
    if ($okU === false) return knx_v2_drivers_resp(false, 'Failed to toggle user status.', null, 500);

    return knx_v2_drivers_resp(true, 'Status toggled.', [
        'driver_id'      => $driver_id,
        'driver_user_id' => $user_id,
        'status'         => $new_status
    ]);
}

/**
 * ==========================================================
 * POST SOFT-DELETE — mark driver as deleted (soft)
 * id = driver_id
 * ==========================================================
 */
function knx_v2_drivers_soft_delete(WP_REST_Request $req) {
    global $wpdb;

    $driver_id = (int)$req->get_param('id');
    if ($driver_id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $row = knx_v2_driver_row_by_id($driver_id);
    if (!$row) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    if (knx_v2_db_has_column($tD, 'deleted_at') && !empty($row->deleted_at)) {
        return knx_v2_drivers_resp(false, 'Not found.', null, 404);
    }

    // Manager scoping
    if (!knx_v2_driver_in_manager_scope($driver_id)) {
        return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

    $user_id = knx_v2_driver_user_id_from_row($row);
    if ($user_id <= 0) return knx_v2_drivers_resp(false, 'Driver link is invalid (missing driver_user_id).', null, 409);

    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    $deleted_by = (int)($session->id ?? 0);

    $now = current_time('mysql');
    $reason = isset($body['reason']) ? trim(sanitize_text_field((string)$body['reason'])) : '';

    $fields = [
        'updated_at' => $now,
        'status'     => 'inactive',
    ];
    $formats = ['%s','%s'];

    if (knx_v2_db_has_column($tD, 'deleted_at')) {
        $fields['deleted_at'] = $now;
        $formats[] = '%s';
    }
    if (knx_v2_db_has_column($tD, 'deleted_by')) {
        $fields['deleted_by'] = $deleted_by;
        $formats[] = '%d';
    }
    if (knx_v2_db_has_column($tD, 'deleted_reason')) {
        $fields['deleted_reason'] = ($reason !== '' ? $reason : null);
        $formats[] = '%s';
    }

    $ok = $wpdb->update($tD, $fields, ['id' => $driver_id], $formats, ['%d']);
    if ($ok === false) return knx_v2_drivers_resp(false, 'Failed to soft-delete driver.', null, 500);

    // Also mark user as inactive to prevent login
    $wpdb->update($tU, ['status' => 'inactive', 'updated_at' => $now], ['id' => $user_id], ['%s','%s'], ['%d']);

    return knx_v2_drivers_resp(true, 'Driver soft-deleted.', [
        'driver_id'      => $driver_id,
        'driver_user_id' => $user_id
    ]);
}

/**
 * ==========================================================
 * POST RESET PASSWORD — updates knx_users.password only
 * id = driver_id
 * ==========================================================
 */
function knx_v2_drivers_reset_password(WP_REST_Request $req) {
    global $wpdb;

    $driver_id = (int)$req->get_param('id');
    if ($driver_id <= 0) return knx_v2_drivers_resp(false, 'Invalid id.', null, 400);

    $body = knx_v2_body($req);
    if ($deny = knx_v2_require_knx_nonce($body)) return $deny;

    $tD = knx_v2_table_drivers();
    $tU = knx_v2_table_users();

    $driver = knx_v2_driver_row_by_id($driver_id);
    if (!$driver) return knx_v2_drivers_resp(false, 'Not found.', null, 404);

    if (knx_v2_db_has_column($tD, 'deleted_at') && !empty($driver->deleted_at)) {
        return knx_v2_drivers_resp(false, 'Not found.', null, 404);
    }

    // Manager scoping
    if (!knx_v2_driver_in_manager_scope($driver_id)) {
        return knx_v2_drivers_resp(false, 'Driver out of scope for manager', null, 403);
    }

    $user_id = knx_v2_driver_user_id_from_row($driver);
    if ($user_id <= 0) return knx_v2_drivers_resp(false, 'Driver link is invalid (missing driver_user_id).', null, 409);

    $user = $wpdb->get_row($wpdb->prepare("SELECT id, username, email FROM {$tU} WHERE id = %d LIMIT 1", $user_id));
    if (!$user) return knx_v2_drivers_resp(false, 'User not found.', null, 404);

    $new_plain = wp_generate_password(14, true, true);
    $new_hash  = password_hash($new_plain, PASSWORD_BCRYPT);

    $ok = $wpdb->update($tU, [
        'password'   => $new_hash,
        'updated_at' => current_time('mysql'),
    ], ['id' => $user_id], ['%s','%s'], ['%d']);

    if ($ok === false) return knx_v2_drivers_resp(false, 'Failed to reset password.', null, 500);

    return knx_v2_drivers_resp(true, 'Password reset.', [
        'driver_id'      => $driver_id,
        'driver_user_id' => (int)$user->id,
        'username'       => (string)$user->username,
        'email'          => (string)$user->email,
        'temp_password'  => $new_plain,
    ]);
}
