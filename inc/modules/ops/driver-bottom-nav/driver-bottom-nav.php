<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX DRIVER — Bottom Navbar (CANON)
 * Auto-injected globally for drivers (mobile app style).
 *
 * Links (4 screens):
 * - /driver-quick-menu
 * - /driver-ops
 * - /driver-active-orders
 * - /driver-profile
 *
 * Notes:
 * - Driver-only (auto-detects via session).
 * - Inline CSS/JS injected in wp_footer.
 * - Appears on ALL pages when driver is logged in.
 * - Soft-push runtime is owned globally by kingdom-nexus.php
 * ==========================================================
 */

/**
 * Auto-inject bottom navbar for drivers globally
 */
add_action('wp_footer', function () {
    if (!function_exists('knx_get_driver_context')) return;

    $ctx = knx_get_driver_context();
    if (!$ctx || empty($ctx->session) || (string)($ctx->session->role ?? '') !== 'driver') return;

    $home_url    = site_url('/driver-quick-menu');
    $catch_url   = site_url('/driver-ops');
    $orders_url  = site_url('/driver-active-orders');
    $profile_url = site_url('/driver-profile');

    $css = '';
    $js  = '';

    $css_path = __DIR__ . '/driver-bottom-nav-style.css';
    if (file_exists($css_path)) {
        $css = (string) file_get_contents($css_path);
    }

    $js_path = __DIR__ . '/driver-bottom-navbar.js';
    if (file_exists($js_path)) {
        $js = (string) file_get_contents($js_path);
    }

    ?>
    <?php if ($css !== ''): ?>
        <style data-knx="driver-bottom-nav-style"><?php echo $css; ?></style>
    <?php endif; ?>

    <script>
        document.body.classList.add('knx-has-driver-nav');
    </script>

    <nav class="knx-dbn" data-knx-driver-bottom-nav
         data-home-url="<?php echo esc_attr($home_url); ?>"
         data-catch-url="<?php echo esc_attr($catch_url); ?>"
         data-orders-url="<?php echo esc_attr($orders_url); ?>"
         data-profile-url="<?php echo esc_attr($profile_url); ?>"
         aria-label="Driver navigation">
        <a class="knx-dbn__item" data-nav="home" href="<?php echo esc_url($home_url); ?>">
            <span class="knx-dbn__icon">🏠</span>
            <span class="knx-dbn__label">Home</span>
        </a>
        <a class="knx-dbn__item" data-nav="catch" href="<?php echo esc_url($catch_url); ?>">
            <span class="knx-dbn__icon">⚡</span>
            <span class="knx-dbn__label">Catch</span>
        </a>
        <a class="knx-dbn__item" data-nav="orders" href="<?php echo esc_url($orders_url); ?>">
            <span class="knx-dbn__icon">📦</span>
            <span class="knx-dbn__label">Orders</span>
        </a>
        <a class="knx-dbn__item" data-nav="profile" href="<?php echo esc_url($profile_url); ?>">
            <span class="knx-dbn__icon">👤</span>
            <span class="knx-dbn__label">Profile</span>
        </a>
    </nav>

    <?php if ($js !== ''): ?>
        <script data-knx="driver-bottom-nav-js"><?php echo $js; ?></script>
    <?php endif; ?>
    <?php
}, 999);

/**
 * Legacy shortcode support (optional, for manual placement)
 */
add_shortcode('knx_driver_bottom_nav', function () {
    return '';
});