<?php
/**
 * KNX — Settings shortcode (site branding)
 * Shortcode: [knx_settings]
 *
 * Allows administrators to set the site logo (stores URL in option 'knx_site_logo').
 */

if (!defined('ABSPATH')) exit;

function knx_settings_shortcode() {
    // Enqueue assets (use KNX_URL + KNX_VERSION)
    wp_enqueue_style('knx-settings', KNX_URL . 'inc/modules/settings/settings.css', [], KNX_VERSION);
    wp_enqueue_script('knx-settings', KNX_URL . 'inc/modules/settings/settings.js', ['jquery'], KNX_VERSION, true);

    // WP media required for media frame
    wp_enqueue_media();

    // Localize some values (if needed for AJAX later)
    wp_localize_script('knx-settings', 'knxSettings', [
        'nonce' => wp_create_nonce('knx_settings_save_nonce'),
    ]);

    $message = '';
    // Handle POST save (only admins)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['knx_settings_save'])) {
        if (! isset($_POST['knx_settings_nonce']) || ! wp_verify_nonce($_POST['knx_settings_nonce'], 'knx_settings_save')) {
            $message = '<div class="knx-settings-error">Invalid request (nonce).</div>';
        } elseif (! current_user_can('manage_options')) {
            $message = '<div class="knx-settings-error">Permission denied.</div>';
        } else {
            $logo = isset($_POST['knx_site_logo']) ? esc_url_raw(trim((string) $_POST['knx_site_logo'])) : '';
            update_option('knx_site_logo', $logo);
            $message = '<div class="knx-settings-success">Settings saved.</div>';
        }
    }

    $current_logo = get_option('knx_site_logo', '');

    ob_start();
    echo '<div class="knx-settings-wrap">';
    echo $message;

    if (! current_user_can('manage_options')) {
        echo '<p class="knx-settings-note">You must be an administrator to change branding settings. Please <a href="' . esc_url(wp_login_url()) . '">log in</a>.</p>';
    }

    echo '<form method="post" class="knx-settings-form" action="' . esc_url( $_SERVER['REQUEST_URI'] ) . '">';
    wp_nonce_field('knx_settings_save', 'knx_settings_nonce');
    echo '<input type="hidden" name="knx_settings_save" value="1" />';

    echo '<div class="knx-settings-field">';
    echo '<label for="knx_site_logo">Site logo</label>';
    if ($current_logo) {
        echo '<div class="knx-settings-logo-preview"><img src="' . esc_url($current_logo) . '" alt="Logo preview"></div>';
    }
    echo '<div class="knx-settings-controls">';
    echo '<input type="text" id="knx_site_logo" name="knx_site_logo" value="' . esc_attr($current_logo) . '" placeholder="Logo URL or choose from Media Library" />';
    echo '<button type="button" class="knx-btn knx-upload-logo">Choose / Upload</button>';
    echo '<button type="submit" class="knx-btn knx-save-settings">Save</button>';
    echo '</div>'; // controls
    echo '</div>'; // field

    echo '</form>';
    echo '</div>';

    return ob_get_clean();
}

add_shortcode('knx_settings', 'knx_settings_shortcode');
