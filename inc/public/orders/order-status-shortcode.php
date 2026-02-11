<?php
if (!defined('ABSPATH')) exit;

add_shortcode('knx_order_status', 'knx_render_order_status_page');

function knx_render_order_status_page($atts = array()) {
    // Minimal order status page: container + assets (JS handles fetch + render)
    $out = '';
    $out .= '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/orders/order-status.css?v=' . KNX_VERSION) . '">';
    $out .= '<script src="' . esc_url(KNX_URL . 'inc/public/orders/order-status.js?v=' . KNX_VERSION) . '" defer></script>';

    $out .= '<div id="knx-order-status" class="knx-order-status">';
    $out .= '  <div id="knxOrderStatusContent">';
    $out .= '    <h2>Order Status</h2>';
    $out .= '    <div id="knxOrderStatusBox">Loading order details…</div>';
    $out .= '  </div>';
    $out .= '</div>';

    return $out;
}
