/**
 * KNX DRIVER — View Order (CANON)
 * - Uses GET /knx/v2/driver/orders/{id}
 * - Two modals only: Change Status, Release
 * - DB-canon only (no legacy mappings)
 * - No alert/prompt/confirm
 */

(function () {
  "use strict";

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

  function normalizeStatus(s) {
    return String(s || "").trim().toLowerCase();
  }

  function statusLabel(s) {
    const st = normalizeStatus(s);
    return STATUS_LABELS[st] || (st ? st.replace(/_/g, " ") : "Unknown");
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"]/g, (x) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[x]));
  }

  function parseMysqlDate(mysql) {
    if (!mysql) return null;
    const s = String(mysql).trim().replace(" ", "T");
    const d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
  }

  function relTime(mysql) {
    const d = parseMysqlDate(mysql);
    if (!d) return mysql ? String(mysql) : "";
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return `${diff}s ago`;
    const m = Math.floor(diff / 60);
    if (m < 60) return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ago`;
    return `${Math.floor(h / 24)}d ago`;
  }

  function toast(root, msg, type = "info") {
    if (window.knxToast && typeof window.knxToast === "function") {
      window.knxToast(msg, type);
      return;
    }
    const node = root.querySelector("[data-toast]");
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

  async function fetchJson(url, opts = {}) {
    const res = await fetch(url, Object.assign({ credentials: "include" }, opts));
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}
    return { ok: res.ok, status: res.status, json, text };
  }

  function openModal(root, key) {
    const m = root.querySelector(`[data-modal="${key}"]`);
    if (!m) return;
    m.classList.add("is-open");
    root.dataset.modalOpen = "1";
  }

  function closeModals(root) {
    root.querySelectorAll(".knx-vo__modal").forEach((m) => m.classList.remove("is-open"));
    root.dataset.modalOpen = "0";
  }

  document.addEventListener("DOMContentLoaded", () => {
    const root = document.querySelector('[data-knx-driver-module="view-order"]');
    if (!root) return;

    const orderId = parseInt(root.dataset.orderId || "0", 10);
    const apiBase = (root.dataset.apiBaseV2 || "/wp-json/knx/v2/driver/orders/").replace(/\/+$/, "/");
    const knxNonce = root.dataset.knxNonce || "";
    const backUrl = root.dataset.backUrl || "/driver-active-orders";

    const stateNode = root.querySelector("[data-state]");
    const contentNode = root.querySelector("[data-content]");

    const btnConfirmStatus = root.querySelector("[data-confirm-status]");
    const btnConfirmRelease = root.querySelector("[data-confirm-release]");

    let order = null;
    let chosenStatus = "";

    if (!orderId) {
      if (stateNode) stateNode.textContent = "Invalid order_id";
      return;
    }
    if (!knxNonce) {
      if (stateNode) stateNode.textContent = "Missing knx_nonce (re-login).";
      return;
    }

    function detailUrl() {
      return apiBase + String(orderId);
    }
    function opsStatusUrl() {
      return apiBase + String(orderId) + "/ops-status";
    }
    function releaseUrl() {
      return apiBase + String(orderId) + "/release";
    }

    function chipTextFor(orderRow) {
      const st = normalizeStatus(orderRow.status);
      if (st === "confirmed") {
        const t = relTime(orderRow.created_at);
        return t ? `${STATUS_LABELS.confirmed} • ${t}` : STATUS_LABELS.confirmed;
      }
      return statusLabel(st);
    }

    function chipClass(st) {
      const s = normalizeStatus(st);
      if (s === "confirmed") return "is-new";
      if (s === "completed") return "is-done";
      if (s === "cancelled") return "is-cancelled";
      return "is-progress";
    }

    function render() {
      if (!order) return;
      if (stateNode) stateNode.textContent = "";

      const st = normalizeStatus(order.status);
      const allow = TRANSITIONS[st] || [];
      const canRelease = st !== "completed" && st !== "cancelled";

      contentNode.innerHTML = `
        <div class="knx-vo__section">
          <div class="knx-vo__row"><span>Order</span><strong>#${escapeHtml(order.order_number || order.id)}</strong></div>
          <div class="knx-vo__row"><span>Status</span><strong><span class="knx-vo__chip ${chipClass(st)}">${escapeHtml(chipTextFor(order))}</span></strong></div>
          <div class="knx-vo__row"><span>Total</span><strong>${escapeHtml(order.total != null ? String(order.total) : "—")}</strong></div>
          <div class="knx-vo__row"><span>Created</span><strong>${escapeHtml(order.created_at || "—")}</strong></div>
        </div>

        <div class="knx-vo__section">
          <div class="knx-vo__row"><span>Customer</span><strong>${escapeHtml(order.customer_name || "—")}</strong></div>
          <div class="knx-vo__row"><span>Phone</span><strong>${escapeHtml(order.customer_phone || "—")}</strong></div>
          <div class="knx-vo__row"><span>Address</span><strong>${escapeHtml(order.delivery_address || "—")}</strong></div>
        </div>

        <div class="knx-vo__actions">
          ${allow.length ? `<button type="button" class="knx-vo__btn knx-vo__btn--primary" data-action="open-status">Change status</button>` : ""}
          ${canRelease ? `<button type="button" class="knx-vo__btn knx-vo__btn--danger" data-action="open-release">Release</button>` : ""}
          <button type="button" class="knx-vo__btn" data-action="back">Back</button>
        </div>
      `;
    }

    function setStatusModal() {
      const meta = root.querySelector("[data-status-meta]");
      const opts = root.querySelector("[data-status-options]");
      if (!meta || !opts || !btnConfirmStatus) return;

      const st = normalizeStatus(order.status);
      const allow = TRANSITIONS[st] || [];

      meta.innerHTML = `
        <div class="knx-vo__meta-row"><span>Order</span><strong>#${escapeHtml(order.order_number || order.id)}</strong></div>
        <div class="knx-vo__meta-row"><span>Current</span><strong>${escapeHtml(chipTextFor(order))}</strong></div>
      `;

      if (!allow.length) {
        opts.innerHTML = `<div class="knx-vo__empty">No transitions available.</div>`;
        btnConfirmStatus.disabled = true;
        return;
      }

      chosenStatus = "";
      btnConfirmStatus.disabled = true;

      opts.innerHTML = allow.map((to) => `
        <label class="knx-vo__opt">
          <input type="radio" name="knx_new_status" value="${escapeHtml(to)}" />
          <span>${escapeHtml(statusLabel(to))}</span>
        </label>
      `).join("");
    }

    function setReleaseModal() {
      const meta = root.querySelector("[data-release-meta]");
      if (!meta) return;
      meta.innerHTML = `
        <div class="knx-vo__meta-row"><span>Order</span><strong>#${escapeHtml(order.order_number || order.id)}</strong></div>
        <div class="knx-vo__meta-row"><span>Status</span><strong>${escapeHtml(chipTextFor(order))}</strong></div>
      `;
    }

    async function load() {
      if (stateNode) stateNode.textContent = "Loading…";
      const r = await fetchJson(detailUrl(), { method: "GET" });

      if (!r.json || !r.json.success) {
        const reason = r.json?.data?.reason || "load_failed";
        if (stateNode) stateNode.textContent = `Failed: ${reason}`;
        return;
      }

      order = r.json.data?.order || null;
      if (!order) {
        if (stateNode) stateNode.textContent = "Order not found.";
        return;
      }
      render();
    }

    // Click delegation (buttons)
    root.addEventListener("click", (e) => {
      const close = e.target.closest("[data-close-modal]");
      if (close) {
        closeModals(root);
        return;
      }

      const btn = e.target.closest("[data-action]");
      if (!btn) return;

      const action = btn.dataset.action;

      if (action === "open-status") {
        setStatusModal();
        openModal(root, "status");
        return;
      }

      if (action === "open-release") {
        setReleaseModal();
        openModal(root, "release");
        return;
      }

      if (action === "back") {
        window.location.href = backUrl;
        return;
      }
    });

    // Radio change
    root.addEventListener("change", (e) => {
      const input = e.target;
      if (!input || input.name !== "knx_new_status") return;
      chosenStatus = String(input.value || "").trim().toLowerCase();
      if (btnConfirmStatus) btnConfirmStatus.disabled = !chosenStatus;
    });

    // Confirm status
    if (btnConfirmStatus) {
      btnConfirmStatus.addEventListener("click", async () => {
        if (!order || !chosenStatus) return;

        const from = normalizeStatus(order.status);
        const allow = TRANSITIONS[from] || [];
        if (!allow.includes(chosenStatus)) {
          toast(root, `Invalid transition: ${from} → ${chosenStatus}`, "error");
          return;
        }

        btnConfirmStatus.disabled = true;
        toast(root, "Updating status…", "info");

        const r = await fetchJson(opsStatusUrl(), {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ knx_nonce: knxNonce, status: chosenStatus }),
        });

        if (r.json && r.json.success) {
          toast(root, `Status updated to "${statusLabel(chosenStatus)}"`, "success");
          closeModals(root);
          await load();
        } else {
          const reason = r.json?.data?.reason || "update_failed";
          const msg = r.json?.data?.message || "";
          toast(root, `Update failed (${r.status}): ${reason}${msg ? " — " + msg : ""}`, "error");
        }

        btnConfirmStatus.disabled = false;
      });
    }

    // Confirm release
    if (btnConfirmRelease) {
      btnConfirmRelease.addEventListener("click", async () => {
        if (!order) return;

        btnConfirmRelease.disabled = true;
        toast(root, "Releasing…", "info");

        const r = await fetchJson(releaseUrl(), {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ knx_nonce: knxNonce }),
        });

        if (r.json && r.json.success) {
          toast(root, "Order released ✅", "success");
          closeModals(root);
          window.location.href = backUrl;
        } else {
          const reason = r.json?.data?.reason || "release_failed";
          const msg = r.json?.data?.message || "";
          toast(root, `Release failed (${r.status}): ${reason}${msg ? " — " + msg : ""}`, "error");
        }

        btnConfirmRelease.disabled = false;
      });
    }

    load();
  });
})();