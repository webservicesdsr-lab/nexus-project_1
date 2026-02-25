<?php
/**
 * ════════════════════════════════════════════════════════════════
 * KNX OPS — Dashboard Analytics REST Endpoint
 * ════════════════════════════════════════════════════════════════
 *
 * GET /wp-json/knx/v1/ops/dashboard
 *
 * Returns:
 *  - 8 KPI cards (30-day window)
 *  - Monthly sales-value series (last 7 months)
 *  - Monthly total-orders series (last 7 months)
 *
 * Access: super_admin, manager (city-scoped)
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/dashboard', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $req) {
            return knx_rest_wrap('knx_ops_dashboard_handler')($req);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Main handler
 */
function knx_ops_dashboard_handler(WP_REST_Request $request) {
    global $wpdb;

    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $role    = isset($session->role)    ? (string) $session->role    : '';
    $user_id = isset($session->user_id) ? (int)    $session->user_id : 0;

    if (!in_array($role, ['super_admin', 'manager'], true)) {
        return knx_rest_error('Forbidden', 403);
    }

    // ── City scoping ──────────────────────────────────────────
    $city_clause = '';
    $city_params = [];

    if ($role === 'manager') {
        $managed = knx_ops_dashboard_manager_cities($user_id);
        if (empty($managed)) {
            return knx_rest_response(true, 'OK', [
                'cards'         => knx_ops_dashboard_empty_cards(),
                'sales_series'  => [],
                'orders_series' => [],
            ]);
        }
        $placeholders = implode(',', array_fill(0, count($managed), '%d'));
        $city_clause  = " AND o.city_id IN ($placeholders)";
        $city_params  = $managed;
    }

    // ── 30-day window ─────────────────────────────────────────
    $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

    // KPI query: single pass over orders in window
    $kpi_sql = $wpdb->prepare(
        "SELECT
            COUNT(*)                              AS order_count,
            COALESCE(SUM(o.total), 0)             AS sales_volume,
            COUNT(DISTINCT o.customer_id)          AS unique_customers,
            COALESCE(SUM(o.delivery_fee), 0)       AS delivery_fee_sum,
            COALESCE(SUM(o.software_fee), 0)       AS static_fee_sum
         FROM {$wpdb->prefix}knx_orders o
         WHERE o.status NOT IN ('pending_payment','cancelled')
           AND o.created_at >= %s
           {$city_clause}",
        array_merge([$thirty_days_ago], $city_params)
    );
    $kpi = $wpdb->get_row($kpi_sql);

    // Dynamic fee = total - (subtotal + tax + delivery + software + tip - discount)
    // Simpler: dynamic_fee ≈ software_fee already captured; but the image shows
    // "Dynamic Fee" separate from "Static Fee". We'll compute dynamic as:
    //   total - subtotal - tax_amount - delivery_fee - software_fee - tip_amount + discount_amount
    // which captures any residual fee not classified.
    $dynamic_sql = $wpdb->prepare(
        "SELECT COALESCE(SUM(
            o.total - o.subtotal - o.tax_amount - o.delivery_fee
            - o.software_fee - o.tip_amount + o.discount_amount
        ), 0) AS dynamic_fee_sum
         FROM {$wpdb->prefix}knx_orders o
         WHERE o.status NOT IN ('pending_payment','cancelled')
           AND o.created_at >= %s
           {$city_clause}",
        array_merge([$thirty_days_ago], $city_params)
    );
    $dynamic = $wpdb->get_var($dynamic_sql);
    $dynamic_fee = max(0, floatval($dynamic));

    // Exposure: total registered users (not time-bound, platform-wide or city-scoped)
    if ($role === 'super_admin') {
        $exposure = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knx_users"
        );
    } else {
        // Manager: count users who have ordered in managed cities
        $placeholders = implode(',', array_fill(0, count($city_params), '%d'));
        $exposure = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT customer_id)
             FROM {$wpdb->prefix}knx_orders
             WHERE city_id IN ($placeholders)",
            ...$city_params
        ));
    }

    $order_count      = (int)   ($kpi->order_count ?? 0);
    $sales_volume     = (float) ($kpi->sales_volume ?? 0);
    $unique_customers = (int)   ($kpi->unique_customers ?? 0);
    $delivery_fee_sum = (float) ($kpi->delivery_fee_sum ?? 0);
    $static_fee_sum   = (float) ($kpi->static_fee_sum ?? 0);
    $total_fee        = $delivery_fee_sum + $static_fee_sum + $dynamic_fee;

    $cards = [
        'orders'           => $order_count,
        'sales_volume'     => round($sales_volume, 2),
        'unique_customers' => $unique_customers,
        'exposure'         => $exposure,
        'delivery_fee'     => round($delivery_fee_sum, 2),
        'static_fee'       => round($static_fee_sum, 2),
        'dynamic_fee'      => round($dynamic_fee, 2),
        'total_fee'        => round($total_fee, 2),
    ];

    // ── Monthly series (last 7 months) ────────────────────────
    $seven_months_ago = date('Y-m-01', strtotime('-6 months'));

    $series_sql = $wpdb->prepare(
        "SELECT
            DATE_FORMAT(o.created_at, '%%Y-%%m') AS month_key,
            DATE_FORMAT(o.created_at, '%%b')     AS month_label,
            COUNT(*)                              AS order_count,
            COALESCE(SUM(o.total), 0)             AS sales_total
         FROM {$wpdb->prefix}knx_orders o
         WHERE o.status NOT IN ('pending_payment','cancelled')
           AND o.created_at >= %s
           {$city_clause}
         GROUP BY month_key
         ORDER BY month_key ASC",
        array_merge([$seven_months_ago], $city_params)
    );
    $series_rows = $wpdb->get_results($series_sql);

    // Build full 7-month grid (fill gaps with zero)
    $sales_series  = [];
    $orders_series = [];
    $month_map     = [];

    foreach ($series_rows as $row) {
        $month_map[$row->month_key] = $row;
    }

    for ($i = 6; $i >= 0; $i--) {
        $key   = date('Y-m', strtotime("-{$i} months"));
        $label = date('M', strtotime("-{$i} months"));
        $row   = $month_map[$key] ?? null;

        $sales_series[] = [
            'month' => $label,
            'value' => $row ? round(floatval($row->sales_total), 2) : 0,
        ];
        $orders_series[] = [
            'month' => $label,
            'value' => $row ? (int) $row->order_count : 0,
        ];
    }

    return knx_rest_response(true, 'OK', [
        'cards'         => $cards,
        'sales_series'  => $sales_series,
        'orders_series' => $orders_series,
        'meta'          => [
            'role'         => $role,
            'window_days'  => 30,
            'generated_at' => gmdate('c'),
        ],
    ]);
}

/**
 * Get managed city IDs for a manager (fail-closed).
 */
if (!function_exists('knx_ops_dashboard_manager_cities')) {
function knx_ops_dashboard_manager_cities(int $user_id): array {
    global $wpdb;
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT city_id FROM {$wpdb->prefix}knx_manager_cities WHERE manager_user_id = %d",
        $user_id
    ));
    return array_map('intval', $rows ?: []);
}
}

/**
 * Return zeroed-out cards structure.
 */
if (!function_exists('knx_ops_dashboard_empty_cards')) {
function knx_ops_dashboard_empty_cards(): array {
    return [
        'orders'           => 0,
        'sales_volume'     => 0,
        'unique_customers' => 0,
        'exposure'         => 0,
        'delivery_fee'     => 0,
        'static_fee'       => 0,
        'dynamic_fee'      => 0,
        'total_fee'        => 0,
    ];
}
}
