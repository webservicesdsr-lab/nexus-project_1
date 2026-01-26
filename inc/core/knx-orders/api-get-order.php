<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - GET ORDER API (Read-Only)
 * Endpoint:
 *   GET /wp-json/knx/v1/orders/{order_id}
 * ----------------------------------------------------------
 * Base checkpoint: PLUGIN_NEXUS_SEALED_v5
 * Philosophy: Immutable snapshots, read-only access
 * ----------------------------------------------------------
 * Returns single Order with full snapshot and status history.
 * Access control: session + ownership validation.
 * ==========================================================
 */

/**
 * Register REST route for fetching single order.
 */
add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/orders/(?P<order_id>\d+)', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_get_order'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                }
            ],
        ],
    ]);
});

/**
 * Fetch single Order with access control.
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function knx_api_get_order(WP_REST_Request $req) {
    global $wpdb;

    $table_orders = $wpdb->prefix . 'knx_orders';
    $table_order_history = $wpdb->prefix . 'knx_order_status_history';

    $order_id = intval($req['order_id']);

    // Fetch order
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_orders} WHERE id = %d LIMIT 1",
        $order_id
    ));

    if (!$order) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'order-not-found'
        ], 404);
    }

    // ACCESS CONTROL: Validate ownership
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
    // - Super Admin: Unrestricted (system owner)
    // - Manager: City-scoped via hub relationships
    // - Customer: Own orders only (customer_id match)
    // - Guest: Own orders only (session_token match)
    //
    // CRITICAL SECURITY:
    // - Customers/Guests get 404 (not 403) when order exists but is not theirs
    //   This prevents order ID enumeration attacks
    // - Managers get 403 when order exists but outside their city scope
    //   This is acceptable for internal roles
    // ====================================================

    // Super admin: unrestricted access
    if ($role === 'super_admin') {
        // Full access - no restrictions
    }
    // Manager: city-scoped access via hub → city relationship
    elseif ($role === 'manager') {
        // NOTE: Manager scope is city-based by design
        // Managers can only access orders from hubs in cities they manage
        // TODO: Full city-scoping requires knx_users.managed_cities or similar
        // For now, we enforce hub-level validation as best-effort restriction
        
        $table_hubs = $wpdb->prefix . 'knx_hubs';
        
        // Verify the hub exists and get its city_id
        $hub = $wpdb->get_row($wpdb->prepare(
            "SELECT city_id FROM {$table_hubs} WHERE id = %d LIMIT 1",
            $order->hub_id
        ));
        
        if (!$hub) {
            // Hub doesn't exist - manager cannot access
            // Return 403 (not 404) because this is an internal role
            return new WP_REST_Response([
                'success' => false,
                'error' => 'access-denied'
            ], 403);
        }
        
        // TEMPORARY: Allow all managers until city-scoping is fully implemented
        // Future: Check if $hub->city_id is in manager's managed_cities array
        // For now: Allow (assumes manager manages all cities)
    }
    // Customer: only own orders
    elseif ($role === 'customer') {
        // Check if customer owns this order
        if ($order->customer_id && (int)$order->customer_id === (int)$session->user_id) {
            // Allow - customer owns order
        } else {
            // CRITICAL: Return 404 (not 403) to prevent order existence leak
            // Customers must not be able to detect if an order_id exists
            return new WP_REST_Response([
                'success' => false,
                'error' => 'order-not-found'
            ], 404);
        }
    }
    // Guest or other roles: check session token
    else {
        if ($order->session_token === $session->token) {
            // Allow - session token matches
        } else {
            // CRITICAL: Return 404 (not 403) to prevent order existence leak
            // Guests must not be able to detect if an order_id exists
            return new WP_REST_Response([
                'success' => false,
                'error' => 'order-not-found'
            ], 404);
        }
    }

    // Fetch status history
    $status_history = $wpdb->get_results($wpdb->prepare(
        "SELECT status, changed_by, created_at 
         FROM {$table_order_history} 
         WHERE order_id = %d 
         ORDER BY created_at ASC",
        $order_id
    ));

    // Decode cart_snapshot
    $cart_snapshot = null;
    if ($order->cart_snapshot) {
        $cart_snapshot = json_decode($order->cart_snapshot, true);
    }

    // Build response
    return new WP_REST_Response([
        'success' => true,
        'order' => [
            'order_id' => (int)$order->id,
            'hub_id' => (int)$order->hub_id,
            'status' => $order->status,
            'subtotal' => (float)$order->subtotal,
            'created_at' => $order->created_at,
            'cart_snapshot' => $cart_snapshot,
            'status_history' => array_map(function($h) {
                return [
                    'status' => $h->status,
                    'changed_by' => $h->changed_by ? (int)$h->changed_by : null,
                    'created_at' => $h->created_at,
                ];
            }, $status_history),
        ]
    ], 200);
}
