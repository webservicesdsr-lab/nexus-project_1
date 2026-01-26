<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — KNX Edit City (SEALED v4 / Module)
 * ----------------------------------------------------------
 * Shortcode: [knx_edit_city]
 *
 * Rules:
 * - SuperAdmin ONLY (hard gate)
 * - Uses v2 SEALED endpoints for City Info
 * - Delivery Rates (A) rebuilt + connected to v2 endpoints
 * - No enqueue: assets loaded via <link>/<script>
 * - Sidebar inline (no dependency on wp_footer)
 * ==========================================================
 */

add_shortcode('knx_edit_city', function () {
    global $wpdb;

    /** Canonical back URL */
    $back_cities_url = site_url('/knx-cities');
    $fallback_url    = site_url('/dashboard');

    /**
     * Safe redirect helper for shortcode context.
     * Falls back to JS/meta redirect if headers were already sent.
     */
    $knx_redirect = function ($url) {
        $url = esc_url_raw($url);

        if (!headers_sent()) {
            wp_safe_redirect($url);
            exit;
        }

        $safe = esc_url($url);

        return ''
            . '<script>window.location.href=' . wp_json_encode($safe) . ';</script>'
            . '<noscript><meta http-equiv="refresh" content="0;url=' . esc_attr($safe) . '"></noscript>';
    };

    /**
     * Best-effort soft-delete detector (schema-agnostic).
     * Fail-closed only on malformed rows.
     */
    $knx_city_is_soft_deleted = function ($city_row) {
        if (!is_object($city_row)) return true;

        $checks = [
            ['deleted_at', function ($v) { return !empty($v) && $v !== '0000-00-00 00:00:00'; }],
            ['is_deleted', function ($v) { return (int)$v === 1; }],
            ['deleted', function ($v) { return (int)$v === 1; }],
            ['archived', function ($v) { return (int)$v === 1; }],
            ['status', function ($v) { return is_string($v) && strtolower($v) === 'deleted'; }],
        ];

        foreach ($checks as [$field, $fn]) {
            if (property_exists($city_row, $field)) {
                try {
                    if ($fn($city_row->{$field})) return true;
                } catch (\Throwable $e) {
                    return true;
                }
            }
        }

        return false;
    };

    /** =========================
     * 1) Session + Role Gate (SuperAdmin ONLY)
     * ========================= */
    if (!function_exists('knx_get_session')) {
        return $knx_redirect($fallback_url);
    }

    $session = knx_get_session();
    if (!is_object($session) || !isset($session->role) || $session->role !== 'super_admin') {
        return $knx_redirect($fallback_url);
    }

    /** =========================
     * 2) City ID Gate
     * ========================= */
    $city_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    if (!$city_id) {
        return $knx_redirect($back_cities_url);
    }

    /** =========================
     * 3) City Exists + Not Soft Deleted
     * ========================= */
    $table_cities = $wpdb->prefix . 'knx_cities';
    $city = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_cities} WHERE id = %d", $city_id));

    if (!$city || $knx_city_is_soft_deleted($city)) {
        return $knx_redirect($back_cities_url);
    }

    /** =========================
     * 4) Nonce + API roots (City Info v2 SEALED)
     * ========================= */
    $nonce = wp_create_nonce('knx_edit_city_nonce');

    $api_city_get    = rest_url('knx/v2/cities/get-city');
    $api_city_update = rest_url('knx/v2/cities/update-city');

    /** Delivery Rates (v2 SEALED) */
    $api_rates_get    = rest_url('knx/v2/cities/get-delivery-rates');
    $api_rates_update = rest_url('knx/v2/cities/update-delivery-rates');

    /** Version for cache busting */
    $ver = defined('KNX_VERSION') ? KNX_VERSION : '1.0';

    /** Prefill (optional UX) */
    $prefill_name   = isset($city->name) ? (string)$city->name : '';
    $prefill_status = isset($city->status) ? (string)$city->status : 'active';
    $prefill_status = in_array($prefill_status, ['active', 'inactive'], true) ? $prefill_status : 'active';

    ob_start();
    ?>
    <div class="knx-edit-city-signed" data-module="knx-edit-city">

        <!-- Base/admin styles (kept for UI consistency) -->
        <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/hubs-style.css?ver=' . rawurlencode($ver)); ?>">

        <!-- This module styles -->
        <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/knx-cities/knx-edit-city-style.css?ver=' . rawurlencode($ver)); ?>">

        <!-- Sidebar assets (inline usage) -->
        <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/sidebar/sidebar-style.css?ver=' . rawurlencode($ver)); ?>">

        <!-- Hide KNX top navbar only (do not touch theme header) -->
        <style>
            .knx-edit-city-signed #knxTopNavbar,
            .knx-edit-city-signed .knx-top-navbar,
            .knx-edit-city-signed .knx-navbar {
                display: none !important;
            }
        </style>

        <div class="knx-edit-city-shell">

            <!-- =========================
                 SIDEBAR (inline)
                 ========================= -->
            <aside class="knx-sidebar" id="knxSidebar">
                <div class="knx-sidebar-header">
                    <button id="knxExpandMobile" class="knx-expand-btn" aria-label="Toggle Sidebar">
                        <i class="fas fa-angles-right"></i>
                    </button>
                    <a href="<?php echo esc_url(site_url('/dashboard')); ?>" class="knx-logo" title="Dashboard">
                        <i class="fas fa-home"></i>
                    </a>
                </div>

                <div class="knx-sidebar-scroll">
                    <ul class="knx-sidebar-menu">
                        <li><a href="<?php echo esc_url(site_url('/dashboard')); ?>"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></li>
                        <li><a href="<?php echo esc_url(site_url('/hubs')); ?>"><i class="fas fa-store"></i><span>Hubs</span></a></li>
                        <li><a href="<?php echo esc_url(site_url('/menus')); ?>"><i class="fas fa-utensils"></i><span>Menus</span></a></li>
                        <li><a href="<?php echo esc_url(site_url('/hub-categories')); ?>"><i class="fas fa-list"></i><span>Hub Categories</span></a></li>
                        <li><a href="<?php echo esc_url(site_url('/drivers')); ?>"><i class="fas fa-car"></i><span>Drivers</span></a></li>
                        <li><a href="<?php echo esc_url(site_url('/customers')); ?>"><i class="fas fa-users"></i><span>Customers</span></a></li>
                        <li><a href="<?php echo esc_url(site_url('/knx-cities')); ?>"><i class="fas fa-city"></i><span>Cities</span></a></li>
                        <li><a href="<?php echo esc_url(site_url('/settings')); ?>"><i class="fas fa-cog"></i><span>Settings</span></a></li>
                    </ul>
                </div>
            </aside>

            <!-- =========================
                 MAIN CONTENT
                 ========================= -->
            <main class="knx-edit-city-main">

                <div class="knx-edit-city-container">

                    <div class="knx-edit-city-actionbar">
                        <a class="knx-btn" href="<?php echo esc_url($back_cities_url); ?>">
                            <i class="fas fa-arrow-left"></i> Back to Cities
                        </a>
                    </div>

                    <!-- =============================================
                         CITY INFO SECTION (v2 SEALED)
                    ============================================= -->
                    <div class="knx-card knx-edit-city-wrapper"
                         data-api-get="<?php echo esc_url($api_city_get); ?>"
                         data-api-update="<?php echo esc_url($api_city_update); ?>"
                         data-city-id="<?php echo esc_attr($city_id); ?>"
                         data-nonce="<?php echo esc_attr($nonce); ?>">

                        <div class="knx-edit-header">
                            <i class="fas fa-city knx-edit-city-icon"></i>
                            <h1>Edit City</h1>
                        </div>

                        <div class="knx-form-group">
                            <label>City Name</label>
                            <input type="text" id="cityName" placeholder="City name" value="<?php echo esc_attr($prefill_name); ?>">
                        </div>

                        <div class="knx-form-group">
                            <label>Status</label>
                            <select id="cityStatus">
                                <option value="active" <?php selected($prefill_status, 'active'); ?>>Active</option>
                                <option value="inactive" <?php selected($prefill_status, 'inactive'); ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="knx-save-row">
                            <button id="saveCity" class="knx-btn">Save City</button>
                        </div>
                    </div>

                    <!-- =============================================
                         DELIVERY RATES (A) — Flat + Per Distance + Unit
                    ============================================= -->
                    <div class="knx-card knx-edit-city-rates-wrapper"
                         data-api-get="<?php echo esc_url($api_rates_get); ?>"
                         data-api-update="<?php echo esc_url($api_rates_update); ?>"
                         data-city-id="<?php echo esc_attr($city_id); ?>"
                         data-nonce="<?php echo esc_attr($nonce); ?>">

                        <div class="knx-edit-header">
                            <i class="fas fa-truck knx-edit-city-icon"></i>
                            <h2>Delivery Rates</h2>
                        </div>

                        <div class="knx-dr-field">
                            <label for="knxFlatRate">Flat Rate</label>
                            <input
                                type="text"
                                id="knxFlatRate"
                                inputmode="decimal"
                                placeholder="0.00"
                                autocomplete="off"
                            >
                        </div>

                        <div class="knx-dr-field">
                            <label for="knxRatePerDistance">Rate Per Distance</label>
                            <input
                                type="text"
                                id="knxRatePerDistance"
                                inputmode="decimal"
                                placeholder="0.00"
                                autocomplete="off"
                            >
                        </div>

                        <div class="knx-dr-field">
                            <label for="knxDistanceUnit">Select Distance Type</label>
                            <select id="knxDistanceUnit">
                                <option value="kilometer">KiloMeter</option>
                                <option value="mile">Mile</option>
                            </select>
                        </div>

                        <!-- Optional (kept for backend compatibility, not shown in UI) -->
                        <input type="hidden" id="knxRatesStatus" value="active">

                        <div class="knx-rates-save-row">
                            <button id="knxUpdateRates" class="knx-btn knx-btn--rates">Update</button>
                        </div>
                    </div>

                </div>
            </main>
        </div>

        <!-- JS (no enqueue) -->
        <script src="<?php echo esc_url(KNX_URL . 'inc/modules/sidebar/sidebar-script.js?ver=' . rawurlencode($ver)); ?>"></script>
        <script src="<?php echo esc_url(KNX_URL . 'inc/modules/knx-cities/knx-edit-city-script.js?ver=' . rawurlencode($ver)); ?>"></script>
    </div>
    <?php

    return ob_get_clean();
});
