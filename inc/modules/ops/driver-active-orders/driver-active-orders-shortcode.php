<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Driver Active Orders (BRIDGE)
 * Shortcode: [knx_driver_active_orders]
 * Slug (WP Page): /driver-active-orders
 *
 * Purpose:
 * - Show assigned (active) driver orders
 * - UX bridge: tap any card -> /driver-view-order?order_id=...
 *
 * Canon:
 * - GET  /wp-json/knx/v2/driver/orders/active
 * - Reads ONLY DB-canon status from response
 *
 * Constraints:
 * - No wp_footer dependency
 * - Assets loaded via <link>/<script> (no enqueue)
 * ==========================================================
 */

add_shortcode('knx_driver_active_orders', function () {

    // Strict driver context
    $ctx = null;
    $session = null;

    if (function_exists('knx_get_driver_context')) {
        $ctx = knx_get_driver_context();
        if (empty($ctx) || empty($ctx->session) || empty($ctx->session->user_id) || (string)($ctx->session->role ?? '') !== 'driver') {
            wp_safe_redirect(site_url('/login'));
            exit;
        }
        $session = $ctx->session;
    } else {
        if (!function_exists('knx_get_session')) {
            return '<div class="knx-drivers-err">Session unavailable.</div>';
        }
        $session = knx_get_session();
        $role = $session && isset($session->role) ? (string)$session->role : '';
        if (!$session || $role !== 'driver') {
            wp_safe_redirect(site_url('/login'));
            exit;
        }
    }

    $driver_name = '';
    if ($session && isset($session->username)) $driver_name = (string)$session->username;

    // Nonces (some installs still want wp_rest nonce header; safe to include)
    $knx_nonce     = wp_create_nonce('knx_nonce');
    $wp_rest_nonce = wp_create_nonce('wp_rest');

    // Canon API
    $api_active = rest_url('knx/v2/driver/orders/active');
    $api_signals = rest_url('knx/v1/hub-management/orders/signals');

    // View order URL (page route; uses query param order_id)
    $view_order_url = site_url('/driver-view-order');

    // Polling
    $poll_ms = 15000;

    // Assets
    $ver = defined('KNX_VERSION') ? KNX_VERSION : '1.0';
    $base_url = defined('KNX_URL') ? KNX_URL : plugin_dir_url(dirname(__FILE__, 4) . '/kingdom-nexus.php');

    $css_url = esc_url($base_url . 'inc/modules/ops/driver-active-orders/driver-active-orders-style.css?v=' . $ver);
    $js_url  = esc_url($base_url . 'inc/modules/ops/driver-active-orders/driver-active-orders-script.js?v=' . $ver);

    // Toast system (optional but consistent)
    $toast_css_url = esc_url($base_url . 'inc/modules/core/knx-toast.css?v=' . $ver);
    $toast_js_url  = esc_url($base_url . 'inc/modules/core/knx-toast.js?v=' . $ver);

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo $toast_css_url; ?>">
    <link rel="stylesheet" href="<?php echo $css_url; ?>">

    <div id="knx-driver-active-orders"
         class="knx-driver-active-wrapper"
         data-api-active="<?php echo esc_url($api_active); ?>"
         data-view-order-url="<?php echo esc_url($view_order_url); ?>"
         data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
         data-wp-rest-nonce="<?php echo esc_attr($wp_rest_nonce); ?>"
         data-poll-ms="<?php echo (int)$poll_ms; ?>">

        <div class="knx-active-header">
            <div class="knx-active-title">
                <h2>Active Orders<?php echo $driver_name ? ' — ' . esc_html($driver_name) : ''; ?></h2>
                <div class="knx-active-sub">Tap any card to open details</div>
            </div>

            <div class="knx-active-controls">
                <button type="button" class="knx-btn-icon" id="knxActiveRefresh" title="Refresh" aria-label="Refresh">↻</button>

                <div class="knx-live">
                    <label class="knx-live-label" for="knxActiveLive">Live</label>
                    <label class="knx-switch" aria-label="Toggle live refresh">
                        <input id="knxActiveLive" type="checkbox" checked>
                        <span class="knx-slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="knx-active-toolbar">
            <div class="knx-active-search">
                <label class="sr-only" for="knxActiveSearch">Search</label>
                <input id="knxActiveSearch" class="knx-input" type="text" inputmode="search"
                       placeholder="Search by order #, restaurant, customer, address…" autocomplete="off">
            </div>

            <a class="knx-btn-secondary knx-active-cta" href="/driver-ops">Catch Orders</a>
        </div>

        <div id="knxActiveOrdersList" class="knx-active-orders-list" aria-label="Active orders list">
            <div class="knx-empty">Loading your active orders…</div>
        </div>

        <?php echo do_shortcode('[knx_driver_bottom_nav]'); ?>
    </div>

    <script>
      window.KNX_DRIVER_ACTIVE_CONFIG = {
        apiActive: <?php echo wp_json_encode($api_active); ?>,
        apiSignals: <?php echo wp_json_encode($api_signals); ?>,
        viewOrderUrl: <?php echo wp_json_encode($view_order_url); ?>,
        knxNonce: <?php echo wp_json_encode($knx_nonce); ?>,
        wpRestNonce: <?php echo wp_json_encode($wp_rest_nonce); ?>,
        pollMs: <?php echo (int)$poll_ms; ?>
      };
    </script>

    <script src="<?php echo $toast_js_url; ?>"></script>
    <script src="<?php echo $js_url; ?>"></script>
    <?php
    return ob_get_clean();
});