<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Sidebar Render (v5.0 Final)
 * Unified global sidebar for all internal modules and CRUDs.
 * Auto-height + responsive scroll.
 */

function knx_render_sidebar() {
    global $post;

    $session = knx_get_session();
    if (!$session) return;

    $role = $session->role;
    $slug = is_object($post) ? $post->post_name : '';

    $allowed_pages = [
        'dashboard',
        'hubs',
        'menus',
        'hub-categories',
        'drivers',
        'customers',
        'knx-cities',
        'settings',
        'edit-hub-items',
        'edit-item-categories'
    ];

    $allowed_roles = [
        'super_admin',
        'manager',
        'hub_management',
        'menu_uploader'
    ];

    if (!in_array($slug, $allowed_pages, true) || !in_array($role, $allowed_roles, true)) {
        return;
    }

    wp_enqueue_script('knx-sidebar-js', KNX_URL . 'inc/modules/sidebar/sidebar-script.js', [], KNX_VERSION, true);
    wp_enqueue_style('knx-sidebar-css', KNX_URL . 'inc/modules/sidebar/sidebar-style.css', [], KNX_VERSION);
    ?>

    <aside class="knx-sidebar" id="knxSidebar">
        <div class="knx-sidebar-header">
            <button id="knxExpandMobile" class="knx-expand-btn" aria-label="Toggle Sidebar">
                <i class="fas fa-angles-right"></i>
            </button>
            <a href="<?php echo esc_url(site_url('/dashboard')); ?>" class="knx-logo" title="Dashboard">
                <i class="fas fa-home"></i>
            </a>
        </div>

        <div class="knx-sidebar-scroll">
            <ul class="knx-sidebar-menu">
                <li><a href="<?php echo site_url('/dashboard'); ?>"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></li>
                <li><a href="<?php echo site_url('/hubs'); ?>"><i class="fas fa-store"></i><span>Hubs</span></a></li>
                <li><a href="<?php echo site_url('/menus'); ?>"><i class="fas fa-utensils"></i><span>Menus</span></a></li>
                <li><a href="<?php echo site_url('/hub-categories'); ?>"><i class="fas fa-list"></i><span>Hub Categories</span></a></li>
                <li><a href="<?php echo site_url('/drivers'); ?>"><i class="fas fa-car"></i><span>Drivers</span></a></li>
                <li><a href="<?php echo site_url('/customers'); ?>"><i class="fas fa-users"></i><span>Customers</span></a></li>
                <li><a href="<?php echo site_url('/knx-cities'); ?>"><i class="fas fa-city"></i><span>Cities</span></a></li>
                <li><a href="<?php echo site_url('/settings'); ?>"><i class="fas fa-cog"></i><span>Settings</span></a></li>
            </ul>
        </div>
    </aside>

    <?php
}
add_action('wp_footer', 'knx_render_sidebar');
