<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — CORPORATE SIDEBAR (Private Pages)
 * ════════════════════════════════════════════════════════════════
 * 
 * PURPOSE:
 * - Fixed left sidebar for private admin/staff pages
 * - Role-based menu filtering (navigation-engine.php)
 * - Desktop body push (margin-left: 230px)
 * - Mobile collapsible (58px → 200px)
 * - NO wp_enqueue (Nexus principles)
 * 
 * SCOPE:
 * - /dashboard, /hubs, /menus, /settings, etc.
 * - Admin roles: super_admin, manager, hub_management, menu_uploader
 * 
 * NOT FOR:
 * - Public pages (use navbar.php)
 * - Customer pages (future Phase 4/5)
 * 
 * [KNX-NAV-CORPORATE]
 */

if (!function_exists('knx_render_corporate_sidebar')) {
    function knx_render_corporate_sidebar() {
        global $post;
        
        $context = knx_get_navigation_context();
        $slug = $context['current_slug'];
        
        // Private pages only
        $private_pages = [
            'dashboard',
            'knx-dashboard',
            'hubs',
            'menus',
            'hub-categories',
            'drivers-admin',
            'customers',
            'knx-cities',
            'settings',
            'edit-hub-items',
            'edit-item-categories',
            'edit-hub',
            // OPS pages
            'live-orders',
            'orders',
        ];
        
        // Role check (admin roles only)
        $admin_roles = ['super_admin', 'manager', 'hub_management', 'menu_uploader'];
        $has_access = in_array($context['role'], $admin_roles, true);
        
        // Fail-closed: Only render on private pages with admin access
        if (!in_array($slug, $private_pages, true) || !$has_access) {
            return;
        }
        
        // Get role-filtered nav items
        $admin_items = knx_get_nav_items('admin');
        $filtered_items = [];
        foreach ($admin_items as $item) {
            if (knx_can_render_nav_item($item, $context)) {
                $filtered_items[] = $item;
            }
        }

        // If super_admin, override with canonical corporate tabs in desired order
        if ($context['role'] === 'super_admin') {
            $filtered_items = [
                ['id' => 'dashboard', 'label' => 'Dashboard', 'route' => '/knx-dashboard', 'icon' => 'tachometer-alt', 'roles' => ['super_admin'], 'active_slugs' => ['knx-dashboard', 'dashboard']],
                ['id' => 'live-orders', 'label' => 'Live Orders', 'route' => '/live-orders', 'icon' => 'bolt', 'roles' => ['super_admin'], 'active_slugs' => ['live-orders']],
                ['id' => 'orders', 'label' => 'Orders', 'route' => '/orders', 'icon' => 'receipt', 'roles' => ['super_admin'], 'active_slugs' => ['orders']],
                ['id' => 'hubs', 'label' => 'Hubs', 'route' => '/hubs', 'icon' => 'store', 'roles' => ['super_admin'], 'active_slugs' => ['hubs']],
                ['id' => 'cities', 'label' => 'Cities', 'route' => '/knx-cities', 'icon' => 'city', 'roles' => ['super_admin'], 'active_slugs' => ['knx-cities']],
                ['id' => 'customers', 'label' => 'Customers', 'route' => '/customers', 'icon' => 'users', 'roles' => ['super_admin'], 'active_slugs' => ['customers']],
                ['id' => 'drivers', 'label' => 'Drivers', 'route' => '/drivers', 'icon' => 'truck', 'roles' => ['super_admin'], 'active_slugs' => ['drivers']],
                ['id' => 'coupons', 'label' => 'Coupons', 'route' => '/coupons', 'icon' => 'tags', 'roles' => ['super_admin'], 'active_slugs' => ['coupons']],
                ['id' => 'settings', 'label' => 'Settings', 'route' => '/settings', 'icon' => 'cog', 'roles' => ['super_admin'], 'active_slugs' => ['settings']],
            ];
        }
        
        // No items = no sidebar
        if (empty($filtered_items)) {
            return;
        }
        
        // Load assets (echo, not enqueue)
        echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/navigation/corporate-sidebar-style.css?v=' . KNX_VERSION) . '">';
        echo '<script src="' . esc_url(KNX_URL . 'inc/public/navigation/corporate-sidebar-script.js?v=' . KNX_VERSION) . '" defer></script>';
        ?>

        <!-- CORPORATE SIDEBAR (Fixed Left) -->
        <aside class="knx-corporate-sidebar" id="knxCorporateSidebar">
            <!-- Header -->
            <div class="knx-corporate-sidebar__header">
                <button id="knxExpandMobile" class="knx-expand-btn" aria-label="Toggle Sidebar">
                    <i class="fas fa-angles-right"></i>
                </button>
                <a href="<?php echo esc_url(site_url('/dashboard')); ?>" class="knx-corporate-sidebar__logo" title="Dashboard">
                    🍃
                </a>
            </div>

            <!-- Scrollable Menu -->
            <div class="knx-corporate-sidebar__scroll">
                <nav class="knx-corporate-sidebar__menu">
                    <?php foreach ($filtered_items as $item): 
                        $is_active = knx_is_nav_item_active($item, $context);
                        $active_class = $is_active ? ' knx-corporate-sidebar__link--active' : '';
                    ?>
                    <a href="<?php echo esc_url(site_url($item['route'])); ?>" 
                       class="knx-corporate-sidebar__link<?php echo $active_class; ?>">
                        <i class="fas fa-<?php echo esc_attr($item['icon']); ?>"></i>
                        <span><?php echo esc_html($item['label']); ?></span>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </aside>

        <?php
    }
}

// Render in footer (after body content)
add_action('wp_footer', 'knx_render_corporate_sidebar');
