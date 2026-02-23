<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX DRIVER — Active Orders (CANON)
 * Shortcode: [knx_driver_active_orders]
 *
 * Canon:
 * - Driver-only (knx_get_driver_context()).
 * - Uses ONLY DB-canon status: knx_orders.status (8 values).
 * - "Order Created" is UI-only label for status=confirmed (uses created_at timestamp).
 * - Two modals only: Change Status, Release Order.
 *
 * Notes:
 * - Assets injected inline via file_get_contents (no wp_footer dependency).
 * - Data attributes provide API URLs + nonce.
 * ==========================================================
 */

add_shortcode('knx_driver_active_orders', function ($atts = []) {

    if (!function_exists('knx_get_driver_context')) {
        return '<div class="knx-driver-orders-error">Driver context unavailable.</div>';
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || empty($ctx->session) || empty($ctx->session->user_id)) {
        return '<div class="knx-driver-orders-error">Unauthorized (driver only).</div>';
    }

    $role = isset($ctx->session->role) ? (string)$ctx->session->role : '';
    if ($role !== 'driver') {
        return '<div class="knx-driver-orders-error">Unauthorized (driver only).</div>';
    }

    $atts = shortcode_atts([
        // Where clicking "View" should go (expects ?order_id=123)
        'view_order_url' => site_url('/driver-view-order'),
        // Polling interval (ms)
        'poll_ms' => 10000,
        // Include terminal in list response (we do our own bucketing in UI anyway)
        'include_terminal' => 1,
    ], (array)$atts, 'knx_driver_active_orders');

    $poll_ms = (int)$atts['poll_ms'];
    if ($poll_ms < 5000) $poll_ms = 5000;
    if ($poll_ms > 60000) $poll_ms = 60000;

    $include_terminal = ((int)$atts['include_terminal'] === 1) ? 1 : 0;

    $api_base_v2 = rest_url('knx/v2/driver/orders/');
    $active_url  = rest_url('knx/v2/driver/orders/active');
    $nonce       = wp_create_nonce('knx_nonce');

    // Inline assets
    $css = '';
    $js  = '';

    $css_path = __DIR__ . '/driver-active-orders-style.css';
    if (file_exists($css_path)) {
        $css = (string)file_get_contents($css_path);
    }

    $js_path = __DIR__ . '/driver-active-orders-script.js';
    if (file_exists($js_path)) {
        $js = (string)file_get_contents($js_path);
    }

    ob_start();
    ?>
    <?php if ($css !== ''): ?>
        <style data-knx="driver-active-orders-style"><?php echo $css; ?></style>
    <?php endif; ?>

    <div
        class="knx-do"
        data-knx-driver-module="active-orders"
        data-api-base-v2="<?php echo esc_attr($api_base_v2); ?>"
        data-api-active-url="<?php echo esc_attr($active_url); ?>"
        data-view-order-url="<?php echo esc_attr((string)$atts['view_order_url']); ?>"
        data-knx-nonce="<?php echo esc_attr($nonce); ?>"
        data-poll-ms="<?php echo (int)$poll_ms; ?>"
        data-include-terminal="<?php echo (int)$include_terminal; ?>"
    >
        <div class="knx-do__shell">
            <div class="knx-do__top">
                <div class="knx-do__title-wrap">
                    <h2 class="knx-do__title">My Orders</h2>
                    <div class="knx-do__live">
                        <span class="knx-do__dot" aria-hidden="true"></span>
                        <span class="knx-do__live-text">Live</span>
                    </div>
                </div>

                <div class="knx-do__tabs" role="tablist" aria-label="Driver orders tabs">
                    <button class="knx-do__tab is-active" type="button" data-tab="active" role="tab" aria-selected="true">
                        Active <span class="knx-do__count" data-count="active">0</span>
                    </button>
                    <button class="knx-do__tab" type="button" data-tab="completed" role="tab" aria-selected="false">
                        Completed <span class="knx-do__count" data-count="completed">0</span>
                    </button>
                </div>
            </div>

            <div class="knx-do__panels">
                <div class="knx-do__panel is-active" data-panel="active" role="tabpanel">
                    <div class="knx-do__list" data-list="active"></div>
                </div>
                <div class="knx-do__panel" data-panel="completed" role="tabpanel">
                    <div class="knx-do__list" data-list="completed"></div>
                </div>
            </div>
        </div>

        <!-- Toast -->
        <div class="knx-do__toast" data-toast aria-live="polite" aria-atomic="true"></div>

        <!-- Modal: Change Status -->
        <div class="knx-do__modal" data-modal="status" role="dialog" aria-modal="true" aria-label="Change Order Status">
            <div class="knx-do__overlay" data-close-modal></div>
            <div class="knx-do__dialog" role="document">
                <div class="knx-do__dialog-head">
                    <div class="knx-do__dialog-title">Change Order Status</div>
                    <button class="knx-do__icon-btn" type="button" data-close-modal aria-label="Close">×</button>
                </div>

                <div class="knx-do__dialog-body">
                    <div class="knx-do__modal-meta" data-status-meta></div>
                    <div class="knx-do__options" data-status-options></div>
                </div>

                <div class="knx-do__dialog-foot">
                    <button class="knx-do__btn" type="button" data-close-modal>Cancel</button>
                    <button class="knx-do__btn knx-do__btn--primary" type="button" data-confirm-status disabled>
                        Confirm
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal: Release -->
        <div class="knx-do__modal" data-modal="release" role="dialog" aria-modal="true" aria-label="Release Order">
            <div class="knx-do__overlay" data-close-modal></div>
            <div class="knx-do__dialog" role="document">
                <div class="knx-do__dialog-head">
                    <div class="knx-do__dialog-title">Release Order</div>
                    <button class="knx-do__icon-btn" type="button" data-close-modal aria-label="Close">×</button>
                </div>

                <div class="knx-do__dialog-body">
                    <p class="knx-do__p">
                        Are you sure you want to release this order? It will become available for other drivers.
                    </p>
                    <div class="knx-do__modal-meta" data-release-meta></div>
                </div>

                <div class="knx-do__dialog-foot">
                    <button class="knx-do__btn" type="button" data-close-modal>Cancel</button>
                    <button class="knx-do__btn knx-do__btn--danger" type="button" data-confirm-release>
                        Release
                    </button>
                </div>
            </div>
        </div>

    </div>

    <?php if ($js !== ''): ?>
        <script data-knx="driver-active-orders-script"><?php echo $js; ?></script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
});