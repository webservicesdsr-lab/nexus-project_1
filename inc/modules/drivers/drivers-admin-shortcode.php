<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Drivers Admin UI (Responsive) v2.1
 * Shortcode: [knx_drivers_admin]
 * Access: super_admin, manager
 * ----------------------------------------------------------
 * - No wp_footer dependency
 * - Inline assets injection (no enqueues)
 * - Provides:
 *   - wp_rest nonce (for WP cookie auth): X-WP-Nonce
 *   - knx_nonce (for module write gate): body.knx_nonce
 * ==========================================================
 */

add_shortcode('knx_drivers_admin', function () {

    $session = function_exists('knx_get_session') ? knx_get_session() : false;
    $role    = (string)(($session->role ?? ''));

    if (!$session || !in_array($role, ['super_admin', 'manager'], true)) {
        $login = site_url('/login');
        if (!headers_sent()) { wp_safe_redirect($login); exit; }
        return '<div style="padding:24px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">
            <h2>Access required</h2>
            <p>Please log in to continue.</p>
            <p><a href="' . esc_url($login) . '">Go to Login</a></p>
        </div>';
    }

    $api_list   = rest_url('knx/v2/drivers/list');
    $api_create = rest_url('knx/v2/drivers/create');
    $api_base   = rest_url('knx/v2/drivers'); // append /{id}, /{id}/update, etc.

    $knx_nonce  = wp_create_nonce('knx_nonce');
    $wp_nonce   = wp_create_nonce('wp_rest');

    $css_rel  = 'inc/modules/drivers/drivers-admin-style.css';
    $js_rel   = 'inc/modules/drivers/drivers-admin-script.js';

    $css_path = (defined('KNX_PATH') ? KNX_PATH : plugin_dir_path(__FILE__) . '../../../') . $css_rel;
    $js_path  = (defined('KNX_PATH') ? KNX_PATH : plugin_dir_path(__FILE__) . '../../../') . $js_rel;

    $css_url  = (defined('KNX_URL') ? KNX_URL : plugin_dir_url(__FILE__) . '../../../') . $css_rel . '?v=' . (defined('KNX_VERSION') ? KNX_VERSION : time());
    $js_url   = (defined('KNX_URL') ? KNX_URL : plugin_dir_url(__FILE__) . '../../../') . $js_rel . '?v=' . (defined('KNX_VERSION') ? KNX_VERSION : time());

    ob_start(); ?>

    <?php if (file_exists($css_path)): ?>
        <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
    <?php endif; ?>

    <div class="knx-drivers-admin knx-drivers-signed"
         data-api-list="<?php echo esc_url($api_list); ?>"
         data-api-create="<?php echo esc_url($api_create); ?>"
         data-api-base="<?php echo esc_url($api_base); ?>"
         data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
         data-wp-nonce="<?php echo esc_attr($wp_nonce); ?>">

        <div class="knx-drivers-header">
            <div class="knx-drivers-title">
                <i class="fas fa-motorcycle"></i>
                <h2>Drivers</h2>
                <span class="knx-badge-sealed">CRUD v2.1</span>
            </div>

            <div class="knx-drivers-controls">
                <div class="knx-search">
                    <i class="fas fa-search"></i>
                    <input id="knxDriversSearch" type="text" placeholder="Search name, email, username, phone..." autocomplete="off">
                </div>

                <select id="knxDriversStatus" class="knx-select">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <button id="knxDriversAddBtn" class="knx-btn knx-btn--primary" type="button">
                    <i class="fas fa-plus"></i> Add Driver
                </button>
            </div>
        </div>

        <!-- Desktop -->
        <div class="knx-tablewrap" id="knxDriversTableWrap">
            <table class="knx-table" aria-label="Drivers table">
                <thead>
                    <tr>
                        <th>Driver</th>
                        <th>Login</th>
                        <th>Status</th>
                        <th class="knx-center">Toggle</th>
                        <th class="knx-center">Edit</th>
                        <th class="knx-center">Reset</th>
                    </tr>
                </thead>
                <tbody id="knxDriversTbody">
                    <tr><td colspan="6"><div class="knx-loading">Loading drivers...</div></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Mobile -->
        <div class="knx-cards" id="knxDriversCards"></div>

        <div class="knx-pagination" id="knxDriversPagination"></div>

        <noscript>
            <div class="knx-error-state">
                <i class="fas fa-exclamation-triangle"></i>
                <p>This page requires JavaScript.</p>
            </div>
        </noscript>
    </div>

    <!-- Add/Edit Modal -->
    <div id="knxDriverModal" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-backdrop" data-knx-close="1"></div>

        <div class="knx-modal-box" role="dialog" aria-modal="true" aria-labelledby="knxDriverModalTitle">
            <h3 id="knxDriverModalTitle">Add Driver</h3>
            <p class="knx-modal-sub">Create or update a driver account.</p>

            <form id="knxDriverForm">
                <input type="hidden" name="id" value="">

                <label class="knx-field">
                    <span>Full Name</span>
                    <input type="text" name="full_name" placeholder="e.g. Jeremy" required>
                </label>

                <label class="knx-field">
                    <span>Email</span>
                    <input type="email" name="email" placeholder="e.g. driver@email.com" required>
                </label>

                <label class="knx-field">
                    <span>Phone (optional)</span>
                    <input type="text" name="phone" placeholder="+1 ...">
                </label>

                <label class="knx-field">
                    <span>Vehicle Info (optional)</span>
                    <input type="text" name="vehicle_info" placeholder="e.g. Honda Civic / Plate...">
                </label>

                <label class="knx-field">
                    <span>Status</span>
                    <select name="status" class="knx-select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </label>

                <div class="knx-modal-actions">
                    <button type="button" id="knxDriverCancel" class="knx-btn knx-btn--ghost">Cancel</button>
                    <button type="submit" id="knxDriverSave" class="knx-btn knx-btn--primary">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Credentials Modal (shown after create/reset) -->
    <div id="knxDriverCredsModal" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-backdrop" data-knx-close="1"></div>

        <div class="knx-modal-box" role="dialog" aria-modal="true" aria-labelledby="knxDriverCredsTitle">
            <h3 id="knxDriverCredsTitle">Driver Credentials</h3>
            <p class="knx-modal-sub">Copy these credentials now. This will not be shown again automatically.</p>

            <div class="knx-creds">
                <div class="knx-creds-row">
                    <div class="knx-creds-label">Username</div>
                    <div class="knx-creds-value" id="knxCredsUsername">—</div>
                </div>
                <div class="knx-creds-row">
                    <div class="knx-creds-label">Temp Password</div>
                    <div class="knx-creds-value" id="knxCredsPassword">—</div>
                </div>
            </div>

            <div class="knx-modal-actions">
                <button type="button" id="knxDriverCredsClose" class="knx-btn knx-btn--primary">
                    <i class="fas fa-check"></i> Done
                </button>
            </div>
        </div>
    </div>

    <?php if (file_exists($js_path)): ?>
        <script src="<?php echo esc_url($js_url); ?>" defer></script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
});
