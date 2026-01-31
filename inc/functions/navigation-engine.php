<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — NAVIGATION ENGINE (SSOT)
 * ════════════════════════════════════════════════════════════════
 * 
 * PURPOSE:
 * - Single source of truth for navigation items
 * - Role-based filtering (fail-closed)
 * - Session-aware rendering
 * - Active state calculation
 * 
 * PRINCIPLES:
 * - Backend authority (UX reflects, not decides)
 * - No phantom links (if not allowed, not shown)
 * - Fail-closed (no session = no private nav)
 * - Extensible (drivers, dashboards, ops)
 * 
 * @package KingdomNexus
 * @since Phase 3.6
 */

/**
 * Get navigation context from current session
 * 
 * @return array {
 *   is_logged: bool,
 *   username: string,
 *   role: string (guest|customer|super_admin|manager|etc),
 *   is_admin: bool,
 *   is_customer: bool,
 *   is_driver: bool,
 *   current_slug: string
 * }
 */
if (!function_exists('knx_get_navigation_context')) {
    function knx_get_navigation_context() {
        global $post;
        
        $session = function_exists('knx_get_session') ? knx_get_session() : null;
        $slug = is_object($post) ? $post->post_name : '';
        
        $is_logged = $session ? true : false;
        $username = $session ? ($session->username ?? '') : '';
        $role = $session ? ($session->role ?? 'guest') : 'guest';
        
        // Admin roles
        $admin_roles = ['super_admin', 'manager', 'hub_management', 'menu_uploader'];
        $is_admin = in_array($role, $admin_roles, true);
        
        // Customer role
        $is_customer = ($role === 'customer');
        
        // Driver role (future-safe)
        $is_driver = ($role === 'driver');
        
        return [
            'is_logged' => $is_logged,
            'username' => $username,
            'role' => $role,
            'is_admin' => $is_admin,
            'is_customer' => $is_customer,
            'is_driver' => $is_driver,
            'current_slug' => $slug,
        ];
    }
}

/**
 * Get navigation items for specific area
 * 
 * @param string $area 'public'|'customer'|'admin'|'driver'
 * @return array Navigation items
 */
if (!function_exists('knx_get_nav_items')) {
    function knx_get_nav_items($area = 'public') {
        $items = [];
        
        switch ($area) {
            case 'public':
                // Public navigation (guest users)
                $items = [
                    [
                        'id' => 'home',
                        'label' => 'Home',
                        'route' => '/',
                        'icon' => 'home',
                        'roles' => ['*'], // All roles
                        'area' => 'public',
                        'active_slugs' => ['home', ''],
                    ],
                    [
                        'id' => 'explore',
                        'label' => 'Explore',
                        'route' => '/explore-hubs',
                        'icon' => 'compass',
                        'roles' => ['*'],
                        'area' => 'public',
                        'active_slugs' => ['explore-hubs'],
                    ],
                ];
                break;
                
            case 'customer':
                // Customer navigation (logged in customers)
                $items = [
                    [
                        'id' => 'explore',
                        'label' => 'Explore',
                        'route' => '/explore-hubs',
                        'icon' => 'compass',
                        'roles' => ['customer'],
                        'area' => 'customer',
                        'active_slugs' => ['explore-hubs'],
                    ],
                    [
                        'id' => 'my-orders',
                        'label' => 'My Orders',
                        'route' => '/my-orders',
                        'icon' => 'receipt',
                        'roles' => ['customer'],
                        'area' => 'customer',
                        'active_slugs' => ['my-orders'],
                    ],
                    [
                        'id' => 'my-addresses',
                        'label' => 'My Addresses',
                        'route' => '/my-addresses',
                        'icon' => 'map-marker-alt',
                        'roles' => ['customer'],
                        'area' => 'customer',
                        'active_slugs' => ['my-addresses'],
                    ],
                    [
                        'id' => 'profile',
                        'label' => 'Profile',
                        'route' => '/profile',
                        'icon' => 'user',
                        'roles' => ['customer'],
                        'area' => 'customer',
                        'active_slugs' => ['profile'],
                    ],
                ];
                break;
                
            case 'admin':
                // Admin navigation (sidebar)
                $items = [
                    [
                        'id' => 'dashboard',
                        'label' => 'Dashboard',
                        'route' => '/dashboard',
                        'icon' => 'chart-line',
                        'roles' => ['super_admin', 'manager', 'hub_management', 'menu_uploader'],
                        'area' => 'admin',
                        'active_slugs' => ['dashboard'],
                    ],
                    [
                        'id' => 'hubs',
                        'label' => 'Hubs',
                        'route' => '/hubs',
                        'icon' => 'store',
                        'roles' => ['super_admin', 'manager', 'hub_management'],
                        'area' => 'admin',
                        'active_slugs' => ['hubs', 'edit-hub', 'edit-hub-items', 'edit-item-categories'],
                    ],
                    [
                        'id' => 'menus',
                        'label' => 'Menus',
                        'route' => '/menus',
                        'icon' => 'utensils',
                        'roles' => ['super_admin', 'manager', 'menu_uploader'],
                        'area' => 'admin',
                        'active_slugs' => ['menus'],
                    ],
                    [
                        'id' => 'hub-categories',
                        'label' => 'Hub Categories',
                        'route' => '/hub-categories',
                        'icon' => 'list',
                        'roles' => ['super_admin', 'manager'],
                        'area' => 'admin',
                        'active_slugs' => ['hub-categories'],
                    ],
                    [
                        'id' => 'drivers',
                        'label' => 'Drivers',
                        'route' => '/drivers',
                        'icon' => 'car',
                        'roles' => ['super_admin', 'manager'],
                        'area' => 'admin',
                        'active_slugs' => ['drivers'],
                    ],
                    [
                        'id' => 'customers',
                        'label' => 'Customers',
                        'route' => '/customers',
                        'icon' => 'users',
                        'roles' => ['super_admin', 'manager'],
                        'area' => 'admin',
                        'active_slugs' => ['customers'],
                    ],
                    [
                        'id' => 'cities',
                        'label' => 'Cities',
                        'route' => '/knx-cities',
                        'icon' => 'city',
                        'roles' => ['super_admin', 'manager'],
                        'area' => 'admin',
                        'active_slugs' => ['knx-cities'],
                    ],
                    [
                        'id' => 'settings',
                        'label' => 'Settings',
                        'route' => '/settings',
                        'icon' => 'cog',
                        'roles' => ['super_admin', 'manager'],
                        'area' => 'admin',
                        'active_slugs' => ['settings'],
                    ],
                ];
                break;
                
            case 'driver':
                // Driver navigation (future-safe)
                $items = [
                    [
                        'id' => 'driver-dashboard',
                        'label' => 'Dashboard',
                        'route' => '/driver-dashboard',
                        'icon' => 'tachometer-alt',
                        'roles' => ['driver'],
                        'area' => 'driver',
                        'active_slugs' => ['driver-dashboard'],
                    ],
                    [
                        'id' => 'my-deliveries',
                        'label' => 'My Deliveries',
                        'route' => '/my-deliveries',
                        'icon' => 'box',
                        'roles' => ['driver'],
                        'area' => 'driver',
                        'active_slugs' => ['my-deliveries'],
                    ],
                ];
                break;
        }
        
        return $items;
    }
}

/**
 * Check if navigation item can be rendered for current session
 * 
 * @param array $item Navigation item
 * @param array $context Navigation context from knx_get_navigation_context()
 * @return bool
 */
if (!function_exists('knx_can_render_nav_item')) {
    function knx_can_render_nav_item($item, $context) {
        // Validate item structure
        if (!isset($item['roles']) || !is_array($item['roles'])) {
            return false;
        }
        
        // Wildcard: allow all
        if (in_array('*', $item['roles'], true)) {
            return true;
        }
        
        // No session: only public items
        if (!$context['is_logged']) {
            return false;
        }
        
        // Check role match
        return in_array($context['role'], $item['roles'], true);
    }
}

/**
 * Check if navigation item is currently active
 * 
 * @param array $item Navigation item
 * @param string $current_slug Current page slug
 * @return bool
 */
if (!function_exists('knx_is_nav_item_active')) {
    function knx_is_nav_item_active($item, $current_slug) {
        if (!isset($item['active_slugs']) || !is_array($item['active_slugs'])) {
            return false;
        }
        
        return in_array($current_slug, $item['active_slugs'], true);
    }
}

/**
 * Determine which navigation layout to use
 * 
 * @param array $context Navigation context
 * @return array {
 *   render_navbar: bool,
 *   render_sidebar: bool,
 *   navbar_area: string,
 *   sidebar_area: string|null
 * }
 */
if (!function_exists('knx_get_navigation_layout')) {
    function knx_get_navigation_layout($context) {
        $slug = $context['current_slug'];
        
        // Admin pages: sidebar only
        $admin_slugs = [
            'dashboard', 'knx-dashboard', 'hubs', 'edit-hub', 'edit-hub-items', 'edit-item-categories',
            'menus', 'hub-categories', 'drivers', 'customers', 'knx-cities', 'settings', 'live-orders', 'orders', 'coupons'
        ];
        
        if (in_array($slug, $admin_slugs, true) && $context['is_admin']) {
            return [
                'render_navbar' => false,
                'render_sidebar' => true,
                'navbar_area' => null,
                'sidebar_area' => 'admin',
            ];
        }
        
        // Driver pages: sidebar (future-safe)
        $driver_slugs = ['driver-dashboard', 'my-deliveries'];
        if (in_array($slug, $driver_slugs, true) && $context['is_driver']) {
            return [
                'render_navbar' => false,
                'render_sidebar' => true,
                'navbar_area' => null,
                'sidebar_area' => 'driver',
            ];
        }
        
        // Public pages: navbar only
        // Determine area based on login state
        $navbar_area = 'public';
        if ($context['is_customer']) {
            $navbar_area = 'customer';
        }
        
        return [
            'render_navbar' => true,
            'render_sidebar' => false,
            'navbar_area' => $navbar_area,
            'sidebar_area' => null,
        ];
    }
}
