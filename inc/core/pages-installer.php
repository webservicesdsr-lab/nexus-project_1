<?php
/**
 * KNX Minimal Pages Installer
 * Purpose: Provide a no-op installer to allow plugin activation.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('knx_install_pages')) {
    /**
     * Create required plugin pages on activation.
     *
     * This function will create top-level pages that render the
     * plugin's standalone shortcodes if they do not already exist.
     * It is safe to call multiple times.
     *
     * @return bool True on completion
     */
    function knx_install_pages() {
        if (!function_exists('get_page_by_path') || !function_exists('wp_insert_post')) {
            // WP not loaded (or early bootstrap) — no-op
            return false;
        }

        // Helper: robust check for existing page by slug/post_name.
        // Excludes trashed pages to avoid treating a trashed duplicate as valid.
        if (!function_exists('knx_page_exists_by_slug')) {
            function knx_page_exists_by_slug($slug) {
                $args = [
                    'name'        => sanitize_title($slug),
                    'post_type'   => 'page',
                    'post_status' => [
                        'publish',
                        'private',
                        'draft',
                        'pending',
                        'future',
                        'inherit'
                    ],
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                ];

                $found = get_posts($args);
                if (!empty($found)) return (int) $found[0];

                // Fallback: try get_page_by_path (handles hierarchical paths)
                $by_path = get_page_by_path($slug, OBJECT, 'page');
                if ($by_path && !in_array($by_path->post_status, ['trash', 'auto-draft'], true)) {
                    return (int) $by_path->ID;
                }

                return 0;
            }
        }

        $pages = [
            // Public-facing pages
            ['title' => 'Home', 'slug' => 'home', 'content' => '[knx_home]'],
            ['title' => 'Explore Hubs', 'slug' => 'explore-hubs', 'content' => '[olc_explore_hubs]'],
            ['title' => 'Menu', 'slug' => 'menu', 'content' => '[knx_menu]'],
            ['title' => 'Cart', 'slug' => 'cart', 'content' => '[knx_cart_page]'],
            ['title' => 'Checkout', 'slug' => 'checkout', 'content' => '[knx_checkout]'],
            ['title' => 'Order Status', 'slug' => 'order-status', 'content' => '[knx_order_status]'],
            ['title' => 'Profile', 'slug' => 'profile', 'content' => '[knx_profile]'],
            ['title' => 'Login', 'slug' => 'login', 'content' => '[knx_auth]'],
            ['title' => 'Reset Password', 'slug' => 'reset-password', 'content' => '[knx_reset_password]'],
            ['title' => 'My Addresses', 'slug' => 'my-addresses', 'content' => '[knx_my_addresses]'],

            // Administrative / management pages
            ['title' => 'Hubs', 'slug' => 'hubs', 'content' => '[knx_hubs]'],
            ['title' => 'Hub Categories', 'slug' => 'hub-categories', 'content' => '[knx_hub_categories]'],
            ['title' => 'Cities (Admin)', 'slug' => 'knx-cities', 'content' => '[knx_cities_signed]'],
            ['title' => 'Edit City', 'slug' => 'knx-edit-city', 'content' => '[knx_edit_city]'],
            ['title' => 'Customers', 'slug' => 'customers', 'content' => '[knx_customers_admin]'],
            ['title' => 'Coupons', 'slug' => 'coupons', 'content' => '[knx_coupons_admin]'],
            ['title' => 'Fees', 'slug' => 'fees', 'content' => '[knx_software_fees]'],
            ['title' => 'Edit Hub', 'slug' => 'edit-hub', 'content' => '[knx_edit_hub]'],
            ['title' => 'Edit Hub Items', 'slug' => 'edit-hub-items', 'content' => '[knx_edit_hub_items]'],
            ['title' => 'Edit Item', 'slug' => 'edit-item', 'content' => '[knx_edit_item]'],
            ['title' => 'Edit Item Categories', 'slug' => 'edit-item-categories', 'content' => '[knx_edit_item_categories]'],
            // Additional operational/admin pages
            ['title' => 'Driver Ops', 'slug' => 'driver-ops', 'content' => '[knx_driver_ops_dashboard]'],
            ['title' => 'Driver Live Orders', 'slug' => 'driver-live-orders', 'content' => '[knx_driver_live_orders]'],
            ['title' => 'Active Orders', 'slug' => 'driver-active-orders', 'content' => '[knx_driver_active_orders]'],
            // Driver quick & profile pages (shortcodes created by modules)
            ['title' => 'Driver Quick Menu', 'slug' => 'driver-quick-menu', 'content' => '[knx_driver_quick_menu]'],
            ['title' => 'Driver Profile', 'slug' => 'driver-profile', 'content' => '[knx_driver_profile]'],
            ['title' => 'Drivers Admin', 'slug' => 'drivers-admin', 'content' => '[knx_drivers_admin]'],
            // Canonical drivers admin page (matches navigation-engine admin nav)
            ['title' => 'Drivers', 'slug' => 'drivers', 'content' => '[knx_drivers_admin]'],
            ['title' => 'Live Orders', 'slug' => 'live-orders', 'content' => '[knx_ops_live_orders]'],
            ['title' => 'View Order', 'slug' => 'orders', 'content' => '[knx-view-orders]'],
            // Alternate path for OPS view-order (helps install a direct route)
            ['title' => 'View Order (OPS)', 'slug' => 'view-order', 'content' => '[knx-view-orders]'],
            // Legacy OPS pages removed as part of PHASE 13.CLEAN
        ];

        foreach ($pages as $p) {
            $slug = sanitize_title($p['slug']);

            $existing_id = knx_page_exists_by_slug($slug);
            if ($existing_id) {
                // Page exists (published/draft/private/etc) — skip creation
                continue;
            }

            $postarr = [
                'post_title'   => wp_strip_all_tags($p['title']),
                'post_name'    => $slug,
                'post_content' => $p['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ];

            // Insert page; do not attempt to update existing pages here.
            wp_insert_post($postarr);
        }

        return true;
    }
}
