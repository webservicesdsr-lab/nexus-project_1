<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KNX OPS — All Orders History
 * Endpoint: GET /wp-json/knx/v1/ops/all-orders
 * ════════════════════════════════════════════════════════════════
 *
 * Scope:
 * - super_admin : all cities (city_ids[]=all OR any city_ids)
 * - manager     : only cities assigned via knx_manager_cities pivot (fail-closed)
 *
 * Query params:
 * - city_ids[]  : array of city IDs  |  city_id=all (super_admin only)
 * - status      : filter by status string (optional)
 * - search      : order_id / restaurant name search (optional)
 * - page        : 1-based page number (default 1)
 * - per_page    : rows per page (default 25, max 100)
 *
 * Response:
 * { success, data: { orders:[], pagination:{ total, page, per_page, total_pages } } }
 *
 * [KNX-OPS-ALL-ORDERS-1.0]
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/all-orders', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $req) {
            return knx_rest_wrap('knx_ops_all_orders')($req);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

function knx_ops_all_orders(WP_REST_Request $request) {
    global $wpdb;

    $session  = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin', 'manager']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $role    = (string)($session->role ?? '');
    $user_id = (int)($session->user_id ?? 0);

    // ── Pagination ──────────────────────────────────────────
    $per_page = max(1, min(100, (int)($request->get_param('per_page') ?? 25)));
    $page     = max(1, (int)($request->get_param('page') ?? 1));
    $offset   = ($page - 1) * $per_page;

    // ── Filters ─────────────────────────────────────────────
    $status_filter = sanitize_text_field((string)($request->get_param('status') ?? ''));
    $search        = sanitize_text_field((string)($request->get_param('search') ?? ''));

    // ── City scope ──────────────────────────────────────────
    $all_cities  = false;
    $city_ids    = [];

    $city_id_raw = $request->get_param('city_id');
    if (is_string($city_id_raw) && strtolower(trim($city_id_raw)) === 'all') {
        if ($role !== 'super_admin') return knx_rest_error('city_id=all is only allowed for super_admin', 403);
        $all_cities = true;
    } else {
        // Parse city_ids[] (also accepts legacy csv via 'cities' param — reuse live-orders helper)
        if (function_exists('knx_ops_live_orders_parse_city_ids')) {
            $city_ids = knx_ops_live_orders_parse_city_ids($request);
        } else {
            $raw = $request->get_param('city_ids');
            if (is_array($raw)) {
                foreach ($raw as $c) { $n = (int)$c; if ($n > 0) $city_ids[] = $n; }
            }
        }

        if (empty($city_ids)) {
            return knx_rest_error('city_ids is required', 400);
        }
    }

    // ── Manager scope enforcement (fail-closed) ─────────────
    if ($role === 'manager') {
        if (!$user_id) return knx_rest_error('Unauthorized', 401);

        if (!function_exists('knx_ops_live_orders_manager_city_ids')) {
            return knx_rest_error('Manager city resolver unavailable', 500);
        }

        $allowed = knx_ops_live_orders_manager_city_ids($user_id);
        if (empty($allowed)) return knx_rest_error('No cities assigned to this manager', 403);

        if (!$all_cities) {
            foreach ($city_ids as $c) {
                if (!in_array((int)$c, $allowed, true)) {
                    return knx_rest_error('Forbidden: city outside manager scope', 403);
                }
            }
        } else {
            // manager requested all — restrict to their assigned cities
            $all_cities = false;
            $city_ids   = $allowed;
        }
    }

    // ── Tables ──────────────────────────────────────────────
    $orders_table = $wpdb->prefix . 'knx_orders';
    $hubs_table   = $wpdb->prefix . 'knx_hubs';

    // ── Build WHERE ─────────────────────────────────────────
    // History shows all orders regardless of payment status
    $where   = ['1=1'];
    $params  = [];

    if (!$all_cities) {
        $ph      = implode(',', array_fill(0, count($city_ids), '%d'));
        $where[] = "o.city_id IN ({$ph})";
        $params  = array_merge($params, array_map('intval', $city_ids));
    }

    if ($status_filter !== '') {
        $where[] = 'o.status = %s';
        $params[] = $status_filter;
    }

    if ($search !== '') {
        // Search order ID (exact) or hub name (LIKE)
        if (is_numeric($search)) {
            $where[] = 'o.id = %d';
            $params[] = (int)$search;
        } else {
            $where[] = 'h.name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
    }

    $where_sql = implode(' AND ', $where);

    // ── Count ───────────────────────────────────────────────
    $count_sql = "SELECT COUNT(*)
                  FROM {$orders_table} AS o
                  LEFT JOIN {$hubs_table} AS h ON h.id = o.hub_id
                  WHERE {$where_sql}";

    $total = (int)$wpdb->get_var($wpdb->prepare($count_sql, $params));

    // ── Main query ──────────────────────────────────────────
    $select_sql = "SELECT
                       o.id            AS order_id,
                       o.city_id,
                       o.hub_id,
                       o.status,
                       o.created_at,
                       o.total         AS total_amount,
                       h.name          AS hub_name,
                       h.logo_url      AS hub_logo
                   FROM {$orders_table} AS o
                   LEFT JOIN {$hubs_table} AS h ON h.id = o.hub_id
                   WHERE {$where_sql}
                   ORDER BY o.created_at DESC
                   LIMIT %d OFFSET %d";

    $query_params = array_merge($params, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($select_sql, $query_params));

    // ── Format rows ─────────────────────────────────────────
    $orders = array_map(function ($r) {
        return [
            'order_id'     => (int)$r->order_id,
            'display_id'   => '#' . $r->order_id,
            'city_id'      => (int)$r->city_id,
            'hub_id'       => (int)$r->hub_id,
            'hub_name'     => trim((string)$r->hub_name),
            'hub_logo'     => trim((string)$r->hub_logo),
            'status'       => (string)$r->status,
            'total_amount' => (float)$r->total_amount,
            'created_at'   => (string)$r->created_at,
        ];
    }, (array)$rows);

    $total_pages = $per_page > 0 ? (int)ceil($total / $per_page) : 1;

    return knx_rest_response(true, 'All orders', [
        'orders'     => $orders,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
        ],
        'meta' => [
            'role'         => $role,
            'generated_at' => current_time('mysql'),
        ],
    ], 200);
}
