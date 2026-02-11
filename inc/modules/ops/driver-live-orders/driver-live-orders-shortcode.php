<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX — Driver Active Orders (Detail UI)
 * Shortcode: [knx_driver_active_orders]
 *
 * Rules:
 * - Snapshot v5 is the source of truth for UI content.
 * - No wp_footer usage; assets injected inline.
 * - Only two modals: Status update + Release order.
 * ==========================================================
 */

add_shortcode('knx_driver_active_orders', function () {

    if (!function_exists('knx_get_driver_context')) {
        return '<div class="knx-dao__empty"><strong>Driver context unavailable.</strong></div>';
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || !is_object($ctx) || empty($ctx->session) || !is_object($ctx->session) || empty($ctx->session->user_id)) {
        return '<div class="knx-dao__empty"><strong>Unauthorized.</strong></div>';
    }

    $role = !empty($ctx->session->role) ? (string) $ctx->session->role : '';
    if (!in_array($role, array('driver', 'super_admin', 'manager'), true)) {
        return '<div class="knx-dao__empty"><strong>Forbidden.</strong></div>';
    }

    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

    // Endpoints (kept configurable via dataset; frontend must not hardcode)
    $api_detail = '/wp-json/knx/v1/ops/driver-active-orders';
    // This should match the same base used by driver-ops accept: ".../knx/v2/driver/orders/"
    $api_base_v2 = '/wp-json/knx/v2/driver/orders/';

    // Back destination (Available orders reminder)
    $back_url = '/driver-live-orders';

    // Nonces
    $wp_rest_nonce = wp_create_nonce('wp_rest');

    // Best-effort KNX nonce (if your session provides it)
    $knx_nonce = '';
    if (!empty($ctx->session->knx_nonce)) $knx_nonce = (string) $ctx->session->knx_nonce;
    else if (!empty($ctx->session->nonce)) $knx_nonce = (string) $ctx->session->nonce;

    ob_start();
    ?>
    <div
        id="knx-driver-active-order"
        class="knx-dao"
        data-order-id="<?php echo esc_attr($order_id); ?>"
        data-api-detail="<?php echo esc_attr($api_detail); ?>"
        data-api-base-v2="<?php echo esc_attr($api_base_v2); ?>"
        data-back-url="<?php echo esc_attr($back_url); ?>"
        data-wp-rest-nonce="<?php echo esc_attr($wp_rest_nonce); ?>"
        data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
    >
        <!-- Top back -->
        <div class="knx-dao__top">
            <a class="knx-dao__back" href="<?php echo esc_attr($back_url); ?>">
                <span class="knx-dao__back-ico" aria-hidden="true">←</span>
                <span>Back to Available</span>
            </a>
        </div>

        <!-- Status card -->
        <button type="button" class="knx-dao__status" id="knxDaoStatusCard" aria-label="Update order status">
            <div class="knx-dao__status-left">
                <div class="knx-dao__status-label">ORDER STATUS</div>
                <div class="knx-dao__status-value" id="knxDaoStatusValue">Loading…</div>
            </div>
            <div class="knx-dao__status-right" aria-hidden="true">
                <span class="knx-dao__chev">▾</span>
            </div>
        </button>

        <!-- Main card -->
        <div class="knx-dao__card" id="knxDaoCard" aria-busy="true">
            <div class="knx-dao__card-title">YOUR ACTIVE ORDER</div>

            <div class="knx-dao__totals">
                <div class="knx-dao__total" id="knxDaoTotal">$—</div>
                <div class="knx-dao__tips">
                    <div class="knx-dao__tips-label">Tips</div>
                    <div class="knx-dao__tips-value" id="knxDaoTips">$—</div>
                </div>
            </div>

            <div class="knx-dao__restaurant" id="knxDaoRestaurant">—</div>
            <div class="knx-dao__orderid">Order ID: <strong id="knxDaoOrderNumber">—</strong></div>

            <div class="knx-dao__hr"></div>

            <div class="knx-dao__section">ORDER ITEMS</div>
            <div class="knx-dao__items" id="knxDaoItems"></div>

            <div class="knx-dao__hr"></div>

            <div class="knx-dao__locs">
                <div class="knx-dao__loc">
                    <div class="knx-dao__loc-ico knx-dao__loc-ico--pickup" aria-hidden="true">
                        <!-- Map pin -->
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path d="M12 22s7-4.5 7-12a7 7 0 1 0-14 0c0 7.5 7 12 7 12Z" stroke="currentColor" stroke-width="2"/>
                            <circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="knx-dao__loc-body">
                        <div class="knx-dao__loc-label">PICKUP LOCATION</div>
                        <div class="knx-dao__loc-text" id="knxDaoPickup">—</div>
                    </div>
                </div>

                <div class="knx-dao__loc">
                    <div class="knx-dao__loc-ico knx-dao__loc-ico--delivery" aria-hidden="true">
                        <!-- Location -->
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path d="M12 22s7-4.5 7-12a7 7 0 1 0-14 0c0 7.5 7 12 7 12Z" stroke="currentColor" stroke-width="2"/>
                            <circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="knx-dao__loc-body">
                        <div class="knx-dao__loc-label">DELIVERY LOCATION</div>
                        <div class="knx-dao__loc-text" id="knxDaoDelivery">—</div>
                        <div class="knx-dao__customer" id="knxDaoCustomerLine">Customer: —</div>
                    </div>
                </div>
            </div>

            <div class="knx-dao__hr"></div>

            <div class="knx-dao__distance">
                <strong id="knxDaoDistanceVal">—</strong>
                <span>distance</span>
            </div>

            <div class="knx-dao__actions">
                <a class="knx-dao__btn knx-dao__btn--green" id="knxDaoNavigate" href="#" target="_blank" rel="noopener">
                    <span class="knx-dao__btn-ico" aria-hidden="true">
                        <!-- Paper plane -->
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path d="M22 2 11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M22 2 15 22l-4-9-9-4 20-7Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span>Navigate</span>
                </a>

                <a class="knx-dao__btn knx-dao__btn--green" id="knxDaoCustomer" href="#">
                    <span class="knx-dao__btn-ico" aria-hidden="true">
                        <!-- Phone -->
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.86.3 1.7.54 2.5a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.58-1.11a2 2 0 0 1 2.11-.45c.8.24 1.64.42 2.5.54A2 2 0 0 1 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span>Customer</span>
                </a>
            </div>
        </div>

        <!-- Release -->
        <button type="button" class="knx-dao__release" id="knxDaoReleaseBtn">
            <span class="knx-dao__release-ico" aria-hidden="true">⚠️</span>
            <span>Release Order</span>
        </button>

        <!-- Empty/Error state -->
        <div class="knx-dao__empty" id="knxDaoEmpty" hidden></div>

        <!-- Toast container -->
        <div class="knx-dao__toast" id="knxDaoToast" aria-live="polite" aria-atomic="true"></div>

        <!-- STATUS MODAL (allowed) -->
        <div class="knx-dao__modal" id="knxDaoStatusModal" aria-hidden="true">
            <div class="knx-dao__modal-dialog" role="dialog" aria-modal="true" aria-labelledby="knxDaoStatusTitle">
                <div class="knx-dao__modal-head">
                    <div>
                        <div class="knx-dao__modal-title" id="knxDaoStatusTitle">Update Order Status</div>
                        <div class="knx-dao__modal-sub">Choose the current status of this order</div>
                    </div>
                    <button type="button" class="knx-dao__modal-x" data-close aria-label="Close">×</button>
                </div>

                <div class="knx-dao__modal-divider"></div>

                <div class="knx-dao__status-list" id="knxDaoStatusList">
                    <!-- Filled by JS (1:1 cards) -->
                </div>

                <div class="knx-dao__status-warn" id="knxDaoCancelWarn" hidden>
                    This action requires confirmation.
                </div>

                <div class="knx-dao__modal-foot">
                    <button type="button" class="knx-dao__btn knx-dao__btn--ghost" data-close>Cancel</button>
                    <button type="button" class="knx-dao__btn knx-dao__btn--solid" id="knxDaoUpdateStatusBtn">Update</button>
                </div>
            </div>
        </div>

        <!-- RELEASE MODAL (allowed) -->
        <div class="knx-dao__modal" id="knxDaoReleaseModal" aria-hidden="true">
            <div class="knx-dao__modal-dialog" role="dialog" aria-modal="true" aria-labelledby="knxDaoReleaseTitle">
                <div class="knx-dao__modal-head">
                    <div>
                        <div class="knx-dao__modal-title" id="knxDaoReleaseTitle">Release Order</div>
                        <div class="knx-dao__modal-sub">This will remove the order from your active queue.</div>
                    </div>
                    <button type="button" class="knx-dao__modal-x" data-close aria-label="Close">×</button>
                </div>

                <div class="knx-dao__modal-divider"></div>

                <div class="knx-dao__release-body">
                    Are you sure you want to release this order?
                </div>

                <div class="knx-dao__modal-foot">
                    <button type="button" class="knx-dao__btn knx-dao__btn--ghost" data-close>Cancel</button>
                    <button type="button" class="knx-dao__btn knx-dao__btn--danger" id="knxDaoConfirmReleaseBtn">Release</button>
                </div>
            </div>
        </div>
    </div>
    <?php

    // Inject CSS
    $css_path = __DIR__ . '/driver-active-orders-style.css';
    if (file_exists($css_path)) {
        echo '<style>' . file_get_contents($css_path) . '</style>';
    }

    // Inject JS
    $js_path = __DIR__ . '/driver-active-orders-script.js';
    if (file_exists($js_path)) {
        echo '<script>' . file_get_contents($js_path) . '</script>';
    }

    return ob_get_clean();
});
