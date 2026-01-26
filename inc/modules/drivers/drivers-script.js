/* global KNX_DRIVER_CONFIG */
(function () {
  "use strict";

  /**
   * ==========================================================
   * KNX Drivers - Driver Dashboard Script (SEALED MVP v1.3)
   * ----------------------------------------------------------
   * ASCII-only source (prevents "Invalid or unexpected token")
   * - Robust fetch (tolerate non-JSON/HTML)
   * - No window.confirm (uses modal)
   * - Release + Delay hit real endpoints
   * - History shows IDs only (per_page hard cap 5)
   * - Availability is independent (drivers can finish orders off-duty)
   * ==========================================================
   */

  /** ---------- Tiny DOM helpers ---------- */
  function $(sel, root) {
    return (root || document).querySelector(sel);
  }
  function byId(id) {
    return document.getElementById(id);
  }
  function escapeHtml(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }
  function domReady(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn);
    } else {
      fn();
    }
  }

  /** ---------- CSS.escape polyfill (minimal) ---------- */
  if (typeof window.CSS === "undefined") window.CSS = {};
  if (typeof window.CSS.escape !== "function") {
    window.CSS.escape = function (value) {
      // Minimal safe escape for attribute selectors
      return String(value).replace(/[^a-zA-Z0-9_-]/g, function (ch) {
        var hex = ch.charCodeAt(0).toString(16).toUpperCase();
        return "\\" + hex + " ";
      });
    };
  }

  /** ---------- Config ---------- */
  var CFG = window.KNX_DRIVER_CONFIG || {};
  var root = byId("knx-driver-dashboard") || document.body;

  /** ---------- UI nodes (fail-soft) ---------- */
  var listEl = byId("knx-driver-list") || root;
  var pillEl = byId("knx-driver-pill") || null;
  var toastEl = byId("knx-driver-toast") || null;

  var refreshBtn = byId("knx-driver-refresh-btn");
  var openHistoryBtn = byId("knx-driver-open-history");
  var pushTestBtn = byId('knx-driver-push-test');

  /** ---------- State ---------- */
  var isLocked = false;
  var isBusy = false;

  var POLL_INTERVAL = 30000;
  var pollTimer = null;
  var currentAbort = null;

  var seenOrderIds = new Set();
  var newOrderIds = new Set();
  var hasLoadedOnce = false;
  var NEW_HIGHLIGHT_MS = 8000;

  var availabilityState = "off";

  /** ---------- Toast + pill ---------- */
  function setPill(text, tone) {
    if (!pillEl) return;
    pillEl.textContent = text || "";
    pillEl.classList.remove("knx-pill--danger", "knx-pill--ok", "knx-pill--muted");
    if (tone) pillEl.classList.add(tone);
  }

  function toast(msg) {
    if (!toastEl || !msg) return;
    toastEl.textContent = msg;
    toastEl.classList.add("knx-toast--show");
    clearTimeout(toastEl.__t);
    toastEl.__t = setTimeout(function () {
      toastEl.classList.remove("knx-toast--show");
    }, 2600);
  }

  function renderCard(title, subtitle) {
    listEl.innerHTML =
      '<div class="knx-card knx-card--soft"><div class="knx-card__body">' +
      '<div class="knx-title">' +
      escapeHtml(title || "") +
      "</div>" +
      (subtitle ? '<div class="knx-muted">' + escapeHtml(subtitle) + "</div>" : "") +
      "</div></div>";
  }

  function renderRestricted(status) {
    isLocked = true;
    setPill("Restricted (" + status + ")", "knx-pill--danger");
    renderCard("Access restricted", "Please log in again.");
    toast("Access restricted.");
  }

  function renderError(msg) {
    setPill("Error", "knx-pill--danger");
    renderCard("Something went wrong", msg || "Please try again.");
  }

  function renderEmpty() {
    setPill("No orders", "knx-pill--muted");
    renderCard("No assigned orders yet", "Pull to refresh or tap Refresh.");
  }

  /** ---------- Network helpers ---------- */
  function buildUrl(base, qs) {
    var url = String(base || "").trim();
    if (!url) return "";
    var asUserId = parseInt(CFG.asUserId || 0, 10);
    var parts = [];
    if (asUserId > 0) parts.push("as_user_id=" + encodeURIComponent(String(asUserId)));
    if (qs) {
      Object.keys(qs).forEach(function (k) {
        if (qs[k] === undefined || qs[k] === null || qs[k] === "") return;
        parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(String(qs[k])));
      });
    }
    if (parts.length) url += (url.indexOf("?") >= 0 ? "&" : "?") + parts.join("&");
    return url;
  }

  async function readResponseBody(res) {
    var text = "";
    try {
      text = await res.text();
    } catch (e) {
      text = "";
    }
    if (!text) return { json: null, text: "" };
    try {
      return { json: JSON.parse(text), text: text };
    } catch (e2) {
      return { json: null, text: text };
    }
  }

  function extractOrders(json) {
    if (!json) return [];
    var data = json.data && typeof json.data === "object" ? json.data : null;
    if (data && Array.isArray(data.orders)) return data.orders;
    if (data && Array.isArray(data.rows)) return data.rows;
    if (Array.isArray(json.orders)) return json.orders;
    if (Array.isArray(json.rows)) return json.rows;
    if (Array.isArray(json.data)) return json.data;
    return [];
  }

  function extractHistoryItems(json) {
    if (!json) return [];
    var data = json.data && typeof json.data === "object" ? json.data : null;
    if (data && Array.isArray(data.items)) return data.items;
    if (Array.isArray(json.items)) return json.items;
    return [];
  }

  async function fetchJson(url, opts) {
    var options = opts || {};
    options.credentials = "include";
    options.headers = options.headers || {};
    options.headers["Accept"] = "application/json";

    var res;
    try {
      res = await fetch(url, options);
    } catch (netErr) {
      return { ok: false, status: 0, json: null, text: "", netErr: netErr };
    }
    var payload = await readResponseBody(res);
    return { ok: res.ok, status: res.status, json: payload.json, text: payload.text };
  }

  /** ---------- Map helpers ---------- */
  function buildMapUrl(address) {
    if (!address) return "";
    var enc = encodeURIComponent(address);
    var ua = "";
    try {
      ua = navigator.userAgent || "";
    } catch (e) {
      ua = "";
    }

    if (/iPhone|iPad|iPod/i.test(ua)) return "maps://?daddr=" + enc;
    if (/Android/i.test(ua)) return "google.navigation:q=" + enc;
    return "https://www.google.com/maps/dir/?api=1&destination=" + enc;
  }

  function openMap(address) {
    var primary = buildMapUrl(address);
    if (!primary) return;
    window.location.href = primary;

    // Android fallback: geo scheme (best-effort)
    var ua = "";
    try {
      ua = navigator.userAgent || "";
    } catch (e) {
      ua = "";
    }
    if (/Android/i.test(ua)) {
      setTimeout(function () {
        try {
          window.location.href = "geo:0,0?q=" + encodeURIComponent(address);
        } catch (e2) {}
      }, 450);
    }
  }

  /** ---------- Order status helpers ---------- */
  function normalizeStatus(s) {
    var v = String(s || "").trim().toLowerCase();
    return v || "assigned";
  }

  function nextActionFor(opsStatus) {
    var s = normalizeStatus(opsStatus);
    if (s === "assigned") return { next: "picked_up", label: "Picked up" };
    if (s === "picked_up") return { next: "delivered", label: "Delivered" };
    return null;
  }

  /** ---------- New order highlight ---------- */
  function extractOrderIds(rows) {
    var ids = [];
    if (!rows || !rows.length) return ids;
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      var id = r.order_id || r.id || "";
      if (id !== "") ids.push(String(id));
    }
    return ids;
  }

  function markNewOrders(orderList) {
    var ids = extractOrderIds(orderList);
    var newly = [];
    for (var i = 0; i < ids.length; i++) {
      var id = ids[i];
      if (!seenOrderIds.has(id)) {
        if (hasLoadedOnce) {
          newOrderIds.add(id);
          newly.push(id);
        }
        seenOrderIds.add(id);
      }
    }
    return newly;
  }

  function highlightNewOrders() {
    if (!newOrderIds || newOrderIds.size === 0) return;
    try {
      newOrderIds.forEach(function (id) {
        var card = listEl.querySelector('[data-order-id="' + window.CSS.escape(id) + '"]');
        if (card) {
          card.classList.add("knx-order-new");
          var badgeHost = card.querySelector(".knx-driver-card__title");
          if (badgeHost && !card.querySelector(".knx-badge")) {
            var b = document.createElement("span");
            b.className = "knx-badge";
            b.textContent = "New";
            badgeHost.appendChild(b);
          }
        }
      });
      setTimeout(function () {
        newOrderIds.forEach(function (id2) {
          var c = listEl.querySelector('[data-order-id="' + window.CSS.escape(id2) + '"]');
          if (c) c.classList.remove("knx-order-new");
        });
        newOrderIds.clear();
      }, NEW_HIGHLIGHT_MS);
    } catch (e) {
      newOrderIds.clear();
    }
  }

  /** ---------- Banner for new orders ---------- */
  var bannerEl = byId('knx-driver-banner');
  function showBanner(msg) {
    try {
      if (!bannerEl) return;
      bannerEl.textContent = String(msg || 'New orders available');
      bannerEl.classList.add('knx-driver-banner--show');
      setTimeout(function () {
        if (bannerEl) bannerEl.classList.remove('knx-driver-banner--show');
      }, 4500);
      try { if (navigator.vibrate) navigator.vibrate([60,40,60]); } catch (e) {}
    } catch (e) {}
  }

  function playNotify() {
    try {
      if (navigator.vibrate) navigator.vibrate(180);
    } catch (e) {}
  }

  /** ---------- Modal (Confirm) ---------- */
  var modalEl = byId("knx-driver-modal");
  var modalTitleEl = byId("knx-modal-title");
  var modalBodyEl = byId("knx-modal-body");
  var modalCancelEl = byId("knx-modal-cancel");
  var modalConfirmEl = byId("knx-modal-confirm");

  function openModal(title, bodyHtml, onConfirm) {
    if (!modalEl || !modalTitleEl || !modalBodyEl || !modalCancelEl || !modalConfirmEl) {
      // ultimate fallback (should not happen in shortcode)
      if (typeof onConfirm === "function") onConfirm();
      return;
    }

    modalTitleEl.textContent = title || "Confirm";
    modalBodyEl.innerHTML = bodyHtml || "";

    modalEl.setAttribute("aria-hidden", "false");
    modalEl.classList.add("knx-modal--open");

    function close() {
      modalEl.setAttribute("aria-hidden", "true");
      modalEl.classList.remove("knx-modal--open");
      modalCancelEl.removeEventListener("click", onCancel);
      modalConfirmEl.removeEventListener("click", onOk);
      modalEl.removeEventListener("click", onBackdrop);
      document.removeEventListener("keydown", onEsc);
    }

    function onCancel(e) {
      e.preventDefault();
      close();
    }
    function onOk(e) {
      e.preventDefault();
      close();
      if (typeof onConfirm === "function") onConfirm();
    }
    function onBackdrop(e) {
      var t = e.target;
      if (t && t.getAttribute && t.getAttribute("data-knx-close") === "1") close();
    }
    function onEsc(e) {
      if (e.key === "Escape") close();
    }

    modalCancelEl.addEventListener("click", onCancel);
    modalConfirmEl.addEventListener("click", onOk);
    modalEl.addEventListener("click", onBackdrop);
    document.addEventListener("keydown", onEsc);
  }

  /** ---------- Delay Modal ---------- */
  var delayEl = byId("knx-driver-delay");
  var delayOpenOrderId = 0;

  function openDelay(orderId) {
    delayOpenOrderId = parseInt(orderId, 10) || 0;
    if (!delayEl || !delayOpenOrderId) return;
    delayEl.setAttribute("aria-hidden", "false");
    delayEl.classList.add("knx-modal--open");
  }

  function closeDelay() {
    delayOpenOrderId = 0;
    if (!delayEl) return;
    delayEl.setAttribute("aria-hidden", "true");
    delayEl.classList.remove("knx-modal--open");
  }

  async function submitDelay(delayCode) {
    if (isLocked || isBusy) return;
    var orderId = delayOpenOrderId;
    if (!orderId) return;

    var url = buildUrl(CFG.delayEndpoint);
    if (!url) {
      toast("Delay endpoint missing.");
      return;
    }

    isBusy = true;
    setPill("Saving delay...", "knx-pill--muted");

    var res = await fetchJson(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ order_id: orderId, delay_code: String(delayCode || "") })
    });

    isBusy = false;

    if (res.status === 401 || res.status === 403) {
      renderRestricted(res.status);
      return;
    }
    if (!res.ok) {
      toast("Failed to report delay.");
      setPill("Error", "knx-pill--danger");
      return;
    }

    toast("Delay saved.");
    triggerImmediateRefresh();
  }

  /** ---------- History Modal ---------- */
  var historyEl = byId("knx-driver-history");
  var historyListEl = byId("knx-history-list");
  var historyPrevEl = byId("knx-history-prev");
  var historyNextEl = byId("knx-history-next");
  var historyPageEl = byId("knx-history-page");

  var historyPage = 1;
  var historyHasMore = false;

  function openHistory() {
    if (!historyEl) return;
    historyEl.setAttribute("aria-hidden", "false");
    historyEl.classList.add("knx-modal--open");
    historyPage = 1;
    loadHistoryPage(historyPage);
  }

  function closeHistory() {
    if (!historyEl) return;
    historyEl.setAttribute("aria-hidden", "true");
    historyEl.classList.remove("knx-modal--open");
  }

  function renderHistory(items) {
    if (!historyListEl) return;

    if (!items || !items.length) {
      historyListEl.innerHTML =
        '<div class="knx-card knx-card--soft"><div class="knx-card__body"><div class="knx-muted">No completed orders yet.</div></div></div>';
      return;
    }

    var html = [];
    for (var i = 0; i < items.length; i++) {
      var it = items[i] || {};
      var oid = it.order_id || it.id || "";
      html.push(
        '<div class="knx-history-row">' +
          '<div class="knx-history-left">Order #' +
          escapeHtml(oid) +
          "</div>" +
          '<button type="button" class="knx-btn knx-btn--ghost knx-btn--sm" disabled title="Coming soon">Upload receipt</button>' +
        "</div>"
      );
    }

    historyListEl.innerHTML = html.join("");
  }

  async function loadHistoryPage(page) {
    if (isLocked || isBusy) return;
    var url = buildUrl(CFG.ordersHistory, { page: page, per_page: 5 });
    if (!url) {
      if (historyListEl) historyListEl.innerHTML = '<div class="knx-muted">History endpoint missing.</div>';
      return;
    }

    isBusy = true;
    if (historyListEl) historyListEl.innerHTML = '<div class="knx-muted">Loading...</div>';

    var res = await fetchJson(url, { method: "GET" });

    isBusy = false;

    if (res.status === 401 || res.status === 403) {
      renderRestricted(res.status);
      closeHistory();
      return;
    }
    if (!res.ok) {
      if (historyListEl) historyListEl.innerHTML = '<div class="knx-muted">Failed to load history.</div>';
      return;
    }

    var items = extractHistoryItems(res.json);
    historyHasMore = items.length === 5;

    renderHistory(items);

    if (historyPageEl) historyPageEl.textContent = "Page " + String(page);
    if (historyPrevEl) historyPrevEl.disabled = page <= 1;
    if (historyNextEl) historyNextEl.disabled = !historyHasMore;
  }

  /** ---------- Availability ---------- */
  function renderAvailability(status) {
    var pill = byId("knx-driver-availability-pill");
    if (!pill) return;

    availabilityState = status === "on" ? "on" : "off";

    pill.classList.remove("knx-pill--ok", "knx-pill--muted");
    if (availabilityState === "on") {
      pill.textContent = "ON DUTY";
      pill.classList.add("knx-pill--ok");
    } else {
      pill.textContent = "OFF DUTY";
      pill.classList.add("knx-pill--muted");
    }
  }

  async function loadAvailability() {
    var url = buildUrl(CFG.availabilityGet);
    if (!url) return;
    var res = await fetchJson(url, { method: "GET" });
    if (!res || !res.ok) return;

    var s =
      res.json && res.json.data && res.json.data.status ? String(res.json.data.status) :
      res.json && res.json.status ? String(res.json.status) :
      "off";

    renderAvailability(s);
  }

  async function toggleAvailability() {
    // Availability must not block finishing orders (MVP rule).
    var current = availabilityState === "on" ? "on" : "off";
    var next = current === "on" ? "off" : "on";

    renderAvailability(next);

    var url = buildUrl(CFG.availabilitySet);
    if (!url) return;

    var res = await fetchJson(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ status: next })
    });

    if (!res || !res.ok) {
      renderAvailability(current);
      toast("Failed to update availability.");
    }
  }

  /** ---------- Rendering orders ---------- */
  function renderOrders(rows) {
    if (!rows || !rows.length) {
      renderEmpty();
      return;
    }

    var html = [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      var orderId = r.order_id || r.id || "";
      var total = r.total != null ? String(r.total) : "";
      var fulfillment = r.fulfillment_type ? String(r.fulfillment_type) : "";
      var addr = r.delivery_address ? String(r.delivery_address) : "";
      var ops = normalizeStatus(r.ops_status || r.status);
      var action = nextActionFor(ops);

      html.push('<article class="knx-driver-card" data-order-id="' + escapeHtml(orderId) + '">');

      html.push('<div class="knx-driver-card__head">');
      html.push('<div class="knx-driver-card__title">Order #' + escapeHtml(orderId) + "</div>");
      html.push('<div class="knx-pill knx-pill--soft">Status: ' + escapeHtml(ops) + "</div>");
      html.push("</div>");

      html.push('<div class="knx-driver-card__meta">');
      if (total) html.push('<div class="knx-meta"><span>Total</span><strong>' + escapeHtml(total) + "</strong></div>");
      if (fulfillment) html.push('<div class="knx-meta"><span>Type</span><strong>' + escapeHtml(fulfillment) + "</strong></div>");
      if (addr) html.push('<div class="knx-meta knx-meta--wide"><span>Address</span><strong>' + escapeHtml(addr) + "</strong></div>");
      html.push("</div>");

      html.push('<div class="knx-driver-card__actions">');

      if (addr) {
        html.push(
          '<button type="button" class="knx-btn knx-btn--ghost knx-btn--sm knx-act-map" data-address="' +
            escapeHtml(addr) +
          '">Map</button>'
        );
      }

        // Show Delay only when order is not terminal (ops or order status)
        var orderStatus = (r.order_status || '').toString().toLowerCase();
        var terminalStates = ['delivered','completed','cancelled','canceled'];
        if (terminalStates.indexOf(ops) === -1 && terminalStates.indexOf(orderStatus) === -1) {
          html.push(
            '<button type="button" class="knx-btn knx-btn--ghost knx-btn--sm knx-act-delay" data-order-id="' +
              escapeHtml(orderId) +
            '">Delay</button>'
          );
        }

      // Only show Release when the ops status is not terminal (delivered/completed)
      if (['delivered', 'completed'].indexOf(ops) === -1) {
        html.push(
          '<button type="button" class="knx-btn knx-btn--danger knx-btn--sm knx-act-release" data-order-id="' +
            escapeHtml(orderId) +
          '">Release</button>'
        );
      }

      if (action && orderId) {
        html.push(
          '<button type="button" class="knx-btn knx-btn--primary knx-btn--sm knx-act-next" ' +
            'data-next-status="' + escapeHtml(action.next) + '" ' +
            'data-order-id="' + escapeHtml(orderId) + '">' +
            escapeHtml(action.label) +
          "</button>"
        );
      } else {
        html.push('<button type="button" class="knx-btn knx-btn--ghost knx-btn--sm" disabled>Completed</button>');
      }

      html.push("</div>");
      html.push("</article>");
    }

    listEl.innerHTML = html.join("");
  }

  /** ---------- API actions ---------- */
  async function loadOrders() {
    if (isLocked || isBusy) return;

    var url = buildUrl(CFG.myOrders);
    if (!url) {
      renderError("Driver config missing: myOrders");
      return;
    }

    // Abort any previous in-flight request before starting a new one.
    if (currentAbort) {
      try { currentAbort.abort(); } catch (e) {}
      currentAbort = null;
    }
    currentAbort = new AbortController();

    isBusy = true;
    setPill("Loading...", "knx-pill--muted");

    var res = await fetchJson(url, { method: "GET", signal: currentAbort.signal });

    isBusy = false;

    if (res.status === 401 || res.status === 403) {
      renderRestricted(res.status);
      return;
    }
    if (!res.ok) {
      renderError("HTTP " + String(res.status || 0));
      toast("Failed to load orders.");
      return;
    }

    var orders = extractOrders(res.json);
    var newly = markNewOrders(orders);
    hasLoadedOnce = true;

    setPill(
      orders.length ? "Loaded " + orders.length + " order(s)" : "No orders",
      orders.length ? "knx-pill--ok" : "knx-pill--muted"
    );
    renderOrders(orders);

    if (newly && newly.length) {
      playNotify();
      highlightNewOrders();
      toast("New order(s) received.");
      // show a small banner to draw attention in addition to toast
      try { showBanner("New order(s) received"); } catch (e) {}
    }
  }

  async function updateStatus(orderId, nextStatus) {
    if (isLocked || isBusy) return;

    var url = buildUrl(CFG.updateStatus);
    if (!url) {
      toast("Missing updateStatus endpoint.");
      return;
    }

    isBusy = true;
    setPill("Updating...", "knx-pill--muted");

    var body = {
      order_id: parseInt(orderId, 10),
      next_status: String(nextStatus || "").trim()
    };

    // Admin acting as driver (testing only)
    var asUserId = parseInt(CFG.asUserId || 0, 10);
    if (asUserId > 0) body.as_user_id = asUserId;

    var res = await fetchJson(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    });

    isBusy = false;

    if (res.status === 401 || res.status === 403) {
      renderRestricted(res.status);
      return;
    }
    if (!res.ok) {
      toast("Update failed.");
      setPill("Error", "knx-pill--danger");
      return;
    }

    toast("Status updated.");
    triggerImmediateRefresh();
  }

  async function releaseOrder(orderId) {
    if (isLocked || isBusy) return;

    var url = buildUrl(CFG.releaseEndpoint);
    if (!url) {
      toast("Release endpoint missing.");
      return;
    }

    isBusy = true;
    setPill("Releasing...", "knx-pill--muted");

    var body = { order_id: parseInt(orderId, 10) };
    var asUserId = parseInt(CFG.asUserId || 0, 10);
    if (asUserId > 0) body.as_user_id = asUserId;

    var res = await fetchJson(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    });

    isBusy = false;

    if (res.status === 401 || res.status === 403) {
      renderRestricted(res.status);
      return;
    }
    if (!res.ok) {
      toast("Release failed.");
      setPill("Error", "knx-pill--danger");
      return;
    }

    toast("Order released.");
    triggerImmediateRefresh();
  }

  /** ---------- Polling ---------- */
  async function triggerPoll() {
    if (isLocked || isBusy) return;
    await loadOrders();
  }

  function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(function () {
      if (document.hidden) return;
      triggerPoll();
    }, POLL_INTERVAL);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    if (currentAbort) {
      try { currentAbort.abort(); } catch (e) {}
      currentAbort = null;
    }
  }

  function triggerImmediateRefresh() {
    triggerPoll();
  }

  function setupVisibilityHandlers() {
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) stopPolling();
      else {
        startPolling();
        triggerImmediateRefresh();
      }
    });
    window.addEventListener("focus", function () {
      triggerImmediateRefresh();
    });
  }

  // Push test handler
  if (pushTestBtn) {
    pushTestBtn.addEventListener('click', async function () {
      try {
        var url = buildUrl(CFG.pushTest);
        if (!url) { toast('Push test endpoint missing.'); return; }
        var res = await fetchJson(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ target: 'driver' }) });
        if (!res.ok) {
          toast('Push test failed.');
          return;
        }
        if (res.json && res.json.data && res.json.data.mock) {
          toast('Subscription found (mock).');
        } else {
          toast('Push sent.');
        }
      } catch (e) {
        toast('Push test error.');
      }
    });
  }

  function setupPullToRefresh() {
    var startY = 0;
    var pulling = false;
    var threshold = 70;

    window.addEventListener(
      "touchstart",
      function (e) {
        if (document.scrollingElement && document.scrollingElement.scrollTop === 0) {
          startY = e.touches && e.touches[0] ? e.touches[0].clientY : 0;
        } else {
          startY = 0;
        }
      },
      { passive: true }
    );

    window.addEventListener(
      "touchmove",
      function (e) {
        if (!startY) return;
        var y = e.touches && e.touches[0] ? e.touches[0].clientY : 0;
        var delta = y - startY;
        if (delta > threshold && !pulling) pulling = true;
      },
      { passive: true }
    );

    window.addEventListener(
      "touchend",
      function () {
        if (pulling) {
          pulling = false;
          triggerImmediateRefresh();
        }
        startY = 0;
      },
      { passive: true }
    );
  }

  /** ---------- Events ---------- */
  function wireEvents() {
    if (refreshBtn) {
      refreshBtn.addEventListener("click", function () {
        if (isLocked || isBusy) return;
        triggerImmediateRefresh();
      });
    }

    if (openHistoryBtn) {
      openHistoryBtn.addEventListener("click", function () {
        if (isLocked) return;
        openHistory();
      });
    }

    var availBtn = byId("knx-driver-toggle-availability");
    if (availBtn) {
      availBtn.addEventListener("click", function () {
        if (isLocked || isBusy) return;
        toggleAvailability();
      });
    }

    // Delay modal close + chip submit
    if (delayEl) {
      delayEl.addEventListener("click", function (e) {
        var t = e.target;
        if (t && t.getAttribute && t.getAttribute("data-knx-delay-close") === "1") closeDelay();
        if (t && t.classList && t.classList.contains("knx-chip")) {
          var code = t.getAttribute("data-delay-code") || "";
          closeDelay();
          submitDelay(code);
        }
      });
    }

    // History modal close + pager
    if (historyEl) {
      historyEl.addEventListener("click", function (e) {
        var t = e.target;
        if (t && t.getAttribute && t.getAttribute("data-knx-history-close") === "1") closeHistory();
      });
    }
    if (historyPrevEl) {
      historyPrevEl.addEventListener("click", function () {
        if (isLocked || isBusy) return;
        if (historyPage <= 1) return;
        historyPage -= 1;
        loadHistoryPage(historyPage);
      });
    }
    if (historyNextEl) {
      historyNextEl.addEventListener("click", function () {
        if (isLocked || isBusy) return;
        if (!historyHasMore) return;
        historyPage += 1;
        loadHistoryPage(historyPage);
      });
    }

    // List action delegation
    listEl.addEventListener("click", function (e) {
      var t = e.target;
      if (!t || !t.classList) return;

      if (t.classList.contains("knx-act-map")) {
        e.preventDefault();
        var addr = t.getAttribute("data-address") || "";
        if (addr) openMap(addr);
        return;
      }

      if (t.classList.contains("knx-act-delay")) {
        e.preventDefault();
        if (isLocked || isBusy) return;
        var did = t.getAttribute("data-order-id") || "";
        if (!did) return;
        openDelay(did);
        return;
      }

      if (t.classList.contains("knx-act-release")) {
        e.preventDefault();
        if (isLocked || isBusy) return;
        var rid = t.getAttribute("data-order-id") || "";
        if (!rid) return;

        openModal(
          "Release order?",
          '<div class="knx-modal__line"><strong>Order #' + escapeHtml(rid) + "</strong></div>" +
            '<div class="knx-muted">This puts the order back into the available pool. Use only if you can\'t complete it.</div>',
          function () {
            releaseOrder(rid);
          }
        );
        return;
      }

      if (t.classList.contains("knx-act-next")) {
        e.preventDefault();
        if (isLocked || isBusy) return;

        var oid = t.getAttribute("data-order-id") || "";
        var next = t.getAttribute("data-next-status") || "";
        if (!oid || !next) return;

        var label = t.textContent ? t.textContent.trim() : "Confirm";
        openModal(
          "Confirm action",
          '<div class="knx-modal__line"><strong>Order #' + escapeHtml(oid) + "</strong></div>" +
            '<div class="knx-muted">Action: <strong>' + escapeHtml(label) + "</strong></div>",
          function () {
            updateStatus(oid, next);
          }
        );
        return;
      }
    });
  }

  /** ---------- Boot ---------- */
  domReady(function () {
    wireEvents();
    setupVisibilityHandlers();
    setupPullToRefresh();

    loadAvailability();
    loadOrders();
    startPolling();
  });
})();
