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
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'knx_activate_plugin');

add_action('plugins_loaded', function() {

    /* ======================================================
     * FUNCTIONS (LOAD ORDER CRITICAL)
     * ====================================================== */
    knx_require('inc/functions/helpers.php');
    knx_require('inc/functions/security.php');

    // PWA (Driver) — Phase 6.1 (removed in PHASE 13.CLEAN)

    knx_require('inc/functions/customer-helpers.php');
    knx_require('inc/functions/order-helpers.php');          // KNX-A0.9.2: Order SSOT helpers
    knx_require('inc/functions/payment-helpers.php');        // KNX-A1.1: Payment State Authority

    /* ======================================================
     * STRIPE (SSOT SEALED) — Load BEFORE any payment handlers
     * ======================================================
     * SEALED ORDER:
     * 1) stripe-logger.php     — storage + redact rules
     * 2) stripe-authority.php  — SSOT: mode + keys + sdk boot + init
     * 3) stripe-helpers.php    — legacy wrappers ONLY (no SSOT functions)
     * ====================================================== */
    knx_require('inc/functions/stripe-logger.php');                          // 1) Logger first
    knx_require('inc/core/resources/knx-payments/stripe-authority.php');     // 2) SSOT authority
    knx_require('inc/functions/stripe-helpers.php');                         // 3) Legacy wrappers

    // Addresses (NEXUS 4.D)
    knx_require('inc/functions/address-helper.php');

    // Geo + delivery engines
    knx_require('inc/functions/geo-engine.php');               // FIRST: Pure math helpers
    knx_require('inc/functions/coverage-parser-internal.php'); // Internal: Polygon parsers (salvaged from legacy)
    knx_require('inc/functions/coverage-engine.php');          // KNX-A4.3: Polygon Coverage SSOT
    knx_require('inc/functions/distance-calculator.php');      // KNX-A4.4: Distance Calculator (Haversine)
    knx_require('inc/functions/delivery-fee-engine.php');      // KNX-A4.5: Delivery Fee Engine
    knx_require('inc/functions/hours-engine.php');
    knx_require('inc/functions/availability-engine.php');
    knx_require('inc/functions/totals-engine.php');            // depends on distance + fee helpers
    knx_require('inc/functions/taxes-engine.php');             // Phase 4: Taxes Engine (mock)

    // Navigation (Phase 3.6)
    knx_require('inc/functions/navigation-engine.php');        // Navigation SSOT Authority

    /* ======================================================
     * REST INFRASTRUCTURE (PHASE 1)
     * ====================================================== */
    knx_require('inc/core/rest/knx-rest-response.php');
    knx_require('inc/core/rest/knx-rest-guard.php');
    knx_require('inc/core/rest/knx-rest-wrapper.php');

    // PWA Router (serves manifest + service worker) removed

    /* ======================================================
     * CORE (LEGACY - STABLE)
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

    /* ======================================================
     * RESOURCES — KNX CART
     * ====================================================== */
    knx_require('inc/core/resources/knx-cart/api-cart.php');

    /* ======================================================
     * RESOURCES — KNX ADDRESSES (NEXUS 4.D)
     * ====================================================== */
    knx_require('inc/core/resources/knx-addresses/api-addresses.php');

    /* ======================================================
     * RESOURCES — KNX PROFILE (PHASE 2.BETA+)
     * ====================================================== */
    knx_require('inc/core/resources/knx-profile/api-get-profile.php');
    knx_require('inc/core/resources/knx-profile/api-update-profile.php');

    /* ======================================================
     * RESOURCES — KNX CUSTOMERS (PHASE 2.BETA+)
     * ====================================================== */
    knx_require('inc/core/resources/knx-customers/api-list-customers.php');

    /* ======================================================
     * RESOURCES — KNX ORDERS (MVP CANONICAL)
     * ====================================================== */
    knx_require('inc/core/knx-orders/api-create-order-mvp.php'); // MVP canonical
    knx_require('inc/core/knx-orders/api-quote-totals.php');     // read-only quote
    knx_require('inc/core/knx-orders/api-get-order.php');
    knx_require('inc/core/knx-orders/api-list-orders.php');
    knx_require('inc/core/knx-orders/api-update-order-status.php');

    /* ======================================================
     * RESOURCES — KNX PAYMENTS (A1.0-A1.3)
     * ======================================================
     * Stripe SDK + keys are managed ONLY by stripe-authority.php (SSOT).
     * ====================================================== */
    knx_require('inc/core/resources/knx-payments/api-create-payment-intent.php'); // A1.0: Intent creation
    knx_require('inc/core/resources/knx-payments/api-payment-webhook.php');       // A1.2: Webhook handler
    knx_require('inc/core/resources/knx-payments/api-payment-status.php');        // A1.1: Status polling (read-only)

    /* ======================================================
     * RESOURCES — KNX CHECKOUT
     * ====================================================== */
    knx_require('inc/core/resources/knx-checkout/api-checkout-prevalidate.php');
    knx_require('inc/core/resources/knx-checkout/api-checkout-quote.php'); // MVP canonical

    /* ======================================================
     * RESOURCES — KNX LOCATION
     * ====================================================== */
    knx_require('inc/core/resources/knx-location/api-check-coverage.php');
    knx_require('inc/core/resources/knx-location/api-location-search.php');

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
    knx_require('inc/core/resources/knx-items/api-update-item.php');
    knx_require('inc/core/resources/knx-items/api-reorder-item.php');
    knx_require('inc/core/resources/knx-items/api-item-addons.php');
    knx_require('inc/core/resources/knx-items/api-menu-read.php');
    knx_require('inc/core/resources/knx-items/api-modifiers.php');

    /* ======================================================
     * RESOURCES — KNX CITIES (SEALED)
     * ====================================================== */
    knx_require('inc/core/resources/knx-cities/api-edit-city.php');
    knx_require('inc/core/resources/knx-cities/get-cities.php');
    knx_require('inc/core/resources/knx-cities/post-operational-toggle.php');
    knx_require('inc/core/resources/knx-cities/add-city.php');
    knx_require('inc/core/resources/knx-cities/delete-city.php');
    knx_require('inc/core/resources/knx-cities/get-delivery-rates.php');
    knx_require('inc/core/resources/knx-cities/update-delivery-rates.php');

    /* ======================================================
     * RESOURCES — MVP DELIVERY (PHASE 3)
     * ====================================================== */
    // OPS Live Orders (push endpoint removed)

    /* ======================================================
     * RESOURCES — KNX DRIVERS (Driver App MVP)
     * ====================================================== */
    // Runtime APIs removed in PHASE 13/14 CLEAN (deleted)
    // Administrative drivers endpoints (CRUD) remain loaded.
    knx_require('inc/core/resources/knx-drivers/api-drivers.php');
    knx_require('inc/core/resources/knx-drivers/api-drivers-crud.php');
    // Push subscriptions and test endpoints removed (PHASE 13.CLEAN)

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
     * MODULES — OPS ORDERS (Admin / Manager)
     * NOTE: legacy OPS UI removed in PHASE 13.CLEAN — UI modules are deleted
     * ====================================================== */

    /* ======================================================
     * MODULES — ORDERS (Live admin dashboard)
     * ====================================================== */
    // Live orders module removed — use OPS dashboards instead

    /* ======================================================
     * MODULES — DRIVERS (Driver Dashboard)
     * NOTE: drivers UI removed in PHASE 13.CLEAN — UI modules are deleted
     * Backend driver resources were reviewed and ops backend removed.
     * ====================================================== */

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
     * MODULES — KNX CITIES (NEW UI)
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
    knx_require('inc/modules/hubs/edit-hub-template.php');
    knx_require('inc/modules/hubs/edit-hub-identity.php');

    knx_require('inc/modules/hub-categories/hub-categories-shortcode.php');

    knx_require('inc/modules/items/edit-hub-items.php');
    knx_require('inc/modules/items/edit-item-categories.php');
    knx_require('inc/modules/items/edit-item.php');

    // Navigation (Phase 3.6 — NAVIGATION CANON)
    knx_require('inc/public/navigation/navbar.php');              // Navbar: Public/Customer top nav
    knx_require('inc/public/navigation/corporate-sidebar.php');   // Corporate: Admin/Staff fixed sidebar

    // Customer navigation (future Phase 4/5)
    // knx_require('inc/public/navigation/sidebar.php');          // Customer: Profile/Orders sidebar

    // Legacy navigation (DEPRECATED - will be removed)
    // knx_require('inc/modules/navbar/navbar-render.php');
    // knx_require('inc/modules/sidebar/sidebar-render.php');

    knx_require('inc/modules/auth/auth-shortcode.php');
    knx_require('inc/modules/auth/auth-handler.php');
    knx_require('inc/modules/auth/auth-redirects.php');

    knx_require('inc/modules/admin/admin-menu.php');

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
});

/**
 * Initialize Stripe logger (no helpers boot here; SSOT boots on-demand).
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

// Force FontAwesome and core assets on ALL pages with high priority
add_action('wp_enqueue_scripts', 'knx_enqueue_public_assets', 5);
add_action('admin_enqueue_scripts', 'knx_enqueue_public_assets', 5);
add_action('wp_head', 'knx_force_fontawesome_head', 1);

function knx_enqueue_public_assets() {
    // FontAwesome 6.5.1
    wp_enqueue_style(
        'knx-fontawesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
        [],
        '6.5.1'
    );

    // Toast notifications
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

    // Choices.js
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
