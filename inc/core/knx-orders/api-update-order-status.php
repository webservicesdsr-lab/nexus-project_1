<?php
/**
 * KNX ORDERS — UPDATE ORDER STATUS (CANON: Financial + Ops lifecycle)
 *
 * Canon endpoint:
 * POST /wp-json/knx/v1/orders/{order_id}/status
 * Body: { status: "..." }
 *
 * Backward-compat alias (OPS):
 * POST /wp-json/knx/v1/ops/update-status
 * Body: { order_id: 123, to_status: "..." }
 *
 * Sealed Contract:
 * Financial (invisible):
 *   pending_payment -> confirmed  (webhook/system)
 *
 * Ops (Driver First):
 *   confirmed -> accepted_by_driver -> accepted_by_hub -> preparing -> prepared -> picked_up -> completed
 *
 * Cancel:
 *   cancelled allowed from any non-terminal
 *
 * Hard rules:
 * - No backwards
 * - No skipping
 * - completed/cancelled are terminal
 * - pending_payment is not part of ops timeline (blocked for ops moves)
 *
 * Transition guard:
 *   next_index = current_index + 1
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
                    $allowed = knx_orders_allowed_status_inputs();
                    return in_array((string)$param, $allowed, true);
                }
            ]
        ]
    ]);

    // OPS alias
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
                    $allowed = knx_orders_allowed_status_inputs();
                    return in_array((string)$param, $allowed, true);
                }
            ]
        ]
    ]);
});

/**
 * Allowed input statuses (defensive).
 * Includes legacy synonyms so old clients don't break during migration.
 *
 * @return array<int,string>
 */
function knx_orders_allowed_status_inputs() {
    return [
        // Canon targets
        'confirmed',
        'accepted_by_driver',
        'accepted_by_hub',
        'preparing',
        'prepared',
        'picked_up',
        'completed',
        'cancelled',

        // Legacy inputs (normalized server-side)
        'placed',
        'accepted_by_restaurant',
        'out_for_delivery',
        'ready',
    ];
}

/**
 * Canon operational flow (SSOT order).
 *
 * @return array<int,string>
 */
function knx_orders_flow_driver_first() {
    return [
        'confirmed',
        'accepted_by_driver',
        'accepted_by_hub',
        'preparing',
        'prepared',
        'picked_up',
        'completed',
    ];
}

/**
 * Normalize legacy statuses to canon.
 *
 * @param string $status
 * @return string
 */
function knx_orders_normalize_status($status) {
    $s = strtolower(trim((string)$status));

    // legacy aliases
    if ($s === 'placed') return 'confirmed';
    if ($s === 'accepted_by_restaurant') return 'accepted_by_hub';
    if ($s === 'out_for_delivery') return 'picked_up';
    if ($s === 'ready') return 'prepared';

    return $s;
}

/**
 * Get index in canon flow.
 *
 * @param string $status
 * @return int|null
 */
function knx_orders_flow_index($status) {
    $flow = knx_orders_flow_driver_first();
    $i = array_search((string)$status, $flow, true);
    return ($i === false) ? null : (int)$i;
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
 * Update order status with strict transition validation (index-based).
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

    $new_status_raw = (string) $request->get_param('status');
    if ($new_status_raw === '') {
        $new_status_raw = (string) $request->get_param('to_status');
    }
    $new_status_raw = sanitize_text_field($new_status_raw);

    if ($new_status_raw === '') {
        return new WP_REST_Response([
            'error' => 'invalid-status',
            'message' => 'status is required'
        ], 400);
    }

    $new_status = knx_orders_normalize_status($new_status_raw);

    $allowed_targets = [
        'confirmed',
        'accepted_by_driver',
        'accepted_by_hub',
        'preparing',
        'prepared',
        'picked_up',
        'completed',
        'cancelled',
    ];
    if (!in_array($new_status, $allowed_targets, true)) {
        return new WP_REST_Response([
            'error'   => 'invalid-status',
            'message' => 'Invalid status: ' . $new_status_raw
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

    $current_status_raw = (string) ($order->status ?? '');
    $current_status = knx_orders_normalize_status($current_status_raw);

    $skip_index_guard = false;

    // Financial boundary: pending_payment is invisible.
    if ($current_status === 'pending_payment') {

        // allowed from pending_payment: confirmed (payment success) OR cancelled (fail-safe)
        if (!in_array($new_status, ['confirmed', 'cancelled'], true)) {
            return new WP_REST_Response([
                'error'   => 'invalid-transition',
                'message' => 'Order is pending payment. Status changes are blocked until payment is confirmed.'
            ], 409);
        }

        if ($new_status === 'confirmed') {
            // Optional: prevent managers from doing financial promotion
            if ($role === 'manager') {
                return new WP_REST_Response([
                    'error'   => 'forbidden',
                    'message' => 'Only the payment system can promote pending_payment → confirmed.'
                ], 403);
            }
            $skip_index_guard = true; // pending_payment is outside the ops index flow
        }
    }

    if ($current_status === $new_status) {
        return new WP_REST_Response([
            'error'   => 'invalid-transition',
            'message' => 'Status is already ' . $current_status
        ], 400);
    }

    // Terminal states are immutable
    if (in_array($current_status, ['completed', 'cancelled'], true)) {
        return new WP_REST_Response([
            'error'   => 'invalid-transition',
            'message' => 'Order is in a terminal status: ' . $current_status
        ], 409);
    }

    // Cancellation allowed from any non-terminal state
    if ($new_status !== 'cancelled' && !$skip_index_guard) {

        // Financial-only confirmed: humans cannot set confirmed from ops flow
        if ($new_status === 'confirmed' && $current_status !== 'pending_payment') {
            return new WP_REST_Response([
                'error'   => 'forbidden',
                'message' => 'confirmed is controlled by the payment system.'
            ], 403);
        }

        // Canon guard: next_index = current_index + 1
        $cur_i = knx_orders_flow_index($current_status);
        $to_i  = knx_orders_flow_index($new_status);

        if ($cur_i === null) {
            return new WP_REST_Response([
                'error'   => 'invalid-current-status',
                'message' => 'Unknown current status: ' . $current_status_raw
            ], 400);
        }
        if ($to_i === null) {
            return new WP_REST_Response([
                'error'   => 'invalid-status',
                'message' => 'Unknown target status: ' . $new_status_raw
            ], 400);
        }

        if ($to_i !== ($cur_i + 1)) {
            return new WP_REST_Response([
                'error'   => 'invalid-transition',
                'message' => sprintf(
                    'Cannot transition from %s to %s. Only the next status is allowed.',
                    $current_status,
                    $new_status
                )
            ], 409);
        }
    }

    // Status history table must exist (fail-closed)
    $hist_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_status_history));
    if (empty($hist_exists)) {
        return new WP_REST_Response([
            'error'   => 'history-not-configured',
            'message' => 'Status history table is missing.'
        ], 500);
    }

    $wpdb->query('START TRANSACTION');

    try {
        $now = current_time('mysql');

        // WRITE #1: Update order status
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

        // WRITE #2: Append to status history
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
        'success'     => true,
        'order_id'    => $order_id,
        'from_status' => $current_status,
        'status'      => $new_status
    ], 200);
}
