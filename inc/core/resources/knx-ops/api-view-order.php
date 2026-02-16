<?php
// inc/core/resources/knx-ops/api-view-order.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — View Order (Operational Read)
 * Endpoint: GET /wp-json/knx/v1/ops/view-order
 *
 * Notes:
 * - Fail-closed: requires session + role + scoped cities for managers.
 * - Manager scope is resolved via {prefix}knx_manager_cities (NOT hubs.manager_user_id).
 * - Hubs are the "restaurants" (no restaurants table).
 * - Payload may include order_id for internal navigation, but UI must not display IDs.
 *
 * Timeline:
 * - Adds a VIRTUAL first event: "order_created" using orders.created_at (NOT stored in DB).
 * - Hides pending_payment completely from OPS timeline.
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/view-order', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_ops_view_order')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Resolve manager allowed city IDs based on {prefix}knx_manager_cities.
 *
 * Fail-closed returns [] if table missing or no rows found.
 *
 * @param int $manager_user_id
 * @return array<int>
 */
function knx_ops_view_order_manager_city_ids($manager_user_id) {
    global $wpdb;

    $table = $wpdb->prefix . 'knx_manager_cities';

    // Fail-closed if table does not exist
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (empty($exists)) return [];

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id
         FROM {$table}
         WHERE manager_user_id = %d
           AND city_id IS NOT NULL",
        (int)$manager_user_id
    ));

    $ids = array_map('intval', (array)$ids);
    $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));

    return $ids;
}

/**
 * Best-effort: detect a lat/lng pair on orders table for "View Location".
 * Cached per request.
 *
 * @return array{lat:string,lng:string}|null
 */
function knx_ops_view_order_detect_latlng_columns() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $candidates = [
        ['lat' => 'delivery_lat', 'lng' => 'delivery_lng'],
        ['lat' => 'address_lat',  'lng' => 'address_lng'],
        ['lat' => 'lat',          'lng' => 'lng'],
    ];

    foreach ($candidates as $pair) {
        $lat_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", $pair['lat']));
        $lng_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", $pair['lng']));
        if (!empty($lat_exists) && !empty($lng_exists)) {
            $cache = $pair;
            return $cache;
        }
    }

    $cache = null;
    return null;
}

/**
 * Best-effort: detect a notes column on orders table.
 *
 * @return string|null
 */
function knx_ops_view_order_detect_notes_column() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $candidates = ['notes', 'order_notes', 'customer_notes', 'delivery_notes', 'instructions'];

    foreach ($candidates as $col) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", $col));
        if (!empty($exists)) {
            $cache = $col;
            return $cache;
        }
    }

    $cache = null;
    return null;
}

/**
 * Best-effort: detect an items/snapshot JSON column on orders table.
 *
 * @return string|null
 */
function knx_ops_view_order_detect_items_column() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $candidates = ['items_json', 'cart_json', 'snapshot_json', 'order_snapshot', 'cart_snapshot', 'items'];

    foreach ($candidates as $col) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$orders_table} LIKE %s", $col));
        if (!empty($exists)) {
            $cache = $col;
            return $cache;
        }
    }

    $cache = null;
    return null;
}

/**
 * Best-effort: detect customer fields on orders table.
 *
 * @return array{customer_id:?string,name:?string,phone:?string,email:?string}
 */
function knx_ops_view_order_detect_orders_customer_columns() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$orders_table}");
    $cols = is_array($cols) ? array_map('strtolower', $cols) : [];

    $pickFirst = static function(array $candidates) use ($cols) {
        foreach ($candidates as $c) {
            if (in_array(strtolower($c), $cols, true)) return $c;
        }
        return null;
    };

    $cache = [
        'customer_id' => $pickFirst(['customer_id', 'user_id', 'client_id']),
        'name'        => $pickFirst(['customer_name', 'client_name', 'name', 'customer_full_name']),
        'phone'       => $pickFirst(['customer_phone', 'phone', 'phone_number', 'customer_phone_number']),
        'email'       => $pickFirst(['customer_email', 'email', 'customer_email_address']),
    ];

    return $cache;
}

/**
 * Best-effort: detect useful restaurant (hub) columns.
 *
 * @return array{phone:?string,email:?string,address:?string,logo:?string}
 */
function knx_ops_view_order_detect_hub_columns() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $hubs_table = $wpdb->prefix . 'knx_hubs';

    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$hubs_table}");
    $cols = is_array($cols) ? array_map('strtolower', $cols) : [];

    $pickFirst = static function(array $candidates) use ($cols) {
        foreach ($candidates as $c) {
            if (in_array(strtolower($c), $cols, true)) return $c;
        }
        return null;
    };

    $cache = [
        'phone'   => $pickFirst(['phone', 'phone_number', 'contact_phone', 'business_phone']),
        'email'   => $pickFirst(['email', 'contact_email', 'business_email']),
        'address' => $pickFirst(['address', 'full_address', 'street_address', 'location_address']),
        'logo'    => $pickFirst(['logo_url', 'image_url', 'photo_url', 'logo', 'image']),
    ];

    return $cache;
}

/**
 * Best-effort: detect delivery/payment columns on orders table.
 *
 * @return array{
 *   delivery_method:?string,delivery_address:?string,delivery_time_slot:?string,
 *   payment_method:?string,payment_status:?string,fulfillment_type:?string
 * }
 */
function knx_ops_view_order_detect_orders_delivery_payment_columns() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $orders_table = $wpdb->prefix . 'knx_orders';

    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$orders_table}");
    $cols = is_array($cols) ? array_map('strtolower', $cols) : [];

    $pickFirst = static function(array $candidates) use ($cols) {
        foreach ($candidates as $c) {
            if (in_array(strtolower($c), $cols, true)) return $c;
        }
        return null;
    };

    $cache = [
        'delivery_method'    => $pickFirst(['delivery_method', 'fulfillment_method', 'method']),
        'delivery_address'   => $pickFirst(['delivery_address', 'delivery_address_text', 'address', 'customer_address']),
        'delivery_time_slot' => $pickFirst(['delivery_time_slot', 'time_slot', 'slot_label', 'delivery_slot']),
        'payment_method'     => $pickFirst(['payment_method', 'pay_method', 'stripe_method', 'method_payment']),
        'payment_status'     => $pickFirst(['payment_status', 'pay_status', 'stripe_status', 'paid_status']),
        'fulfillment_type'   => $pickFirst(['fulfillment_type', 'delivery_type', 'order_type']),
    ];

    return $cache;
}

/**
 * Format "created_human" with a friendly "Just now" for sub-60s.
 *
 * @param int $age_seconds
 * @return string
 */
function knx_ops_view_order_human_age($age_seconds) {
    $age_seconds = (int)$age_seconds;
    if ($age_seconds <= 60) return 'Just now';

    if (function_exists('human_time_diff')) {
        $created_ts = time() - $age_seconds;
        return human_time_diff($created_ts, time()) . ' ago';
    }

    $mins = max(1, (int)floor($age_seconds / 60));
    return $mins . ' min ago';
}

/**
 * Optional: best-effort detect driver table and name column.
 * If not found, we keep driver name null.
 *
 * @return array{table:string,name_col:string}|null
 */
function knx_ops_view_order_detect_driver_name_source() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $table = $wpdb->prefix . 'knx_drivers';

    // Check table exists
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (empty($table_exists)) {
        $cache = null;
        return null;
    }

    $candidates = ['full_name', 'name', 'driver_name', 'display_name'];

    foreach ($candidates as $col) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        if (!empty($exists)) {
            $cache = ['table' => $table, 'name_col' => $col];
            return $cache;
        }
    }

    $cache = null;
    return null;
}

/**
 * Detect status history table.
 *
 * @return string|null
 */
function knx_ops_view_order_detect_status_history_table() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $candidates = [
        $wpdb->prefix . 'knx_order_status_history',
        $wpdb->prefix . 'knx_orders_status_history',
        $wpdb->prefix . 'knx_status_history',
    ];

    foreach ($candidates as $t) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
        if (!empty($exists)) {
            $cache = $t;
            return $cache;
        }
    }

    $cache = null;
    return null;
}

/**
 * Normalize a mysql datetime to an ISO8601 string in WP timezone.
 *
 * @param string $mysql
 * @return string|null
 */
function knx_ops_view_order_iso_from_mysql($mysql) {
    $mysql = trim((string)$mysql);
    if ($mysql === '') return null;

    $ts = strtotime($mysql);
    if (!$ts) return null;

    if (function_exists('wp_date')) {
        return wp_date('c', $ts);
    }

    // Fallback: server timezone ISO8601
    return date('c', $ts);
}

/**
 * Resolve a "changed_by_label" best-effort.
 * - null/0 => "System"
 * - numeric => WP user display_name when possible, else "User #ID"
 *
 * @param int|null $user_id
 * @return string
 */
function knx_ops_view_order_changed_by_label($user_id) {
    global $wpdb;

    $uid = (int)($user_id ?? 0);
    if ($uid <= 0) return 'System';

    // Best-effort WP users table
    try {
        $users_table = $wpdb->users; // wp_users with correct prefix
        if (!empty($users_table)) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $users_table));
            if (!empty($exists)) {
                $name = $wpdb->get_var($wpdb->prepare("SELECT display_name FROM {$users_table} WHERE ID = %d LIMIT 1", $uid));
                $name = trim((string)$name);
                if ($name !== '') return $name;
            }
        }
    } catch (Throwable $e) {
        // fail-soft
    }

    return 'User #' . $uid;
}

/**
 * Best-effort: detect columns on {prefix}knx_users table.
 *
 * @return array{table:?string,name:?string,phone:?string,email:?string}
 */
function knx_ops_view_order_detect_knx_users_columns() {
    global $wpdb;
    static $cache = null;
    if ($cache !== null) return $cache;

    $users_table = $wpdb->prefix . 'knx_users';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $users_table));
    if (empty($exists)) {
        $cache = ['table' => null, 'name' => null, 'phone' => null, 'email' => null];
        return $cache;
    }

    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$users_table}");
    $cols = is_array($cols) ? array_map('strtolower', $cols) : [];

    $pickFirst = static function(array $candidates) use ($cols) {
        foreach ($candidates as $c) {
            if (in_array(strtolower($c), $cols, true)) return $c;
        }
        return null;
    };

    $cache = [
        'table' => $users_table,
        'name'  => $pickFirst(['name', 'full_name', 'display_name', 'customer_name']),
        'phone' => $pickFirst(['phone', 'phone_number', 'mobile', 'cell']),
        'email' => $pickFirst(['email', 'user_email']),
    ];

    return $cache;
}

/**
 * Best-effort: fetch customer fields from Nexus users table.
 *
 * @param int $customer_id
 * @return array{name:?string,phone:?string,email:?string}
 */
function knx_ops_view_order_fetch_customer_from_users($customer_id) {
    global $wpdb;

    $out = ['name' => null, 'phone' => null, 'email' => null];

    $customer_id = (int)$customer_id;
    if ($customer_id <= 0) return $out;

    $map = knx_ops_view_order_detect_knx_users_columns();
    if (empty($map['table'])) return $out;

    $table = (string)$map['table'];

    $name_col  = !empty($map['name']) ? ('`' . esc_sql($map['name']) . '`') : "''";
    $phone_col = !empty($map['phone']) ? ('`' . esc_sql($map['phone']) . '`') : "''";
    $email_col = !empty($map['email']) ? ('`' . esc_sql($map['email']) . '`') : "''";

    $u = $wpdb->get_row($wpdb->prepare(
        "SELECT {$name_col} AS name, {$phone_col} AS phone, {$email_col} AS email
         FROM {$table}
         WHERE id = %d
         LIMIT 1",
        $customer_id
    ));
    if (!$u) return $out;

    $nm = trim((string)($u->name ?? ''));
    $ph = trim((string)($u->phone ?? ''));
    $em = trim((string)($u->email ?? ''));

    $out['name']  = ($nm !== '') ? $nm : null;
    $out['phone'] = ($ph !== '') ? $ph : null;
    $out['email'] = ($em !== '') ? $em : null;

    return $out;
}

/**
 * Fetch status history rows (ASC) and hide pending_payment completely.
 *
 * Output shape:
 * - status
 * - created_at (mysql)
 * - created_at_iso
 * - changed_by
 * - changed_by_label
 *
 * @param int $order_id
 * @return array<int, array<string,mixed>>
 */
function knx_ops_view_order_fetch_status_history($order_id) {
    global $wpdb;

    $order_id = (int)$order_id;
    if ($order_id <= 0) return [];

    $table = knx_ops_view_order_detect_status_history_table();
    if (!$table) return [];

    // Best-effort: detect columns (fail-soft)
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
    $cols = is_array($cols) ? array_map('strtolower', $cols) : [];

    $has_changed_by = in_array('changed_by', $cols, true);
    $has_created_at = in_array('created_at', $cols, true);
    $has_status     = in_array('status', $cols, true);

    if (!$has_status || !$has_created_at) return [];

    $select = "status, created_at";
    if ($has_changed_by) $select .= ", changed_by";

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT {$select}
         FROM {$table}
         WHERE order_id = %d
         ORDER BY created_at ASC",
        $order_id
    ));

    $out = [];
    foreach ((array)$rows as $r) {
        $st = strtolower(trim((string)$r->status));
        if ($st === '' || $st === 'pending_payment') {
            // Hide completely in OPS timeline
            continue;
        }

        // Also ignore any accidental "order_created" rows if someone ever inserted them
        if ($st === 'order_created' || $st === 'created') {
            continue;
        }

        $changed_by = null;
        if ($has_changed_by && isset($r->changed_by)) {
            $cb = (int)$r->changed_by;
            $changed_by = ($cb > 0) ? $cb : null;
        }

        $created_mysql = (string)$r->created_at;

        $out[] = [
            'status' => $st,
            'changed_by' => $changed_by,
            'changed_by_label' => knx_ops_view_order_changed_by_label($changed_by),
            'created_at' => $created_mysql,
            'created_at_iso' => knx_ops_view_order_iso_from_mysql($created_mysql),
        ];
    }

    return $out;
}

function knx_ops_view_order(WP_REST_Request $request) {
    global $wpdb;

    // Require session + allowed roles (fail-closed)
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    $order_id = (int)$request->get_param('order_id');
    if ($order_id <= 0) {
        return knx_rest_error('order_id is required and must be a positive integer', 400);
    }

    $orders_table = $wpdb->prefix . 'knx_orders';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    // Optional columns
    $latlng       = knx_ops_view_order_detect_latlng_columns();
    $notes_col    = knx_ops_view_order_detect_notes_column();
    $items_col    = knx_ops_view_order_detect_items_column();
    $hub_cols     = knx_ops_view_order_detect_hub_columns();
    $dp_cols      = knx_ops_view_order_detect_orders_delivery_payment_columns();
    $cust_cols    = knx_ops_view_order_detect_orders_customer_columns();

    $select_latlng = '';
    if ($latlng) {
        $select_latlng = ", o.`{$latlng['lat']}` AS lat, o.`{$latlng['lng']}` AS lng";
    }

    $select_notes = '';
    if ($notes_col) {
        $select_notes = ", o.`{$notes_col}` AS notes";
    }

    $select_items = '';
    if ($items_col) {
        $select_items = ", o.`{$items_col}` AS items_json";
    }

    // Delivery/payment best-effort from orders table
    $select_delivery_payment = '';
    $dp_alias = [
        'delivery_method'    => 'delivery_method',
        'delivery_address'   => 'delivery_address',
        'delivery_time_slot' => 'delivery_time_slot',
        'payment_method'     => 'payment_method',
        'payment_status'     => 'payment_status',
        'fulfillment_type'   => 'fulfillment_type', // for inference
    ];
    foreach ($dp_alias as $k => $alias) {
        if (!empty($dp_cols[$k])) {
            $col = $dp_cols[$k];
            $select_delivery_payment .= ", o.`{$col}` AS {$alias}";
        }
    }

    // Customer best-effort from orders table (avoid SQL errors if missing)
    $select_customer = '';
    $select_customer .= !empty($cust_cols['customer_id'])
        ? ", o.`{$cust_cols['customer_id']}` AS customer_id"
        : ", 0 AS customer_id";

    $select_customer .= !empty($cust_cols['name'])
        ? ", o.`{$cust_cols['name']}` AS customer_name"
        : ", '' AS customer_name";

    $select_customer .= !empty($cust_cols['phone'])
        ? ", o.`{$cust_cols['phone']}` AS customer_phone"
        : ", '' AS customer_phone";

    $select_customer .= !empty($cust_cols['email'])
        ? ", o.`{$cust_cols['email']}` AS customer_email"
        : ", '' AS customer_email";

    // Hub columns best-effort
    $select_hub_extras = '';
    if (!empty($hub_cols['phone']))   $select_hub_extras .= ", h.`{$hub_cols['phone']}` AS hub_phone";
    if (!empty($hub_cols['email']))   $select_hub_extras .= ", h.`{$hub_cols['email']}` AS hub_email";
    if (!empty($hub_cols['address'])) $select_hub_extras .= ", h.`{$hub_cols['address']}` AS hub_address";
    if (!empty($hub_cols['logo']))    $select_hub_extras .= ", h.`{$hub_cols['logo']}` AS hub_logo";

    // Optional driver name join
    $driver_src = knx_ops_view_order_detect_driver_name_source();
    $select_driver_name = '';
    $join_driver = '';
    if ($driver_src) {
        $drivers_table = $driver_src['table'];
        $driver_name_col = $driver_src['name_col'];
        $select_driver_name = ", d.`{$driver_name_col}` AS driver_name";
        $join_driver = " LEFT JOIN {$drivers_table} d ON o.driver_id = d.id ";
    }

    $query = "
        SELECT
            o.id AS order_id,
            o.city_id AS city_id,
            o.hub_id AS hub_id,
            o.created_at AS created_at,
            o.total AS total_amount,
            o.tip_amount AS tip_amount,
            o.status AS status,
            o.driver_id AS driver_id,
            h.name AS hub_name,
            h.city_id AS hub_city_id
            {$select_latlng}
            {$select_notes}
            {$select_items}
            {$select_delivery_payment}
            {$select_customer}
            {$select_driver_name}
            {$select_hub_extras}
        FROM {$orders_table} o
        INNER JOIN {$hubs_table} h ON o.hub_id = h.id
        {$join_driver}
        WHERE o.id = %d
        LIMIT 1
    ";

    $row = $wpdb->get_row($wpdb->prepare($query, $order_id));
    if (!$row) {
        return knx_rest_error('Order not found', 404);
    }

    // Manager scope enforcement (fail-closed)
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        $allowed = knx_ops_view_order_manager_city_ids($user_id);
        if (empty($allowed)) {
            return knx_rest_error('Manager city assignment not configured', 403);
        }

        $order_city = (int)$row->city_id;
        if (!in_array($order_city, $allowed, true)) {
            return knx_rest_error('Forbidden: order outside manager scope', 403);
        }
    }

    $now_ts = (int)current_time('timestamp');
    $created_ts = strtotime((string)$row->created_at);
    $age = ($created_ts > 0) ? max(0, $now_ts - $created_ts) : 0;

    // Location URL
    $lat = null;
    $lng = null;
    $view_location_url = null;

    if (isset($row->lat) && isset($row->lng)) {
        $lat_f = (float)$row->lat;
        $lng_f = (float)$row->lng;
        if ($lat_f !== 0.0 || $lng_f !== 0.0) {
            $lat = $lat_f;
            $lng = $lng_f;
            $view_location_url = 'https://www.google.com/maps?q=' . rawurlencode($lat_f . ',' . $lng_f);
        }
    }

    // Items best-effort decode
    $items = null;
    $decoded_snapshot = null;
    if (isset($row->items_json)) {
        $raw_items = $row->items_json;
        if (is_string($raw_items) && $raw_items !== '') {
            $decoded = json_decode($raw_items, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $items = $decoded;
                $decoded_snapshot = is_array($decoded) ? $decoded : null;
            } else {
                $items = $raw_items;
            }
        } else {
            $items = $raw_items;
        }
    }

    $notes = isset($row->notes) ? trim((string)$row->notes) : null;

    // Restaurant (hub) mapping
    $hub_name = trim((string)$row->hub_name);
    $hub_phone = isset($row->hub_phone) ? trim((string)$row->hub_phone) : '';
    $hub_email = isset($row->hub_email) ? trim((string)$row->hub_email) : '';
    $hub_addr  = isset($row->hub_address) ? trim((string)$row->hub_address) : '';
    $hub_logo  = isset($row->hub_logo) ? trim((string)$row->hub_logo) : '';

    if ($hub_phone === '') $hub_phone = null;
    if ($hub_email === '') $hub_email = null;
    if ($hub_addr  === '') $hub_addr  = null;
    if ($hub_logo  === '') $hub_logo  = null;

    // Driver
    $driver_id = isset($row->driver_id) ? (int)$row->driver_id : 0;
    $driver_assigned = ($driver_id > 0);
    $driver_name = isset($row->driver_name) ? trim((string)$row->driver_name) : null;
    if ($driver_name === '') $driver_name = null;

    // Delivery best-effort: columns first, then snapshot fallback
    $delivery_method  = isset($row->delivery_method) ? trim((string)$row->delivery_method) : '';
    $delivery_address = isset($row->delivery_address) ? trim((string)$row->delivery_address) : '';
    $delivery_slot    = isset($row->delivery_time_slot) ? trim((string)$row->delivery_time_slot) : '';

    if ($delivery_method === '' && is_array($decoded_snapshot)) {
        $m = $decoded_snapshot['delivery']['method'] ?? $decoded_snapshot['fulfillment']['method'] ?? null;
        if (is_string($m)) $delivery_method = trim($m);
    }
    if ($delivery_address === '' && is_array($decoded_snapshot)) {
        $a = $decoded_snapshot['delivery']['address'] ?? $decoded_snapshot['delivery_address'] ?? null;
        if (is_string($a)) $delivery_address = trim($a);
    }
    if ($delivery_slot === '' && is_array($decoded_snapshot)) {
        $s = $decoded_snapshot['delivery']['time_slot'] ?? $decoded_snapshot['time_slot'] ?? null;
        if (is_string($s)) $delivery_slot = trim($s);
    }

    // Fulfillment inference: if delivery_method still empty, derive from orders.fulfillment_type or delivery_address
    if ($delivery_method === '') {
        if (isset($row->fulfillment_type)) {
            $ft = strtolower(trim((string)$row->fulfillment_type));
            if ($ft === 'delivery' || $ft === 'pickup') $delivery_method = $ft;
        }
        if ($delivery_method === '' && $delivery_address !== '') {
            $delivery_method = 'delivery';
        }
        if ($delivery_method === '' && $delivery_address === '') {
            $delivery_method = 'pickup';
        }
    }

    if ($delivery_method === '')  $delivery_method = null;
    if ($delivery_address === '') $delivery_address = null;
    if ($delivery_slot === '')    $delivery_slot = null;

    // Payment best-effort: columns first, then snapshot fallback
    $payment_method = isset($row->payment_method) ? trim((string)$row->payment_method) : '';
    $payment_status = isset($row->payment_status) ? trim((string)$row->payment_status) : '';

    if ($payment_method === '' && is_array($decoded_snapshot)) {
        $pm = $decoded_snapshot['payment']['method'] ?? null;
        if (is_string($pm)) $payment_method = trim($pm);
    }
    if ($payment_status === '' && is_array($decoded_snapshot)) {
        $ps = $decoded_snapshot['payment']['status'] ?? null;
        if (is_string($ps)) $payment_status = trim($ps);
    }

    if ($payment_method === '') $payment_method = null;
    if ($payment_status === '') $payment_status = null;

    // Totals + best-effort quote breakdown from snapshot
    $quote = null;
    if (is_array($decoded_snapshot)) {
        $q = $decoded_snapshot['quote'] ?? $decoded_snapshot['totals']['quote'] ?? null;
        if (is_array($q)) $quote = $q;
    }

    // Customer: SSOT = orders columns; fallback = knx_users for legacy rows
    $customer_id = isset($row->customer_id) ? (int)$row->customer_id : 0;

    $customer_name  = isset($row->customer_name) ? trim((string)$row->customer_name) : '';
    $customer_phone = isset($row->customer_phone) ? trim((string)$row->customer_phone) : '';
    $customer_email = isset($row->customer_email) ? trim((string)$row->customer_email) : '';

    if ($customer_name === '' || $customer_phone === '' || $customer_email === '') {
        $u = knx_ops_view_order_fetch_customer_from_users($customer_id);
        if ($customer_name === ''  && !empty($u['name']))  $customer_name  = (string)$u['name'];
        if ($customer_phone === '' && !empty($u['phone'])) $customer_phone = (string)$u['phone'];
        if ($customer_email === '' && !empty($u['email'])) $customer_email = (string)$u['email'];
    }

    if ($customer_name === '')  $customer_name = null;
    if ($customer_phone === '') $customer_phone = null;
    if ($customer_email === '') $customer_email = null;

    // Status history (DB) + Virtual "Order Created" first row.
    $history = knx_ops_view_order_fetch_status_history($order_id);

    $created_mysql = (string)$row->created_at;
    $created_event = [
        'status' => 'order_created',
        'changed_by' => null,
        'changed_by_label' => 'System',
        'created_at' => $created_mysql,
        'created_at_iso' => knx_ops_view_order_iso_from_mysql($created_mysql),
    ];

    array_unshift($history, $created_event);

    // Canon payload (matches view-order-script.js contract)
    $payload = [
        'order_id' => (int)$row->order_id, // internal navigation only (UI must not display)
        'status' => (string)$row->status,

        // Created fields
        'created_at' => $created_mysql,
        'created_at_iso' => knx_ops_view_order_iso_from_mysql($created_mysql),
        'created_age_seconds' => (int)$age,
        'created_human' => knx_ops_view_order_human_age((int)$age),

        // Scope / routing
        'city_id' => (int)$row->city_id,

        // Contract: delivery + payment + restaurant
        'delivery' => [
            'method' => $delivery_method,
            'address' => $delivery_address,
            'time_slot' => $delivery_slot,
        ],
        'payment' => [
            'method' => $payment_method,
            'status' => $payment_status,
        ],
        'restaurant' => [
            'id' => (int)$row->hub_id,
            'name' => $hub_name,
            'phone' => $hub_phone,
            'email' => $hub_email,
            'address' => $hub_addr,
            'logo_url' => $hub_logo,
        ],

        // Customer (now includes phone + email)
        'customer' => [
            'name'  => $customer_name,
            'phone' => $customer_phone,
            'email' => $customer_email,
        ],

        // Totals
        'totals' => [
            'total' => (float)$row->total_amount,
            'tip' => (float)$row->tip_amount,
            'quote' => $quote,
        ],

        // Driver
        'driver' => [
            'assigned' => $driver_assigned,
            'driver_id' => $driver_id > 0 ? $driver_id : null,
            'name' => $driver_name,
        ],

        // Location
        'location' => [
            'lat' => $lat,
            'lng' => $lng,
            'view_url' => $view_location_url,
        ],

        // Raw snapshot data
        'raw' => [
            'items' => $items,
            'notes' => $notes,
        ],

        // Timeline
        'status_history' => $history,
    ];

    return knx_rest_response(true, 'View order', [
        'order' => $payload,
        'meta' => [
            'role' => $role,
            'generated_at' => current_time('mysql'),
            'generated_at_iso' => knx_ops_view_order_iso_from_mysql(current_time('mysql')),
        ],
    ], 200);
}
