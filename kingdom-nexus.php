<?php
/**
 * Plugin Name: Kingdom Nexus
 * Description: Modular secure framework for authentication, roles, and dashboards with smart redirects.
 * Version: 2.8.5
 * Author: Kingdom Builders
 */

if (!defined('ABSPATH')) exit;

// Guard plugin constants to prevent redefinition errors
if (!defined('KNX_PATH')) {
    define('KNX_PATH', plugin_dir_path(__FILE__));
}
if (!defined('KNX_PLUGIN_DIR')) {
    define('KNX_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('KNX_URL')) {
    define('KNX_URL', plugin_dir_url(__FILE__));
}
if (!defined('KNX_VERSION')) {
    define('KNX_VERSION', '2.8.5');
}

if (!is_ssl() && !defined('WP_DEBUG')) {
    if (strpos($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '', 'https') !== false) {
        $_SERVER['HTTPS'] = 'on';
    }
}

/**
 * KNX-A4.1-BOOT-SESSION-FIX
 */
add_action('init', function() {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict'
        ]);
    }
}, 1);

function knx_require($relative) {
    $path = KNX_PATH . ltrim($relative, '/');
    if (file_exists($path)) {
        require_once $path;
    }
}

function knx_activate_plugin() {
    require_once KNX_PATH . 'inc/core/pages-installer.php';

    if (function_exists('knx_install_pages')) {
        knx_install_pages();
    }

    // Ensure default worker config exists
    if (get_option('knx_dn_max_attempts') === false) {
        add_option('knx_dn_max_attempts', 3);
    }
    if (get_option('knx_dn_backoff_base_seconds') === false) {
        add_option('knx_dn_backoff_base_seconds', 30);
    }
    if (get_option('knx_dn_backoff_max_seconds') === false) {
        add_option('knx_dn_backoff_max_seconds', 3600);
    }
    if (get_option('knx_dn_failed_retention_days') === false) {
        add_option('knx_dn_failed_retention_days', 30);
    }
    if (get_option('knx_dn_delivered_retention_days') === false) {
        add_option('knx_dn_delivered_retention_days', 90);
    }

    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'knx_activate_plugin');

add_action('plugins_loaded', function() {

    /* ======================================================
     * FUNCTIONS (LOAD ORDER CRITICAL)
     * ====================================================== */
    knx_require('inc/functions/helpers.php');
    knx_require('inc/functions/security.php');

    knx_require('inc/functions/customer-helpers.php');
    knx_require('inc/functions/order-helpers.php');
    knx_require('inc/functions/payment-helpers.php');

    /* ======================================================
     * STRIPE (SSOT SEALED)
     * ====================================================== */
    knx_require('inc/functions/stripe-logger.php');
    knx_require('inc/core/resources/knx-payments/stripe-authority.php');
    knx_require('inc/functions/stripe-helpers.php');

    // Addresses
    knx_require('inc/functions/address-helper.php');

    // Geo + delivery engines
    knx_require('inc/functions/geo-engine.php');
    knx_require('inc/functions/coverage-parser-internal.php');
    knx_require('inc/functions/coverage-engine.php');
    knx_require('inc/functions/distance-calculator.php');
    knx_require('inc/functions/delivery-fee-engine.php');
    knx_require('inc/functions/hours-engine.php');
    knx_require('inc/functions/availability-engine.php');
    knx_require('inc/functions/totals-engine.php');
    knx_require('inc/functions/taxes-engine.php');

    // Navigation
    knx_require('inc/functions/navigation-engine.php');

    // Hub Management ownership helpers
    knx_require('inc/functions/hub-ownership.php');

    /* ======================================================
     * REST INFRASTRUCTURE
     * ====================================================== */
    knx_require('inc/core/rest/knx-rest-response.php');
    knx_require('inc/core/rest/knx-rest-guard.php');
    knx_require('inc/core/rest/knx-rest-wrapper.php');

    /* ======================================================
     * CORE
     * ====================================================== */
    knx_require('inc/core/pages-installer.php');
    knx_require('inc/core/session-cleaner.php');
    knx_require('inc/core/cart/cart-cleanup.php');

    knx_require('inc/core/api-delivery-rates.php');

    /* ======================================================
     * RESOURCES — KNX HUBS
     * ====================================================== */
    knx_require('inc/core/resources/knx-hubs/api-hub-categories.php');
    knx_require('inc/core/resources/knx-hubs/api-hubs-core.php');
    knx_require('inc/core/resources/knx-hubs/api-hubs.php');
    knx_require('inc/core/resources/knx-hubs/api-get-hub.php');
    knx_require('inc/core/resources/knx-hubs/api-toggle-featured.php');
    knx_require('inc/core/resources/knx-hubs/api-update-hub-slug.php');
    knx_require('inc/core/resources/knx-hubs/api-upload-logo.php');
    knx_require('inc/core/resources/knx-hubs/api-edit-hub-identity.php');
    knx_require('inc/core/resources/knx-hubs/api-hub-location.php');
    knx_require('inc/core/resources/knx-hubs/api-hub-hours.php');
    knx_require('inc/core/resources/knx-hubs/api-update-closure.php');
    knx_require('inc/core/resources/knx-hubs/api-delete-hub.php');
    knx_require('inc/core/resources/knx-hubs/api-update-settings.php');
    knx_require('inc/core/resources/knx-hubs/api-time-slots.php');
    knx_require('inc/core/resources/knx-hubs/api-hub-dashboard.php');
    knx_require('inc/core/resources/knx-hubs/api-hub-management-settings.php');
    knx_require('inc/modules/hubs/api-ft-locations.php');
    knx_require('inc/core/resources/knx-hubs/api-hub-managers.php');
    knx_require('inc/core/resources/knx-hubs/api-hub-orders.php');
    knx_require('inc/core/resources/knx-settings/api-brand-logo-upload.php');

    /* ======================================================
     * RESOURCES — KNX CART
     * ====================================================== */
    knx_require('inc/core/resources/knx-cart/api-cart.php');

    /* ======================================================
     * RESOURCES — KNX ADDRESSES
     * ====================================================== */
    knx_require('inc/core/resources/knx-addresses/api-addresses.php');

    /* ======================================================
     * RESOURCES — KNX PROFILE
     * ====================================================== */
    knx_require('inc/core/resources/knx-profile/api-get-profile.php');
    knx_require('inc/core/resources/knx-profile/api-update-profile.php');
    knx_require('inc/core/resources/knx-profile/api-change-password.php');
    knx_require('inc/core/resources/knx-profile/api-change-username.php');

    /* ======================================================
     * RESOURCES — KNX CUSTOMERS
     * ====================================================== */
    knx_require('inc/core/resources/knx-customers/api-list-customers.php');

    /* ======================================================
     * RESOURCES — KNX ORDERS
     * ====================================================== */
    knx_require('inc/core/knx-orders/api-create-order-mvp.php');
    knx_require('inc/core/knx-orders/api-quote-totals.php');
    knx_require('inc/core/knx-orders/api-get-order.php');
    knx_require('inc/core/knx-orders/api-list-orders.php');
    knx_require('inc/core/knx-orders/api-update-order-status.php');
    knx_require('inc/core/knx-orders/api-order-messages.php');

    /* ======================================================
     * RESOURCES — KNX PAYMENTS
     * ====================================================== */
    knx_require('inc/core/resources/knx-payments/api-create-payment-intent.php');
    knx_require('inc/core/resources/knx-payments/api-payment-webhook.php');
    knx_require('inc/core/resources/knx-payments/api-payment-status.php');

    /* ======================================================
     * RESOURCES — KNX CHECKOUT
     * ====================================================== */
    knx_require('inc/core/resources/knx-checkout/api-checkout-prevalidate.php');
    knx_require('inc/core/resources/knx-checkout/api-checkout-quote.php');

    /* ======================================================
     * RESOURCES — KNX LOCATION
     * ====================================================== */
    knx_require('inc/core/resources/knx-location/api-check-coverage.php');
    knx_require('inc/core/resources/knx-location/api-location-search.php');
    knx_require('inc/core/resources/knx-geocode-search.php');
    knx_require('inc/core/resources/knx-fees/api-software-fees.php');

    /* ======================================================
     * RESOURCES — KNX EXPLORE
     * ====================================================== */
    knx_require('inc/core/resources/knx-explore/api-explore-hubs.php');

    /* ======================================================
     * RESOURCES — KNX SYSTEM
     * ====================================================== */
    knx_require('inc/core/resources/knx-system/api-hours-extension.php');
    knx_require('inc/core/resources/knx-system/api-settings.php');

    /* ======================================================
     * RESOURCES — KNX ITEMS
     * ====================================================== */
    knx_require('inc/core/resources/knx-items/api-get-item-categories.php');
    knx_require('inc/core/resources/knx-items/api-save-item-category.php');
    knx_require('inc/core/resources/knx-items/api-toggle-item-category.php');
    knx_require('inc/core/resources/knx-items/api-delete-item-category.php');
    knx_require('inc/core/resources/knx-items/api-reorder-item-category.php');
    knx_require('inc/core/resources/knx-items/api-hub-items.php');
    knx_require('inc/core/resources/knx-items/api-upload-hub-items-csv.php');
    knx_require('inc/core/resources/knx-items/api-export-hub-items-csv.php');
    knx_require('inc/core/resources/knx-items/api-update-item.php');
    knx_require('inc/core/resources/knx-items/api-reorder-item.php');
    knx_require('inc/core/resources/knx-items/api-item-addons.php');
    knx_require('inc/core/resources/knx-items/api-menu-read.php');
    knx_require('inc/core/resources/knx-items/api-modifiers.php');

    /* ======================================================
     * RESOURCES — KNX CITIES
     * ====================================================== */
    knx_require('inc/core/resources/knx-cities/api-edit-city.php');
    knx_require('inc/core/resources/knx-cities/get-cities.php');
    knx_require('inc/core/resources/knx-cities/post-operational-toggle.php');
    knx_require('inc/core/resources/knx-cities/add-city.php');
    knx_require('inc/core/resources/knx-cities/delete-city.php');
    knx_require('inc/core/resources/knx-cities/get-delivery-rates.php');
    knx_require('inc/core/resources/knx-cities/update-delivery-rates.php');
    knx_require('inc/core/resources/knx-cities/api-city-branding.php');

    /* ======================================================
     * RESOURCES — MVP DELIVERY
     * ====================================================== */
    knx_require('inc/core/resources/knx-ops/api-live-orders.php');
    knx_require('inc/core/resources/knx-ops/api-all-orders.php');
    knx_require('inc/core/resources/knx-ops/api-dashboard.php');
    knx_require('inc/core/resources/knx-ops/api-view-order.php');
    knx_require('inc/core/resources/knx-ops/api-drivers.php');
    knx_require('inc/core/resources/knx-ops/api-assign-driver.php');
    knx_require('inc/core/resources/knx-ops/api-update-status.php');
    knx_require('inc/core/resources/knx-ops/api-unassign-driver.php');
    knx_require('inc/core/resources/knx-ops/api-driver-available-orders.php');
    knx_require('inc/core/resources/knx-ops/api-driver-active-orders.php');
    knx_require('inc/core/resources/knx-ops/api-driver-self-assign.php');
    knx_require('inc/core/resources/knx-ops/knx-ops-availability.php');
    knx_require('inc/core/resources/knx-ops/api-driver-orders.php');

    /* ======================================================
     * PUSH / NOTIFICATIONS
     * ====================================================== */
    knx_require('inc/core/resources/knx-push/driver-soft-push-routes.php');
    knx_require('inc/core/resources/knx-push/push-sender.php');
    knx_require('inc/core/resources/knx-push/ntfy-sender.php');
    knx_require('inc/core/resources/knx-push/worker.php');
    knx_require('inc/core/resources/knx-push/cli-worker.php');

    /* ======================================================
     * RESOURCES — KNX DRIVERS
     * ====================================================== */
    knx_require('inc/core/resources/knx-drivers/api-drivers-crud.php');

    /* ======================================================
     * MODULES — FEES
     * ====================================================== */
    knx_require('inc/modules/fees/fees-shortcode.php');

    /* ======================================================
     * RESOURCES — KNX CUSTOMERS (CRUD)
     * ====================================================== */
    knx_require('inc/core/resources/knx-customers/api-customers-crud.php');

    /* ======================================================
     * MODULES — CUSTOMERS ADMIN
     * ====================================================== */
    knx_require('inc/modules/customers/customers-shortcode.php');

    /* ======================================================
     * MODULES — OPS
     * ====================================================== */
    knx_require('inc/modules/ops/live-orders/live-orders-shortcode.php');
    knx_require('inc/modules/ops/all-orders/all-orders-shortcode.php');
    knx_require('inc/modules/ops/dashboard/dashboard-shortcode.php');
    knx_require('inc/modules/ops/view-order/view-order-shortcode.php');
    knx_require('inc/modules/ops/driver-ops/driver-ops-shortcode.php');
    knx_require('inc/modules/ops/driver-notifier-shortcode.php');
    knx_require('inc/modules/ops/driver-live-orders/driver-live-orders-shortcode.php');
    knx_require('inc/modules/ops/driver-active-orders/driver-active-orders-shortcode.php');

    /* ======================================================
     * MODULES — DRIVERS
     * ====================================================== */
    knx_require('inc/modules/drivers/active-orders/driver-active-orders-shortcode.php');
    knx_require('inc/modules/ops/driver-view-order/driver-view-order-shortcode.php');

    knx_require('inc/modules/ops/driver-quick-menu/driver-quick-menu-shortcode.php');
    knx_require('inc/modules/ops/driver-profile/driver-profile-shortcode.php');
    knx_require('inc/modules/ops/driver-bottom-nav/driver-bottom-nav.php');

    knx_require('inc/modules/drivers/drivers-shortcode.php');

    /* ======================================================
     * MODULES — DRIVER NOTIFICATIONS
     * ====================================================== */
    knx_require('inc/modules/driver-notifications/driver-notifications-bootstrap.php');

    /* ======================================================
     * MODULES — SYSTEM EMAILS
     * ====================================================== */
    knx_require('inc/modules/system-emails/system-emails-bootstrap.php');

    /* ======================================================
     * RESOURCES — KNX COUPONS
     * ====================================================== */
    knx_require('inc/core/resources/knx-coupons/api-coupons-crud.php');
    knx_require('inc/core/resources/knx-coupons/api-validate-coupon.php');

    /* ======================================================
     * MODULES — COUPONS ADMIN
     * ====================================================== */
    knx_require('inc/modules/coupons/coupons-shortcode.php');

    /* ======================================================
     * MODULES — KNX CITIES
     * ====================================================== */
    knx_require('inc/modules/knx-cities/knx-cities-shortcode.php');
    knx_require('inc/modules/knx-cities/knx-edit-city.php');

    /* ======================================================
     * MENU (SEO)
     * ====================================================== */
    knx_require('inc/public/menu/menu-rewrite-rules.php');
    knx_require('inc/public/menu/menu-shortcode.php');

    /* ======================================================
     * MODULES — LEGACY
     * ====================================================== */
    knx_require('inc/modules/hubs/hubs-shortcode.php');
    knx_require('inc/modules/hubs/hub-dashboard-shortcode.php');
    knx_require('inc/modules/hubs/hub-settings-shortcode.php');
    knx_require('inc/modules/hubs/hub-items-shortcode.php');
    knx_require('inc/modules/hubs/hub-managers-shortcode.php');
    knx_require('inc/modules/hubs/hub-orders-shortcode.php');
    knx_require('inc/modules/hubs/hub-bottom-nav/hub-bottom-nav.php');
    knx_require('inc/modules/hubs/edit-hub-template.php');
    knx_require('inc/modules/hubs/edit-hub-identity.php');

    knx_require('inc/modules/hub-categories/hub-categories-shortcode.php');

    knx_require('inc/modules/items/edit-hub-items.php');
    knx_require('inc/modules/items/edit-item-categories.php');
    knx_require('inc/modules/items/edit-item.php');

    /* ======================================================
     * NAVIGATION
     * ====================================================== */
    knx_require('inc/public/navigation/navbar.php');
    knx_require('inc/public/navigation/corporate-sidebar.php');

    knx_require('inc/modules/auth/auth-shortcode.php');
    knx_require('inc/modules/auth/reset-shortcode.php');
    knx_require('inc/modules/auth/auth-handler.php');
    knx_require('inc/modules/auth/auth-redirects.php');

    knx_require('inc/modules/admin/admin-menu.php');
    knx_require('inc/modules/settings/settings-shortcode.php');

    /* ======================================================
     * PUBLIC FRONTEND
     * ====================================================== */
    knx_require('inc/public/home/home-shortcode.php');
    knx_require('inc/shortcodes/cities-grid-shortcode.php');
    knx_require('inc/public/explore-hubs/explore-hubs-shortcode.php');
    knx_require('inc/public/cart/cart-shortcode.php');
    knx_require('inc/public/addresses/my-addresses-shortcode.php');
    knx_require('inc/public/profile/profile-shortcode.php');
    knx_require('inc/public/checkout/checkout-shortcode.php');
    knx_require('inc/public/orders/order-status-shortcode.php');
    knx_require('inc/public/orders/my-orders-shortcode.php');
    knx_require('inc/public/support/contact-shortcode.php');
});

/**
 * Initialize Stripe logger.
 */
add_action('plugins_loaded', 'knx_init_stripe_logging', 20);
function knx_init_stripe_logging() {
    if (function_exists('knx_stripe_log_init')) {
        knx_stripe_log_init();
    }
}

if (!wp_next_scheduled('knx_hourly_cleanup')) {
    wp_schedule_event(time(), 'hourly', 'knx_hourly_cleanup');
}
add_action('knx_hourly_cleanup', 'knx_cleanup_sessions');

// Add a short cron schedule for worker runs (fallback). Adds a 1-minute interval.
add_filter('cron_schedules', function($schedules){
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = ['interval' => 60, 'display' => 'Every Minute'];
    }
    return $schedules;
});

if (!wp_next_scheduled('knx_dn_worker_cron')) {
    wp_schedule_event(time(), 'every_minute', 'knx_dn_worker_cron');
}

/**
 * Return true when the current frontend session belongs to a driver.
 *
 * @return bool
 */
function knx_is_driver_session_active() {
    if (!function_exists('knx_get_driver_context')) {
        return false;
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || empty($ctx->session) || !is_object($ctx->session)) {
        return false;
    }

    return !empty($ctx->session->user_id) && ((string)($ctx->session->role ?? '') === 'driver');
}

/**
 * Render the canonical browser push bootstrap for any authenticated driver session.
 * This is the single global authority for:
 * - config exposure
 * - service worker registration
 * - soft-push client loading
 *
 * Browser push now respects the dedicated browser switch.
 */
function knx_render_driver_soft_push_bootstrap() {
    if (is_admin()) {
        return;
    }

    if (!knx_is_driver_session_active()) {
        return;
    }

    $ctx = function_exists('knx_get_driver_context') ? knx_get_driver_context() : null;
    $uid = ($ctx && !empty($ctx->session->user_id)) ? (int)$ctx->session->user_id : 0;
    if ($uid <= 0) {
        return;
    }

    $browser_enabled = get_user_meta($uid, 'knx_browser_push_enabled', true);
    $browser_enabled = ($browser_enabled === '' || is_null($browser_enabled)) ? '1' : ($browser_enabled ? '1' : '0');

    if ($browser_enabled !== '1') {
        return;
    }

    $config = [
        'softPushPoll'    => rest_url('knx/v1/driver-soft-push/poll'),
        'prefsUrl'        => rest_url('knx/v1/driver-soft-push/prefs'),
        'ackUrl'          => rest_url('knx/v1/driver-soft-push/ack'),
        'testUrl'         => rest_url('knx/v1/driver-soft-push/test-ntfy'),
        'activeOrdersUrl' => site_url('/driver-active-orders'),
        'swUrl'           => KNX_URL . 'inc/core/pwa/knx-ops-sw.js',
        'pollVisibleMs'   => 9000,
        'pollHiddenMs'    => 25000,
        'debug'           => (defined('WP_DEBUG') && WP_DEBUG),
    ];
    ?>
    <script>
    (function () {
        window.KNX_DRIVER_OPS_CONFIG = window.KNX_DRIVER_OPS_CONFIG || {};
        var incoming = <?php echo wp_json_encode($config); ?>;
        for (var key in incoming) {
            if (Object.prototype.hasOwnProperty.call(incoming, key)) {
                window.KNX_DRIVER_OPS_CONFIG[key] = incoming[key];
            }
        }

        if (window.__KNX_DRIVER_SOFT_PUSH_SW_REGISTERED__) {
            return;
        }
        window.__KNX_DRIVER_SOFT_PUSH_SW_REGISTERED__ = true;

        if (!('serviceWorker' in navigator)) {
            return;
        }

        try {
            var swUrl = window.KNX_DRIVER_OPS_CONFIG.swUrl;
            if (!swUrl) {
                return;
            }

            navigator.serviceWorker.register(swUrl).catch(function (err) {
                try { console.warn('KNX: service worker registration failed.', err); } catch (e) {}
            });
        } catch (err) {
            try { console.warn('KNX: service worker bootstrap failed.', err); } catch (e) {}
        }
    })();
    </script>
    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/ops/driver-ops/driver-soft-push-client.js?v=' . KNX_VERSION); ?>" defer></script>
    <?php
}

// Force FontAwesome and core assets on ALL pages with high priority
add_action('wp_enqueue_scripts', 'knx_enqueue_public_assets', 5);
add_action('admin_enqueue_scripts', 'knx_enqueue_public_assets', 5);
add_action('wp_head', 'knx_force_fontawesome_head', 1);
add_action('wp_head', 'knx_render_driver_soft_push_bootstrap', 2);

function knx_enqueue_public_assets() {
    wp_enqueue_style(
        'knx-fontawesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
        [],
        '6.5.1'
    );

    wp_enqueue_style(
        'knx-toast',
        KNX_URL . 'inc/modules/core/knx-toast.css',
        [],
        KNX_VERSION
    );

    wp_enqueue_script(
        'knx-toast',
        KNX_URL . 'inc/modules/core/knx-toast.js',
        [],
        KNX_VERSION,
        true
    );

    wp_enqueue_style(
        'choices-js',
        'https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css'
    );

    wp_enqueue_script(
        'choices-js',
        'https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js',
        [],
        null,
        true
    );
}

// Fallback: Force FontAwesome in <head> if WordPress doesn't enqueue it
function knx_force_fontawesome_head() {
    if (!wp_style_is('knx-fontawesome', 'enqueued')) {
        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />' . "\n";
    }
}