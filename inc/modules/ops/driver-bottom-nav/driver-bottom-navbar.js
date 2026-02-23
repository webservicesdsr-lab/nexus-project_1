(function () {
  "use strict";

  function norm(u) {
    try {
      const x = new URL(u, window.location.origin);
      // strip trailing slash
      return x.pathname.replace(/\/+$/, "") || "/";
    } catch (_) {
      return String(u || "").replace(/\/+$/, "") || "/";
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const nav = document.querySelector("[data-knx-driver-bottom-nav]");
    if (!nav) return;

    const home = norm(nav.dataset.homeUrl || "/driver-quick-menu");
    const catchU = norm(nav.dataset.catchUrl || "/driver-ops");
    const orders = norm(nav.dataset.ordersUrl || "/driver-active-orders");
    const profile = norm(nav.dataset.profileUrl || "/driver-profile");

    const here = norm(window.location.pathname);

    let activeKey = "";
    
    // Exact matches
    if (here === home) activeKey = "home";
    else if (here === catchU) activeKey = "catch";
    else if (here === orders) activeKey = "orders";
    else if (here === profile) activeKey = "profile";
    
    // Related pages (view order belongs to Orders section)
    else if (here.includes("/driver-view-order") || here.includes("/order-detail")) {
      activeKey = "orders";
    }
    // Driver live orders also belongs to Orders
    else if (here.includes("/driver-live-orders")) {
      activeKey = "orders";
    }

    nav.querySelectorAll(".knx-dbn__item").forEach((a) => {
      a.classList.toggle("is-active", a.dataset.nav === activeKey);
    });
  });
})();