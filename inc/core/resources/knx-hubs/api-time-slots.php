<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Hub Time Slots API (v1.0)
 * ----------------------------------------------------------
 * Returns available same-day delivery/pickup time slots
 * based on the hub's operating hours for today.
 *
 * Route: GET /wp-json/knx/v1/hubs/<hub_id>/time-slots
 * Auth:  Session (logged-in user with active cart)
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/hubs/(?P<hub_id>\d+)/time-slots', [
        'methods'             => 'GET',
        'callback'            => 'knx_api_hub_time_slots',
        'permission_callback' => '__return_true', // public — slots are not secret
        'args' => [
            'hub_id' => [
                'required'          => true,
                'validate_callback' => function ($v) { return is_numeric($v) && (int) $v > 0; },
                'sanitize_callback' => 'absint',
            ],
            'eta_minutes' => [
                'required'          => false,
                'default'           => 0,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});

/**
 * GET /wp-json/knx/v1/hubs/{hub_id}/time-slots
 *
 * Response shape (knx_rest_response):
 *   success: true
 *   data: {
 *     slots: [ { value: "14:00", label: "2:00 PM – 2:30 PM" }, … ],
 *     timezone: "America/Chicago",
 *     today: "monday"
 *   }
 */
function knx_api_hub_time_slots(WP_REST_Request $r) {
    $hub_id      = (int) $r->get_param('hub_id');
    $eta_minutes = max(0, (int) $r->get_param('eta_minutes')); // 0 = pickup or unknown

    if (!function_exists('knx_hours_generate_time_slots')) {
        return knx_rest_response(false, 'Hours engine not loaded.', null, 500);
    }

    $slots = knx_hours_generate_time_slots($hub_id, $eta_minutes);

    // Fetch timezone + day for frontend context
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hubs';
    $hub   = $wpdb->get_row($wpdb->prepare(
        "SELECT timezone FROM {$table} WHERE id = %d LIMIT 1",
        $hub_id
    ));

    $tz_name = ($hub && !empty($hub->timezone)) ? $hub->timezone : 'America/Chicago';
    try {
        $tz  = new DateTimeZone($tz_name);
        $now = new DateTime('now', $tz);
        $day = strtolower($now->format('l'));
    } catch (Exception $e) {
        $day = strtolower(gmdate('l'));
    }

    return knx_rest_response(true, 'Time slots retrieved.', [
        'slots'       => $slots,
        'timezone'    => $tz_name,
        'today'       => $day,
        'eta_minutes' => $eta_minutes,
    ], 200);
}
