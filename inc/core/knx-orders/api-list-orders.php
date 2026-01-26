<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - LIST ORDERS API (Read-Only)
 * Endpoint:
 *   GET /wp-json/knx/v1/orders
 * ----------------------------------------------------------
 * Base checkpoint: PLUGIN_NEXUS_SEALED_v5
 * Philosophy: Immutable snapshots, read-only access
 * ----------------------------------------------------------
 * Query Parameters (all optional):
 * - status: Filter by order status
 * - hub_id: Filter by hub
 * - limit: Max results (default 20, max 100)
 * - offset: Skip records (default 0)
 *
 * Returns list of Orders (summary only, no snapshot).
 * Access control: session + ownership validation.
 * ==========================================================
 */

/**
 * Register REST route for listing orders.
 */
add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/orders', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_list_orders'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'status' => [
                'validate_callback' => function($param) {
                    $allowed = ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'completed', 'cancelled'];
                    return in_array($param, $allowed, true);
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'hub_id' => [
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'limit' => [
                'default' => 20,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0 && $param <= 100;
                },
                'sanitize_callback' => 'absint',
            ],
            'offset' => [
                'default' => 0,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});

/**
 * List Orders with pagination and filters.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function knx_api_list_orders(WP_REST_Request $req) {
    global $wpdb;

    $table_orders = $wpdb->prefix . 'knx_orders';

    // Get query params
    $status  = $req->get_param('status');
    $hub_id  = $req->get_param('hub_id');
    $limit   = intval($req->get_param('limit'));
    $offset  = intval($req->get_param('offset'));

    // ACCESS CONTROL: Get session
    $session = knx_get_session();
    
    if (!$session) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'session-invalid'
        ], 401);
    }

    $role = isset($session->role) ? $session->role : '';

    // ====================================================
    // ACCESS CONTROL LOGIC (NEXUS Philosophy)
    // ====================================================
    // - Super Admin: All orders globally
    // - Manager: City-scoped orders via hub → city relationship
    // - Customer: Own orders only (customer_id match)
    // - Guest: Own orders only (session_token match)
    //
    // CRITICAL SECURITY:
    // - Pagination COUNT queries are intentionally separate
    //   This ensures accurate total counts for each role's scope
    // - Performance optimization deferred to Analytics phase
    // ====================================================

    // Build WHERE clause based on role
    $where_clauses = ['1=1'];
    $where_values = [];

    // Super admin: see all orders
    if ($role === 'super_admin') {
        // No additional restrictions - full global access
    }
    // Manager: city-scoped access via hub → city relationship
    elseif ($role === 'manager') {
        // NOTE: Manager scope is city-based by design
        // Managers can only see orders from hubs in cities they manage
        // TODO: Full city-scoping requires knx_users.managed_cities or similar
        // For now, we enforce hub-level validation as best-effort restriction
        
        $table_hubs = $wpdb->prefix . 'knx_hubs';
        
        // Get all hub IDs (will be restricted to manager's cities in future)
        // TEMPORARY: Allow all hubs until city-scoping is fully implemented
        // Future: Filter hubs by city_id IN manager's managed_cities array
        $hub_ids = $wpdb->get_col("SELECT id FROM {$table_hubs}");
        
        if (empty($hub_ids)) {
            // No hubs exist - manager sees nothing
            $where_clauses[] = '1=0';
        } else {
            // Restrict to hubs (future: only hubs in manager's cities)
            $placeholders = implode(',', array_fill(0, count($hub_ids), '%d'));
            $where_clauses[] = "hub_id IN ({$placeholders})";
            $where_values = array_merge($where_values, $hub_ids);
        }
    }
    // Customer: only own orders
    elseif ($role === 'customer') {
        $where_clauses[] = 'customer_id = %d';
        $where_values[] = $session->user_id;
    }
    // Guest or other roles: filter by session token
    else {
        $where_clauses[] = 'session_token = %s';
        $where_values[] = $session->token;
    }

    // Apply optional filters
    if ($status !== null) {
        $where_clauses[] = 'status = %s';
        $where_values[] = $status;
    }

    if ($hub_id !== null) {
        $where_clauses[] = 'hub_id = %d';
        $where_values[] = $hub_id;
    }

    $where_sql = implode(' AND ', $where_clauses);

    // Build main query
    $query = "SELECT id, hub_id, status, subtotal, created_at 
              FROM {$table_orders} 
              WHERE {$where_sql} 
              ORDER BY created_at DESC 
              LIMIT %d OFFSET %d";

    $query_values = array_merge($where_values, [$limit, $offset]);

    if (!empty($query_values)) {
        $query = $wpdb->prepare($query, $query_values);
    }

    $orders = $wpdb->get_results($query);

    // Count total (for pagination)
    $count_query = "SELECT COUNT(*) FROM {$table_orders} WHERE {$where_sql}";
    
    if (!empty($where_values)) {
        $count_query = $wpdb->prepare($count_query, $where_values);
    }
    
    $total = (int)$wpdb->get_var($count_query);

    // Format orders
    $formatted_orders = array_map(function($order) {
        return [
            'order_id' => (int)$order->id,
            'hub_id' => (int)$order->hub_id,
            'status' => $order->status,
            'subtotal' => (float)$order->subtotal,
            'created_at' => $order->created_at,
        ];
    }, $orders);

    return new WP_REST_Response([
        'success' => true,
        'orders' => $formatted_orders,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ]
    ], 200);
}
