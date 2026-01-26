<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Cities — Get Delivery Rates (SEALED v1)
 * ----------------------------------------------------------
 * Endpoint:
 * - GET /wp-json/knx/v2/cities/get-delivery-rates
 *
 * Query Params:
 * - city_id (int)
 *
 * Security:
 * - Session required
 * - Role: super_admin ONLY
 *
 * Behavior:
 * - Returns delivery rates for a city
 * - If not found, returns safe defaults (no DB write)
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v2', '/cities/get-delivery-rates', [
        'methods'             => 'GET',
        'callback'            => 'knx_get_city_delivery_rates',
        'permission_callback' => function () {
            return knx_rest_guard([
                'require_session' => true,
                'roles'           => ['super_admin'],
            ]);
        },
    ]);
});

/**
 * Get delivery rates for a city.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_get_city_delivery_rates(WP_REST_Request $request) {
    global $wpdb;

    $city_id = absint($request->get_param('city_id'));
    if (!$city_id) {
        return knx_rest_error('Invalid city id.');
    }

    $table = $wpdb->prefix . 'knx_delivery_rates';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT flat_rate, rate_per_distance, distance_unit, status
             FROM {$table}
             WHERE city_id = %d
             LIMIT 1",
            $city_id
        ),
        ARRAY_A
    );

    /**
     * If no record exists, return defaults
     * (UI will still render clean)
     */
    if (!$row) {
        return knx_rest_success([
            'flat_rate'         => '0.00',
            'rate_per_distance' => '0.00',
            'distance_unit'     => 'mile',
            'status'            => 'active',
            'exists'            => false,
        ]);
    }

    return knx_rest_success([
        'flat_rate'         => (string)$row['flat_rate'],
        'rate_per_distance' => (string)$row['rate_per_distance'],
        'distance_unit'     => $row['distance_unit'],
        'status'            => $row['status'],
        'exists'            => true,
    ]);
}
