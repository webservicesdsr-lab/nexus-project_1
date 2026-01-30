<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Location Search API (Canonical)
 * Provides autocomplete functionality for location search
 *
 * Endpoint: GET /knx/v1/location-search?q={query}
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/location-search', [
        'methods'             => 'GET',
        'callback'            => knx_rest_wrap('knx_api_location_search'),
        'permission_callback' => '__return_true',
        'args' => [
            'q' => [
                'required'          => true,
                'type'              => 'string',
                'description'       => 'Search query for location',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
});

/**
 * Location search callback
 *
 * Contract (must remain stable):
 * - Returns: { success: bool, items: [{ id, name, state, display }] }
 *
 * Notes:
 * - Reads from knx_cities (no writes)
 * - Provider enrichment moved to dedicated geocode endpoint (/knx/v1/geocode-search)
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function knx_api_location_search($request) {
    global $wpdb;

    $query = (string) $request->get_param('q');
    $query = trim($query);

    // Minimum 2 characters for search (stable behavior)
    if (mb_strlen($query) < 2) {
        return new WP_REST_Response([
            'success' => true,
            'items'   => [],
        ], 200);
    }

    // -----------------------------
    // DB suggestions (knx_cities)
    // -----------------------------
    $table_cities = $wpdb->prefix . 'knx_cities';

    // Check if table exists (safe)
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_cities));
    if ($exists !== $table_cities) {
        // Keep behavior explicit: if the table is missing, surface as server error
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Cities table not found',
        ], 500);
    }

    // Detect columns (schema-flex but fail-closed for required fields)
    $cols_rows = $wpdb->get_results("SHOW COLUMNS FROM {$table_cities}");
    $cols = [];
    if (is_array($cols_rows)) {
        foreach ($cols_rows as $r) {
            if (!empty($r->Field)) $cols[] = (string) $r->Field;
        }
    }

    // Required columns: name (your schema uses name/state)
    // Fallback support: city_name/state_code (older schema) if present
    $col_name  = in_array('name', $cols, true) ? 'name' : (in_array('city_name', $cols, true) ? 'city_name' : '');
    $col_state = in_array('state', $cols, true) ? 'state' : (in_array('state_code', $cols, true) ? 'state_code' : '');

    if ($col_name === '') {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Cities schema mismatch',
        ], 500);
    }

    // Optional filters only if columns exist
    $where = [];
    $where[] = "{$col_name} LIKE %s";

    if (in_array('status', $cols, true)) {
        $where[] = "status = 'active'";
    }

    if (in_array('is_operational', $cols, true)) {
        $where[] = "is_operational = 1";
    }

    if (in_array('deleted_at', $cols, true)) {
        $where[] = "deleted_at IS NULL";
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $search_term = '%' . $wpdb->esc_like($query) . '%';

    // Build SELECT safely (only whitelisted column names)
    $select_cols = "id, {$col_name} AS city_name";
    if ($col_state !== '') {
        $select_cols .= ", {$col_state} AS state_code";
    } else {
        // Keep contract stable: state can be empty if no state column exists
        $select_cols .= ", '' AS state_code";
    }

    $sql = $wpdb->prepare(
        "SELECT {$select_cols}
         FROM {$table_cities}
         {$where_sql}
         ORDER BY city_name ASC
         LIMIT 10",
        $search_term
    );

    $cities = $wpdb->get_results($sql);

    if ($wpdb->last_error) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Database error',
        ], 500);
    }

    $db_items = [];
    if (is_array($cities)) {
        foreach ($cities as $city) {
            $name  = isset($city->city_name) ? trim((string) $city->city_name) : '';
            $state = isset($city->state_code) ? trim((string) $city->state_code) : '';

            $display = $name;
            if ($state !== '') {
                $display .= ', ' . $state;
            }

            $db_items[] = [
                'id'      => isset($city->id) ? (int) $city->id : 0,
                'name'    => $name,
                'state'   => $state,
                'display' => $display,
            ];
        }
    }

    // -----------------------------
    // Photon has been removed. This endpoint now returns DB suggestions only.
    // The geocoding/autocomplete provider is Nominatim by default and is
    // served by the dedicated /knx/v1/geocode-search endpoint.
    // -----------------------------

    // Ensure we return a stable contract (items array)
    return new WP_REST_Response([
        'success' => true,
        'items'   => $db_items,
    ], 200);
}
