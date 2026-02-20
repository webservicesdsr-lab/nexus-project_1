<?php
/**
 * KNX ORDERS — UPDATE ORDER STATUS (OPTION A LIFECYCLE)
 *
 * Canon endpoint:
 * POST /wp-json/knx/v1/orders/{order_id}/status
 * Body: { status: "..." }
 *
 * Backward-compat alias (OPS legacy):
 * POST /wp-json/knx/v1/ops/update-status
 * Body: { order_id: 123, to_status: "..." }
 *
 * The ONLY allowed post-creation write operation for Orders.
 * Updates order status and appends to status history (append-only).
 *
 * PHILOSOPHY:
 * - Orders are immutable snapshots (except status)
 * - Status transitions are append-only (history preserved)
 * - Transition matrix enforced (no invalid progressions)
 * - Two-write transaction (orders + history)
 *
 * ACCESS CONTROL:
 * - super_admin: Unrestricted
 * - manager: City-scoped via hub validation (future enhancement)
 * - customer/guest: 404 (no existence leak)
 * - driver: 404 (no existence leak) (drivers will have their own guarded endpoints later)
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

    // Canon route
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
                    $allowed = knx_orders_allowed_statuses_option_a();
                    return in_array((string)$param, $allowed, true);
                }
            ]
        ]
    ]);

    // Backward-compat OPS alias
    register_rest_route('knx/v1', '/ops/update-status', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_ops_update_order_status_alias'),
        'permission_callback' => knx_rest_permission_session(),
        'args' => [
            'order_id' => [
                'required'          => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && (int)$param > 0;
                }
            ],
            'to_status' => [
                'required'          => true,
                'validate_callback' => function($param) {
                    $allowed = knx_orders_allowed_statuses_option_a();
                    return in_array((string)$param, $allowed, true);
                }
            ]
        ]
    ]);
});

/**
 * Allowed statuses for OPTION A lifecycle (excluding pending_payment as a target).
 *
 * @return array
 */
function knx_orders_allowed_statuses_option_a() {
    return [
        'placed',
        'accepted_by_driver',
        'accepted_by_restaurant',
        'preparing',
        'prepared',
        'out_for_delivery',
        'completed',
        'cancelled',
    ];
}

/**
 * OPS alias handler: maps {to_status} -> {status} and reuses canonical handler.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_ops_update_order_status_alias($request) {
    $to = (string) $request->get_param('to_status');
    $request->set_param('status', $to);
    return knx_update_order_status_handler($request);
}

/**
 * Update order status with transition validation.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_update_order_status_handler($request) {
    global $wpdb;

    $session = knx_get_session();
    if (!$session || !isset($session->user_id, $session->role)) {
        return new WP_REST_Response(['error' => 'session-invalid'], 401);
    }

    $order_id = (int) $request->get_param('order_id');

    // Accept both keys defensively (canon is 'status')
    $new_status = (string) $request->get_param('status');
    if ($new_status === '') {
        $new_status = (string) $request->get_param('to_status');
    }
    $new_status = sanitize_text_field($new_status);

    $allowed_targets = knx_orders_allowed_statuses_option_a();
    if (!in_array($new_status, $allowed_targets, true)) {
        return new WP_REST_Response([
            'error'   => 'invalid-status',
            'message' => 'Invalid status: ' . $new_status
        ], 400);
    }

    $prefix               = $wpdb->prefix;
    $table_orders         = $prefix . 'knx_orders';
    $table_status_history = $prefix . 'knx_order_status_history';
    $table_hubs           = $prefix . 'knx_hubs';

    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, hub_id, status, customer_id, payment_status
         FROM {$table_orders}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));

    if (!$order) {
        return new WP_REST_Response(['error' => 'order-not-found'], 404);
    }

    // Access Control (fail-closed, no existence leak)
    $role = strtolower((string)$session->role);

    if ($role === 'super_admin') {
        // unrestricted
    } elseif ($role === 'manager') {
        // TODO: strict city-scoping once manager->allowed cities exist
        $hub = $wpdb->get_row($wpdb->prepare(
            "SELECT city_id FROM {$table_hubs} WHERE id = %d",
            (int) $order->hub_id
        ));
        if (!$hub) {
            return new WP_REST_Response(['error' => 'order-not-found'], 404);
        }
    } else {
        return new WP_REST_Response(['error' => 'order-not-found'], 404);
    }

    $current_status = (string) ($order->status ?? '');

    // Hard block: pending payment must be promoted by webhook only
    if ($current_status === 'pending_payment') {
        return new WP_REST_Response([
            'error'   => 'invalid-transition',
            'message' => 'Order is pending payment. Status changes are blocked until payment is confirmed.'
        ], 409);
    }

    if ($current_status === $new_status) {
        return new WP_REST_Response([
            'error'   => 'invalid-transition',
            'message' => 'Status is already ' . $current_status
        ], 400);
    }

    // OPTION A transition matrix
    $allowed_transitions = [
        'placed'                => ['accepted_by_driver', 'cancelled'],
        'accepted_by_driver'    => ['accepted_by_restaurant', 'cancelled'],
        'accepted_by_restaurant'=> ['preparing', 'cancelled'],
        'preparing'             => ['prepared', 'cancelled'],
        'prepared'              => ['out_for_delivery', 'cancelled'],
        'out_for_delivery'      => ['completed'],
        'completed'             => [],
        'cancelled'             => [],
        // Legacy states (if any exist in DB) are terminal-blocked here:
        'confirmed'             => [],
        'ready'                 => [],
    ];

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

    $wpdb->query('START TRANSACTION');

    try {
        $now = current_time('mysql');

        $update_result = $wpdb->update(
            $table_orders,
            [
                'status'     => $new_status,
                'updated_at' => $now
            ],
            ['id' => $order_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($update_result === false) {
            throw new Exception('Failed to update order status');
        }

        $insert_result = $wpdb->insert(
            $table_status_history,
            [
                'order_id'   => $order_id,
                'status'     => $new_status,
                'changed_by' => (int) $session->user_id,
                'created_at' => $now
            ],
            ['%d', '%s', '%d', '%s']
        );

        if ($insert_result === false) {
            throw new Exception('Failed to insert status history');
        }

        $wpdb->query('COMMIT');

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');

        return new WP_REST_Response([
            'error'   => 'transaction-failed',
            'message' => 'Status update failed. Please try again.'
        ], 500);
    }

    return new WP_REST_Response([
        'success'       => true,
        'order_id'      => $order_id,
        'from_status'   => $current_status,
        'status'        => $new_status
    ], 200);
}