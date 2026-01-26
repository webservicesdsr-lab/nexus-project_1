/**
 * ==========================================================
 * Kingdom Nexus — Coupons Script (v1.0 Nexus Definitive CRUD)
 * ----------------------------------------------------------
 * - Renders table (desktop) + cards (mobile)
 * - Search + Status filter + Pagination via REST list
 * - Add/Edit via modal (REST create/update)
 * - Toggle via REST toggle
 * - Toast: prefers knxToast(), then window.knx_toast(), fallback alert()
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.querySelector(".knx-coupons-wrapper");
  if (!wrapper) return;

  const API = {
    list: wrapper.dataset.apiList || "",
    create: wrapper.dataset.apiCreate || "",
    update: wrapper.dataset.apiUpdate || "",
    toggle: wrapper.dataset.apiToggle || "",
    nonce: wrapper.dataset.nonce || "",
  };

  // UI
  const searchInput = document.getElementById("knxCouponsSearchInput");
  const searchBtn = document.getElementById("knxCouponsSearchBtn");
  const statusFilter = document.getElementById("knxCouponsStatusFilter");
  const addBtn = document.getElementById("knxCouponsAddBtn");

  const tbody = document.getElementById("knxCouponsTableBody");
  const cards = document.getElementById("knxCouponsCards");
  const pagination = document.getElementById("knxCouponsPagination");

  // Modal
  const modal = document.getElementById("knxCouponModal");
  const modalTitle = document.getElementById("knxCouponModalTitle");
  const form = document.getElementById("knxCouponForm");
  const cancelBtn = document.getElementById("knxCouponCancelBtn");
  const saveBtn = document.getElementById("knxCouponSaveBtn");

  const idEl = document.getElementById("knxCouponId");
  const codeEl = document.getElementById("knxCouponCode");
  const statusEl = document.getElementById("knxCouponStatus");
  const typeEl = document.getElementById("knxCouponType");
  const valueEl = document.getElementById("knxCouponValue");
  const minEl = document.getElementById("knxCouponMinSubtotal");
  const limitEl = document.getElementById("knxCouponUsageLimit");
  const startsEl = document.getElementById("knxCouponStartsAt");
  const expiresEl = document.getElementById("knxCouponExpiresAt");
  const valueHintEl = document.getElementById("knxCouponValueHint");

  let currentPage = 1;
  const perPage = 10;
  let totalPages = 1;

  function toast(msg, type = "info") {
    if (typeof window.knxToast === "function") {
      window.knxToast(msg, type);
      return;
    }
    if (typeof window.knx_toast === "function") {
      window.knx_toast(msg, type);
      return;
    }
    alert(msg);
  }

  function esc(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  async function fetchJson(url, opts = {}) {
    const res = await fetch(url, {
      credentials: "same-origin",
      ...opts,
      headers: {
        ...(opts.headers || {}),
        "Content-Type": "application/json",
      },
    });

    const text = await res.text();
    let data = null;

    try {
      data = JSON.parse(text);
    } catch (e) {
      return { success: false, message: "Invalid JSON response." };
    }

    // Some endpoints may return HTTP 200 with success:false
    if (data && data.success === false) return data;

    if (!res.ok) {
      return { success: false, message: `Request failed (HTTP ${res.status}).` };
    }

    return data;
  }

  function setSaving(isSaving) {
    if (!saveBtn) return;
    saveBtn.disabled = !!isSaving;
    saveBtn.textContent = isSaving ? "Saving..." : "Save";
  }

  function openModal(isEdit) {
    modal.classList.add("active");
    modal.setAttribute("aria-hidden", "false");
    modalTitle.textContent = isEdit ? "Edit Coupon" : "Add Coupon";
    updateValueHint();
  }

  function closeModal() {
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
    resetForm();
  }

  function resetForm() {
    idEl.value = "";
    codeEl.value = "";
    statusEl.value = "active";
    typeEl.value = "percent";
    valueEl.value = "";
    minEl.value = "";
    limitEl.value = "";
    startsEl.value = "";
    expiresEl.value = "";
    updateValueHint();
  }

  function updateValueHint() {
    if (!valueHintEl || !typeEl) return;
    valueHintEl.textContent = typeEl.value === "percent" ? "0–100 for percent" : "Dollar amount";
  }

  function toDatetimeLocal(d) {
    if (!d) return "";
    const dt = new Date(d);
    if (!isFinite(dt.getTime())) return "";
    const y = dt.getFullYear();
    const m = String(dt.getMonth() + 1).padStart(2, "0");
    const day = String(dt.getDate()).padStart(2, "0");
    const h = String(dt.getHours()).padStart(2, "0");
    const min = String(dt.getMinutes()).padStart(2, "0");
    return `${y}-${m}-${day}T${h}:${min}`;
  }

  function formatDate(d) {
    if (!d) return "—";
    const dt = new Date(d);
    if (!isFinite(dt.getTime())) return "—";
    return dt.toLocaleDateString();
  }

  function money(n) {
    if (n === null || n === undefined || n === "") return "—";
    const v = parseFloat(n);
    if (!isFinite(v)) return "—";
    return `$${v.toFixed(2)}`;
  }

  function renderValue(type, value) {
    const v = parseFloat(value);
    if (!isFinite(v)) return "—";
    if (type === "percent") return `${v}%`;
    return `$${v.toFixed(2)}`;
  }

  function renderEmpty(message = "No coupons found.") {
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;">${esc(message)}</td></tr>`;
    }
    if (cards) {
      cards.innerHTML = `<div class="knx-empty">${esc(message)}</div>`;
    }
  }

  function renderPagination(page, pages) {
    if (!pagination) return;
    if (!pages || pages <= 1) {
      pagination.innerHTML = "";
      return;
    }

    const max = 10;
    const start = Math.max(1, page - Math.floor(max / 2));
    const end = Math.min(pages, start + max - 1);

    let html = "";
    html += `<a href="#" data-page="${page - 1}" class="${page <= 1 ? "disabled" : ""}">&laquo; Prev</a>`;

    for (let i = start; i <= end; i++) {
      html += `<a href="#" data-page="${i}" class="${i === page ? "active" : ""}">${i}</a>`;
    }

    html += `<a href="#" data-page="${page + 1}" class="${page >= pages ? "disabled" : ""}">Next &raquo;</a>`;
    pagination.innerHTML = html;
  }

  function statusPill(status) {
    const s = status === "active" ? "active" : "inactive";
    const cls = s === "active" ? "status-active" : "status-inactive";
    const label = s === "active" ? "Active" : "Inactive";
    return `<span class="${cls}">${label}</span>`;
  }

  function toggleSwitchHtml(checked) {
    return `
      <label class="knx-switch">
        <input type="checkbox" class="knx-toggle-coupon" ${checked ? "checked" : ""}>
        <span class="knx-slider"></span>
      </label>
    `;
  }

  function normalizeListPayload(out) {
    // Flexible payload shape
    const list = out?.data?.coupons || out?.coupons || [];
    const pag = out?.data?.pagination || out?.pagination || null;
    return { list, pag };
  }

  function renderCoupons(coupons) {
    if (!coupons || !coupons.length) {
      renderEmpty("No coupons found.");
      return;
    }

    const rows = coupons
      .map((c) => {
        const id = c.id ?? c.coupon_id ?? "";
        const code = (c.code ?? "").toString();
        const type = (c.type ?? "percent").toString();
        const value = c.value ?? "";
        const min = c.min_subtotal ?? "";
        const starts = c.starts_at ?? "";
        const expires = c.expires_at ?? "";
        const limit = c.usage_limit ?? "";
        const used = c.used_count ?? c.times_used ?? 0;
        const status = c.status ?? "inactive";

        return `
          <tr data-id="${esc(id)}"
              data-code="${esc(code)}"
              data-type="${esc(type)}"
              data-value="${esc(value)}"
              data-min="${esc(min)}"
              data-limit="${esc(limit)}"
              data-used="${esc(used)}"
              data-starts="${esc(starts)}"
              data-expires="${esc(expires)}"
              data-status="${esc(status)}">
            <td><strong>${esc(code || "—")}</strong></td>
            <td>${esc(type)}</td>
            <td>${esc(renderValue(type, value))}</td>
            <td>${esc(min ? money(min) : "—")}</td>
            <td>${esc(formatDate(starts))}</td>
            <td>${esc(formatDate(expires))}</td>
            <td>${esc(limit === "" || limit === null ? "∞" : String(limit))}</td>
            <td>${esc(String(used))}</td>
            <td class="knx-status-cell">${statusPill(status)}</td>
            <td class="knx-col-center">
              <button type="button" class="knx-edit-link" data-action="edit" title="Edit">
                <i class="fas fa-pen"></i>
              </button>
            </td>
            <td class="knx-col-center">
              ${toggleSwitchHtml(status === "active")}
            </td>
          </tr>
        `;
      })
      .join("");

    tbody.innerHTML = rows;

    const cardsHtml = coupons
      .map((c) => {
        const id = c.id ?? c.coupon_id ?? "";
        const code = (c.code ?? "").toString();
        const type = (c.type ?? "percent").toString();
        const value = c.value ?? "";
        const min = c.min_subtotal ?? "";
        const starts = c.starts_at ?? "";
        const expires = c.expires_at ?? "";
        const limit = c.usage_limit ?? "";
        const used = c.used_count ?? c.times_used ?? 0;
        const status = c.status ?? "inactive";

        return `
          <div class="knx-coupon-card"
               data-id="${esc(id)}"
               data-code="${esc(code)}"
               data-type="${esc(type)}"
               data-value="${esc(value)}"
               data-min="${esc(min)}"
               data-limit="${esc(limit)}"
               data-used="${esc(used)}"
               data-starts="${esc(starts)}"
               data-expires="${esc(expires)}"
               data-status="${esc(status)}">

            <div class="knx-coupon-card__top">
              <div class="knx-coupon-card__title">${esc(code || "—")}</div>
              <div class="knx-coupon-card__status knx-status-cell">${statusPill(status)}</div>
            </div>

            <div class="knx-coupon-card__meta">
              <div><strong>Type:</strong> ${esc(type)}</div>
              <div><strong>Value:</strong> ${esc(renderValue(type, value))}</div>
              <div><strong>Min:</strong> ${esc(min ? money(min) : "—")}</div>
              <div><strong>Starts:</strong> ${esc(formatDate(starts))}</div>
              <div><strong>Expires:</strong> ${esc(formatDate(expires))}</div>
              <div><strong>Limit:</strong> ${esc(limit === "" || limit === null ? "∞" : String(limit))}</div>
              <div><strong>Used:</strong> ${esc(String(used))}</div>
            </div>

            <div class="knx-coupon-card__actions">
              <button type="button" class="knx-edit-link" data-action="edit">
                <i class="fas fa-pen"></i> Edit
              </button>
              <div class="knx-coupon-card__toggle">
                ${toggleSwitchHtml(status === "active")}
              </div>
            </div>
          </div>
        `;
      })
      .join("");

    cards.innerHTML = cardsHtml;
  }

  async function loadCoupons(page = 1) {
    currentPage = page;

    const q = (searchInput?.value || "").trim();
    const st = statusFilter?.value || "";

    const params = new URLSearchParams({
      page: String(page),
      per_page: String(perPage),
    });

    if (q) params.set("q", q);
    if (st) params.set("status", st);

    const url = `${API.list}?${params.toString()}`;
    const out = await fetchJson(url, { method: "GET" });

    if (!out || out.success !== true) {
      renderEmpty(out?.message || "Failed to load coupons.");
      return;
    }

    const { list, pag } = normalizeListPayload(out);

    if (pag) {
      currentPage = parseInt(pag.page || page, 10) || page;
      totalPages = parseInt(pag.total_pages || 1, 10) || 1;
    } else {
      totalPages = 1;
    }

    renderCoupons(list);
    renderPagination(currentPage, totalPages);
  }

  async function saveCoupon() {
    const id = (idEl.value || "").trim();
    const code = (codeEl.value || "").trim().toUpperCase();
    const status = statusEl.value || "active";
    const type = typeEl.value || "percent";

    const value = valueEl.value !== "" ? parseFloat(valueEl.value) : NaN;
    const min = minEl.value !== "" ? parseFloat(minEl.value) : null;
    const limit = limitEl.value !== "" ? parseInt(limitEl.value, 10) : null;

    const starts = startsEl.value ? startsEl.value : null;
    const expires = expiresEl.value ? expiresEl.value : null;

    if (!code) return toast("Code is required.", "error");
    if (!type) return toast("Type is required.", "error");
    if (!isFinite(value) || value < 0) return toast("Value must be >= 0.", "error");
    if (type === "percent" && value > 100) return toast("Percent value cannot exceed 100.", "error");

    if (starts && expires) {
      const a = new Date(starts);
      const b = new Date(expires);
      if (isFinite(a.getTime()) && isFinite(b.getTime()) && b <= a) {
        return toast("Expiration date must be after start date.", "error");
      }
    }

    const payload = {
      code,
      status,
      type,
      value,
      min_subtotal: isFinite(min) ? min : null,
      usage_limit: isFinite(limit) ? limit : null,
      starts_at: starts,
      expires_at: expires,
      knx_nonce: API.nonce,
    };

    setSaving(true);

    const isEdit = !!id;
    if (isEdit) payload.coupon_id = parseInt(id, 10);

    const endpoint = isEdit ? API.update : API.create;

    const out = await fetchJson(endpoint, {
      method: "POST",
      body: JSON.stringify(payload),
    });

    setSaving(false);

    if (!out || out.success !== true) {
      toast(out?.message || "Failed to save coupon.", "error");
      return;
    }

    toast(isEdit ? "Coupon updated." : "Coupon created.", "success");
    closeModal();
    loadCoupons(currentPage);
  }

  async function toggleCoupon(containerEl, checkboxEl) {
    const id = containerEl?.dataset?.id;
    if (!id) return;

    const desired = checkboxEl.checked ? "active" : "inactive";

    const out = await fetchJson(API.toggle, {
      method: "POST",
      body: JSON.stringify({
        coupon_id: parseInt(id, 10),
        status: desired, // safe even if backend ignores
        knx_nonce: API.nonce,
      }),
    });

    if (!out || out.success !== true) {
      toast(out?.message || "Toggle failed.", "error");
      checkboxEl.checked = !checkboxEl.checked;
      return;
    }

    containerEl.dataset.status = desired;

    const statusCell = containerEl.querySelector(".knx-status-cell");
    if (statusCell) statusCell.innerHTML = statusPill(desired);

    toast("Coupon status updated.", "success");
  }

  function fillModalFromContainer(containerEl) {
    const id = containerEl.dataset.id || "";
    const code = containerEl.dataset.code || "";
    const type = containerEl.dataset.type || "percent";
    const value = containerEl.dataset.value || "";
    const min = containerEl.dataset.min || "";
    const limit = containerEl.dataset.limit || "";
    const starts = containerEl.dataset.starts || "";
    const expires = containerEl.dataset.expires || "";
    const status = containerEl.dataset.status || "active";

    idEl.value = id;
    codeEl.value = code;
    typeEl.value = type === "fixed" ? "fixed" : "percent";
    valueEl.value = value;
    minEl.value = min;
    limitEl.value = limit;
    statusEl.value = status === "inactive" ? "inactive" : "active";
    startsEl.value = toDatetimeLocal(starts);
    expiresEl.value = toDatetimeLocal(expires);

    updateValueHint();
  }

  // Events
  addBtn?.addEventListener("click", () => {
    resetForm();
    openModal(false);
  });

  cancelBtn?.addEventListener("click", () => closeModal());

  modal?.addEventListener("click", (e) => {
    if (e.target === modal) closeModal();
  });

  form?.addEventListener("submit", (e) => {
    e.preventDefault();
    saveCoupon();
  });

  searchBtn?.addEventListener("click", () => {
    currentPage = 1;
    loadCoupons(1);
  });

  searchInput?.addEventListener("keypress", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      currentPage = 1;
      loadCoupons(1);
    }
  });

  statusFilter?.addEventListener("change", () => {
    currentPage = 1;
    loadCoupons(1);
  });

  typeEl?.addEventListener("change", updateValueHint);

  // Delegate edit + toggle for BOTH table rows and cards
  wrapper.addEventListener("click", (e) => {
    const editBtn = e.target.closest(".knx-edit-link[data-action='edit'], .knx-edit-link");
    if (editBtn) {
      const container = editBtn.closest("[data-id]");
      if (!container) return;
      fillModalFromContainer(container);
      openModal(true);
      return;
    }
  });

  wrapper.addEventListener("change", (e) => {
    const toggle = e.target.closest(".knx-toggle-coupon");
    if (!toggle) return;
    const container = toggle.closest("[data-id]"); // works for <tr> and card
    if (!container) return;
    toggleCoupon(container, toggle);
  });

  pagination?.addEventListener("click", (e) => {
    const link = e.target.closest("a[data-page]");
    if (!link) return;
    e.preventDefault();
    if (link.classList.contains("disabled")) return;

    const p = parseInt(link.dataset.page, 10);
    if (!Number.isFinite(p)) return;
    if (p < 1 || p > totalPages) return;

    loadCoupons(p);
  });

  // Boot
  loadCoupons(1);
});
