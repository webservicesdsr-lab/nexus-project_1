<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Management — Dashboard REST Endpoint (v1.0)
 * ----------------------------------------------------------
 * GET /wp-json/knx/v1/hub-management/dashboard
 *
 * Returns hub-scoped analytics for the hub-management role:
 *  - 3 KPI cards (30-day: orders, sales_volume, avg_ticket)
 *  - Monthly sales series (last 7 months)
 *  - Monthly orders series (last 7 months)
 *
 * Security: session + role + ownership (fail-closed)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/hub-management/dashboard', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_hub_management_dashboard_handler'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

function knx_hub_management_dashboard_handler(WP_REST_Request $request) {
    global $wpdb;

    // Ownership guard (fail-closed)
    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $orders_table = $wpdb->prefix . 'knx_orders';

    // ── 30-day KPI window ─────────────────────────────────
    $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

    $kpi = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(*)                        AS order_count,
            COALESCE(SUM(o.total), 0)       AS sales_volume
         FROM {$orders_table} o
         WHERE o.hub_id = %d
           AND o.status NOT IN ('pending_payment','cancelled')
           AND o.created_at >= %s",
        $hub_id, $thirty_days_ago
    ));

    $order_count  = (int)   ($kpi->order_count ?? 0);
    $sales_volume = (float) ($kpi->sales_volume ?? 0);
    $avg_ticket   = $order_count > 0 ? round($sales_volume / $order_count, 2) : 0;

    $cards = [
        'orders'       => $order_count,
        'sales_volume' => round($sales_volume, 2),
        'avg_ticket'   => $avg_ticket,
    ];

    // ── Monthly series (last 7 months) ────────────────────
    $seven_months_ago = date('Y-m-01', strtotime('-6 months'));

    $series_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT
            DATE_FORMAT(o.created_at, '%%Y-%%m') AS month_key,
            DATE_FORMAT(o.created_at, '%%b')     AS month_label,
            COUNT(*)                              AS order_count,
            COALESCE(SUM(o.total), 0)             AS sales_total
         FROM {$orders_table} o
         WHERE o.hub_id = %d
           AND o.status NOT IN ('pending_payment','cancelled')
           AND o.created_at >= %s
         GROUP BY month_key
         ORDER BY month_key ASC",
        $hub_id, $seven_months_ago
    ));

    $month_map = [];
    foreach ($series_rows as $row) {
        $month_map[$row->month_key] = $row;
    }

    $sales_series  = [];
    $orders_series = [];

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
            'hub_id'       => $hub_id,
            'window_days'  => 30,
            'generated_at' => gmdate('c'),
        ],
    ]);
}
