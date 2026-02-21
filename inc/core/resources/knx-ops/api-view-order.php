<?php
// File: inc/core/resources/knx-ops/api-view-order.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — View Order (CANON)
 * Endpoint: GET /wp-json/knx/v1/ops/view-order?order_id=123
 *
 * Access:
 * - super_admin, manager
 * - manager is city-scoped via {prefix}knx_manager_cities (fail-closed)
 *
 * Contract:
 * {
 *   success, message,
 *   data: {
 *     order: {
 *       order_id, status, created_at, created_at_iso, created_age_seconds, created_human,
 *       city_id,
 *       restaurant:{name,phone,email,address,logo_url,location:{lat,lng,view_url}},
 *       customer:{name,phone,email},
 *       delivery:{method,address,time_slot},
 *       payment:{method,status},
 *       totals:{total,tip,quote},
 *       driver:{assigned,driver_id,name},
 *       location:{lat,lng,view_url},
 *       raw:{items:{items:[],source},notes},
 *       status_history:[{status,label,is_done,is_current,created_at,created_at_iso,changed_by,changed_by_label}]
 *     },
 *     meta:{role,generated_at,generated_at_iso}
 *   }
 * }
 *
 * Notes:
 * - pending_payment is NOT shown in status_history timeline.
 * - Timeline always starts with virtual "order_created" from orders.created_at.
 * - Status history placeholders include null timestamps for future steps (UI may filter).
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/view-order', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_ops_view_order')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
        'args' => [
            'order_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && (int)$param > 0;
                }
            ],
        ],
    ]);
});

/**
 * Resolve manager allowed city IDs via {prefix}knx_manager_cities.
 * Fail-closed if missing or empty.
 *
 * @param int $manager_user_id
 * @return array<int>
 */
function knx_ops_view_order_manager_city_ids($manager_user_id) {
    global $wpdb;

    $manager_user_id = (int)$manager_user_id;
    if ($manager_user_id <= 0) return [];

    $pivot = $wpdb->prefix . 'knx_manager_cities';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pivot));
    if (empty($exists)) return [];

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id
         FROM {$pivot}
         WHERE manager_user_id = %d
           AND city_id IS NOT NULL",
        $manager_user_id
    ));

    $ids = array_map('intval', (array)$ids);
    $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));
    return $ids;
}

/**
 * Best-effort: detect orders lat/lng columns.
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
 * Best-effort: detect hubs lat/lng columns.
 * Supports your current dump (latitude/longitude) and older variants.
 *
 * @return array{lat:string,lng:string}|null
 */
function knx_ops_view_order_detect_hub_latlng_columns() {
    global $wpdb;

    static $cache = null;
    if ($cache !== null) return $cache;

    $hubs_table = $wpdb->prefix . 'knx_hubs';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hubs_table));
    if (empty($exists)) { $cache = null; return null; }

    $candidates = [
        ['lat' => 'latitude',  'lng' => 'longitude'], // CANON in your dump
        ['lat' => 'lat',       'lng' => 'lng'],
        ['lat' => 'hub_lat',   'lng' => 'hub_lng'],
    ];

    foreach ($candidates as $pair) {
        $lat_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$hubs_table} LIKE %s", $pair['lat']));
        $lng_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$hubs_table} LIKE %s", $pair['lng']));
        if (!empty($lat_exists) && !empty($lng_exists)) {
            $cache = $pair;
            return $cache;
        }
    }

    $cache = null;
    return null;
}

/**
 * Age helper.
 * @param int $created_ts
 * @return array{age_seconds:int, human:string}
 */
function knx_ops_view_order_age($created_ts) {
    $now = (int)current_time('timestamp');
    $age = ($created_ts > 0) ? max(0, $now - $created_ts) : 0;

    if ($age <= 60) {
        return ['age_seconds' => (int)$age, 'human' => 'Just now'];
    }

    if (function_exists('human_time_diff')) {
        return ['age_seconds' => (int)$age, 'human' => human_time_diff($created_ts, $now) . ' ago'];
    }

    $mins = max(1, (int)floor($age / 60));
    return ['age_seconds' => (int)$age, 'human' => $mins . ' min ago'];
}

/**
 * Safe JSON decode.
 * @param mixed $json
 * @return mixed
 */
function knx_ops_view_order_json_decode($json) {
    if (!is_string($json) || trim($json) === '') return null;
    $out = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) return null;
    return $out;
}

/**
 * Canon label mapping for the timeline.
 * @param string $status
 * @return string
 */
function knx_ops_view_order_status_label($status) {
    $s = strtolower(trim((string)$status));

    $map = [
        'order_created'       => 'Order Created',
        'confirmed'           => 'Waiting for driver',
        'accepted_by_driver'  => 'Driver Accepted',
        'accepted_by_hub'     => 'Restaurant Accepted',
        'preparing'           => 'Preparing',
        'prepared'            => 'Prepared',
        'picked_up'           => 'Picked Up',
        'completed'           => 'Completed',
        'cancelled'           => 'Cancelled',
    ];

    return $map[$s] ?? (ucwords(str_replace('_', ' ', $s)));
}

/**
 * Build canonical timeline.
 *
 * @param string $current_status
 * @param string $order_created_at
 * @param array<int,array{status:string,changed_by:int|null,created_at:string}> $raw_rows
 * @return array<int,array<string,mixed>>
 */
function knx_ops_view_order_build_timeline($current_status, $order_created_at, array $raw_rows) {
    global $wpdb;

    $current_status = strtolower(trim((string)$current_status));

    // Canon steps (no pending_payment)
    $steps = [
        'order_created',
        'confirmed',
        'accepted_by_driver',
        'accepted_by_hub',
        'preparing',
        'prepared',
        'picked_up',
        'completed',
    ];

    // Terminal
    if ($current_status === 'cancelled') {
        $steps = [
            'order_created',
            'confirmed',
            'accepted_by_driver',
            'accepted_by_hub',
            'preparing',
            'prepared',
            'picked_up',
            'completed',
            'cancelled',
        ];
    }

    // Pick earliest timestamp per status (stable) + changed_by
    $by_status = [];
    foreach ($raw_rows as $r) {
        $st = strtolower(trim((string)($r['status'] ?? '')));
        if ($st === '' || $st === 'pending_payment') continue;

        $at = isset($r['created_at']) ? (string)$r['created_at'] : '';
        if ($at === '') continue;

        if (!isset($by_status[$st])) {
            $by_status[$st] = [
                'created_at' => $at,
                'changed_by' => isset($r['changed_by']) ? (int)$r['changed_by'] : null,
            ];
        }
    }

    // Always seed order_created from orders.created_at (virtual)
    $by_status['order_created'] = [
        'created_at' => (string)$order_created_at,
        'changed_by' => null,
    ];

    // Resolve changed_by labels in one query
    $ids = [];
    foreach ($by_status as $st => $info) {
        $cb = $info['changed_by'];
        if ($cb !== null && (int)$cb > 0) $ids[] = (int)$cb;
    }
    $ids = array_values(array_unique($ids));

    $labels = []; // user_id => username
    if (!empty($ids)) {
        $users_table = $wpdb->prefix . 'knx_users';
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare("SELECT id, username FROM {$users_table} WHERE id IN ({$ph})", $ids);
        $rows = $wpdb->get_results($sql);
        foreach ((array)$rows as $u) {
            $labels[(int)$u->id] = (string)$u->username;
        }
    }

    $sys_label = 'System';

    // Compute indices
    $idx = array_flip($steps);
    $cur_i = isset($idx[$current_status]) ? (int)$idx[$current_status] : -1;

    $timeline = [];
    foreach ($steps as $i => $st) {
        $has = isset($by_status[$st]);

        $created_at = $has ? (string)$by_status[$st]['created_at'] : null;
        $created_iso = $created_at ? date('c', strtotime($created_at)) : null;

        $changed_by = $has ? ($by_status[$st]['changed_by'] !== null ? (int)$by_status[$st]['changed_by'] : null) : null;
        $changed_by_label = $sys_label;
        if ($changed_by !== null && $changed_by > 0) {
            $changed_by_label = $labels[$changed_by] ?? ('User #' . $changed_by);
        }

        // is_done and is_current based on current status position
        $is_current = ($st === $current_status);
        $is_done = false;

        if ($cur_i >= 0) {
            $is_done = ($i <= $cur_i);
        } else {
            // Fallback: if we have a timestamp, treat as done
            $is_done = ($created_at !== null);
        }

        if ($current_status === 'cancelled') {
            $is_current = ($st === 'cancelled');
            $is_done = ($created_at !== null) || ($st === 'order_created');
        }

        $timeline[] = [
            'status'           => $st,
            'label'            => knx_ops_view_order_status_label($st),
            'is_done'          => (bool)$is_done,
            'is_current'       => (bool)$is_current,
            'created_at'       => $created_at,
            'created_at_iso'   => $created_iso,
            'changed_by'       => $changed_by,
            'changed_by_label' => $has ? $changed_by_label : null,
        ];
    }

    return $timeline;
}

function knx_ops_view_order(WP_REST_Request $request) {
    global $wpdb;

    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role = isset($session->role) ? (string)$session->role : '';
    $user_id = isset($session->user_id) ? (int)$session->user_id : 0;

    $order_id = (int)$request->get_param('order_id');
    if ($order_id <= 0) return knx_rest_error('order_id is required', 400);

    // Enforce manager scope (fail-closed preflight)
    if ($role === 'manager') {
        if ($user_id <= 0) return knx_rest_error('Unauthorized', 401);
        $allowed = knx_ops_view_order_manager_city_ids($user_id);
        if (empty($allowed)) return knx_rest_error('Manager city assignment not configured', 403);
    }

    $orders_table   = $wpdb->prefix . 'knx_orders';
    $hubs_table     = $wpdb->prefix . 'knx_hubs';
    $drivers_table  = $wpdb->prefix . 'knx_drivers';
    $items_table    = $wpdb->prefix . 'knx_order_items';
    $hist_table     = $wpdb->prefix . 'knx_order_status_history';
    $users_table    = $wpdb->prefix . 'knx_users';

    // Defensive: ensure orders exists
    $orders_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
    if (empty($orders_exists)) return knx_rest_error('Orders not configured', 409);

    $latlng = knx_ops_view_order_detect_latlng_columns();
    $select_latlng = '';
    if ($latlng) {
        $select_latlng = ", o.`{$latlng['lat']}` AS delivery_lat, o.`{$latlng['lng']}` AS delivery_lng";
    }

    // NEW: Hub coords (restaurant precise navigation)
    $hub_latlng = knx_ops_view_order_detect_hub_latlng_columns();
    $select_hub_latlng = '';
    if ($hub_latlng) {
        $select_hub_latlng = ", h.`{$hub_latlng['lat']}` AS hub_lat, h.`{$hub_latlng['lng']}` AS hub_lng";
    } else {
        // Keep select stable
        $select_hub_latlng = ", NULL AS hub_lat, NULL AS hub_lng";
    }

    // Load order + hub (restaurant identity)
    $sql = "
        SELECT
            o.id,
            o.city_id,
            o.hub_id,
            o.customer_id,
            o.customer_name,
            o.customer_phone,
            o.customer_email,
            o.fulfillment_type,
            o.delivery_address,
            o.delivery_address_id,
            o.payment_method,
            o.payment_status,
            o.subtotal,
            o.tax_amount,
            o.delivery_fee,
            o.software_fee,
            o.tip_amount,
            o.discount_amount,
            o.total,
            o.status,
            o.driver_id,
            o.notes,
            o.totals_snapshot,
            o.cart_snapshot,
            o.created_at
            {$select_latlng},
            h.name      AS hub_name,
            h.phone     AS hub_phone,
            h.email     AS hub_email,
            h.address   AS hub_address,
            h.logo_url  AS hub_logo_url
            {$select_hub_latlng}
        FROM {$orders_table} o
        INNER JOIN {$hubs_table} h ON o.hub_id = h.id
        WHERE o.id = %d
        LIMIT 1
    ";
    $row = $wpdb->get_row($wpdb->prepare($sql, $order_id));

    // No existence leak posture: 404
    if (!$row) return knx_rest_error('Order not found', 404);

    // Manager scope enforcement against the loaded order
    if ($role === 'manager') {
        $allowed = knx_ops_view_order_manager_city_ids($user_id);
        if (empty($allowed)) return knx_rest_error('Order not found', 404);

        $city_id = (int)($row->city_id ?? 0);
        if ($city_id <= 0 || !in_array($city_id, $allowed, true)) {
            return knx_rest_error('Order not found', 404);
        }
    }

    // Restaurant location (precise coords from hubs table if available)
    $hub_lat = null;
    $hub_lng = null;
    if (isset($row->hub_lat) && $row->hub_lat !== null) $hub_lat = (float)$row->hub_lat;
    if (isset($row->hub_lng) && $row->hub_lng !== null) $hub_lng = (float)$row->hub_lng;

    if (($hub_lat === 0.0 && $hub_lng === 0.0)) {
        $hub_lat = null;
        $hub_lng = null;
    }

    $hub_view_url = null;
    if ($hub_lat !== null && $hub_lng !== null) {
        $hub_view_url = 'https://www.google.com/maps?q=' . rawurlencode($hub_lat . ',' . $hub_lng);
    }

    $restaurant = [
        'name'     => (string)($row->hub_name ?? ''),
        'phone'    => isset($row->hub_phone) ? (string)$row->hub_phone : null,
        'email'    => isset($row->hub_email) ? (string)$row->hub_email : null,
        'address'  => isset($row->hub_address) ? (string)$row->hub_address : null,
        'logo_url' => isset($row->hub_logo_url) ? (string)$row->hub_logo_url : null,
        'location' => [
            'lat'      => $hub_lat,
            'lng'      => $hub_lng,
            'view_url' => $hub_view_url,
        ],
    ];

    // Customer: SSOT = orders columns; fallback = knx_users if missing
    $customer_id = isset($row->customer_id) ? (int)$row->customer_id : 0;

    $customer_name  = isset($row->customer_name) ? trim((string)$row->customer_name) : '';
    $customer_phone = isset($row->customer_phone) ? trim((string)$row->customer_phone) : '';
    $customer_email = isset($row->customer_email) ? trim((string)$row->customer_email) : '';

    if ($customer_id > 0 && ($customer_name === '' || $customer_phone === '' || $customer_email === '')) {
        $u_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $users_table));
        if (!empty($u_exists)) {
            $u = $wpdb->get_row($wpdb->prepare(
                "SELECT name, phone, email, username
                 FROM {$users_table}
                 WHERE id = %d
                 LIMIT 1",
                $customer_id
            ));
            if ($u) {
                if ($customer_name === '') {
                    $nm = trim((string)($u->name ?? ''));
                    if ($nm === '') $nm = trim((string)($u->username ?? ''));
                    if ($nm !== '') $customer_name = $nm;
                }
                if ($customer_phone === '') {
                    $ph = trim((string)($u->phone ?? ''));
                    if ($ph !== '') $customer_phone = $ph;
                }
                if ($customer_email === '') {
                    $em = trim((string)($u->email ?? ''));
                    if ($em !== '') $customer_email = $em;
                }
            }
        }
    }

    $customer = [
        'name'  => ($customer_name !== '' ? $customer_name : null),
        'phone' => ($customer_phone !== '' ? $customer_phone : null),
        'email' => ($customer_email !== '' ? $customer_email : null),
    ];

    // Delivery
    $delivery_method = isset($row->fulfillment_type) ? (string)$row->fulfillment_type : 'delivery';
    if ($delivery_method !== 'delivery' && $delivery_method !== 'pickup') $delivery_method = 'delivery';

    $delivery = [
        'method'    => $delivery_method,
        'address'   => isset($row->delivery_address) ? (string)$row->delivery_address : null,
        'time_slot' => null,
    ];

    // Payment
    $payment = [
        'method' => isset($row->payment_method) ? (string)$row->payment_method : null,
        'status' => isset($row->payment_status) ? (string)$row->payment_status : null,
    ];

    // Totals (quote optional from totals_snapshot)
    $quote = null;
    $totals_snapshot = knx_ops_view_order_json_decode($row->totals_snapshot ?? null);
    if (is_array($totals_snapshot)) {
        $quote = null;
    }

    $totals = [
        'total' => (float)($row->total ?? 0),
        'tip'   => (float)($row->tip_amount ?? 0),
        'quote' => $quote,
    ];

    // Driver
    $driver_id = isset($row->driver_id) ? (int)$row->driver_id : 0;
    $driver_name = null;

    // Prefer knx_drivers.full_name (when table exists & populated)
    $drivers_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drivers_table));
    if ($driver_id > 0 && !empty($drivers_exists)) {
        $d = $wpdb->get_row($wpdb->prepare(
            "SELECT full_name, user_id
             FROM {$drivers_table}
             WHERE id = %d
             LIMIT 1",
            $driver_id
        ));
        if ($d && isset($d->full_name) && trim((string)$d->full_name) !== '') {
            $driver_name = trim((string)$d->full_name);
        } else {
            // If drivers row exists but name missing, try to resolve via users.user_id (if present)
            $maybe_uid = ($d && isset($d->user_id)) ? (int)$d->user_id : 0;
            if ($maybe_uid > 0) {
                $u = $wpdb->get_row($wpdb->prepare(
                    "SELECT name, username FROM {$users_table} WHERE id = %d LIMIT 1",
                    $maybe_uid
                ));
                if ($u) {
                    $nm = trim((string)($u->name ?? ''));
                    if ($nm === '') $nm = trim((string)($u->username ?? ''));
                    if ($nm !== '') $driver_name = $nm;
                }
            }
        }
    }

    // Fallback: when orders.driver_id is actually a knx_users.id in some environments
    if ($driver_id > 0 && ($driver_name === null || $driver_name === '')) {
        $u_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $users_table));
        if (!empty($u_exists)) {
            $u = $wpdb->get_row($wpdb->prepare(
                "SELECT name, username
                 FROM {$users_table}
                 WHERE id = %d
                 LIMIT 1",
                $driver_id
            ));
            if ($u) {
                $nm = trim((string)($u->name ?? ''));
                if ($nm === '') $nm = trim((string)($u->username ?? ''));
                if ($nm !== '') $driver_name = $nm;
            }
        }
    }

    $driver = [
        'assigned'  => ($driver_id > 0),
        'driver_id' => ($driver_id > 0 ? $driver_id : null),
        'name'      => ($driver_name !== '' ? $driver_name : null),
    ];

    // Location (delivery coords)
    $lat = null;
    $lng = null;
    if ($latlng) {
        $lat = isset($row->delivery_lat) ? (float)$row->delivery_lat : null;
        $lng = isset($row->delivery_lng) ? (float)$row->delivery_lng : null;
    }

    $view_url = null;
    if ($lat !== null && $lng !== null && ($lat != 0.0 || $lng != 0.0)) {
        $view_url = 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);
    }

    $location = [
        'lat'      => $lat,
        'lng'      => $lng,
        'view_url' => $view_url,
    ];

    // Raw items (prefer order_items table)
    $raw_items = [];
    $items_source = 'none';

    $items_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $items_table));
    if (!empty($items_exists)) {
        $items_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT name_snapshot, image_snapshot, quantity, unit_price, line_total, modifiers_json
             FROM {$items_table}
             WHERE order_id = %d
             ORDER BY id ASC",
            $order_id
        ));

        foreach ((array)$items_rows as $it) {
            $mods = null;
            if (isset($it->modifiers_json) && is_string($it->modifiers_json) && trim($it->modifiers_json) !== '') {
                $decoded = json_decode((string)$it->modifiers_json, true);
                if (json_last_error() === JSON_ERROR_NONE) $mods = $decoded;
            }

            $raw_items[] = [
                'name_snapshot'  => (string)($it->name_snapshot ?? ''),
                'image_snapshot' => isset($it->image_snapshot) ? (string)$it->image_snapshot : null,
                'quantity'       => (int)($it->quantity ?? 1),
                'unit_price'     => (float)($it->unit_price ?? 0),
                'line_total'     => (float)($it->line_total ?? 0),
                'modifiers'      => $mods,
            ];
        }

        $items_source = 'order_items';
    }

    if (empty($raw_items)) {
        // Fallback: cart_snapshot.items
        $cart_snapshot = knx_ops_view_order_json_decode($row->cart_snapshot ?? null);
        if (is_array($cart_snapshot) && isset($cart_snapshot['items']) && is_array($cart_snapshot['items'])) {
            foreach ($cart_snapshot['items'] as $it) {
                if (!is_array($it)) continue;
                $raw_items[] = [
                    'name_snapshot'  => (string)($it['name_snapshot'] ?? ''),
                    'image_snapshot' => isset($it['image_snapshot']) ? (string)$it['image_snapshot'] : null,
                    'quantity'       => (int)($it['quantity'] ?? 1),
                    'unit_price'     => (float)($it['unit_price'] ?? 0),
                    'line_total'     => (float)($it['line_total'] ?? 0),
                    'modifiers'      => isset($it['modifiers']) ? $it['modifiers'] : null,
                ];
            }
            $items_source = 'cart_snapshot';
        }
    }

    $raw = [
        'items' => [
            'items'  => $raw_items,
            'source' => $items_source,
        ],
        'notes' => isset($row->notes) ? $row->notes : null,
    ];

    // Status history (raw DB)
    $hist_rows = [];
    $hist_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hist_table));
    if (!empty($hist_exists)) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, changed_by, created_at
             FROM {$hist_table}
             WHERE order_id = %d
             ORDER BY created_at ASC, id ASC",
            $order_id
        ));

        foreach ((array)$rows as $h) {
            $hist_rows[] = [
                'status'     => (string)($h->status ?? ''),
                'changed_by' => isset($h->changed_by) ? (int)$h->changed_by : null,
                'created_at' => (string)($h->created_at ?? ''),
            ];
        }
    }

    // Canon timeline (includes placeholders; UI can filter null created_at)
    $timeline = knx_ops_view_order_build_timeline(
        (string)($row->status ?? ''),
        (string)($row->created_at ?? current_time('mysql')),
        $hist_rows
    );

    // Created fields
    $created_at = (string)($row->created_at ?? current_time('mysql'));
    $created_ts = strtotime($created_at);
    $age = knx_ops_view_order_age($created_ts);

    $order = [
        'order_id'            => (int)$row->id,
        'status'              => (string)($row->status ?? ''),
        'created_at'          => $created_at,
        'created_at_iso'      => date('c', strtotime($created_at)),
        'created_age_seconds' => (int)$age['age_seconds'],
        'created_human'       => (string)$age['human'],

        'city_id'             => isset($row->city_id) ? (int)$row->city_id : null,

        'restaurant'          => $restaurant,
        'customer'            => $customer,
        'delivery'            => $delivery,
        'payment'             => $payment,
        'totals'              => $totals,
        'driver'              => $driver,
        'location'            => $location,
        'raw'                 => $raw,

        'status_history'      => $timeline,
    ];

    $now = current_time('mysql');

    return knx_rest_response(true, 'View order', [
        'order' => $order,
        'meta'  => [
            'role'             => $role,
            'generated_at'     => $now,
            'generated_at_iso' => date('c', strtotime($now)),
        ],
    ], 200);
}