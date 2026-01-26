<?php
// File: inc/modules/hub-categories/hub-categories.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Hub Categories (CRUD Responsive UI) v1.1
 * Shortcode: [knx_hub_categories]
 *
 * Matches API v1.1:
 * - GET  /knx/v1/get-hub-categories
 * - POST /knx/v1/add-hub-category
 * - POST /knx/v1/toggle-hub-category
 * - POST /knx/v1/update-hub-category
 * - POST /knx/v1/delete-hub-category
 *
 * Rules:
 * - No wp_footer dependency
 * - Inline asset injection (no enqueues)
 * - Always renders base HTML (JS can fail without white-screen)
 * ==========================================================
 */

add_shortcode('knx_hub_categories', function () {

    // Session / role guard (admin surface)
    $session = function_exists('knx_get_session') ? knx_get_session() : false;
    $role    = (string) (($session->role ?? ''));

    if (!$session || !in_array($role, ['super_admin', 'manager'], true)) {
        $login = site_url('/login');

        // If redirect is possible, do it; otherwise render a safe fallback UI.
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

    // Endpoints (v1.1)
    $api_get    = rest_url('knx/v1/get-hub-categories');
    $api_add    = rest_url('knx/v1/add-hub-category');
    $api_toggle = rest_url('knx/v1/toggle-hub-category');
    $api_update = rest_url('knx/v1/update-hub-category');
    $api_delete = rest_url('knx/v1/delete-hub-category');

    // Nonces (must match API verify_nonce actions)
    $nonce_add    = wp_create_nonce('knx_add_hub_category_nonce');
    $nonce_toggle = wp_create_nonce('knx_toggle_hub_category_nonce');
    $nonce_update = wp_create_nonce('knx_update_hub_category_nonce');
    $nonce_delete = wp_create_nonce('knx_delete_hub_category_nonce');

    // Assets (guarded to prevent MIME text/html stylesheet errors)
    $css_rel  = 'inc/modules/hub-categories/hub-categories-style.css';
    $js_rel   = 'inc/modules/hub-categories/hub-categories-script.js';

    $css_path = (defined('KNX_PATH') ? KNX_PATH : plugin_dir_path(__FILE__) . '../../../') . $css_rel;
    $js_path  = (defined('KNX_PATH') ? KNX_PATH : plugin_dir_path(__FILE__) . '../../../') . $js_rel;

    $css_url  = (defined('KNX_URL') ? KNX_URL : plugin_dir_url(__FILE__) . '../../../') . $css_rel . '?v=' . (defined('KNX_VERSION') ? KNX_VERSION : time());
    $js_url   = (defined('KNX_URL') ? KNX_URL : plugin_dir_url(__FILE__) . '../../../') . $js_rel . '?v=' . (defined('KNX_VERSION') ? KNX_VERSION : time());

    ob_start(); ?>

    <?php if (file_exists($css_path)): ?>
        <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
    <?php endif; ?>

    <div class="knx-hubcats-signed"
         data-role="<?php echo esc_attr($role); ?>"
         data-api-get="<?php echo esc_url($api_get); ?>"
         data-api-add="<?php echo esc_url($api_add); ?>"
         data-api-toggle="<?php echo esc_url($api_toggle); ?>"
         data-api-update="<?php echo esc_url($api_update); ?>"
         data-api-delete="<?php echo esc_url($api_delete); ?>"
         data-nonce-add="<?php echo esc_attr($nonce_add); ?>"
         data-nonce-toggle="<?php echo esc_attr($nonce_toggle); ?>"
         data-nonce-update="<?php echo esc_attr($nonce_update); ?>"
         data-nonce-delete="<?php echo esc_attr($nonce_delete); ?>">

        <div class="knx-cities-header">
            <div class="knx-cities-title">
                <i class="fas fa-tags"></i>
                <h2>Hub Categories</h2>
                <span class="knx-badge-sealed">CRUD v1.1</span>
            </div>

            <div class="knx-cities-controls">
                <div class="knx-search">
                    <i class="fas fa-search"></i>
                    <input id="knxHubCatsSearch" type="text" placeholder="Search categories..." autocomplete="off">
                </div>

                <button id="knxAddHubCategoryBtn" class="knx-btn knx-btn--primary" type="button">
                    <i class="fas fa-plus"></i> Add Category
                </button>
            </div>
        </div>

        <!-- Desktop table -->
        <div class="knx-cities-tablewrap" id="knxHubCatsTableWrap">
            <table class="knx-cities-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Toggle</th>
                        <th>Edit</th>
                        <th>Delete</th>
                    </tr>
                </thead>
                <tbody id="knxHubCatsTbody">
                    <tr>
                        <td colspan="5">
                            <div class="knx-loading">Loading categories...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="knx-cities-cards" id="knxHubCatsCards"></div>

        <!-- JS-off fallback -->
        <noscript>
            <div class="knx-error-state">
                <i class="fas fa-exclamation-triangle"></i>
                <p>This page requires JavaScript to manage categories.</p>
            </div>
        </noscript>

    </div>

    <!-- =========================
         ADD / EDIT MODAL (Unified)
    ========================== -->
    <div id="knxHubCatModal" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-backdrop" data-knx-close="1"></div>

        <div class="knx-modal-box" role="dialog" aria-modal="true" aria-labelledby="knxHubCatModalTitle">
            <h3 id="knxHubCatModalTitle">Add Category</h3>
            <p class="knx-modal-sub">Create or rename a hub category.</p>

            <form id="knxHubCatForm">
                <input type="hidden" name="id" value="">
                <label class="knx-field">
                    <span>Category Name</span>
                    <input type="text" name="name" placeholder="e.g. Burgers, Sushi, Pizza" required>
                </label>

                <div class="knx-modal-actions">
                    <button type="button" id="knxHubCatCancel" class="knx-btn knx-btn--ghost">
                        Cancel
                    </button>
                    <button type="submit" id="knxHubCatSave" class="knx-btn knx-btn--primary">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- =========================
         DELETE MODAL (Hard delete, simple)
    ========================== -->
    <div id="knxHubCatDeleteModal" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-backdrop" data-knx-close="1"></div>

        <div class="knx-modal-box" role="dialog" aria-modal="true" aria-labelledby="knxHubCatDeleteTitle">
            <h3 id="knxHubCatDeleteTitle">Delete Category</h3>
            <p class="knx-modal-sub knx-modal-sub--danger">
                This will permanently delete <strong id="knxHubCatDeleteName">this category</strong>.
            </p>

            <div class="knx-modal-actions">
                <button type="button" id="knxHubCatDeleteCancel" class="knx-btn knx-btn--ghost">
                    Cancel
                </button>
                <button type="button" id="knxHubCatDeleteConfirm" class="knx-btn knx-btn--danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>

    <?php if (file_exists($js_path)): ?>
        <script src="<?php echo esc_url($js_url); ?>" defer></script>
    <?php endif; ?>

    <?php
    $out = ob_get_clean();
    return $out ?: '<div class="knx-error-state"><p>Unable to render Hub Categories UI.</p></div>';
});
