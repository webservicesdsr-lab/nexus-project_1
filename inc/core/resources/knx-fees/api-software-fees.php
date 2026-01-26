<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Software Fees API (City SSOT + Hub Overrides)
 * ----------------------------------------------------------
 * Endpoints:
 *   GET  /wp-json/knx/v1/software-fees
 *   GET  /wp-json/knx/v1/software-fees/hubs?city_id=123
 *   POST /wp-json/knx/v1/software-fees/save
 *   POST /wp-json/knx/v1/software-fees/toggle
 *
 * DB Contract (knx_software_fees):
 * - scope      ENUM('city','hub')
 * - city_id    BIGINT UNSIGNED (required)
 * - hub_id     BIGINT UNSIGNED (0 for city scope)
 * - fee_amount DECIMAL(10,2)
 * - status     'active'|'inactive'
 *
 * Rules:
 * - One ACTIVE row per key:
 *   - City key: (scope='city', city_id, hub_id=0)
 *   - Hub key:  (scope='hub', hub_id)
 * - Never delete rows (deactivate only)
 * - Fail-closed validation
 * - Nonce required (knx_nonce)
 * ==========================================================
 */

add_action('rest_api_init', function () {

    register_rest_route('knx/v1', '/software-fees', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_list_software_fees'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v1', '/software-fees/hubs', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_list_software_fee_hubs'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v1', '/software-fees/save', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_save_software_fee'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v1', '/software-fees/toggle', [
        'methods'             => 'POST',
        'callback'            => knx_rest_wrap('knx_api_toggle_software_fee'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager']),
    ]);
});

/**
 * Verify nonce from JSON body.
 *
 * @param array $body
 * @return true|WP_REST_Response
 */
function knx_fees_require_nonce($body) {
    $nonce = isset($body['knx_nonce']) ? (string) $body['knx_nonce'] : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'knx_nonce')) {
        return knx_rest_response(false, 'NONCE_INVALID', [
            'message' => 'Invalid security token.'
        ], 403);
    }
    return true;
}

/**
 * List all fees (includes city_name and hub_name).
 */
function knx_api_list_software_fees(WP_REST_Request $req) {
    global $wpdb;

    $table_fees   = $wpdb->prefix . 'knx_software_fees';
    $table_cities = $wpdb->prefix . 'knx_cities';
    $table_hubs   = $wpdb->prefix . 'knx_hubs';

    $sql = "
        SELECT
            f.*,
            c.name AS city_name,
            h.name AS hub_name
        FROM {$table_fees} f
        LEFT JOIN {$table_cities} c ON f.city_id = c.id
        LEFT JOIN {$table_hubs}   h ON f.hub_id = h.id
        ORDER BY f.scope ASC, f.city_id ASC, f.hub_id ASC, f.id DESC
    ";

    $fees = $wpdb->get_results($sql);
    if (!is_array($fees)) $fees = [];

    return knx_rest_response(true, 'OK', [
        'fees' => $fees
    ], 200);
}

/**
 * List hubs by city_id (for Hub Overrides UI).
 */
function knx_api_list_software_fee_hubs(WP_REST_Request $req) {
    global $wpdb;

    $city_id = (int) $req->get_param('city_id');
    if ($city_id <= 0) {
        return knx_rest_response(false, 'CITY_REQUIRED', [
            'message' => 'city_id is required.'
        ], 400);
    }

    $table_hubs = $wpdb->prefix . 'knx_hubs';

    // Keep this query minimal to avoid column mismatch surprises.
    $hubs = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name
         FROM {$table_hubs}
         WHERE city_id = %d
         ORDER BY name ASC",
        $city_id
    ));

    if (!is_array($hubs)) $hubs = [];

    return knx_rest_response(true, 'OK', [
        'hubs' => $hubs
    ], 200);
}

/**
 * Save software fee (create or update).
 *
 * Payload:
 * - id (optional)
 * - scope: 'city'|'hub'
 * - city_id (required)
 * - hub_id  (required for hub scope, 0 for city scope)
 * - fee_amount (>=0)
 * - status: 'active'|'inactive'
 * - knx_nonce
 */
function knx_api_save_software_fee(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'INVALID_REQUEST', [
            'message' => 'Invalid request format.'
        ], 400);
    }

    $nonce_ok = knx_fees_require_nonce($body);
    if ($nonce_ok !== true) return $nonce_ok;

    $table_fees   = $wpdb->prefix . 'knx_software_fees';
    $table_cities = $wpdb->prefix . 'knx_cities';
    $table_hubs   = $wpdb->prefix . 'knx_hubs';

    $id        = isset($body['id']) ? (int) $body['id'] : 0;
    $scope     = isset($body['scope']) ? (string) $body['scope'] : '';
    $city_id   = isset($body['city_id']) ? (int) $body['city_id'] : 0;
    $hub_id    = isset($body['hub_id']) ? (int) $body['hub_id'] : 0;
    $fee_amount = isset($body['fee_amount']) ? (float) $body['fee_amount'] : -1;
    $status    = isset($body['status']) ? (string) $body['status'] : 'active';

    if (!in_array($scope, ['city', 'hub'], true)) {
        return knx_rest_response(false, 'INVALID_SCOPE', [
            'message' => 'Scope must be city or hub.'
        ], 400);
    }

    if ($city_id <= 0) {
        return knx_rest_response(false, 'CITY_REQUIRED', [
            'message' => 'city_id is required.'
        ], 400);
    }

    if ($scope === 'city') {
        $hub_id = 0;
    } else {
        if ($hub_id <= 0) {
            return knx_rest_response(false, 'HUB_REQUIRED', [
                'message' => 'hub_id is required for hub scope.'
            ], 400);
        }
    }

    if (!is_finite($fee_amount) || $fee_amount < 0) {
        return knx_rest_response(false, 'INVALID_FEE_AMOUNT', [
            'message' => 'fee_amount must be >= 0.'
        ], 400);
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        return knx_rest_response(false, 'INVALID_STATUS', [
            'message' => 'status must be active or inactive.'
        ], 400);
    }

    // Validate city exists
    $city_exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_cities} WHERE id = %d",
        $city_id
    ));
    if ($city_exists <= 0) {
        return knx_rest_response(false, 'CITY_NOT_FOUND', [
            'message' => 'Selected city does not exist.'
        ], 404);
    }

    // Validate hub exists + belongs to city (hub scope only)
    if ($scope === 'hub') {
        $hub_city = $wpdb->get_var($wpdb->prepare(
            "SELECT city_id FROM {$table_hubs} WHERE id = %d LIMIT 1",
            $hub_id
        ));
        if ($hub_city === null) {
            return knx_rest_response(false, 'HUB_NOT_FOUND', [
                'message' => 'Selected hub does not exist.'
            ], 404);
        }
        if ((int) $hub_city !== $city_id) {
            return knx_rest_response(false, 'HUB_CITY_MISMATCH', [
                'message' => 'Hub does not belong to the provided city.'
            ], 400);
        }
    }

    $now = current_time('mysql');

    $wpdb->query('START TRANSACTION');

    try {
        // If activating, enforce one active per key
        if ($status === 'active') {
            if ($scope === 'city') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_fees}
                     SET status = 'inactive', updated_at = %s
                     WHERE scope = 'city'
                       AND city_id = %d
                       AND hub_id = 0
                       AND status = 'active'
                       AND id != %d",
                    $now,
                    $city_id,
                    $id
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_fees}
                     SET status = 'inactive', updated_at = %s
                     WHERE scope = 'hub'
                       AND hub_id = %d
                       AND status = 'active'
                       AND id != %d",
                    $now,
                    $hub_id,
                    $id
                ));
            }
        }

        $data = [
            'scope'      => $scope,
            'city_id'    => $city_id,
            'hub_id'     => $hub_id,
            'fee_amount' => round(max(0.00, (float) $fee_amount), 2),
            'status'     => $status,
            'updated_at' => $now,
        ];

        if ($id > 0) {
            $updated = $wpdb->update($table_fees, $data, ['id' => $id]);
            if ($updated === false) {
                throw new Exception('Update failed');
            }

            $wpdb->query('COMMIT');

            return knx_rest_response(true, 'UPDATED', [
                'message' => 'Fee updated.'
            ], 200);
        }

        $data['created_at'] = $now;

        $inserted = $wpdb->insert($table_fees, $data);
        if (!$inserted) {
            throw new Exception('Insert failed');
        }

        $wpdb->query('COMMIT');

        return knx_rest_response(true, 'CREATED', [
            'message' => 'Fee created.',
            'id'      => (int) $wpdb->insert_id
        ], 201);

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');

        return knx_rest_response(false, 'SAVE_FAILED', [
            'message' => 'Failed to save fee.'
        ], 500);
    }
}

/**
 * Toggle fee status (active <-> inactive).
 *
 * Payload:
 * - id
 * - knx_nonce
 */
function knx_api_toggle_software_fee(WP_REST_Request $req) {
    global $wpdb;

    $body = $req->get_json_params();
    if (empty($body) || !is_array($body)) {
        return knx_rest_response(false, 'INVALID_REQUEST', [
            'message' => 'Invalid request format.'
        ], 400);
    }

    $nonce_ok = knx_fees_require_nonce($body);
    if ($nonce_ok !== true) return $nonce_ok;

    $id = isset($body['id']) ? (int) $body['id'] : 0;
    if ($id <= 0) {
        return knx_rest_response(false, 'INVALID_ID', [
            'message' => 'Invalid fee ID.'
        ], 400);
    }

    $table_fees = $wpdb->prefix . 'knx_software_fees';
    $now = current_time('mysql');

    $fee = $wpdb->get_row($wpdb->prepare(
        "SELECT id, scope, city_id, hub_id, status
         FROM {$table_fees}
         WHERE id = %d
         LIMIT 1",
        $id
    ));

    if (!$fee) {
        return knx_rest_response(false, 'NOT_FOUND', [
            'message' => 'Fee not found.'
        ], 404);
    }

    $new_status = ((string) $fee->status === 'active') ? 'inactive' : 'active';

    $wpdb->query('START TRANSACTION');

    try {
        // If activating, enforce one active per key
        if ($new_status === 'active') {
            if ((string) $fee->scope === 'city') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_fees}
                     SET status = 'inactive', updated_at = %s
                     WHERE scope = 'city'
                       AND city_id = %d
                       AND hub_id = 0
                       AND status = 'active'
                       AND id != %d",
                    $now,
                    (int) $fee->city_id,
                    $id
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_fees}
                     SET status = 'inactive', updated_at = %s
                     WHERE scope = 'hub'
                       AND hub_id = %d
                       AND status = 'active'
                       AND id != %d",
                    $now,
                    (int) $fee->hub_id,
                    $id
                ));
            }
        }

        $updated = $wpdb->update(
            $table_fees,
            ['status' => $new_status, 'updated_at' => $now],
            ['id' => $id]
        );

        if ($updated === false) {
            throw new Exception('Toggle failed');
        }

        $wpdb->query('COMMIT');

        return knx_rest_response(true, 'OK', [
            'message' => 'Fee status updated.',
            'status'  => $new_status
        ], 200);

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');

        return knx_rest_response(false, 'TOGGLE_FAILED', [
            'message' => 'Failed to toggle fee.'
        ], 500);
    }
}
