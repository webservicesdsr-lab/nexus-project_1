<?php
if (!defined('ABSPATH')) exit;

/**
 * Driver OPS Screen — Catch Orders (Available + Assign)
 * Shortcode: [knx_driver_ops]
 *
 * Notes:
 * - Driver-only (knx_get_driver_context required)
 * - Injects CSS/JS inline (NO wp_enqueue, NO wp_footer dependency)
 */
add_shortcode('knx_driver_ops', function () {

    if (!function_exists('knx_get_driver_context') || !knx_get_driver_context()) {
        return '<div class="knx-driver-shell"><p>Unauthorized (driver only).</p></div>';
    }

    $api_base_v2   = rest_url('knx/v2/driver/orders/');
    $available_url = rest_url('knx/v2/driver/orders/available');
    $nonce         = wp_create_nonce('knx_nonce');

    $dir = __DIR__;
    $css_path = $dir . '/driver-ops-style.css';
    $js_path  = $dir . '/driver-ops-script.js';

    $css = file_exists($css_path) ? file_get_contents($css_path) : '';
    $js  = file_exists($js_path) ? file_get_contents($js_path) : '';

    ob_start();
    ?>
    <style><?php echo $css; ?></style>

    <div class="knx-driver-shell" data-knx-driver-module="driver-ops"
         data-api-base-v2="<?php echo esc_attr($api_base_v2); ?>"
         data-api-available-url="<?php echo esc_attr($available_url); ?>"
         data-knx-nonce="<?php echo esc_attr($nonce); ?>">

        <div class="knx-driver-topbar">
            <div class="knx-driver-topbar__title">Driver OPS</div>
            <div class="knx-driver-topbar__subtitle">Available orders</div>
        </div>

        <div class="knx-driver-toast" aria-live="polite" aria-atomic="true"></div>

        <div class="knx-driver-card knx-driver-card--soft">
            <div class="knx-driver-row">
                <button type="button" class="knx-btn knx-btn--primary" data-knx-action="refresh">Refresh</button>
                <a class="knx-btn knx-btn--ghost" href="/driver-active-orders">Go to My Active Orders</a>
            </div>
        </div>

        <div class="knx-driver-list" data-knx-list="available"></div>
    </div>

    <script><?php echo $js; ?></script>
    <?php
    return ob_get_clean();
});