document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".knx-items-wrapper");
  if (!wrap) return;

  const apiGet = wrap.dataset.apiGet;
  const apiSave = wrap.dataset.apiSave;
  const apiReorder = wrap.dataset.apiReorder;
  const apiToggle = wrap.dataset.apiToggle;
  const apiDelete = wrap.dataset.apiDelete;
  const hubId = wrap.dataset.hubId;
  const nonce = wrap.dataset.nonce;

  const list = document.getElementById("knxCategoriesList");
  const addBtn = document.getElementById("knxAddCatBtn");

  const modal = document.getElementById("knxCatModal");
  const form = document.getElementById("knxCatForm");
  const modalTitle = document.getElementById("knxCatModalTitle");
  const catId = document.getElementById("knxCatId");
  const catName = document.getElementById("knxCatName");
  const cancelBtn = document.getElementById("knxCatCancel");

  const delModal = document.getElementById("knxDeleteCatModal");
  const delId = document.getElementById("knxDeleteCatId");
  const delConfirm = document.getElementById("knxConfirmDeleteCatBtn");
  const delCancel = document.getElementById("knxCancelDeleteCatBtn");

  async function loadCategories() {
    try {
      const res = await fetch(`${apiGet}?hub_id=${hubId}`);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || "Error loading categories");
      renderList(data.categories || []);
    } catch (e) {
      console.error(e);
      knxToast("Error loading categories", "error");
    }
  }

  function renderList(rows) {
    list.innerHTML = "";
    if (!rows.length) {
      list.innerHTML = "<p style='text-align:center;'>No categories yet.</p>";
      return;
    }

    rows.forEach(c => {
      const row = document.createElement("div");
      row.className = "knx-category-row";
      row.innerHTML = `
        <div class="knx-category-left">
          <h4 class="knx-category-name">${c.name}</h4>
          <span class="knx-category-status ${c.status}">${c.status}</span>
        </div>
        <div class="knx-category-right">
          <button class="knx-action-icon move-up" data-id="${c.id}" title="Move Up"><i class="fas fa-chevron-up"></i></button>
          <button class="knx-action-icon move-down" data-id="${c.id}" title="Move Down"><i class="fas fa-chevron-down"></i></button>
          <label class="knx-switch" title="Toggle status">
            <input type="checkbox" class="knx-toggle" data-id="${c.id}" ${c.status === "active" ? "checked" : ""}>
            <span class="knx-slider"></span>
          </label>
          <button class="knx-action-icon edit" data-id="${c.id}" data-name="${c.name}"><i class="fas fa-pen"></i></button>
          <button class="knx-action-icon delete" data-id="${c.id}"><i class="fas fa-trash"></i></button>
        </div>
      `;
      list.appendChild(row);
    });
    attachEvents();
  }

  function openModal(edit = false, data = {}) {
    modal.classList.add("active");
    if (edit) {
      modalTitle.textContent = "Edit Category";
      catId.value = data.id;
      catName.value = data.name;
    } else {
      modalTitle.textContent = "Add Category";
      catId.value = "";
      catName.value = "";
    }
  }

  function closeModal() { modal.classList.remove("active"); }
  addBtn.addEventListener("click", () => openModal(false));
  cancelBtn.addEventListener("click", closeModal);

  form.addEventListener("submit", async e => {
    e.preventDefault();
    const name = catName.value.trim();
    if (!name) return knxToast("Name required", "error");

    const fd = new FormData();
    fd.append("hub_id", hubId);
    fd.append("name", name);
    if (catId.value) fd.append("id", catId.value);
    fd.append("knx_nonce", nonce);

    try {
      const res = await fetch(apiSave, { method: "POST", body: fd });
      const data = await res.json();
      if (data.success) {
        knxToast("Category saved", "success");
        closeModal();
        loadCategories();
      } else knxToast(data.error || "Save failed", "error");
    } catch {
      knxToast("Network error saving category", "error");
    }
  });

  function attachEvents() {
    document.querySelectorAll(".edit").forEach(btn =>
      btn.addEventListener("click", () =>
        openModal(true, { id: btn.dataset.id, name: btn.dataset.name })
      )
    );

    document.querySelectorAll(".move-up, .move-down").forEach(btn =>
      btn.addEventListener("click", async () => {
        const id = btn.dataset.id;
        const move = btn.classList.contains("move-up") ? "up" : "down";
        try {
          const res = await fetch(apiReorder, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ hub_id: hubId, category_id: id, move, knx_nonce: nonce }),
          });
          const data = await res.json();
          if (data.success) loadCategories();
          else knxToast(data.error || "Reorder failed", "error");
        } catch {
          knxToast("Network error reordering", "error");
        }
      })
    );

    document.querySelectorAll(".knx-toggle").forEach(chk =>
      chk.addEventListener("change", async () => {
        const id = chk.dataset.id;
        const status = chk.checked ? "active" : "inactive";
        try {
          const res = await fetch(apiToggle, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ hub_id: hubId, category_id: id, status, knx_nonce: nonce }),
          });
          const data = await res.json();
          if (!data.success) knxToast(data.error || "Toggle failed", "error");
        } catch {
          knxToast("Network error toggling", "error");
        }
      })
    );

    document.querySelectorAll(".delete").forEach(btn =>
      btn.addEventListener("click", () => {
        delId.value = btn.dataset.id;
        delModal.classList.add("active");
      })
    );
  }

  delCancel.addEventListener("click", () => delModal.classList.remove("active"));
  delConfirm.addEventListener("click", async () => {
    const id = delId.value;
    try {
      const res = await fetch(apiDelete, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ hub_id: hubId, category_id: id, knx_nonce: nonce }),
      });
      const data = await res.json();
      if (data.success) {
        knxToast("Category deleted", "success");
        delModal.classList.remove("active");
        loadCategories();
      } else knxToast(data.error || "Delete failed", "error");
    } catch {
      knxToast("Network error deleting category", "error");
    }
  });

  loadCategories();
});
