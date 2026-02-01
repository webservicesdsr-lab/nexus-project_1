<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Drivers Admin (Shortcode) (v2.2)
 * Shortcode: [knx_drivers_admin]
 * ----------------------------------------------------------
 * UI is NON-authoritative:
 * - Lists + CRUD via REST only (knx/v2)
 * - No direct DB queries here
 *
 * Notes:
 * - Assets injected via HTML tags (no wp_footer dependency)
 * - Uses:
 *   GET  /knx/v2/drivers/list
 *   GET  /knx/v2/drivers/allowed-cities
 *   POST /knx/v2/drivers/create
 *   GET  /knx/v2/drivers/{id}
 *   POST /knx/v2/drivers/{id}/update
 *   POST /knx/v2/drivers/{id}/toggle
 *   POST /knx/v2/drivers/{id}/reset-password
 *   POST /knx/v2/drivers/{id}/delete
 * ==========================================================
 */

add_shortcode('knx_drivers_admin', function () {

    if (!function_exists('knx_get_session')) {
        return '<div class="knx-drivers-err">Session unavailable.</div>';
    }

    $session = knx_get_session();
    $role = $session && isset($session->role) ? (string)$session->role : '';

    if (!$session || !in_array($role, ['super_admin', 'manager'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // Nonces
    $knx_nonce = wp_create_nonce('knx_nonce');   // Required by your drivers API
    $wp_rest_nonce = wp_create_nonce('wp_rest'); // Required by WP cookie REST auth on POST

    // URLs (absolute)
    $api_list   = rest_url('knx/v2/drivers/list');
    $api_create = rest_url('knx/v2/drivers/create');
    $api_base   = rest_url('knx/v2/drivers/'); // will be used as {base}{id}/update etc
    $api_cities = rest_url('knx/v2/drivers/allowed-cities');

    // Asset URLs
    $base_url = defined('KNX_URL') ? KNX_URL : plugin_dir_url(__FILE__);
    $ver = defined('KNX_VERSION') ? KNX_VERSION : '2.2';

    $css_url = esc_url($base_url . 'inc/modules/drivers/drivers-style.css?v=' . $ver);
    $js_url  = esc_url($base_url . 'inc/modules/drivers/drivers-script.js?v=' . $ver);

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo $css_url; ?>">

    <div id="knx-drivers-admin"
         class="knx-drivers-wrapper"
         data-api-list="<?php echo esc_url($api_list); ?>"
         data-api-create="<?php echo esc_url($api_create); ?>"
         data-api-base="<?php echo esc_url($api_base); ?>"
         data-api-cities="<?php echo esc_url($api_cities); ?>"
         data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
         data-wp-rest-nonce="<?php echo esc_attr($wp_rest_nonce); ?>">

        <div class="knx-drivers-header">
            <h2>Drivers</h2>

            <div class="knx-drivers-controls">
                <input class="knx-drivers-q" type="text" inputmode="search" placeholder="Search drivers by name, email, phone, username, or ID…" autocomplete="off">

                <select class="knx-drivers-status">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <button type="button" class="knx-btn knx-drivers-add">
                    <span aria-hidden="true">＋</span>
                    <span>Add Driver</span>
                </button>
            </div>
        </div>

        <div class="knx-drivers-table-wrap">
            <table class="knx-drivers-table" aria-label="Drivers table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>City</th>
                        <th>Status</th>
                        <th class="knx-col-center">Edit</th>
                        <th class="knx-col-center">Toggle</th>
                    </tr>
                </thead>
                <tbody class="knx-drivers-tbody">
                    <tr>
                        <td colspan="6">
                            <div class="knx-drivers-empty">Loading…</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="knx-pagination" aria-label="Pagination"></div>
    </div>

    <!-- Driver Modal (Add/Edit) -->
    <div id="knxDriverModal" class="knx-drv-modal" aria-hidden="true">
        <div class="knx-drv-modal__content" role="dialog" aria-modal="true" aria-labelledby="knxDriverModalTitle">
            <div class="knx-drv-modal__head">
                <h3 id="knxDriverModalTitle">Add Driver</h3>
                <button type="button" class="knx-drv-x" aria-label="Close">✕</button>
            </div>

            <form id="knxDriverForm" class="knx-drv-form">
                <input type="hidden" name="driver_id" value="">
                <div class="knx-drv-grid two">
                    <div class="knx-drv-field">
                        <label>Full name</label>
                        <input type="text" name="full_name" placeholder="Driver full name" required>
                    </div>
                    <div class="knx-drv-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="knx-drv-grid two">
                    <div class="knx-drv-field">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="email@domain.com" required>
                    </div>
                    <div class="knx-drv-field">
                        <label>Phone</label>
                        <input type="text" name="phone" placeholder="(optional)">
                    </div>
                </div>

                <div class="knx-drv-field">
                    <label>Vehicle info</label>
                    <input type="text" name="vehicle_info" placeholder="(optional)">
                </div>

                <div class="knx-drv-field">
                    <label>Cities</label>
                    <div class="knx-drv-cities">
                        <div class="knx-drv-chips" aria-live="polite"></div>
                        <button type="button" class="knx-btn-secondary knx-drv-pick-cities">Select cities</button>
                    </div>
                    <div class="knx-drv-hint">At least 1 city is required.</div>
                </div>

                <div class="knx-drv-credentials" hidden>
                    <div class="knx-drv-cred-title">New credentials</div>
                    <div class="knx-drv-cred-row">
                        <div class="knx-drv-cred-box">
                            <div class="knx-drv-cred-label">Username</div>
                            <div class="knx-drv-cred-value" data-cred="username"></div>
                        </div>
                        <button type="button" class="knx-icon-btn knx-drv-copy" data-copy="username" aria-label="Copy username">⧉</button>
                    </div>
                    <div class="knx-drv-cred-row">
                        <div class="knx-drv-cred-box">
                            <div class="knx-drv-cred-label">Temp password</div>
                            <div class="knx-drv-cred-value" data-cred="password"></div>
                        </div>
                        <button type="button" class="knx-icon-btn knx-drv-copy" data-copy="password" aria-label="Copy temp password">⧉</button>
                    </div>
                </div>

                <div class="knx-drv-modal__actions">
                    <button type="button" class="knx-btn-secondary knx-drv-cancel">Cancel</button>
                    <button type="submit" class="knx-btn knx-drv-save">Save</button>
                </div>

                <div class="knx-drv-divider"></div>

                <div class="knx-drv-modal__actions knx-drv-secondary-actions">
                    <button type="button" class="knx-icon-btn danger knx-drv-reset" title="Reset password" hidden>Reset Password</button>
                    <button type="button" class="knx-icon-btn danger knx-drv-delete" title="Soft delete" hidden>Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm Deactivate Modal -->
    <div id="knxDriverConfirmModal" class="knx-drv-modal" aria-hidden="true">
        <div class="knx-drv-modal__content knx-drv-confirm" role="dialog" aria-modal="true" aria-labelledby="knxDriverConfirmTitle">
            <div class="knx-drv-modal__head">
                <h3 id="knxDriverConfirmTitle">Deactivate Driver</h3>
                <button type="button" class="knx-drv-x" aria-label="Close">✕</button>
            </div>
            <p>This driver will be unavailable. Continue?</p>
            <div class="knx-drv-modal__actions">
                <button type="button" class="knx-btn-secondary knx-drv-confirm-cancel">Cancel</button>
                <button type="button" class="knx-btn knx-drv-confirm-ok">Deactivate</button>
            </div>
        </div>
    </div>

    <!-- Cities Picker Modal (single instance) -->
    <div id="knxDriverCitiesModal" class="knx-drv-modal" aria-hidden="true">
        <div class="knx-drv-modal__content knx-drv-cities-modal" role="dialog" aria-modal="true" aria-labelledby="knxDriverCitiesTitle">
            <div class="knx-drv-modal__head">
                <h3 id="knxDriverCitiesTitle">Select Cities</h3>
                <button type="button" class="knx-drv-x" aria-label="Close">✕</button>
            </div>

            <div class="knx-drv-field">
                <label>Search</label>
                <input type="text" class="knx-drv-cities-q" placeholder="Type to filter cities…" autocomplete="off">
            </div>

            <div class="knx-drv-cities-list" role="list"></div>

            <div class="knx-drv-modal__actions">
                <button type="button" class="knx-btn-secondary knx-drv-cities-cancel">Cancel</button>
                <button type="button" class="knx-btn knx-drv-cities-save">Save</button>
            </div>
        </div>
    </div>

    <script>
      window.KNX_DRIVERS_CONFIG = {
        apiList: <?php echo wp_json_encode($api_list); ?>,
        apiCreate: <?php echo wp_json_encode($api_create); ?>,
        apiBase: <?php echo wp_json_encode($api_base); ?>,
        apiCities: <?php echo wp_json_encode($api_cities); ?>,
        knxNonce: <?php echo wp_json_encode($knx_nonce); ?>,
        wpRestNonce: <?php echo wp_json_encode($wp_rest_nonce); ?>
      };
    </script>

    <script src="<?php echo $js_url; ?>"></script>
    <?php
    return ob_get_clean();
});
