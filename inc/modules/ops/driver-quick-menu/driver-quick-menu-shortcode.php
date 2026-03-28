<?php
// inc/modules/ops/driver-quick-menu/driver-quick-menu-shortcode.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX — Driver Quick Menu — CANON v1.1
 * Shortcode: [knx_driver_quick_menu]
 *
 * - Driver-only (fail-closed)
 * - Simple navigation tiles
 * - Notification controls moved to Profile
 * ==========================================================
 */

add_shortcode('knx_driver_quick_menu', function ($atts = []) {

    if (!function_exists('knx_get_driver_context')) {
        return '';
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id) || empty($ctx->session->role) || $ctx->session->role !== 'driver') {
        return '';
    }

    $active_order_id = isset($atts['active_order_id']) ? (int)$atts['active_order_id'] : 0;

    $live_url    = esc_url(site_url('/driver-live-orders'));
    $active_url  = esc_url(site_url('/driver-active-orders'));
    $past_url    = esc_url(site_url('/driver-past-orders'));
    $profile_url = esc_url(site_url('/driver-profile'));

    ob_start();
    ?>
    <div id="knx-driver-quick-menu" class="knx-dqm" data-active-order-id="<?php echo esc_attr($active_order_id); ?>" data-active-url="<?php echo esc_attr($active_url); ?>">
        <div class="knx-dqm__grid">
            <a class="knx-dqm__tile" href="<?php echo esc_url($live_url); ?>">
                <div class="knx-dqm__title">Available</div>
                <div class="knx-dqm__sub">New orders</div>
            </a>

            <a class="knx-dqm__tile knx-dqm__tile--active" href="<?php echo esc_url($active_url); ?>" id="knxDqmActiveLink">
                <div class="knx-dqm__title">Active</div>
                <div class="knx-dqm__sub">Current order</div>
            </a>

            <a class="knx-dqm__tile" href="<?php echo esc_url($past_url); ?>">
                <div class="knx-dqm__title">Past</div>
                <div class="knx-dqm__sub">History</div>
            </a>

            <a class="knx-dqm__tile" href="<?php echo esc_url($profile_url); ?>">
                <div class="knx-dqm__title">Profile</div>
                <div class="knx-dqm__sub">Notifications & account</div>
            </a>
        </div>
    </div>
    <?php

    $css_path = __DIR__ . '/driver-quick-menu-style.css';
    if (is_readable($css_path)) {
        echo '<style>' . file_get_contents($css_path) . '</style>';
    }

    $js_path = __DIR__ . '/driver-quick-menu-script.js';
    if (is_readable($js_path)) {
        echo '<script>' . file_get_contents($js_path) . '</script>';
    }

    return ob_get_clean();
});

add_shortcode('knx-driver-quick-menu', function ($atts = []) {
    return do_shortcode('[knx_driver_quick_menu]');
});