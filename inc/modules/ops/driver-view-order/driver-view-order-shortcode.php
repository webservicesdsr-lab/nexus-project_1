<?php
// File: inc/modules/ops/driver-view-order/driver-view-order-shortcode.php
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

    $ver = defined('KNX_VERSION') ? KNX_VERSION : (string)time();

    // Base URL for assets
    $base_url = defined('KNX_URL') ? KNX_URL : plugin_dir_url(__FILE__);

    // REST URLs
    $api_url  = esc_url(rest_url('knx/v2/driver/orders')); // v2 driver base
    $back_url = esc_url((string)$atts['back_url']);

    // Toast system (needed because JS calls window.knxToast)
    $toast_css = esc_url($base_url . 'inc/modules/core/knx-toast.css?v=' . $ver);
    $toast_js  = esc_url($base_url . 'inc/modules/core/knx-toast.js?v=' . $ver);

    // Core VO styles (existing OPS VO layout)
    $vo_css        = esc_url($base_url . 'inc/modules/ops/view-order/view-order-style.css?v=' . $ver);
    $vo_actions    = esc_url($base_url . 'inc/modules/ops/view-order/view-order-actions.css?v=' . $ver);
    $driver_actions= esc_url($base_url . 'inc/modules/ops/driver-view-order/driver-view-order-actions.css?v=' . $ver);

    // Driver chat styles (this is what was missing)
    $driver_vo_css = esc_url($base_url . 'inc/modules/ops/driver-view-order/driver-view-order-style.css?v=' . $ver);

    // Script
    $driver_vo_js  = esc_url($base_url . 'inc/modules/ops/driver-view-order/driver-view-order-script.js?v=' . $ver);

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo $toast_css; ?>">
    <link rel="stylesheet" href="<?php echo $vo_css; ?>">
    <link rel="stylesheet" href="<?php echo $vo_actions; ?>">
    <link rel="stylesheet" href="<?php echo $driver_actions; ?>">
    <link rel="stylesheet" href="<?php echo $driver_vo_css; ?>">

    <script>
      // Used by some endpoints that expect a KNX nonce in body
      window.knxNonce = <?php echo json_encode(wp_create_nonce('knx_nonce')); ?>;
    </script>

    <div id="knxOpsViewOrderApp"
         class="knx-ops-vo"
         data-api-url="<?php echo esc_attr($api_url); ?>"
         data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
         data-back-url="<?php echo esc_attr($back_url); ?>"
         data-order-id="<?php echo (int)$order_id; ?>">
        <div class="knx-ops-vo__shell">

            <div class="knx-ops-vo__topbar">
                <a class="knx-ops-vo__back" href="<?php echo esc_url($back_url); ?>">&larr; Back</a>
            </div>

            <div class="knx-ops-vo__title">
                <h2>Order tracking</h2>
                <div id="knxOpsVOState" class="knx-ops-vo__state">
                    <?php echo ($order_id > 0) ? 'Loading…' : 'Missing order_id'; ?>
                </div>
            </div>

            <div id="knxOpsVOContent" class="knx-ops-vo__content"></div>
        </div>
    </div>

    <script src="<?php echo $toast_js; ?>"></script>
    <script src="<?php echo $driver_vo_js; ?>" defer></script>
    <?php
    return ob_get_clean();
});