<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Customers Admin UI (v1.0 Cities/Hubs UX)
 * ----------------------------------------------------------
 * Shortcode: [knx_customers_admin]
 * - Desktop: table layout
 * - Mobile: cards layout (CSS switch)
 * - Modal Add/Edit (REST create/update)
 * - Toggle status (REST toggle)
 * - Search + Status filter + Pagination
 * - Plugin-first assets (KNX_URL)
 * - No enqueues (injects <link>/<script>)
 * ==========================================================
 */

add_shortcode('knx_customers_admin', function () {

    // Defensive session guard (do not fatal if session system isn't loaded).
    if (!function_exists('knx_get_session')) {
        return '<div style="padding:16px;background:#fff;border:1px solid #e2e5e8;border-radius:12px;">
            <strong>Customers UI unavailable:</strong> knx_get_session() not found.
        </div>';
    }

    $session = knx_get_session();
    if (!$session || !in_array($session->role, ['manager', 'super_admin'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // REST endpoints (v2)
    $api_list   = rest_url('knx/v2/customers/list');
    $api_create = rest_url('knx/v2/customers/create');
    $api_update = rest_url('knx/v2/customers/update');
    $api_toggle = rest_url('knx/v2/customers/toggle');

    // Canon nonce used across Nexus UIs
    $nonce = wp_create_nonce('knx_nonce');

    // Assets (plugin-first)
    $css_url = KNX_URL . 'inc/modules/customers/customers-style.css?v=' . rawurlencode(KNX_VERSION);
    $js_url  = KNX_URL . 'inc/modules/customers/customers-script.js?v=' . rawurlencode(KNX_VERSION);

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">

    <div class="knx-customers-wrapper knx-admin-page"
         data-api-list="<?php echo esc_url($api_list); ?>"
         data-api-create="<?php echo esc_url($api_create); ?>"
         data-api-update="<?php echo esc_url($api_update); ?>"
         data-api-toggle="<?php echo esc_url($api_toggle); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>">

        <div class="knx-customers-header">
            <h2>
                <i class="fas fa-users"></i>
                Customers Management
            </h2>

            <div class="knx-customers-controls">
                <div class="knx-search-form">
                    <input type="text" id="knxCustomersSearchInput" placeholder="Search name, email, phone...">
                    <button type="button" id="knxCustomersSearchBtn" title="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>

                <select id="knxCustomersStatusFilter" class="knx-status-filter" aria-label="Status Filter">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <button type="button" id="knxCustomersAddBtn" class="knx-add-btn">
                    <i class="fas fa-plus"></i> Add Customer
                </button>
            </div>
        </div>

        <!-- Desktop: Table -->
        <table class="knx-customers-table" aria-label="Customers Table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th class="knx-col-center">Edit</th>
                    <th class="knx-col-center">Toggle</th>
                </tr>
            </thead>
            <tbody id="knxCustomersTableBody">
                <tr><td colspan="6" style="text-align:center;">Loading...</td></tr>
            </tbody>
        </table>

        <!-- Mobile: Cards -->
        <div class="knx-customers-cards" id="knxCustomersCards" aria-label="Customers Cards">
            <!-- JS renders -->
        </div>

        <!-- Pagination -->
        <div class="knx-pagination" id="knxCustomersPagination"></div>
    </div>

    <!-- Modal: Add/Edit Customer -->
    <div id="knxCustomerModal" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-content">
            <h3 id="knxCustomerModalTitle">Add Customer</h3>

            <form id="knxCustomerForm">
                <input type="hidden" id="knxCustomerId" value="">

                <input type="text" id="knxCustomerName" placeholder="Full Name" required>
                <input type="email" id="knxCustomerEmail" placeholder="Email" required>
                <input type="tel" id="knxCustomerPhone" placeholder="Phone" required>

                <input type="password" id="knxCustomerPassword" placeholder="Password (optional)">
                <small class="knx-hint">
                    Leave blank to keep current password (edit) or auto-generate (new).
                </small>

                <select id="knxCustomerStatus" aria-label="Customer Status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <button type="submit" class="knx-btn" id="knxCustomerSaveBtn">Save</button>
                <button type="button" id="knxCustomerCancelBtn" class="knx-btn-secondary">Cancel</button>
            </form>
        </div>
    </div>

    <script src="<?php echo esc_url($js_url); ?>"></script>

    <?php
    return ob_get_clean();
});
