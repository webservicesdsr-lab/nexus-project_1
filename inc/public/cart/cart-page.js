/**
 * ==========================================================
 * CART PAGE — INTERACTIVE PUBLIC FRONTEND  (Phase 6)
 * ----------------------------------------------------------
 * Reads knx_cart from localStorage, renders items with
 * quantity +/- and remove controls. Writes back to
 * localStorage on every change, dispatches knx-cart-updated,
 * and calls syncCartToServer so the drawer + badge stay in
 * sync. Works for guests and logged-in users alike.
 * ==========================================================
 */
document.addEventListener("DOMContentLoaded", function () {

  /* ---------------------------------------------------------
   * DOM references
   * --------------------------------------------------------- */
  const wrap       = document.getElementById("knx-cart-page");
  if (!wrap) return;

  const listEl     = document.getElementById("knxCartPageItems");
  const emptyEl    = document.getElementById("knxCartPageEmpty");
  const summaryEl  = document.getElementById("knxCartPageSummary");
  const subtotalEl = document.getElementById("knxCartPageSubtotal");

  if (!listEl || !emptyEl || !summaryEl || !subtotalEl) return;

  const LS_CART   = "knx_cart";
  const LS_HUB_ID = "knx_cart_hub_id";

  /* ---------------------------------------------------------
   * localStorage helpers (mirrors cart-drawer.js)
   * --------------------------------------------------------- */
  function readCart() {
    try {
      var raw = localStorage.getItem(LS_CART);
      if (!raw) return [];
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
      return [];
    }
  }

  /**
   * Persist cart to localStorage, dispatch event, sync.
   * Uses cart-drawer's saveCart indirectly: the event it
   * dispatches (knx-cart-updated) causes the drawer to
   * re-render. Direct DB sync is done via the global hook
   * exposed by cart-drawer.js (window.knxSyncCartToServer).
   */
  function persistCart(cart) {
    try {
      localStorage.setItem(LS_CART, JSON.stringify(cart || []));
    } catch (_) {}

    // Dispatch so other listeners (drawer, badge) react
    try {
      window.dispatchEvent(new Event("knx-cart-updated"));
    } catch (_) {
      try {
        var evt = document.createEvent("Event");
        evt.initEvent("knx-cart-updated", true, true);
        window.dispatchEvent(evt);
      } catch (_) {}
    }

    // Trigger DB sync via cart-drawer's global hook
    if (typeof window.knxSyncCartToServer === "function") {
      window.knxSyncCartToServer();
    }
  }

  function findItemIndex(cart, key) {
    return cart.findIndex(function (i) {
      var k = String(i._key || i.key || i.id || i.item_id || "");
      return k !== "" && k === String(key);
    });
  }

  /* ---------------------------------------------------------
   * Utility
   * --------------------------------------------------------- */
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function renderModifiers(mods) {
    if (!mods || !mods.length) return "";

    return mods
      .map(function (m) {
        var opts = (m.options || []).map(function (o) {
          if (o.option_action === "remove") {
            return '<span class="knx-mod-remove">No ' + escapeHtml(o.name) + "</span>";
          }
          return escapeHtml(o.name);
        }).join(", ");
        return "<span>" + escapeHtml(m.name) + ": " + opts + "</span>";
      })
      .join(" &bull; ");
  }

  /* ---------------------------------------------------------
   * Render
   * --------------------------------------------------------- */
  function renderCartPage() {
    var cart = readCart();
    listEl.innerHTML = "";

    if (!cart.length) {
      listEl.style.display     = "none";
      emptyEl.style.display    = "block";
      summaryEl.style.display  = "none";
      return;
    }

    listEl.style.display    = "";
    emptyEl.style.display   = "none";
    summaryEl.style.display = "block";

    var subtotal = 0;

    cart.forEach(function (item) {
      var qty  = item.quantity || 1;
      var unit = item.unit_price_with_modifiers || item.base_price || item.unit_price || 0;
      var line = (item.line_total != null) ? item.line_total : (unit * qty);
      subtotal += line;

      var key = String(item._key || item.key || item.id || item.item_id || "");

      var html = '<div class="knx-cart-item" data-key="' + escapeHtml(key) + '">';

      // Image
      if (item.image) {
        html += '<div class="knx-cart-item__img">'
              + '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name || "") + '">'
              + '</div>';
      }

      // Body: name + modifiers + notes
      html += '<div class="knx-cart-item__body">';
      html += '<div class="knx-cart-item__title">' + escapeHtml(item.name || "") + '</div>';

      var modsMarkup = renderModifiers(item.modifiers);
      if (modsMarkup) {
        html += '<div class="knx-cart-item__mods">' + modsMarkup + '</div>';
      }
      if (item.notes) {
        html += '<div class="knx-cart-item__notes">' + escapeHtml(item.notes) + '</div>';
      }
      html += '</div>';

      // Meta: price + controls
      html += '<div class="knx-cart-item__meta">';
      html += '<div class="knx-cart-item__price">$' + Number(line).toFixed(2) + '</div>';

      // Qty controls
      html += '<div class="knx-cart-item__controls">';
      html += '<button type="button" class="knx-cart-item__qty-btn" data-delta="-1" aria-label="Decrease quantity">−</button>';
      html += '<span class="knx-cart-item__qty-value">' + qty + '</span>';
      html += '<button type="button" class="knx-cart-item__qty-btn" data-delta="1" aria-label="Increase quantity">+</button>';
      html += '</div>';

      // Remove
      html += '<button type="button" class="knx-cart-item__remove" aria-label="Remove item">Remove</button>';

      html += '</div>'; // meta
      html += '</div>'; // knx-cart-item

      listEl.insertAdjacentHTML("beforeend", html);
    });

    subtotalEl.textContent = "$" + subtotal.toFixed(2);
  }

  /* ---------------------------------------------------------
   * Interaction: delegated click on items list
   * --------------------------------------------------------- */
  listEl.addEventListener("click", function (e) {
    var target = e.target;
    var itemEl = target.closest(".knx-cart-item");
    if (!itemEl) return;

    var key = itemEl.getAttribute("data-key");
    if (!key) return;

    var cart = readCart();
    var idx  = findItemIndex(cart, key);
    if (idx === -1) return;

    // Handle remove
    if (target.classList.contains("knx-cart-item__remove")) {
      cart.splice(idx, 1);
      persistCart(cart);
      renderCartPage();
      return;
    }

    // Handle qty change
    if (target.classList.contains("knx-cart-item__qty-btn")) {
      var delta = parseInt(target.getAttribute("data-delta"), 10);
      if (!delta) return;

      var item = cart[idx];
      var currentQty = item.quantity || 1;
      var nextQty    = currentQty + delta;

      if (nextQty < 1) {
        // Treat going below 1 as remove
        cart.splice(idx, 1);
      } else {
        item.quantity = nextQty;

        var unit = item.unit_price_with_modifiers || item.base_price || item.unit_price || 0;
        item.line_total = unit * nextQty;
        cart[idx] = item;
      }

      persistCart(cart);
      renderCartPage();
    }
  });

  /* ---------------------------------------------------------
   * Initial render + cross-tab / drawer sync
   * --------------------------------------------------------- */
  renderCartPage();

  // Re-render when the drawer (or another tab) modifies the cart
  window.addEventListener("knx-cart-updated", function () {
    // Small debounce to avoid render loop (persistCart also fires this event)
    clearTimeout(renderCartPage._t);
    renderCartPage._t = setTimeout(renderCartPage, 50);
  });

});
