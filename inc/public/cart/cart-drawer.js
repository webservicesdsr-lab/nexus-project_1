/**
 * ==========================================================
 * Kingdom Nexus - Cart Drawer (Production)
 * Handles:
 * - Render cart items (with images)
 * - Quantity modify
 * - Remove item
 * - Subtotal sync
 * - Toggle drawer open/close
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {

  const drawer = document.getElementById("knxCartDrawer");
  const btnToggle = document.getElementById("knxCartToggle");
  const btnClose = document.getElementById("knxCartClose");
  const listEl = document.getElementById("knxCartItems");
  const totalEl = document.getElementById("knxCartTotal");

  if (!drawer || !btnToggle || !btnClose || !listEl || !totalEl) {
    console.warn("KNX Drawer: Missing DOM elements");
    return;
  }

  /* ---------------------------------------------------------
   * DRAWER OPEN/CLOSE
   * --------------------------------------------------------- */
  function openDrawer() {
    drawer.classList.add("active");
    document.body.style.overflow = "hidden";
  }

  function closeDrawer() {
    drawer.classList.remove("active");
    document.body.style.overflow = "";
  }

  btnToggle.addEventListener("click", (e) => {
    e.preventDefault();
    drawer.classList.contains("active") ? closeDrawer() : openDrawer();
  });

  btnClose.addEventListener("click", closeDrawer);

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && drawer.classList.contains("active")) {
      closeDrawer();
    }
  });

  /* ---------------------------------------------------------
   * CART STORAGE HELPERS
   * --------------------------------------------------------- */
  function readCart() {
    try {
      const raw = localStorage.getItem("knx_cart");
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function saveCart(cart) {
    localStorage.setItem("knx_cart", JSON.stringify(cart));
    window.dispatchEvent(new Event("knx-cart-updated"));
  }

  /* ---------------------------------------------------------
   * RENDER FUNCTION
   * --------------------------------------------------------- */
  function renderCart() {
    const cart = readCart();
    listEl.innerHTML = "";

    if (!cart.length) {
      totalEl.textContent = "$0.00";
      return;
    }

    let subtotal = 0;

    cart.forEach((item) => {
      subtotal += item.line_total;

      const modsText = item.modifiers?.length
        ? item.modifiers.map(m => `${m.name}: ${m.options.map(o => o.name).join(", ")}`).join(" • ")
        : "";

      const html = `
        <div class="knx-cart-item" data-id="${item.id}">

          <div class="knx-cart-item__img">
            <img src="${item.image || ''}" alt="${item.name}">
          </div>

          <div class="knx-cart-item__content">
            <div class="knx-cart-item__title">${item.name}</div>
            ${modsText ? `<div class="knx-cart-item__mods">${modsText}</div>` : ""}
            
            <div class="knx-cart-item__qty-row">
              <button class="knx-cart-item__qty-btn" data-delta="-1">-</button>
              <span class="knx-cart-item__qty">${item.quantity}</span>
              <button class="knx-cart-item__qty-btn" data-delta="+1">+</button>
            </div>
          </div>

          <div class="knx-cart-item__price">$${item.line_total.toFixed(2)}</div>

          <button class="knx-cart-item__remove">Remove</button>
        </div>
      `;

      listEl.insertAdjacentHTML("beforeend", html);
    });

    totalEl.textContent = "$" + subtotal.toFixed(2);
  }

  /* ---------------------------------------------------------
   * EVENT: MODIFY QTY OR REMOVE ITEM
   * --------------------------------------------------------- */
  listEl.addEventListener("click", (e) => {
    const target = e.target;
    const itemEl = target.closest(".knx-cart-item");
    if (!itemEl) return;

    const id = itemEl.getAttribute("data-id");
    let cart = readCart();
    let item = cart.find(i => i.id === id);
    if (!item) return;

    if (target.classList.contains("knx-cart-item__remove")) {
      cart = cart.filter(i => i.id !== id);
      saveCart(cart);
      renderCart();
      return;
    }

    if (target.classList.contains("knx-cart-item__qty-btn")) {
      const delta = parseInt(target.getAttribute("data-delta"), 10);
      const newQty = item.quantity + delta;
      if (newQty < 1) return;

      item.quantity = newQty;
      item.line_total = item.unit_price_with_modifiers * newQty;

      saveCart(cart);
      renderCart();
      return;
    }
  });

  /* ---------------------------------------------------------
   * INITIAL RENDER + SYNC ON CHANGE
   * --------------------------------------------------------- */
  renderCart();
  window.addEventListener("knx-cart-updated", renderCart);
});
