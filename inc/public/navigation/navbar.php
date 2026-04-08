<?php
// File: inc/public/navigation/navbar.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KINGDOM NEXUS — NAVBAR (Top Navigation) v3.3 (Brand Logo View)
 * ----------------------------------------------------------
 * - Logo is displayed in a fixed frame
 * - Image is NOT cropped at upload
 * - We apply saved "view" (pan/zoom) via CSS variables
 * ==========================================================
 */

add_action('wp_body_open', 'knx_render_navbar');

if (!function_exists('knx_render_navbar')) {
    function knx_render_navbar() {
        $context = knx_get_navigation_context();
        $layout  = knx_get_navigation_layout($context);

        if (!$layout['render_navbar']) return;

        echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/navigation/navigation-style.css?v=' . KNX_VERSION) . '">';
        echo '<script src="' . esc_url(KNX_URL . 'inc/public/navigation/navigation-script.js?v=' . KNX_VERSION) . '" defer></script>';

        // Cart drawer assets load for ALL visitors (guests + logged) — Phase 2: guest cart visibility
        echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/cart/cart-drawer.css?v=' . KNX_VERSION) . '">';
        echo '<script src="' . esc_url(KNX_URL . 'inc/modules/cart/cart-drawer.js?v=' . KNX_VERSION) . '" defer></script>';

        if ($context['is_logged']) {
            // Customer sidebar is account navigation — stays logged-only
            echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/navigation/customer-sidebar.css?v=' . KNX_VERSION) . '">';
            echo '<script src="' . esc_url(KNX_URL . 'inc/public/navigation/customer-sidebar.js?v=' . KNX_VERSION) . '" defer></script>';
        }

        if ($context['current_slug'] === 'explore-hubs') {
            echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-modal.css?v=' . KNX_VERSION) . '">';
            echo '<script src="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-detector.js?v=' . KNX_VERSION) . '" defer></script>';
        }

        $knx_logo_url = get_option('knx_site_logo', '');

        $view_raw = get_option('knx_site_logo_view', '');
        $view = ['scale' => 1, 'x' => 0, 'y' => 0];
        if (is_string($view_raw) && $view_raw) {
            $decoded = json_decode($view_raw, true);
            if (is_array($decoded)) {
                $view['scale'] = isset($decoded['scale']) ? floatval($decoded['scale']) : 1;
                $view['x']     = isset($decoded['x']) ? floatval($decoded['x']) : 0;
                $view['y']     = isset($decoded['y']) ? floatval($decoded['y']) : 0;
            }
        }
        $view['scale'] = max(0.5, min(3.0, $view['scale']));
        $view['x']     = max(-300, min(300, $view['x']));
        $view['y']     = max(-120, min(120, $view['y']));

        $logo_style = sprintf(
            '--knx-logo-scale:%s;--knx-logo-x:%spx;--knx-logo-y:%spx;',
            esc_attr($view['scale']),
            esc_attr($view['x']),
            esc_attr($view['y'])
        );
        ?>

        <nav class="knx-nav" id="knxNav">
            <div class="knx-nav__inner">

                <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-nav__brand" id="knxNavBrand">
                    <?php if ($knx_logo_url): ?>
                        <span class="knx-nav__logo-frame" id="knxNavLogoFrame" style="<?php echo esc_attr($logo_style); ?>" aria-hidden="true">
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

                        <span class="knx-nav__logo" style="display:none;">🍃</span>
                        <span class="knx-nav__brand-text" style="display:none;">Kingdom Nexus</span>
                    <?php else: ?>
                        <span class="knx-nav__logo">🍃</span>
                        <span class="knx-nav__brand-text">Kingdom Nexus</span>
                    <?php endif; ?>
                </a>

                <?php if ($context['current_slug'] === 'explore-hubs'): ?>
                    <div class="knx-nav__center">
                        <button class="knx-loc-chip" id="knx-detect-location" type="button" aria-label="Detect location">
                            <i class="fas fa-location-dot"></i>
                            <span class="knx-loc-chip__text" id="knxLocChipText">Set location</span>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="knx-nav__actions">
                    <!-- Cart toggle visible to ALL visitors (Phase 2: guest cart visibility) -->
                    <a href="#" class="knx-nav__cart" id="knxCartToggle" role="button"
                       aria-haspopup="dialog" aria-controls="knxCartDrawer" aria-expanded="false">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                        <span>Cart</span>
                        <span class="knx-nav__cart-badge" id="knxCartBadge">0</span>
                    </a>

                    <?php if ($context['is_logged']):
                        $nav_display = $context['name'] ?: $context['email'] ?: $context['username'] ?: 'Guest';
                        ?>
                        <button type="button" class="knx-nav__username" id="knxUserMenuToggle"
                                aria-controls="knxSidebar" aria-expanded="false" aria-label="Open user menu">
                            <i class="fas fa-bars knx-nav__username-icon"></i>
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
                    <?php else:
                        // Phase 3: Include current URI as redirect_to on login link
                        $current_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '/';
                        $nav_login_url = site_url('/login');
                        if ($current_uri !== '/' && $current_uri !== '/login' && $current_uri !== '/register') {
                            $nav_login_url = add_query_arg('redirect_to', rawurlencode($current_uri), $nav_login_url);
                        }
                    ?>
                        <a href="<?php echo esc_url($nav_login_url); ?>" class="knx-nav__login">
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

        <?php
        // Cart drawer renders for ALL visitors — JS uses data-knx-logged for contextual CTA
        $is_logged_attr = $context['is_logged'] ? '1' : '0';
        ?>
        <aside class="knx-cart-drawer" id="knxCartDrawer" role="dialog" aria-modal="true" aria-labelledby="knxCartTitle"
               data-knx-logged="<?php echo esc_attr($is_logged_attr); ?>">
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

        <?php
        // Customer sidebar stays behind auth gate — it's account navigation
        if ($context['is_logged']) {
            include KNX_PATH . 'inc/public/navigation/customer-sidebar.php';
        }
    }
}