<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX DRIVER — View Order (CANON)
 * Shortcode: [knx_driver_view_order]
 *
 * URL pattern:
 * - /driver-view-order?order_id=123
 *
 * Rules:
 * - Driver-only (knx_get_driver_context()).
 * - Uses v2 driver detail endpoint:
 *   GET /wp-json/knx/v2/driver/orders/{id}
 * - Two modals only: Change Status, Release.
 * - No wp_footer dependency; assets inline.
 * ==========================================================
 */

add_shortcode('knx_driver_view_order', function ($atts = []) {

    if (!function_exists('knx_get_driver_context')) {
        return '<div class="knx-driver-vo-err">Driver context unavailable.</div>';
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id)) {
        return '<div class="knx-driver-vo-err">Unauthorized (driver only).</div>';
    }

    $role = isset($ctx->session->role) ? (string)$ctx->session->role : '';
    if ($role !== 'driver') {
        return '<div class="knx-driver-vo-err">Unauthorized (driver only).</div>';
    }

    $atts = shortcode_atts([
        'back_url' => site_url('/driver-active-orders'),
    ], (array)$atts, 'knx_driver_view_order');

    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

    $api_base_v2 = rest_url('knx/v2/driver/orders/');
    $nonce       = wp_create_nonce('knx_nonce');

    $css = '';
    $js  = '';

    $css_path = __DIR__ . '/driver-view-order-style.css';
    if (file_exists($css_path)) $css = (string)file_get_contents($css_path);

    $js_path = __DIR__ . '/driver-view-order-script.js';
    if (file_exists($js_path)) $js = (string)file_get_contents($js_path);

    ob_start();
    ?>
    <?php if ($css !== ''): ?>
        <style data-knx="driver-view-order-style"><?php echo $css; ?></style>
    <?php endif; ?>

    <div
        class="knx-vo"
        data-knx-driver-module="view-order"
        data-order-id="<?php echo (int)$order_id; ?>"
        data-api-base-v2="<?php echo esc_attr($api_base_v2); ?>"
        data-knx-nonce="<?php echo esc_attr($nonce); ?>"
        data-back-url="<?php echo esc_attr((string)$atts['back_url']); ?>"
    >
        <div class="knx-vo__shell">
            <div class="knx-vo__topbar">
                <a class="knx-vo__back" href="<?php echo esc_url((string)$atts['back_url']); ?>">← Back</a>
                <div class="knx-vo__title">Order Details</div>
            </div>

            <div class="knx-vo__state" data-state>
                <?php echo ($order_id > 0) ? 'Loading…' : 'Missing order_id'; ?>
            </div>

            <div class="knx-vo__card" data-content></div>
        </div>

        <!-- Toast -->
        <div class="knx-vo__toast" data-toast aria-live="polite" aria-atomic="true"></div>

        <!-- Modal: Change Status -->
        <div class="knx-vo__modal" data-modal="status" role="dialog" aria-modal="true" aria-label="Change Order Status">
            <div class="knx-vo__overlay" data-close-modal></div>
            <div class="knx-vo__dialog" role="document">
                <div class="knx-vo__dialog-head">
                    <div class="knx-vo__dialog-title">Change Order Status</div>
                    <button class="knx-vo__icon-btn" type="button" data-close-modal aria-label="Close">×</button>
                </div>
                <div class="knx-vo__dialog-body">
                    <div class="knx-vo__meta" data-status-meta></div>
                    <div class="knx-vo__options" data-status-options></div>
                </div>
                <div class="knx-vo__dialog-foot">
                    <button class="knx-vo__btn" type="button" data-close-modal>Cancel</button>
                    <button class="knx-vo__btn knx-vo__btn--primary" type="button" data-confirm-status disabled>Confirm</button>
                </div>
            </div>
        </div>

        <!-- Modal: Release -->
        <div class="knx-vo__modal" data-modal="release" role="dialog" aria-modal="true" aria-label="Release Order">
            <div class="knx-vo__overlay" data-close-modal></div>
            <div class="knx-vo__dialog" role="document">
                <div class="knx-vo__dialog-head">
                    <div class="knx-vo__dialog-title">Release Order</div>
                    <button class="knx-vo__icon-btn" type="button" data-close-modal aria-label="Close">×</button>
                </div>
                <div class="knx-vo__dialog-body">
                    <p class="knx-vo__p">Are you sure you want to release this order? It will become available for other drivers.</p>
                    <div class="knx-vo__meta" data-release-meta></div>
                </div>
                <div class="knx-vo__dialog-foot">
                    <button class="knx-vo__btn" type="button" data-close-modal>Cancel</button>
                    <button class="knx-vo__btn knx-vo__btn--danger" type="button" data-confirm-release>Release</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($js !== ''): ?>
        <script data-knx="driver-view-order-script"><?php echo $js; ?></script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
});