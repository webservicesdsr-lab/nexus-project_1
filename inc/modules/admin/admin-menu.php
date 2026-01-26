<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Admin Menu (v2.5)
 * ---------------------------------
 * Full administrative structure for Nexus dashboard.
 */

add_action('admin_menu', function() {

    add_menu_page(
        'Kingdom Nexus',
        'Kingdom Nexus',
        'manage_options',
        'knx-dashboard',
        'knx_render_admin_dashboard',
        'dashicons-shield-alt',
        3
    );

    add_submenu_page(
        'knx-dashboard',
        'User Management',
        'User Management',
        'manage_options',
        'knx-users',
        function() {
            require_once KNX_PATH . 'inc/modules/admin/admin-users.php';
        }
    );

    add_submenu_page(
        'knx-dashboard',
        'Settings',
        'Settings',
        'manage_options',
        'knx-settings',
        function() {
            require_once KNX_PATH . 'inc/modules/admin/admin-settings.php';
        }
    );
});

/**
 * Enqueue global admin styles and scripts
 */
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'knx') !== false) {
        wp_enqueue_style('knx-admin-style', KNX_URL . 'inc/modules/admin/admin-style.css', [], KNX_VERSION);
        wp_enqueue_script('knx-admin-script', KNX_URL . 'inc/modules/admin/admin-script.js', ['jquery'], KNX_VERSION, true);
    }
});

/**
 * Main Dashboard Page
 */
function knx_render_admin_dashboard() {
    ?>
    <div class="knx-admin-wrap">
        <div class="knx-admin-header">
            <h1><i class="dashicons dashicons-shield-alt"></i> Kingdom Nexus</h1>
            <p class="subtitle">Modular Core Framework for Kingdom Builders</p>
        </div>

        <div class="knx-admin-grid">
            <div class="knx-card">
                <h2><i class="dashicons dashicons-admin-users"></i> User Management</h2>
                <p>Manage internal KNX users, roles, and permissions with security-first logic.</p>
                <a href="?page=knx-users" class="button button-primary">Go to Users</a>
            </div>

            <div class="knx-card">
                <h2><i class="dashicons dashicons-admin-generic"></i> Settings</h2>
                <p>Configure your Nexus system. Manage API keys, global settings, and integrations.</p>
                <a href="?page=knx-settings" class="button">Go to Settings</a>
            </div>
        </div>
    </div>
    <?php
}
