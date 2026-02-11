/**
 * KINGDOM NEXUS — NAVIGATION SCRIPT v3.1 LEGACY PARITY
 * PURPOSE:
 * - Location chip storage reader
 * - Admin sidebar open/close with overlay
 * - Cart badge canonical safe reader (localStorage)
 * - Cross-tab storage sync
 * - Keyboard navigation (ESC key)
 * - Public API (window.knxNavbar)
 * 
 * DEPENDENCIES:
 * - navigation-engine.php (backend SSOT)
 * - cart-drawer.js (cart operations)
 * 
 * [KNX-NAV-3.1] [KNX-TASK-NAV-003]
 */

(function() {
    'use strict';

    // ========================================
    // LOCATION CHIP (Storage Reader)
    // ========================================
    function readStoredLocation() {
        // Priority: localStorage > sessionStorage
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
                    if (parsed && parsed.hub && parsed.hub.name) {
                        return parsed.hub.name;
                    }
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
        if (locationName) {
            chipText.textContent = locationName;
        } else {
            chipText.textContent = 'Set location';
        }
    }

    // ========================================
    // ADMIN SIDEBAR REMOVED — no-op placeholders
    // ========================================
    function openAdminSidebar() { /* no-op */ }
    function closeAdminSidebar() { /* no-op */ }

    // ========================================
    // CART BADGE (Canonical Safe Reader)
    // ========================================
    // [KNX-TASK-NAV-003] Fix 2: Use localStorage for cross-tab cart sync
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
        
        // Support both formats: { items: [...] } or direct array
                const items = Array.isArray(cart) ? cart : (cart.items || []);

                // Be permissive with quantity type (string or number)
                const normalized = items.filter(item => item && typeof item === 'object')
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

        // Hide badge when empty
        if (count === 0) {
            badge.style.display = 'none';
        } else {
            badge.style.display = '';
        }
    }

    // ========================================
    // CROSS-TAB SYNC + INIT
    // ========================================
    window.addEventListener('storage', function(e) {
        if (e.key === 'knx_cart') {
            updateCartBadge();
        }
        if (e.key === 'knx_location') {
            updateLocationChipText();
        }
    });

    // Custom event from cart-drawer.js
    // Some modules dispatch the event on `window`, others on `document`.
    // Listen on both to be robust across modules (menu-script uses window.dispatchEvent).
    document.addEventListener('knx-cart-updated', function() { updateCartBadge(); });
    window.addEventListener('knx-cart-updated', function() { updateCartBadge(); });

    // ========================================
    // INIT ON DOM READY
    // ========================================
    function init() {
        updateLocationChipText();
        updateCartBadge();
        // Retry passes to mitigate races where cart is written before init
        setTimeout(updateCartBadge, 300);
        setTimeout(updateCartBadge, 1200);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ========================================
    // [KNX-TASK-NAV-003] PUBLIC API (Legacy Parity)
    // ========================================
    window.knxNavbar = {
        updateCart: updateCartBadge,
        openAdminMenu: openAdminSidebar,
        closeAdminMenu: closeAdminSidebar,
        setLocation: function(label) {
            try {
                if (label) {
                    localStorage.setItem('knx_location', label);
                }
            } catch (e) {
                console.warn('[KNX-NAV] Location storage failed:', e);
            }
            updateLocationChipText();
        }
    };

})();
