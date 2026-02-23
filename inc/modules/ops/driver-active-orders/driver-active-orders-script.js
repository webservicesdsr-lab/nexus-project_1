/**
 * KNX DRIVER — Active Orders (CANON, DB-canon only)
 *
 * Rules:
 * - No legacy status mapping (no out_for_delivery/ready/etc).
 * - "confirmed" is displayed as "Order Created" using created_at.
 * - Two modals only: Change Status, Release Order.
 * - No alert/prompt/confirm.
 * - Polling: pause when tab hidden, pause when modal open, backoff on errors.
 */

(function () {
  "use strict";

  const DB_STATUSES = [
    "confirmed",
    "accepted_by_driver",
    "accepted_by_hub",
    "preparing",
    "prepared",
    "picked_up",
    "completed",
    "cancelled",
  ];

  const STATUS_LABELS = {
    confirmed: "Order Created",
    accepted_by_driver: "Accepted by driver",
    accepted_by_hub: "Restaurant accepted",
    preparing: "Preparing",
    prepared: "Prepared",
    picked_up: "Picked up",
    completed: "Completed",
    cancelled: "Cancelled",
  };

  const TRANSITIONS = {
    confirmed: ["accepted_by_driver", "cancelled"],
    accepted_by_driver: ["accepted_by_hub", "cancelled"],
    accepted_by_hub: ["preparing", "cancelled"],
    preparing: ["prepared", "cancelled"],
    prepared: ["picked_up", "cancelled"],
    picked_up: ["completed"],
    completed: [],
    cancelled: [],
  };

  function normalizeStatus(status) {
    const st = String(status || "").trim().toLowerCase();
    return st;
  }

  function statusLabel(status) {
    const st = normalizeStatus(status);
    return STATUS_LABELS[st] || (st ? st.replace(/_/g, " ") : "Unknown");
  }

  function isTerminal(status) {
    const st = normalizeStatus(status);
    return st === "completed" || st === "cancelled";
  }

  function allowedTransitions(status) {
    const st = normalizeStatus(status);
    return TRANSITIONS[st] || [];
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"]/g, (s) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[s]));
  }

  function parseMysqlDate(mysql) {
    // MySQL "YYYY-MM-DD HH:MM:SS" (local or server time) -> Date
    // This is best-effort; we also display raw if parsing fails.
    if (!mysql) return null;
    const s = String(mysql).trim().replace(" ", "T");
    const d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
  }

  function relativeTimeFrom(mysql) {
    const d = parseMysqlDate(mysql);
    if (!d) return mysql ? String(mysql) : "";
    const diffMs = Date.now() - d.getTime();
    const diffSec = Math.floor(diffMs / 1000);
    if (diffSec < 60) return `${diffSec}s ago`;
    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return `${diffHr}h ago`;
    const diffDay = Math.floor(diffHr / 24);
    return `${diffDay}d ago`;
  }

  function toast(root, msg, type = "info") {
    // Prefer global core toast if present
    if (window.knxToast && typeof window.knxToast === "function") {
      window.knxToast(msg, type);
      return;
    }

    const node = root.querySelector('[data-toast]');
    if (!node) return;

    node.textContent = msg;
    node.classList.remove("is-success", "is-error", "is-info");
    node.classList.add("is-show");
    if (type === "success") node.classList.add("is-success");
    else if (type === "error") node.classList.add("is-error");
    else node.classList.add("is-info");

    window.clearTimeout(toast._t);
    toast._t = window.setTimeout(() => node.classList.remove("is-show"), 3500);
  }

  async function fetchJson(url, opts = {}, abortSignal) {
    const res = await fetch(url, Object.assign({ credentials: "include", signal: abortSignal }, opts));
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}
    return { ok: res.ok, status: res.status, json, text };
  }

  function getCfg(root) {
    const pollMs = Math.max(5000, Math.min(60000, parseInt(root.dataset.pollMs || "10000", 10)));
    return {
      apiBaseV2: (root.dataset.apiBaseV2 || "/wp-json/knx/v2/driver/orders/").replace(/\/+$/, "/"),
      activeUrl: root.dataset.apiActiveUrl || "/wp-json/knx/v2/driver/orders/active",
      viewOrderUrl: root.dataset.viewOrderUrl || "/driver-view-order",
      knxNonce: root.dataset.knxNonce || "",
      pollMs,
    };
  }

  function buildOpsStatusUrl(cfg, orderId) {
    return cfg.apiBaseV2 + String(orderId) + "/ops-status";
  }

  function buildReleaseUrl(cfg, orderId) {
    return cfg.apiBaseV2 + String(orderId) + "/release";
  }

  function buildViewOrderUrl(cfg, orderId) {
    try {
      const u = new URL(cfg.viewOrderUrl, window.location.origin);
      u.searchParams.set("order_id", String(orderId));
      return u.toString();
    } catch (_) {
      const sep = cfg.viewOrderUrl.includes("?") ? "&" : "?";
      return cfg.viewOrderUrl + sep + "order_id=" + encodeURIComponent(String(orderId));
    }
  }

  function setTab(root, tab) {
    const tabs = root.querySelectorAll(".knx-do__tab");
    const panels = root.querySelectorAll(".knx-do__panel");
    tabs.forEach((b) => {
      const isOn = b.dataset.tab === tab;
      b.classList.toggle("is-active", isOn);
      b.setAttribute("aria-selected", isOn ? "true" : "false");
    });
    panels.forEach((p) => p.classList.toggle("is-active", p.dataset.panel === tab));
    root.dataset.activeTab = tab;
  }

  function setCounts(root, activeCount, completedCount) {
    const a = root.querySelector('[data-count="active"]');
    const c = root.querySelector('[data-count="completed"]');
    if (a) a.textContent = String(activeCount);
    if (c) c.textContent = String(completedCount);
  }

  function chipTextForOrder(order) {
    const st = normalizeStatus(order.status);
    if (st === "confirmed") {
      const rel = relativeTimeFrom(order.created_at);
      return rel ? `${STATUS_LABELS.confirmed} • ${rel}` : STATUS_LABELS.confirmed;
    }
    return statusLabel(st);
  }

  function chipClassForOrder(order) {
    const st = normalizeStatus(order.status);
    if (st === "confirmed") return "is-new";
    if (st === "cancelled") return "is-cancelled";
    if (st === "completed") return "is-done";
    return "is-progress";
  }

  function renderList(root, tabKey, orders) {
    const list = root.querySelector(`[data-list="${tabKey}"]`);
    if (!list) return;

    if (!orders.length) {
      list.innerHTML = `<div class="knx-do__empty">No ${tabKey} orders.</div>`;
      return;
    }

    list.innerHTML = orders.map((o) => {
      const id = o.id;
      const num = o.order_number || id;
      const st = normalizeStatus(o.status);

      const viewUrl = buildViewOrderUrl(getCfg(root), id);

      return `
        <article class="knx-do__card" data-order-id="${escapeHtml(id)}" data-order-status="${escapeHtml(st)}">
          <div class="knx-do__card-head">
            <div class="knx-do__card-title">Order #${escapeHtml(num)}</div>
            <div class="knx-do__chip ${chipClassForOrder(o)}" data-chip-status="${escapeHtml(st)}">
              ${escapeHtml(chipTextForOrder(o))}
            </div>
          </div>

          <div class="knx-do__card-body">
            <div class="knx-do__kv"><span>Customer</span><strong>${escapeHtml(o.customer_name || "—")}</strong></div>
            <div class="knx-do__kv"><span>Phone</span><strong>${escapeHtml(o.customer_phone || "—")}</strong></div>
            <div class="knx-do__kv"><span>Address</span><strong>${escapeHtml(o.delivery_address || "—")}</strong></div>
            <div class="knx-do__kv"><span>Total</span><strong>${escapeHtml(o.total != null ? String(o.total) : "—")}</strong></div>
          </div>

          <div class="knx-do__card-actions">
            <button type="button" class="knx-do__btn knx-do__btn--primary" data-action="change-status" data-order-id="${escapeHtml(id)}">
              Change status
            </button>
            <a class="knx-do__btn" href="${escapeHtml(viewUrl)}">View</a>
            ${isTerminal(st) ? "" : `
              <button type="button" class="knx-do__btn knx-do__btn--danger" data-action="release" data-order-id="${escapeHtml(id)}">
                Release
              </button>
            `}
          </div>
        </article>
      `;
    }).join("");
  }

  function openModal(root, key) {
    const modal = root.querySelector(`[data-modal="${key}"]`);
    if (!modal) return;
    modal.classList.add("is-open");
    root.dataset.modalOpen = "1";
  }

  function closeModals(root) {
    const modals = root.querySelectorAll(".knx-do__modal");
    modals.forEach((m) => m.classList.remove("is-open"));
    root.dataset.modalOpen = "0";
  }

  function setStatusModal(root, order) {
    const meta = root.querySelector("[data-status-meta]");
    const optionsWrap = root.querySelector("[data-status-options]");
    const btnConfirm = root.querySelector("[data-confirm-status]");

    if (!meta || !optionsWrap || !btnConfirm) return;

    const st = normalizeStatus(order.status);
    const allow = allowedTransitions(st);

    meta.innerHTML = `
      <div class="knx-do__meta-row"><span>Order</span><strong>#${escapeHtml(order.order_number || order.id)}</strong></div>
      <div class="knx-do__meta-row"><span>Current</span><strong>${escapeHtml(chipTextForOrder(order))}</strong></div>
    `;

    if (!allow.length) {
      optionsWrap.innerHTML = `<div class="knx-do__empty">No transitions available (terminal state).</div>`;
      btnConfirm.disabled = true;
      return;
    }

    // Radio list
    optionsWrap.innerHTML = allow.map((to) => {
      return `
        <label class="knx-do__opt">
          <input type="radio" name="knx_new_status" value="${escapeHtml(to)}" />
          <span>${escapeHtml(statusLabel(to))}</span>
        </label>
      `;
    }).join("");

    btnConfirm.disabled = true;
  }

  function setReleaseModal(root, order) {
    const meta = root.querySelector("[data-release-meta]");
    if (!meta) return;

    meta.innerHTML = `
      <div class="knx-do__meta-row"><span>Order</span><strong>#${escapeHtml(order.order_number || order.id)}</strong></div>
      <div class="knx-do__meta-row"><span>Status</span><strong>${escapeHtml(chipTextForOrder(order))}</strong></div>
    `;
  }

  async function postStatus(cfg, orderId, newStatus, abortSignal) {
    const url = buildOpsStatusUrl(cfg, orderId);
    const body = { knx_nonce: cfg.knxNonce, status: newStatus };

    return fetchJson(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    }, abortSignal);
  }

  async function postRelease(cfg, orderId, abortSignal) {
    const url = buildReleaseUrl(cfg, orderId);
    const body = { knx_nonce: cfg.knxNonce };

    return fetchJson(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    }, abortSignal);
  }

  function splitBuckets(orders) {
    const active = [];
    const completed = [];
    orders.forEach((o) => {
      const st = normalizeStatus(o.status);
      if (st === "completed" || st === "cancelled") completed.push(o);
      else active.push(o);
    });
    return { active, completed };
  }

  function stableHash(orders) {
    // Minimal hash to avoid re-render spam
    return orders.map((o) => `${o.id}:${o.status}:${o.updated_at || ""}`).join("|");
  }

  document.addEventListener("DOMContentLoaded", () => {
    const root = document.querySelector('[data-knx-driver-module="active-orders"]');
    if (!root) return;

    const cfg = getCfg(root);
    if (!cfg.knxNonce) {
      toast(root, "Missing knx_nonce. Please re-login.", "error");
      return;
    }

    let state = {
      tab: "active",
      orders: [],
      hash: "",
      loading: false,
      pollTimer: null,
      abort: null,
      backoffMs: cfg.pollMs,
      consecutiveErrors: 0,
      modalOrder: null,
      modalNewStatus: "",
    };

    // Tabs
    root.addEventListener("click", (e) => {
      const btn = e.target.closest(".knx-do__tab");
      if (!btn) return;
      const tab = btn.dataset.tab === "completed" ? "completed" : "active";
      state.tab = tab;
      setTab(root, tab);
    });

    // Modal close
    root.addEventListener("click", (e) => {
      const close = e.target.closest("[data-close-modal]");
      if (!close) return;
      closeModals(root);
      state.modalOrder = null;
      state.modalNewStatus = "";
      schedulePoll(0);
    });

    // Actions (delegation)
    root.addEventListener("click", (e) => {
      const a = e.target.closest("[data-action]");
      if (!a) return;

      const orderId = parseInt(a.dataset.orderId || "0", 10);
      if (!orderId) return;

      const order = state.orders.find((o) => parseInt(o.id, 10) === orderId);
      if (!order) return;

      const action = a.dataset.action;

      if (action === "change-status") {
        state.modalOrder = order;
        state.modalNewStatus = "";
        setStatusModal(root, order);
        openModal(root, "status");
        stopPoll();
        return;
      }

      if (action === "release") {
        state.modalOrder = order;
        setReleaseModal(root, order);
        openModal(root, "release");
        stopPoll();
        return;
      }
    });

    // Status modal selection
    root.addEventListener("change", (e) => {
      const input = e.target;
      if (!(input && input.name === "knx_new_status")) return;

      state.modalNewStatus = String(input.value || "").trim().toLowerCase();
      const btnConfirm = root.querySelector("[data-confirm-status]");
      if (btnConfirm) btnConfirm.disabled = !state.modalNewStatus;
    });

    // Confirm status
    const btnConfirmStatus = root.querySelector("[data-confirm-status]");
    if (btnConfirmStatus) {
      btnConfirmStatus.addEventListener("click", async () => {
        if (!state.modalOrder || !state.modalNewStatus) return;

        const from = normalizeStatus(state.modalOrder.status);
        const to = state.modalNewStatus;

        // UI-level guard: only allowed transitions
        const allow = allowedTransitions(from);
        if (!allow.includes(to)) {
          toast(root, `Invalid transition: ${from} → ${to}`, "error");
          return;
        }

        try {
          btnConfirmStatus.disabled = true;
          toast(root, "Updating status…", "info");

          const abort = new AbortController();
          const r = await postStatus(cfg, state.modalOrder.id, to, abort.signal);

          if (r.json && r.json.success) {
            toast(root, `Status updated to "${statusLabel(to)}"`, "success");
            closeModals(root);
            await loadOrders(true);
          } else {
            const reason = r.json?.data?.reason || "update_failed";
            const msg = r.json?.data?.message || "";
            toast(root, `Update failed (${r.status}): ${reason}${msg ? " — " + msg : ""}`, "error");
          }
        } finally {
          btnConfirmStatus.disabled = false;
          schedulePoll(0);
        }
      });
    }

    // Confirm release
    const btnConfirmRelease = root.querySelector("[data-confirm-release]");
    if (btnConfirmRelease) {
      btnConfirmRelease.addEventListener("click", async () => {
        if (!state.modalOrder) return;

        try {
          btnConfirmRelease.disabled = true;
          toast(root, "Releasing order…", "info");

          const abort = new AbortController();
          const r = await postRelease(cfg, state.modalOrder.id, abort.signal);

          if (r.json && r.json.success) {
            toast(root, "Order released ✅", "success");
            closeModals(root);
            await loadOrders(true);
          } else {
            const reason = r.json?.data?.reason || "release_failed";
            const msg = r.json?.data?.message || "";
            toast(root, `Release failed (${r.status}): ${reason}${msg ? " — " + msg : ""}`, "error");
          }
        } finally {
          btnConfirmRelease.disabled = false;
          schedulePoll(0);
        }
      });
    }

    // Polling controls
    function stopPoll() {
      if (state.pollTimer) {
        window.clearTimeout(state.pollTimer);
        state.pollTimer = null;
      }
      if (state.abort) {
        try { state.abort.abort(); } catch (_) {}
        state.abort = null;
      }
    }

    function schedulePoll(delayMs) {
      stopPoll();
      const delay = Math.max(0, delayMs);
      state.pollTimer = window.setTimeout(() => {
        loadOrders(false);
      }, delay);
    }

    function setBackoff(success) {
      if (success) {
        state.consecutiveErrors = 0;
        state.backoffMs = cfg.pollMs;
        return;
      }
      state.consecutiveErrors++;
      const next = Math.min(60000, cfg.pollMs * Math.pow(2, Math.min(3, state.consecutiveErrors))); // cap exponent
      state.backoffMs = next;
    }

    async function loadOrders(forceRender) {
      if (root.dataset.modalOpen === "1") return; // pause while modal open
      if (document.hidden) return; // pause when tab hidden
      if (state.loading) return;

      state.loading = true;
      if (state.abort) {
        try { state.abort.abort(); } catch (_) {}
      }
      state.abort = new AbortController();

      const url = new URL(cfg.activeUrl, window.location.origin);
      url.searchParams.set("limit", "100");
      url.searchParams.set("include_terminal", "1"); // we bucket on client

      const r = await fetchJson(url.toString(), { method: "GET" }, state.abort.signal);

      if (!r.json || !r.json.success) {
        setBackoff(false);
        toast(root, `Load failed (${r.status}). Retrying in ${Math.round(state.backoffMs / 1000)}s…`, "error");
        state.loading = false;
        schedulePoll(state.backoffMs);
        return;
      }

      const orders = Array.isArray(r.json.data?.orders) ? r.json.data.orders : [];
      const hash = stableHash(orders);

      state.orders = orders;

      if (forceRender || hash !== state.hash) {
        state.hash = hash;
        const buckets = splitBuckets(orders);
        setCounts(root, buckets.active.length, buckets.completed.length);
        renderList(root, "active", buckets.active);
        renderList(root, "completed", buckets.completed);
      }

      setBackoff(true);
      state.loading = false;
      schedulePoll(state.backoffMs);
    }

    // Visibility pause/resume
    document.addEventListener("visibilitychange", () => {
      if (document.hidden) {
        stopPoll();
      } else {
        schedulePoll(0);
      }
    });

    // Initialize
    setTab(root, "active");
    loadOrders(true);
  });
})();