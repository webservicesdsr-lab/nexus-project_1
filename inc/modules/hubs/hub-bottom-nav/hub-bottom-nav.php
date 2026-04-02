<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX HUB MANAGEMENT — Bottom Navbar (CANON)
 * Auto-injected globally for hub_management role (mobile app style).
 *
 * Links (4 screens):
 * - /hub-dashboard      → Dashboard
 * - /hub-items          → My Products
 * - /hub-settings       → Settings
 * - /hub-active-orders  → Active Orders (future-safe, links to hub-dashboard for now)
 *
 * Notes:
 * - hub_management only (auto-detects via session).
 * - Inline CSS/JS injected in wp_footer.
 * - Appears on ALL pages when hub_management is logged in.
 * - Mirrors driver-bottom-nav pattern 1:1.
 * ==========================================================
 */

add_action('wp_footer', function () {
    if (!function_exists('knx_get_session')) return;

    $session = knx_get_session();
    if (!$session || (string)($session->role ?? '') !== 'hub_management') return;

    // Resolve hub_id for query params
    $hub_id = 0;
    if (function_exists('knx_get_managed_hub_ids')) {
        $ids = knx_get_managed_hub_ids((int) $session->user_id);
        if (!empty($ids)) $hub_id = $ids[0];
    }

    $dashboard_url = site_url('/hub-dashboard');
    $items_url     = site_url('/hub-items' . ($hub_id ? '?hub_id=' . $hub_id : ''));
    $settings_url  = site_url('/hub-settings' . ($hub_id ? '?hub_id=' . $hub_id : ''));
    $orders_url    = site_url('/hub-orders');

    $css = '';
    $js  = '';

    $css_path = __DIR__ . '/hub-bottom-nav-style.css';
    if (file_exists($css_path)) {
        $css = (string) file_get_contents($css_path);
    }

    $js_path = __DIR__ . '/hub-bottom-nav.js';
    if (file_exists($js_path)) {
        $js = (string) file_get_contents($js_path);
    }

    ?>
    <?php if ($css !== ''): ?>
        <style data-knx="hub-bottom-nav-style"><?php echo $css; ?></style>
    <?php endif; ?>

    <script>
        document.body.classList.add('knx-has-hub-nav');
    </script>

    <nav class="knx-hbn" data-knx-hub-bottom-nav
         data-dashboard-url="<?php echo esc_attr($dashboard_url); ?>"
         data-items-url="<?php echo esc_attr($items_url); ?>"
         data-settings-url="<?php echo esc_attr($settings_url); ?>"
         data-orders-url="<?php echo esc_attr($orders_url); ?>"
         aria-label="Hub management navigation">
        <a class="knx-hbn__item" data-nav="dashboard" href="<?php echo esc_url($dashboard_url); ?>">
            <span class="knx-hbn__icon"><i class="fas fa-chart-line"></i></span>
            <span class="knx-hbn__label">Dashboard</span>
        </a>
        <a class="knx-hbn__item" data-nav="items" href="<?php echo esc_url($items_url); ?>">
            <span class="knx-hbn__icon"><i class="fas fa-utensils"></i></span>
            <span class="knx-hbn__label">My Products</span>
        </a>
        <a class="knx-hbn__item" data-nav="settings" href="<?php echo esc_url($settings_url); ?>">
            <span class="knx-hbn__icon"><i class="fas fa-cog"></i></span>
            <span class="knx-hbn__label">Settings</span>
        </a>
        <a class="knx-hbn__item" data-nav="orders" href="<?php echo esc_url($orders_url); ?>">
            <span class="knx-hbn__icon"><i class="fas fa-receipt"></i></span>
            <span class="knx-hbn__label">Orders</span>
        </a>
    </nav>

    <?php if ($js !== ''): ?>
        <script data-knx="hub-bottom-nav-js"><?php echo $js; ?></script>
    <?php endif; ?>
    <?php
}, 999);
