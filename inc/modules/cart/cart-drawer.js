/**
 * ==========================================================
 * Kingdom Nexus - Cart Drawer (Production, DB Sync) — vFinal
 * ----------------------------------------------------------
 * Key fixes:
 * - Ensures knx_cart_token cookie exists (client-generated token)
 * - Ensures hub_id is known (stored as knx_cart_hub_id)
 * - Fail-closed: do NOT call /cart/sync without hub_id + session_token
 * - Initial sync on load (only if cart + hub_id exist)
 * - When backend confirms empty => clear localStorage + cookie
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const drawer    = document.getElementById("knxCartDrawer");
  const btnToggle = document.getElementById("knxCartToggle");
  const btnClose  = document.getElementById("knxCartClose");
  const listEl    = document.getElementById("knxCartItems");
  const totalEl   = document.getElementById("knxCartTotal");

  // If the drawer UI is not on this page, we still may want syncing (for /cart page, badges, etc.)
  // But we will not crash if elements are missing.
  const hasDrawerUI = !!(drawer && btnToggle && btnClose && listEl && totalEl);

  const LS_CART   = "knx_cart";
  const LS_HUB_ID = "knx_cart_hub_id";
  const COOKIE_TOKEN = "knx_cart_token";

  /* ---------------------------------------------------------
   * Cookie helpers
   * --------------------------------------------------------- */
  function getCookie(name) {
    const parts = document.cookie.split(";").map(s => s.trim());
    for (const p of parts) {
      if (p.startsWith(name + "=")) return decodeURIComponent(p.substring(name.length + 1));
    }
    return "";
  }

  function setCookie(name, value, days) {
    const d = new Date();
    d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
    const expires = "expires=" + d.toUTCString();
    const secure = (location.protocol === "https:") ? "; Secure" : "";
    document.cookie = `${name}=${encodeURIComponent(value)}; ${expires}; path=/; SameSite=Lax${secure}`;
  }

  function deleteCookie(name) {
    const secure = (location.protocol === "https:") ? "; Secure" : "";
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax${secure}`;
  }

  function generateToken() {
    // Prefer crypto if available
    try {
      if (window.crypto && window.crypto.getRandomValues) {
        const arr = new Uint8Array(16);
        window.crypto.getRandomValues(arr);
        const hex = Array.from(arr).map(b => b.toString(16).padStart(2, "0")).join("");
        return "knx_" + hex;
      }
    } catch (_) {}
    return "knx_" + Math.random().toString(16).slice(2) + Date.now().toString(16);
  }

  function ensureSessionToken() {
    let token = getCookie(COOKIE_TOKEN);
    if (token) return token;

    token = generateToken();
    setCookie(COOKIE_TOKEN, token, 14);
    return token;
  }

  /* ---------------------------------------------------------
   * LocalStorage helpers
   * --------------------------------------------------------- */
  function readCart() {
    try {
      const raw = window.localStorage.getItem(LS_CART);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
      return [];
    }
  }

  function saveCart(cart) {
    try {
      window.localStorage.setItem(LS_CART, JSON.stringify(cart || []));
    } catch (_) {}

    try {
      window.dispatchEvent(new Event("knx-cart-updated"));
    } catch (_) {
      try {
        const evt = document.createEvent("Event");
        evt.initEvent("knx-cart-updated", true, true);
        window.dispatchEvent(evt);
      } catch (_) {}
    }

    // Sync to backend (only if hub_id exists)
    syncCartToServer(cart || []);
  }

  function clearClientCartState() {
    try { window.localStorage.removeItem(LS_CART); } catch (_) {}
    try { window.localStorage.removeItem(LS_HUB_ID); } catch (_) {}
    deleteCookie(COOKIE_TOKEN);

    try { window.dispatchEvent(new Event("knx-cart-updated")); } catch (_) {}
  }

  /* ---------------------------------------------------------
   * Hub ID resolution (fail-closed)
   * --------------------------------------------------------- */
  function readStoredHubId() {
    try {
      const v = window.localStorage.getItem(LS_HUB_ID);
      const n = parseInt(v, 10);
      return Number.isFinite(n) && n > 0 ? n : 0;
    } catch (_) {
      return 0;
    }
  }

  function storeHubId(hubId) {
    if (!hubId || hubId <= 0) return;
    try { window.localStorage.setItem(LS_HUB_ID, String(hubId)); } catch (_) {}
  }

  function inferHubIdFromDOM() {
    // Try common dataset patterns
    const b = document.body;
    if (b && b.dataset) {
      const a = parseInt(b.dataset.knxHubId || b.dataset.hubId || "", 10);
      if (a > 0) return a;
    }

    const candidates = [
      document.getElementById("knx-checkout"),
      document.getElementById("olc-menu"),
      document.getElementById("knx-menu"),
    ].filter(Boolean);

    for (const el of candidates) {
      const hub = parseInt(el.getAttribute("data-hub-id") || "", 10);
      if (hub > 0) return hub;
    }

    return 0;
  }

  function inferHubIdFromCart(cart) {
    if (!Array.isArray(cart) || cart.length === 0) return 0;
    const first = cart[0] || {};
    const hub = parseInt(first.hub_id || first.hubId || first.hub || "", 10);
    return hub > 0 ? hub : 0;
  }

  function resolveHubId(cart) {
    const stored = readStoredHubId();
    if (stored > 0) return stored;

    const fromCart = inferHubIdFromCart(cart);
    if (fromCart > 0) {
      storeHubId(fromCart);
      return fromCart;
    }

    const fromDOM = inferHubIdFromDOM();
    if (fromDOM > 0) {
      storeHubId(fromDOM);
      return fromDOM;
    }

    return 0; // fail-closed
  }

  /* ---------------------------------------------------------
   * Payload normalization
   * --------------------------------------------------------- */
  function normalizeItems(cart) {
    if (!Array.isArray(cart)) return [];

    return cart.map((x) => {
      const itemId = parseInt(x.item_id || x.itemId || x.id || "", 10);
      const qty = Math.max(1, parseInt(x.quantity || 1, 10) || 1);

      // Keep modifiers as-is (server validates strictly)
      const modifiers = Array.isArray(x.modifiers) ? x.modifiers : null;

      return {
        item_id: Number.isFinite(itemId) ? itemId : 0,
        quantity: qty,
        modifiers: modifiers || [],
      };
    }).filter(i => i.item_id > 0);
  }

  /* ---------------------------------------------------------
   * Sync with backend
   * --------------------------------------------------------- */
  function syncCartToServer(cart) {
    const token = ensureSessionToken();
    const hubId = resolveHubId(cart);

    // Fail-closed: do not call API without hubId
    if (!hubId || hubId <= 0) {
      return;
    }

    const items = normalizeItems(cart);

    // If cart is empty client-side, still sync an empty payload to force abandon state (optional)
    // This keeps DB consistent when user removes last item.
    const url = "/wp-json/knx/v1/cart/sync";

    try {
      window.fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          session_token: token,
          hub_id: hubId,
          items: items
        })
      })
      .then(r => r.json().catch(() => ({})))
      .then((data) => {
        if (data && data.success === true) {
          if (data.cart_empty === true || (data.cart && data.cart.item_count === 0)) {
            clearClientCartState();

            // If user is on /cart, reload so the page reflects empty state
            if (window.location.pathname === "/cart" || window.location.pathname.startsWith("/cart/")) {
              setTimeout(() => location.reload(), 200);
            }
          }
        }
      })
      .catch(() => {});
    } catch (_) {}
  }

  // Expose a safe global hook for other modules (cart page, navbar badge, etc.)
  window.knxSyncCartToServer = function () {
    syncCartToServer(readCart());
  };

  /* ---------------------------------------------------------
   * Drawer UI (optional per page)
   * --------------------------------------------------------- */
  function openDrawer() { if (drawer) drawer.classList.add("is-open"); }
  function closeDrawer() { if (drawer) drawer.classList.remove("is-open"); }

  function renderCart() {
    if (!hasDrawerUI) return;

    const cart = readCart();
    listEl.innerHTML = "";

    if (!cart.length) {
      totalEl.textContent = "$0.00";
      listEl.innerHTML = '<div class="knx-cart-empty">Your cart is empty</div>';
      return;
    }

    let subtotal = 0;

    cart.forEach((item) => {
      const qty  = item.quantity || 1;
      const unit = item.unit_price_with_modifiers || item.base_price || item.unit_price || 0;
      const line = (item.line_total != null) ? item.line_total : (unit * qty);
      subtotal += line;

      const modsText = item.modifiers && item.modifiers.length
        ? item.modifiers
            .map((m) => {
              const names = (m.options || []).map((o) => o.name).join(", ");
              return names ? `${m.name}: ${names}` : m.name;
            })
            .join(" • ")
        : "";

      const safeName = item.name || "";

      const html = `
        <div class="knx-cart-line" data-key="${String(item._key || item.key || item.id || item.item_id || "")}">
          <div class="knx-cart-line__top">
            <div class="knx-cart-line__title-row">
              <div style="display:flex;align-items:center;gap:8px;">
                ${item.image ? `<img src="${item.image}" alt="${safeName}" style="width:40px;height:40px;object-fit:cover;border-radius:8px;">` : ""}
                <h4 class="knx-cart-line__name">${safeName}</h4>
              </div>
              <div class="knx-cart-line__price">$${Number(line).toFixed(2)}</div>
            </div>
            ${modsText ? `<div class="knx-cart-line__mods">${modsText}</div>` : ""}
          </div>

          <div class="knx-cart-line__bottom">
            <button class="knx-cart-line__qty-btn" data-delta="-1" type="button">-</button>
            <span class="knx-cart-line__qty">${qty}</span>
            <button class="knx-cart-line__qty-btn" data-delta="1" type="button">+</button>

            <button class="knx-cart-line__remove" type="button">Remove</button>
          </div>
        </div>
      `;

      listEl.insertAdjacentHTML("beforeend", html);
    });

    totalEl.textContent = "$" + subtotal.toFixed(2);
  }

  function findItemIndex(cart, key) {
    // Try to match by various keys used across your project
    return cart.findIndex((i) => {
      const k = String(i._key || i.key || i.id || i.item_id || "");
      return k !== "" && k === String(key);
    });
  }

  if (hasDrawerUI) {
    btnToggle.addEventListener("click", (e) => {
      e.preventDefault();
      if (drawer.classList.contains("is-open")) closeDrawer();
      else openDrawer();
    });

    btnClose.addEventListener("click", (e) => {
      e.preventDefault();
      closeDrawer();
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && drawer.classList.contains("is-open")) closeDrawer();
    });

    listEl.addEventListener("click", (e) => {
      const target = e.target;
      const lineEl = target.closest(".knx-cart-line");
      if (!lineEl) return;

      const key = lineEl.getAttribute("data-key");
      if (!key) return;

      let cart = readCart();
      const idx = findItemIndex(cart, key);
      if (idx === -1) return;

      const item = cart[idx];

      if (target.classList.contains("knx-cart-line__remove")) {
        cart.splice(idx, 1);
        saveCart(cart);
        renderCart();
        return;
      }

      if (target.classList.contains("knx-cart-line__qty-btn")) {
        const delta = parseInt(target.getAttribute("data-delta"), 10);
        if (!delta) return;

        const currentQty = item.quantity || 1;
        const nextQty = currentQty + delta;
        if (nextQty < 1) return;

        item.quantity = nextQty;

        const unit = item.unit_price_with_modifiers || item.base_price || item.unit_price || 0;
        item.line_total = unit * nextQty;

        cart[idx] = item;
        saveCart(cart);
        renderCart();
      }
    });
  }

  // Initial render for drawer UI
  renderCart();
  window.addEventListener("knx-cart-updated", renderCart);

  // Initial sync (only if cart + hub_id are resolvable)
  try {
    syncCartToServer(readCart());
  } catch (_) {}
});
