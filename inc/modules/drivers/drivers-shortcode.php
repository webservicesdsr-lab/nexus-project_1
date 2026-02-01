<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Drivers Admin UI (v2.3)
 * ----------------------------------------------------------
 * Shortcode: [knx_drivers_admin]
 *
 * UI-only module:
 * - No SQL here (preserves API purpose / SSOT).
 * - Uses REST v2 drivers endpoints for all operations.
 *
 * Endpoints used:
 *   GET   /knx/v2/drivers/list
 *   POST  /knx/v2/drivers/create
 *   GET   /knx/v2/drivers/{id}
 *   POST  /knx/v2/drivers/{id}/update
 *   POST  /knx/v2/drivers/{id}/toggle
 *   POST  /knx/v2/drivers/{id}/reset-password
 *   POST  /knx/v2/drivers/{id}/delete
 *   GET   /knx/v2/drivers/allowed-cities
 *
 * Security notes:
 * - API validates wp_verify_nonce(knx_nonce, 'knx_nonce') on writes.
 * - WP REST cookie auth requires X-WP-Nonce header (wp_rest) on POST.
 * - This UI provides BOTH nonces to JS via data-attributes.
 *
 * Assets:
 * - Injected via <link> / <script> tags (no wp_footer dependency).
 * ==========================================================
 */

function knx_drivers_admin_shortcode_v23() {

    if (!function_exists('knx_get_session')) {
        return '<div class="knx-drivers-err">Session unavailable.</div>';
    }

    $session = knx_get_session();
    $role = $session && isset($session->role) ? (string)$session->role : 'guest';

    if (!$session || !in_array($role, ['manager', 'super_admin'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // Nonces:
    // - knx_nonce: module write gate (checked by your drivers API)
    // - wp_rest: required as X-WP-Nonce for cookie auth POST requests
    $knx_nonce = wp_create_nonce('knx_nonce');
    $wp_nonce  = wp_create_nonce('wp_rest');

    // REST endpoints
    $api_list          = rest_url('knx/v2/drivers/list');
    $api_create        = rest_url('knx/v2/drivers/create');
    $api_allowed_cities= rest_url('knx/v2/drivers/allowed-cities');

    // Note: toggle/update/reset/delete include {id} placeholders; JS will build final URLs.
    $api_get_tpl        = rest_url('knx/v2/drivers/{id}');
    $api_update_tpl     = rest_url('knx/v2/drivers/{id}/update');
    $api_toggle_tpl     = rest_url('knx/v2/drivers/{id}/toggle');
    $api_reset_tpl      = rest_url('knx/v2/drivers/{id}/reset-password');
    $api_delete_tpl     = rest_url('knx/v2/drivers/{id}/delete');

    // URLs
    $edit_url_base = site_url('/edit-driver?id=');

    // Assets (no enqueues; no wp_footer)
    $css_url = defined('KNX_URL') ? KNX_URL . 'inc/modules/drivers/drivers-style.css' : '';
    $js_url  = defined('KNX_URL') ? KNX_URL . 'inc/modules/drivers/drivers-script.js' : '';

    ob_start(); ?>

    <?php if ($css_url): ?>
        <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
    <?php endif; ?>

    <div id="knx-drivers-admin"
         class="knx-drivers-wrapper"
         data-api-list="<?php echo esc_url($api_list); ?>"
         data-api-create="<?php echo esc_url($api_create); ?>"
         data-api-allowed-cities="<?php echo esc_url($api_allowed_cities); ?>"
         data-api-get-tpl="<?php echo esc_url($api_get_tpl); ?>"
         data-api-update-tpl="<?php echo esc_url($api_update_tpl); ?>"
         data-api-toggle-tpl="<?php echo esc_url($api_toggle_tpl); ?>"
         data-api-reset-tpl="<?php echo esc_url($api_reset_tpl); ?>"
         data-api-delete-tpl="<?php echo esc_url($api_delete_tpl); ?>"
         data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
         data-wp-nonce="<?php echo esc_attr($wp_nonce); ?>"
         data-edit-url-base="<?php echo esc_url($edit_url_base); ?>"
         data-per-page="20">

        <noscript>
            <div class="knx-drivers-err">JavaScript is required to manage drivers.</div>
        </noscript>

        <header class="knx-drivers-header">
            <div class="knx-drivers-title">
                <h2>Drivers</h2>
                <p class="knx-drivers-subtitle">Manage drivers with REST-only CRUD (mobile-first).</p>
            </div>

            <div class="knx-drivers-controls">
                <form class="knx-search-form" id="knxDriversSearchForm" autocomplete="off">
                    <input
                        type="text"
                        id="knxDriversSearchInput"
                        name="q"
                        placeholder="Search drivers..."
                        inputmode="search"
                        aria-label="Search drivers"
                    />
                    <button type="submit" aria-label="Search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                    </button>
                </form>

                <select id="knxDriversStatusFilter" class="knx-select" aria-label="Filter by status">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <button id="knxAddDriverBtn" class="knx-add-btn" type="button">
                    <i class="fas fa-plus" aria-hidden="true"></i>
                    Add Driver
                </button>
            </div>
        </header>

        <main class="knx-drivers-surface">
            <section class="knx-drivers-table-wrap" aria-label="Drivers list">
                <table class="knx-drivers-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="knx-hide-sm">Email</th>
                            <th class="knx-hide-md">Phone</th>
                            <th class="knx-hide-md">Vehicle</th>
                            <th>Cities</th>
                            <th>Status</th>
                            <th class="knx-col-icon">Edit</th>
                            <th class="knx-col-icon">Toggle</th>
                            <th class="knx-col-icon knx-hide-sm">Reset</th>
                            <th class="knx-col-icon knx-hide-sm">Delete</th>
                        </tr>
                    </thead>

                    <tbody id="knxDriversTbody">
                        <tr class="knx-row-loading">
                            <td colspan="10">
                                <div class="knx-loading" aria-label="Loading">
                                    <div class="knx-loading-bar"></div>
                                    <div class="knx-loading-bar"></div>
                                    <div class="knx-loading-bar"></div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="knx-pagination" id="knxDriversPagination" aria-label="Pagination"></div>
            </section>
        </main>

        <!-- ======================================================
             Modal: Add Driver
             IMPORTANT: City selection happens ONLY inside this modal.
             No separate "city modal" is used.
             ====================================================== -->
        <div id="knxAddDriverModal" class="knx-modal" aria-hidden="true">
            <div class="knx-modal-content" role="dialog" aria-modal="true" aria-labelledby="knxAddDriverTitle">
                <div class="knx-modal-top">
                    <h3 id="knxAddDriverTitle">Add Driver</h3>

                    <button type="button" class="knx-icon-btn" id="knxCloseDriverModal" aria-label="Close">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>

                <form id="knxAddDriverForm" novalidate>
                    <div class="knx-grid">
                        <label class="knx-field">
                            <span class="knx-label">Full Name</span>
                            <input type="text" name="full_name" placeholder="Driver name" required>
                        </label>

                        <label class="knx-field">
                            <span class="knx-label">Email</span>
                            <input type="email" name="email" placeholder="Email address" required>
                        </label>

                        <label class="knx-field">
                            <span class="knx-label">Phone</span>
                            <input type="text" name="phone" placeholder="Phone number">
                        </label>

                        <label class="knx-field">
                            <span class="knx-label">Vehicle Info</span>
                            <input type="text" name="vehicle_info" placeholder="Optional (e.g. Blue Corolla)">
                        </label>

                        <label class="knx-field knx-field--status">
                            <span class="knx-label">Status</span>
                            <select name="status" class="knx-select">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </label>
                    </div>

                    <div class="knx-divider"></div>

                    <div class="knx-city-picker">
                        <div class="knx-city-picker__head">
                            <div>
                                <div class="knx-city-picker__title">Cities</div>
                                <div class="knx-city-picker__hint">Select at least one city.</div>
                            </div>

                            <input type="text" id="knxCitySearch" class="knx-city-search" placeholder="Search cities..." inputmode="search" aria-label="Search cities">
                        </div>

                        <div id="knxCityChips" class="knx-city-chips" aria-label="Selected cities"></div>

                        <div id="knxCityList" class="knx-city-list" aria-label="Cities list">
                            <div class="knx-city-empty">Loading cities...</div>
                        </div>

                        <!-- Hidden field (JS will sync selected ids as JSON array string) -->
                        <input type="hidden" name="city_ids" id="knxCityIdsField" value="[]">
                    </div>

                    <div class="knx-modal-actions">
                        <button type="submit" class="knx-btn" id="knxCreateDriverBtn">Create Driver</button>
                        <button type="button" class="knx-btn-secondary" id="knxCancelDriverModal">Cancel</button>
                    </div>

                    <!-- Create result (temp credentials shown once) -->
                    <div id="knxCreateResult" class="knx-create-result" hidden>
                        <div class="knx-create-result__title">Driver created</div>

                        <div class="knx-create-result__row">
                            <span>Username</span>
                            <code id="knxCreatedUsername"></code>
                            <button type="button" class="knx-mini-btn" data-copy="#knxCreatedUsername">Copy</button>
                        </div>

                        <div class="knx-create-result__row">
                            <span>Temp Password</span>
                            <code id="knxCreatedPassword"></code>
                            <button type="button" class="knx-mini-btn" data-copy="#knxCreatedPassword">Copy</button>
                        </div>

                        <div class="knx-create-result__foot">
                            Save these credentials now. Password is only shown once.
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Confirm deactivate -->
        <div id="knxConfirmDeactivateDriver" class="knx-modal" aria-hidden="true">
            <div class="knx-modal-content knx-confirm-content" role="dialog" aria-modal="true" aria-labelledby="knxConfirmDriverTitle">
                <h3 id="knxConfirmDriverTitle">Deactivate driver?</h3>
                <p class="knx-confirm-message">This driver will become unavailable for assignments.</p>
                <div class="knx-confirm-actions">
                    <button id="knxCancelDeactivateDriver" type="button" class="knx-btn-secondary">Cancel</button>
                    <button id="knxConfirmDeactivateDriverBtn" type="button" class="knx-btn knx-danger">Deactivate</button>
                </div>
            </div>
        </div>

        <!-- Modal: Confirm delete (soft-delete) -->
        <div id="knxConfirmDeleteDriver" class="knx-modal" aria-hidden="true">
            <div class="knx-modal-content knx-confirm-content" role="dialog" aria-modal="true" aria-labelledby="knxConfirmDeleteTitle">
                <h3 id="knxConfirmDeleteTitle">Delete driver?</h3>
                <p class="knx-confirm-message">This is a soft-delete. The driver will be hidden and disabled.</p>

                <label class="knx-field" style="margin-top: 10px;">
                    <span class="knx-label">Reason (optional)</span>
                    <input type="text" id="knxDeleteReason" placeholder="e.g. No longer working">
                </label>

                <div class="knx-confirm-actions">
                    <button id="knxCancelDeleteDriver" type="button" class="knx-btn-secondary">Cancel</button>
                    <button id="knxConfirmDeleteDriverBtn" type="button" class="knx-btn knx-danger">Delete</button>
                </div>
            </div>
        </div>

        <!-- Modal: Reset password -->
        <div id="knxConfirmResetDriver" class="knx-modal" aria-hidden="true">
            <div class="knx-modal-content knx-confirm-content" role="dialog" aria-modal="true" aria-labelledby="knxConfirmResetTitle">
                <h3 id="knxConfirmResetTitle">Reset password?</h3>
                <p class="knx-confirm-message">A new temporary password will be generated and shown once.</p>
                <div class="knx-confirm-actions">
                    <button id="knxCancelResetDriver" type="button" class="knx-btn-secondary">Cancel</button>
                    <button id="knxConfirmResetDriverBtn" type="button" class="knx-btn">Reset</button>
                </div>

                <div id="knxResetResult" class="knx-create-result" hidden>
                    <div class="knx-create-result__title">Password reset</div>

                    <div class="knx-create-result__row">
                        <span>Username</span>
                        <code id="knxResetUsername"></code>
                        <button type="button" class="knx-mini-btn" data-copy="#knxResetUsername">Copy</button>
                    </div>

                    <div class="knx-create-result__row">
                        <span>Temp Password</span>
                        <code id="knxResetPassword"></code>
                        <button type="button" class="knx-mini-btn" data-copy="#knxResetPassword">Copy</button>
                    </div>

                    <div class="knx-create-result__foot">
                        Save this password now. It will only be shown once.
                    </div>
                </div>
            </div>
        </div>

    </div>

    <?php if ($js_url): ?>
        <script src="<?php echo esc_url($js_url); ?>" defer></script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

/**
 * Canonical shortcode name:
 *   [knx_drivers_admin]
 */
add_shortcode('knx_drivers_admin', 'knx_drivers_admin_shortcode_v23');
