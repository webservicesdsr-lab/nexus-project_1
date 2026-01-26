<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX ADDRESSES — REST API v1.8 (CRUD + select + default)
 * ----------------------------------------------------------
 * ROADMAP v1.8: /my-addresses backend
 * 
 * Endpoints (all POST):
 * - /knx/v1/addresses/list
 * - /knx/v1/addresses/add
 * - /knx/v1/addresses/update
 * - /knx/v1/addresses/delete (soft)
 * - /knx/v1/addresses/set-default
 * - /knx/v1/addresses/select (sets SSOT + returns redirect)
 * 
 * Rules:
 * - Session required (knx_rest_permission_session)
 * - Ownership enforced
 * - Coords required for add/update (Leaflet-first, no manual inputs)
 * - Transactional writes
 * - Fail-closed if table missing
 * ==========================================================
 */

add_action('rest_api_init', function() {
    
    // Safety: skip registration if wrapper missing
    if (!function_exists('knx_rest_wrap')) {
        return;
    }

    $namespace = 'knx/v1';
    
    // Custom permission: session-only (no WP cookie auth required)
    $permission = function () {
        // Only check if session exists (knx_get_session validates it)
        if (function_exists('knx_get_session')) {
            $session = knx_get_session();
            if ($session && isset($session->user_id) && $session->user_id > 0) {
                return true;
            }
        }
        return new WP_Error('knx_unauthorized', 'Session required', ['status' => 401]);
    };

    // List addresses
    register_rest_route($namespace, '/addresses/list', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_addresses_list'),
        'permission_callback' => $permission,
    ]);

    // Add address
    register_rest_route($namespace, '/addresses/add', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_addresses_add'),
        'permission_callback' => $permission,
    ]);

    // Update address
    register_rest_route($namespace, '/addresses/update', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_addresses_update'),
        'permission_callback' => $permission,
    ]);

    // Delete address (soft)
    register_rest_route($namespace, '/addresses/delete', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_addresses_delete'),
        'permission_callback' => $permission,
    ]);

    // Set default
    register_rest_route($namespace, '/addresses/set-default', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_addresses_set_default'),
        'permission_callback' => $permission,
    ]);

    // Select (SSOT)
    register_rest_route($namespace, '/addresses/select', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_addresses_select'),
        'permission_callback' => $permission,
    ]);
});

/**
 * Permission fallback if knx_rest_permission_session doesn'\''t exist
 */
if (!function_exists('knx_addresses_permission_fallback')) {
    function knx_addresses_permission_fallback() {
        // Check session
        if (function_exists('knx_get_session')) {
            $session = knx_get_session();
            if ($session && isset($session->user_id) && $session->user_id > 0) {
                return true;
            }
        }
        
        // Fallback to WP user
        return is_user_logged_in();
    }
}

/**
 * Get customer ID from session/WP user
 */
if (!function_exists('knx_addresses_get_customer_id')) {
    function knx_addresses_get_customer_id() {
        // Session-only identity (SSOT = knx_users.id)
        if (function_exists('knx_get_session')) {
            $session = knx_get_session();
            if ($session && isset($session->user_id) && $session->user_id > 0) {
                return (int) $session->user_id;
            }
        }
        
        // No fallback to WP user (fail-closed)
        return 0;
    }
}

/**
 * ==========================================================
 * ENDPOINT: List addresses
 * ==========================================================
 */
function knx_api_addresses_list($request) {
    global $wpdb;

    if (!knx_addresses_table_exists()) {
        return [
            'success' => false,
            'reason'  => 'SYSTEM_UNAVAILABLE',
            'message' => 'Address system is not available.',
        ];
    }

    $customer_id = knx_addresses_get_customer_id();
    if ($customer_id <= 0) {
        return [
            'success' => false,
            'reason'  => 'AUTH_REQUIRED',
            'message' => 'Please log in.',
        ];
    }

    $addresses = knx_addresses_list_for_customer($customer_id);
    $selected_id = knx_session_get_selected_address_id();
    // Determine canonical SSOT-selected id (session/cookie -> DB default)
    $ssot_id = knx_addresses_get_selected_id_for_customer($customer_id);

    // Enrich with flags
    foreach ($addresses as &$addr) {
        $addr->is_selected = ((int) $addr->id === $selected_id);
        // Normalize is_default according to SSOT so UI shows a single default
        $addr->is_default = ((int) $addr->id === $ssot_id) ? 1 : 0;
        $addr->one_line = knx_addresses_format_one_line($addr);
    }

    // Return payload only (wrapper adds success/message)
    return [
        'addresses'   => $addresses,
        'selected_id' => $selected_id,
    ];
}

/**
 * ==========================================================
 * ENDPOINT: Add address
 * ==========================================================
 */
function knx_api_addresses_add($request) {
    global $wpdb;

    if (!knx_addresses_table_exists()) {
        return [
            'success' => false,
            'reason'  => 'SYSTEM_UNAVAILABLE',
            'message' => 'Address system is not available.',
        ];
    }

    $customer_id = knx_addresses_get_customer_id();
    if ($customer_id <= 0) {
        return [
            'success' => false,
            'reason'  => 'AUTH_REQUIRED',
            'message' => 'Please log in.',
        ];
    }

    $body = $request->get_json_params() ?: [];

    // Required fields
    $label = knx_addresses_trim($body['label'] ?? '', 100);
    $line1 = knx_addresses_trim($body['line1'] ?? '', 255);
    $city  = knx_addresses_trim($body['city'] ?? '', 100);

    if (!$line1 || !$city) {
        return [
            'success' => false,
            'reason'  => 'INVALID_DATA',
            'message' => 'Address and city are required.',
        ];
    }

    // Coords required (Leaflet-first)
    $lat = isset($body['latitude']) ? (float) $body['latitude'] : null;
    $lng = isset($body['longitude']) ? (float) $body['longitude'] : null;

    list($lat, $lng) = knx_addresses_normalize_coords($lat, $lng);

    if ($lat === null || $lng === null) {
        return [
            'success' => false,
            'reason'  => 'MISSING_COORDS',
            'message' => 'Please set location on map.',
        ];
    }

    // Optional fields
    $line2       = knx_addresses_trim($body['line2'] ?? '', 255);
    $state       = knx_addresses_trim($body['state'] ?? '', 50);
    $postal_code = knx_addresses_trim($body['postal_code'] ?? '', 20);
    $country     = knx_addresses_trim($body['country'] ?? 'USA', 100);

    $table = knx_addresses_table();

    // Transaction
    $wpdb->query('START TRANSACTION');

    $inserted = $wpdb->insert($table, [
        'customer_id'  => $customer_id,
        'label'        => $label,
        'line1'        => $line1,
        'line2'        => $line2,
        'city'         => $city,
        'state'        => $state,
        'postal_code'  => $postal_code,
        'country'      => $country,
        'latitude'     => $lat,
        'longitude'    => $lng,
        'is_default'   => 0,
        'created_at'   => knx_addresses_now_mysql(),
        'updated_at'   => knx_addresses_now_mysql(),
    ], [
        '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%s'
    ]);

    if ($inserted === false) {
        $wpdb->query('ROLLBACK');
        return [
            'success' => false,
            'reason'  => 'DB_ERROR',
            'message' => 'Could not save address.',
        ];
    }

    $address_id = $wpdb->insert_id;

    $wpdb->query('COMMIT');

    return [
        'success' => true,
        'data'    => [
            'address_id' => $address_id,
            'message'    => 'Address added successfully.',
        ],
    ];
}

/**
 * ==========================================================
 * ENDPOINT: Update address
 * ==========================================================
 */
function knx_api_addresses_update($request) {
    global $wpdb;

    if (!knx_addresses_table_exists()) {
        return [
            'success' => false,
            'reason'  => 'SYSTEM_UNAVAILABLE',
            'message' => 'Address system is not available.',
        ];
    }

    $customer_id = knx_addresses_get_customer_id();
    if ($customer_id <= 0) {
        return [
            'success' => false,
            'reason'  => 'AUTH_REQUIRED',
            'message' => 'Please log in.',
        ];
    }

    $body = $request->get_json_params() ?: [];
    $address_id = isset($body['address_id']) ? (int) $body['address_id'] : 0;

    if ($address_id <= 0) {
        return [
            'success' => false,
            'reason'  => 'INVALID_ID',
            'message' => 'Address ID required.',
        ];
    }

    // Verify ownership
    $existing = knx_get_address_by_id($address_id, $customer_id);
    if (!$existing) {
        return [
            'success' => false,
            'reason'  => 'NOT_FOUND',
            'message' => 'Address not found.',
        ];
    }

    // Required fields
    $label = knx_addresses_trim($body['label'] ?? '', 100);
    $line1 = knx_addresses_trim($body['line1'] ?? '', 255);
    $city  = knx_addresses_trim($body['city'] ?? '', 100);

    if (!$line1 || !$city) {
        return [
            'success' => false,
            'reason'  => 'INVALID_DATA',
            'message' => 'Address and city are required.',
        ];
    }

    // Coords required
    $lat = isset($body['latitude']) ? (float) $body['latitude'] : null;
    $lng = isset($body['longitude']) ? (float) $body['longitude'] : null;

    list($lat, $lng) = knx_addresses_normalize_coords($lat, $lng);

    if ($lat === null || $lng === null) {
        return [
            'success' => false,
            'reason'  => 'MISSING_COORDS',
            'message' => 'Please set location on map.',
        ];
    }

    // Optional fields
    $line2       = knx_addresses_trim($body['line2'] ?? '', 255);
    $state       = knx_addresses_trim($body['state'] ?? '', 50);
    $postal_code = knx_addresses_trim($body['postal_code'] ?? '', 20);
    $country     = knx_addresses_trim($body['country'] ?? 'USA', 100);

    $table = knx_addresses_table();

    // Transaction
    $wpdb->query('START TRANSACTION');

    $updated = $wpdb->update($table, [
        'label'        => $label,
        'line1'        => $line1,
        'line2'        => $line2,
        'city'         => $city,
        'state'        => $state,
        'postal_code'  => $postal_code,
        'country'      => $country,
        'latitude'     => $lat,
        'longitude'    => $lng,
        'updated_at'   => knx_addresses_now_mysql(),
    ], [
        'id'          => $address_id,
        'customer_id' => $customer_id,
    ], [
        '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s'
    ], [
        '%d', '%d'
    ]);

    if ($updated === false) {
        $wpdb->query('ROLLBACK');
        return [
            'success' => false,
            'reason'  => 'DB_ERROR',
            'message' => 'Could not update address.',
        ];
    }

    $wpdb->query('COMMIT');

    return [
        'success' => true,
        'data'    => [
            'message' => 'Address updated successfully.',
        ],
    ];
}

/**
 * ==========================================================
 * ENDPOINT: Delete address (SOFT DELETE)
 * ==========================================================
 */
function knx_api_addresses_delete($request) {
    global $wpdb;

    if (!function_exists('knx_get_session')) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'SESSION_ENGINE_MISSING',
            'message' => 'System unavailable.',
        ], 503);
    }

    $session = knx_get_session();
    if (!$session || empty($session->user_id)) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'AUTH_REQUIRED',
            'message' => 'Please login.',
        ], 401);
    }

    $customer_id = (int) $session->user_id;
    $address_id  = (int) $request->get_param('address_id');

    if ($address_id <= 0) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'INVALID_ADDRESS_ID',
        ], 400);
    }

    $table = $wpdb->prefix . 'knx_addresses';

    // Ensure address belongs to customer
    $address = $wpdb->get_row($wpdb->prepare(
        "SELECT id, is_default
         FROM {$table}
         WHERE id = %d AND customer_id = %d
         LIMIT 1",
        $address_id,
        $customer_id
    ));

    if (!$address) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'ADDRESS_NOT_FOUND',
        ], 404);
    }

    $now = current_time('mysql');

    // Soft delete
    $updated = $wpdb->update(
        $table,
        [
            'deleted_at' => $now,
            'status'     => 'deleted',
            'is_default' => 0,
        ],
        [
            'id' => $address_id,
            'customer_id' => $customer_id,
        ],
        ['%s', '%s', '%d'],
        ['%d', '%d']
    );

    if ($updated === false) {
        return new WP_REST_Response([
            'success' => false,
            'reason'  => 'DELETE_FAILED',
            'message' => 'Unable to delete address.',
        ], 500);
    }

    // If this address was selected → clear SSOT selection
    if (function_exists('knx_session_get_selected_address_id')
        && function_exists('knx_session_clear_selected_address_id')) {

        $selected_id = knx_session_get_selected_address_id();
        if ((int) $selected_id === (int) $address_id) {
            knx_session_clear_selected_address_id();
        }
    }

    return new WP_REST_Response([
        'success' => true,
        'cleared_selection' => true,
    ], 200);
}

/**
 * ==========================================================
 * ENDPOINT: Set default
 * ==========================================================
 */
function knx_api_addresses_set_default($request) {
    global $wpdb;

    if (!knx_addresses_table_exists()) {
        return [
            'success' => false,
            'reason'  => 'SYSTEM_UNAVAILABLE',
            'message' => 'Address system is not available.',
        ];
    }

    $customer_id = knx_addresses_get_customer_id();
    if ($customer_id <= 0) {
        return [
            'success' => false,
            'reason'  => 'AUTH_REQUIRED',
            'message' => 'Please log in.',
        ];
    }

    $body = $request->get_json_params() ?: [];
    $address_id = isset($body['address_id']) ? (int) $body['address_id'] : 0;

    if ($address_id <= 0) {
        return [
            'success' => false,
            'reason'  => 'INVALID_ID',
            'message' => 'Address ID required.',
        ];
    }

    // Verify ownership
    $existing = knx_get_address_by_id($address_id, $customer_id);
    if (!$existing) {
        return [
            'success' => false,
            'reason'  => 'NOT_FOUND',
            'message' => 'Address not found.',
        ];
    }

    $table = knx_addresses_table();

    // Require is_default column for this operation
    if (!knx_addresses_has_col('is_default')) {
        return [
            'success' => false,
            'reason'  => 'MISSING_COLUMN',
            'message' => 'Address defaults are not supported on this installation.',
        ];
    }

    // Transaction
    $wpdb->query('START TRANSACTION');

    // Use helper to clear defaults (DB-safe)
    $cleared = knx_clear_default_address($customer_id);
    if ($cleared === false) {
        $wpdb->query('ROLLBACK');
        return [
            'success' => false,
            'reason'  => 'DB_ERROR',
            'message' => 'Could not clear existing defaults.',
        ];
    }

    // Set new default (ownership enforced in WHERE)
    $updated = $wpdb->update($table, ['is_default' => 1], [
        'id'          => $address_id,
        'customer_id' => $customer_id,
    ], ['%d'], ['%d', '%d']);

    if ($updated === false || $updated === 0) {
        $wpdb->query('ROLLBACK');
        return [
            'success' => false,
            'reason'  => 'NOT_FOUND',
            'message' => 'Address not found or not owned by you.',
        ];
    }

    // Extra safety: ensure uniqueness (clear any accidental duplicates)
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET is_default = 0 WHERE customer_id = %d AND id != %d",
        $customer_id,
        $address_id
    ));

    $wpdb->query('COMMIT');

    return [
        'success' => true,
        'data'    => [
            'message' => 'Default address updated.',
        ],
    ];
}

/**
 * ==========================================================
 * ENDPOINT: Select (SSOT setter)
 * ==========================================================
 */
function knx_api_addresses_select($request) {
    if (!knx_addresses_table_exists()) {
        return [
            'success' => false,
            'reason'  => 'SYSTEM_UNAVAILABLE',
            'message' => 'Address system is not available.',
        ];
    }

    $customer_id = knx_addresses_get_customer_id();
    if ($customer_id <= 0) {
        return [
            'success' => false,
            'reason'  => 'AUTH_REQUIRED',
            'message' => 'Please log in.',
        ];
    }

    $body = $request->get_json_params() ?: [];
    $address_id = isset($body['address_id']) ? (int) $body['address_id'] : 0;

    if ($address_id <= 0) {
        return [
            'success' => false,
            'reason'  => 'INVALID_ID',
            'message' => 'Address ID required.',
        ];
    }

    // Verify ownership and coords
    $addr = knx_get_address_by_id($address_id, $customer_id);
    if (!$addr) {
        return [
            'success' => false,
            'reason'  => 'NOT_FOUND',
            'message' => 'Address not found.',
        ];
    }

    // Fail-closed: require coords for selection
    $lat = isset($addr->latitude) ? (float) $addr->latitude : 0.0;
    $lng = isset($addr->longitude) ? (float) $addr->longitude : 0.0;

    if ($lat == 0.0 || $lng == 0.0) {
        return [
            'success' => false,
            'reason'  => 'MISSING_COORDS',
            'message' => 'Please set location on map before using this address.',
        ];
    }

    // ================================================================
    // PHASE 4.3: COVERAGE GATE (INJECTION POINT)
    // ================================================================
    // Gate-only enforcement: coverage check before SSOT write.
    // Does NOT mutate state, does NOT redirect, does NOT write DB.
    // Only blocks selection if address is outside delivery coverage.
    // ================================================================

    // Retrieve hub_id from active cart (fail-open if no cart)
    $hub_id = knx_addresses_get_cart_hub_id($customer_id);

    // Only enforce coverage if:
    // 1. Hub is known (hub_id > 0)
    // 2. Coverage function exists (SSOT is available)
    if ($hub_id > 0 && function_exists('knx_check_coverage')) {
        $coverage = knx_check_coverage($hub_id, $lat, $lng);

        // Fail-closed: block selection if not deliverable
        if (!$coverage['ok']) {
            $reason = $coverage['reason'] ?? 'UNKNOWN';

            // Map technical reason to user-safe message
            $user_messages = [
                'OUT_OF_COVERAGE'    => 'This address is outside our delivery area. Please select another address or try pickup.',
                'NO_ACTIVE_ZONE'     => 'Delivery is not available for this restaurant yet.',
                'DELIVERY_DISABLED'  => 'Delivery is currently unavailable for this restaurant.',
                'ZONE_NOT_POLYGON'   => 'Delivery coverage is temporarily unavailable.',
                'INVALID_POLYGON'    => 'Delivery coverage is temporarily unavailable.',
                'INVALID_JSON'       => 'Delivery coverage is temporarily unavailable.',
                'MISSING_POLYGON'    => 'Delivery coverage is temporarily unavailable.',
                'HUB_NOT_FOUND'      => 'Restaurant not found. Please refresh and try again.',
                'INVALID_HUB_ID'     => 'Restaurant not found. Please refresh and try again.',
                'MISSING_COORDS'     => 'Address coordinates are missing. Please try again.',
            ];

            $message = $user_messages[$reason] ?? 'Delivery is not available to this address.';

            return [
                'success' => false,
                'reason'  => $reason,
                'message' => $message,
            ];
        }
    }

    // Set SSOT
    $set_ok = knx_session_set_selected_address_id($address_id);

    if ($set_ok === false) {
        return [
            'success' => false,
            'reason'  => 'DB_ERROR',
            'message' => 'Failed to select address for checkout.',
        ];
    }

    return [
        'success' => true,
        'data'    => [
            'message'     => 'Address selected.',
            'redirect_to' => '/cart',
        ],
    ];
}
