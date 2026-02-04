<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Driver OPS Dashboard (Shortcode) (v1.0)
 * Shortcode: [knx_driver_ops_dashboard]
 * ----------------------------------------------------------
 * Notes:
 * - Assets injected via <link>/<script> (no wp_footer, no enqueue).
 * - UI is NON-authoritative; all actions via REST.
 * - Uses:
 *   GET  /knx/v1/ops/driver-available-orders (snapshot-sealed pickup addresses)
 *   POST /knx/v2/driver/orders/{id}/assign
 * ==========================================================
 */

add_shortcode('knx_driver_ops_dashboard', function () {

    // Prefer strict driver context if available
    if (function_exists('knx_get_driver_context')) {
        $ctx = knx_get_driver_context();
        if (empty($ctx) || empty($ctx->session) || empty($ctx->session->user_id)) {
            wp_safe_redirect(site_url('/login'));
            exit;
        }
    } else {
        // Fallback to session role check (best-effort)
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

    // Get driver name for personalized greeting
    $driver_name = '';
    if (isset($ctx->session->username)) {
        $driver_name = $ctx->session->username;
    } elseif (isset($session->username)) {
        $driver_name = $session->username;
    }

    // Nonces
    $knx_nonce     = wp_create_nonce('knx_nonce');
    $wp_rest_nonce = wp_create_nonce('wp_rest');

    // API URLs (snapshot-sealed pickup addresses)
    $api_available = rest_url('knx/v1/ops/driver-available-orders');
    $api_base      = rest_url('knx/v2/driver/orders/'); // {base}{id}/assign

    // Asset URLs
    $ver = defined('KNX_VERSION') ? KNX_VERSION : '1.0';
    $plugin_root = dirname(__FILE__, 4); // .../plugin-root
    $base_url = defined('KNX_URL') ? KNX_URL : plugin_dir_url($plugin_root . '/kingdom-nexus.php');

    // Correct asset paths (module is under inc/modules/ops/driver-ops)
    $css_url = esc_url($base_url . 'inc/modules/ops/driver-ops/driver-ops-style.css?v=' . $ver);
    $js_url  = esc_url($base_url . 'inc/modules/ops/driver-ops/driver-ops-script.js?v=' . $ver);

    // Global toast system (shared UI component)
    $toast_css_url = esc_url($base_url . 'inc/modules/core/knx-toast.css?v=' . $ver);
    $toast_js_url  = esc_url($base_url . 'inc/modules/core/knx-toast.js?v=' . $ver);

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo $toast_css_url; ?>">
    <link rel="stylesheet" href="<?php echo $css_url; ?>">

    <div id="knx-driver-ops-dashboard"
         class="knx-driver-ops-wrapper"
         data-api-available="<?php echo esc_url($api_available); ?>"
         data-api-base="<?php echo esc_url($api_base); ?>"
         data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
         data-wp-rest-nonce="<?php echo esc_attr($wp_rest_nonce); ?>">

        <div class="knx-driver-ops-header">
            <div class="knx-driver-ops-title">
                <h2>Hi<?php echo $driver_name ? ', ' . esc_html($driver_name) : ''; ?>! 👋</h2>
            </div>

            <div class="knx-driver-ops-controls">
                <button type="button" class="knx-btn-icon" id="knxDriverOpsSearchToggle" title="Search" aria-label="Toggle search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                </button>

                <button type="button" class="knx-btn-secondary" id="knxDriverOpsRefresh" title="Refresh">
                    ↻
                </button>

                <div class="knx-live">
                    <label class="knx-live-label" for="knxDriverOpsLive">Live</label>
                    <label class="knx-switch" aria-label="Toggle live refresh">
                        <input id="knxDriverOpsLive" type="checkbox" checked>
                        <span class="knx-slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="knx-search-container" id="knxSearchContainer" style="display:none;">
            <label class="sr-only" for="knxDriverOpsSearch">Search</label>
            <input id="knxDriverOpsSearch" class="knx-input" type="text" inputmode="search"
                   placeholder="Search by order # or address…" autocomplete="off">
        </div>

        <button type="button" class="knx-today-orders-bar" id="knxViewPastOrders">
            Today's Orders
        </button>

        <div id="knxDriverOpsList" class="knx-driver-ops-list" aria-label="Available orders list">
            <div class="knx-empty">Loading available orders…</div>
        </div>
    </div>

    <!-- Order Details Modal (canon) -->
    <div id="knxDriverOpsOrderModal" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-content" role="dialog" aria-modal="true" aria-labelledby="knxDriverOpsOrderTitle">
            <div class="knx-modal-head">
                <h3 id="knxDriverOpsOrderTitle">Order</h3>
                <button type="button" class="knx-modal-x" aria-label="Close">✕</button>
            </div>

            <div class="knx-modal-body" id="knxDriverOpsOrderBody">
                <!-- filled by JS -->
            </div>

            <div class="knx-modal-actions">
                <button type="button" class="knx-btn-secondary knx-modal-cancel">Close</button>
                <button type="button" class="knx-btn knx-modal-accept">Accept Order</button>
            </div>
        </div>
    </div>

    <!-- Confirm Accept Modal (canon confirm dialog) -->
    <div id="knxDriverOpsConfirm" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-content knx-confirm" role="dialog" aria-modal="true" aria-labelledby="knxDriverOpsConfirmTitle">
            <div class="knx-modal-head">
                <h3 id="knxDriverOpsConfirmTitle">Accept Order</h3>
                <button type="button" class="knx-modal-x" aria-label="Close">✕</button>
            </div>
            <p class="knx-confirm-text">You’ll be assigned to this order. Continue?</p>
            <div class="knx-modal-actions">
                <button type="button" class="knx-btn-secondary knx-confirm-cancel">Cancel</button>
                <button type="button" class="knx-btn knx-confirm-ok">Accept</button>
            </div>
        </div>
    </div>

    <script>
      window.KNX_DRIVER_OPS_CONFIG = {
        apiAvailable: <?php echo wp_json_encode($api_available); ?>,
        apiBase: <?php echo wp_json_encode($api_base); ?>,
        knxNonce: <?php echo wp_json_encode($knx_nonce); ?>,
        wpRestNonce: <?php echo wp_json_encode($wp_rest_nonce); ?>,
        pollMs: 15000
      };
    </script>

    <script src="<?php echo $toast_js_url; ?>"></script>
    <script src="<?php echo $js_url; ?>"></script>
    <?php
    return ob_get_clean();
});
