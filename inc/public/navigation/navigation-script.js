// File: inc/public/navigation/navigation-script.js

/**
 * KINGDOM NEXUS — NAVIGATION SCRIPT v3.2
 * - Keeps legacy parity behavior
 * - Adds a tiny helper for brand logo state (has-logo/no-logo)
 */

(function() {
  'use strict';

  // ----------------------------------------
  // BRAND LOGO STATE (optional)
  // ----------------------------------------
  function initBrandLogoState() {
    const brand = document.getElementById('knxNavBrand');
    const img   = document.getElementById('knxNavLogoImg');

    if (!brand) return;

    if (!img) {
      brand.classList.add('knx-nav__brand--no-logo');
      return;
    }

    function hasLogo() {
      brand.classList.remove('knx-nav__brand--no-logo');
      brand.classList.add('knx-nav__brand--has-logo');
    }

    function noLogo() {
      brand.classList.remove('knx-nav__brand--has-logo');
      brand.classList.add('knx-nav__brand--no-logo');
    }

    if (img.complete && img.naturalWidth > 0) hasLogo();
    img.addEventListener('load', hasLogo);
    img.addEventListener('error', noLogo);
  }

  // ========================================
  // LOCATION CHIP (Storage Reader)
  // ========================================
  function readStoredLocation() {
    try {
      const localLoc = localStorage.getItem('knx_location');
      if (localLoc) {
        try {
          const parsed = JSON.parse(localLoc);
          if (parsed && parsed.name) return parsed.name;
        } catch {
          return localLoc;
        }
      }

      const sessionLoc = sessionStorage.getItem('knx_user_location');
      if (sessionLoc) {
        try {
          const parsed = JSON.parse(sessionLoc);
          if (parsed && parsed.hub && parsed.hub.name) return parsed.hub.name;
        } catch {}
      }
    } catch (e) {
      console.warn('[KNX-NAV] Location storage read failed:', e);
    }
    return null;
  }

  function updateLocationChipText() {
    const chipText = document.getElementById('knxLocChipText');
    if (!chipText) return;
    const locationName = readStoredLocation();
    chipText.textContent = locationName ? locationName : 'Set location';
  }

  // ========================================
  // ADMIN SIDEBAR REMOVED — no-op placeholders
  // ========================================
  function openAdminSidebar() {}
  function closeAdminSidebar() {}

  // ========================================
  // CART BADGE (Canonical Safe Reader)
  // ========================================
  function readCartSafe() {
    try {
      const raw = localStorage.getItem('knx_cart');
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      console.warn('[KNX-NAV] Cart read failed:', e);
      return null;
    }
  }

  function normalizeCart(cart) {
    if (!cart || typeof cart !== 'object') return { items: [] };
    const items = Array.isArray(cart) ? cart : (cart.items || []);
    const normalized = items
      .filter(item => item && typeof item === 'object')
      .map(item => {
        const qty = item.quantity != null ? parseInt(item.quantity, 10) : 0;
        return Object.assign({}, item, { quantity: Number.isFinite(qty) && qty > 0 ? qty : 0 });
      })
      .filter(item => item.quantity > 0);
    return { items: normalized };
  }

  function calculateItemCount(cart) {
    const normalized = normalizeCart(cart);
    return normalized.items.reduce((sum, item) => sum + item.quantity, 0);
  }

  function updateCartBadge() {
    const badge = document.getElementById('knxCartBadge');
    if (!badge) return;
    const cart = readCartSafe();
    const count = calculateItemCount(cart);
    badge.textContent = count;
    badge.setAttribute('data-count', count);
    badge.style.display = (count === 0) ? 'none' : '';
  }

  // ========================================
  // CROSS-TAB SYNC + INIT
  // ========================================
  window.addEventListener('storage', function(e) {
    if (e.key === 'knx_cart') updateCartBadge();
    if (e.key === 'knx_location') updateLocationChipText();
  });

  document.addEventListener('knx-cart-updated', function() { updateCartBadge(); });
  window.addEventListener('knx-cart-updated', function() { updateCartBadge(); });

  function init() {
    initBrandLogoState();
    updateLocationChipText();
    updateCartBadge();
    setTimeout(updateCartBadge, 300);
    setTimeout(updateCartBadge, 1200);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.knxNavbar = {
    updateCart: updateCartBadge,
    openAdminMenu: openAdminSidebar,
    closeAdminMenu: closeAdminSidebar,
    setLocation: function(label) {
      try {
        if (label) localStorage.setItem('knx_location', label);
      } catch (e) {
        console.warn('[KNX-NAV] Location storage failed:', e);
      }
      updateLocationChipText();
    }
  };

})();