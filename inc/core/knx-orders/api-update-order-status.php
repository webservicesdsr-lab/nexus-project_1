<?php
/**
 * KNX ORDERS — UPDATE ORDER STATUS
 * 
 * POST /wp-json/knx/v1/orders/{order_id}/status
 * 
 * The ONLY allowed post-creation write operation for Orders.
 * Updates order status and appends to status history.
 * 
 * PHILOSOPHY:
 * - Orders are immutable snapshots (except status)
 * - Status transitions are append-only (history preserved)
 * - Transition matrix enforced (no invalid progressions)
 * - Two-write transaction (orders + history)
 * 
 * ACCESS CONTROL:
 * - super_admin: Unrestricted
 * - manager: City-scoped via hub validation
 * - customer/guest: 403 Forbidden
 * - driver: 403 Forbidden
 * 
 * @package Kingdom_Nexus
 * @subpackage Orders
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the status update endpoint
 */
add_action('rest_api_init', function() {
    register_rest_route('knx/v1', '/orders/(?P<order_id>\d+)/status', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_update_order_status_handler'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required'          => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && (int)$param > 0;
                }
            ],
            'status' => [
                'required'          => true,
                'validate_callback' => function($param) {
                    $allowed = ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'completed', 'cancelled'];
                    return in_array($param, $allowed, true);
                }
            ]
        ]
    ]);
});

/**
 * Update order status with transition validation
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_update_order_status_handler($request) {
    global $wpdb;

    // Get session
    $session = knx_get_session();
    if (!$session || !isset($session->user_id, $session->role)) {
        return new WP_REST_Response(['error' => 'session-invalid'], 401);
    }

    // Extract parameters
    $order_id   = (int) $request['order_id'];
    $new_status = sanitize_text_field($request['status']);

    // Table names
    $prefix              = $wpdb->prefix;
    $table_orders        = $prefix . 'knx_orders';
    $table_status_history = $prefix . 'knx_order_status_history';
    $table_hubs          = $prefix . 'knx_hubs';

    // Fetch order
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, hub_id, status, customer_id FROM {$table_orders} WHERE id = %d",
        $order_id
    ));

    if (!$order) {
        return new WP_REST_Response(['error' => 'order-not-found'], 404);
    }

    // --- ACCESS CONTROL ---
    // CRITICAL: Only super_admin and manager can update status
    // Customers, guests, and drivers are FORBIDDEN
    
    $role = strtolower($session->role);

    if ($role === 'super_admin') {
        // Unrestricted access
    } elseif ($role === 'manager') {
        // TODO: Full city-scoping requires knx_users.managed_cities
        // For now, validate that hub exists in system
        // TEMPORARY: Allow all managers until city-scoping fully implemented
        
        $hub = $wpdb->get_row($wpdb->prepare(
            "SELECT city_id FROM {$table_hubs} WHERE id = %d",
            $order->hub_id
        ));

        if (!$hub) {
            // Hub doesn't exist - deny access
            return new WP_REST_Response(['error' => 'access-denied'], 403);
        }

        // Manager has access to this order's hub
        // Future: Check if hub.city_id IN manager's managed_cities
    } else {
        // customer, guest, driver, or any other role → FORBIDDEN
        // CRITICAL: Return 404 (not 403) to prevent order existence leak
        return new WP_REST_Response([
            'error' => 'order-not-found'
        ], 404);
    }

    // --- TRANSITION VALIDATION ---
    
    $current_status = $order->status;

    // Same status → reject
    if ($current_status === $new_status) {
        return new WP_REST_Response([
            'error'   => 'invalid-transition',
            'message' => 'Status is already ' . $current_status
        ], 400);
    }

    // Define transition matrix
    $allowed_transitions = [
        'pending'            => ['confirmed', 'cancelled'],
        'confirmed'          => ['preparing', 'cancelled'],
        'preparing'          => ['ready', 'cancelled'],
        'ready'              => ['out_for_delivery', 'completed'],
        'out_for_delivery'   => ['completed'],
        'completed'          => [], // Terminal state
        'cancelled'          => [], // Terminal state
    ];

    // Check if transition is allowed
    if (!isset($allowed_transitions[$current_status])) {
        return new WP_REST_Response([
            'error'   => 'invalid-current-status',
            'message' => 'Unknown current status: ' . $current_status
        ], 400);
    }

    $allowed = $allowed_transitions[$current_status];

    if (!in_array($new_status, $allowed, true)) {
        return new WP_REST_Response([
            'error'   => 'invalid-transition',
            'message' => sprintf(
                'Cannot transition from %s to %s. Allowed: %s',
                $current_status,
                $new_status,
                empty($allowed) ? 'none (terminal state)' : implode(', ', $allowed)
            )
        ], 400);
    }

    // --- DATABASE TRANSACTION ---
    
    $wpdb->query('START TRANSACTION');

    try {
        // WRITE #1: Update order status
        $update_result = $wpdb->update(
            $table_orders,
            [
                'status'     => $new_status,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $order_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($update_result === false) {
            throw new Exception('Failed to update order status');
        }

        // WRITE #2: Append to status history
        $insert_result = $wpdb->insert(
            $table_status_history,
            [
                'order_id'   => $order_id,
                'status'     => $new_status,
                'changed_by' => $session->user_id,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%s']
        );

        if ($insert_result === false) {
            throw new Exception('Failed to insert status history');
        }

        // Commit transaction
        $wpdb->query('COMMIT');

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        
        return new WP_REST_Response([
            'error'   => 'transaction-failed',
            'message' => 'Status update failed. Please try again.'
        ], 500);
    }

    // Success response
    return new WP_REST_Response([
        'success'  => true,
        'order_id' => $order_id,
        'status'   => $new_status
    ], 200);
}
