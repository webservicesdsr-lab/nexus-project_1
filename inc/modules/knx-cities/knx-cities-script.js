/**
 * ==========================================================
 * KNX Cities — Sealed UI Script (NEXUS FINAL)
 * ----------------------------------------------------------
 * Desktop → TABLE (CSS controls visibility)
 * Mobile  → CARDS (CSS controls visibility)
 * - Add City modal (super_admin)
 * - Operational toggle
 * - Edit link (new slug base)
 * - Delete → existing delete modal ONLY (no browser confirm)
 * - Toast → knxToast(message, type)
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
    } catch (_) {}
    console.log("[KNX]", msg);
  }

  function esc(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  async function apiJson(url, payload) {
    const res = await fetch(url, {
      method: payload ? "POST" : "GET",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: payload ? JSON.stringify(payload) : null
    });

    let json = null;
    try { json = await res.json(); } catch (e) {}

    return { ok: res.ok, status: res.status, json };
  }

  function normalizeOperational(city) {
    return Number(city?.is_operational ?? 1) === 1;
  }

  function normalizeStatus(city) {
    const s = String(city?.status ?? "active").toLowerCase();
    return (s === "inactive") ? "inactive" : "active";
  }

  function buildEditUrl(editBase, id) {
    // editBase example: https://site.com/knx-edit-city/?id=
    const base = String(editBase || "");
    if (!base) return "#";
    return base + encodeURIComponent(String(id));
  }

  function renderRow(city, role, editBase, apiToggle, nonceToggle, apiDelete, nonceDelete) {
    const id = city.id;
    const name = city.name || ("City #" + id);
    const status = normalizeStatus(city);
    const operational = normalizeOperational(city);

    const tr = document.createElement("tr");
    tr.setAttribute("data-city-id", String(id));

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
        <label class="knx-switch" title="Operational">
          <input type="checkbox" class="knx-operational-toggle" ${operational ? "checked" : ""}>
          <span class="knx-slider"></span>
        </label>
      </td>

      <td class="knx-center">
        <a class="knx-iconbtn" href="${esc(buildEditUrl(editBase, id))}" title="Edit City">
          <i class="fas fa-pen"></i>
        </a>
      </td>

      ${role === "super_admin" ? `
        <td class="knx-center">
          <button type="button" class="knx-iconbtn knx-iconbtn--danger knx-delete-city" title="Delete City">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      ` : ``}
    `;

    // Toggle
    const toggle = qs(".knx-operational-toggle", tr);
    if (toggle) {
      toggle.addEventListener("change", async () => {
        const desired = toggle.checked ? 1 : 0;
        tr.classList.add("is-busy");

        const { ok, json } = await apiJson(apiToggle, {
          city_id: id,
          operational: desired,
          knx_nonce: nonceToggle
        });

        tr.classList.remove("is-busy");

        if (!ok || !json || json.success === false) {
          toggle.checked = !toggle.checked;
          toast((json && (json.message || json.error)) || "Operational toggle failed", "error");
          return;
        }

        toast("Operational updated", "success");
      });
    }

    // Delete (modal only)
    const delBtn = qs(".knx-delete-city", tr);
    if (delBtn) {
      delBtn.addEventListener("click", () => {
        if (typeof window.knxOpenCityDeleteModal !== "function") {
          toast("Delete modal is not loaded.", "error");
          return;
        }

        window.knxOpenCityDeleteModal({
          city_id: String(id),
          api: apiDelete,
          nonce: nonceDelete
        });
      });
    }

    return tr;
  }

  function renderCard(city, role, editBase, apiToggle, nonceToggle, apiDelete, nonceDelete) {
    const id = city.id;
    const name = city.name || ("City #" + id);
    const status = normalizeStatus(city);
    const operational = normalizeOperational(city);

    const card = document.createElement("div");
    card.className = "knx-citycard";
    card.setAttribute("data-city-id", String(id));

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
        <div class="knx-citycard__label">Operational</div>
        <label class="knx-switch">
          <input type="checkbox" class="knx-operational-toggle" ${operational ? "checked" : ""}>
          <span class="knx-slider"></span>
        </label>
      </div>

      <div class="knx-citycard__actions">
        <a class="knx-btn knx-btn--ghost knx-btn--sm" href="${esc(buildEditUrl(editBase, id))}">
          <i class="fas fa-pen"></i> Edit
        </a>

        ${role === "super_admin" ? `
          <button type="button" class="knx-btn knx-btn--danger knx-btn--sm knx-delete-city">
            <i class="fas fa-trash"></i> Delete
          </button>
        ` : ``}
      </div>
    `;

    // Toggle
    const toggle = qs(".knx-operational-toggle", card);
    if (toggle) {
      toggle.addEventListener("change", async () => {
        const desired = toggle.checked ? 1 : 0;
        card.classList.add("is-busy");

        const { ok, json } = await apiJson(apiToggle, {
          city_id: id,
          operational: desired,
          knx_nonce: nonceToggle
        });

        card.classList.remove("is-busy");

        if (!ok || !json || json.success === false) {
          toggle.checked = !toggle.checked;
          toast((json && (json.message || json.error)) || "Operational toggle failed", "error");
          return;
        }

        toast("Operational updated", "success");
      });
    }

    // Delete
    const delBtn = qs(".knx-delete-city", card);
    if (delBtn) {
      delBtn.addEventListener("click", () => {
        if (typeof window.knxOpenCityDeleteModal !== "function") {
          toast("Delete modal is not loaded.", "error");
          return;
        }

        window.knxOpenCityDeleteModal({
          city_id: String(id),
          api: apiDelete,
          nonce: nonceDelete
        });
      });
    }

    return card;
  }

  async function init() {
    const root = qs(".knx-cities-signed");
    if (!root) return;

    const role = root.getAttribute("data-role") || "";
    const apiGet = root.getAttribute("data-api-get") || "";
    const apiAdd = root.getAttribute("data-api-add") || "";
    const apiToggle = root.getAttribute("data-api-toggle") || "";
    const apiDelete = root.getAttribute("data-api-delete") || "";
    const nonceAdd = root.getAttribute("data-nonce-add") || "";
    const nonceToggle = root.getAttribute("data-nonce-toggle") || "";
    const nonceDelete = root.getAttribute("data-nonce-delete") || "";
    const editBase = root.getAttribute("data-edit-base") || "";

    const tbody = qs("#knxCitiesTbody", root);
    const cards = qs("#knxCitiesCards", root);
    const search = qs("#knxCitiesSearch", root);

    if (!apiGet || !tbody || !cards) return;

    // Fetch cities
    const { ok, json } = await apiJson(apiGet);
    if (!ok || !json || json.success === false) {
      toast((json && (json.message || json.error)) || "Failed to load cities", "error");
      return;
    }

    let cities = (json?.data?.cities && Array.isArray(json.data.cities)) ? json.data.cities : [];

    // Render function (client-side search)
    function doRender() {
      const f = String(search?.value || "").trim().toLowerCase();
      const filtered = !f
        ? cities
        : cities.filter(c => String(c?.name || "").toLowerCase().includes(f));

      // Table
      tbody.innerHTML = "";
      filtered.forEach(city => {
        tbody.appendChild(renderRow(city, role, editBase, apiToggle, nonceToggle, apiDelete, nonceDelete));
      });

      // Cards
      cards.innerHTML = "";
      filtered.forEach(city => {
        cards.appendChild(renderCard(city, role, editBase, apiToggle, nonceToggle, apiDelete, nonceDelete));
      });
    }

    doRender();

    if (search) {
      search.addEventListener("input", doRender);
    }

    // ==========================
    // Add City Modal (super_admin)
    // ==========================
    const addBtn = qs("#knxAddCityBtn");
    const addModal = qs("#knxAddCityModal");
    const addCancel = qs("#knxAddCityCancel");
    const addForm = qs("#knxAddCityForm");
    const addSave = qs("#knxAddCitySave");

    function openAddModal() {
      if (!addModal) return;
      addModal.classList.add("active");
      addModal.setAttribute("aria-hidden", "false");
      const input = qs('input[name="name"]', addModal);
      if (input) setTimeout(() => input.focus(), 50);
    }

    function closeAddModal() {
      if (!addModal) return;
      addModal.classList.remove("active");
      addModal.setAttribute("aria-hidden", "true");
      const input = qs('input[name="name"]', addModal);
      if (input) input.value = "";
    }

    if (addBtn && addModal) addBtn.addEventListener("click", openAddModal);
    if (addCancel && addModal) addCancel.addEventListener("click", closeAddModal);

    // Close by backdrop
    if (addModal) {
      addModal.addEventListener("click", (e) => {
        const t = e.target;
        if (t && t.getAttribute && t.getAttribute("data-knx-close") === "1") {
          closeAddModal();
        }
      });
    }

    // Submit add
    if (addForm) {
      addForm.addEventListener("submit", async (e) => {
        e.preventDefault();

        if (!apiAdd) {
          toast("Add API not configured.", "error");
          return;
        }

        const input = qs('input[name="name"]', addForm);
        const name = String(input?.value || "").trim();

        if (!name) {
          toast("City name is required.", "warning");
          return;
        }

        if (addSave) addSave.disabled = true;

        const { ok: okAdd, json: out } = await apiJson(apiAdd, {
          name,
          knx_nonce: nonceAdd
        });

        if (addSave) addSave.disabled = false;

        if (!okAdd || !out || out.success === false) {
          toast((out && (out.message || out.error)) || "Error adding city", "error");
          return;
        }

        toast("City added successfully", "success");
        closeAddModal();

        // Refresh list (no reload)
        const fresh = await apiJson(apiGet);
        if (fresh.ok && fresh.json && fresh.json.success) {
          cities = Array.isArray(fresh.json?.data?.cities) ? fresh.json.data.cities : cities;
          doRender();
        } else {
          // fallback
          doRender();
        }
      });
    }

    // NOTE:
    // Delete modal JS removes row/card itself by selector:
    // tr[data-city-id="X"] + .knx-citycard[data-city-id="X"]
    // We matched those attributes intentionally.
  }

  document.addEventListener("DOMContentLoaded", init);
})();
