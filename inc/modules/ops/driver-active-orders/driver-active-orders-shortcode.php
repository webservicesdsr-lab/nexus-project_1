<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Driver Active Orders (Shortcode) (v1.0)
 * Shortcode: [knx_driver_active_orders]
 * ----------------------------------------------------------
 * Purpose:
 * - Display active (assigned) orders for logged-in driver
 * - Execution-focused UI (order details, status updates, completion)
 * - NOT for order discovery (see driver-ops module)
 * 
 * API Endpoints:
 * - GET  /knx/v1/ops/driver-active-orders (fetch assigned orders)
 * - POST /knx/v1/ops/driver-orders/{id}/status (update order status)
 * - POST /knx/v1/ops/driver-orders/{id}/complete (mark complete)
 * 
 * Architecture:
 * - Non-authoritative UI (all state changes via REST)
 * - Assets injected via <link>/<script> (no wp_footer)
 * - Separate responsibility from driver-ops (discovery feed)
 * ==========================================================
 */

add_shortcode('knx_driver_active_orders', function () {

    // Driver context validation
    if (function_exists('knx_get_driver_context')) {
        $ctx = knx_get_driver_context();
        if (empty($ctx) || empty($ctx->session) || empty($ctx->session->user_id)) {
            wp_safe_redirect(site_url('/login'));
            exit;
        }
    } else {
        // Fallback session check
        if (!function_exists('knx_get_session')) {
            return '<div class="knx-drivers-err">Session unavailable.</div>';
        }
        $session = knx_get_session();
        $role = $session && isset($session->role) ? (string)$session->role : '';
        if (!$session || !in_array($role, array('driver','super_admin'), true)) {
            wp_safe_redirect(site_url('/login'));
            exit;
        }
    }

    // Driver name for greeting
    $driver_name = '';
    if (isset($ctx->session->username)) {
        $driver_name = $ctx->session->username;
    } elseif (isset($session->username)) {
        $driver_name = $session->username;
    }

    // Nonces
    $knx_nonce     = wp_create_nonce('knx_nonce');
    $wp_rest_nonce = wp_create_nonce('wp_rest');

    // API URLs
    $api_active = rest_url('knx/v1/ops/driver-active-orders');
    $api_base   = rest_url('knx/v1/ops/driver-orders/'); // {base}{id}/status or /complete

    // Asset URLs
    $ver = defined('KNX_VERSION') ? KNX_VERSION : '1.0';
    $plugin_root = dirname(__FILE__, 4);
    $base_url = defined('KNX_URL') ? KNX_URL : plugin_dir_url($plugin_root . '/kingdom-nexus.php');

    $css_url = esc_url($base_url . 'inc/modules/ops/driver-active-orders/driver-active-orders-style.css?v=' . $ver);
    $js_url  = esc_url($base_url . 'inc/modules/ops/driver-active-orders/driver-active-orders-script.js?v=' . $ver);

    // Toast system
    $toast_css_url = esc_url($base_url . 'inc/modules/core/knx-toast.css?v=' . $ver);
    $toast_js_url  = esc_url($base_url . 'inc/modules/core/knx-toast.js?v=' . $ver);

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo $toast_css_url; ?>">
    <link rel="stylesheet" href="<?php echo $css_url; ?>">

    <div id="knx-driver-active-orders"
         class="knx-driver-active-wrapper"
         data-api-active="<?php echo esc_url($api_active); ?>"
         data-api-base="<?php echo esc_url($api_base); ?>"
         data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
         data-wp-rest-nonce="<?php echo esc_attr($wp_rest_nonce); ?>">

        <div class="knx-active-header">
            <h2>Active Orders</h2>
        </div>

        <div id="knxActiveOrdersList" class="knx-active-orders-list" aria-label="Active orders list">
            <div class="knx-empty">Loading your active orders…</div>
        </div>
    </div>

    <script src="<?php echo $toast_js_url; ?>"></script>
    <script src="<?php echo $js_url; ?>"></script>
    <?php
    return ob_get_clean();
});
