<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — CUSTOMER SIDEBAR
 * ════════════════════════════════════════════════════════════════
 * 
 * PURPOSE:
 * - Customer navigation sidebar (overlay, no push)
 * - Desktop: fixed left overlay (320px)
 * - Mobile: full-screen drawer from left
 * - Responsive toggle buttons in navbar
 * 
 * SCOPE:
 * - Public/customer pages only
 * - Shows when logged in (customer role)
 */

$session = function_exists('knx_get_session') ? knx_get_session() : null;
$is_logged = $session ? true : false;

// Derive display name: prefer full name from DB, fallback to email then username
$username = 'Guest';
if ($session) {
    // Fetch name from knx_users (same source as navigation context)
    $knx_display_name = '';
    if (!empty($session->user_id)) {
        global $wpdb;
        $knx_row = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}knx_users WHERE id = %d LIMIT 1",
            $session->user_id
        ));
        if ($knx_row && !empty($knx_row->name)) {
            $knx_display_name = (string) $knx_row->name;
        }
    }
    $username = $knx_display_name
        ?: ($session->email    ?? '')
        ?: ($session->username ?? '')
        ?: 'Guest';
}
$role = $session ? $session->role : 'guest';

// Only render for logged customers (hub_management has its own bottom nav)
if (!$is_logged || in_array($role, ['super_admin', 'manager', 'hub_owner', 'hub_staff', 'hub_management', 'menu_uploader', 'driver'], true)) {
    return;
}

?>

<!-- Customer Sidebar Overlay (closes on click) -->
<div class="knx-sidebar-overlay" id="knxSidebarOverlay" aria-hidden="true"></div>

<!-- Customer Sidebar -->
<aside class="knx-sidebar" id="knxSidebar" role="navigation" aria-label="Customer navigation" aria-hidden="true">
    <div class="knx-sidebar__inner">
        
        <!-- Header -->
        <header class="knx-sidebar__header">
            <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-sidebar__brand">
                <span class="knx-sidebar__logo">🍃</span>
                <span class="knx-sidebar__brand-text">Kingdom Nexus</span>
            </a>
            <button type="button" class="knx-sidebar__close" id="knxSidebarClose" aria-label="Close menu">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </header>

        <!-- User Profile Area -->
        <div class="knx-sidebar__profile">
            <div class="knx-sidebar__avatar">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
            <div class="knx-sidebar__profile-info">
                <div class="knx-sidebar__profile-name"><?php echo esc_html($username); ?></div>
                <div class="knx-sidebar__profile-role">Customer</div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="knx-sidebar__nav" role="navigation">
            
            <!-- Main Section -->
            <ul class="knx-sidebar__list">
                <li>
                    <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-sidebar__link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                        <span>Home</span>
                    </a>
                </li>
                <!-- Explore Restaurants removed per request -->
            </ul>

            <!-- Orders Section -->
            <div class="knx-sidebar__section-title">Orders</div>
            <ul class="knx-sidebar__list">
                <li>
                    <a href="<?php echo esc_url(site_url('/my-orders')); ?>" class="knx-sidebar__link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                            <rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
                        </svg>
                        <span>My Orders</span>
                    </a>
                </li>
                <!-- Track Order removed per request -->
            </ul>

            <!-- Account Section -->
            <div class="knx-sidebar__section-title">Account</div>
            <ul class="knx-sidebar__list">
                <li>
                    <a href="<?php echo esc_url(site_url('/profile')); ?>" class="knx-sidebar__link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        <span>Profile</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo esc_url(site_url('/my-addresses')); ?>" class="knx-sidebar__link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                        <span>Addresses</span>
                    </a>
                </li>
                <li>
                    <button type="button" class="knx-sidebar__link knx-sidebar__cart-trigger" id="knxSidebarCartBtn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                        <span>Cart</span>
                        <span class="knx-sidebar__badge" id="knxSidebarCartBadge">0</span>
                    </button>
                </li>
            </ul>

            <!-- Help Section -->
            <div class="knx-sidebar__section-title">Support</div>
            <ul class="knx-sidebar__list">
                <li>
                    <a href="<?php echo esc_url(site_url('/contact')); ?>" class="knx-sidebar__link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        <span>Help & Support</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Footer -->
        <footer class="knx-sidebar__footer">
            <form method="post" class="knx-sidebar__logout-form">
                <?php wp_nonce_field('knx_logout_action', 'knx_logout_nonce'); ?>
                <button type="submit" name="knx_logout" class="knx-sidebar__logout-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    <span>Logout</span>
                </button>
            </form>
            <div class="knx-sidebar__version">Kingdom Nexus v<?php echo esc_html(defined('KNX_VERSION') ? KNX_VERSION : '1.0'); ?></div>
        </footer>

    </div>
</aside>
