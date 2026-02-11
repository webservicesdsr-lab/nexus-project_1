<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX — Driver Bottom Navbar (v1.0)
 * ----------------------------------------------------------
 * - No wp_footer usage; assets injected inline (once).
 * - 4 tabs only (Support later).
 * - Stores last active order_id in localStorage for Active tab.
 * ==========================================================
 */

if (!function_exists('knx_driver_bottom_nav_render')) {

    /**
     * Render bottom navbar (4 tabs).
     *
     * @param array $args
     * @return void
     */
    function knx_driver_bottom_nav_render(array $args = []) {

        static $assets_printed = false;

        $current = isset($args['current']) ? (string)$args['current'] : '';
        if (!in_array($current, ['quick', 'ops', 'live', 'profile'], true)) $current = '';

        // Canonical routes for driver navigation
        $quick_url   = isset($args['quick_url']) ? (string)$args['quick_url'] : '/driver-quick-menu';
        $ops_url     = isset($args['ops_url']) ? (string)$args['ops_url'] : '/driver-ops';
        $live_url    = isset($args['live_url']) ? (string)$args['live_url'] : '/driver-live-orders';
        $profile_url = isset($args['profile_url']) ? (string)$args['profile_url'] : '/driver-profile';

        $last_active_order_id = isset($args['last_active_order_id']) ? (int)$args['last_active_order_id'] : 0;

        // Inject assets once (no enqueue, no footer)
        if (!$assets_printed) {
            $assets_printed = true;

            $css_path = __DIR__ . '/driver-bottom-nav-style.css';
            if (file_exists($css_path)) {
                echo '<style data-knx-driver-bottomnav-style="1.0">' . file_get_contents($css_path) . '</style>';
            }

            $js_path = __DIR__ . '/driver-bottom-nav-script.js';
            if (file_exists($js_path)) {
                echo '<script data-knx-driver-bottomnav-script="1.0">' . file_get_contents($js_path) . '</script>';
            }
        }

        $is_live    = ($current === 'live');
        $is_active  = ($current === 'active');
        $is_past    = ($current === 'past');
        $is_profile = ($current === 'profile');

        ?>
        <nav
            class="knx-driver-bottomnav"
            aria-label="Driver navigation"
            data-current="<?php echo esc_attr($current); ?>"
            data-quick-url="<?php echo esc_attr($quick_url); ?>"
            data-ops-url="<?php echo esc_attr($ops_url); ?>"
            data-live-url="<?php echo esc_attr($live_url); ?>"
            data-profile-url="<?php echo esc_attr($profile_url); ?>"
            data-last-active-order-id="<?php echo esc_attr($last_active_order_id); ?>"
        >
            <a class="knx-driver-bottomnav__item <?php echo ($current === 'quick') ? 'is-active' : ''; ?>"
               href="<?php echo esc_attr($quick_url); ?>"
               <?php echo ($current === 'quick') ? 'aria-current="page"' : ''; ?>
               data-tab="quick"
            >
                <span class="knx-driver-bottomnav__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </span>
                <span class="knx-driver-bottomnav__label">Quick</span>
            </a>

            <a class="knx-driver-bottomnav__item <?php echo ($current === 'ops') ? 'is-active' : ''; ?>"
               href="<?php echo esc_attr($ops_url); ?>"
               <?php echo ($current === 'ops') ? 'aria-current="page"' : ''; ?>
               data-tab="ops"
            >
                <span class="knx-driver-bottomnav__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M12 2v6M5 7h14M7 22h10v-6H7v6z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="knx-driver-bottomnav__label">Driver OPS</span>
            </a>

            <a class="knx-driver-bottomnav__item <?php echo ($current === 'live') ? 'is-active' : ''; ?>"
               href="<?php echo esc_attr($live_url); ?>"
               <?php echo ($current === 'live') ? 'aria-current="page"' : ''; ?>
               data-tab="live"
            >
                <span class="knx-driver-bottomnav__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M3 12h18M3 6h12M3 18h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </span>
                <span class="knx-driver-bottomnav__label">Live</span>
            </a>

            <a class="knx-driver-bottomnav__item <?php echo ($current === 'profile') ? 'is-active' : ''; ?>"
               href="<?php echo esc_attr($profile_url); ?>"
               <?php echo ($current === 'profile') ? 'aria-current="page"' : ''; ?>
               data-tab="profile"
            >
                <span class="knx-driver-bottomnav__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M20 21a8 8 0 1 0-16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </span>
                <span class="knx-driver-bottomnav__label">Profile</span>
            </a>
        </nav>
        <?php
        // Flag nav printed (useful when we render in footer globally)
        $GLOBALS['knx_driver_bottomnav_printed'] = true;
    }
}

/**
 * Render the driver bottom navbar globally for driver users only.
 * Uses the navigation engine context/layout to decide whether it's safe to render.
 */
if (!function_exists('knx_driver_bottom_nav_maybe_render_global')) {
    function knx_driver_bottom_nav_maybe_render_global() {
        // Only render on frontend
        if (is_admin()) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        if (defined('WP_CLI') && WP_CLI) return;

        // Don't render if already printed
        if (!empty($GLOBALS['knx_driver_bottomnav_printed'])) return;

        // Use navigation engine for authoritative context
        if (!function_exists('knx_get_navigation_context')) return;
        $nav_ctx = knx_get_navigation_context();
        if (empty($nav_ctx) || !is_array($nav_ctx)) return;

        // Only drivers should see this global nav
        if (empty($nav_ctx['is_driver'])) return;

        // Use layout to avoid conflicts with admin/sidebar pages
        if (function_exists('knx_get_navigation_layout')) {
            $layout = knx_get_navigation_layout($nav_ctx);
            if (!empty($layout['sidebar_area']) && $layout['sidebar_area'] === 'admin') {
                // Avoid rendering on admin pages that use a sidebar
                return;
            }
            // If layout explicitly disables navbar, respect it
            if (isset($layout['render_navbar']) && $layout['render_navbar'] === false) return;
        }

        // Determine current tab from request path
        $path = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        $current = '';
        if (strpos($path, '/driver-quick-menu') !== false) $current = 'quick';
        else if (strpos($path, '/driver-ops') !== false) $current = 'ops';
        else if (strpos($path, '/driver-live-orders') !== false) $current = 'live';
        else if (strpos($path, '/driver-profile') !== false) $current = 'profile';

        // Render navbar
        knx_driver_bottom_nav_render([
            'current' => $current,
            'quick_url' => '/driver-quick-menu',
            'ops_url' => '/driver-ops',
            'live_url' => '/driver-live-orders',
            'profile_url' => '/driver-profile',
            'last_active_order_id' => 0,
        ]);
    }

    // Attach to footer late so nav appears above closing body and after main content
    add_action('wp_footer', 'knx_driver_bottom_nav_maybe_render_global', 100);
}
