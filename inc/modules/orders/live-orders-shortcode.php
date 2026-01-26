<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — OPS Live Orders Dashboard (MVP v1.0)
 * Shortcode: [knx_live_orders_admin]
 * Access: super_admin, manager
 *
 * PURPOSE:
 * - Ops inbox-style live list (polling)
 * - Separate push channel: audience = "ops_orders" (NO driver mixing)
 * - No wp_footer dependency
 * - Inline asset injection (no enqueues required here)
 * ==========================================================
 */

add_shortcode('knx_live_orders_admin', function () {

    if (!function_exists('knx_get_session')) {
        return '<div style="padding:16px;background:#fff;border:1px solid #e2e5e8;border-radius:12px;">
            <strong>Live Orders UI unavailable:</strong> knx_get_session() not found.
        </div>';
    }

    $session = knx_get_session();
    $role = (string) ($session->role ?? '');

    if (!$session || !in_array($role, ['manager', 'super_admin'], true)) {
        $login = site_url('/login');
        if (!headers_sent()) {
            wp_safe_redirect($login);
            exit;
        }
        return '<div style="padding:24px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">
            <h2>Access required</h2>
            <p>Please log in to continue.</p>
            <p><a href="' . esc_url($login) . '">Go to Login</a></p>
        </div>';
    }

    // OPS v2 endpoints (canonical for this dashboard)
    $api_live        = rest_url('knx/v2/ops/orders/live');          // NEW: ops inbox list
    $api_subscribe   = rest_url('knx/v2/ops/push/subscribe');       // NEW: ops push subscribe (audience=ops_orders)
    $api_unsubscribe = rest_url('knx/v2/ops/push/unsubscribe');     // NEW: ops push unsubscribe

    // Assets
    $css = KNX_URL . 'inc/modules/orders/live-orders-style.css?v=' . rawurlencode(KNX_VERSION);
    $js  = KNX_URL . 'inc/modules/orders/live-orders-script.js?v=' . rawurlencode(KNX_VERSION);

    // VAPID public key (from your push system, if exists)
    $vapid = function_exists('knx_push_vapid_public_key') ? (string) knx_push_vapid_public_key() : '';

    // Dashboard config
    $poll_ms = 5000; // base polling
    $audience = 'ops_orders';

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url($css); ?>">

    <div class="knx-live-orders-wrapper knx-admin-page"
         data-api-live="<?php echo esc_url($api_live); ?>"
         data-api-subscribe="<?php echo esc_url($api_subscribe); ?>"
         data-api-unsubscribe="<?php echo esc_url($api_unsubscribe); ?>"
         data-vapid="<?php echo esc_attr($vapid); ?>"
         data-poll-ms="<?php echo esc_attr((string) $poll_ms); ?>"
         data-audience="<?php echo esc_attr($audience); ?>">

        <div class="knx-live-header">
            <div class="knx-live-title">
                <i class="fas fa-bolt"></i>
                <h2>OPS Live Orders</h2>
                <span class="knx-live-badge">MVP</span>
            </div>

            <div class="knx-live-controls">
                <button id="knxOpsRefreshBtn" class="knx-btn knx-btn--ghost" type="button">
                    <i class="fas fa-rotate"></i> Refresh
                </button>

                <button id="knxOpsPollingBtn" class="knx-btn knx-btn--ghost" type="button" aria-pressed="true">
                    <i class="fas fa-signal"></i> Live: ON
                </button>

                <button id="knxOpsPushBtn" class="knx-btn knx-btn--primary" type="button">
                    <i class="fas fa-bell"></i> Enable Alerts
                </button>

                <button id="knxOpsTestBtn" class="knx-btn knx-btn--ghost" type="button">
                    <i class="fas fa-message"></i> Test
                </button>
            </div>
        </div>

        <div class="knx-live-subrow">
            <div class="knx-live-meta">
                <span class="knx-pill" id="knxOpsConnPill">Connecting…</span>
                <span class="knx-pill knx-pill--muted" id="knxOpsUpdatedPill">Last update: —</span>
            </div>
        </div>

        <div id="knxOpsOrdersList" class="knx-live-list" aria-live="polite">
            <div class="knx-loading">Loading orders…</div>
        </div>

        <noscript>
            <div class="knx-error">This page requires JavaScript to show live orders.</div>
        </noscript>

    </div>

    <script src="<?php echo esc_url($js); ?>" defer></script>

    <?php
    return ob_get_clean();
});
