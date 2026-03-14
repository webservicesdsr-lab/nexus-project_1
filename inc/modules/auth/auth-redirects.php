<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Auth Redirects (v2)
 *
 * Controls access flow and route restrictions across the site.
 * - Prevents logged-in users from viewing /login or /register
 * - Redirects unauthorized roles trying to access restricted pages
 * - Handles guest restrictions to protected dashboards
 */

add_action('template_redirect', function() {
    global $post;

    $session = knx_get_session();
    $slug = is_object($post) ? $post->post_name : '';

    // Define route categories
    $public_pages     = ['home', 'about', 'contact', 'terms', 'privacy', 'login', 'register'];
    $restricted_pages = ['hubs', 'drivers', 'customers', 'cities', 'advanced-dashboard', 'dashboard',
                         'account-settings', 'knx-dashboard', 'live-orders', 'orders', 'view-order',
                         'fees', 'coupons', 'knx-cities', 'knx-edit-city', 'edit-hub',
                         'edit-hub-items', 'edit-item', 'edit-item-categories', 'drivers-admin',
                         'menu-studio', 'capture'];
    $studio_pages     = ['menu-studio', 'capture'];
    $dashboard_pages  = ['hubs', 'drivers', 'customers', 'cities', 'advanced-dashboard', 'dashboard',
                         'knx-dashboard', 'live-orders', 'orders', 'fees', 'coupons',
                         'knx-cities', 'drivers-admin'];

    // Admin/manager roles: redirect away from login to their dashboard
    $admin_roles = ['super_admin', 'manager', 'hub_management', 'menu_uploader'];

    // Redirect logged-in users away from login/register
    if ($session && in_array($slug, ['login', 'register'])) {
        $role = $session->role ?? '';
        if (in_array($role, ['super_admin', 'manager'], true)) {
            wp_safe_redirect(site_url('/knx-dashboard'));
        } else {
            wp_safe_redirect(site_url('/cart'));
        }
        exit;
    }

    // Redirect guests trying to access restricted pages
    if (!$session && in_array($slug, $restricted_pages)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // Role-based restrictions
    if ($session) {
        $role = $session->role;

        // Customers cannot access dashboards
        if ($role === 'customer' && in_array($slug, $dashboard_pages)) {
            wp_safe_redirect(site_url('/cart'));
            exit;
        }

        // Menu uploader cannot access admin or driver pages
        if ($role === 'menu_uploader' && in_array($slug, ['drivers', 'advanced-dashboard'])) {
            wp_safe_redirect(site_url('/hubs'));
            exit;
        }

        // Manager cannot access super admin routes
        if ($role === 'manager' && $slug === 'advanced-dashboard') {
            wp_safe_redirect(site_url('/hubs'));
            exit;
        }

        // Drivers cannot access hubs or admin dashboards
        if ($role === 'driver' && in_array($slug, ['hubs', 'customers', 'cities', 'advanced-dashboard'])) {
            wp_safe_redirect(site_url('/driver-dashboard'));
            exit;
        }

        // Menu Studio: only allowed roles
        $studio_allowed = ['super_admin', 'manager', 'hub_management', 'menu_uploader'];
        if (in_array($slug, $studio_pages) && !in_array($role, $studio_allowed, true)) {
            wp_safe_redirect(site_url('/'));
            exit;
        }
    }
});
