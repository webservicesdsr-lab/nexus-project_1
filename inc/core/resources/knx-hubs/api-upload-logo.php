<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Upload Hub Logo API (v1.1 - Canonical)
 * ----------------------------------------------------------
 * Securely uploads, resizes and compresses a hub logo
 * ✅ Crops image to 590x400px (no distortion)
 * ✅ Compresses if over 300KB (max quality ~85%)
 * ✅ Deletes old logos before saving
 * ✅ Updates DB with final URL
 * Route: POST /wp-json/knx/v1/upload-logo
 * ==========================================================
 */

add_action('rest_api_init', function () {
    register_rest_route('knx/v1', '/upload-logo', [
        'methods'  => 'POST',
        'callback' => knx_rest_wrap('knx_api_upload_logo'),
        'permission_callback' => knx_rest_permission_roles(['super_admin', 'manager', 'hub_management']),
    ]);
});

function knx_api_upload_logo(WP_REST_Request $r) {
    global $wpdb;

    /** Validate nonce */
    $nonce = sanitize_text_field($r->get_param('knx_nonce'));
    if (empty($nonce) || !wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) {
        return knx_rest_response(false, 'invalid_nonce', null, 403);
    }

    /** Validate hub ID */
    $hub_id = intval($r->get_param('hub_id'));
    if (!$hub_id) {
        return knx_rest_response(false, 'missing_hub_id', null, 400);
    }

    /** Validate file */
    if (empty($_FILES['file'])) {
        return knx_rest_response(false, 'missing_file', null, 400);
    }

    $file = $_FILES['file'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowed_types)) {
        return knx_rest_response(false, 'invalid_type', null, 400);
    }

    if ($file['size'] > 5 * 1024 * 1024) { // absolute limit 5MB
        return knx_rest_response(false, 'file_too_large', null, 400);
    }

    /** Prepare upload dirs */
    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit($upload_dir['basedir']) . 'knx-uploads/';
    $hub_dir    = $base_dir . $hub_id . '/';

    if (!file_exists($hub_dir)) {
        wp_mkdir_p($hub_dir);
    }
    if (!file_exists($hub_dir . 'index.html')) {
        file_put_contents($hub_dir . 'index.html', '');
    }

    /** Delete old logos */
    foreach (glob($hub_dir . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $old_file) {
        @unlink($old_file);
    }

    /** Generate new file name */
    $timestamp = date('Ymd-His');
    $random    = substr(md5(uniqid(mt_rand(), true)), 0, 5);
    $filename  = "{$timestamp}-{$random}.jpg";
    $target    = $hub_dir . $filename;

    require_once(ABSPATH . 'wp-admin/includes/image.php');

    /** Resize + crop */
    $image = wp_get_image_editor($file['tmp_name']);
    if (is_wp_error($image)) {
        return knx_rest_response(false, 'image_editor_error', null, 500);
    }

    // Resize maintaining aspect ratio first
    $image->resize(590, 400, true);

    // Save with initial quality
    $saved = $image->save($target, 'image/jpeg');
    if (is_wp_error($saved)) {
        return knx_rest_response(false, 'save_failed', null, 500);
    }

    // Check final size and recompress if > 300KB
    $final_size = filesize($target);
    $max_bytes  = 300 * 1024; // 300KB

    if ($final_size > $max_bytes) {
        // Adjust quality dynamically
        $quality = max(60, 85 - floor(($final_size - $max_bytes) / 10240)); 
        $quality = min($quality, 85);

        $image = wp_get_image_editor($target);
        if (!is_wp_error($image)) {
            $image->set_quality($quality);
            $image->save($target, 'image/jpeg');
        }
    }

    /** Build final URL */
    $file_url = trailingslashit($upload_dir['baseurl']) . "knx-uploads/{$hub_id}/" . $filename;

    /** Update DB */
    $table = $wpdb->prefix . 'knx_hubs';
    $wpdb->update(
        $table,
        ['logo_url' => esc_url_raw($file_url)],
        ['id' => $hub_id],
        ['%s'],
        ['%d']
    );

    return knx_rest_response(true, 'Logo uploaded and optimized successfully', [
        'url' => $file_url
    ], 200);
}
