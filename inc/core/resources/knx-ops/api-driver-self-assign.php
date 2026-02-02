<?php
/**
 * Driver self-assign endpoint (y05-aligned)
 *
 * POST /wp-json/knx/v1/ops/driver-self-assign
 * Body: { "order_id": <int> }
 *
 * Security:
 * - permission_callback => knx_rest_permission_driver_context()
 *
 * Fail-closed checks:
 * - order exists
 * - status assignable: placed, confirmed, preparing, ready
 * - order is in driver's hub/city scope (knx_driver_hubs / knx_driver_cities)
 *
 * Race-safety:
 * - START TRANSACTION
 * - SELECT ... FOR UPDATE on knx_driver_ops
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'knx_do__table_exists_cached' ) ) {
    function knx_do__table_exists_cached( $table_name ) {
        $cache_key = 'tbl_exists_' . md5( (string) $table_name );
        $cached = wp_cache_get( $cache_key, 'knx_driver_ops' );
        if ( false !== $cached ) return (bool) $cached;

        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) );
        $exists = ! empty( $found );

        wp_cache_set( $cache_key, $exists ? 1 : 0, 'knx_driver_ops', 600 );
        return $exists;
    }
}

if ( ! function_exists( 'knx_do__load_driver_scope' ) ) {
    function knx_do__load_driver_scope( $driver_id ) {
        global $wpdb;

        $driver_id = (int) $driver_id;

        $t_hubs   = $wpdb->prefix . 'knx_driver_hubs';
        $t_cities = $wpdb->prefix . 'knx_driver_cities';

        $hub_ids  = array();
        $city_ids = array();

        if ( $driver_id > 0 && knx_do__table_exists_cached( $t_hubs ) ) {
            $hub_ids = $wpdb->get_col( $wpdb->prepare( "SELECT hub_id FROM {$t_hubs} WHERE driver_id = %d", $driver_id ) );
            if ( is_array( $hub_ids ) ) $hub_ids = array_values( array_unique( array_map( 'intval', $hub_ids ) ) );
        }

        if ( $driver_id > 0 && knx_do__table_exists_cached( $t_cities ) ) {
            $city_ids = $wpdb->get_col( $wpdb->prepare( "SELECT city_id FROM {$t_cities} WHERE driver_id = %d", $driver_id ) );
            if ( is_array( $city_ids ) ) $city_ids = array_values( array_unique( array_map( 'intval', $city_ids ) ) );
        }

        return array(
            'hub_ids'  => is_array( $hub_ids ) ? $hub_ids : array(),
            'city_ids' => is_array( $city_ids ) ? $city_ids : array(),
        );
    }
}

if ( ! function_exists( 'knx_do__lookup_user_role' ) ) {
    function knx_do__lookup_user_role( $user_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return '';

        $t_users = $wpdb->prefix . 'knx_users';
        if ( ! knx_do__table_exists_cached( $t_users ) ) return '';

        $role = $wpdb->get_var( $wpdb->prepare( "SELECT role FROM {$t_users} WHERE id = %d", $user_id ) );
        return is_string( $role ) ? $role : '';
    }
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'knx/v1', '/ops/driver-self-assign', array(
        'methods'             => 'POST',
        'callback'            => 'knx_api_driver_self_assign_handler',
        'permission_callback' => knx_rest_permission_driver_context(),
    ) );
} );

function knx_api_driver_self_assign_handler( WP_REST_Request $request ) {
    global $wpdb;

    if ( ! function_exists( 'knx_get_driver_context' ) ) {
        return new WP_Error( 'missing_helper', 'Driver context helper missing', array( 'status' => 500 ) );
    }

    $driver_ctx = knx_get_driver_context();
    if ( empty( $driver_ctx ) || empty( $driver_ctx->session ) || empty( $driver_ctx->driver_id ) ) {
        return new WP_Error( 'forbidden', 'Driver context not available', array( 'status' => 403 ) );
    }

    $driver_user_id = (int) $driver_ctx->session->user_id;
    $driver_id      = (int) $driver_ctx->driver_id;

    $body = $request->get_json_params();
    $order_id = isset( $body['order_id'] ) ? (int) $body['order_id'] : 0;

    if ( $order_id <= 0 ) {
        return new WP_Error( 'invalid_order', 'order_id is required', array( 'status' => 400 ) );
    }

    $t_orders = $wpdb->prefix . 'knx_orders';
    $t_ops    = $wpdb->prefix . 'knx_driver_ops';

    if ( ! knx_do__table_exists_cached( $t_orders ) || ! knx_do__table_exists_cached( $t_ops ) ) {
        return new WP_Error( 'missing_tables', 'Required tables missing', array( 'status' => 500 ) );
    }

    $order = $wpdb->get_row(
        $wpdb->prepare( "SELECT id, status, city_id, hub_id FROM {$t_orders} WHERE id = %d", $order_id ),
        ARRAY_A
    );

    if ( ! $order ) {
        return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
    }

    // Assignable statuses (y05)
    $assignable_statuses = array( 'placed', 'confirmed', 'preparing', 'ready' );
    if ( empty( $order['status'] ) || ! in_array( (string) $order['status'], $assignable_statuses, true ) ) {
        return new WP_Error( 'status_not_assignable', 'Order status is not assignable', array( 'status' => 422 ) );
    }

    // Scope check (fail-closed)
    $scope = knx_do__load_driver_scope( $driver_id );
    $allowed_hub_ids  = $scope['hub_ids'];
    $allowed_city_ids = $scope['city_ids'];

    if ( empty( $allowed_hub_ids ) && empty( $allowed_city_ids ) ) {
        return new WP_Error( 'forbidden_scope', 'Driver is not configured for any hub/city', array( 'status' => 403 ) );
    }

    $order_hub_id  = ! empty( $order['hub_id'] ) ? (int) $order['hub_id'] : 0;
    $order_city_id = ! empty( $order['city_id'] ) ? (int) $order['city_id'] : 0;

    $in_scope = false;
    if ( $order_hub_id > 0 && ! empty( $allowed_hub_ids ) && in_array( $order_hub_id, $allowed_hub_ids, true ) ) {
        $in_scope = true;
    }
    if ( ! $in_scope && $order_city_id > 0 && ! empty( $allowed_city_ids ) && in_array( $order_city_id, $allowed_city_ids, true ) ) {
        $in_scope = true;
    }

    if ( ! $in_scope ) {
        return new WP_Error( 'forbidden_scope', 'Driver is not permitted for this order city/hub', array( 'status' => 403 ) );
    }

    $now = current_time( 'mysql', 1 );

    $wpdb->query( 'START TRANSACTION' );

    try {
        $ops_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT order_id, driver_user_id, assigned_by, ops_status FROM {$t_ops} WHERE order_id = %d FOR UPDATE",
                $order_id
            ),
            ARRAY_A
        );

        $ops_status = $ops_row && isset( $ops_row['ops_status'] ) ? (string) $ops_row['ops_status'] : '';
        $ops_driver_user_id = $ops_row && ! empty( $ops_row['driver_user_id'] ) ? (int) $ops_row['driver_user_id'] : 0;

        // Taken by someone else (and not unassigned)
        if ( $ops_row && $ops_driver_user_id > 0 && $ops_driver_user_id !== $driver_user_id && $ops_status !== 'unassigned' ) {
            $assigned_by_user_id = ! empty( $ops_row['assigned_by'] ) ? (int) $ops_row['assigned_by'] : null;

            $wpdb->query( 'ROLLBACK' );

            return rest_ensure_response( array(
                'assigned'                => false,
                'already_assigned'        => true,
                'assigned_driver_user_id' => $ops_driver_user_id,
                'assigned_by_user_id'     => $assigned_by_user_id,
                'assigned_by_role'        => $assigned_by_user_id ? knx_do__lookup_user_role( $assigned_by_user_id ) : '',
            ) );
        }

        // Already assigned to you (idempotent)
        if ( $ops_row && $ops_driver_user_id === $driver_user_id && $ops_status !== 'unassigned' ) {
            $wpdb->query( 'COMMIT' );

            return rest_ensure_response( array(
                'assigned'         => false,
                'already_assigned' => true,
                'assigned_to_you'  => true,
                'order_id'         => $order_id,
                'driver_user_id'   => $driver_user_id,
                'driver_id'        => $driver_id,
            ) );
        }

        // Claim it (update or insert)
        if ( $ops_row ) {
            $ok = $wpdb->update(
                $t_ops,
                array(
                    'driver_user_id' => $driver_user_id,
                    'assigned_by'    => $driver_user_id,
                    'ops_status'     => 'assigned',
                    'assigned_at'    => $now,
                    'updated_at'     => $now,
                ),
                array( 'order_id' => $order_id ),
                array( '%d', '%d', '%s', '%s', '%s' ),
                array( '%d' )
            );
            if ( false === $ok ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'db_error', 'Failed to update knx_driver_ops', array( 'status' => 500 ) );
            }
        } else {
            $ok = $wpdb->insert(
                $t_ops,
                array(
                    'order_id'       => $order_id,
                    'driver_user_id' => $driver_user_id,
                    'assigned_by'    => $driver_user_id,
                    'ops_status'     => 'assigned',
                    'assigned_at'    => $now,
                    'updated_at'     => $now,
                ),
                array( '%d', '%d', '%d', '%s', '%s', '%s' )
            );
            if ( false === $ok ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'db_error', 'Failed to insert knx_driver_ops', array( 'status' => 500 ) );
            }
        }

        // Reflect in orders table
        $ok_order = $wpdb->update(
            $t_orders,
            array(
                'driver_id'   => $driver_id,
                'updated_at'  => $now,
            ),
            array( 'id' => $order_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( false === $ok_order ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_error', 'Failed to update knx_orders', array( 'status' => 500 ) );
        }

        $wpdb->query( 'COMMIT' );

        // Best-effort audit
        if ( function_exists( 'knx_ops_assign_driver_audit' ) ) {
            try {
                knx_ops_assign_driver_audit(
                    $order_id,
                    'driver_self_assign',
                    'Driver self-assign',
                    array( 'driver_user_id' => $driver_user_id, 'driver_id' => $driver_id ),
                    $driver_ctx->session
                );
            } catch ( Exception $e ) {
                // non-fatal
            }
        }

        return rest_ensure_response( array(
            'assigned'       => true,
            'order_id'       => $order_id,
            'driver_user_id' => $driver_user_id,
            'driver_id'      => $driver_id,
        ) );

    } catch ( Exception $ex ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'exception', $ex->getMessage(), array( 'status' => 500 ) );
    }
}
