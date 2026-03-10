<?php
/**
 * ==========================================================
 * KNX OPS — Driver Self Assign (Legacy v1) — CANONICALIZED
 * ----------------------------------------------------------
 * Endpoint: POST /wp-json/knx/v1/ops/driver-self-assign
 *
 * Canon behavior:
 * - Same SSOT as v2 claim:
 *   confirmed -> accepted_by_driver
 *   orders.driver_id = driver_profile_id
 *   driver_ops.driver_user_id = session user id
 *   history insert best-effort
 *
 * Notes:
 * - No new endpoints created.
 * - Uses driver context + driver scope (fail-closed).
 * - Transactional with row locks.
 * ==========================================================
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/ops/driver-self-assign', array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'knx_api_driver_self_assign_handler',
        'permission_callback' => function () {
            // Keep existing permission style if present
            if (function_exists('knx_rest_permission_driver_context')) {
                return knx_rest_permission_driver_context();
            }
            // Fallback: require driver context
            if (!function_exists('knx_get_driver_context')) {
                return new WP_Error('knx_missing_driver_context', 'Driver context helper missing.', array('status' => 500));
            }
            $ctx = knx_get_driver_context();
            if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id)) {
                return new WP_Error('knx_forbidden', 'Driver context not available.', array('status' => 403));
            }
            if (empty($ctx->driver_profile_id) || (int)$ctx->driver_profile_id <= 0) {
                return new WP_Error('knx_forbidden', 'Driver profile missing.', array('status' => 403));
            }
            return true;
        },
    ));
});

function knx_api_driver_self_assign_handler(WP_REST_Request $request) {
    global $wpdb;

    if (!function_exists('knx_get_driver_context')) {
        return new WP_Error('knx_missing_driver_context', 'Driver context helper missing.', array('status' => 500));
    }

    $driver_ctx = knx_get_driver_context();
    if (empty($driver_ctx) || empty($driver_ctx->session)) {
        return new WP_Error('forbidden', 'Driver context not available', array('status' => 403));
    }

    $driver_user_id = isset($driver_ctx->driver_user_id) ? (int)$driver_ctx->driver_user_id : (int)$driver_ctx->session->user_id;
    $driver_profile_id = isset($driver_ctx->driver_profile_id) ? (int)$driver_ctx->driver_profile_id : 0;

    if ($driver_profile_id <= 0) {
        return new WP_Error('forbidden', 'Driver profile missing', array('status' => 403));
    }

    // Accept order_id from JSON or params
    $body = $request->get_json_params();
    $order_id = 0;
    if (is_array($body) && isset($body['order_id'])) $order_id = (int)$body['order_id'];
    if ($order_id <= 0) $order_id = (int)$request->get_param('order_id');

    if ($order_id <= 0) {
        return new WP_Error('bad_request', 'order_id is required', array('status' => 400));
    }

    // Load driver scope (canonical: by driver_profile_id)
    $allowed_city_ids = array();
    $allowed_hub_ids  = array();

    if (function_exists('knx_do__load_driver_scope')) {
        $scope = knx_do__load_driver_scope($driver_profile_id);
        if (is_array($scope)) {
            $allowed_city_ids = !empty($scope['city_ids']) && is_array($scope['city_ids']) ? $scope['city_ids'] : array();
            $allowed_hub_ids  = !empty($scope['hub_ids'])  && is_array($scope['hub_ids'])  ? $scope['hub_ids']  : array();
        }
    } else {
        // Minimal fallback
        $t_cities = $wpdb->prefix . 'knx_driver_cities';
        $t_hubs   = $wpdb->prefix . 'knx_driver_hubs';

        $cities_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_cities));
        if (!empty($cities_exists)) {
            $allowed_city_ids = $wpdb->get_col($wpdb->prepare("SELECT city_id FROM {$t_cities} WHERE driver_id = %d", $driver_profile_id));
            if (empty($allowed_city_ids)) {
                $allowed_city_ids = $wpdb->get_col($wpdb->prepare("SELECT city_id FROM {$t_cities} WHERE driver_id = %d", $driver_user_id));
            }
        }

        $hubs_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_hubs));
        if (!empty($hubs_exists)) {
            $allowed_hub_ids = $wpdb->get_col($wpdb->prepare("SELECT hub_id FROM {$t_hubs} WHERE driver_id = %d", $driver_profile_id));
            if (empty($allowed_hub_ids)) {
                $allowed_hub_ids = $wpdb->get_col($wpdb->prepare("SELECT hub_id FROM {$t_hubs} WHERE driver_id = %d", $driver_user_id));
            }
        }
    }

    $allowed_city_ids = array_values(array_unique(array_map('intval', (array)$allowed_city_ids)));
    $allowed_hub_ids  = array_values(array_unique(array_map('intval', (array)$allowed_hub_ids)));

    // Fail-closed for mutation
    if (empty($allowed_city_ids) && empty($allowed_hub_ids)) {
        return new WP_Error('forbidden', 'Driver scope empty', array('status' => 403));
    }

    $t_orders   = $wpdb->prefix . 'knx_orders';
    $t_ops      = $wpdb->prefix . 'knx_driver_ops';
    $t_history  = $wpdb->prefix . 'knx_order_status_history';

    $ops_exists = !empty($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_ops)));
    $hist_exists = !empty($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_history)));

    // Timestamp columns (best-effort)
    $orders_ts_col = '';
    $ops_ts_col = '';
    $orders_ts_col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t_orders} LIKE %s", 'updated_at')) ? 'updated_at' : '';
    if ($orders_ts_col === '' && $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t_orders} LIKE %s", 'modified_at'))) $orders_ts_col = 'modified_at';

    if ($ops_exists) {
        if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t_ops} LIKE %s", 'ops_updated_at'))) $ops_ts_col = 'ops_updated_at';
        elseif ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t_ops} LIKE %s", 'updated_at'))) $ops_ts_col = 'updated_at';
        elseif ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t_ops} LIKE %s", 'modified_at'))) $ops_ts_col = 'modified_at';
    }

    $now = gmdate('Y-m-d H:i:s');

    $wpdb->query('START TRANSACTION');

    try {
        // Lock order row
        $order = $wpdb->get_row(
            $wpdb->prepare("SELECT id, status, driver_id, city_id, hub_id FROM {$t_orders} WHERE id = %d FOR UPDATE", $order_id),
            ARRAY_A
        );

        if (!$order) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('not_found', 'Order not found', array('status' => 404));
        }

        $current_driver_id = !empty($order['driver_id']) ? (int)$order['driver_id'] : 0;
        if ($current_driver_id > 0) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('conflict', 'Order already assigned', array('status' => 409));
        }

        $status = isset($order['status']) ? (string)$order['status'] : '';
        if ($status !== 'confirmed') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('status_not_assignable', 'Order status is not assignable', array('status' => 409, 'current' => $status, 'required' => 'confirmed'));
        }

        $order_city = !empty($order['city_id']) ? (int)$order['city_id'] : 0;
        $order_hub  = !empty($order['hub_id'])  ? (int)$order['hub_id']  : 0;

        $in_scope = false;
        if ($order_hub > 0 && !empty($allowed_hub_ids) && in_array($order_hub, $allowed_hub_ids, true)) $in_scope = true;
        if (!$in_scope && $order_city > 0 && !empty($allowed_city_ids) && in_array($order_city, $allowed_city_ids, true)) $in_scope = true;

        if (!$in_scope) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('forbidden', 'Order outside driver scope', array('status' => 403));
        }

        // Lock ops row (if exists)
        $ops_row = null;
        if ($ops_exists) {
            $ops_row = $wpdb->get_row(
                $wpdb->prepare("SELECT order_id, driver_user_id, ops_status FROM {$t_ops} WHERE order_id = %d FOR UPDATE", $order_id),
                ARRAY_A
            );

            $ops_driver_user_id = $ops_row && !empty($ops_row['driver_user_id']) ? (int)$ops_row['driver_user_id'] : 0;
            $ops_status = $ops_row && isset($ops_row['ops_status']) ? (string)$ops_row['ops_status'] : '';

            if ($ops_driver_user_id > 0 && $ops_driver_user_id !== $driver_user_id && $ops_status !== 'unassigned') {
                $wpdb->query('ROLLBACK');
                return new WP_Error('conflict', 'Order already assigned in ops', array('status' => 409));
            }

            if ($ops_driver_user_id === $driver_user_id && $ops_status !== 'unassigned') {
                $wpdb->query('COMMIT');
                return rest_ensure_response(array(
                    'success' => true,
                    'ok' => true,
                    'data' => array(
                        'assigned' => false,
                        'already_assigned' => true,
                        'order_id' => $order_id,
                        'driver_user_id' => $driver_user_id,
                        'driver_profile_id' => $driver_profile_id,
                        'status' => 'accepted_by_driver',
                    )
                ));
            }
        }

        // Upsert ops row
        if ($ops_exists) {
            $ops_data = array(
                'driver_user_id' => $driver_user_id,
                'assigned_by'    => $driver_user_id,
                'ops_status'     => 'assigned',
                'assigned_at'    => $now,
            );
            if ($ops_ts_col) $ops_data[$ops_ts_col] = $now;

            if ($ops_row) {
                $ok = $wpdb->update($t_ops, $ops_data, array('order_id' => $order_id));
                if ($ok === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('server_error', 'Failed to update ops row', array('status' => 500));
                }
            } else {
                $ops_insert = array_merge(array('order_id' => $order_id), $ops_data);
                $ok = $wpdb->insert($t_ops, $ops_insert);
                if ($ok === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('server_error', 'Failed to insert ops row', array('status' => 500));
                }
            }
        }

        // Update orders SSOT
        $order_update = array(
            'status'    => 'accepted_by_driver',
            'driver_id' => $driver_profile_id,
        );
        if ($orders_ts_col) $order_update[$orders_ts_col] = $now;

        $ok_order = $wpdb->update($t_orders, $order_update, array('id' => $order_id));
        if ($ok_order === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('server_error', 'Failed to update order', array('status' => 500));
        }

        // History (best-effort)
        if ($hist_exists) {
            try {
                $wpdb->insert($t_history, array(
                    'order_id' => $order_id,
                    'status' => 'accepted_by_driver',
                    'changed_by' => $driver_user_id,
                    'created_at' => $now,
                ));
            } catch (Throwable $e) {
                // Non-fatal
            }
        }

        $wpdb->query('COMMIT');

        return rest_ensure_response(array(
            'success' => true,
            'ok' => true,
            'data' => array(
                'assigned' => true,
                'order_id' => $order_id,
                'driver_user_id' => $driver_user_id,
                'driver_profile_id' => $driver_profile_id,
                'status' => 'accepted_by_driver',
                'ops_status' => $ops_exists ? 'assigned' : null,
                'server_gmt' => gmdate('Y-m-d H:i:s'),
            )
        ));

    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('server_error', 'Exception: ' . $e->getMessage(), array('status' => 500));
    }
}
