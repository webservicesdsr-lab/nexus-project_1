<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Food Truck Saved Locations — REST Endpoints (v1.0)
 * ----------------------------------------------------------
 * CRUD for food-truck operator saved serving locations.
 *
 * Routes:
 *   GET    /knx/v1/hub-management/ft-locations          → list
 *   POST   /knx/v1/hub-management/ft-locations           → add
 *   POST   /knx/v1/hub-management/ft-locations/update    → update
 *   POST   /knx/v1/hub-management/ft-locations/delete    → delete
 *   POST   /knx/v1/hub-management/ft-locations/select    → set active
 *   POST   /knx/v1/hub-management/ft-locations/check     → coverage check
 *
 * Storage: y05_knx_hub_management_settings
 *   key = knx_hub_ft_locations_{hub_id}  →  JSON array
 *   key = knx_hub_ft_active_loc_{hub_id} →  selected location id
 *
 * Security: session + role + ownership (fail-closed)
 * ==========================================================
 */

add_action('rest_api_init', function () {

    // List saved locations
    register_rest_route('knx/v1', '/hub-management/ft-locations', [
        [
            'methods'             => 'GET',
            'callback'            => knx_rest_wrap('knx_ft_locations_list'),
            'permission_callback' => knx_rest_permission_session(),
        ],
        [
            'methods'             => 'POST',
            'callback'            => knx_rest_wrap('knx_ft_locations_add'),
            'permission_callback' => knx_rest_permission_session(),
        ],
    ]);

    // Update a location
    register_rest_route('knx/v1', '/hub-management/ft-locations/update', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_ft_locations_update'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // Delete a location
    register_rest_route('knx/v1', '/hub-management/ft-locations/delete', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_ft_locations_delete'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // Select active location
    register_rest_route('knx/v1', '/hub-management/ft-locations/select', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_ft_locations_select'),
        'permission_callback' => knx_rest_permission_session(),
    ]);

    // Coverage check for a location
    register_rest_route('knx/v1', '/hub-management/ft-locations/check', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_ft_locations_check_coverage'),
        'permission_callback' => knx_rest_permission_session(),
    ]);
});

/* ── Helpers ──────────────────────────────────────── */

function _knx_ft_locations_key($hub_id) {
    return 'knx_hub_ft_locations_' . intval($hub_id);
}
function _knx_ft_active_key($hub_id) {
    return 'knx_hub_ft_active_loc_' . intval($hub_id);
}

function _knx_ft_get_locations($hub_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hub_management_settings';
    $key   = _knx_ft_locations_key($hub_id);
    $raw   = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$table} WHERE setting_key = %s LIMIT 1",
        $key
    ));
    return $raw ? json_decode($raw, true) : [];
}

function _knx_ft_save_locations($hub_id, $locations) {
    global $wpdb;
    $table   = $wpdb->prefix . 'knx_hub_management_settings';
    $key     = _knx_ft_locations_key($hub_id);
    $payload = wp_json_encode(array_values($locations));

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE setting_key = %s LIMIT 1",
        $key
    ));

    if ($existing) {
        $wpdb->update($table, [
            'setting_value' => $payload,
            'updated_at'    => current_time('mysql'),
        ], ['id' => $existing], ['%s', '%s'], ['%d']);
    } else {
        $wpdb->insert($table, [
            'setting_key'   => $key,
            'setting_value' => $payload,
            'created_at'    => current_time('mysql'),
        ], ['%s', '%s', '%s']);
    }
}

function _knx_ft_get_active_id($hub_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hub_management_settings';
    $key   = _knx_ft_active_key($hub_id);
    $val   = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$table} WHERE setting_key = %s LIMIT 1",
        $key
    ));
    return $val ? $val : null;
}

function _knx_ft_set_active_id($hub_id, $loc_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_hub_management_settings';
    $key   = _knx_ft_active_key($hub_id);

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE setting_key = %s LIMIT 1",
        $key
    ));

    if ($existing) {
        $wpdb->update($table, [
            'setting_value' => (string) $loc_id,
            'updated_at'    => current_time('mysql'),
        ], ['id' => $existing], ['%s', '%s'], ['%d']);
    } else {
        $wpdb->insert($table, [
            'setting_key'   => $key,
            'setting_value' => (string) $loc_id,
            'created_at'    => current_time('mysql'),
        ], ['%s', '%s', '%s']);
    }
}

/* ── LIST ─────────────────────────────────────────── */

function knx_ft_locations_list(WP_REST_Request $request) {
    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $locations = _knx_ft_get_locations($hub_id);
    $active_id = _knx_ft_get_active_id($hub_id);

    return knx_rest_response(true, 'OK', [
        'hub_id'    => $hub_id,
        'locations' => $locations,
        'active_id' => $active_id,
    ]);
}

/* ── ADD ──────────────────────────────────────────── */

function knx_ft_locations_add(WP_REST_Request $request) {
    global $wpdb;

    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $nonce = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_settings_nonce')) {
        return knx_rest_error('Invalid nonce', 403);
    }

    $display_name = sanitize_text_field($request->get_param('display_name') ?? '');
    $note         = sanitize_text_field($request->get_param('note') ?? '');
    $label        = sanitize_text_field($request->get_param('label') ?? '');
    $line1        = sanitize_text_field($request->get_param('line1') ?? '');
    $line2        = sanitize_text_field($request->get_param('line2') ?? '');
    $city         = sanitize_text_field($request->get_param('city') ?? '');
    $state        = sanitize_text_field($request->get_param('state') ?? '');
    $zip          = sanitize_text_field($request->get_param('postal_code') ?? '');
    $country      = sanitize_text_field($request->get_param('country') ?? '');
    $lat          = floatval($request->get_param('latitude'));
    $lng          = floatval($request->get_param('longitude'));

    if (($lat == 0 && $lng == 0) || (empty($display_name) && empty($line1))) {
        return knx_rest_error('Address and coordinates are required', 400);
    }

    $locations = _knx_ft_get_locations($hub_id);

    $new_id = 'ftloc_' . time() . '_' . wp_rand(1000, 9999);

    $new_location = [
        'id'           => $new_id,
        'display_name' => $display_name,
        'note'         => $note,
        'label'        => $label ?: ($display_name ? '' : $line1),
        'line1'        => $line1,
        'line2'        => $line2,
        'city'         => $city,
        'state'        => $state,
        'postal_code'  => $zip,
        'country'      => $country,
        'latitude'     => round($lat, 7),
        'longitude'    => round($lng, 7),
        'created_at'   => gmdate('Y-m-d H:i:s'),
    ];

    $locations[] = $new_location;
    _knx_ft_save_locations($hub_id, $locations);

    // Auto-select the newly added location
    _knx_ft_set_active_id($hub_id, $new_id);

    // Update hub's lat/lng to the new location
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $wpdb->update(
        $table_hubs,
        [
            'latitude'   => $new_location['latitude'],
            'longitude'  => $new_location['longitude'],
            'updated_at' => current_time('mysql'),
        ],
        ['id' => $hub_id],
        ['%f', '%f', '%s'],
        ['%d']
    );

    // Coverage check for the new location
    $coverage = knx_check_coverage($hub_id, $new_location['latitude'], $new_location['longitude']);

    return knx_rest_response(true, 'Location saved and activated', [
        'id'       => $new_id,
        'active'   => true,
        'coverage' => $coverage,
    ]);
}

/* ── UPDATE ───────────────────────────────────────── */

function knx_ft_locations_update(WP_REST_Request $request) {
    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $nonce = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_settings_nonce')) {
        return knx_rest_error('Invalid nonce', 403);
    }

    $loc_id = sanitize_text_field($request->get_param('location_id') ?? '');
    if (!$loc_id) return knx_rest_error('Missing location_id', 400);

    $locations = _knx_ft_get_locations($hub_id);
    $found = false;

    foreach ($locations as &$loc) {
        if ($loc['id'] === $loc_id) {
            // New simplified fields
            $new_display = $request->get_param('display_name');
            $new_note    = $request->get_param('note');
            if ($new_display !== null) $loc['display_name'] = sanitize_text_field($new_display);
            if ($new_note !== null)    $loc['note']         = sanitize_text_field($new_note);

            // Legacy structured fields (backwards compatible)
            $loc['label']       = sanitize_text_field($request->get_param('label') ?? ($loc['label'] ?? ''));
            $loc['line1']       = sanitize_text_field($request->get_param('line1') ?? ($loc['line1'] ?? ''));
            $loc['line2']       = sanitize_text_field($request->get_param('line2') ?? ($loc['line2'] ?? ''));
            $loc['city']        = sanitize_text_field($request->get_param('city') ?? ($loc['city'] ?? ''));
            $loc['state']       = sanitize_text_field($request->get_param('state') ?? ($loc['state'] ?? ''));
            $loc['postal_code'] = sanitize_text_field($request->get_param('postal_code') ?? ($loc['postal_code'] ?? ''));
            $loc['country']     = sanitize_text_field($request->get_param('country') ?? ($loc['country'] ?? ''));

            $new_lat = $request->get_param('latitude');
            $new_lng = $request->get_param('longitude');
            if ($new_lat !== null) $loc['latitude']  = round(floatval($new_lat), 7);
            if ($new_lng !== null) $loc['longitude'] = round(floatval($new_lng), 7);

            $loc['updated_at'] = gmdate('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    unset($loc);

    if (!$found) return knx_rest_error('Location not found', 404);

    _knx_ft_save_locations($hub_id, $locations);

    return knx_rest_response(true, 'Location updated');
}

/* ── DELETE ────────────────────────────────────────── */

function knx_ft_locations_delete(WP_REST_Request $request) {
    global $wpdb;

    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $nonce = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_settings_nonce')) {
        return knx_rest_error('Invalid nonce', 403);
    }

    $loc_id = sanitize_text_field($request->get_param('location_id') ?? '');
    if (!$loc_id) return knx_rest_error('Missing location_id', 400);

    $locations = _knx_ft_get_locations($hub_id);
    $filtered  = array_filter($locations, function ($loc) use ($loc_id) {
        return $loc['id'] !== $loc_id;
    });

    if (count($filtered) === count($locations)) {
        return knx_rest_error('Location not found', 404);
    }

    _knx_ft_save_locations($hub_id, $filtered);

    // If deleted location was active, clear active + reset hub coords (fail-closed)
    $active = _knx_ft_get_active_id($hub_id);
    if ($active === $loc_id) {
        _knx_ft_set_active_id($hub_id, '');

        // Clear hub lat/lng so delivery is properly blocked
        $table_hubs = $wpdb->prefix . 'knx_hubs';
        $wpdb->update(
            $table_hubs,
            [
                'latitude'   => 0,
                'longitude'  => 0,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $hub_id],
            ['%f', '%f', '%s'],
            ['%d']
        );
    }

    return knx_rest_response(true, 'Location deleted');
}

/* ── SELECT (set active) ──────────────────────────── */

function knx_ft_locations_select(WP_REST_Request $request) {
    global $wpdb;

    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $nonce = sanitize_text_field($request->get_param('knx_nonce') ?? '');
    if (!$nonce || !wp_verify_nonce($nonce, 'knx_hub_settings_nonce')) {
        return knx_rest_error('Invalid nonce', 403);
    }

    $loc_id = sanitize_text_field($request->get_param('location_id') ?? '');
    if (!$loc_id) return knx_rest_error('Missing location_id', 400);

    // Verify location exists
    $locations = _knx_ft_get_locations($hub_id);
    $target = null;
    foreach ($locations as $loc) {
        if ($loc['id'] === $loc_id) {
            $target = $loc;
            break;
        }
    }
    if (!$target) return knx_rest_error('Location not found', 404);

    // Coverage check
    $coverage = knx_check_coverage($hub_id, $target['latitude'], $target['longitude']);

    _knx_ft_set_active_id($hub_id, $loc_id);

    // Also update the hub's lat/lng to the selected location
    // so that the distance calculator uses the food truck's current position
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $wpdb->update(
        $table_hubs,
        [
            'latitude'   => $target['latitude'],
            'longitude'  => $target['longitude'],
            'updated_at' => current_time('mysql'),
        ],
        ['id' => $hub_id],
        ['%f', '%f', '%s'],
        ['%d']
    );

    return knx_rest_response(true, 'Location selected', [
        'location_id' => $loc_id,
        'coverage'    => $coverage,
    ]);
}

/* ── COVERAGE CHECK ───────────────────────────────── */

function knx_ft_locations_check_coverage(WP_REST_Request $request) {
    $guard = knx_rest_hub_management_guard($request);
    if ($guard instanceof WP_REST_Response) return $guard;
    [$session, $hub_id] = $guard;

    $lat = floatval($request->get_param('latitude'));
    $lng = floatval($request->get_param('longitude'));

    if ($lat == 0 && $lng == 0) {
        return knx_rest_error('Missing coordinates', 400);
    }

    $coverage = knx_check_coverage($hub_id, $lat, $lng);

    return knx_rest_response(true, 'Coverage check complete', [
        'hub_id'   => $hub_id,
        'coverage' => $coverage,
    ]);
}
