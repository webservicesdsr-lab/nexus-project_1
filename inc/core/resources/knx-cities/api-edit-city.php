<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — API: Edit City (SEALED v3 / Resources)
 * ----------------------------------------------------------
 * v2 Endpoints:
 * - GET  /wp-json/knx/v2/cities/get-city?city_id=123   (or id=123)
 * - POST /wp-json/knx/v2/cities/update-city           (JSON)
 *
 * Payload (POST JSON):
 * - city_id (int) or id (int)
 * - name (string)
 * - status (active|inactive)
 * - knx_nonce (string)  (action: knx_edit_city_nonce)
 *
 * Security:
 * - Session required (route-level + handler-level)
 * - Roles: super_admin | manager
 * - Nonce required for update
 *
 * Back-compat (guarded shims):
 * - GET  /wp-json/knx/v1/get-city
 * - POST /wp-json/knx/v1/update-city
 * ==========================================================
 */

add_action('rest_api_init', function () {

    /**
     * Permission callback builder (guarded).
     */
    $perm_roles = function (array $roles) {
        if (function_exists('knx_rest_permission_roles')) {
            return knx_rest_permission_roles($roles);
        }

        return function () use ($roles) {
            $s = function_exists('knx_get_session') ? knx_get_session() : null;
            return (is_object($s) && isset($s->role) && in_array($s->role, $roles, true));
        };
    };

    /**
     * Wrapper callback builder (optional).
     */
    $cb = function (string $handler_fn) {
        return function ($request) use ($handler_fn) {
            if (function_exists('knx_rest_wrap')) {
                return knx_rest_wrap($handler_fn)($request);
            }
            return call_user_func($handler_fn, $request);
        };
    };

    // v2 (SEALED)
    register_rest_route('knx/v2', '/cities/get-city', [
        'methods'  => 'GET',
        'callback' => $cb('knx_api_v2_cities_get_city'),
        'permission_callback' => $perm_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v2', '/cities/update-city', [
        'methods'  => 'POST',
        'callback' => $cb('knx_api_v2_cities_update_city'),
        'permission_callback' => $perm_roles(['super_admin', 'manager']),
    ]);

    // v1 shims (guarded)
    register_rest_route('knx/v1', '/get-city', [
        'methods'  => 'GET',
        'callback' => $cb('knx_api_v2_cities_get_city'),
        'permission_callback' => $perm_roles(['super_admin', 'manager']),
    ]);

    register_rest_route('knx/v1', '/update-city', [
        'methods'  => 'POST',
        'callback' => $cb('knx_api_v2_cities_update_city'),
        'permission_callback' => $perm_roles(['super_admin', 'manager']),
    ]);
});

/**
 * ==========================================================
 * Helpers (guarded to avoid redeclare fatals)
 * ==========================================================
 */

if (!function_exists('knx_api__get_session_safe')) {
    function knx_api__get_session_safe() {
        if (function_exists('knx_rest_get_session')) return knx_rest_get_session();
        if (function_exists('knx_get_session')) return knx_get_session();
        return null;
    }
}

if (!function_exists('knx_api__deny')) {
    function knx_api__deny($error, $status = 403) {
        return new WP_REST_Response(['success' => false, 'error' => $error], (int)$status);
    }
}

if (!function_exists('knx_api__ok')) {
    function knx_api__ok($payload, $status = 200) {
        if (!is_array($payload)) $payload = ['data' => $payload];
        $payload = array_merge(['success' => true], $payload);
        return new WP_REST_Response($payload, (int)$status);
    }
}

if (!function_exists('knx_api__table_has_col')) {
    function knx_api__table_has_col($table, $col) {
        static $cache = [];
        $key = $table . '::' . $col;
        if (isset($cache[$key])) return (bool)$cache[$key];

        global $wpdb;
        $like = $wpdb->esc_like($col);
        $exists = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE '{$like}'");
        $cache[$key] = $exists ? true : false;
        return (bool)$cache[$key];
    }
}

if (!function_exists('knx_api__city_is_soft_deleted')) {
    function knx_api__city_is_soft_deleted($city_row) {
        if (!is_object($city_row)) return true;

        $checks = [
            ['deleted_at', function ($v) { return !empty($v) && $v !== '0000-00-00 00:00:00'; }],
            ['is_deleted', function ($v) { return (int)$v === 1; }],
            ['deleted', function ($v) { return (int)$v === 1; }],
            ['archived', function ($v) { return (int)$v === 1; }],
            ['status', function ($v) { return is_string($v) && strtolower($v) === 'deleted'; }],
        ];

        foreach ($checks as [$field, $fn]) {
            if (property_exists($city_row, $field)) {
                try {
                    if ($fn($city_row->{$field})) return true;
                } catch (\Throwable $e) {
                    return true;
                }
            }
        }

        return false;
    }
}

/**
 * ==========================================================
 * Handlers (guarded)
 * ==========================================================
 */

if (!function_exists('knx_api_v2_cities_get_city')) {
    function knx_api_v2_cities_get_city(WP_REST_Request $r) {
        global $wpdb;

        $session = knx_api__get_session_safe();
        if (!is_object($session) || !isset($session->role) || !in_array($session->role, ['super_admin', 'manager'], true)) {
            return knx_api__deny('unauthorized', 403);
        }

        $city_id = absint($r->get_param('city_id') ?: $r->get_param('id'));
        if (!$city_id) {
            return knx_api__deny('missing_id', 400);
        }

        $table_cities = $wpdb->prefix . 'knx_cities';
        $city = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_cities} WHERE id = %d", $city_id));

        if (!$city || knx_api__city_is_soft_deleted($city)) {
            return knx_api__deny('not_found', 404);
        }

        return knx_api__ok(['city' => $city], 200);
    }
}

if (!function_exists('knx_api_v2_cities_update_city')) {
    function knx_api_v2_cities_update_city(WP_REST_Request $r) {
        global $wpdb;

        $session = knx_api__get_session_safe();
        if (!is_object($session) || !isset($session->role) || !in_array($session->role, ['super_admin', 'manager'], true)) {
            return knx_api__deny('unauthorized', 403);
        }

        $data = $r->get_json_params();
        if (!is_array($data)) {
            $data = json_decode($r->get_body(), true);
        }
        if (!is_array($data)) $data = [];

        $city_id = absint($data['city_id'] ?? $data['id'] ?? 0);
        $name    = sanitize_text_field($data['name'] ?? '');
        $status  = sanitize_text_field($data['status'] ?? 'active');
        $nonce   = sanitize_text_field($data['knx_nonce'] ?? '');

        if (!$city_id || $name === '') {
            return knx_api__deny('missing_fields', 400);
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if (!wp_verify_nonce($nonce, 'knx_edit_city_nonce')) {
            return knx_api__deny('invalid_nonce', 403);
        }

        // Ensure city exists + not soft deleted
        $table_cities = $wpdb->prefix . 'knx_cities';
        $city = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_cities} WHERE id = %d", $city_id));
        if (!$city || knx_api__city_is_soft_deleted($city)) {
            return knx_api__deny('not_found', 404);
        }

        $data_update = [
            'name'   => $name,
            'status' => $status,
        ];
        $formats = ['%s', '%s'];

        // Add updated_at only if column exists
        if (knx_api__table_has_col($table_cities, 'updated_at')) {
            $data_update['updated_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        $updated = $wpdb->update(
            $table_cities,
            $data_update,
            ['id' => $city_id],
            $formats,
            ['%d']
        );

        if ($updated === false) {
            return knx_api__deny('db_error', 500);
        }

        return knx_api__ok([
            'message' => $updated ? '✅ City updated successfully' : 'ℹ️ No changes detected',
        ], 200);
    }
}
