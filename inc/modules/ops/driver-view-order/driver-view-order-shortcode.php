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

    $ver = defined('KNX_VERSION') ? KNX_VERSION : (string)time();

    $api_url = esc_url(rest_url('knx/v2/driver/orders'));
    $back_url = esc_url((string)$atts['back_url']);

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/ops/view-order/view-order-style.css'); ?>?v=<?php echo esc_attr($ver); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/ops/view-order/view-order-actions.css'); ?>?v=<?php echo esc_attr($ver); ?>">

    <div id="knxOpsViewOrderApp"
         class="knx-ops-vo"
         data-api-url="<?php echo esc_attr($api_url); ?>"
         data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
         data-back-url="<?php echo esc_attr($back_url); ?>"
         data-order-id="<?php echo (int)$order_id; ?>"
    >
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

    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/ops/driver-view-order/driver-view-order-script.js'); ?>?v=<?php echo esc_attr($ver); ?>" defer></script>

    <?php
    return ob_get_clean();
});