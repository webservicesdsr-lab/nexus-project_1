(function () {
  "use strict";

  function qs(sel, root) { return (root || document).querySelector(sel); }

  const root = qs(".knx-live-orders-wrapper");
  if (!root) return;

  const apiLive = root.dataset.apiLive || "";
  const apiSubscribe = root.dataset.apiSubscribe || "";
  const apiUnsubscribe = root.dataset.apiUnsubscribe || "";
  const vapid = root.dataset.vapid || "";
  const pollMsBase = Math.max(1500, parseInt(root.dataset.pollMs || "5000", 10) || 5000);
  const audience = root.dataset.audience || "ops_orders";

  const listEl = qs("#knxOpsOrdersList", root);
  const refreshBtn = qs("#knxOpsRefreshBtn", root);
  const pollingBtn = qs("#knxOpsPollingBtn", root);
  const pushBtn = qs("#knxOpsPushBtn", root);
  const testBtn = qs("#knxOpsTestBtn", root);

  const connPill = qs("#knxOpsConnPill", root);
  const updatedPill = qs("#knxOpsUpdatedPill", root);

  let pollTimer = null;
  let pollingEnabled = true;
  let backoffMs = pollMsBase;
  let lastIds = new Set();
  let lastCursor = ""; // optional (server may return)

  function toast(msg, type) {
    try {
      if (typeof window.knxToast === "function") {
        window.knxToast(String(msg || ""), type || "info");
        return;
      }
      if (window.knxToast && typeof window.knxToast.show === "function") {
        window.knxToast.show(String(msg || ""), type || "info");
        return;
      }
    } catch (_) {}
    try { console.log("[KNX][OPS]", msg); } catch (_) {}
  }

  function esc(s) {
    return String(s ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;"
    }[c]));
  }

  function setConn(state) {
    if (!connPill) return;
    connPill.classList.remove("knx-pill--ok", "knx-pill--bad", "knx-pill--muted");
    if (state === "ok") {
      connPill.textContent = "Live connected";
      connPill.classList.add("knx-pill--ok");
      return;
    }
    if (state === "bad") {
      connPill.textContent = "Connection issue";
      connPill.classList.add("knx-pill--bad");
      return;
    }
    connPill.textContent = "Connecting…";
    connPill.classList.add("knx-pill--muted");
  }

  function setUpdatedNow() {
    if (!updatedPill) return;
    const d = new Date();
    const hh = String(d.getHours()).padStart(2, "0");
    const mm = String(d.getMinutes()).padStart(2, "0");
    const ss = String(d.getSeconds()).padStart(2, "0");
    updatedPill.textContent = `Last update: ${hh}:${mm}:${ss}`;
  }

  async function getJson(url) {
    const res = await fetch(url, { method: "GET", credentials: "same-origin" });
    let json = null;
    try { json = await res.json(); } catch (_) {}
    return { ok: res.ok, status: res.status, json };
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload || {})
    });
    let json = null;
    try { json = await res.json(); } catch (_) {}
    return { ok: res.ok, status: res.status, json };
  }

  function normalizeOrders(payload) {
    // Expected from ops endpoint:
    // { success:true, data:{ orders:[...], cursor:"..." } }
    if (!payload) return { orders: [], cursor: "" };
    const data = payload.data || payload;
    const orders = Array.isArray(data.orders) ? data.orders : [];
    const cursor = String(data.cursor || payload.cursor || "");
    return { orders, cursor };
  }

  function renderEmpty() {
    listEl.innerHTML = `
      <div class="knx-empty">
        <i class="fas fa-inbox"></i>
        <div>
          <div class="knx-empty-title">No live orders</div>
          <div class="knx-empty-sub">Waiting for new orders…</div>
        </div>
      </div>
    `;
  }

  function statusPill(status) {
    const s = String(status || "unknown").toLowerCase();
    const cls =
      (s === "new" || s === "pending") ? "knx-status--new" :
      (s === "accepted" || s === "confirmed") ? "knx-status--ok" :
      (s === "preparing") ? "knx-status--prep" :
      (s === "ready") ? "knx-status--ready" :
      (s === "en_route" || s === "enroute" || s === "out_for_delivery") ? "knx-status--route" :
      (s === "delivered" || s === "completed") ? "knx-status--done" :
      (s === "canceled" || s === "cancelled") ? "knx-status--bad" :
      "knx-status--muted";

    return `<span class="knx-status ${cls}">${esc(s.replace(/_/g, " "))}</span>`;
  }

  function renderOrders(orders) {
    if (!orders || orders.length === 0) {
      renderEmpty();
      return;
    }

    const html = orders.map(o => {
      const id = o.id ?? o.order_id ?? "—";
      const customer = o.customer_name ?? o.customer ?? o.name ?? "—";
      const total = o.total_display ?? o.total ?? "";
      const hub = o.hub_name ?? o.hub ?? "";
      const updated = o.updated_at ?? o.updated ?? "";
      const isNew = !lastIds.has(String(id));

      return `
        <div class="knx-order-card ${isNew ? "is-new" : ""}" data-order-id="${esc(id)}">
          <div class="knx-order-top">
            <div class="knx-order-left">
              <div class="knx-order-id">#${esc(id)}</div>
              <div class="knx-order-customer">${esc(customer)}</div>
              ${hub ? `<div class="knx-order-hub"><i class="fas fa-store"></i> ${esc(hub)}</div>` : ""}
            </div>
            <div class="knx-order-right">
              ${statusPill(o.status)}
              ${total ? `<div class="knx-order-total">${esc(total)}</div>` : ""}
            </div>
          </div>
          ${updated ? `<div class="knx-order-meta">Updated: ${esc(updated)}</div>` : ""}
        </div>
      `;
    }).join("");

    listEl.innerHTML = `<div class="knx-orders-grid">${html}</div>`;
  }

  function detectNewOrders(orders) {
    const ids = new Set((orders || []).map(o => String(o.id ?? o.order_id ?? "")));
    for (const id of ids) {
      if (id && !lastIds.has(id)) {
        // "new" detection
        toast(`New order #${id}`, "success");
        // Local notification ONLY if page is alive (not background)
        if (window.Notification && Notification.permission === "granted") {
          try { new Notification("New order", { body: `#${id}` }); } catch (_) {}
        }
      }
    }
    lastIds = ids;
  }

  async function fetchLive() {
    if (!apiLive) return;

    const url = new URL(apiLive, location.origin);
    url.searchParams.set("limit", "60");
    if (lastCursor) url.searchParams.set("cursor", lastCursor);

    try {
      setConn("muted");
      const { ok, json } = await getJson(url.toString());

      if (!ok || !json || json.success === false) {
        setConn("bad");
        backoffMs = Math.min(backoffMs * 2, 30000);
        if (json && json.message) toast(json.message, "error");
        return;
      }

      setConn("ok");
      backoffMs = pollMsBase;

      const { orders, cursor } = normalizeOrders(json);
      if (cursor) lastCursor = cursor;

      renderOrders(orders);
      detectNewOrders(orders);
      setUpdatedNow();

    } catch (e) {
      setConn("bad");
      backoffMs = Math.min(backoffMs * 2, 30000);
    }
  }

  function schedulePolling() {
    stopPolling();
    if (!pollingEnabled) return;

    // Adaptive: if tab hidden, slow down
    const hidden = document.hidden === true;
    const ms = hidden ? Math.max(15000, pollMsBase * 3) : backoffMs;

    pollTimer = setTimeout(async () => {
      await fetchLive();
      schedulePolling();
    }, ms);
  }

  function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
  }

  function setPollingUI() {
    if (!pollingBtn) return;
    pollingBtn.setAttribute("aria-pressed", pollingEnabled ? "true" : "false");
    pollingBtn.innerHTML = pollingEnabled
      ? `<i class="fas fa-signal"></i> Live: ON`
      : `<i class="fas fa-pause"></i> Live: OFF`;
  }

  // ===========================
  // Push (OPS-only, separate SW)
  // ===========================

  async function ensureOpsServiceWorker() {
    if (!("serviceWorker" in navigator)) {
      throw new Error("Service Worker not supported");
    }
    // IMPORTANT: OPS SW (NOT driver SW)
    const reg = await navigator.serviceWorker.register("/knx-ops-sw.js", { scope: "/" });
    return reg;
  }

  function urlBase64ToUint8Array(base64String) {
    if (!base64String) return new Uint8Array();
    const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  async function getExistingSubscription(reg) {
    try {
      return await reg.pushManager.getSubscription();
    } catch (_) {
      return null;
    }
  }

  async function syncPushButtonState() {
    if (!pushBtn) return;
    try {
      const reg = await ensureOpsServiceWorker();
      const sub = await getExistingSubscription(reg);
      if (sub) {
        pushBtn.classList.remove("knx-btn--primary");
        pushBtn.classList.add("knx-btn--ghost");
        pushBtn.innerHTML = `<i class="fas fa-bell-slash"></i> Disable Alerts`;
        return;
      }
    } catch (_) {}
    pushBtn.classList.remove("knx-btn--ghost");
    pushBtn.classList.add("knx-btn--primary");
    pushBtn.innerHTML = `<i class="fas fa-bell"></i> Enable Alerts`;
  }

  async function enablePush() {
    if (!apiSubscribe) throw new Error("Subscribe API not configured");
    if (!("Notification" in window)) throw new Error("Notifications not supported");

    const perm = await Notification.requestPermission();
    if (perm !== "granted") throw new Error("Permission not granted");

    const reg = await ensureOpsServiceWorker();

    let sub = await getExistingSubscription(reg);
    if (!sub) {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapid)
      });
    }

    const payload = {
      audience,
      subscription: sub.toJSON()
    };

    const { ok, json } = await postJson(apiSubscribe, payload);
    if (!ok || !json || json.success === false) {
      throw new Error((json && (json.message || json.error)) || "Subscribe failed");
    }

    toast("OPS alerts enabled.", "success");
    await syncPushButtonState();
  }

  async function disablePush() {
    if (!apiUnsubscribe) throw new Error("Unsubscribe API not configured");

    const reg = await ensureOpsServiceWorker();
    const sub = await getExistingSubscription(reg);

    if (sub) {
      const endpoint = (sub.endpoint || "");
      try { await sub.unsubscribe(); } catch (_) {}

      const payload = { audience, endpoint };
      const { ok, json } = await postJson(apiUnsubscribe, payload);
      if (!ok || !json || json.success === false) {
        // Not fatal: user is unsubscribed in browser, DB may still have it
        toast("Browser unsubscribed; server cleanup may be needed.", "warning");
      } else {
        toast("OPS alerts disabled.", "success");
      }
    } else {
      toast("No active OPS subscription found.", "info");
    }

    await syncPushButtonState();
  }

  async function testNotification() {
    // This is NOT true background push; it's a quick validation that permission + SW are OK.
    try {
      if (!("Notification" in window)) throw new Error("Notifications not supported");
      const perm = await Notification.requestPermission();
      if (perm !== "granted") throw new Error("Permission not granted");

      const reg = await ensureOpsServiceWorker();
      await reg.showNotification("OPS Alerts Test", {
        body: "If you saw this, your OPS notification channel is ready.",
        tag: "knx_ops_orders_test",
        renotify: true,
        data: { url: "/ops-orders" }
      });
      toast("Test notification sent.", "success");
    } catch (e) {
      toast(e.message || "Test failed", "error");
    }
  }

  // ===========================
  // Events
  // ===========================

  if (refreshBtn) refreshBtn.addEventListener("click", () => fetchLive());
  if (pollingBtn) pollingBtn.addEventListener("click", () => {
    pollingEnabled = !pollingEnabled;
    setPollingUI();
    if (pollingEnabled) schedulePolling();
    else stopPolling();
  });

  document.addEventListener("visibilitychange", () => {
    // re-schedule to apply hidden/visible intervals
    if (pollingEnabled) schedulePolling();
  });

  if (pushBtn) pushBtn.addEventListener("click", async () => {
    try {
      pushBtn.disabled = true;

      // Determine current state
      const reg = await ensureOpsServiceWorker();
      const sub = await getExistingSubscription(reg);
      if (sub) {
        await disablePush();
      } else {
        await enablePush();
      }
    } catch (e) {
      toast(e.message || "Push action failed", "error");
    } finally {
      pushBtn.disabled = false;
    }
  });

  if (testBtn) testBtn.addEventListener("click", () => testNotification());

  // ===========================
  // Init
  // ===========================

  (async function init() {
    setConn("muted");
    setPollingUI();
    await syncPushButtonState();
    await fetchLive();
    schedulePolling();
  })();

})();
