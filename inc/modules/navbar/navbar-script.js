// ==========================================================
// Kingdom Nexus - Navbar Script (v7.1 Canon Aligned)
// ----------------------------------------------------------
// - Passive cart badge reader (NO cart mutations)
// - Fully aligned with canonical cart-drawer.js
// - Never assumes quantity defaults
// - Badge NEVER sticks when cart is empty
// ==========================================================

document.addEventListener("DOMContentLoaded", () => {

  /* =========================================================
   * Location Chip
   * ========================================================= */
  const locTextEl = document.getElementById("knxLocChipText");

  function readStoredLocation() {
    try {
      const explicit = localStorage.getItem("knx_location");
      if (explicit && explicit.trim()) return explicit.trim();

      const cached = JSON.parse(sessionStorage.getItem("knx_user_location") || "null");
      if (cached && cached.hub && cached.hub.name) return cached.hub.name;
    } catch (_) {}

    return "";
  }

  function setLocText(txt) {
    if (!locTextEl) return;
    locTextEl.textContent = txt && txt.length > 0 ? txt : "Set location";
  }

  setLocText(readStoredLocation());

  // Admin sidebar removed — no-op placeholders kept for backward compatibility
  function openAdminSidebar() { /* no-op */ }
  function closeAdminSidebar() { /* no-op */ }

  /* =========================================================
   * Explore Submenu Toggle
   * ========================================================= */
  const exploreToggle  = document.getElementById("knxExploreToggle");
  const exploreSubmenu = document.getElementById("knxExploreSubmenu");

  if (exploreToggle && exploreSubmenu) {
    exploreToggle.addEventListener("click", (e) => {
      e.preventDefault();
      exploreToggle.classList.toggle("active");
      exploreSubmenu.classList.toggle("active");
    });
  }

  /* =========================================================
   * Cart Badge (CANONICAL SAFE)
   * ========================================================= */
  const badgeEl = document.getElementById("knxCartBadge");

  function readCartSafe() {
    try {
      const raw = localStorage.getItem("knx_cart");
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
      typeof it === "object" &&
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
    if (!badgeEl) return;

    const cart = normalizeCart(readCartSafe());
    const totalItems = calculateItemCount(cart);

    if (totalItems > 0) {
      badgeEl.textContent = totalItems > 99 ? "99+" : String(totalItems);
      badgeEl.style.display = "flex";
      badgeEl.setAttribute("data-count", String(totalItems));
    } else {
      badgeEl.textContent = "0";
      badgeEl.style.display = "none";
      badgeEl.setAttribute("data-count", "0");
    }
  }

  // Initial render
  updateCartBadge();

  // Cross-tab updates
  window.addEventListener("storage", (e) => {
    if (e.key === "knx_cart") {
      updateCartBadge();
    }
  });

  // Canonical cart event
  window.addEventListener("knx-cart-updated", updateCartBadge);

  /* =========================================================
   * Public API (used sparingly)
   * ========================================================= */
  window.knxNavbar = {
    updateCart: updateCartBadge,
    openAdminMenu: openAdminSidebar,
    closeAdminMenu: closeAdminSidebar,
    setLocation: (label) => {
      try {
        if (label) localStorage.setItem("knx_location", label);
      } catch (_) {}
      setLocText(label);
    }
  };
});
