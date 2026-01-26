<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Orders UI (Shortcode)
 * Shortcode: [knx_ops_orders]
 *
 * Renders OPS dashboard for managers/super_admins:
 * - Orders list (v2)
 * - Assign/Unassign
 * - Cancel
 * - Force status (super_admin only; backend enforces)
 *
 * Notes:
 * - Assets are injected via echo (no wp_footer dependency).
 * - JS reads config from window.KNX_OPS_CONFIG.
 * ==========================================================
 */

add_shortcode('knx_ops_orders', function ($atts = []) {

    if (!function_exists('knx_get_session')) {
        return '<div class="knx-ops-wrap"><div class="knx-ops-empty">OPS unavailable (session missing).</div></div>';
    }

    $session = knx_get_session();
    $role = $session && isset($session->role) ? (string)$session->role : '';
    if (!in_array($role, ['super_admin', 'manager'], true)) {
        return '<div class="knx-ops-wrap"><div class="knx-ops-empty">Forbidden.</div></div>';
    }

    $ver = defined('KNX_VERSION') ? KNX_VERSION : time();
    $css_url = defined('KNX_URL') ? KNX_URL . 'inc/modules/ops/ops-style.css?ver=' . rawurlencode($ver) : '';
    $js_url  = defined('KNX_URL') ? KNX_URL . 'inc/modules/ops/ops-script.js?ver=' . rawurlencode($ver) : '';

    $config = [
        'restBase' => esc_url_raw(rest_url()),
        'endpoints' => [
            'ordersList'  => esc_url_raw(rest_url('knx/v2/ops/orders/list')),
            'assign'      => esc_url_raw(rest_url('knx/v2/ops/orders/assign')),
            'unassign'    => esc_url_raw(rest_url('knx/v2/ops/orders/unassign')),
            'cancel'      => esc_url_raw(rest_url('knx/v2/ops/orders/cancel')),
            'forceStatus' => esc_url_raw(rest_url('knx/v2/ops/orders/force-status')),
            'drivers'     => esc_url_raw(rest_url('knx/v2/ops/drivers/active')),
        ],
        'nonces' => [
            'assign'      => wp_create_nonce('knx_ops_assign_driver_nonce'),
            'unassign'    => wp_create_nonce('knx_ops_unassign_driver_nonce'),
            'cancel'      => wp_create_nonce('knx_ops_cancel_order_nonce'),
            'forceStatus' => wp_create_nonce('knx_ops_force_status_nonce'),
        ],
        'actor' => [
            'role' => $role,
            'user_id' => $session && isset($session->user_id) ? (int)$session->user_id : 0,
        ],
        'ui' => [
            'pollMs' => 8000,
            'perPage' => 20,
        ],
    ];

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo esc_attr($css_url); ?>" />
    <div class="knx-ops-wrap" id="knxOpsRoot" data-knx-ops="1">
        <div class="knx-ops-topbar">
            <div class="knx-ops-title">
                <div class="knx-ops-h1">OPS</div>
                <div class="knx-ops-sub">Orders • Assignments • Delivery pipeline</div>
            </div>

            <div class="knx-ops-actions">
                <button class="knx-btn knx-btn-ghost" type="button" id="knxOpsRefreshBtn">
                    <i class="fa-solid fa-rotate"></i>
                    Refresh
                </button>
                <button class="knx-btn" type="button" id="knxOpsAutoBtn" data-state="on">
                    <i class="fa-solid fa-bolt"></i>
                    Live: ON
                </button>
            </div>
        </div>

        <div class="knx-ops-filters">
            <div class="knx-field">
                <label>Status</label>
                <select id="knxOpsStatus">
                    <option value="">All</option>
                    <option value="placed">placed</option>
                    <option value="confirmed">confirmed</option>
                    <option value="preparing">preparing</option>
                    <option value="ready">ready</option>
                    <option value="out_for_delivery">out_for_delivery</option>
                    <option value="completed">completed</option>
                    <option value="cancelled">cancelled</option>
                </select>
            </div>

            <div class="knx-field">
                <label>OPS</label>
                <select id="knxOpsOpsStatus">
                    <option value="">All</option>
                    <option value="unassigned">unassigned</option>
                    <option value="assigned">assigned</option>
                    <option value="picked_up">picked_up</option>
                    <option value="delivered">delivered</option>
                </select>
            </div>

            <div class="knx-field knx-grow">
                <label>Search order #</label>
                <input id="knxOpsSearch" type="text" inputmode="numeric" placeholder="e.g. 1" />
            </div>
        </div>

        <div class="knx-ops-content">
            <div class="knx-ops-tableWrap">
                <table class="knx-ops-table" id="knxOpsTable">
                    <thead>
                    <tr>
                        <th>Order</th>
                        <th>Order Status</th>
                        <th>OPS</th>
                        <th>Hub</th>
                        <th>Total</th>
                        <th>Driver</th>
                        <th>Updated</th>
                        <th class="knx-ops-col-actions">Actions</th>
                    </tr>
                    </thead>
                    <tbody id="knxOpsTbody">
                    <tr><td colspan="8" class="knx-ops-loadingCell">Loading…</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="knx-ops-cards" id="knxOpsCards"></div>

            <div class="knx-ops-footer">
                <div class="knx-ops-pager">
                    <button class="knx-btn knx-btn-ghost" type="button" id="knxOpsPrev">Prev</button>
                    <div class="knx-ops-page" id="knxOpsPageLabel">Page 1</div>
                    <button class="knx-btn knx-btn-ghost" type="button" id="knxOpsNext">Next</button>
                </div>
                <div class="knx-ops-meta" id="knxOpsMeta"></div>
            </div>
        </div>

        <div class="knx-ops-toast" id="knxOpsToast" aria-live="polite" aria-atomic="true"></div>
    </div>

    <script>
        window.KNX_OPS_CONFIG = <?php echo wp_json_encode($config); ?>;
    </script>
    <script src="<?php echo esc_attr($js_url); ?>"></script>
    <?php
    return ob_get_clean();
});
