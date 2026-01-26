<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Cities — Sealed CRUD UI (NEXUS FINAL)
 * Shortcode: [knx_cities_signed]
 *
 * Uses:
 * - v2 sealed REST APIs
 * - Global knxToast()
 * - Sidebar layout (CSS margin-left)
 * - Add City modal (super_admin)
 * - Delete City modal (super_admin)
 * ==========================================================
 */

add_shortcode('knx_cities_signed', function () {

    $session = function_exists('knx_get_session') ? knx_get_session() : false;
    if (!$session || !in_array(($session->role ?? ''), ['super_admin', 'manager'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $role = (string) ($session->role ?? '');

    // v2 sealed endpoints
    $api_get    = rest_url('knx/v2/cities/get');
    $api_add    = rest_url('knx/v2/cities/add');
    $api_toggle = rest_url('knx/v2/cities/operational-toggle');
    $api_delete = rest_url('knx/v2/cities/delete');

    // nonces
    $nonce_add    = wp_create_nonce('knx_city_add');
    $nonce_toggle = wp_create_nonce('knx_city_operational_toggle');
    $nonce_delete = wp_create_nonce('knx_city_delete');

    // NEW slug base for edit
    // Example: /knx-edit-city/?id=2
    $edit_base = site_url('/knx-edit-city/?id=');

    // Assets (echo-style like your legacy modules)
    $css_main_url  = KNX_URL . 'inc/modules/knx-cities/knx-cities-style.css?v=' . KNX_VERSION;
    $css_main_path = KNX_PATH . 'inc/modules/knx-cities/knx-cities-style.css';

 
    $css_del_url   = KNX_URL . 'inc/modules/knx-cities/knx-cities-delete-modal.css?v=' . KNX_VERSION;
    $css_del_path  = KNX_PATH . 'inc/modules/knx-cities/knx-cities-delete-modal.css';

    ob_start(); ?>

    <?php if (file_exists($css_main_path)): ?>
        <link rel="stylesheet" href="<?php echo esc_url($css_main_url); ?>">
    <?php endif; ?>

    <?php if (file_exists($css_del_path)): ?>
        <link rel="stylesheet" href="<?php echo esc_url($css_del_url); ?>">
    <?php endif; ?>

    <div class="knx-cities-signed"
         data-role="<?php echo esc_attr($role); ?>"
         data-api-get="<?php echo esc_url($api_get); ?>"
         data-api-add="<?php echo esc_url($api_add); ?>"
         data-api-toggle="<?php echo esc_url($api_toggle); ?>"
         data-api-delete="<?php echo esc_url($api_delete); ?>"
         data-nonce-add="<?php echo esc_attr($nonce_add); ?>"
         data-nonce-toggle="<?php echo esc_attr($nonce_toggle); ?>"
         data-nonce-delete="<?php echo esc_attr($nonce_delete); ?>"
         data-edit-base="<?php echo esc_url($edit_base); ?>"
    >

        <div class="knx-cities-header">
            <div class="knx-cities-title">
                <i class="fas fa-city"></i>
                <h2>KNX Cities</h2>
                <span class="knx-badge-sealed">SEALED v2</span>
            </div>

            <div class="knx-cities-controls">
                <div class="knx-search">
                    <i class="fas fa-search"></i>
                    <input id="knxCitiesSearch" type="text" placeholder="Search cities..." autocomplete="off">
                </div>

                <?php if ($role === 'super_admin'): ?>
                    <button id="knxAddCityBtn" class="knx-btn knx-btn--primary" type="button">
                        <i class="fas fa-plus"></i> Add City
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Desktop table -->
        <div class="knx-cities-tablewrap" id="knxCitiesTableWrap">
            <table class="knx-cities-table">
                <thead>
                    <tr>
                        <th>City</th>
                        <th>Status</th>
                        <th>Operational</th>
                        <th>Edit</th>
                        <?php if ($role === 'super_admin'): ?>
                            <th>Delete</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="knxCitiesTbody"></tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="knx-cities-cards" id="knxCitiesCards"></div>

    </div>

    <!-- =========================
         ADD CITY MODAL (NEXUS)
    ========================== -->
    <?php if ($role === 'super_admin'): ?>
    <div id="knxAddCityModal" class="knx-modal" aria-hidden="true">
        <div class="knx-modal-backdrop" data-knx-close="1"></div>

        <div class="knx-modal-box" role="dialog" aria-modal="true" aria-labelledby="knxAddCityTitle">
            <h3 id="knxAddCityTitle">Add City</h3>
            <p class="knx-modal-sub">Create a new city in your network.</p>

            <form id="knxAddCityForm">
                <label class="knx-field">
                    <span>City Name</span>
                    <input type="text" name="name" placeholder="e.g. Kankakee County, IL" required>
                </label>

                <div class="knx-modal-actions">
                    <button type="button" id="knxAddCityCancel" class="knx-btn knx-btn--ghost">
                        Cancel
                    </button>
                    <button type="submit" class="knx-btn knx-btn--primary" id="knxAddCitySave">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========================
         DELETE MODAL (markup)
    ========================== -->
    <?php
      $delete_modal_php = KNX_PATH . 'inc/modules/knx-cities/knx-cities-delete-modal.php';
      if (file_exists($delete_modal_php)) {
          require $delete_modal_php;
      }
    ?>

    <!-- Scripts -->
    <?php
      $delete_modal_js      = KNX_URL . 'inc/modules/knx-cities/knx-cities-delete-modal.js?v=' . KNX_VERSION;
      $cities_js            = KNX_URL . 'inc/modules/knx-cities/knx-cities-script.js?v=' . KNX_VERSION;

      $delete_modal_js_path = KNX_PATH . 'inc/modules/knx-cities/knx-cities-delete-modal.js';
      $cities_js_path       = KNX_PATH . 'inc/modules/knx-cities/knx-cities-script.js';
    ?>

    <?php if (file_exists($delete_modal_js_path)): ?>
        <script src="<?php echo esc_url($delete_modal_js); ?>" defer></script>
    <?php endif; ?>

    <?php if (file_exists($cities_js_path)): ?>
        <script src="<?php echo esc_url($cities_js); ?>" defer></script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
});
