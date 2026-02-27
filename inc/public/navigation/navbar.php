<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — NAVBAR (Top Navigation) v3.2 (Brand Logo Crop Frame)
 * ════════════════════════════════════════════════════════════════
 *
 * Purpose:
 * - Render brand logo as a fixed-size "window" (auto-crop display)
 * - Logo uses object-fit: cover inside a frame (no distortion, consistent)
 *
 * Notes:
 * - This change is DISPLAY-only. Upload pipeline stays intact.
 * - No wp_enqueue. No wp_footer dependency.
 */

add_action('wp_body_open', 'knx_render_navbar');

if (!function_exists('knx_render_navbar')) {
    function knx_render_navbar() {
        $context = knx_get_navigation_context();
        $layout  = knx_get_navigation_layout($context);

        if (!$layout['render_navbar']) return;

        echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/navigation/navigation-style.css?v=' . KNX_VERSION) . '">';
        echo '<script src="' . esc_url(KNX_URL . 'inc/public/navigation/navigation-script.js?v=' . KNX_VERSION) . '" defer></script>';

        if ($context['is_logged']) {
            echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/cart/cart-drawer.css?v=' . KNX_VERSION) . '">';
            echo '<script src="' . esc_url(KNX_URL . 'inc/modules/cart/cart-drawer.js?v=' . KNX_VERSION) . '" defer></script>';

            echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/navigation/customer-sidebar.css?v=' . KNX_VERSION) . '">';
            echo '<script src="' . esc_url(KNX_URL . 'inc/public/navigation/customer-sidebar.js?v=' . KNX_VERSION) . '" defer></script>';
        }

        if ($context['current_slug'] === 'explore-hubs') {
            echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-modal.css?v=' . KNX_VERSION) . '">';
            echo '<script src="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-detector.js?v=' . KNX_VERSION) . '" defer></script>';
        }
        ?>

        <!-- NAVBAR -->
        <nav class="knx-nav" id="knxNav">
            <div class="knx-nav__inner">

                <!-- Brand -->
                <?php $knx_logo_url = get_option('knx_site_logo', ''); ?>
                <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-nav__brand" id="knxNavBrand">
                    <?php if ($knx_logo_url): ?>
                        <!-- Fixed frame window (auto-crop display) -->
                        <span class="knx-nav__logo-frame" id="knxNavLogoFrame" aria-hidden="true">
                            <img
                                id="knxNavLogoImg"
                                src="<?php echo esc_url($knx_logo_url); ?>"
                                alt="<?php echo esc_attr(get_bloginfo('name')); ?>"
                                class="knx-nav__logo-img"
                                loading="eager"
                                decoding="async"
                                onerror="
                                    try{
                                        var frame=this.closest('.knx-nav__logo-frame');
                                        if(frame){ frame.style.display='none'; }
                                        var brand=this.closest('.knx-nav__brand');
                                        if(brand){
                                            var leaf=brand.querySelector('.knx-nav__logo');
                                            var text=brand.querySelector('.knx-nav__brand-text');
                                            if(leaf) leaf.style.display='';
                                            if(text) text.style.display='';
                                            brand.classList.remove('knx-nav__brand--has-logo');
                                            brand.classList.add('knx-nav__brand--no-logo');
                                        }
                                    }catch(e){}
                                "
                            >
                        </span>

                        <!-- Fallback (hidden unless error) -->
                        <span class="knx-nav__logo" style="display:none;">🍃</span>
                        <span class="knx-nav__brand-text" style="display:none;">Kingdom Nexus</span>
                    <?php else: ?>
                        <span class="knx-nav__logo">🍃</span>
                        <span class="knx-nav__brand-text">Kingdom Nexus</span>
                    <?php endif; ?>
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

                    <!-- Cart -->
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

                    <!-- User -->
                    <?php if ($context['is_logged']):
                        $nav_display = $context['name'] ?: $context['email'] ?: $context['username'] ?: 'Guest';
                        ?>
                        <button type="button" class="knx-nav__username" id="knxUserMenuToggle"
                                aria-controls="knxSidebar" aria-expanded="false" aria-label="Open user menu">
                            <span class="knx-nav__username-text"><?php echo esc_html($nav_display); ?></span>
                        </button>

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

        <!-- Cart Drawer -->
        <?php if ($context['is_logged']): ?>
            <aside class="knx-cart-drawer" id="knxCartDrawer" role="dialog" aria-modal="true" aria-labelledby="knxCartTitle">
                <header class="knx-cart-drawer__header">
                    <h3 id="knxCartTitle">Your Cart</h3>
                    <button type="button" class="knx-cart-drawer__close" id="knxCartClose" aria-label="Close cart">×</button>
                </header>

                <div class="knx-cart-drawer__body" id="knxCartItems"></div>

                <footer class="knx-cart-drawer__footer">
                    <div class="knx-cart-drawer__total">
                        <span>Subtotal:</span>
                        <strong id="knxCartTotal">$0.00</strong>
                    </div>

                    <a class="knx-cart-drawer__review-btn" href="<?php echo esc_url(site_url('/cart')); ?>">
                        Review cart
                    </a>

                    <div id="knxCartCheckoutBtn"></div>
                </footer>
            </aside>
        <?php endif; ?>

        <!-- Customer Sidebar -->
        <?php
        if ($context['is_logged']) {
            include KNX_PATH . 'inc/public/navigation/customer-sidebar.php';
        }
        ?>
        <?php
    }
}