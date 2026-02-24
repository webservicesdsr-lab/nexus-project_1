<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Customer Order Status (Canonical)
 * Shortcode: [knx_order_status]
 * ----------------------------------------------------------
 * - Reads order_id from ?order_id= query param
 * - JS fetches GET /knx/v1/orders/{id} (session-scoped)
 * - Polls every 15s for real-time status updates
 * - Nexus Shell UX styling
 * ==========================================================
 */

add_shortcode('knx_order_status', 'knx_render_order_status_page');

function knx_render_order_status_page($atts = array()) {
    $ver = defined('KNX_VERSION') ? KNX_VERSION : '1.0';
    $base_url = defined('KNX_URL') ? KNX_URL : plugin_dir_url(__FILE__);

    $css_url = esc_url($base_url . 'inc/public/orders/order-status.css?v=' . $ver);
    $js_url  = esc_url($base_url . 'inc/public/orders/order-status.js?v=' . $ver);

    // Toast system
    $toast_css = esc_url($base_url . 'inc/modules/core/knx-toast.css?v=' . $ver);
    $toast_js  = esc_url($base_url . 'inc/modules/core/knx-toast.js?v=' . $ver);

    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo $toast_css; ?>">
    <link rel="stylesheet" href="<?php echo $css_url; ?>">

    <div id="knx-order-status" class="knx-order-status"
         data-order-id="<?php echo (int)$order_id; ?>"
         data-api-base="<?php echo esc_url(rest_url('knx/v1/orders/')); ?>"
         data-home-url="<?php echo esc_url(home_url('/')); ?>"
         data-orders-url="<?php echo esc_url(site_url('/my-orders')); ?>">

        <div class="knx-os__shell">
            <div class="knx-os__topbar">
                <a class="knx-os__back" href="<?php echo esc_url(site_url('/my-orders')); ?>">&larr; My Orders</a>
            </div>

            <div id="knxOrderStatusContent" class="knx-os__content">
                <?php if ($order_id > 0): ?>
                    <div class="knx-os__loading">Loading order details…</div>
                <?php else: ?>
                    <div class="knx-os__error">Order ID missing. Please check the URL.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="<?php echo $toast_js; ?>"></script>
    <script src="<?php echo $js_url; ?>"></script>
    <?php
    return ob_get_clean();
}
