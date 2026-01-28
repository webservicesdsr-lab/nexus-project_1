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
    $public_pages    = ['home', 'about', 'contact', 'terms', 'privacy', 'login', 'register'];
    $restricted_pages = ['hubs', 'drivers', 'customers', 'cities', 'advanced-dashboard', 'dashboard', 'account-settings'];
    $dashboard_pages = ['hubs', 'drivers', 'customers', 'cities', 'advanced-dashboard', 'dashboard'];

    // Redirect logged-in users away from login/register
    if ($session && in_array($slug, ['login', 'register'])) {
        wp_safe_redirect(site_url('/cart'));
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
    }
});
