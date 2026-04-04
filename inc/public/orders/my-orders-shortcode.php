<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Customer Order List (Canonical)
 * Shortcode: [knx_my_orders]
 * ----------------------------------------------------------
 * - Requires logged-in customer session
 * - JS fetches GET /knx/v1/orders (session-scoped)
 * - Paginated with "Load more" (limit/offset)
 * - Separates Active vs Past orders
 * - Each card links to /order-status?order_id=X
 * - Nexus Shell UX
 * ==========================================================
 */

add_shortcode('knx_my_orders', 'knx_render_my_orders_page');

function knx_render_my_orders_page($atts = array()) {
    $ver      = defined('KNX_VERSION') ? KNX_VERSION : '1.0';
    $base_url = defined('KNX_URL')     ? KNX_URL     : plugin_dir_url(__FILE__);

    $css_url  = esc_url($base_url . 'inc/public/orders/my-orders.css?v=' . $ver);
    $js_url   = esc_url($base_url . 'inc/public/orders/my-orders.js?v=' . $ver);

    // Toast system
    $toast_css = esc_url($base_url . 'inc/modules/core/knx-toast.css?v=' . $ver);
    $toast_js  = esc_url($base_url . 'inc/modules/core/knx-toast.js?v=' . $ver);

    // ── Auth gate — customers only ──
    $session = function_exists('knx_get_session') ? knx_get_session() : null;

    if (!$session || empty($session->user_id) || ($session->role ?? '') !== 'customer') {
        ob_start();
        ?>
        <link rel="stylesheet" href="<?php echo $css_url; ?>">
        <div class="knx-mo__auth-gate">
            <div class="knx-mo__auth-card">
                <div class="knx-mo__auth-icon">🔒</div>
                <h2 class="knx-mo__auth-title">Login Required</h2>
                <p class="knx-mo__auth-text">Sign in to view your order history.</p>
                <a class="knx-mo__btn knx-mo__btn--primary" href="<?php echo esc_url(site_url('/login') . '?redirect_to=' . rawurlencode('/my-orders')); ?>">Sign In</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo $toast_css; ?>">
    <link rel="stylesheet" href="<?php echo $css_url; ?>">

    <div id="knx-my-orders" class="knx-my-orders"
         data-api-base="<?php echo esc_url(rest_url('knx/v1/orders')); ?>"
         data-order-status-url="<?php echo esc_url(site_url('/order-status')); ?>"
         data-home-url="<?php echo esc_url(home_url('/')); ?>"
         data-page-size="15">

        <div class="knx-mo__shell">
            <div class="knx-mo__topbar">
                <h1 class="knx-mo__title">My Orders</h1>
            </div>

            <div id="knxMyOrdersContent" class="knx-mo__content">
                <div class="knx-mo__loading">
                    <div class="knx-mo__spinner"></div>
                    <span>Loading your orders…</span>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo $toast_js; ?>"></script>
    <script src="<?php echo $js_url; ?>"></script>
    <?php
    return ob_get_clean();
}
