<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — NAVBAR (Top Navigation) v3.1 LEGACY PARITY
 * ════════════════════════════════════════════════════════════════
 * 
 * PURPOSE:
 * - Public/customer top navigation bar
 * - Cart drawer with badge (logged users)
 * - Location chip (explore page)
 * - Username display (clickable for admin) + logout
 * - Admin sidebar overlay (public pages only)
 * 
 * SCOPE:
 * - Public pages: /, /explore-hubs, /menu
 * - Customer pages: /cart, /checkout, /profile
 * 
 * ADMIN ACCESS:
 * - Admin users see sidebar overlay on public pages
 * - Use corporate-sidebar.php for private admin pages
 * 
 * [KNX-NAV-3.1] [KNX-TASK-NAV-003]
 */

add_action('wp_body_open', 'knx_render_navbar');

if (!function_exists('knx_render_navbar')) {
    function knx_render_navbar() {
        // Get navigation context
        $context = knx_get_navigation_context();
        $layout = knx_get_navigation_layout($context);
        
        // Only render navbar if layout says so
        if (!$layout['render_navbar']) {
            return;
        }
        
        // Load assets (echo, not enqueue)
        echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/navigation/navigation-style.css?v=' . KNX_VERSION) . '">';
        echo '<script src="' . esc_url(KNX_URL . 'inc/public/navigation/navigation-script.js?v=' . KNX_VERSION) . '" defer></script>';
        
        // Cart drawer assets (only if logged in)
        if ($context['is_logged']) {
            echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/cart/cart-drawer.css?v=' . KNX_VERSION) . '">';
            echo '<script src="' . esc_url(KNX_URL . 'inc/modules/cart/cart-drawer.js?v=' . KNX_VERSION) . '" defer></script>';
        }
        
        // Location detector (only on explore page)
        if ($context['current_slug'] === 'explore-hubs') {
            echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-modal.css?v=' . KNX_VERSION) . '">';
            echo '<script src="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-detector.js?v=' . KNX_VERSION) . '" defer></script>';
        }
        ?>

        <!-- NAVBAR -->
        <nav class="knx-nav" id="knxNav">
            <div class="knx-nav__inner">
                <!-- Logo -->
                <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-nav__brand">
                    <span class="knx-nav__logo">🍃</span>
                    <span class="knx-nav__brand-text">Kingdom Nexus</span>
                </a>
                
                <!-- Center: Location detector (explore page only) -->
                <?php if ($context['current_slug'] === 'explore-hubs'): ?>
                <div class="knx-nav__center">
                    <button class="knx-loc-chip" id="knx-detect-location" type="button" aria-label="Detect location">
                        <i class="fas fa-location-dot"></i>
                        <span class="knx-loc-chip__text" id="knxLocChipText">Set location</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <!-- Right Actions -->
                <div class="knx-nav__actions">
                    <!-- Cart (only if logged in) -->
                    <?php if ($context['is_logged']): ?>
                    <a href="#" class="knx-nav__cart" id="knxCartToggle" role="button"
                       aria-haspopup="dialog" aria-controls="knxCartDrawer" aria-expanded="false">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                        <span>Cart</span>
                        <span class="knx-nav__cart-badge" id="knxCartBadge">0</span>
                    </a>
                    <?php endif; ?>
                    
                    <!-- User Menu -->
                    <?php if ($context['is_logged']): ?>
                        <!-- [KNX-TASK-NAV-003] Fix 3: Username clickable for admin -->
                        <?php if ($context['is_admin']): ?>
                            <button class="knx-nav__username-btn" id="knxAdminMenuBtn" aria-label="User menu">
                                <span class="knx-nav__username-text"><?php echo esc_html($context['username']); ?></span>
                            </button>
                        <?php else: ?>
                            <div class="knx-nav__username">
                                <span class="knx-nav__username-text"><?php echo esc_html($context['username']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <!-- [KNX-TASK-NAV-003] Fix 4: Desktop/mobile logout separation -->
                        <form method="post" class="knx-nav__logout knx-nav__logout--desktop">
                            <?php wp_nonce_field('knx_logout_action', 'knx_logout_nonce'); ?>
                            <button type="submit" name="knx_logout" aria-label="Logout" class="knx-nav__logout-btn">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                                    <polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
                                </svg>
                                <span>Logout</span>
                            </button>
                        </form>
                    <?php else: ?>
                        <!-- Login Link -->
                        <a href="<?php echo esc_url(site_url('/login')); ?>" class="knx-nav__login">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                                <polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
                            </svg>
                            <span>Login</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <!-- Cart Drawer (Right Side) -->
        <?php if ($context['is_logged']): ?>
        <aside class="knx-cart-drawer" id="knxCartDrawer" role="dialog" aria-modal="true" aria-labelledby="knxCartTitle">
            <header class="knx-cart-drawer__header">
                <h3 id="knxCartTitle">Your Cart</h3>
                <button type="button" class="knx-cart-drawer__close" id="knxCartClose" aria-label="Close cart">×</button>
            </header>

            <div class="knx-cart-drawer__body" id="knxCartItems">
                <!-- Items rendered via JS -->
            </div>

            <footer class="knx-cart-drawer__footer">
                <div class="knx-cart-drawer__total">
                    <span>Subtotal:</span>
                    <strong id="knxCartTotal">$0.00</strong>
                </div>
                
                <!-- Review Cart Button (Always visible) -->
                <a class="knx-cart-drawer__review-btn" href="<?php echo esc_url(site_url('/cart')); ?>">
                    Review cart
                </a>
                
                <!-- Checkout Button (Dynamic, rendered by JS) -->
                <div id="knxCartCheckoutBtn">
                    <!-- Rendered by cart-drawer.js based on login + availability -->
                </div>
            </footer>
        </aside>
        <?php endif; ?>

        <!-- [KNX-TASK-NAV-003] Fix 1: Admin Sidebar Overlay on PUBLIC Pages -->
        <!-- Only renders on public/customer pages for admin users -->
        <?php if ($context['is_admin']): ?>
            <div class="knx-admin-overlay" id="knxAdminOverlay"></div>
            <aside class="knx-admin-sidebar" id="knxAdminSidebar">
                <header class="knx-admin-sidebar__header">
                    <a href="<?php echo esc_url(site_url('/dashboard')); ?>" class="knx-admin-sidebar__logo">🍃</a>
                    <button type="button" class="knx-admin-sidebar__close" id="knxAdminClose" aria-label="Close menu">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </header>

                <nav class="knx-admin-sidebar__nav">
                    <?php 
                    $admin_items = knx_get_nav_items('admin');
                    foreach ($admin_items as $item):
                        if (!knx_can_render_nav_item($item, $context)) continue;
                        $is_active = knx_is_nav_item_active($item, $context['current_slug']);
                        $active_class = $is_active ? ' knx-admin-sidebar__link--active' : '';
                    ?>
                        <a href="<?php echo esc_url(site_url($item['route'])); ?>" 
                           class="knx-admin-sidebar__link<?php echo $active_class; ?>">
                            <i class="fas fa-<?php echo esc_attr($item['icon']); ?>"></i>
                            <span><?php echo esc_html($item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>

                    <hr class="knx-admin-sidebar__divider">

                    <!-- [KNX-TASK-NAV-003] Fix 4: Mobile logout inside sidebar -->
                    <form method="post" class="knx-nav__logout knx-nav__logout--mobile">
                        <?php wp_nonce_field('knx_logout_action', 'knx_logout_nonce'); ?>
                        <button type="submit" name="knx_logout" class="knx-admin-sidebar__link knx-admin-sidebar__logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </button>
                    </form>
                </nav>
            </aside>
        <?php endif; ?>

        <?php
    }
}
