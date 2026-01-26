/**
 * ==========================================================
 * CART PAGE — PUBLIC FRONTEND
 * Reads knx_cart from localStorage and renders full list
 * ==========================================================
 */
document.addEventListener("DOMContentLoaded", function () {

  const wrap = document.getElementById("knx-cart-page");
  if (!wrap) return;

  const listEl = document.getElementById("knxCartPageItems");
  const emptyEl = document.getElementById("knxCartPageEmpty");
  const summaryEl = document.getElementById("knxCartPageSummary");
  const subtotalEl = document.getElementById("knxCartPageSubtotal");

  renderCartPage();

  window.addEventListener("knx-cart-updated", renderCartPage);

  function renderCartPage() {
    const cart = readCart();
    listEl.innerHTML = "";

    if (!cart.length) {
      emptyEl.style.display = "block";
      summaryEl.style.display = "none";
      return;
    }

    emptyEl.style.display = "none";
    summaryEl.style.display = "block";

    let subtotal = 0;

    cart.forEach(item => {
      subtotal += item.line_total;

      const div = document.createElement("div");
      div.className = "knx-cart-item";
      div.innerHTML = `
        <div class="knx-cart-item__img">
          <img src="${item.image || ""}" alt="">
        </div>

        <div class="knx-cart-item__body">
          <div class="knx-cart-item__title">${item.name}</div>
          <div class="knx-cart-item__mods">
            ${renderModifiers(item.modifiers)}
          </div>
          ${item.notes ? `<div class="knx-cart-item__notes">${escapeHtml(item.notes)}</div>` : ""}
        </div>

        <div class="knx-cart-item__meta">
          <div class="knx-cart-item__price">$${item.line_total.toFixed(2)}</div>
          <div class="knx-cart-item__qty">x${item.quantity}</div>
        </div>
      `;

      listEl.appendChild(div);
    });

    subtotalEl.textContent = "$" + subtotal.toFixed(2);
  }

  function renderModifiers(mods) {
    if (!mods || !mods.length) return "";

    return mods
      .map(m => {
        const opts = m.options.map(o => o.name).join(", ");
        return `<span>${escapeHtml(m.name)}: ${escapeHtml(opts)}</span>`;
      })
      .join("<br>");
  }

  function readCart() {
    try {
      const raw = localStorage.getItem("knx_cart");
      return raw ? JSON.parse(raw) : [];
    } catch (e) {
      return [];
    }
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
});
