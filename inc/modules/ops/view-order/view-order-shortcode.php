<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — View Order Shortcode
 * Shortcode: [knx_ops_view_order]
 *
 * Notes:
 * - Assets are injected via echo/link/script (no wp_footer dependency).
 * - Reads order_id from query string (?order_id=123).
 * ==========================================================
 */

add_shortcode('knx-view-orders', function () {
    // Require session + allowed roles
    if (!function_exists('knx_get_session')) {
        return '<div class="knx-ops-err">Session unavailable.</div>';
    }

    $session = knx_get_session();
    $role = $session && isset($session->role) ? (string)$session->role : '';

    if (!$session || !in_array($role, ['super_admin', 'manager'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

    $api_url  = esc_url(rest_url('knx/v1/ops/view-order'));
    $back_url = esc_url(site_url('/live-orders'));

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/ops/view-order/view-order-style.css'); ?>">

    <div id="knxOpsViewOrderApp"
         class="knx-ops-vo"
         data-api-url="<?php echo $api_url; ?>"
         data-order-id="<?php echo esc_attr($order_id); ?>"
         data-role="<?php echo esc_attr($role); ?>"
         data-back-url="<?php echo $back_url; ?>">

        <div class="knx-ops-vo__shell">
            <div class="knx-ops-vo__top">
                <a class="knx-ops-vo__back" href="<?php echo $back_url; ?>">&larr; Back to Live Orders</a>
                <div class="knx-ops-vo__title">
                    <h2>Order Details</h2>
                    <div id="knxOpsVOState" class="knx-ops-vo__state"><?php echo $order_id > 0 ? 'Loading…' : 'Missing order_id'; ?></div>
                </div>
            </div>

            <div id="knxOpsVOContent" class="knx-ops-vo__content"></div>
        </div>
    </div>

    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/ops/view-order/view-order-script.js'); ?>" defer></script>
    <?php
    return ob_get_clean();
});
