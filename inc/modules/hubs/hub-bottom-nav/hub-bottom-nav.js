(function () {
  "use strict";

  function norm(u) {
    try {
      const x = new URL(u, window.location.origin);
      return x.pathname.replace(/\/+$/, "") || "/";
    } catch (_) {
      return String(u || "").replace(/\/+$/, "") || "/";
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const nav = document.querySelector("[data-knx-hub-bottom-nav]");
    if (!nav) return;

    const dashboard = norm(nav.dataset.dashboardUrl || "/hub-dashboard");
    const items     = norm(nav.dataset.itemsUrl     || "/hub-items");
    const settings  = norm(nav.dataset.settingsUrl  || "/hub-settings");
    const orders    = norm(nav.dataset.ordersUrl     || "/hub-orders");

    const here = norm(window.location.pathname);

    let activeKey = "";

    // Exact matches
    if (here === dashboard) activeKey = "dashboard";
    else if (here === items) activeKey = "items";
    else if (here === settings) activeKey = "settings";

    // Related pages: edit-item belongs to items section
    else if (here.includes("/edit-item")) activeKey = "items";
    else if (here.includes("/edit-item-categories")) activeKey = "items";

    // Hub orders
    else if (here === orders || here.includes("/hub-orders") || here.includes("/hub-active-orders")) {
      activeKey = "orders";
    }

    nav.querySelectorAll(".knx-hbn__item").forEach((a) => {
      a.classList.toggle("is-active", a.dataset.nav === activeKey);
    });
  });
})();
