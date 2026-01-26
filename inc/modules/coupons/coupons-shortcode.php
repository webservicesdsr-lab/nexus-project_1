<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Coupons Admin UI (v1.0 Nexus Definitive CRUD)
 * ----------------------------------------------------------
 * Shortcode: [knx_coupons_admin]
 * - Desktop: table layout
 * - Mobile: cards layout (CSS switch)
 * - Modal Add/Edit (REST create/update)
 * - Toggle status (REST toggle)
 * - Search + Status filter + Pagination
 * - Plugin-first assets (KNX_URL)
 * - No enqueues (injects <link>/<script>)
 * ==========================================================
 */

add_shortcode('knx_coupons_admin', function () {

    // Defensive session guard (do not fatal if session system isn't loaded).
    if (!function_exists('knx_get_session')) {
        return '<div style="padding:16px;background:#fff;border:1px solid #e2e5e8;border-radius:12px;">
            <strong>Coupons UI unavailable:</strong> knx_get_session() not found.
        </div>';
    }

    $session = knx_get_session();
    if (!$session || !in_array($session->role, ['manager', 'super_admin'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // REST endpoints (v2)
    $api_list   = rest_url('knx/v2/coupons/list');
    $api_create = rest_url('knx/v2/coupons/create');
    $api_update = rest_url('knx/v2/coupons/update');
    $api_toggle = rest_url('knx/v2/coupons/toggle');

    // Canon nonce used across Nexus UIs
    $nonce = wp_create_nonce('knx_nonce');

    // Assets (plugin-first)
    $css_url = KNX_URL . 'inc/modules/coupons/coupons-style.css?v=' . rawurlencode(KNX_VERSION);
    $js_url  = KNX_URL . 'inc/modules/coupons/coupons-script.js?v=' . rawurlencode(KNX_VERSION);

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">

    <div class="knx-coupons-wrapper knx-admin-page"
         data-api-list="<?php echo esc_url($api_list); ?>"
         data-api-create="<?php echo esc_url($api_create); ?>"
         data-api-update="<?php echo esc_url($api_update); ?>"
         data-api-toggle="<?php echo esc_url($api_toggle); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>">

        <div class="knx-coupons-header">
            <h2>
                <i class="fas fa-ticket-alt"></i>
                Coupons Management
            </h2>

            <div class="knx-coupons-controls">
                <div class="knx-search-form">
                    <input type="text" id="knxCouponsSearchInput" placeholder="Search code...">
                    <button type="button" id="knxCouponsSearchBtn" title="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>

                <select id="knxCouponsStatusFilter" class="knx-status-filter" aria-label="Status Filter">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <button type="button" id="knxCouponsAddBtn" class="knx-add-btn">
                    <i class="fas fa-plus"></i> Add Coupon
                </button>
            </div>
        </div>

        <!-- Desktop: Table -->
        <table class="knx-coupons-table" aria-label="Coupons Table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Type</th>
                    <th>Value</th>
                    <th>Min</th>
                    <th>Starts</th>
                    <th>Expires</th>
                    <th>Limit</th>
                    <th>Used</th>
                    <th>Status</th>
                    <th class="knx-col-center">Edit</th>
                    <th class="knx-col-center">Toggle</th>
                </tr>
            </thead>
            <tbody id="knxCouponsTableBody">
                <tr><td colspan="11" style="text-align:center;">Loading...</td></tr>
            </tbody>
        </table>

        <!-- Mobile: Cards -->
        <div class="knx-coupons-cards" id="knxCouponsCards" aria-label="Coupons Cards">
            <!-- JS renders -->
        </div>

        <!-- Pagination -->
        <div class="knx-pagination" id="knxCouponsPagination"></div>
    </div>

    <!-- Modal: Add/Edit Coupon -->
    <div id="knxCouponModal" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-content knx-modal-content--wide">
            <h3 id="knxCouponModalTitle">Add Coupon</h3>

            <form id="knxCouponForm">
                <input type="hidden" id="knxCouponId" value="">

                <div class="knx-form-grid">
                    <div class="knx-field">
                        <label class="knx-label" for="knxCouponCode">Code *</label>
                        <input type="text" id="knxCouponCode" placeholder="SAVE10" maxlength="50" required>
                        <small class="knx-hint">Uppercase recommended. Letters/numbers only.</small>
                    </div>

                    <div class="knx-field">
                        <label class="knx-label" for="knxCouponStatus">Status</label>
                        <select id="knxCouponStatus" aria-label="Coupon Status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="knx-field">
                        <label class="knx-label" for="knxCouponType">Type *</label>
                        <select id="knxCouponType" required>
                            <option value="percent">Percent (%)</option>
                            <option value="fixed">Fixed ($)</option>
                        </select>
                    </div>

                    <div class="knx-field">
                        <label class="knx-label" for="knxCouponValue">Value *</label>
                        <input type="number" id="knxCouponValue" step="0.01" min="0" required>
                        <small id="knxCouponValueHint" class="knx-hint">0–100 for percent</small>
                    </div>

                    <div class="knx-field">
                        <label class="knx-label" for="knxCouponMinSubtotal">Min Subtotal</label>
                        <input type="number" id="knxCouponMinSubtotal" step="0.01" min="0" placeholder="Optional">
                    </div>

                    <div class="knx-field">
                        <label class="knx-label" for="knxCouponUsageLimit">Usage Limit</label>
                        <input type="number" id="knxCouponUsageLimit" step="1" min="0" placeholder="Optional">
                        <small class="knx-hint">Empty = unlimited</small>
                    </div>

                    <div class="knx-field">
                        <label class="knx-label" for="knxCouponStartsAt">Starts At</label>
                        <input type="datetime-local" id="knxCouponStartsAt">
                    </div>

                    <div class="knx-field">
                        <label class="knx-label" for="knxCouponExpiresAt">Expires At</label>
                        <input type="datetime-local" id="knxCouponExpiresAt">
                    </div>
                </div>

                <button type="submit" class="knx-btn" id="knxCouponSaveBtn">Save</button>
                <button type="button" id="knxCouponCancelBtn" class="knx-btn-secondary">Cancel</button>
            </form>
        </div>
    </div>

    <script src="<?php echo esc_url($js_url); ?>"></script>

    <?php
    return ob_get_clean();
});
