<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — API: City Branding (SEALED v2 / Resources)
 * ----------------------------------------------------------
 * Endpoints:
 * - GET  /wp-json/knx/v2/cities/get-branding?city_id=
 * - POST /wp-json/knx/v2/cities/update-branding
 *
 * Security:
 * - Session required
 * - Role: super_admin
 * - Nonce: knx_edit_city_nonce (for POST)
 *
 * Response contract:
 * { success, message, data: { city_id, branding } }
 * ==========================================================
 */

add_action('rest_api_init', function () {

    $perm_roles = function (array $roles) {
        if (function_exists('knx_rest_permission_roles')) {
            return knx_rest_permission_roles($roles);
        }

        return function () use ($roles) {
            $s = function_exists('knx_get_session') ? knx_get_session() : null;
            return (is_object($s) && isset($s->role) && in_array($s->role, $roles, true));
        };
    };

    $cb = function (string $handler_fn) {
        return function ($request) use ($handler_fn) {
            if (function_exists('knx_rest_wrap')) {
                return knx_rest_wrap($handler_fn)($request);
            }
            return call_user_func($handler_fn, $request);
        };
    };

    register_rest_route('knx/v2', '/cities/get-branding', [
        'methods'  => 'GET',
        'callback' => $cb('knx_api_v2_cities_get_branding'),
        'permission_callback' => $perm_roles(['super_admin']),
    ]);

    register_rest_route('knx/v2', '/cities/update-branding', [
        'methods'  => 'POST',
        'callback' => $cb('knx_api_v2_cities_update_branding'),
        'permission_callback' => $perm_roles(['super_admin']),
    ]);
});

if (!function_exists('knx_city_branding_table')) {
    function knx_city_branding_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'knx_city_branding';
    }
}

if (!function_exists('knx_city_branding_ensure_table')) {
    function knx_city_branding_ensure_table(): void {
        global $wpdb;
        $t = knx_city_branding_table();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") === $t) return;

        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$t} (
            `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            `city_id` bigint UNSIGNED NOT NULL,
            `gradient_from` varchar(7) NOT NULL DEFAULT '#ff7a00',
            `gradient_to` varchar(7) NOT NULL DEFAULT '#ffb100',
            `gradient_angle` smallint NOT NULL DEFAULT 90,
            `title_font_size` smallint NOT NULL DEFAULT 36,
            `title_stroke_color` varchar(7) NOT NULL DEFAULT '#08324a',
            `title_stroke_width` tinyint NOT NULL DEFAULT 4,
            `cta_bg` varchar(7) NOT NULL DEFAULT '#083b58',
            `cta_text_color` varchar(7) NOT NULL DEFAULT '#ffffff',
            `cta_border_dotted` tinyint NOT NULL DEFAULT 1,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_city_id` (`city_id`),
            KEY `idx_updated_at` (`updated_at`)
        ) ENGINE=InnoDB {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}

if (!function_exists('knx_city_branding_defaults')) {
    function knx_city_branding_defaults(): array {
        return [
            'gradient_from' => '#ff7a00',
            'gradient_to'   => '#ffb100',
            'gradient_angle' => 120,
            'title_font_size' => 48,
            'title_stroke_color' => '#083b58',
            'title_stroke_width' => 4,
            'cta_bg' => '#083b58',
            'cta_text_color' => '#ffffff',
            'cta_border_dotted' => 1,
        ];
    }
}

if (!function_exists('knx_city_branding_load')) {
    function knx_city_branding_load(int $city_id): array {
        global $wpdb;
        knx_city_branding_ensure_table();
        $t = knx_city_branding_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE city_id = %d LIMIT 1", $city_id), ARRAY_A);
        if (!$row) return knx_city_branding_defaults();

        // Normalize types and fill missing keys with defaults
        $defs = knx_city_branding_defaults();
        $out = [];
        foreach ($defs as $k => $v) {
            $out[$k] = isset($row[$k]) && $row[$k] !== null ? $row[$k] : $v;
        }
        return $out;
    }
}

if (!function_exists('knx_api_v2_cities_get_branding')) {
    function knx_api_v2_cities_get_branding(WP_REST_Request $r) {
        global $wpdb;

        $session = (function_exists('knx_rest_get_session') ? knx_rest_get_session() : (function_exists('knx_get_session') ? knx_get_session() : null));
        if (!is_object($session) || !isset($session->role) || $session->role !== 'super_admin') {
            return new WP_REST_Response(['success' => false, 'error' => 'unauthorized'], 403);
        }

        $city_id = absint($r->get_param('city_id') ?: $r->get_param('id'));
        if (!$city_id) return new WP_REST_Response(['success' => false, 'error' => 'missing_id'], 400);

        // Ensure city exists and not soft deleted (best-effort)
        $table_cities = $wpdb->prefix . 'knx_cities';
        $city = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_cities} WHERE id = %d", $city_id));
        if (!$city) return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);

        // Load branding (fail-closed defaults)
        $branding = knx_city_branding_load($city_id);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'city_id' => $city_id,
                'branding' => $branding,
            ]
        ], 200);
    }
}

if (!function_exists('knx_api_v2_cities_update_branding')) {
    function knx_api_v2_cities_update_branding(WP_REST_Request $r) {
        global $wpdb;

        $session = (function_exists('knx_rest_get_session') ? knx_rest_get_session() : (function_exists('knx_get_session') ? knx_get_session() : null));
        if (!is_object($session) || !isset($session->role) || $session->role !== 'super_admin') {
            return new WP_REST_Response(['success' => false, 'error' => 'unauthorized'], 403);
        }

        $data = $r->get_json_params();
        if (!is_array($data)) $data = json_decode($r->get_body(), true) ?: [];

        $city_id = absint($data['city_id'] ?? 0);
        $nonce = sanitize_text_field($data['knx_nonce'] ?? '');

        if (!$city_id) return new WP_REST_Response(['success' => false, 'error' => 'missing_id'], 400);
        if (!wp_verify_nonce($nonce, 'knx_edit_city_nonce')) {
            return new WP_REST_Response(['success' => false, 'error' => 'invalid_nonce'], 403);
        }

        // Ensure city exists
        $table_cities = $wpdb->prefix . 'knx_cities';
        $city = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_cities} WHERE id = %d", $city_id));
        if (!$city) return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);

        // Ensure table
        knx_city_branding_ensure_table();
        $t = knx_city_branding_table();

        $allowed = [
            'gradient_from','gradient_to','gradient_angle','title_font_size','title_stroke_color','title_stroke_width','cta_bg','cta_text_color','cta_border_dotted'
        ];

        $payload = [];
        foreach ($allowed as $k) {
            if (isset($data[$k])) {
                $val = $data[$k];
                // basic sanitization
                if (in_array($k, ['gradient_from','gradient_to','title_stroke_color','cta_bg','cta_text_color'], true)) {
                    $val = sanitize_text_field($val);
                    if (strpos($val, '#') !== 0) $val = '#' . ltrim($val, '#');
                    $val = substr($val, 0, 7);
                }
                if ($k === 'gradient_angle' || $k === 'title_font_size' || $k === 'title_stroke_width') {
                    $val = (int)$val;
                }
                if ($k === 'cta_border_dotted') {
                    $val = (int)!!$val;
                }
                $payload[$k] = $val;
            }
        }

        if (empty($payload)) {
            return new WP_REST_Response(['success' => false, 'error' => 'missing_payload'], 400);
        }

        // Upsert: check exists
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE city_id = %d LIMIT 1", $city_id));

        if ($exists) {
            $formats = array_map(function($v){
                if (is_int($v)) return '%d';
                return '%s';
            }, $payload);

            $updated = $wpdb->update($t, $payload + ['updated_at' => current_time('mysql')], ['city_id' => $city_id], array_merge($formats, ['%s']), ['%d']);
            if ($updated === false) return new WP_REST_Response(['success' => false, 'error' => 'db_error'], 500);
        } else {
            $payload['city_id'] = $city_id;
            $formats = [];
            foreach ($payload as $v) $formats[] = is_int($v) ? '%d' : '%s';
            $inserted = $wpdb->insert($t, $payload, $formats);
            if ($inserted === false) return new WP_REST_Response(['success' => false, 'error' => 'db_error'], 500);
        }

        $branding = knx_city_branding_load($city_id);

        return new WP_REST_Response([
            'success' => true,
            'message' => '✅ Branding saved',
            'data' => [ 'city_id' => $city_id, 'branding' => $branding ]
        ], 200);
    }
}
