<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — OPS Orders Live API (MVP v1.0)
 * ----------------------------------------------------------
 * Endpoint:
 *   GET /wp-json/knx/v2/ops/orders/live
 *
 * Access:
 *   Roles: super_admin, manager
 *
 * Notes:
 * - This endpoint is OPS-facing (inbox/live list).
 * - It proxies to an existing orders list route to avoid DB-coupling here.
 * - Returns a normalized payload: { orders: [...], cursor: "..." }
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/ops/orders/live', [
        'methods'  => 'GET',
        'callback' => function_exists('knx_rest_wrap') ? knx_rest_wrap('knx_v2_ops_orders_live') : 'knx_v2_ops_orders_live',
        'permission_callback' => function_exists('knx_rest_permission_roles')
            ? knx_rest_permission_roles(['super_admin', 'manager'])
            : '__return_true',
    ]);
});

/**
 * Choose the best available internal orders list route.
 * Prefers v2 list if present, otherwise falls back to v1.
 */
function knx_ops_pick_orders_route() {
    try {
        $server = rest_get_server();
        if ($server && method_exists($server, 'get_routes')) {
            $routes = $server->get_routes();

            // Common candidates (adjustable by Copilot if your actual route differs)
            if (isset($routes['/knx/v2/orders/list'])) return '/knx/v2/orders/list';
            if (isset($routes['/knx/v2/orders']))      return '/knx/v2/orders';
            if (isset($routes['/knx/v1/orders']))      return '/knx/v1/orders';
        }
    } catch (Throwable $e) {}

    // Safe fallback
    return '/knx/v1/orders';
}

/**
 * OPS live list handler.
 */
function knx_v2_ops_orders_live(WP_REST_Request $req) {

    $limit = (int) ($req->get_param('limit') ?: 60);
    $limit = max(1, min(200, $limit));

    $cursor = (string) ($req->get_param('cursor') ?: '');

    $route = knx_ops_pick_orders_route();

    // Build internal request
    $internal = new WP_REST_Request('GET', $route);

    // Pass typical filters (best-effort)
    $internal->set_query_params([
        'limit'  => $limit,
        'page'   => 1,
        'cursor' => $cursor,
        // You can add ops filters later: hub_id, status, etc.
    ]);

    $response = rest_do_request($internal);

    if (is_wp_error($response)) {
        return knx_ops_rest(false, 'Internal orders request failed.', null, 500);
    }

    $status = method_exists($response, 'get_status') ? (int) $response->get_status() : 500;
    $data   = method_exists($response, 'get_data') ? $response->get_data() : null;

    if ($status < 200 || $status >= 300 || !$data) {
        return knx_ops_rest(false, 'Unable to load orders.', [
            'route' => $route,
            'status' => $status,
        ], 500);
    }

    /**
     * Normalize:
     * - Some endpoints return: { success:true, data:{ orders:[...] } }
     * - Others return: { success:true, orders:[...] }
     */
    $orders = [];
    $outCursor = '';

    if (is_array($data)) {
        if (isset($data['data']) && is_array($data['data']) && isset($data['data']['orders'])) {
            $orders = is_array($data['data']['orders']) ? $data['data']['orders'] : [];
            $outCursor = (string) ($data['data']['cursor'] ?? '');
        } elseif (isset($data['orders'])) {
            $orders = is_array($data['orders']) ? $data['orders'] : [];
            $outCursor = (string) ($data['cursor'] ?? '');
        } elseif (isset($data['data']) && is_array($data['data']) && isset($data['data']['items'])) {
            // Some list APIs might call them "items"
            $orders = is_array($data['data']['items']) ? $data['data']['items'] : [];
            $outCursor = (string) ($data['data']['cursor'] ?? '');
        }
    }

    // Cursor best-effort: if missing, compute from updated_at if present
    if (!$outCursor && is_array($orders) && $orders) {
        $max = '';
        foreach ($orders as $o) {
            if (is_array($o) && !empty($o['updated_at'])) {
                $t = (string) $o['updated_at'];
                if ($t > $max) $max = $t;
            } elseif (is_object($o) && !empty($o->updated_at)) {
                $t = (string) $o->updated_at;
                if ($t > $max) $max = $t;
            }
        }
        $outCursor = $max;
    }

    return knx_ops_rest(true, 'OPS live orders', [
        'orders' => $orders ?: [],
        'cursor' => $outCursor,
    ], 200);
}

/**
 * Standard response wrapper (uses knx_rest_response if available).
 */
function knx_ops_rest($success, $message, $data = null, $code = 200) {
    if (function_exists('knx_rest_response')) {
        return knx_rest_response((bool) $success, (string) $message, $data, (int) $code);
    }
    return new WP_REST_Response([
        'success' => (bool) $success,
        'message' => (string) $message,
        'data'    => $data,
    ], (int) $code);
}
