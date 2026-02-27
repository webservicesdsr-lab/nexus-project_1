<?php
if (!defined('ABSPATH')) exit;

/**
 * KNX Branding API — v2 (canonical)
 * --------------------------------------------------------
 * Single endpoint: POST /wp-json/knx/v1/save-branding
 * Accepts multipart file upload, saves to uploads/knx-branding/,
 * deletes previous logo, registers WP attachment, updates options.
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
    if (empty($_FILES['file'])) {
        return knx_rest_response(false, 'No file received.', null, 400);
    }

    $file = $_FILES['file'];

    // Validate upload error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return knx_rest_response(false, 'Upload error code: ' . intval($file['error']), null, 400);
    }

    // Validate size
    if ($file['size'] > 5 * 1024 * 1024) {
        return knx_rest_response(false, 'File too large. Max 5 MB.', null, 400);
    }

    // Validate mime
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $real_mime = $finfo ? @finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    // Fallback: check reported type if finfo unavailable
    $mime_to_check = $real_mime ?: $file['type'];
    if (!in_array($mime_to_check, $allowed_types, true)) {
        return knx_rest_response(false, 'Invalid file type. Only JPG, PNG, WEBP, SVG allowed.', null, 400);
    }

    // Ensure branding folder exists
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $upload      = wp_upload_dir();
    $branding_dir = trailingslashit($upload['basedir']) . 'knx-branding';
    if (!file_exists($branding_dir)) {
        wp_mkdir_p($branding_dir);
        @chmod($branding_dir, 0755);
    }

    // Unique safe filename
    $orig        = sanitize_file_name(basename($file['name']));
    $target_name = wp_unique_filename($branding_dir, $orig);
    $target_path = $branding_dir . DIRECTORY_SEPARATOR . $target_name;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        return knx_rest_response(false, 'Failed to save uploaded file.', null, 500);
    }
    @chmod($target_path, 0644);

    // Build public URL
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

    // Delete previous logo (attachment + file)
    $old_id = (int) get_option('knx_site_logo_id', 0);
    if ($old_id && $old_id !== $attach_id) {
        wp_delete_attachment($old_id, true); // true = delete file too
    }

    // Update options
    update_option('knx_site_logo_id', $attach_id);
    update_option('knx_site_logo', esc_url_raw($file_url));

    return knx_rest_response(true, 'Logo saved successfully.', [
        'url' => $file_url,
        'id'  => $attach_id,
    ], 200);
}

// ---- DEAD CODE BELOW — old two-step functions removed ----
// (upload-branding-temp / register-branding are legacy stubs above)

if (!function_exists('knx_DEAD_api_upload_branding_temp')) {
    // placeholder so old includes don't break
    function knx_DEAD_api_upload_branding_temp(WP_REST_Request $r) {
        return knx_rest_response(false, 'deprecated', null, 410);
    }
}
