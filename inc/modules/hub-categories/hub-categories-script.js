// File: inc/modules/hub-categories/hub-categories-script.js
/**
 * ==========================================================
 * Kingdom Nexus — Hub Categories Script (CRUD Responsive) v1.1
 * - Desktop: table
 * - Mobile: cards
 * - Unified Add/Edit modal
 * - Simple hard delete modal (no countdown)
 * - Uses optional global toast: window.knxToast(message, type)
 * ==========================================================
 */
(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

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
    try { console.log("[KNX]", msg); } catch (_) {}
  }

  function esc(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
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

  async function getJson(url) {
    const res = await fetch(url, { method: "GET", credentials: "same-origin" });
    let json = null;
    try { json = await res.json(); } catch (_) {}
    return { ok: res.ok, status: res.status, json };
  }

  function normalizeStatus(cat) {
    const s = String(cat?.status ?? "active").toLowerCase();
    return (s === "inactive") ? "inactive" : "active";
  }

  function removeFromUI(id) {
    const key = String(id);

    const row = document.querySelector(`tr[data-cat-id="${key}"]`);
    if (row) row.remove();

    const card = document.querySelector(`.knx-citycard[data-cat-id="${key}"]`);
    if (card) card.remove();
  }

  function setBusy(el, busy) {
    if (!el) return;
    if (busy) el.classList.add("is-busy");
    else el.classList.remove("is-busy");
  }

  function renderRow(cat) {
    const id = Number(cat?.id || 0);
    const name = String(cat?.name || ("Category #" + id));
    const status = normalizeStatus(cat);

    const tr = document.createElement("tr");
    tr.setAttribute("data-cat-id", String(id));

    tr.innerHTML = `
      <td class="knx-citycell">
        <div class="knx-cityname">${esc(name)}</div>
        <div class="knx-citymeta">ID: ${esc(id)}</div>
      </td>

      <td>
        <span class="knx-status knx-status--${status}">
          ${status === "active" ? "Active" : "Inactive"}
        </span>
      </td>

      <td>
        <label class="knx-switch" title="Active/Inactive">
          <input type="checkbox" class="knx-cat-toggle" ${status === "active" ? "checked" : ""}>
          <span class="knx-slider"></span>
        </label>
      </td>

      <td class="knx-center">
        <button type="button" class="knx-iconbtn knx-cat-edit" title="Edit Category">
          <i class="fas fa-pen"></i>
        </button>
      </td>

      <td class="knx-center">
        <button type="button" class="knx-iconbtn knx-iconbtn--danger knx-cat-delete" title="Delete Category">
          <i class="fas fa-trash"></i>
        </button>
      </td>
    `;

    return tr;
  }

  function renderCard(cat) {
    const id = Number(cat?.id || 0);
    const name = String(cat?.name || ("Category #" + id));
    const status = normalizeStatus(cat);

    const card = document.createElement("div");
    card.className = "knx-citycard";
    card.setAttribute("data-cat-id", String(id));

    card.innerHTML = `
      <div class="knx-citycard__top">
        <div>
          <div class="knx-citycard__name">${esc(name)}</div>
          <div class="knx-citycard__meta">ID: ${esc(id)}</div>
        </div>
        <span class="knx-status knx-status--${status}">
          ${status === "active" ? "Active" : "Inactive"}
        </span>
      </div>

      <div class="knx-citycard__row">
        <div class="knx-citycard__label">Active</div>
        <label class="knx-switch">
          <input type="checkbox" class="knx-cat-toggle" ${status === "active" ? "checked" : ""}>
          <span class="knx-slider"></span>
        </label>
      </div>

      <div class="knx-citycard__actions">
        <button type="button" class="knx-btn knx-btn--ghost knx-btn--sm knx-cat-edit">
          <i class="fas fa-pen"></i> Edit
        </button>
        <button type="button" class="knx-btn knx-btn--danger knx-btn--sm knx-cat-delete">
          <i class="fas fa-trash"></i> Delete
        </button>
      </div>
    `;

    return card;
  }

  function init() {
    const root = qs(".knx-hubcats-signed");
    if (!root) return;

    const apiGet    = root.getAttribute("data-api-get") || "";
    const apiAdd    = root.getAttribute("data-api-add") || "";
    const apiToggle = root.getAttribute("data-api-toggle") || "";
    const apiUpdate = root.getAttribute("data-api-update") || "";
    const apiDelete = root.getAttribute("data-api-delete") || "";

    const nonceAdd    = root.getAttribute("data-nonce-add") || "";
    const nonceToggle = root.getAttribute("data-nonce-toggle") || "";
    const nonceUpdate = root.getAttribute("data-nonce-update") || "";
    const nonceDelete = root.getAttribute("data-nonce-delete") || "";

    const tbody  = qs("#knxHubCatsTbody", root);
    const cards  = qs("#knxHubCatsCards", root);
    const search = qs("#knxHubCatsSearch", root);
    const addBtn = qs("#knxAddHubCategoryBtn", root);

    // Unified modal (add/edit)
    const modal      = qs("#knxHubCatModal");
    const modalTitle = qs("#knxHubCatModalTitle");
    const form       = qs("#knxHubCatForm");
    const cancelBtn  = qs("#knxHubCatCancel");
    const saveBtn    = qs("#knxHubCatSave");

    // Delete modal
    const delModal    = qs("#knxHubCatDeleteModal");
    const delNameEl   = qs("#knxHubCatDeleteName");
    const delCancel   = qs("#knxHubCatDeleteCancel");
    const delConfirm  = qs("#knxHubCatDeleteConfirm");

    if (!apiGet || !tbody || !cards) return;

    let categories = [];
    let pendingDelete = { id: 0, name: "" };

    function openModal(mode, cat) {
      if (!modal || !form) return;

      const idInput = qs('input[name="id"]', form);
      const nameInput = qs('input[name="name"]', form);

      if (mode === "edit") {
        if (modalTitle) modalTitle.textContent = "Edit Category";
        if (idInput) idInput.value = String(cat?.id || "");
        if (nameInput) nameInput.value = String(cat?.name || "");
      } else {
        if (modalTitle) modalTitle.textContent = "Add Category";
        if (idInput) idInput.value = "";
        if (nameInput) nameInput.value = "";
      }

      modal.classList.add("active");
      modal.setAttribute("aria-hidden", "false");
      setTimeout(() => { try { nameInput && nameInput.focus(); } catch (_) {} }, 50);
    }

    function closeModal() {
      if (!modal || !form) return;
      modal.classList.remove("active");
      modal.setAttribute("aria-hidden", "true");
      const idInput = qs('input[name="id"]', form);
      const nameInput = qs('input[name="name"]', form);
      if (idInput) idInput.value = "";
      if (nameInput) nameInput.value = "";
      if (saveBtn) saveBtn.disabled = false;
    }

    function openDeleteModal(cat) {
      if (!delModal) return;
      pendingDelete.id = Number(cat?.id || 0);
      pendingDelete.name = String(cat?.name || "");

      if (delNameEl) delNameEl.textContent = pendingDelete.name || "this category";

      delModal.classList.add("active");
      delModal.setAttribute("aria-hidden", "false");
      delModal.style.display = "block";
      if (delConfirm) delConfirm.disabled = false;
    }

    function closeDeleteModal() {
      if (!delModal) return;
      delModal.classList.remove("active");
      delModal.setAttribute("aria-hidden", "true");
      delModal.style.display = "";
      pendingDelete = { id: 0, name: "" };
      if (delConfirm) delConfirm.disabled = false;
    }

    function bindBackdropClose(modalEl, closeFn) {
      if (!modalEl) return;
      modalEl.addEventListener("click", (e) => {
        const t = e.target;
        if (t && t.getAttribute && t.getAttribute("data-knx-close") === "1") {
          closeFn();
        }
      });
    }

    bindBackdropClose(modal, closeModal);
    bindBackdropClose(delModal, closeDeleteModal);

    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      if (modal && modal.classList.contains("active")) closeModal();
      if (delModal && delModal.classList.contains("active")) closeDeleteModal();
    });

    async function load() {
      const { ok, json } = await getJson(apiGet);
      if (!ok || !json || json.success === false) {
        toast((json && (json.message || json.error)) || "Failed to load categories", "error");
        tbody.innerHTML = `
          <tr><td colspan="5">
            <div class="knx-error-state">
              <i class="fas fa-exclamation-triangle"></i>
              <p>Unable to load categories.</p>
            </div>
          </td></tr>
        `;
        cards.innerHTML = `
          <div class="knx-error-state">
            <i class="fas fa-exclamation-triangle"></i>
            <p>Unable to load categories.</p>
          </div>
        `;
        return;
      }

      categories = Array.isArray(json?.categories) ? json.categories : [];
      render();
    }

    function render() {
      const f = String(search?.value || "").trim().toLowerCase();
      const list = !f
        ? categories
        : categories.filter(c => String(c?.name || "").toLowerCase().includes(f));

      // Table
      tbody.innerHTML = "";
      if (!list.length) {
        tbody.innerHTML = `<tr><td colspan="5" class="knx-center">No categories found.</td></tr>`;
      } else {
        list.forEach(cat => tbody.appendChild(renderRow(cat)));
      }

      // Cards
      cards.innerHTML = "";
      if (!list.length) {
        cards.innerHTML = `
          <div class="knx-empty-state">
            <i class="fas fa-tags"></i>
            <p>No categories found.</p>
            <button type="button" class="knx-btn knx-btn--primary" id="knxHubCatsEmptyAdd">
              <i class="fas fa-plus"></i> Add Category
            </button>
          </div>
        `;
        const emptyAdd = qs("#knxHubCatsEmptyAdd", cards);
        if (emptyAdd) emptyAdd.addEventListener("click", () => openModal("add"));
      } else {
        list.forEach(cat => cards.appendChild(renderCard(cat)));
      }
    }

    // Search
    if (search) search.addEventListener("input", render);

    // Open add modal
    if (addBtn) addBtn.addEventListener("click", () => openModal("add"));

    // Cancel add/edit modal
    if (cancelBtn) cancelBtn.addEventListener("click", closeModal);

    // Submit add/edit
    if (form) {
      form.addEventListener("submit", async (e) => {
        e.preventDefault();

        const idInput = qs('input[name="id"]', form);
        const nameInput = qs('input[name="name"]', form);

        const id = Number(idInput?.value || 0);
        const name = String(nameInput?.value || "").trim();

        if (!name) {
          toast("Category name is required.", "warning");
          return;
        }

        if (saveBtn) saveBtn.disabled = true;

        // EDIT
        if (id > 0) {
          if (!apiUpdate) {
            toast("Update API not configured.", "error");
            if (saveBtn) saveBtn.disabled = false;
            return;
          }

          const { ok, json } = await postJson(apiUpdate, {
            id,
            name,
            knx_nonce: nonceUpdate
          });

          if (!ok || !json || json.success === false) {
            toast((json && (json.message || json.error)) || "Update failed", "error");
            if (saveBtn) saveBtn.disabled = false;
            return;
          }

          toast("Category updated.", "success");

          // Update local list
          categories = categories.map(c => (Number(c?.id) === id ? { ...c, name } : c));
          closeModal();
          render();
          return;
        }

        // ADD
        if (!apiAdd) {
          toast("Add API not configured.", "error");
          if (saveBtn) saveBtn.disabled = false;
          return;
        }

        const { ok, json } = await postJson(apiAdd, {
          name,
          knx_nonce: nonceAdd
        });

        if (!ok || !json || json.success === false) {
          const msg =
            (json && (json.message || json.error)) ||
            (json && json.data && (json.data.message || json.data.error)) ||
            "Add failed";
          toast(msg, "error");
          if (saveBtn) saveBtn.disabled = false;
          return;
        }

        toast("Category added.", "success");
        closeModal();

        // Prefer server-returned category (fast), else reload
        const newCat = json?.category || null;
        if (newCat && newCat.id) {
          categories = [newCat, ...categories];
          render();
        } else {
          await load();
        }
      });
    }

    // Delete modal cancel
    if (delCancel) delCancel.addEventListener("click", closeDeleteModal);

    // Delete modal confirm
    if (delConfirm) {
      delConfirm.addEventListener("click", async () => {
        const id = Number(pendingDelete?.id || 0);
        if (!id) {
          toast("Missing category id.", "error");
          return;
        }
        if (!apiDelete) {
          toast("Delete API not configured.", "error");
          return;
        }

        delConfirm.disabled = true;

        const { ok, json } = await postJson(apiDelete, {
          id,
          knx_nonce: nonceDelete
        });

        if (!ok || !json || json.success === false) {
          toast((json && (json.message || json.error)) || "Delete failed", "error");
          delConfirm.disabled = false;
          return;
        }

        toast("Category deleted.", "success");

        // Remove from local + UI
        categories = categories.filter(c => Number(c?.id) !== id);
        removeFromUI(id);

        closeDeleteModal();

        // Ensure UI stays consistent if last element removed
        render();
      });
    }

    // Delegated actions (table + cards)
    root.addEventListener("click", (e) => {
      const t = e.target;
      if (!t) return;

      const row = t.closest("tr[data-cat-id]");
      const card = t.closest(".knx-citycard[data-cat-id]");
      const host = row || card;

      if (!host) return;

      const id = Number(host.getAttribute("data-cat-id") || 0);
      const cat = categories.find(c => Number(c?.id) === id);
      if (!cat) return;

      // Edit
      if (t.closest(".knx-cat-edit")) {
        openModal("edit", cat);
        return;
      }

      // Delete
      if (t.closest(".knx-cat-delete")) {
        openDeleteModal(cat);
        return;
      }
    });

    // Delegated toggle (change event)
    root.addEventListener("change", async (e) => {
      const t = e.target;
      if (!t || !t.classList || !t.classList.contains("knx-cat-toggle")) return;

      const row = t.closest("tr[data-cat-id]");
      const card = t.closest(".knx-citycard[data-cat-id]");
      const host = row || card;
      if (!host) return;

      const id = Number(host.getAttribute("data-cat-id") || 0);
      const current = categories.find(c => Number(c?.id) === id);
      if (!current) return;

      const desired = t.checked ? "active" : "inactive";

      setBusy(host, true);

      const { ok, json } = await postJson(apiToggle, {
        id,
        status: desired,
        knx_nonce: nonceToggle
      });

      setBusy(host, false);

      if (!ok || !json || json.success === false) {
        // revert
        t.checked = !t.checked;
        toast((json && (json.message || json.error)) || "Toggle failed", "error");
        return;
      }

      // Update local list + badges
      categories = categories.map(c => (Number(c?.id) === id ? { ...c, status: desired } : c));
      render();
      toast("Category status updated.", "success");
    });

    // Start
    load();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
