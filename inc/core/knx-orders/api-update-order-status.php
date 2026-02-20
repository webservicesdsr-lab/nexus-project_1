<?php
/**
 * KNX ORDERS — UPDATE ORDER STATUS (DB-CANON LIFECYCLE)
 *
 * Canon endpoint:
 * POST /wp-json/knx/v1/orders/{order_id}/status
 * Body: { status: "..." }
 *
 * Backward-compat alias (OPS legacy):
 * POST /wp-json/knx/v1/ops/update-status
 * Body: { order_id: 123, to_status: "..." }
 *
 * DB-canon statuses:
 * pending_payment,
 * confirmed,
 * accepted_by_driver,
 * accepted_by_hub,
 * preparing,
 * prepared,
 * picked_up,
 * completed,
 * cancelled
 *
 * Access:
 * - super_admin: allowed
 * - manager: city-scoped via {prefix}knx_manager_cities (fail-closed)
 * - others: 404 (no existence leak)
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

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
                    $allowed = knx_orders_allowed_statuses_db_canon();
                    return in_array((string)$param, $allowed, true);
                }
            ]
        ]
    ]);

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
                    $allowed = knx_orders_allowed_statuses_db_canon();
                    return in_array((string)$param, $allowed, true);
                }
            ]
        ]
    ]);
});

/**
 * Allowed statuses (targets) for DB-canon lifecycle.
 * pending_payment is not a target (webhook-only).
 *
 * @return array
 */
function knx_orders_allowed_statuses_db_canon() {
    return [
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

/**
 * Resolve manager allowed city IDs via knx_manager_cities.
 * Fail-closed if missing or empty.
 *
 * @param int $manager_user_id
 * @return array<int>
 */
function knx_orders_manager_city_ids($manager_user_id) {
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
 * OPS alias handler: maps {to_status} -> {status} and reuses canonical handler.
 */
function knx_ops_update_order_status_alias($request) {
    $to = (string) $request->get_param('to_status');
    $request->set_param('status', $to);
    return knx_update_order_status_handler($request);
}

function knx_update_order_status_handler($request) {
    global $wpdb;

    $session = knx_get_session();
    if (!$session || !isset($session->user_id, $session->role)) {
        return new WP_REST_Response(['error' => 'session-invalid'], 401);
    }

    $order_id = (int) $request->get_param('order_id');

    $new_status = (string) $request->get_param('status');
    if ($new_status === '') {
        $new_status = (string) $request->get_param('to_status');
    }
    $new_status = sanitize_text_field($new_status);

    $allowed_targets = knx_orders_allowed_statuses_db_canon();
    if (!in_array($new_status, $allowed_targets, true)) {
        return new WP_REST_Response([
            'error'   => 'invalid-status',
            'message' => 'Invalid status: ' . $new_status
        ], 400);
    }

    $prefix               = $wpdb->prefix;
    $table_orders         = $prefix . 'knx_orders';
    $table_status_history = $prefix . 'knx_order_status_history';

    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, city_id, status, payment_status
         FROM {$table_orders}
         WHERE id = %d
         LIMIT 1",
        $order_id
    ));

    if (!$order) {
        return new WP_REST_Response(['error' => 'order-not-found'], 404);
    }

    $role = strtolower((string)$session->role);

    if ($role === 'super_admin') {
        // unrestricted
    } elseif ($role === 'manager') {
        $allowed = knx_orders_manager_city_ids((int)$session->user_id);
        if (empty($allowed)) {
            return new WP_REST_Response(['error' => 'order-not-found'], 404);
        }
        $city_id = (int)($order->city_id ?? 0);
        if ($city_id <= 0 || !in_array($city_id, $allowed, true)) {
            return new WP_REST_Response(['error' => 'order-not-found'], 404);
        }
    } else {
        return new WP_REST_Response(['error' => 'order-not-found'], 404);
    }

    $current_status = (string) ($order->status ?? '');

    // Hard block: payment gating
    if ($current_status === 'pending_payment') {
        return new WP_REST_Response([
            'error'   => 'invalid-transition',
            'message' => 'Order is pending payment. Status changes are blocked until payment is confirmed.'
        ], 409);
    }

    // Fail-closed: do not operate on unpaid orders
    $ps = strtolower((string)($order->payment_status ?? ''));
    if ($ps !== 'paid') {
        return new WP_REST_Response([
            'error'   => 'payment-not-paid',
            'message' => 'Order is not paid. Status changes are blocked.'
        ], 409);
    }

    if ($current_status === $new_status) {
        return new WP_REST_Response([
            'error'   => 'invalid-transition',
            'message' => 'Status is already ' . $current_status
        ], 400);
    }

    // DB-canon transition matrix
    $allowed_transitions = [
        'confirmed'           => ['accepted_by_driver', 'cancelled'],
        'accepted_by_driver'  => ['accepted_by_hub', 'cancelled'],
        'accepted_by_hub'     => ['preparing', 'cancelled'],
        'preparing'           => ['prepared', 'cancelled'],
        'prepared'            => ['picked_up', 'cancelled'],
        'picked_up'           => ['completed'],
        'completed'           => [],
        'cancelled'           => [],
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