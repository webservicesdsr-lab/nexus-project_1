/**
 * ==========================================================
 * Kingdom Nexus — Customers Script (v1.0 Cities/Hubs UX)
 * ----------------------------------------------------------
 * - Renders table (desktop) + cards (mobile)
 * - Search + Status filter + Pagination via REST list
 * - Add/Edit via modal (REST create/update)
 * - Toggle via REST toggle
 * - Toast: prefers knxToast(), then window.knx_toast(), fallback alert()
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.querySelector(".knx-customers-wrapper");
  if (!wrapper) return;

  const API = {
    list: wrapper.dataset.apiList || "",
    create: wrapper.dataset.apiCreate || "",
    update: wrapper.dataset.apiUpdate || "",
    toggle: wrapper.dataset.apiToggle || "",
    nonce: wrapper.dataset.nonce || "",
  };

  // UI
  const searchInput = document.getElementById("knxCustomersSearchInput");
  const searchBtn = document.getElementById("knxCustomersSearchBtn");
  const statusFilter = document.getElementById("knxCustomersStatusFilter");
  const addBtn = document.getElementById("knxCustomersAddBtn");

  const tbody = document.getElementById("knxCustomersTableBody");
  const cards = document.getElementById("knxCustomersCards");
  const pagination = document.getElementById("knxCustomersPagination");

  // Modal
  const modal = document.getElementById("knxCustomerModal");
  const modalTitle = document.getElementById("knxCustomerModalTitle");
  const form = document.getElementById("knxCustomerForm");
  const cancelBtn = document.getElementById("knxCustomerCancelBtn");
  const saveBtn = document.getElementById("knxCustomerSaveBtn");

  const idEl = document.getElementById("knxCustomerId");
  const nameEl = document.getElementById("knxCustomerName");
  const emailEl = document.getElementById("knxCustomerEmail");
  const phoneEl = document.getElementById("knxCustomerPhone");
  const passEl = document.getElementById("knxCustomerPassword");
  const statusEl = document.getElementById("knxCustomerStatus");

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
    modalTitle.textContent = isEdit ? "Edit Customer" : "Add Customer";
  }

  function closeModal() {
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
    resetForm();
  }

  function resetForm() {
    idEl.value = "";
    nameEl.value = "";
    emailEl.value = "";
    phoneEl.value = "";
    passEl.value = "";
    statusEl.value = "active";
  }

  function renderEmpty(message = "No customers found.") {
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;">${esc(message)}</td></tr>`;
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
        <input type="checkbox" class="knx-toggle-customer" ${checked ? "checked" : ""}>
        <span class="knx-slider"></span>
      </label>
    `;
  }

  function renderCustomers(customers) {
    if (!customers || !customers.length) {
      renderEmpty("No customers found.");
      return;
    }

    // Table rows
    const rows = customers
      .map((c) => {
        const id = c.id ?? c.user_id ?? "";
        const name = c.name ?? "";
        const email = c.email ?? "";
        const phone = c.phone ?? "";
        const status = c.status ?? "inactive";

        return `
          <tr data-id="${esc(id)}"
              data-name="${esc(name)}"
              data-email="${esc(email)}"
              data-phone="${esc(phone)}"
              data-status="${esc(status)}">
            <td>${esc(name || "—")}</td>
            <td>${esc(email || "—")}</td>
            <td>${esc(phone || "—")}</td>
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

    // Cards
    const cardsHtml = customers
      .map((c) => {
        const id = c.id ?? c.user_id ?? "";
        const name = c.name ?? "";
        const email = c.email ?? "";
        const phone = c.phone ?? "";
        const status = c.status ?? "inactive";

        return `
          <div class="knx-customer-card"
               data-id="${esc(id)}"
               data-name="${esc(name)}"
               data-email="${esc(email)}"
               data-phone="${esc(phone)}"
               data-status="${esc(status)}">
            <div class="knx-customer-card__top">
              <div class="knx-customer-card__title">${esc(name || "—")}</div>
              <div class="knx-customer-card__status knx-status-cell">${statusPill(status)}</div>
            </div>

            <div class="knx-customer-card__meta">
              <div><strong>Email:</strong> ${esc(email || "—")}</div>
              <div><strong>Phone:</strong> ${esc(phone || "—")}</div>
            </div>

            <div class="knx-customer-card__actions">
              <button type="button" class="knx-edit-link" data-action="edit">
                <i class="fas fa-pen"></i> Edit
              </button>
              <div class="knx-customer-card__toggle">
                ${toggleSwitchHtml(status === "active")}
              </div>
            </div>
          </div>
        `;
      })
      .join("");

    cards.innerHTML = cardsHtml;
  }

  async function loadCustomers(page = 1) {
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
      renderEmpty(out?.message || "Failed to load customers.");
      return;
    }

    // Flexible payload shape
    const list = out?.data?.customers || out?.customers || [];
    const pag = out?.data?.pagination || out?.pagination || null;

    if (pag) {
      currentPage = parseInt(pag.page || page, 10) || page;
      totalPages = parseInt(pag.total_pages || 1, 10) || 1;
    } else {
      totalPages = 1;
    }

    renderCustomers(list);
    renderPagination(currentPage, totalPages);
  }

  async function saveCustomer() {
    const id = (idEl.value || "").trim();
    const name = (nameEl.value || "").trim();
    const email = (emailEl.value || "").trim();
    const phone = (phoneEl.value || "").trim();
    const password = (passEl.value || "").trim();
    const status = statusEl.value || "active";

    if (!name) return toast("Name is required.", "error");
    if (!email) return toast("Email is required.", "error");
    if (!phone) return toast("Phone is required.", "error");

    const payload = {
      name,
      email,
      phone,
      status,
      knx_nonce: API.nonce,
    };

    if (password) payload.password = password;

    setSaving(true);

    const isEdit = !!id;
    if (isEdit) payload.user_id = parseInt(id, 10);

    const endpoint = isEdit ? API.update : API.create;

    const out = await fetchJson(endpoint, {
      method: "POST",
      body: JSON.stringify(payload),
    });

    setSaving(false);

    if (!out || out.success !== true) {
      toast(out?.message || "Failed to save customer.", "error");
      return;
    }

    toast(isEdit ? "Customer updated." : "Customer created.", "success");
    closeModal();
    loadCustomers(currentPage);
  }

  async function toggleCustomer(containerEl, checkboxEl) {
    const id = containerEl?.dataset?.id;
    if (!id) return;

    const desired = checkboxEl.checked ? "active" : "inactive";

    const out = await fetchJson(API.toggle, {
      method: "POST",
      body: JSON.stringify({
        user_id: parseInt(id, 10),
        status: desired, // safe even if backend ignores
        knx_nonce: API.nonce,
      }),
    });

    if (!out || out.success !== true) {
      toast(out?.message || "Toggle failed.", "error");
      checkboxEl.checked = !checkboxEl.checked;
      return;
    }

    // Update UI status pill + dataset
    containerEl.dataset.status = desired;

    const statusCell = containerEl.querySelector(".knx-status-cell");
    if (statusCell) statusCell.innerHTML = statusPill(desired);

    toast("Customer status updated.", "success");
  }

  function fillModalFromContainer(containerEl) {
    const id = containerEl.dataset.id || "";
    const name = containerEl.dataset.name || "";
    const email = containerEl.dataset.email || "";
    const phone = containerEl.dataset.phone || "";
    const status = containerEl.dataset.status || "active";

    idEl.value = id;
    nameEl.value = name;
    emailEl.value = email;
    phoneEl.value = phone;
    statusEl.value = status === "inactive" ? "inactive" : "active";
    passEl.value = "";
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
    saveCustomer();
  });

  searchBtn?.addEventListener("click", () => {
    currentPage = 1;
    loadCustomers(1);
  });

  searchInput?.addEventListener("keypress", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      currentPage = 1;
      loadCustomers(1);
    }
  });

  statusFilter?.addEventListener("change", () => {
    currentPage = 1;
    loadCustomers(1);
  });

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
    const toggle = e.target.closest(".knx-toggle-customer");
    if (!toggle) return;
    const container = toggle.closest("[data-id]"); // works for <tr> and card
    if (!container) return;
    toggleCustomer(container, toggle);
  });

  pagination?.addEventListener("click", (e) => {
    const link = e.target.closest("a[data-page]");
    if (!link) return;
    e.preventDefault();
    if (link.classList.contains("disabled")) return;

    const p = parseInt(link.dataset.page, 10);
    if (!Number.isFinite(p)) return;
    if (p < 1 || p > totalPages) return;

    loadCustomers(p);
  });

  // Boot
  loadCustomers(1);
});
