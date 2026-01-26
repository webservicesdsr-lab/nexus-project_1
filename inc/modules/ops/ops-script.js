/* global KNX_OPS_CONFIG */

(function () {
  "use strict";

  const cfg = window.KNX_OPS_CONFIG || null;
  if (!cfg || !cfg.endpoints) return;

  const elRoot = document.getElementById("knxOpsRoot");
  if (!elRoot) return;

  const elTbody = document.getElementById("knxOpsTbody");
  const elCards = document.getElementById("knxOpsCards");
  const elStatus = document.getElementById("knxOpsStatus");
  const elOpsStatus = document.getElementById("knxOpsOpsStatus");
  const elSearch = document.getElementById("knxOpsSearch");
  const elPrev = document.getElementById("knxOpsPrev");
  const elNext = document.getElementById("knxOpsNext");
  const elPageLabel = document.getElementById("knxOpsPageLabel");
  const elMeta = document.getElementById("knxOpsMeta");
  const elRefresh = document.getElementById("knxOpsRefreshBtn");
  const elAuto = document.getElementById("knxOpsAutoBtn");
  const elToast = document.getElementById("knxOpsToast");

  const state = {
    page: 1,
    perPage: (cfg.ui && cfg.ui.perPage) ? Number(cfg.ui.perPage) : 20,
    auto: true,
    pollMs: (cfg.ui && cfg.ui.pollMs) ? Number(cfg.ui.pollMs) : 8000,
    timer: null,
    drivers: [],
    loading: false,
    lastTotal: 0,
  };

  function toast(msg, type) {
    if (!elToast) return;
    const div = document.createElement("div");
    div.className = "knx-toastItem " + (type ? ("knx-toast-" + type) : "");
    div.textContent = msg;
    elToast.appendChild(div);
    setTimeout(() => {
      div.classList.add("is-gone");
      setTimeout(() => div.remove(), 220);
    }, 2400);
  }

  async function fetchJson(url, options) {
    const opt = options || {};
    opt.headers = opt.headers || {};
    opt.headers["Accept"] = "application/json";
    if (opt.body && !opt.headers["Content-Type"]) {
      opt.headers["Content-Type"] = "application/json";
    }

    const res = await fetch(url, opt);
    const text = await res.text();

    let json = null;
    try {
      json = JSON.parse(text);
    } catch (e) {
      // Non-json responses happen on PHP warnings or auth redirects.
      throw new Error("Non-JSON response (HTTP " + res.status + ")");
    }

    if (!res.ok) {
      const msg = (json && json.message) ? json.message : ("HTTP " + res.status);
      const err = new Error(msg);
      err.status = res.status;
      err.payload = json;
      throw err;
    }

    return json;
  }

  function buildUrl(base, params) {
    const u = new URL(base, window.location.origin);
    Object.keys(params || {}).forEach((k) => {
      const v = params[k];
      if (v === null || v === undefined || v === "") return;
      u.searchParams.set(k, String(v));
    });
    return u.toString();
  }

  function formatMoney(v) {
    if (v === null || v === undefined || v === "") return "";
    const n = Number(v);
    if (Number.isNaN(n)) return String(v);
    return "$" + n.toFixed(2);
  }

  function esc(str) {
    return String(str || "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function getFilter() {
    const qRaw = (elSearch && elSearch.value) ? elSearch.value.trim() : "";
    const q = (/^\d+$/.test(qRaw)) ? qRaw : "";
    return {
      page: state.page,
      per_page: state.perPage,
      status: elStatus ? elStatus.value : "",
      ops_status: elOpsStatus ? elOpsStatus.value : "",
      q,
    };
  }

  function setLoading(isLoading) {
    state.loading = isLoading;
    elRoot.classList.toggle("is-loading", !!isLoading);
  }

  function driverOptionsHtml(selectedId) {
    const sel = Number(selectedId || 0);
    const list = state.drivers || [];
    let out = '<option value="0">Unassigned</option>';

    for (const d of list) {
      const id = Number(d.internal_id || d.id || 0);
      if (!id) continue;

      // Only show "on" if availability exists. If not provided, show anyway.
      const av = (d.availability_status || "").toLowerCase();
      const allow = (av === "" || av === "on");
      if (!allow) continue;

      const label = esc(d.full_name || ("Driver " + id));
      const selected = (id === sel) ? " selected" : "";
      out += `<option value="${id}"${selected}>${label}</option>`;
    }
    return out;
  }

  function rowHtml(o) {
    const id = Number(o.id || 0);
    const status = esc(o.status || "");
    const opsStatus = esc(o.ops_status || "unassigned");
    const hubId = Number(o.hub_id || 0);
    const total = formatMoney(o.total);
    const driverName = esc(o.driver_name || "");
    const updated = esc(o.updated_at || "");

    const driverId = Number(o.driver_id || 0);
    const select = `<select class="knx-ops-driverSel" data-order="${id}">${driverOptionsHtml(driverId)}</select>`;

    const assignBtn = `<button type="button" class="knx-btn knx-btn-mini" data-action="assign" data-order="${id}">Assign</button>`;
    const unassignBtn = `<button type="button" class="knx-btn knx-btn-mini knx-btn-ghost" data-action="unassign" data-order="${id}">Unassign</button>`;
    const cancelBtn = `<button type="button" class="knx-btn knx-btn-mini knx-btn-danger" data-action="cancel" data-order="${id}">Cancel</button>`;

    const actions = `<div class="knx-ops-actionsCell">${select}${assignBtn}${unassignBtn}${cancelBtn}</div>`;

    return `
      <tr>
        <td class="knx-ops-ord">#${id}</td>
        <td><span class="knx-pill">${status}</span></td>
        <td><span class="knx-pill knx-pill-ops">${opsStatus}</span></td>
        <td>${hubId ? ("Hub " + hubId) : ""}</td>
        <td class="knx-ops-money">${esc(total)}</td>
        <td>${driverName}</td>
        <td class="knx-ops-date">${updated}</td>
        <td class="knx-ops-col-actions">${actions}</td>
      </tr>
    `;
  }

  function cardHtml(o) {
    const id = Number(o.id || 0);
    const status = esc(o.status || "");
    const opsStatus = esc(o.ops_status || "unassigned");
    const hubId = Number(o.hub_id || 0);
    const total = formatMoney(o.total);
    const driverName = esc(o.driver_name || "");
    const updated = esc(o.updated_at || "");
    const driverId = Number(o.driver_id || 0);

    const select = `<select class="knx-ops-driverSel" data-order="${id}">${driverOptionsHtml(driverId)}</select>`;

    return `
      <div class="knx-ops-card">
        <div class="knx-ops-cardTop">
          <div class="knx-ops-cardTitle">Order #${id}</div>
          <div class="knx-ops-cardMeta">${hubId ? ("Hub " + hubId) : ""}</div>
        </div>

        <div class="knx-ops-cardBadges">
          <span class="knx-pill">${status}</span>
          <span class="knx-pill knx-pill-ops">${opsStatus}</span>
          ${total ? `<span class="knx-pill knx-pill-money">${esc(total)}</span>` : ""}
        </div>

        <div class="knx-ops-cardLine">
          <div class="knx-ops-cardLabel">Driver</div>
          <div class="knx-ops-cardVal">${driverName || "—"}</div>
        </div>

        <div class="knx-ops-cardLine">
          <div class="knx-ops-cardLabel">Updated</div>
          <div class="knx-ops-cardVal">${updated || "—"}</div>
        </div>

        <div class="knx-ops-cardActions">
          ${select}
          <button type="button" class="knx-btn knx-btn-mini" data-action="assign" data-order="${id}">Assign</button>
          <button type="button" class="knx-btn knx-btn-mini knx-btn-ghost" data-action="unassign" data-order="${id}">Unassign</button>
          <button type="button" class="knx-btn knx-btn-mini knx-btn-danger" data-action="cancel" data-order="${id}">Cancel</button>
        </div>
      </div>
    `;
  }

  function renderOrders(list, pagination) {
    const orders = Array.isArray(list) ? list : [];

    if (!elTbody || !elCards) return;

    if (!orders.length) {
      elTbody.innerHTML = `<tr><td colspan="8" class="knx-ops-emptyCell">No orders found.</td></tr>`;
      elCards.innerHTML = `<div class="knx-ops-emptyCard">No orders found.</div>`;
    } else {
      elTbody.innerHTML = orders.map(rowHtml).join("");
      elCards.innerHTML = orders.map(cardHtml).join("");
    }

    const total = pagination && typeof pagination.total === "number" ? pagination.total : 0;
    const totalPages = pagination && typeof pagination.total_pages === "number" ? pagination.total_pages : 0;

    state.lastTotal = total;

    if (elPageLabel) elPageLabel.textContent = "Page " + state.page + (totalPages ? (" / " + totalPages) : "");
    if (elMeta) elMeta.textContent = total ? (total + " orders") : "";

    if (elPrev) elPrev.disabled = state.page <= 1;
    if (elNext) elNext.disabled = totalPages ? state.page >= totalPages : (orders.length < state.perPage);
  }

  async function loadDrivers() {
    try {
      const json = await fetchJson(cfg.endpoints.drivers, { method: "GET" });
      const drivers = json && json.data && Array.isArray(json.data.drivers) ? json.data.drivers : [];
      state.drivers = drivers;
    } catch (e) {
      state.drivers = [];
      toast("Drivers unavailable: " + e.message, "warn");
    }
  }

  async function loadOrders() {
    if (state.loading) return;
    setLoading(true);

    try {
      const params = getFilter();
      const url = buildUrl(cfg.endpoints.ordersList, params);

      const json = await fetchJson(url, { method: "GET" });
      const data = json && json.data ? json.data : {};
      renderOrders(data.orders || [], data.pagination || {});
    } catch (e) {
      if (elTbody) elTbody.innerHTML = `<tr><td colspan="8" class="knx-ops-emptyCell">Error: ${esc(e.message)}</td></tr>`;
      if (elCards) elCards.innerHTML = `<div class="knx-ops-emptyCard">Error: ${esc(e.message)}</div>`;
      toast("Load failed: " + e.message, "error");
    } finally {
      setLoading(false);
    }
  }

  async function postAction(action, orderId, extra) {
    const id = Number(orderId || 0);
    if (!id) return;

    let endpoint = "";
    let nonce = "";

    if (action === "assign") {
      endpoint = cfg.endpoints.assign;
      nonce = cfg.nonces.assign;
    } else if (action === "unassign") {
      endpoint = cfg.endpoints.unassign;
      nonce = cfg.nonces.unassign;
    } else if (action === "cancel") {
      endpoint = cfg.endpoints.cancel;
      nonce = cfg.nonces.cancel;
    } else if (action === "forceStatus") {
      endpoint = cfg.endpoints.forceStatus;
      nonce = cfg.nonces.forceStatus;
    } else {
      return;
    }

    const body = Object.assign({}, extra || {}, { order_id: id, knx_nonce: nonce });

    try {
      await fetchJson(endpoint, {
        method: "POST",
        body: JSON.stringify(body),
      });

      toast("Saved.", "ok");
      await loadOrders();
    } catch (e) {
      toast("Action failed: " + e.message, "error");
    }
  }

  function getSelectedDriverId(orderId) {
    const sel = elRoot.querySelector(`.knx-ops-driverSel[data-order="${orderId}"]`);
    if (!sel) return 0;
    const v = Number(sel.value || 0);
    return Number.isNaN(v) ? 0 : v;
  }

  function wireEvents() {
    if (elRefresh) {
      elRefresh.addEventListener("click", async () => {
        await loadDrivers();
        await loadOrders();
      });
    }

    if (elAuto) {
      elAuto.addEventListener("click", () => {
        state.auto = !state.auto;
        elAuto.dataset.state = state.auto ? "on" : "off";
        elAuto.innerHTML = state.auto
          ? '<i class="fa-solid fa-bolt"></i> Live: ON'
          : '<i class="fa-regular fa-circle-pause"></i> Live: OFF';
        if (state.auto) startPolling();
        else stopPolling();
      });
    }

    const onFilter = async () => {
      state.page = 1;
      await loadDrivers();
      await loadOrders();
    };

    if (elStatus) elStatus.addEventListener("change", onFilter);
    if (elOpsStatus) elOpsStatus.addEventListener("change", onFilter);

    if (elSearch) {
      let t = null;
      elSearch.addEventListener("input", () => {
        clearTimeout(t);
        t = setTimeout(() => onFilter(), 300);
      });
    }

    if (elPrev) {
      elPrev.addEventListener("click", async () => {
        if (state.page <= 1) return;
        state.page -= 1;
        await loadOrders();
      });
    }

    if (elNext) {
      elNext.addEventListener("click", async () => {
        state.page += 1;
        await loadOrders();
      });
    }

    elRoot.addEventListener("click", async (ev) => {
      const btn = ev.target && ev.target.closest ? ev.target.closest("button[data-action]") : null;
      if (!btn) return;

      const action = btn.getAttribute("data-action");
      const order = Number(btn.getAttribute("data-order") || 0);
      if (!order) return;

      if (action === "assign") {
        const driverId = getSelectedDriverId(order);
        await postAction("assign", order, { driver_id: driverId });
        return;
      }

      if (action === "unassign") {
        await postAction("unassign", order, {});
        return;
      }

      if (action === "cancel") {
        const ok = window.confirm("Cancel this order?");
        if (!ok) return;
        await postAction("cancel", order, {});
      }
    });
  }

  function startPolling() {
    stopPolling();
    state.timer = setInterval(async () => {
      if (!state.auto) return;
      if (document.hidden) return;
      await loadOrders();
    }, state.pollMs);
  }

  function stopPolling() {
    if (state.timer) clearInterval(state.timer);
    state.timer = null;
  }

  async function init() {
    wireEvents();
    await loadDrivers();
    await loadOrders();
    startPolling();
  }

  init().catch(() => {
    // Silent init failure
  });
})();
