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
 * - driver: allowed ONLY if orders.driver_id matches driver profile id (knx_drivers.id)
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
 * Resolve driver profile id (knx_drivers.id) from session user_id using knx_get_driver_context().
 * Fail-closed if cannot resolve.
 *
 * @param object $session
 * @return int
 */
function knx_orders_driver_profile_id_from_session($session) {
    if (!function_exists('knx_get_driver_context')) return 0;
    $ctx = knx_get_driver_context();
    if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id)) return 0;

    // Strong preference: ctx->driver->id
    if (isset($ctx->driver) && is_object($ctx->driver) && isset($ctx->driver->id)) {
        $id = (int)$ctx->driver->id;
        return $id > 0 ? $id : 0;
    }

    // Fail-closed: if driver profile isn't available, do not allow.
    return 0;
}

/**
 * OPS alias handler: maps {to_status} -> {status} and reuses canonical handler.
 */
function knx_ops_update_order_status_alias($request) {
    $to = (string) $request->get_param('to_status');
    $request->set_param('status', $to);
    return knx_update_order_status_handler($request);
}

/**
 * ==========================================================
 * SSOT — Apply DB-canon status change (Reusable)
 * ----------------------------------------------------------
 * This function is the single source of truth for:
 * - Payment gating
 * - Transition matrix validation
 * - Orders update
 * - Status history insert
 *
 * IMPORTANT:
 * - This function does NOT check role/scope.
 * - Callers MUST enforce authorization + scope first.
 *
 * Returns:
 * - WP_Error on failure (with status code in data['status'])
 * - array on success: ['success','order_id','from_status','status']
 * ==========================================================
 */
function knx_orders_apply_status_change_db_canon($order_id, $new_status, $changed_by_user_id, $now_mysql = null) {
    global $wpdb;

    $order_id = (int)$order_id;
    $changed_by_user_id = (int)$changed_by_user_id;

    if ($order_id <= 0) {
        return new WP_Error('invalid-order-id', 'Invalid order_id', ['status' => 400]);
    }

    $new_status = sanitize_text_field((string)$new_status);
    $allowed_targets = knx_orders_allowed_statuses_db_canon();
    if (!in_array($new_status, $allowed_targets, true)) {
        return new WP_Error('invalid-status', 'Invalid status: ' . $new_status, ['status' => 400]);
    }

    $table_orders         = $wpdb->prefix . 'knx_orders';
    $table_status_history = $wpdb->prefix . 'knx_order_status_history';

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

    $wpdb->query('START TRANSACTION');

    try {
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, payment_status
             FROM {$table_orders}
             WHERE id = %d
             FOR UPDATE",
            $order_id
        ));

        if (!$order) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('order-not-found', 'Order not found', ['status' => 404]);
        }

        $current_status = (string)($order->status ?? '');
        $ps = strtolower((string)($order->payment_status ?? ''));

        if ($current_status === 'pending_payment') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invalid-transition', 'Order is pending payment. Status changes are blocked until payment is confirmed.', ['status' => 409]);
        }

        if ($ps !== 'paid') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('payment-not-paid', 'Order is not paid. Status changes are blocked.', ['status' => 409]);
        }

        if ($current_status === $new_status) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('status-already', 'Status is already ' . $current_status, ['status' => 400]);
        }

        if (!isset($allowed_transitions[$current_status])) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invalid-current-status', 'Unknown current status: ' . $current_status, ['status' => 400]);
        }

        $allowed = $allowed_transitions[$current_status];
        if (!in_array($new_status, $allowed, true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invalid-transition', sprintf(
                'Cannot transition from %s to %s. Allowed: %s',
                $current_status,
                $new_status,
                empty($allowed) ? 'none (terminal state)' : implode(', ', $allowed)
            ), ['status' => 400]);
        }

        $now = is_string($now_mysql) && $now_mysql !== '' ? $now_mysql : current_time('mysql');

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

        $exists_hist = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_status_history));
        if (empty($exists_hist)) {
            throw new Exception('Status history table missing');
        }

        $insert_result = $wpdb->insert(
            $table_status_history,
            [
                'order_id'   => $order_id,
                'status'     => $new_status,
                'changed_by' => $changed_by_user_id,
                'created_at' => $now
            ],
            ['%d', '%s', '%d', '%s']
        );

        if ($insert_result === false) {
            throw new Exception('Failed to insert status history');
        }

        $wpdb->query('COMMIT');

        return [
            'success'     => true,
            'order_id'    => $order_id,
            'from_status' => $current_status,
            'status'      => $new_status,
        ];

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('transaction-failed', 'Status update failed. Please try again.', ['status' => 500]);
    }
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

    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT id, city_id, status, payment_status, driver_id
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
        if (empty($allowed)) return new WP_REST_Response(['error' => 'order-not-found'], 404);

        $city_id = (int)($order->city_id ?? 0);
        if ($city_id <= 0 || !in_array($city_id, $allowed, true)) {
            return new WP_REST_Response(['error' => 'order-not-found'], 404);
        }
    } elseif ($role === 'driver') {
        // Driver is authorized for all DB-canon statuses, but ONLY for assigned orders (driver_id match).
        $driver_profile_id = knx_orders_driver_profile_id_from_session($session);
        if ($driver_profile_id <= 0) return new WP_REST_Response(['error' => 'order-not-found'], 404);

        $order_driver_id = (int)($order->driver_id ?? 0);
        if ($order_driver_id <= 0 || $order_driver_id !== $driver_profile_id) {
            return new WP_REST_Response(['error' => 'order-not-found'], 404);
        }
    } else {
        return new WP_REST_Response(['error' => 'order-not-found'], 404);
    }

    // Apply via SSOT
    $res = knx_orders_apply_status_change_db_canon($order_id, $new_status, (int)$session->user_id, current_time('mysql'));

    if (is_wp_error($res)) {
        $status = (int)($res->get_error_data()['status'] ?? 400);
        return new WP_REST_Response([
            'error'   => $res->get_error_code(),
            'message' => $res->get_error_message(),
        ], $status);
    }

    return new WP_REST_Response($res, 200);
}