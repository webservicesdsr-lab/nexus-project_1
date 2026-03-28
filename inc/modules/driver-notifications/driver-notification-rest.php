<?php
/**
 * ==========================================================
 * KNX Driver Notifications — REST Endpoints
 * ==========================================================
 */
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/driver-notifications/status', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_dn_rest_status')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin']),
    ]);

    register_rest_route('knx/v1', '/driver-notifications/test', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            return knx_rest_wrap('knx_dn_rest_test')($request);
        },
        'permission_callback' => knx_rest_permission_roles(['super_admin']),
    ]);
});

/**
 * Return notification system status.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function knx_dn_rest_status(WP_REST_Request $request) {
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    $table_exists = knx_dn_table_exists_live();

    $stats = [
        'table_exists' => $table_exists,
        'table_name'   => knx_dn_table_name(),
        'counts'       => null,
    ];

    if ($table_exists) {
        global $wpdb;
        $table = knx_dn_table_name();

        $total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $pending    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending'));
        $processing = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'processing'));
        $delivered  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'delivered'));
        $failed     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'failed'));

        $stats['counts'] = [
            'total'      => $total,
            'pending'    => $pending,
            'processing' => $processing,
            'delivered'  => $delivered,
            'failed'     => $failed,
        ];

        $recent = $wpdb->get_results(
            "SELECT id, order_id, driver_id, city_id, event_type, channel, status, attempts, created_at, sent_at, available_at
             FROM {$table}
             ORDER BY created_at DESC
             LIMIT 10",
            ARRAY_A
        );

        $stats['recent'] = is_array($recent) ? $recent : [];
    }

    return $stats;
}

/**
 * Test broadcast for a specific order.
 *
 * @param WP_REST_Request $request
 * @return array|WP_REST_Response
 */
function knx_dn_rest_test(WP_REST_Request $request) {
    $session = knx_rest_require_session();
    if ($session instanceof WP_REST_Response) return $session;

    $roleCheck = knx_rest_require_role($session, ['super_admin']);
    if ($roleCheck instanceof WP_REST_Response) return $roleCheck;

    if (function_exists('knx_rest_require_nonce')) {
        $nonceRes = knx_rest_require_nonce($request);
        if ($nonceRes instanceof WP_REST_Response) return $nonceRes;
    }

    $body = $request->get_json_params();
    $order_id = isset($body['order_id']) ? (int) $body['order_id'] : 0;

    if ($order_id <= 0) {
        return knx_rest_error('order_id is required', 400);
    }

    if (!knx_dn_table_exists_live()) {
        return knx_rest_error('Notifications table does not exist. Provision via nexus-schema-y05.sql.', 409);
    }

    $broadcast_result = knx_dn_broadcast_order_available($order_id);

    return [
        'order_id' => $order_id,
        'result'   => $broadcast_result,
    ];
}