/**
 * ════════════════════════════════════════════════════════════════
 * KINGDOM NEXUS — CUSTOMER SIDEBAR SCRIPT
 * ════════════════════════════════════════════════════════════════
 * 
 * PURPOSE:
 * - Toggle sidebar open/close (desktop + mobile)
 * - Focus trap in mobile drawer mode
 * - Close on ESC key
 * - Close on overlay click
 * - Update cart badge from localStorage
 * - Integrate with cart drawer
 */

(function () {
  'use strict';

  const sidebar = document.getElementById('knxSidebar');
  const overlay = document.getElementById('knxSidebarOverlay');
  const userMenuBtn = document.getElementById('knxUserMenuToggle');
  const closeBtn = document.getElementById('knxSidebarClose');
  const cartBtn = document.getElementById('knxSidebarCartBtn');
  const sidebarBadge = document.getElementById('knxSidebarCartBadge');

  if (!sidebar || !overlay) return;

  let isOpen = false;
  let previousFocus = null;
  let focusableElements = [];

  /* =========================================================
   * HELPERS
   * ========================================================= */

  function getFocusableElements() {
    if (!sidebar) return [];
    const selectors = [
      'a[href]',
      'button:not([disabled])',
      'input:not([disabled])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])'
    ];
    return Array.from(sidebar.querySelectorAll(selectors.join(',')));
  }

  function trapFocus(e) {
    if (!isOpen) return;

    focusableElements = getFocusableElements();
    if (focusableElements.length === 0) return;

    const firstFocusable = focusableElements[0];
    const lastFocusable = focusableElements[focusableElements.length - 1];

    if (e.key === 'Tab') {
      if (e.shiftKey) {
        // Shift + Tab
        if (document.activeElement === firstFocusable) {
          e.preventDefault();
          lastFocusable.focus();
        }
      } else {
        // Tab
        if (document.activeElement === lastFocusable) {
          e.preventDefault();
          firstFocusable.focus();
        }
      }
    }
  }

  function handleEscape(e) {
    if (e.key === 'Escape' && isOpen) {
      closeSidebar();
    }
  }

  /* =========================================================
   * CART BADGE UPDATE (sync with localStorage)
   * ========================================================= */

  function readCartSafe() {
    try {
      const raw = localStorage.getItem('knx_cart');
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
      return [];
    }
  }

  function normalizeCart(cart) {
    if (!Array.isArray(cart)) return [];
    return cart.filter(it =>
      it &&
      typeof it === 'object' &&
      it.quantity != null &&
      Number.isFinite(parseInt(it.quantity, 10)) &&
      parseInt(it.quantity, 10) > 0
    );
  }

  function calculateItemCount(cart) {
    return cart.reduce((sum, item) => {
      const qty = parseInt(item.quantity, 10);
      return Number.isFinite(qty) && qty > 0 ? sum + qty : sum;
    }, 0);
  }

  function updateCartBadge() {
    if (!sidebarBadge) return;

    const cart = normalizeCart(readCartSafe());
    const totalItems = calculateItemCount(cart);

    if (totalItems > 0) {
      sidebarBadge.textContent = totalItems > 99 ? '99+' : String(totalItems);
      sidebarBadge.style.display = 'inline-flex';
      sidebarBadge.setAttribute('data-count', String(totalItems));
    } else {
      sidebarBadge.textContent = '0';
      sidebarBadge.style.display = 'none';
      sidebarBadge.setAttribute('data-count', '0');
    }
  }

  /* =========================================================
   * OPEN / CLOSE
   * ========================================================= */

  function openSidebar() {
    if (isOpen) return;

    isOpen = true;
    previousFocus = document.activeElement;

    sidebar.classList.add('is-open');
    overlay.classList.add('is-open');
    sidebar.setAttribute('aria-hidden', 'false');
    overlay.setAttribute('aria-hidden', 'false');

    if (userMenuBtn) {
      userMenuBtn.setAttribute('aria-expanded', 'true');
    }

    // Focus first focusable element
    focusableElements = getFocusableElements();
    if (focusableElements.length > 0) {
      setTimeout(() => focusableElements[0].focus(), 100);
    }

    // Trap focus
    document.addEventListener('keydown', trapFocus);
    document.addEventListener('keydown', handleEscape);

    // Prevent body scroll on mobile
    if (window.innerWidth <= 900) {
      document.body.style.overflow = 'hidden';
    }

    // Dispatch event
    window.dispatchEvent(new CustomEvent('knx-sidebar-open'));
  }

  function closeSidebar() {
    if (!isOpen) return;

    isOpen = false;

    sidebar.classList.remove('is-open');
    overlay.classList.remove('is-open');
    sidebar.setAttribute('aria-hidden', 'true');
    overlay.setAttribute('aria-hidden', 'true');

    if (userMenuBtn) {
      userMenuBtn.setAttribute('aria-expanded', 'false');
    }

    // Remove listeners
    document.removeEventListener('keydown', trapFocus);
    document.removeEventListener('keydown', handleEscape);

    // Restore body scroll
    document.body.style.overflow = '';

    // Restore focus
    if (previousFocus && typeof previousFocus.focus === 'function') {
      previousFocus.focus();
    }

    // Dispatch event
    window.dispatchEvent(new CustomEvent('knx-sidebar-close'));
  }

  function toggleSidebar() {
    if (isOpen) {
      closeSidebar();
    } else {
      openSidebar();
    }
  }

  /* =========================================================
   * EVENT LISTENERS
   * ========================================================= */

  // Username button in navbar toggles sidebar
  if (userMenuBtn) {
    userMenuBtn.addEventListener('click', (e) => {
      e.preventDefault();
      toggleSidebar();
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', (e) => {
      e.preventDefault();
      closeSidebar();
    });
  }

  if (overlay) {
    overlay.addEventListener('click', () => {
      closeSidebar();
    });
  }

  // Cart button in sidebar triggers cart drawer
  if (cartBtn) {
    cartBtn.addEventListener('click', (e) => {
      e.preventDefault();
      
      // Close sidebar first
      closeSidebar();
      
      // Open cart drawer (reuse existing cart toggle)
      setTimeout(() => {
        const cartToggle = document.getElementById('knxCartToggle');
        if (cartToggle && typeof cartToggle.click === 'function') {
          cartToggle.click();
        }
      }, 300);
    });
  }

  /* =========================================================
   * CART BADGE SYNC
   * ========================================================= */

  // Initial update
  updateCartBadge();

  // Listen for cart updates
  window.addEventListener('knx-cart-updated', updateCartBadge);
  window.addEventListener('storage', (e) => {
    if (e.key === 'knx_cart') {
      updateCartBadge();
    }
  });

  // Retry updates (race condition handling)
  setTimeout(updateCartBadge, 300);
  setTimeout(updateCartBadge, 1200);

  /* =========================================================
   * ACTIVE LINK DETECTION
   * ========================================================= */

  function setActiveLink() {
    const currentPath = window.location.pathname;
    const links = sidebar.querySelectorAll('.knx-sidebar__link[href]');
    
    links.forEach(link => {
      const href = link.getAttribute('href');
      if (href && currentPath === new URL(href, window.location.origin).pathname) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      }
    });
  }

  setActiveLink();

  /* =========================================================
   * PUBLIC API
   * ========================================================= */

  window.knxSidebar = {
    open: openSidebar,
    close: closeSidebar,
    toggle: toggleSidebar,
    isOpen: () => isOpen
  };

})();
