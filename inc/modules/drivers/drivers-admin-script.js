/**
 * ==========================================================
 * Kingdom Nexus — Drivers Admin Script (Responsive) v2.1
 * - Desktop: table
 * - Mobile: cards
 * - Add/Edit modal
 * - Credentials modal after create/reset
 * - Sends X-WP-Nonce to avoid "Cookie check failed"
 * ==========================================================
 */
(function () {
  "use strict";

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function esc(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function toast(msg, type) {
    try {
      if (typeof window.knxToast === "function") return window.knxToast(String(msg || ""), type || "info");
      if (window.knxToast && typeof window.knxToast.show === "function") return window.knxToast.show(String(msg || ""), type || "info");
    } catch (_) {}
    try { console.log("[KNX][DRIVERS]", msg); } catch (_) {}
  }

  async function getJson(url, wpNonce) {
    const res = await fetch(url, {
      method: "GET",
      credentials: "same-origin",
      headers: wpNonce ? { "X-WP-Nonce": wpNonce } : {}
    });
    let json = null;
    try { json = await res.json(); } catch (_) {}
    return { ok: res.ok, status: res.status, json };
  }

  async function postJson(url, payload, wpNonce) {
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: Object.assign(
        { "Content-Type": "application/json" },
        wpNonce ? { "X-WP-Nonce": wpNonce } : {}
      ),
      body: JSON.stringify(payload || {})
    });
    let json = null;
    try { json = await res.json(); } catch (_) {}
    return { ok: res.ok, status: res.status, json };
  }

  function setBusy(el, busy) {
    if (!el) return;
    if (busy) el.classList.add("is-busy");
    else el.classList.remove("is-busy");
  }

  function normalizeStatus(s) {
    s = String(s || "active").toLowerCase();
    return (s === "inactive") ? "inactive" : "active";
  }

  function renderRow(d) {
    const id = Number(d?.id || 0);
    const name = String(d?.full_name || ("Driver #" + id));
    const email = String(d?.email || "");
    const phone = String(d?.phone || "");
    const username = String(d?.user?.username || "");
    const status = normalizeStatus(d?.status);

    const tr = document.createElement("tr");
    tr.setAttribute("data-id", String(id));
    tr.innerHTML = `
      <td class="knx-cell-main">
        <div class="knx-title">${esc(name)}</div>
        <div class="knx-meta">${esc(email)}${phone ? " • " + esc(phone) : ""}</div>
        ${d?.vehicle_info ? `<div class="knx-meta knx-meta-soft">${esc(d.vehicle_info)}</div>` : ""}
      </td>

      <td>
        <div class="knx-meta"><strong>${esc(username || "—")}</strong></div>
        <div class="knx-meta knx-meta-soft">ID: ${esc(id)}</div>
      </td>

      <td>
        <span class="knx-status knx-status--${status}">
          ${status === "active" ? "Active" : "Inactive"}
        </span>
      </td>

      <td class="knx-center">
        <label class="knx-switch" title="Active/Inactive">
          <input type="checkbox" class="knx-toggle" ${status === "active" ? "checked" : ""}>
          <span class="knx-slider"></span>
        </label>
      </td>

      <td class="knx-center">
        <button type="button" class="knx-iconbtn knx-edit" title="Edit">
          <i class="fas fa-pen"></i>
        </button>
      </td>

      <td class="knx-center">
        <button type="button" class="knx-iconbtn knx-reset" title="Reset Password">
          <i class="fas fa-key"></i>
        </button>
      </td>
    `;
    return tr;
  }

  function renderCard(d) {
    const id = Number(d?.id || 0);
    const name = String(d?.full_name || ("Driver #" + id));
    const email = String(d?.email || "");
    const phone = String(d?.phone || "");
    const username = String(d?.user?.username || "");
    const status = normalizeStatus(d?.status);

    const card = document.createElement("div");
    card.className = "knx-card";
    card.setAttribute("data-id", String(id));
    card.innerHTML = `
      <div class="knx-card-top">
        <div>
          <div class="knx-card-name">${esc(name)}</div>
          <div class="knx-card-meta">${esc(email)}${phone ? " • " + esc(phone) : ""}</div>
          ${d?.vehicle_info ? `<div class="knx-card-meta knx-card-meta-soft">${esc(d.vehicle_info)}</div>` : ""}
        </div>
        <span class="knx-status knx-status--${status}">
          ${status === "active" ? "Active" : "Inactive"}
        </span>
      </div>

      <div class="knx-card-row">
        <div class="knx-card-label">Username</div>
        <div class="knx-card-value"><strong>${esc(username || "—")}</strong></div>
      </div>

      <div class="knx-card-row">
        <div class="knx-card-label">Active</div>
        <label class="knx-switch">
          <input type="checkbox" class="knx-toggle" ${status === "active" ? "checked" : ""}>
          <span class="knx-slider"></span>
        </label>
      </div>

      <div class="knx-card-actions">
        <button type="button" class="knx-btn knx-btn--ghost knx-btn--sm knx-edit">
          <i class="fas fa-pen"></i> Edit
        </button>
        <button type="button" class="knx-btn knx-btn--ghost knx-btn--sm knx-reset">
          <i class="fas fa-key"></i> Reset
        </button>
      </div>
    `;
    return card;
  }

  function openModal(modal) {
    modal.classList.add("active");
    modal.setAttribute("aria-hidden", "false");
  }
  function closeModal(modal) {
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
  }

  function bindBackdropClose(modal, closeFn) {
    if (!modal) return;
    modal.addEventListener("click", (e) => {
      const t = e.target;
      if (t && t.getAttribute && t.getAttribute("data-knx-close") === "1") closeFn();
    });
  }

  function init() {
    const root = qs(".knx-drivers-signed");
    if (!root) return;

    const apiList   = root.getAttribute("data-api-list") || "";
    const apiCreate = root.getAttribute("data-api-create") || "";
    const apiBase   = root.getAttribute("data-api-base") || "";

    const knxNonce = root.getAttribute("data-knx-nonce") || "";
    const wpNonce  = root.getAttribute("data-wp-nonce") || "";

    const tbody = qs("#knxDriversTbody", root);
    const cards = qs("#knxDriversCards", root);
    const pagination = qs("#knxDriversPagination", root);

    const search = qs("#knxDriversSearch", root);
    const statusFilter = qs("#knxDriversStatus", root);
    const addBtn = qs("#knxDriversAddBtn", root);

    const modal = qs("#knxDriverModal");
    const modalTitle = qs("#knxDriverModalTitle");
    const form = qs("#knxDriverForm");
    const cancelBtn = qs("#knxDriverCancel");
    const saveBtn = qs("#knxDriverSave");

    const credsModal = qs("#knxDriverCredsModal");
    const credsClose = qs("#knxDriverCredsClose");
    const credsUser = qs("#knxCredsUsername");
    const credsPass = qs("#knxCredsPassword");

    if (!apiList || !apiCreate || !apiBase || !tbody || !cards || !pagination) return;

    let page = 1;
    const per_page = 20;
    let total_pages = 0;
    let drivers = [];

    function setCreds(username, password) {
      if (credsUser) credsUser.textContent = username || "—";
      if (credsPass) credsPass.textContent = password || "—";
      if (credsModal) openModal(credsModal);
    }

    function openAdd() {
      if (!modal || !form) return;
      if (modalTitle) modalTitle.textContent = "Add Driver";
      form.reset();
      qs('input[name="id"]', form).value = "";
      openModal(modal);
      setTimeout(() => { try { qs('input[name="full_name"]', form).focus(); } catch (_) {} }, 50);
    }

    function openEdit(driver) {
      if (!modal || !form) return;
      if (modalTitle) modalTitle.textContent = "Edit Driver";
      qs('input[name="id"]', form).value = String(driver.id || "");
      qs('input[name="full_name"]', form).value = String(driver.full_name || "");
      qs('input[name="email"]', form).value = String(driver.email || "");
      qs('input[name="phone"]', form).value = String(driver.phone || "");
      qs('input[name="vehicle_info"]', form).value = String(driver.vehicle_info || "");
      qs('select[name="status"]', form).value = normalizeStatus(driver.status);
      openModal(modal);
    }

    function closeDriverModal() {
      if (!modal || !form) return;
      closeModal(modal);
      form.reset();
      qs('input[name="id"]', form).value = "";
      if (saveBtn) saveBtn.disabled = false;
    }

    function closeCredsModal() {
      if (!credsModal) return;
      closeModal(credsModal);
    }

    bindBackdropClose(modal, closeDriverModal);
    bindBackdropClose(credsModal, closeCredsModal);

    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      if (modal && modal.classList.contains("active")) closeDriverModal();
      if (credsModal && credsModal.classList.contains("active")) closeCredsModal();
    });

    function render() {
      // Table
      tbody.innerHTML = "";
      if (!drivers.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="knx-center">No drivers found.</td></tr>`;
      } else {
        drivers.forEach(d => tbody.appendChild(renderRow(d)));
      }

      // Cards
      cards.innerHTML = "";
      if (!drivers.length) {
        cards.innerHTML = `
          <div class="knx-empty-state">
            <i class="fas fa-user"></i>
            <p>No drivers found.</p>
            <button type="button" class="knx-btn knx-btn--primary" id="knxDriversEmptyAdd">
              <i class="fas fa-plus"></i> Add Driver
            </button>
          </div>
        `;
        const emptyAdd = qs("#knxDriversEmptyAdd", cards);
        if (emptyAdd) emptyAdd.addEventListener("click", openAdd);
      } else {
        drivers.forEach(d => cards.appendChild(renderCard(d)));
      }

      // Pagination
      pagination.innerHTML = "";
      if (total_pages > 1) {
        const prevDisabled = page <= 1 ? "disabled" : "";
        const nextDisabled = page >= total_pages ? "disabled" : "";
        pagination.innerHTML = `
          <button type="button" class="knx-btn knx-btn--ghost knx-btn--sm" id="knxPgPrev" ${prevDisabled}>
            <i class="fas fa-chevron-left"></i> Prev
          </button>
          <div class="knx-pg-label">Page <strong>${page}</strong> of <strong>${total_pages}</strong></div>
          <button type="button" class="knx-btn knx-btn--ghost knx-btn--sm" id="knxPgNext" ${nextDisabled}>
            Next <i class="fas fa-chevron-right"></i>
          </button>
        `;
        const prev = qs("#knxPgPrev", pagination);
        const next = qs("#knxPgNext", pagination);
        if (prev) prev.addEventListener("click", () => { if (page > 1) { page--; load(); } });
        if (next) next.addEventListener("click", () => { if (page < total_pages) { page++; load(); } });
      }
    }

    async function load() {
      setBusy(root, true);

      const q = String(search?.value || "").trim();
      const st = String(statusFilter?.value || "").trim();

      const url = new URL(apiList, location.origin);
      url.searchParams.set("page", String(page));
      url.searchParams.set("per_page", String(per_page));
      if (q) url.searchParams.set("q", q);
      if (st) url.searchParams.set("status", st);

      const { ok, json } = await getJson(url.toString(), wpNonce);

      setBusy(root, false);

      if (!ok || !json || json.success === false) {
        toast((json && (json.message || json.error)) || "Failed to load drivers", "error");
        tbody.innerHTML = `<tr><td colspan="6"><div class="knx-error-state"><i class="fas fa-exclamation-triangle"></i><p>Unable to load drivers.</p></div></td></tr>`;
        cards.innerHTML = `<div class="knx-error-state"><i class="fas fa-exclamation-triangle"></i><p>Unable to load drivers.</p></div>`;
        return;
      }

      const list = json?.data?.drivers || [];
      const pg = json?.data?.pagination || {};
      drivers = Array.isArray(list) ? list : [];
      total_pages = Number(pg?.total_pages || 0);

      render();
    }

    // Delegated actions (table + cards)
    root.addEventListener("click", async (e) => {
      const t = e.target;
      if (!t) return;

      const hostRow = t.closest("tr[data-id]");
      const hostCard = t.closest(".knx-card[data-id]");
      const host = hostRow || hostCard;
      if (!host) return;

      const id = Number(host.getAttribute("data-id") || 0);
      const driver = drivers.find(d => Number(d?.id) === id);
      if (!driver) return;

      // Edit
      if (t.closest(".knx-edit")) {
        openEdit(driver);
        return;
      }

      // Reset password
      if (t.closest(".knx-reset")) {
        if (!confirm("Reset this driver's password?")) return;

        setBusy(host, true);

        const { ok, json } = await postJson(`${apiBase}/${id}/reset-password`, { knx_nonce: knxNonce }, wpNonce);

        setBusy(host, false);

        if (!ok || !json || json.success === false) {
          toast((json && (json.message || json.error)) || "Reset failed", "error");
          return;
        }

        const creds = json?.data || {};
        toast("Password reset.", "success");
        setCreds(String(creds.username || driver.user?.username || ""), String(creds.temp_password || ""));
        return;
      }
    });

    // Delegated toggle
    root.addEventListener("change", async (e) => {
      const t = e.target;
      if (!t || !t.classList || !t.classList.contains("knx-toggle")) return;

      const hostRow = t.closest("tr[data-id]");
      const hostCard = t.closest(".knx-card[data-id]");
      const host = hostRow || hostCard;
      if (!host) return;

      const id = Number(host.getAttribute("data-id") || 0);
      const desired = t.checked ? "active" : "inactive";

      setBusy(host, true);

      const { ok, json } = await postJson(`${apiBase}/${id}/toggle`, { knx_nonce: knxNonce }, wpNonce);

      setBusy(host, false);

      if (!ok || !json || json.success === false) {
        t.checked = !t.checked;
        toast((json && (json.message || json.error)) || "Toggle failed", "error");
        return;
      }

      toast("Status updated.", "success");
      await load();
    });

    // Open add
    if (addBtn) addBtn.addEventListener("click", openAdd);

    // Search + filter
    if (search) search.addEventListener("input", () => { page = 1; load(); });
    if (statusFilter) statusFilter.addEventListener("change", () => { page = 1; load(); });

    // Cancel modal
    if (cancelBtn) cancelBtn.addEventListener("click", closeDriverModal);

    // Creds close
    if (credsClose) credsClose.addEventListener("click", closeCredsModal);

    // Submit add/edit
    if (form) {
      form.addEventListener("submit", async (e) => {
        e.preventDefault();

        const id = Number(qs('input[name="id"]', form)?.value || 0);
        const full_name = String(qs('input[name="full_name"]', form)?.value || "").trim();
        const email = String(qs('input[name="email"]', form)?.value || "").trim();
        const phone = String(qs('input[name="phone"]', form)?.value || "").trim();
        const vehicle_info = String(qs('input[name="vehicle_info"]', form)?.value || "").trim();
        const status = String(qs('select[name="status"]', form)?.value || "active").trim();

        if (!full_name || !email) {
          toast("Full name and email are required.", "warning");
          return;
        }

        if (saveBtn) saveBtn.disabled = true;

        // EDIT
        if (id > 0) {
          const { ok, json } = await postJson(`${apiBase}/${id}/update`, {
            knx_nonce: knxNonce,
            full_name,
            email,
            phone,
            vehicle_info,
            status
          }, wpNonce);

          if (!ok || !json || json.success === false) {
            toast((json && (json.message || json.error)) || "Update failed", "error");
            if (saveBtn) saveBtn.disabled = false;
            return;
          }

          toast("Driver updated.", "success");
          closeDriverModal();
          await load();
          return;
        }

        // CREATE
        const { ok, json } = await postJson(apiCreate, {
          knx_nonce: knxNonce,
          full_name,
          email,
          phone,
          vehicle_info,
          status
        }, wpNonce);

        if (!ok || !json || json.success === false) {
          toast((json && (json.message || json.error)) || "Create failed", "error");
          if (saveBtn) saveBtn.disabled = false;
          return;
        }

        toast("Driver created.", "success");
        closeDriverModal();

        const creds = json?.data || {};
        setCreds(String(creds.username || ""), String(creds.temp_password || ""));

        page = 1;
        await load();
      });
    }

    // Start
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
