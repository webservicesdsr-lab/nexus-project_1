<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX — Driver Quick Menu (Home UI)
 * Shortcode: [knx_driver_quick_menu]
 *
 * UX goals:
 * - 1:1 layout feel (header + toggle, banner, stat cards, quick actions, location card)
 * - No wp_footer usage; assets injected inline
 * - No hardcoded REST in JS (dataset-driven)
 * - Toggle is UX-only for now (localStorage), no backend side effects
 * ==========================================================
 */

add_shortcode('knx_driver_quick_menu', function () {

    if (!function_exists('knx_get_driver_context')) {
        return '<div class="knx-qm__empty"><strong>Driver context unavailable.</strong></div>';
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || !is_object($ctx) || empty($ctx->session) || !is_object($ctx->session) || empty($ctx->session->user_id)) {
        return '<div class="knx-qm__empty"><strong>Unauthorized.</strong></div>';
    }

    $role = !empty($ctx->session->role) ? (string)$ctx->session->role : '';
    if (!in_array($role, array('driver', 'super_admin', 'manager'), true)) {
        return '<div class="knx-qm__empty"><strong>Forbidden.</strong></div>';
    }

    // Canonical routes (tabs)
    $route_quick   = '/driver-quick-menu';
    $route_ops     = '/driver-ops';
    $route_live    = '/driver-live-orders';
    $route_profile = '/driver-profile';

    // Optional API(s) for badge/counts (best-effort)
    // Available orders is canonical v2 driver endpoint
    $api_available = '/wp-json/knx/v2/driver/orders/available';

    // Nonces (for future POSTs; quick menu is mostly read-only)
    $wp_rest_nonce = wp_create_nonce('wp_rest');

    // Best-effort KNX nonce (if your session provides it; may be empty)
    $knx_nonce = '';
    if (!empty($ctx->session->knx_nonce)) $knx_nonce = (string)$ctx->session->knx_nonce;
    else if (!empty($ctx->session->nonce)) $knx_nonce = (string)$ctx->session->nonce;

    ob_start();
    ?>
    <div
        id="knx-driver-quick-menu"
        class="knx-qm knx-has-bottomnav"
        role="region"
        aria-label="Driver quick menu"
        data-route-quick="<?php echo esc_attr($route_quick); ?>"
        data-route-ops="<?php echo esc_attr($route_ops); ?>"
        data-route-live="<?php echo esc_attr($route_live); ?>"
        data-route-profile="<?php echo esc_attr($route_profile); ?>"
        data-api-available="<?php echo esc_attr($api_available); ?>"
        data-wp-rest-nonce="<?php echo esc_attr($wp_rest_nonce); ?>"
        data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
        data-online-storage-key="knx_driver_online_v1"
        data-location-storage-key="knx_driver_location_v1"
    >
        <!-- Header -->
        <div class="knx-qm__header">
            <div class="knx-qm__title">Delivery Partner</div>

            <div class="knx-qm__toggle">
                <div class="knx-qm__toggle-label" id="knxQmOnlineLabel">Offline</div>

                <button
                    type="button"
                    class="knx-qm__switch"
                    id="knxQmOnlineSwitch"
                    role="switch"
                    aria-checked="false"
                    aria-label="Toggle online status"
                >
                    <span class="knx-qm__switch-track" aria-hidden="true"></span>
                    <span class="knx-qm__switch-knob" aria-hidden="true"></span>
                </button>
            </div>
        </div>

        <div class="knx-qm__divider" aria-hidden="true"></div>

        <!-- Banner -->
        <div class="knx-qm__banner" id="knxQmBanner" role="status" aria-live="polite">
            <span class="knx-qm__dot" aria-hidden="true"></span>
            <span id="knxQmBannerText">You are offline</span>
        </div>

        <!-- Stats (visual pattern only; data best-effort) -->
        <div class="knx-qm__stats" role="group" aria-label="Overview">
            <a class="knx-qm__stat" href="<?php echo esc_attr($route_live); ?>" aria-label="View live orders">
                <div class="knx-qm__stat-ico" aria-hidden="true">
                    <!-- Dollar icon (outline) -->
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M12 2v20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M17 7.5c0-1.9-2.2-3.5-5-3.5s-5 1.6-5 3.5S9.2 11 12 11s5 1.6 5 3.5S14.8 18 12 18s-5-1.6-5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="knx-qm__stat-body">
                    <div class="knx-qm__stat-label">Active Orders</div>
                    <div class="knx-qm__stat-value" id="knxQmActiveCount">—</div>
                </div>
            </a>

            <a class="knx-qm__stat" href="<?php echo esc_attr($route_ops); ?>" aria-label="Go to driver ops">
                <div class="knx-qm__stat-ico knx-qm__stat-ico--blue" aria-hidden="true">
                    <!-- Box icon -->
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M21 8 12 3 3 8l9 5 9-5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <path d="M21 8v8l-9 5-9-5V8" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <path d="M12 13v9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="knx-qm__stat-body">
                    <div class="knx-qm__stat-label">Available</div>
                    <div class="knx-qm__stat-value" id="knxQmAvailableCount">—</div>
                </div>
            </a>
        </div>

        <!-- Quick Actions card -->
        <div class="knx-qm__card">
            <div class="knx-qm__card-title">Quick Actions</div>

            <div class="knx-qm__actions" role="menu" aria-label="Quick actions">
                <a role="menuitem" class="knx-qm__action" href="<?php echo esc_attr($route_live); ?>">
                    <span class="knx-qm__action-ico" aria-hidden="true">
                        <!-- Box -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M21 8 12 3 3 8l9 5 9-5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                            <path d="M21 8v8l-9 5-9-5V8" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="knx-qm__action-text">View Orders</span>
                </a>

                <a role="menuitem" class="knx-qm__action" href="<?php echo esc_attr($route_ops); ?>">
                    <span class="knx-qm__action-ico" aria-hidden="true">
                        <!-- Compass-ish -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z" stroke="currentColor" stroke-width="2"/>
                            <path d="M14.5 9.5 10 10l-.5 4.5L14 14l.5-4.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="knx-qm__action-text">Go to Driver OPS</span>
                </a>

                <a role="menuitem" class="knx-qm__action" href="<?php echo esc_attr($route_profile); ?>">
                    <span class="knx-qm__action-ico" aria-hidden="true">
                        <!-- User -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M20 21a8 8 0 1 0-16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M12 13a5 5 0 1 0-5-5 5 5 0 0 0 5 5Z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </span>
                    <span class="knx-qm__action-text">Profile</span>
                </a>
            </div>
        </div>

        <!-- Location card -->
        <button type="button" class="knx-qm__card knx-qm__loccard" id="knxQmLocationCard" aria-label="Update current location">
            <div class="knx-qm__location">
                <span class="knx-qm__loc-ico" aria-hidden="true">
                    <!-- Pin -->
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path d="M12 22s7-4.5 7-12a7 7 0 1 0-14 0c0 7.5 7 12 7 12Z" stroke="currentColor" stroke-width="2"/>
                        <circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </span>

                <span class="knx-qm__loc-body">
                    <span class="knx-qm__loc-label">Current Location</span>
                    <span class="knx-qm__loc-text" id="knxQmLocationText">—</span>
                </span>
            </div>
        </button>

        <!-- Toast anchor (optional; JS uses global knxToast if present) -->
        <div class="knx-qm__toast" id="knxQmToast" aria-live="polite" aria-atomic="true"></div>
    </div>
    <?php

    // Bottom navbar (4 tabs only)
    if (function_exists('knx_driver_bottom_nav_render')) {
        knx_driver_bottom_nav_render([
            'current' => 'quick',
            'quick_url' => $route_quick,
            'ops_url' => $route_ops,
            'live_url' => $route_live,
            'profile_url' => $route_profile,
            'last_active_order_id' => 0,
        ]);
    }

    // Inject CSS
    $css_path = __DIR__ . '/driver-quick-menu-style.css';
    if (file_exists($css_path)) {
        echo '<style data-knx="driver-quick-menu-style">' . file_get_contents($css_path) . '</style>';
    }

    // Inject JS
    $js_path = __DIR__ . '/driver-quick-menu-script.js';
    if (file_exists($js_path)) {
        echo '<script data-knx="driver-quick-menu-script">' . file_get_contents($js_path) . '</script>';
    }

    return ob_get_clean();
});
