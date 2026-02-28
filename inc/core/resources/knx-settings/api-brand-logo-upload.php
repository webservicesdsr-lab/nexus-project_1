<?php
// File: inc/core/resources/knx-settings/api-brand-logo-upload.php
if (!defined('ABSPATH')) exit;

/**
 * KNX Branding API — v4.1 (canonical)
 * --------------------------------------------------------
 * Single endpoint: POST /wp-json/knx/v1/save-branding
 *
 * Targets:
 * - site_logo
 *   - options: knx_site_logo_id, knx_site_logo, knx_site_logo_view
 * - home_center
 *   - options: knx_home_center_image_id, knx_home_center_image, knx_home_center_image_view
 * - home_copy
 *   - option: knx_home_headline_text
 * - city_grid_theme (GLOBAL SSOT DB)
 *   - table: {prefix}knx_city_branding (singleton id=1)
 *
 * Accepts multipart/form-data:
 * - target (optional, default: site_logo)
 * - file (optional if view_json present)
 * - view_json (optional, pan+zoom)
 * - home_headline (home_copy)
 * - theme_json (city_grid_theme)
 *
 * Role: super_admin only.
 * --------------------------------------------------------
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/save-branding', [
        'methods'             => 'POST',
        'callback'            => 'knx_api_save_branding',
        'permission_callback' => function () {
            return function_exists('knx_require_role')
                ? knx_require_role('super_admin')
                : current_user_can('manage_options');
        },
    ]);

    // Legacy stubs — kept so old JS that may still exist doesn't hard-error on REST
    register_rest_route('knx/v1', '/upload-branding-temp', [
        'methods'             => 'POST',
        'callback'            => function() { return knx_rest_response(false, 'deprecated_use_save_branding', null, 410); },
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('knx/v1', '/register-branding', [
        'methods'             => 'POST',
        'callback'            => function() { return knx_rest_response(false, 'deprecated_use_save_branding', null, 410); },
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Ensure the global singleton row exists.
 */
function knx_city_branding_db_ensure_singleton() {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_city_branding';

    // If table is missing, fail-closed but don't fatal.
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) return false;

    $has = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE id = 1");
    if ($has > 0) return true;

    $wpdb->insert($table, ['id' => 1], ['%d']);
    return true;
}

/**
 * Read global theme from DB (returns normalized theme structure).
 */
function knx_city_branding_db_get_theme() {
    global $wpdb;
    $table = $wpdb->prefix . 'knx_city_branding';

    $defaults = [
        'gradient' => ['from' => '#FF7A00', 'to' => '#FFB100', 'angle' => 180],
        'card' => ['radius' => 18, 'minHeight' => 240, 'paddingY' => 35, 'paddingX' => 20, 'shadow' => true],
        'title' => [
            'fontFamily' => 'system',
            'fontWeight' => 800,
            'fontSize' => 20,
            'lineHeight' => 1.00,
            'letterSpacing' => 1.00,
            'fill' => '#FFFFFF',
            'strokeColor' => '#083B58',
            'strokeWidth' => 0
        ],
        'cta' => [
            'text' => 'Tap to EXPLORE HUBS',
            'twoLines' => false,
            'bg' => '#083B58',
            'textColor' => '#FFFFFF',
            'radius' => 999,
            'borderDotted' => false,
            'borderColor' => '#FFFFFF',
            'borderWidth' => 2,
            'paddingY' => 14,
            'paddingX' => 26,
            'fontSize' => 18,
            'fontWeight' => 800
        ],
    ];

    $row = $wpdb->get_row("SELECT * FROM {$table} WHERE id = 1 LIMIT 1", ARRAY_A);
    if (!is_array($row)) return $defaults;

    $hex = function($v, $fallback){
        $s = is_string($v) ? strtoupper(trim($v)) : '';
        if ($s === '') return $fallback;
        if ($s[0] !== '#') $s = '#' . ltrim($s, '#');
        $s = substr($s, 0, 7);
        return preg_match('/^#[0-9A-F]{6}$/', $s) ? $s : $fallback;
    };
    $int = function($v, $min, $max, $fallback){
        $n = is_numeric($v) ? (int)$v : (int)$fallback;
        return max($min, min($max, $n));
    };
    $float = function($v, $min, $max, $fallback){
        $n = is_numeric($v) ? (float)$v : (float)$fallback;
        return max($min, min($max, $n));
    };
    $bool = function($v, $fallback){
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;
        if (is_string($v)) {
            $x = strtolower(trim($v));
            if (in_array($x, ['1','true','yes','on'], true)) return true;
            if (in_array($x, ['0','false','no','off'], true)) return false;
        }
        return (bool)$fallback;
    };

    $t = $defaults;

    $t['gradient']['from']  = $hex($row['gradient_from'] ?? null, $defaults['gradient']['from']);
    $t['gradient']['to']    = $hex($row['gradient_to'] ?? null, $defaults['gradient']['to']);
    $t['gradient']['angle'] = $int($row['gradient_angle'] ?? null, 0, 360, $defaults['gradient']['angle']);

    $t['title']['fontSize']      = $int($row['title_font_size'] ?? null, 12, 52, $defaults['title']['fontSize']);
    $t['title']['fill']          = $hex($row['title_fill_color'] ?? null, $defaults['title']['fill']);
    $t['title']['strokeColor']   = $hex($row['title_stroke_color'] ?? null, $defaults['title']['strokeColor']);
    $t['title']['strokeWidth']   = $int($row['title_stroke_width'] ?? null, 0, 14, $defaults['title']['strokeWidth']);
    $t['title']['fontWeight']    = $int($row['title_font_weight'] ?? null, 400, 950, $defaults['title']['fontWeight']);
    $t['title']['lineHeight']    = $float($row['title_line_height'] ?? null, 0.8, 1.6, $defaults['title']['lineHeight']);
    $t['title']['letterSpacing'] = $float($row['title_letter_spacing'] ?? null, -10.0, 10.0, $defaults['title']['letterSpacing']);

    $t['cta']['bg']          = $hex($row['cta_bg'] ?? null, $defaults['cta']['bg']);
    $t['cta']['textColor']   = $hex($row['cta_text_color'] ?? null, $defaults['cta']['textColor']);
    $t['cta']['radius']      = $int($row['cta_radius'] ?? null, 0, 999, $defaults['cta']['radius']);
    $t['cta']['borderColor'] = $hex($row['cta_border_color'] ?? null, $defaults['cta']['borderColor']);
    $t['cta']['borderWidth'] = $int($row['cta_border_width'] ?? null, 0, 16, $defaults['cta']['borderWidth']);
    $t['cta']['borderDotted']= $bool($row['cta_border_dotted'] ?? null, $defaults['cta']['borderDotted']);
    $t['cta']['twoLines']    = $bool($row['cta_two_lines'] ?? null, $defaults['cta']['twoLines']);

    $t['card']['radius']    = $int($row['card_radius'] ?? null, 0, 64, $defaults['card']['radius']);
    $t['card']['paddingY']  = $int($row['card_padding_y'] ?? null, 0, 90, $defaults['card']['paddingY']);
    $t['card']['paddingX']  = $int($row['card_padding_x'] ?? null, 0, 90, $defaults['card']['paddingX']);
    $t['card']['minHeight'] = $int($row['card_min_height'] ?? null, 120, 900, $defaults['card']['minHeight']);
    $t['card']['shadow']    = $bool($row['card_shadow'] ?? null, $defaults['card']['shadow']);

    return $t;
}

/**
 * Normalize theme JSON from UI into a safe structure + DB row payload.
 */
function knx_city_branding_normalize_theme($decoded) {

    $defaults = [
        'gradient' => ['from' => '#FF7A00', 'to' => '#FFB100', 'angle' => 180],
        'card' => ['radius' => 18, 'minHeight' => 240, 'paddingY' => 35, 'paddingX' => 20, 'shadow' => true],
        'title' => [
            'fontFamily' => 'system',
            'fontWeight' => 800,
            'fontSize' => 20,
            'lineHeight' => 1.00,
            'letterSpacing' => 1.00,
            'fill' => '#FFFFFF',
            'strokeColor' => '#083B58',
            'strokeWidth' => 0
        ],
        'cta' => [
            'text' => 'Tap to EXPLORE HUBS',
            'twoLines' => false,
            'bg' => '#083B58',
            'textColor' => '#FFFFFF',
            'radius' => 999,
            'borderDotted' => false,
            'borderColor' => '#FFFFFF',
            'borderWidth' => 2,
            'paddingY' => 14,
            'paddingX' => 26,
            'fontSize' => 18,
            'fontWeight' => 800
        ],
    ];

    $hex = function($v, $fallback){
        $s = is_string($v) ? strtoupper(trim($v)) : '';
        if ($s === '') return $fallback;
        if ($s[0] !== '#') $s = '#' . ltrim($s, '#');
        $s = substr($s, 0, 7);
        return preg_match('/^#[0-9A-F]{6}$/', $s) ? $s : $fallback;
    };
    $int = function($v, $min, $max, $fallback){
        $n = is_numeric($v) ? (int)$v : (int)$fallback;
        return max($min, min($max, $n));
    };
    $float = function($v, $min, $max, $fallback){
        $n = is_numeric($v) ? (float)$v : (float)$fallback;
        return max($min, min($max, $n));
    };
    $bool = function($v, $fallback){
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;
        if (is_string($v)) {
            $x = strtolower(trim($v));
            if (in_array($x, ['1','true','yes','on'], true)) return true;
            if (in_array($x, ['0','false','no','off'], true)) return false;
        }
        return (bool)$fallback;
    };

    $g = is_array($decoded['gradient'] ?? null) ? $decoded['gradient'] : [];
    $c = is_array($decoded['card'] ?? null) ? $decoded['card'] : [];
    $t = is_array($decoded['title'] ?? null) ? $decoded['title'] : [];
    $a = is_array($decoded['cta'] ?? null) ? $decoded['cta'] : [];

    $norm = $defaults;

    $norm['gradient']['from']  = $hex($g['from'] ?? null, $defaults['gradient']['from']);
    $norm['gradient']['to']    = $hex($g['to'] ?? null, $defaults['gradient']['to']);
    $norm['gradient']['angle'] = $int($g['angle'] ?? null, 0, 360, $defaults['gradient']['angle']);

    $norm['card']['radius']    = $int($c['radius'] ?? null, 0, 64, $defaults['card']['radius']);
    $norm['card']['minHeight'] = $int($c['minHeight'] ?? null, 120, 900, $defaults['card']['minHeight']);
    $norm['card']['paddingY']  = $int($c['paddingY'] ?? null, 0, 90, $defaults['card']['paddingY']);
    $norm['card']['paddingX']  = $int($c['paddingX'] ?? null, 0, 90, $defaults['card']['paddingX']);
    $norm['card']['shadow']    = $bool($c['shadow'] ?? null, $defaults['card']['shadow']);

    $norm['title']['fontFamily']    = isset($t['fontFamily']) ? sanitize_text_field($t['fontFamily']) : $defaults['title']['fontFamily'];
    $norm['title']['fontWeight']    = $int($t['fontWeight'] ?? null, 400, 950, $defaults['title']['fontWeight']);
    $norm['title']['fontSize']      = $int($t['fontSize'] ?? null, 12, 52, $defaults['title']['fontSize']);
    $norm['title']['lineHeight']    = $float($t['lineHeight'] ?? null, 0.8, 1.6, $defaults['title']['lineHeight']);
    $norm['title']['letterSpacing'] = $float($t['letterSpacing'] ?? null, -10.0, 10.0, $defaults['title']['letterSpacing']);
    $norm['title']['fill']          = $hex($t['fill'] ?? null, $defaults['title']['fill']);
    $norm['title']['strokeColor']   = $hex($t['strokeColor'] ?? null, $defaults['title']['strokeColor']);
    $norm['title']['strokeWidth']   = $int($t['strokeWidth'] ?? null, 0, 14, $defaults['title']['strokeWidth']);

    $norm['cta']['text']        = isset($a['text']) ? sanitize_text_field($a['text']) : $defaults['cta']['text'];
    $norm['cta']['twoLines']    = $bool($a['twoLines'] ?? null, $defaults['cta']['twoLines']);
    $norm['cta']['bg']          = $hex($a['bg'] ?? null, $defaults['cta']['bg']);
    $norm['cta']['textColor']   = $hex($a['textColor'] ?? null, $defaults['cta']['textColor']);
    $norm['cta']['radius']      = $int($a['radius'] ?? null, 0, 999, $defaults['cta']['radius']);
    $norm['cta']['borderDotted']= $bool($a['borderDotted'] ?? null, $defaults['cta']['borderDotted']);
    $norm['cta']['borderColor'] = $hex($a['borderColor'] ?? null, $defaults['cta']['borderColor']);
    $norm['cta']['borderWidth'] = $int($a['borderWidth'] ?? null, 0, 16, $defaults['cta']['borderWidth']);

    // Keep UI defaults for these advanced CTA fields (not currently editable in /settings)
    $norm['cta']['paddingY']    = $defaults['cta']['paddingY'];
    $norm['cta']['paddingX']    = $defaults['cta']['paddingX'];
    $norm['cta']['fontSize']    = $defaults['cta']['fontSize'];
    $norm['cta']['fontWeight']  = $defaults['cta']['fontWeight'];

    $row = [
        'id' => 1,
        'schema_version' => 2,

        'gradient_from' => $norm['gradient']['from'],
        'gradient_to' => $norm['gradient']['to'],
        'gradient_angle' => (int)$norm['gradient']['angle'],

        'title_font_size' => (int)$norm['title']['fontSize'],
        'title_fill_color' => $norm['title']['fill'],
        'title_stroke_color' => $norm['title']['strokeColor'],
        'title_stroke_width' => (int)$norm['title']['strokeWidth'],
        'title_font_weight' => (int)$norm['title']['fontWeight'],
        'title_line_height' => (float)$norm['title']['lineHeight'],
        'title_letter_spacing' => (float)$norm['title']['letterSpacing'],

        'cta_bg' => $norm['cta']['bg'],
        'cta_text_color' => $norm['cta']['textColor'],
        'cta_radius' => (int)$norm['cta']['radius'],
        'cta_border_color' => $norm['cta']['borderColor'],
        'cta_border_width' => (int)$norm['cta']['borderWidth'],
        'cta_border_dotted' => $norm['cta']['borderDotted'] ? 1 : 0,
        'cta_two_lines' => $norm['cta']['twoLines'] ? 1 : 0,

        'card_radius' => (int)$norm['card']['radius'],
        'card_padding_y' => (int)$norm['card']['paddingY'],
        'card_padding_x' => (int)$norm['card']['paddingX'],
        'card_min_height' => (int)$norm['card']['minHeight'],
        'card_shadow' => $norm['card']['shadow'] ? 1 : 0,

        'extras_json' => wp_json_encode($norm),
    ];

    return [$norm, $row];
}

function knx_api_save_branding(WP_REST_Request $r) {

    $target = sanitize_text_field($r->get_param('target'));
    if (!$target) $target = 'site_logo';

    $allowed_targets = ['site_logo', 'home_center', 'home_copy', 'city_grid_theme'];
    if (!in_array($target, $allowed_targets, true)) {
        return knx_rest_response(false, 'Invalid target.', ['allowed' => $allowed_targets], 400);
    }

    $view_json = $r->get_param('view_json');
    $has_view = is_string($view_json) && strlen($view_json) > 0;

    // Handle simple home headline save
    if ($target === 'home_copy') {
        $headline = $r->get_param('home_headline');
        if (!is_string($headline)) $headline = '';
        $headline = trim(sanitize_text_field($headline));
        $max = 160;
        if (mb_strlen($headline) > $max) $headline = mb_substr($headline, 0, $max);
        update_option('knx_home_headline_text', $headline);
        return knx_rest_response(true, 'Home headline saved.', ['target' => 'home_copy', 'home_headline' => $headline], 200);
    }

    // Handle global city grid theme save (DB singleton)
    if ($target === 'city_grid_theme') {
        $theme_json = $r->get_param('theme_json');
        if (!is_string($theme_json) || trim($theme_json) === '') {
            return knx_rest_response(false, 'Missing theme_json for city_grid_theme.', null, 400);
        }

        $decoded = json_decode($theme_json, true);
        if (!is_array($decoded)) {
            return knx_rest_response(false, 'Invalid theme_json payload.', null, 400);
        }

        if (!knx_city_branding_db_ensure_singleton()) {
            return knx_rest_response(false, 'City branding table missing or not ready.', null, 500);
        }

        list($norm, $row) = knx_city_branding_normalize_theme($decoded);

        global $wpdb;
        $table = $wpdb->prefix . 'knx_city_branding';

        // Update singleton row (fail-closed: if update fails, return error)
        $updated = $wpdb->update(
            $table,
            $row,
            ['id' => 1],
            [
                '%d', // id
                '%d', // schema_version
                '%s','%s','%d',
                '%d','%s','%s','%d','%d','%f','%f',
                '%s','%s','%d','%s','%d','%d','%d',
                '%d','%d','%d','%d','%d',
                '%s'
            ],
            ['%d']
        );

        if ($updated === false) {
            return knx_rest_response(false, 'Failed to save city grid theme.', ['db_error' => $wpdb->last_error], 500);
        }

        return knx_rest_response(true, 'City grid theme saved.', ['target' => 'city_grid_theme', 'theme' => $norm], 200);
    }

    $has_file = !empty($_FILES['file']) && !empty($_FILES['file']['tmp_name']);

    if (!$has_file && !$has_view) {
        return knx_rest_response(false, 'No file or view provided.', null, 400);
    }

    // ---- Parse + clamp view ----
    $view = null;
    if ($has_view) {
        $decoded = json_decode($view_json, true);
        if (!is_array($decoded)) {
            return knx_rest_response(false, 'Invalid view_json.', null, 400);
        }
        $view = [
            'scale' => isset($decoded['scale']) ? floatval($decoded['scale']) : 1,
            'x'     => isset($decoded['x']) ? floatval($decoded['x']) : 0,
            'y'     => isset($decoded['y']) ? floatval($decoded['y']) : 0,
        ];
        $view['scale'] = max(0.6, min(2.6, $view['scale']));
        $view['x']     = max(-320, min(320, $view['x']));
        $view['y']     = max(-200, min(200, $view['y']));

        if ($target === 'site_logo') {
            update_option('knx_site_logo_view', wp_json_encode($view));
        } else {
            update_option('knx_home_center_image_view', wp_json_encode($view));
        }
    }

    // If view-only save, return early with current url
    if (!$has_file) {
        $url = ($target === 'site_logo')
            ? (string) get_option('knx_site_logo', '')
            : (string) get_option('knx_home_center_image', '');

        return knx_rest_response(true, 'Display saved successfully.', [
            'url'    => $url,
            'id'     => ($target === 'site_logo') ? (int)get_option('knx_site_logo_id', 0) : (int)get_option('knx_home_center_image_id', 0),
            'target' => $target,
            'view'   => $view,
        ], 200);
    }

    $file = $_FILES['file'];

    if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
        return knx_rest_response(false, 'Upload error code: ' . intval($file['error']), null, 400);
    }

    if (intval($file['size']) > 5 * 1024 * 1024) {
        return knx_rest_response(false, 'File too large. Max 5 MB.', null, 400);
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $real_mime = $finfo ? @finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);

    $mime_to_check = $real_mime ?: (isset($file['type']) ? $file['type'] : '');
    if (!in_array($mime_to_check, $allowed_types, true)) {
        return knx_rest_response(false, 'Invalid file type. Only JPG, PNG, WEBP allowed.', null, 400);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $upload = wp_upload_dir();
    $branding_dir = trailingslashit($upload['basedir']) . 'knx-branding';
    if (!file_exists($branding_dir)) {
        wp_mkdir_p($branding_dir);
        @chmod($branding_dir, 0755);
    }

    $index_file = $branding_dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($index_file)) {
        @file_put_contents($index_file, '');
    }

    $orig = sanitize_file_name(basename($file['name']));
    $prefixed = ($target === 'home_center') ? ('home-center-' . $orig) : ('site-logo-' . $orig);

    $target_name = wp_unique_filename($branding_dir, $prefixed);
    $target_path = $branding_dir . DIRECTORY_SEPARATOR . $target_name;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        return knx_rest_response(false, 'Failed to save uploaded file.', null, 500);
    }
    @chmod($target_path, 0644);

    $file_url = trailingslashit($upload['baseurl']) . 'knx-branding/' . $target_name;

    $ft   = wp_check_filetype($target_name);
    $mime = !empty($ft['type']) ? $ft['type'] : $mime_to_check;

    $attach_data = [
        'guid'           => esc_url_raw($file_url),
        'post_mime_type' => $mime,
        'post_title'     => sanitize_text_field(pathinfo($target_name, PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attach_data, $target_path);
    if (is_wp_error($attach_id) || !$attach_id) {
        @unlink($target_path);
        return knx_rest_response(false, 'Could not create attachment.', null, 500);
    }

    $meta = wp_generate_attachment_metadata($attach_id, $target_path);
    if (!empty($meta)) wp_update_attachment_metadata($attach_id, $meta);

    if ($target === 'site_logo') {
        $old_id = (int) get_option('knx_site_logo_id', 0);
        if ($old_id && $old_id !== $attach_id) {
            wp_delete_attachment($old_id, true);
        }
        update_option('knx_site_logo_id', $attach_id);
        update_option('knx_site_logo', esc_url_raw($file_url));
    } else {
        $old_id = (int) get_option('knx_home_center_image_id', 0);
        if ($old_id && $old_id !== $attach_id) {
            wp_delete_attachment($old_id, true);
        }
        update_option('knx_home_center_image_id', $attach_id);
        update_option('knx_home_center_image', esc_url_raw($file_url));
    }

    if (!$view) {
        $raw = ($target === 'site_logo')
            ? get_option('knx_site_logo_view', '')
            : get_option('knx_home_center_image_view', '');
        $d = $raw ? json_decode($raw, true) : null;
        if (is_array($d)) $view = $d;
        else $view = ['scale' => 1, 'x' => 0, 'y' => 0];
    }

    return knx_rest_response(true, 'Saved successfully.', [
        'url'    => $file_url,
        'id'     => $attach_id,
        'target' => $target,
        'view'   => $view,
    ], 200);
}

// Legacy placeholder
if (!function_exists('knx_DEAD_api_upload_branding_temp')) {
    function knx_DEAD_api_upload_branding_temp(WP_REST_Request $r) {
        return knx_rest_response(false, 'deprecated', null, 410);
    }
}