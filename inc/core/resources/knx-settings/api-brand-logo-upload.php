<?php
// File: inc/core/resources/knx-settings/api-brand-logo-upload.php
if (!defined('ABSPATH')) exit;

/**
 * KNX Branding API — v3 (canonical)
 * --------------------------------------------------------
 * Single endpoint: POST /wp-json/knx/v1/save-branding
 *
 * Targets:
 * - site_logo
 *   - options: knx_site_logo_id, knx_site_logo, knx_site_logo_view
 * - home_center
 *   - options: knx_home_center_image_id, knx_home_center_image, knx_home_center_image_view
 *
 * Accepts multipart/form-data:
 * - target (optional, default: site_logo)
 * - file (optional if view_json present)
 * - view_json (optional, pan+zoom)
 *
 * Behavior:
 * - Saves uploads into uploads/knx-branding/
 * - Registers WP attachment (so deletion is clean)
 * - Deletes previous attachment per target
 * - Saves view_json per target
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

function knx_api_save_branding(WP_REST_Request $r) {

    $target = sanitize_text_field($r->get_param('target'));
    if (!$target) $target = 'site_logo';

    $allowed_targets = ['site_logo', 'home_center'];
    if (!in_array($target, $allowed_targets, true)) {
        return knx_rest_response(false, 'Invalid target.', ['allowed' => $allowed_targets], 400);
    }

    $view_json = $r->get_param('view_json');
    $has_view = is_string($view_json) && strlen($view_json) > 0;

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

    // Validate upload error
    if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
        return knx_rest_response(false, 'Upload error code: ' . intval($file['error']), null, 400);
    }

    // Validate size
    if (intval($file['size']) > 5 * 1024 * 1024) {
        return knx_rest_response(false, 'File too large. Max 5 MB.', null, 400);
    }

    // Validate mime (canonical: jpg/png/webp)
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $real_mime = $finfo ? @finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);

    $mime_to_check = $real_mime ?: (isset($file['type']) ? $file['type'] : '');
    if (!in_array($mime_to_check, $allowed_types, true)) {
        return knx_rest_response(false, 'Invalid file type. Only JPG, PNG, WEBP allowed.', null, 400);
    }

    // Ensure branding folder exists
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $upload = wp_upload_dir();
    $branding_dir = trailingslashit($upload['basedir']) . 'knx-branding';
    if (!file_exists($branding_dir)) {
        wp_mkdir_p($branding_dir);
        @chmod($branding_dir, 0755);
    }

    // Ensure index.html exists
    $index_file = $branding_dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($index_file)) {
        @file_put_contents($index_file, '');
    }

    // Unique safe filename (prefix by target)
    $orig = sanitize_file_name(basename($file['name']));
    $prefixed = ($target === 'home_center') ? ('home-center-' . $orig) : ('site-logo-' . $orig);

    $target_name = wp_unique_filename($branding_dir, $prefixed);
    $target_path = $branding_dir . DIRECTORY_SEPARATOR . $target_name;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        return knx_rest_response(false, 'Failed to save uploaded file.', null, 500);
    }
    @chmod($target_path, 0644);

    $file_url = trailingslashit($upload['baseurl']) . 'knx-branding/' . $target_name;

    // Create WP attachment
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

    // Delete previous attachment per target (attachment + file)
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

    // If no view provided, return stored view (or defaults)
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