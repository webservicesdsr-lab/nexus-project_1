/**
 * ==========================================================
 * Kingdom Nexus - Edit Hub Items JS (v3.0 Production)
 * ----------------------------------------------------------
 * ✅ REST Real: get-hub-items, add-hub-item, delete-hub-item, reorder-item
 * ✅ Dynamic categories (from knx_items_categories)
 * ✅ Modal Add Item with validation
 * ✅ Unified Toast feedback system
 * ✅ Fully compatible with /edit-hub-items/?id={hub_id}
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".knx-items-wrapper");
  if (!wrap) return;

  const apiGet = wrap.dataset.apiGet;
  const apiAdd = wrap.dataset.apiAdd;
  const apiDelete = wrap.dataset.apiDelete;
  const apiReorder = wrap.dataset.apiReorder;
  const apiCats = wrap.dataset.apiCats;
  const apiExportCsv = wrap.dataset.apiExportCsv;
  const hubId = wrap.dataset.hubId;
  const nonce = wrap.dataset.nonce;

  const grid = document.getElementById("knxCategoriesContainer");
  const addBtn = document.getElementById("knxAddItemBtn");
  const modal = document.getElementById("knxAddItemModal");
  const form = document.getElementById("knxAddItemForm");
  const closeBtn = document.getElementById("knxCloseModal");
  const deleteModal = document.getElementById("knxDeleteItemModal");
  const confirmDeleteBtn = document.getElementById("knxConfirmDeleteItemBtn");
  const cancelDeleteBtn = document.getElementById("knxCancelDeleteItemBtn");
  const deleteItemIdInput = document.getElementById("knxDeleteItemId");

  const categorySelect = document.getElementById("knxItemCategorySelect");

  /* ==========================================================
     Sidebar toggles
  ========================================================== */
  const sidebar = document.getElementById("knxSidebar");
  const toggleDesktop = document.getElementById("knxToggleDesktop");
  const toggleClose = document.getElementById("knxToggleClose");
  const mobileToggle = document.getElementById("knxMobileToggle");

  if (toggleDesktop) {
    toggleDesktop.addEventListener("click", () => {
      sidebar.classList.toggle("collapsed");
      toggleDesktop.innerHTML = sidebar.classList.contains("collapsed")
        ? '<i class="fas fa-angle-right"></i>'
        : '<i class="fas fa-angle-left"></i>';
    });
  }
  if (mobileToggle) {
    mobileToggle.addEventListener("click", () => {
      sidebar.classList.add("active");
      mobileToggle.style.display = "none";
      toggleClose.style.display = "block";
    });
  }
  if (toggleClose) {
    toggleClose.addEventListener("click", () => {
      sidebar.classList.remove("active");
      mobileToggle.style.display = "block";
      toggleClose.style.display = "none";
    });
  }

  /* ==========================================================
     1. Load categories (for Add Item modal)
  ========================================================== */
  async function loadCategories() {
    try {
      const res = await fetch(`${apiCats}?hub_id=${hubId}`);
      const data = await res.json();
      categorySelect.innerHTML = "";

      if (!data.success || !data.categories || data.categories.length === 0) {
        categorySelect.innerHTML = '<option value="">No categories available</option>';
        categorySelect.disabled = true;
        return;
      }

      data.categories.forEach((cat) => {
        if (cat.status === "active") {
          const opt = document.createElement("option");
          opt.value = cat.id;
          opt.textContent = cat.name;
          categorySelect.appendChild(opt);
        }
      });

      categorySelect.disabled = false;
    } catch (err) {
      console.error("Error loading categories:", err);
      categorySelect.innerHTML = '<option value="">Error loading categories</option>';
    }
  }

  /* ==========================================================
     2. Load items (GET)
  ========================================================== */
  async function loadItems() {
    grid.innerHTML = "<p style='text-align:center;'>Loading items...</p>";
    try {
      const res = await fetch(`${apiGet}?hub_id=${hubId}`);
      const data = await res.json();

      if (!data.success) throw new Error(data.error || "Failed to load items");

      renderItems(data.items || []);
    } catch (err) {
      console.error(err);
      grid.innerHTML = "<p style='text-align:center;color:red;'>Error loading items</p>";
    }
  }

  /* ==========================================================
     3. Render items grouped by category
  ========================================================== */
  function renderItems(items) {
    grid.innerHTML = "";

    if (!items.length) {
      grid.innerHTML = "<p style='text-align:center;'>No items yet.</p>";
      return;
    }

    const grouped = {};
    items.forEach((item) => {
      const catId = item.category_id || 0;
      if (!grouped[catId]) grouped[catId] = [];
      grouped[catId].push(item);
    });

    Object.keys(grouped).forEach((catId) => {
      const categoryBlock = document.createElement("div");
      categoryBlock.className = "knx-category-block";

      const catName =
        items.find((i) => i.category_id == catId)?.category_name || "Uncategorized";

      const header = document.createElement("div");
      header.className = "knx-category-header";
      header.innerHTML = `
        <h3>${catName}</h3>
        <button class="knx-category-add-btn" data-cat-id="${catId}" data-cat-name="${catName}" title="Add item to ${catName}">
          <i class="fas fa-plus"></i>
        </button>
      `;
      categoryBlock.appendChild(header);

      const container = document.createElement("div");
      container.className = "knx-item-grid";
      // Flag single-item grids so CSS can align them left instead of centered
      if (grouped[catId].length === 1) {
        container.classList.add("knx-item-grid--single");
      }

      grouped[catId].forEach((item) => {
        const card = document.createElement("div");
        card.className = "knx-item-card";
        if (item.status === "inactive") {
          card.classList.add("inactive");
        }
        card.innerHTML = `
          <div class="knx-item-card__img-wrap">
            <img src="${item.image_url || "https://via.placeholder.com/400x200?text=No+Image"}" alt="${item.name}">
          </div>
          <div class="knx-item-card__body">
            <span class="knx-item-price-pill">$${parseFloat(item.price).toFixed(2)}</span>
            <div class="knx-item-info">
              <h4>${item.name}</h4>
              <p>${item.description ? item.description.substring(0, 80) : ""}</p>
            </div>
            <div class="knx-item-footer">
              <div class="knx-actions">
                <button class="knx-action-icon move-up" data-id="${item.id}" title="Move Up"><i class="fas fa-chevron-up"></i></button>
                <button class="knx-action-icon move-down" data-id="${item.id}" title="Move Down"><i class="fas fa-chevron-down"></i></button>
                <a href="/edit-item?hub_id=${hubId}&item_id=${item.id}" class="knx-action-icon" title="Edit"><i class="fas fa-pen"></i></a>
                <button class="knx-action-icon delete" data-id="${item.id}" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </div>
          </div>
        `;
        container.appendChild(card);
      });

      categoryBlock.appendChild(container);
      grid.appendChild(categoryBlock);
    });

    attachItemEvents();
  }

  /* ==========================================================
     4. Modal controls
  ========================================================== */
  addBtn.addEventListener("click", () => {
    modal.classList.add("active");
    loadCategories();
  });

  closeBtn.addEventListener("click", () => {
    modal.classList.remove("active");
    form.reset();
  });

  /* ==========================================================
     5. Add new item
  ========================================================== */
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const formData = new FormData(form);
    formData.append("hub_id", hubId);
    formData.append("knx_nonce", nonce);

    const categoryId = formData.get("category_id");
    const image = formData.get("item_image");

    if (!categoryId || categoryId === "") {
      knxToast("Please select a category", "error");
      return;
    }
    if (!image || image.size === 0) {
      knxToast("Please upload an image", "error");
      return;
    }

    try {
      const res = await fetch(apiAdd, { method: "POST", body: formData });
      const data = await res.json();

      if (data.success) {
        knxToast("Item added successfully", "success");
        modal.classList.remove("active");
        form.reset();
        loadItems();
      } else {
        knxToast(data.error || "Error adding item", "error");
      }
    } catch (err) {
      console.error(err);
      knxToast("Network error while adding item", "error");
    }
  });

  /* ==========================================================
     6. Item event listeners (delete + reorder + category add)
  ========================================================== */
  function attachItemEvents() {
    // Category add buttons
    document.querySelectorAll(".knx-category-add-btn").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const catId = btn.dataset.catId;
        modal.classList.add("active");
        await loadCategories();
        // Pre-select the category
        if (categorySelect && catId) {
          categorySelect.value = catId;
        }
      });
    });

    // Delete
    document.querySelectorAll(".knx-action-icon.delete").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.dataset.id;
        deleteItemIdInput.value = id;
        deleteModal.classList.add("active");
      });
    });

    cancelDeleteBtn.addEventListener("click", () => {
      deleteModal.classList.remove("active");
    });

    confirmDeleteBtn.addEventListener("click", async () => {
      const id = deleteItemIdInput.value;
      if (!id) return;
      try {
        const res = await fetch(apiDelete, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id, hub_id: hubId, knx_nonce: nonce }),
        });
        const data = await res.json();
        if (data.success) {
          knxToast("Item deleted", "success");
          deleteModal.classList.remove("active");
          loadItems();
        } else {
          knxToast(data.error || "Delete failed", "error");
        }
      } catch (err) {
        console.error(err);
        knxToast("Network error deleting item", "error");
      }
    });

    // Reorder (Up/Down)
    document.querySelectorAll(".move-up, .move-down").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = btn.dataset.id;
        const move = btn.classList.contains("move-up") ? "up" : "down";

        try {
          const res = await fetch(apiReorder, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              hub_id: hubId,
              item_id: id,
              move,
              knx_nonce: nonce,
            }),
          });
          const data = await res.json();

          if (data.success) {
            knxToast("Item reordered successfully", "success");
            setTimeout(loadItems, 400);
          } else {
            knxToast(data.error || "Reorder failed", "error");
          }
        } catch (err) {
          console.error(err);
          knxToast("Network error during reorder", "error");
        }
      });
    });
  }

  /* ==========================================================
     Initialize
  ========================================================== */
  loadItems();
  
  /* ==========================================================
     7. CSV Upload flow (v2.0 — dual format + conflict mode)
  ========================================================== */
  const uploadCsvBtn = document.getElementById("knxUploadCsvBtn");
  const uploadCsvModal = document.getElementById("knxUploadCsvModal");
  const uploadCsvForm = document.getElementById("knxUploadCsvForm");
  const uploadCsvFile = document.getElementById("knxCsvFile");
  const closeCsvBtn = document.getElementById("knxCloseCsvModal");
  const replaceWarning = document.getElementById("knxReplaceWarning");

  // Show/hide replace warning based on conflict_mode radio
  if (uploadCsvModal) {
    uploadCsvModal.querySelectorAll('input[name="conflict_mode"]').forEach((radio) => {
      radio.addEventListener("change", () => {
        if (replaceWarning) {
          replaceWarning.style.display = radio.value === "replace" && radio.checked ? "" : "none";
        }
      });
    });
  }

  if (uploadCsvBtn && uploadCsvModal) {
    uploadCsvBtn.addEventListener("click", () => {
      uploadCsvModal.classList.add("active");
    });
  }

  if (closeCsvBtn) {
    closeCsvBtn.addEventListener("click", () => {
      uploadCsvModal.classList.remove("active");
      if (uploadCsvForm) uploadCsvForm.reset();
      if (replaceWarning) replaceWarning.style.display = "none";
    });
  }

  if (uploadCsvForm) {
    uploadCsvForm.addEventListener("submit", async (e) => {
      e.preventDefault();

      if (!uploadCsvFile || !uploadCsvFile.files || uploadCsvFile.files.length === 0) {
        knxToast("Please select a CSV file", "error");
        return;
      }

      const file = uploadCsvFile.files[0];
      if (!file.name.match(/\.csv$/i)) {
        knxToast("Please upload a .csv file", "error");
        return;
      }

      // Get conflict mode
      const conflictRadio = uploadCsvForm.querySelector('input[name="conflict_mode"]:checked');
      const conflictMode = conflictRadio ? conflictRadio.value : "skip";

      // Confirm replace mode
      if (conflictMode === "replace") {
        if (!confirm("Replace mode will DELETE and RECREATE all modifier groups for matched items. Existing orders are not affected.\n\nContinue?")) {
          return;
        }
      }

      const fd = new FormData();
      fd.append("items_csv", file);
      fd.append("hub_id", hubId);
      fd.append("knx_nonce", nonce);
      fd.append("conflict_mode", conflictMode);

      try {
        const submitBtn = document.getElementById("knxUploadCsvSubmit");
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        }

        const res = await fetch(wrap.dataset.apiUploadCsv, {
          method: "POST",
          body: fd,
        });

        const data = await res.json();

        if (data && data.success) {
          // Build summary message
          const d = data.data || {};
          const parts = [];
          if (d.inserted) parts.push(d.inserted + " inserted");
          if (d.updated) parts.push(d.updated + " updated");
          if (d.skipped) parts.push(d.skipped + " skipped");
          if (d.categories_created) parts.push(d.categories_created + " categories created");
          if (d.modifiers_created) parts.push(d.modifiers_created + " modifier groups");
          if (d.options_created) parts.push(d.options_created + " options");

          const summary = parts.length ? parts.join(", ") : "CSV processed";
          knxToast(summary, "success");
          uploadCsvModal.classList.remove("active");
          uploadCsvForm.reset();
          if (replaceWarning) replaceWarning.style.display = "none";
          loadItems();
        } else {
          const errMsg = (data && data.message) ? data.message : (data && data.error) ? data.error : "CSV upload failed";
          knxToast(errMsg, "error");
          console.error("CSV upload result:", data);
        }
      } catch (err) {
        console.error(err);
        knxToast("Network error during CSV upload", "error");
      } finally {
        const submitBtn = document.getElementById("knxUploadCsvSubmit");
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
        }
      }
    });
  }

  /* ==========================================================
     8. CSV Export flow (Studio format)
  ========================================================== */
  const exportCsvBtn = document.getElementById("knxExportCsvBtn");
  const exportCsvModal = document.getElementById("knxExportCsvModal");
  const exportCsvSubmit = document.getElementById("knxExportCsvSubmit");
  const closeExportBtn = document.getElementById("knxCloseExportModal");
  const exportCatSelect = document.getElementById("knxExportCategorySelect");

  async function loadExportCategories() {
    if (!exportCatSelect) return;
    try {
      const res = await fetch(`${apiCats}?hub_id=${hubId}`);
      const data = await res.json();

      // Keep the "All categories" option
      exportCatSelect.innerHTML = '<option value="">All categories (full hub)</option>';

      if (data.success && data.categories) {
        data.categories.forEach((cat) => {
          if (cat.status === "active") {
            const opt = document.createElement("option");
            opt.value = cat.id;
            opt.textContent = cat.name;
            exportCatSelect.appendChild(opt);
          }
        });
      }
    } catch (err) {
      console.error("Error loading export categories:", err);
    }
  }

  if (exportCsvBtn && exportCsvModal) {
    exportCsvBtn.addEventListener("click", () => {
      exportCsvModal.classList.add("active");
      loadExportCategories();
    });
  }

  if (closeExportBtn) {
    closeExportBtn.addEventListener("click", () => {
      exportCsvModal.classList.remove("active");
    });
  }

  if (exportCsvSubmit) {
    exportCsvSubmit.addEventListener("click", () => {
      if (!apiExportCsv) {
        knxToast("Export endpoint not configured", "error");
        return;
      }

      const catId = exportCatSelect ? exportCatSelect.value : "";
      let url = `${apiExportCsv}?action=knx_export_hub_items_csv&hub_id=${hubId}&knx_nonce=${nonce}`;
      if (catId) {
        url += `&category_id=${catId}`;
      }

      // Trigger download via hidden link
      const a = document.createElement("a");
      a.href = url;
      a.style.display = "none";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);

      knxToast("Export started...", "success");
      exportCsvModal.classList.remove("active");
    });
  }
});
