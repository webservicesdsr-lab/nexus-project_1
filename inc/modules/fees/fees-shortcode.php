<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Software Fees — Nexus Shell Admin UI
 * Shortcode: [knx_software_fees]
 * ----------------------------------------------------------
 * City SSOT + Hub Overrides — Nexus Shell Design Language
 * No enqueues (inline link/script tags)
 * ==========================================================
 */

add_shortcode('knx_software_fees', function () {
    global $wpdb;

    $session = function_exists('knx_get_session') ? knx_get_session() : false;
    $role    = $session ? (string) ($session->role ?? '') : '';

    if (!$session || !in_array($role, ['super_admin', 'manager'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $api_list   = rest_url('knx/v1/software-fees');
    $api_save   = rest_url('knx/v1/software-fees/save');
    $api_toggle = rest_url('knx/v1/software-fees/toggle');
    $api_hubs   = rest_url('knx/v1/software-fees/hubs');

    $nonce = wp_create_nonce('knx_nonce');

    $table_cities = $wpdb->prefix . 'knx_cities';
    $cities = $wpdb->get_results("SELECT id, name, status FROM {$table_cities} ORDER BY (status='active') DESC, name ASC");
    if (!is_array($cities)) $cities = [];

    $css_url = KNX_URL . 'inc/modules/fees/fees-style.css?v=' . KNX_VERSION;
    $js_url  = KNX_URL . 'inc/modules/fees/fees-script.js?v=' . KNX_VERSION;

    ob_start(); ?>

    <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">

    <div class="knx-fees"
         data-api-list="<?php echo esc_url($api_list); ?>"
         data-api-save="<?php echo esc_url($api_save); ?>"
         data-api-toggle="<?php echo esc_url($api_toggle); ?>"
         data-api-hubs="<?php echo esc_url($api_hubs); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-cities="<?php echo esc_attr(wp_json_encode($cities)); ?>"
    >

        <!-- Cards -->
        <div class="knx-fees__cards" id="knxFeesCards">
            <div class="knx-fees__loading">
                <div class="knx-fees__spinner"></div>
                <p>Loading cities&hellip;</p>
            </div>
        </div>

        <!-- Edit -->
        <div class="knx-fees__edit" id="knxFeesEdit" style="display:none;">
            <div class="knx-fees__editTop">
                <button type="button" class="knx-fees__btn knx-fees__btn--ghost" id="knxFeesBackBtn">&larr; All Cities</button>
                <div class="knx-fees__editMeta">
                    <h3 class="knx-fees__editTitle" id="knxFeesEditTitle">Configure Fees</h3>
                    <p class="knx-fees__muted">City: <strong id="knxFeesEditCityName"></strong></p>
                </div>
            </div>

            <div class="knx-fees__panels">
                <!-- City Fee -->
                <div class="knx-fees__panel">
                    <div class="knx-fees__panelHead">
                        <h4>
                            <span class="knx-fees__panelIcon knx-fees__panelIcon--city" aria-hidden="true">🏙</span>
                            Default City Fee
                        </h4>
                        <p class="knx-fees__muted">This fee applies to <strong>every hub</strong> in the city unless overridden below.</p>
                    </div>

                    <form class="knx-fees__form" id="knxFeesCityForm">
                        <input type="hidden" id="knxFeesCityId" value="">
                        <input type="hidden" id="knxFeesCityFeeId" value="">

                        <div class="knx-fees__field">
                            <label for="knxFeesCityAmount">Fee Amount ($)</label>
                            <input id="knxFeesCityAmount" type="number" min="0" step="0.01" class="knx-fees__input" placeholder="e.g. 2.50">
                            <small class="knx-fees__hint">Fixed dollar amount charged per order. Enter 0 for no fee.</small>
                        </div>

                        <div class="knx-fees__actions">
                            <button type="submit" class="knx-fees__btn knx-fees__btn--primary" id="knxFeesCitySaveBtn">Save City Fee</button>
                        </div>
                    </form>
                </div>

                <!-- Hub Overrides -->
                <div class="knx-fees__panel">
                    <div class="knx-fees__panelHead">
                        <h4>
                            <span class="knx-fees__panelIcon knx-fees__panelIcon--hub" aria-hidden="true">🏪</span>
                            Hub Overrides
                        </h4>
                        <p class="knx-fees__muted">Set a <strong>custom fee</strong> for specific hubs. Hubs without an override use the city default.</p>
                    </div>

                    <div id="knxFeesHubsList">
                        <div class="knx-fees__loading">
                            <div class="knx-fees__spinner"></div>
                            <p>Loading hubs&hellip;</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo esc_url($js_url); ?>" defer></script>

    <?php
    return ob_get_clean();
});