<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Navbar Renderer (v7 Simple - Local Bites Style)
 * Logo left, Cart + User right
 */

add_action('wp_body_open', function () {
    global $post;
    $slug = is_object($post) ? $post->post_name : '';

    $private_slugs = [
        'dashboard','basic-dashboard','advanced-dashboard',
        'hubs','edit-hub','edit-hub-items','edit-item-categories',
        'drivers','customers','cities','settings','menus','hub-categories', 'knx-cities', 'customer-panel'
    ];
    if (in_array($slug, $private_slugs, true)) return;

    $session  = knx_get_session();
    $is_logged = $session ? true : false;
    $username  = $session ? $session->username : '';
    $role      = $session ? $session->role : 'guest';
    $is_admin  = in_array($role, ['super_admin','manager','hub_management','menu_uploader'], true);

    // === Assets (con echo) ===
    echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/navbar/navbar-style.css?v=' . KNX_VERSION) . '">';
    echo '<script src="' . esc_url(KNX_URL . 'inc/modules/navbar/navbar-script.js?v=' . KNX_VERSION) . '" defer></script>';

    // Drawer del carrito (aislado, sin overlay)
    echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/cart/cart-drawer.css?v=' . KNX_VERSION) . '">';
    echo '<script src="' . esc_url(KNX_URL . 'inc/modules/cart/cart-drawer.js?v=' . KNX_VERSION) . '" defer></script>';

    // Detector de ubicacion solo en explore
    if ($slug === 'explore-hubs') {
      echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-modal.css?v=' . KNX_VERSION) . '">';
      echo '<script src="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-detector.js?v=' . KNX_VERSION) . '" defer></script>';
    }
    ?>

    <!-- Agrega id para scope anti-bleed -->
    <nav class="knx-nav" id="knx-scope">
      <div class="knx-nav__inner">
        <!-- Logo -->
        <a href="<?php echo esc_url(site_url('/')); ?>" class="knx-nav__brand">
          <span class="knx-nav__logo">🍃</span>
          <span class="knx-nav__brand-text">Kingdom Nexus</span>
        </a>

        <?php if ($slug === 'explore-hubs'): ?>
        <div class="knx-nav__center">
          <button class="knx-loc-chip" id="knx-detect-location" type="button" aria-label="Detect location">
            <i class="fas fa-location-dot"></i>
            <span class="knx-loc-chip__text" id="knxLocChipText">Set location</span>
          </button>
        </div>
        <?php endif; ?>

        <!-- Right Side -->
        <div class="knx-nav__actions">
          <!-- Cart Button: ahora es toggle (no navega) -->
          <a href="#" class="knx-nav__cart" id="knxCartToggle" role="button"
             aria-haspopup="dialog" aria-controls="knxCartDrawer" aria-expanded="false">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
            <span>Cart</span>
            <span class="knx-nav__cart-badge" id="knxCartBadge">0</span>
          </a>

          <?php if ($is_logged): ?>
            <div class="knx-nav__username-btn">
              <span class="knx-nav__username-text"><?php echo esc_html($username); ?></span>
            </div>

            <form method="post" class="knx-nav__logout knx-nav__logout--desktop">
              <?php wp_nonce_field('knx_logout_action','knx_logout_nonce'); ?>
              <button type="submit" name="knx_logout" aria-label="Logout">
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

    <!-- Admin overlay/sidebar removed by maintainers to avoid duplicate sidebars -->

    <!-- ===== Cart Drawer (derecha, sin overlay) ===== -->
    <aside class="knx-cart-drawer" id="knxCartDrawer" role="dialog" aria-modal="true" aria-labelledby="knxCartTitle">
      <header class="knx-cart-drawer__header">
        <h3 id="knxCartTitle">Your Cart</h3>
        <button type="button" class="knx-cart-drawer__close" id="knxCartClose" aria-label="Close cart">×</button>
      </header>

      <div class="knx-cart-drawer__body" id="knxCartItems">
        <!-- Items renderizados via JS (solo summary del menú, sin fees) -->
      </div>

      <footer class="knx-cart-drawer__footer">
        <div class="knx-cart-drawer__total">
          <span>Subtotal:</span>
          <strong id="knxCartTotal">$0.00</strong>
        </div>
        
        <!-- TASK 3: Botón 1 - SIEMPRE visible -->
        <a class="knx-cart-drawer__review-btn" href="<?php echo esc_url(site_url('/cart')); ?>" style="display:block;margin-bottom:8px;text-align:center;padding:12px;background:#f3f4f6;color:#374151;border-radius:8px;text-decoration:none;font-weight:500;">
          Review cart
        </a>
        
        <!-- TASK 3: Botón 2 - DINÁMICO (renderizado por JS) -->
        <div id="knxCartCheckoutBtn">
          <!-- Renderizado por cart-drawer.js basado en estado login + availability -->
        </div>
      </footer>
    </aside>
    <?php
});
