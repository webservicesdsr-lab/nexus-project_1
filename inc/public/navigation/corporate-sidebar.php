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
    /**
     * Return the corporate sidebar HTML. By default it obeys the existing
     * private_pages + role checks. If $force === true the caller is
     * requesting a forced render and must itself ensure the caller has
     * permission (caller-side server check required).
     *
     * This function sets a request-scoped global flag so the sidebar
     * is never printed twice (manual render + wp_footer hook).
     *
     * @param bool $force
     * @return string
     */
    function knx_get_corporate_sidebar_html($force = false) {
        // request-scope guard
        if (!isset($GLOBALS['knx_corporate_sidebar_rendered'])) {
            $GLOBALS['knx_corporate_sidebar_rendered'] = false;
        }
        if ($GLOBALS['knx_corporate_sidebar_rendered']) {
            return '';
        }

        global $post;

        $context = knx_get_navigation_context();
        $slug = isset($context['current_slug']) ? $context['current_slug'] : '';
        
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
            'knx-edit-city',
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
        $has_access = isset($context['role']) && in_array($context['role'], $admin_roles, true);

        // Fail-closed: Only render on private pages with admin access, unless forced by caller
        if (!$force) {
            if (!in_array($slug, $private_pages, true) || !$has_access) {
                return '';
            }
        } else {
            // when forced, still prefer to be conservative: if no role info, do not render
            if (empty($context['role']) && !$force) {
                return '';
            }
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
            return '';
        }

        // Capture markup into a string and return
        ob_start();

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
        $html = ob_get_clean();

        // Mark as rendered for this request to avoid duplicate output
        $GLOBALS['knx_corporate_sidebar_rendered'] = true;

        return $html;
    }

    /**
     * Backwards-compatible render function used by the footer hook.
     * It simply echoes the HTML produced by knx_get_corporate_sidebar_html().
     */
    function knx_render_corporate_sidebar() {
        echo knx_get_corporate_sidebar_html(false);
    }
}

// Render in footer (after body content)
add_action('wp_footer', 'knx_render_corporate_sidebar');

/**
 * Determine whether the corporate sidebar should be rendered on this request.
 * This mirrors the logic used by knx_get_corporate_sidebar_html but returns
 * a boolean and is safe to call early (for body classes).
 *
 * @return bool
 */
function knx_should_render_corporate_sidebar() {
    $context = knx_get_navigation_context();
    $slug = isset($context['current_slug']) ? $context['current_slug'] : '';

    $private_pages = [
        'dashboard', 'knx-dashboard', 'hubs', 'menus', 'hub-categories', 'drivers-admin',
        'customers', 'knx-cities', 'knx-edit-city', 'settings', 'edit-hub-items',
        'edit-item-categories', 'edit-hub', 'live-orders', 'orders',
    ];

    $admin_roles = ['super_admin', 'manager', 'hub_management', 'menu_uploader'];

    // If context role is set and is admin, and the page is private -> true
    if (!empty($context['role']) && in_array($context['role'], $admin_roles, true) && in_array($slug, $private_pages, true)) {
        return true;
    }

    // Special case: home should show for super_admin (we consider session too)
    if (!empty($context['role']) && $context['role'] === 'super_admin' && ($slug === 'home' || $slug === '' || $slug === null)) {
        return true;
    }

    // As a fallback, check the request-scoped rendered flag (if something forced rendering)
    if (!empty($GLOBALS['knx_corporate_sidebar_rendered'])) {
        return true;
    }

    return false;
}

/**
 * Add a body class when the corporate sidebar will be present. This allows
 * shortcodes and theme layouts to be shifted before rendering, avoiding
 * visual overlap.
 */
add_filter('body_class', function($classes) {
    if (function_exists('knx_should_render_corporate_sidebar') && knx_should_render_corporate_sidebar()) {
        $classes[] = 'knx-has-corporate-sidebar';
    }
    return $classes;
});
