<?php
/**
 * Driver available orders - REST endpoint
 *
 * GET /wp-json/knx/v1/ops/driver-available-orders
 *
 * Returns a list of available orders for the driver (metadata only, NO items).
 *
 * Security: Uses knx_rest_permission_driver_context() (must be added in helpers/guards).
 *
 * Follows the SSOT rules using {$wpdb->prefix}knx_driver_ops as authority for availability.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'knx/v1', '/ops/driver-available-orders', array(
        'methods'             => 'GET',
        'callback'            => 'knx_api_driver_available_orders_handler',
        'permission_callback' => knx_rest_permission_driver_context(),
    ) );
} );

function knx_api_driver_available_orders_handler( WP_REST_Request $request ) {
    global $wpdb;

    // Driver context must be resolved by permission callback but check defensively.
    if ( ! function_exists( 'knx_get_driver_context' ) ) {
        return new WP_Error( 'missing_helper', 'Driver context helper missing', array( 'status' => 500 ) );
    }

    $driver_ctx = knx_get_driver_context();
    if ( empty( $driver_ctx ) || empty( $driver_ctx->driver_id ) ) {
        return new WP_Error( 'forbidden', 'Driver context not available', array( 'status' => 403 ) );
    }

    $driver_user_id = intval( $driver_ctx->session->user_id );
    $driver_id = intval( $driver_ctx->driver_id );
    $allowed_hub_ids  = isset( $driver_ctx->hubs ) && is_array( $driver_ctx->hubs ) ? array_map( 'intval', $driver_ctx->hubs ) : array();
    $allowed_city_ids = array();

    // If driver_cities mapping exists, try to load it (best-effort)
    $driver_cities_table = $wpdb->prefix . 'knx_driver_cities';
    $driver_cities_table_exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $driver_cities_table) );
    if ( $driver_cities_table_exists ) {
        $found = $wpdb->get_col( $wpdb->prepare("SELECT city_id FROM {$driver_cities_table} WHERE driver_id = %d", $driver_id) );
        if ( $found && is_array( $found ) ) {
            $allowed_city_ids = array_map( 'intval', $found );
        }
    }

    // Fail-closed by default: if mapping is empty, return empty set
    // But allow opt-in to global scope via filter 'knx_ops_driver_allow_global'.
    if ( empty( $allowed_city_ids ) && empty( $allowed_hub_ids ) ) {
        $allow_global = apply_filters( 'knx_ops_driver_allow_global', false );
        if ( ! $allow_global ) {
            return rest_ensure_response( array() );
        }
        // If global allowed, we'll not restrict by hub/city (scope_where = 1=1)
    }

    // Canonical live statuses
    $live_statuses = array( 'placed', 'confirmed', 'preparing', 'assigned', 'in_progress' );

    $table_orders = $wpdb->prefix . 'knx_orders';
    $table_hubs   = $wpdb->prefix . 'knx_hubs';
    $table_cities = $wpdb->prefix . 'knx_cities';
    $table_ops    = $wpdb->prefix . 'knx_driver_ops';

    // Build scope clause
    $scope_clauses = array();
    $params = array();

    if ( ! empty( $allowed_hub_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $allowed_hub_ids ), '%d' ) );
        $scope_clauses[] = "o.hub_id IN ({$placeholders})";
        foreach ( $allowed_hub_ids as $hid ) $params[] = $hid;
    }

    if ( ! empty( $allowed_city_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $allowed_city_ids ), '%d' ) );
        $scope_clauses[] = "o.city_id IN ({$placeholders})";
        foreach ( $allowed_city_ids as $cid ) $params[] = $cid;
    }

    if ( empty( $scope_clauses ) ) {
        // No scope clauses -> allow all (global) when caller opted in via filter.
        $scope_where = '1=1';
    } else {
        $scope_where = '(' . implode( ' OR ', $scope_clauses ) . ')';
    }

    $status_placeholders = implode( ',', array_fill( 0, count( $live_statuses ), '%s' ) );

    $sql = "
        SELECT
            o.id AS order_id,
            o.order_number AS order_number,
            o.hub_id AS hub_id,
            COALESCE(NULLIF(h.name, ''), '') AS hub_name,
            COALESCE(NULLIF(h.logo_url, ''), NULL) AS hub_logo_url,
            o.city_id AS city_id,
            COALESCE(NULLIF(c.name, ''), '') AS city_name,
            o.created_at AS created_at,
            o.status AS status,
            COALESCE(o.fulfillment_type, '') AS fulfillment_type,
            COALESCE(o.total_amount, 0) AS total,
            COALESCE(o.tip_amount, 0) AS tip_amount,
            COALESCE(o.delivery_lat, NULL) AS delivery_lat,
            COALESCE(o.delivery_lng, NULL) AS delivery_lng,
            CASE WHEN do.driver_user_id = %d THEN 1 ELSE 0 END AS assigned_to_you,
            do.driver_user_id AS assigned_driver_user_id,
            do.assigned_by_user_id AS assigned_by_user_id,
            COALESCE(do.assigned_by_role, '') AS assigned_by_role
        FROM {$table_orders} o
        LEFT JOIN {$table_hubs} h ON o.hub_id = h.id
        LEFT JOIN {$table_cities} c ON o.city_id = c.id
        LEFT JOIN {$table_ops} do ON o.id = do.order_id
        WHERE
            o.status IN ({$status_placeholders})
            AND ( do.driver_user_id IS NULL OR do.ops_status = 'unassigned' OR do.driver_user_id = %d )
            AND {$scope_where}
        ORDER BY o.created_at DESC
        LIMIT 200
    ";

    $prepare_params = array();
    $prepare_params[] = $driver_user_id;
    foreach ( $live_statuses as $s ) $prepare_params[] = $s;
    $prepare_params[] = $driver_user_id;
    $prepare_params = array_merge( $prepare_params, $params );

    $final_sql = $wpdb->prepare( $sql, $prepare_params );

    $rows = $wpdb->get_results( $final_sql, ARRAY_A );

    if ( ! empty( $rows ) ) {
        foreach ( $rows as &$r ) {
            $r['delivery_lat'] = isset( $r['delivery_lat'] ) ? $r['delivery_lat'] : null;
            $r['delivery_lng'] = isset( $r['delivery_lng'] ) ? $r['delivery_lng'] : null;
            if ( ! empty( $r['delivery_lat'] ) && ! empty( $r['delivery_lng'] ) ) {
                $lat = rawurlencode( $r['delivery_lat'] );
                $lng = rawurlencode( $r['delivery_lng'] );
                $r['map_url'] = "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}";
            } else {
                $r['map_url'] = null;
            }
            $r['assigned_to_you'] = boolval( $r['assigned_to_you'] );
            $r['order_id'] = intval( $r['order_id'] );
            $r['hub_id'] = isset( $r['hub_id'] ) ? intval( $r['hub_id'] ) : null;
            $r['city_id'] = isset( $r['city_id'] ) ? intval( $r['city_id'] ) : null;
            $r['assigned_driver_user_id'] = ! empty( $r['assigned_driver_user_id'] ) ? intval( $r['assigned_driver_user_id'] ) : null;
            $r['assigned_by_user_id'] = ! empty( $r['assigned_by_user_id'] ) ? intval( $r['assigned_by_user_id'] ) : null;
            $r['total'] = floatval( $r['total'] );
            $r['tip_amount'] = floatval( $r['tip_amount'] );
        }
        unset( $r );
    } else {
        $rows = array();
    }

    return rest_ensure_response( $rows );
}
