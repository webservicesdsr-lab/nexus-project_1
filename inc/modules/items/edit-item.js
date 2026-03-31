/**
 * Edit Item JS — Nexus Workspace v5.0
 * - Real split workspace support
 * - Improved progressive layout behavior
 * - Modifier groups and options management
 * - Clipboard image paste support
 */

document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".knx-edit-item-wrapper");
  if (!wrap) return;

  /* =========================
     Endpoints + State
  ========================= */
  const api = {
    get: wrap.dataset.apiGet,
    update: wrap.dataset.apiUpdate,
    cats: wrap.dataset.apiCats,
    list: wrap.dataset.apiModifiers,
    globals: wrap.dataset.apiGlobalModifiers,
    clone: wrap.dataset.apiCloneModifier,
    saveMod: wrap.dataset.apiSaveModifier,
    delMod: wrap.dataset.apiDeleteModifier,
    reMod: wrap.dataset.apiReorderModifier,
    saveOpt: wrap.dataset.apiSaveOption,
    delOpt: wrap.dataset.apiDeleteOption,
    reOpt: wrap.dataset.apiReorderOption,
  };

  const state = {
    hubId: wrap.dataset.hubId,
    itemId: wrap.dataset.itemId,
    nonce: wrap.dataset.nonce,
    modifiers: [],
  };

  /* =========================
     DOM refs
  ========================= */
  const nameInput = document.getElementById("knxItemName");
  const descInput = document.getElementById("knxItemDescription");
  const priceInput = document.getElementById("knxItemPrice");
  const catSelect = document.getElementById("knxItemCategory");
  const statusSelect = document.getElementById("knxItemStatus");
  const imageInput = document.getElementById("knxItemImage");
  const imagePreview = document.getElementById("knxItemPreview");
  const modifiersList = document.getElementById("knxModifiersList");
  const editForm = document.getElementById("knxEditItemForm");

  /* =========================
     Helpers
  ========================= */
  const toast = (message, type) => {
    if (typeof knxToast === "function") {
      knxToast(message, type || "success");
    } else {
      console.log(`[${type || "info"}] ${message}`);
    }
  };

  const esc = (value) => {
    return (value || "")
      .toString()
      .replace(/[&<>"']/g, (match) => {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        }[match];
      });
  };

  const priceTextUSD = (value) => {
    const number = parseFloat(value || 0);
    return number === 0
      ? `<span class="knx-free">FREE</span>`
      : `+$${number.toFixed(2)}`;
  };

  const metaTextPlain = (modifier) => {
    const type = modifier.type === "multiple" ? "Multiple" : "Single";
    const required = parseInt(modifier.required, 10) === 1 ? "Required" : "Optional";

    let range = "";
    if (modifier.type === "multiple") {
      const min = parseInt(modifier.min_selection, 10) > 0 ? parseInt(modifier.min_selection, 10) : 0;
      const max = modifier.max_selection ? modifier.max_selection : "∞";
      range = `${min}-${max}`;
    }

    return [type, required, range].filter(Boolean).join(" • ");
  };

  const setPreview = (url) => {
    imagePreview.innerHTML = `<img src="${url}" alt="Item image">`;
  };

  const updatePreviewStatus = (status) => {
    if (status === "inactive") {
      imagePreview.classList.add("inactive");
    } else {
      imagePreview.classList.remove("inactive");
    }
  };

  /* =========================
     Confirm modal
  ========================= */
  function knxConfirm(title, message, onConfirm) {
    const origin = document.activeElement;
    const modal = document.createElement("div");

    modal.className = "knx-modal-overlay";
    modal.innerHTML = `
      <div class="knx-modal-content knx-modal-sm">
        <div class="knx-modal-header">
          <h3><i class="fas fa-exclamation-triangle" style="color:#dc3545"></i> ${esc(title)}</h3>
          <button class="knx-modal-close" aria-label="Close">&times;</button>
        </div>

        <div style="padding:14px;">
          <p style="margin:0;color:#374151;line-height:1.5">${esc(message)}</p>
        </div>

        <div class="knx-modal-actions" style="padding:0 14px 14px;">
          <button type="button" class="knx-btn-secondary knx-modal-close">Cancel</button>
          <button type="button" class="knx-btn" id="knxDoConfirm" style="background:#dc3545">
            <i class="fas fa-trash"></i> Delete
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const close = () => {
      modal.remove();
      if (origin && typeof origin.focus === "function") {
        origin.focus();
      }
    };

    modal.addEventListener("click", (event) => {
      if (event.target === modal) close();
    });

    modal.querySelectorAll(".knx-modal-close").forEach((button) => {
      button.addEventListener("click", close);
    });

    const onEsc = (event) => {
      if (event.key === "Escape") {
        document.removeEventListener("keydown", onEsc);
        close();
      }
    };

    document.addEventListener("keydown", onEsc);

    modal.querySelector("#knxDoConfirm").addEventListener("click", () => {
      document.removeEventListener("keydown", onEsc);
      close();
      if (typeof onConfirm === "function") {
        onConfirm();
      }
    });
  }

  /* =========================
     Init
  ========================= */
  (async function init() {
    await loadItem();
  })();

  async function loadItem() {
    try {
      const response = await fetch(`${api.get}?hub_id=${state.hubId}&id=${state.itemId}`);
      const json = await response.json();

      if (!json.success || !json.item) {
        toast("Item not found", "error");
        return;
      }

      const item = json.item;

      nameInput.value = item.name || "";
      descInput.value = item.description || "";
      priceInput.value = item.price || "0.00";
      statusSelect.value = item.status || "active";

      setPreview(item.image_url || "https://via.placeholder.com/640x360?text=No+Image");
      updatePreviewStatus(item.status || "active");

      await loadCategories(item.category_id);

      try {
        const backLink = document.querySelector(".knx-edit-header a.knx-btn-secondary");
        if (backLink && item.category_id) {
          const href = backLink.getAttribute("href").split("#")[0];
          backLink.setAttribute("href", `${href}#cat-${encodeURIComponent(item.category_id)}`);
        }
      } catch (error) {
        /* noop */
      }

      await loadModifiers();
    } catch (error) {
      toast("Error loading item", "error");
    }
  }

  async function loadCategories(selectedId) {
    try {
      const response = await fetch(`${api.cats}?hub_id=${state.hubId}`);
      const json = await response.json();

      catSelect.innerHTML = "";

      if (!json.success || !json.categories || !json.categories.length) {
        catSelect.innerHTML = '<option value="">No categories</option>';
        return;
      }

      json.categories.forEach((category) => {
        if (category.status !== "active") return;

        const option = document.createElement("option");
        option.value = category.id;
        option.textContent = category.name;

        if (selectedId && parseInt(selectedId, 10) === parseInt(category.id, 10)) {
          option.selected = true;
        }

        catSelect.appendChild(option);
      });
    } catch (error) {
      catSelect.innerHTML = '<option value="">Error loading categories</option>';
    }
  }

  async function loadModifiers() {
    try {
      const response = await fetch(`${api.list}?item_id=${state.itemId}`);
      const json = await response.json();

      state.modifiers = json.success ? (json.modifiers || []) : [];
      renderModifiers();
    } catch (error) {
      modifiersList.innerHTML = '<div class="knx-error-small">Error loading groups</div>';
    }
  }

  function renderModifiers() {
    if (!state.modifiers.length) {
      modifiersList.innerHTML = `
        <div class="knx-empty-state">
          <i class="fas fa-box-open fa-2x" style="color:#9ca3af;margin-bottom:8px;"></i>
          <div>No groups yet. Add one or use the library.</div>
        </div>
      `;
      return;
    }

    modifiersList.innerHTML = state.modifiers.map(renderModifierCard).join("");
    wireCardEvents();
  }

  function renderModifierCard(modifier) {
    const optionsHTML = modifier.options && modifier.options.length
      ? `<div class="knx-options-list">${modifier.options.map(renderOption).join("")}</div>`
      : `<div class="knx-options-list"></div>`;

    return `
      <div class="knx-modifier-card" data-id="${modifier.id}">
        <div class="knx-modifier-card-header" role="button" tabindex="0" aria-expanded="true">
          <div class="knx-h-left">
            <button class="knx-chevron-btn" data-action="collapse" aria-label="Collapse group" title="Collapse">
              <i class="fas fa-chevron-up"></i>
            </button>

            <div class="knx-title-wrap">
              <h4>${esc(modifier.name)}</h4>
              <div class="knx-meta">${esc(metaTextPlain(modifier))}</div>
            </div>
          </div>

          <div class="knx-h-right">
            <div class="knx-actions-grid">
              <button class="knx-icon-btn" data-action="add-option" title="Add option" aria-label="Add option">
                <i class="fas fa-plus"></i>
              </button>

              <button class="knx-icon-btn" data-action="edit" title="Edit group" aria-label="Edit group">
                <i class="fas fa-pen"></i>
              </button>

              <button class="knx-icon-btn danger" data-action="delete" title="Delete group" aria-label="Delete group">
                <i class="fas fa-trash"></i>
              </button>

              <button class="knx-icon-btn" data-action="sort-up" title="Move up" aria-label="Move up">
                <i class="fas fa-chevron-up"></i>
              </button>

              <button class="knx-icon-btn" data-action="sort-down" title="Move down" aria-label="Move down">
                <i class="fas fa-chevron-down"></i>
              </button>
            </div>
          </div>
        </div>

        ${optionsHTML}
      </div>
    `;
  }

  function renderOption(option) {
    const removeBadge = option.option_action === "remove"
      ? '<span class="knx-option-remove-badge">REMOVE</span>'
      : "";

    return `
      <div class="knx-option-item" data-option-id="${option.id}">
        <div class="knx-option-name">${esc(option.name)}${removeBadge}</div>
        <div class="knx-option-price">${priceTextUSD(option.price_adjustment)}</div>
        <div class="knx-option-actions">
          <button class="knx-icon-btn" data-action="edit-option" title="Edit option" aria-label="Edit option">
            <i class="fas fa-pen"></i>
          </button>
          <button class="knx-icon-btn danger" data-action="delete-option" title="Delete option" aria-label="Delete option">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
    `;
  }

  function wireCardEvents() {
    document.querySelectorAll(".knx-modifier-card").forEach((card) => {
      const id = parseInt(card.dataset.id, 10);
      const list = card.querySelector(".knx-options-list");
      const header = card.querySelector(".knx-modifier-card-header");
      const collapseBtn = card.querySelector('[data-action="collapse"]');

      requestAnimationFrame(() => {
        list.style.maxHeight = `${list.scrollHeight}px`;
      });

      const toggle = (event) => {
        if (event && event.target.closest(".knx-actions-grid")) return;

        const collapsed = card.classList.toggle("is-collapsed");
        const icon = collapseBtn.querySelector("i");

        if (collapsed) {
          icon.className = "fas fa-chevron-down";
          list.style.maxHeight = "0px";
          header.setAttribute("aria-expanded", "false");
        } else {
          icon.className = "fas fa-chevron-up";
          list.style.maxHeight = `${list.scrollHeight}px`;
          header.setAttribute("aria-expanded", "true");
        }
      };

      header.addEventListener("click", toggle);
      header.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          toggle(event);
        }
      });

      collapseBtn.addEventListener("click", (event) => {
        event.stopPropagation();
        toggle(event);
      });

      card.querySelector('[data-action="add-option"]').addEventListener("click", (event) => {
        event.stopPropagation();
        openOptionModal(id);
      });

      card.querySelector('[data-action="edit"]').addEventListener("click", (event) => {
        event.stopPropagation();
        const modifier = state.modifiers.find((item) => parseInt(item.id, 10) === id);
        openModifierModal(modifier);
      });

      card.querySelector('[data-action="delete"]').addEventListener("click", (event) => {
        event.stopPropagation();
        deleteModifier(id);
      });

      card.querySelector('[data-action="sort-up"]').addEventListener("click", (event) => {
        event.stopPropagation();
        reorderModifier(id, "up");
      });

      card.querySelector('[data-action="sort-down"]').addEventListener("click", (event) => {
        event.stopPropagation();
        reorderModifier(id, "down");
      });

      card.querySelectorAll('[data-action="edit-option"]').forEach((button) => {
        button.addEventListener("click", (event) => {
          const optionId = parseInt(event.currentTarget.closest(".knx-option-item").dataset.optionId, 10);
          const modifier = state.modifiers.find((item) => parseInt(item.id, 10) === id);
          const option = (modifier.options || []).find((item) => parseInt(item.id, 10) === optionId);

          openOptionModal(id, option);
        });
      });

      card.querySelectorAll('[data-action="delete-option"]').forEach((button) => {
        button.addEventListener("click", (event) => {
          const optionId = parseInt(event.currentTarget.closest(".knx-option-item").dataset.optionId, 10);
          deleteOption(optionId);
        });
      });
    });
  }

  /* =========================
     Item image + save
  ========================= */
  imageInput.addEventListener("change", (event) => {
    const file = event.target.files && event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (loadEvent) => {
      setPreview(loadEvent.target.result);
      updatePreviewStatus(statusSelect.value);
    };
    reader.readAsDataURL(file);
  });

  statusSelect.addEventListener("change", (event) => {
    updatePreviewStatus(event.target.value);
  });

  document.addEventListener("paste", (event) => {
    try {
      const clipboard = event.clipboardData || window.clipboardData;
      if (!clipboard || !clipboard.items) return;

      for (const item of clipboard.items) {
        if (!item.type || item.type.indexOf("image") !== 0) continue;

        const blob = item.getAsFile ? item.getAsFile() : null;
        if (!blob) continue;

        const extension = blob.type && blob.type.split("/")[1] ? blob.type.split("/")[1] : "png";
        const fileName = `pasted-image-${Date.now()}.${extension}`;
        const file = new File([blob], fileName, { type: blob.type });

        try {
          const dataTransfer = new DataTransfer();
          dataTransfer.items.add(file);
          imageInput.files = dataTransfer.files;
        } catch (error) {
          /* noop */
        }

        const url = URL.createObjectURL(file);
        setPreview(url);
        updatePreviewStatus(statusSelect.value);
        toast("Image pasted from clipboard");
        event.preventDefault();
        return;
      }
    } catch (error) {
      /* noop */
    }
  });

  editForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const formData = new FormData();
    formData.append("hub_id", state.hubId);
    formData.append("id", state.itemId);
    formData.append("name", nameInput.value.trim());
    formData.append("description", descInput.value.trim());
    formData.append("category_id", catSelect.value);
    formData.append("price", priceInput.value.trim());
    formData.append("status", statusSelect.value);
    formData.append("knx_nonce", state.nonce);

    if (imageInput.files.length) {
      formData.append("item_image", imageInput.files[0]);
    }

    try {
      const response = await fetch(api.update, {
        method: "POST",
        body: formData,
      });

      const json = await response.json();

      if (json.success) {
        toast("Item updated");
      } else {
        toast(json.error || "Error updating item", "error");
      }
    } catch (error) {
      toast("Network error", "error");
    }
  });

  document.getElementById("knxBrowseGlobalBtn")?.addEventListener("click", openGlobalLibrary);
  document.getElementById("knxAddModifierBtn")?.addEventListener("click", () => openModifierModal(null));

  /* =========================
     Modifier modal
  ========================= */
  function openModifierModal(modifier) {
    const isEdit = !!modifier;
    const modal = document.createElement("div");

    modal.className = "knx-modal-overlay";
    modal.innerHTML = `
      <div class="knx-modal-content">
        <div class="knx-modal-header">
          <h3><i class="fas fa-sliders-h"></i> ${isEdit ? "Edit group" : "New group"}</h3>
          <button class="knx-modal-close" aria-label="Close">&times;</button>
        </div>

        <form id="mmForm">
          <div class="knx-form-row">
            <div class="knx-form-group">
              <label>Group name <span class="knx-required">*</span></label>
              <div class="mmNameWrap" style="position:relative;">
                <input id="mmName" autocomplete="off" value="${isEdit ? esc(modifier.name) : ""}" required>
                <div class="mmPresetDropdown hidden" style="position:absolute;left:0;right:0;top:calc(100% + 6px);background:#fff;border:1px solid var(--line);border-radius:8px;max-height:220px;overflow:auto;z-index:60;padding:6px;box-shadow:0 6px 18px rgba(2,6,23,0.06);"></div>
              </div>
            </div>

            <div class="knx-form-group">
              <label>Type</label>
              <select id="mmType">
                <option value="single" ${isEdit && modifier.type === "single" ? "selected" : ""}>Single</option>
                <option value="multiple" ${isEdit && modifier.type === "multiple" ? "selected" : ""}>Multiple</option>
              </select>
            </div>
          </div>

          <div class="knx-form-row mm-toggle-row">
            <div class="mmToggleGroup">
              <div class="mmToggle" id="mmRequiredToggle" role="button" tabindex="0" aria-pressed="${isEdit && parseInt(modifier.required, 10) === 1 ? "true" : "false"}">Required</div>
              <div class="mmToggle" id="mmGlobalToggle" role="button" tabindex="0" aria-pressed="${isEdit && parseInt(modifier.is_global, 10) === 1 ? "true" : "false"}"><i class="fas fa-globe"></i> Make this global</div>
            </div>

            <input type="hidden" id="mmRequired" value="${isEdit && parseInt(modifier.required, 10) === 1 ? 1 : 0}">
            <input type="hidden" id="mmGlobal" value="${isEdit && parseInt(modifier.is_global, 10) === 1 ? 1 : 0}">
          </div>

          <div class="knx-form-row" id="mmMultiRow" style="display:${isEdit && modifier.type === "multiple" ? "grid" : "none"}">
            <div class="knx-form-group">
              <label>Min</label>
              <input type="number" id="mmMin" min="0" value="${isEdit ? (modifier.min_selection || 0) : 0}">
            </div>

            <div class="knx-form-group">
              <label>Max</label>
              <input type="number" id="mmMax" min="1" value="${isEdit && modifier.max_selection ? modifier.max_selection : ""}">
            </div>
          </div>

          <div class="knx-form-group" style="margin-top:16px;">
            <strong>Options</strong>
            <div id="mmOptions"></div>

            <div style="margin-top:8px;">
              <button type="button" class="knx-btn knx-btn-outline" id="mmAddOpt">
                <i class="fas fa-plus"></i> Add option
              </button>
            </div>
          </div>

          <div class="knx-modal-actions">
            <button type="button" class="knx-btn-secondary knx-modal-close">Cancel</button>
            <button class="knx-btn" type="submit">
              <i class="fas fa-save"></i> ${isEdit ? "Update" : "Create"}
            </button>
          </div>
        </form>
      </div>
    `;

    document.body.appendChild(modal);

    const mmType = modal.querySelector("#mmType");
    const mmMultiRow = modal.querySelector("#mmMultiRow");
    const optionsList = modal.querySelector("#mmOptions");
    const mmName = modal.querySelector("#mmName");
    const presetDropdown = modal.querySelector(".mmPresetDropdown");

    const close = () => {
      try {
        document.removeEventListener("click", onDocClickForPreset);
      } catch (error) {
        /* noop */
      }
      modal.remove();
    };

    modal.addEventListener("click", (event) => {
      if (event.target === modal) close();
    });

    modal.querySelectorAll(".knx-modal-close").forEach((button) => {
      button.addEventListener("click", close);
    });

    mmType.addEventListener("change", () => {
      mmMultiRow.style.display = mmType.value === "multiple" ? "grid" : "none";
    });

    const presets = [
      {
        id: "remove_ingredients",
        name: "Remove Ingredients",
        desc: "Makes group Optional, Type=Multiple and new options default to Remove",
        type: "multiple",
        required: 0,
        defaultOptionAction: "remove",
      },
      {
        id: "choose_size",
        name: "Choose Your Size",
        desc: "Makes group Required (single selection).",
        type: "single",
        required: 1,
        defaultOptionAction: "add",
      },
      {
        id: "extras",
        name: "Extras",
        desc: "Sets group to Multiple (use for extra add-ons).",
        type: "multiple",
        required: 0,
        defaultOptionAction: "add",
      },
    ];

    let selectedPreset = null;

    function renderPresetList(filter) {
      const query = (filter || "").toLowerCase();
      const matches = presets.filter((preset) => preset.name.toLowerCase().includes(query));

      presetDropdown.innerHTML = matches.length
        ? matches.map((preset) => `
            <div class="mmPresetItem" data-id="${preset.id}" style="padding:8px;border-radius:6px;cursor:pointer;">
              <div style="font-weight:800">${preset.name}</div>
              <div style="font-size:12px;color:#6b7280">${preset.desc}</div>
            </div>
          `).join("")
        : '<div style="padding:8px;color:#6b7280">No presets</div>';
    }

    function onDocClickForPreset(event) {
      const wrap = modal.querySelector(".mmNameWrap");
      if (!modal.contains(event.target) || (!wrap.contains(event.target) && !event.target.closest(".mmPresetItem"))) {
        presetDropdown.classList.add("hidden");
        try {
          mmName.setAttribute("autocomplete", "on");
        } catch (error) {
          /* noop */
        }
      }
    }

    function addRow(option) {
      const row = document.createElement("div");
      row.className = "knx-option-row";
      row.dataset.optId = option?.id || "";

      const currentAction = option && option.option_action === "remove"
        ? "remove"
        : (selectedPreset && selectedPreset.defaultOptionAction === "remove" ? "remove" : "add");

      row.innerHTML = `
        <div class="knx-opt-drag"><i class="fas fa-grip-vertical"></i></div>
        <input type="text" class="mmOptName" placeholder="Option name" value="${option ? esc(option.name) : ""}">
        <input type="number" step="0.01" class="mmOptPrice" value="${option ? (option.price_adjustment || 0) : 0}">
        <select class="mmOptAction">
          <option value="add"${currentAction === "add" ? " selected" : ""}>Add</option>
          <option value="remove"${currentAction === "remove" ? " selected" : ""}>Remove</option>
        </select>
        <button type="button" class="knx-icon-btn danger mmDel" title="Remove"><i class="fas fa-trash"></i></button>
      `;

      row.querySelector(".mmDel").addEventListener("click", () => row.remove());
      optionsList.appendChild(row);

      return row;
    }

    mmName.addEventListener("focus", () => {
      renderPresetList("");
      presetDropdown.classList.remove("hidden");
      try {
        mmName.setAttribute("autocomplete", "off");
      } catch (error) {
        /* noop */
      }
    });

    mmName.addEventListener("input", (event) => {
      const query = (event.target.value || "").toLowerCase();
      const matches = presets.filter((preset) => preset.name.toLowerCase().includes(query));

      if (matches.length) {
        presetDropdown.classList.remove("hidden");
        renderPresetList(query);
        try {
          mmName.setAttribute("autocomplete", "off");
        } catch (error) {
          /* noop */
        }
      } else {
        presetDropdown.classList.add("hidden");
        try {
          mmName.setAttribute("autocomplete", "on");
        } catch (error) {
          /* noop */
        }
      }
    });

    presetDropdown.addEventListener("click", (event) => {
      const item = event.target.closest(".mmPresetItem");
      if (!item) return;

      const preset = presets.find((entry) => entry.id === item.dataset.id);
      if (!preset) return;

      selectedPreset = preset;
      mmName.value = preset.name;
      mmType.value = preset.type || "single";
      mmMultiRow.style.display = mmType.value === "multiple" ? "grid" : "none";

      const mmRequiredHidden = modal.querySelector("#mmRequired");
      const mmGlobalHidden = modal.querySelector("#mmGlobal");
      const mmRequiredToggle = modal.querySelector("#mmRequiredToggle");
      const mmGlobalToggle = modal.querySelector("#mmGlobalToggle");

      setToggle(mmRequiredToggle, mmRequiredHidden, preset.required ? 1 : 0);
      setToggle(mmGlobalToggle, mmGlobalHidden, preset.is_global ? 1 : 0);

      presetDropdown.classList.add("hidden");

      const newRow = addRow(null);
      const nameField = newRow.querySelector(".mmOptName");
      if (nameField) {
        nameField.focus();
      }
    });

    document.addEventListener("click", onDocClickForPreset);

    const mmRequiredHidden = modal.querySelector("#mmRequired");
    const mmGlobalHidden = modal.querySelector("#mmGlobal");
    const mmRequiredToggle = modal.querySelector("#mmRequiredToggle");
    const mmGlobalToggle = modal.querySelector("#mmGlobalToggle");

    function setToggle(button, hidden, value) {
      const isPressed = !!parseInt(value, 10);

      if (button) {
        button.setAttribute("aria-pressed", isPressed ? "true" : "false");
        button.classList.toggle("active", isPressed);
      }

      if (hidden) {
        hidden.value = isPressed ? "1" : "0";
      }
    }

    [
      { button: mmRequiredToggle, hidden: mmRequiredHidden },
      { button: mmGlobalToggle, hidden: mmGlobalHidden },
    ].forEach(({ button, hidden }) => {
      if (!button) return;

      button.addEventListener("click", () => {
        const newValue = hidden && hidden.value === "1" ? 0 : 1;
        setToggle(button, hidden, newValue);
      });

      button.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          button.click();
        }
      });
    });

    setToggle(mmRequiredToggle, mmRequiredHidden, mmRequiredHidden ? mmRequiredHidden.value : 0);
    setToggle(mmGlobalToggle, mmGlobalHidden, mmGlobalHidden ? mmGlobalHidden.value : 0);

    if (isEdit && modifier.options) {
      modifier.options.forEach(addRow);
    }

    modal.querySelector("#mmAddOpt").addEventListener("click", () => addRow(null));

    initOptionDrag(optionsList);

    modal.querySelector("#mmForm").addEventListener("submit", async (event) => {
      event.preventDefault();

      const name = modal.querySelector("#mmName").value.trim();
      const type = mmType.value;
      const required = modal.querySelector("#mmRequired").value === "1" ? 1 : 0;
      const isGlobal = modal.querySelector("#mmGlobal").value === "1" ? 1 : 0;
      const minSelection = type === "multiple" ? (parseInt(modal.querySelector("#mmMin").value, 10) || 0) : 0;
      const maxSelection = type === "multiple" && modal.querySelector("#mmMax").value
        ? parseInt(modal.querySelector("#mmMax").value, 10)
        : null;

      if (!name) {
        toast("Name is required", "error");
        return;
      }

      const payload = {
        id: isEdit ? modifier.id : 0,
        item_id: isGlobal ? null : state.itemId,
        hub_id: state.hubId,
        name,
        type,
        required,
        min_selection: minSelection,
        max_selection: maxSelection,
        is_global: isGlobal,
        knx_nonce: state.nonce,
      };

      try {
        const response = await fetch(api.saveMod, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });

        const json = await response.json();

        if (!json.success) {
          toast(json.error || "Error saving group", "error");
          return;
        }

        const savedId = json.id || json.ID || (modifier && modifier.id);

        const rows = Array.from(optionsList.querySelectorAll(".knx-option-row"));
        const originalIds = isEdit && modifier.options ? modifier.options.map((option) => parseInt(option.id, 10)) : [];
        const currentIds = rows
          .map((row) => row.dataset.optId ? parseInt(row.dataset.optId, 10) : 0)
          .filter(Boolean);

        const toDelete = originalIds.filter((id) => !currentIds.includes(id));

        for (const id of toDelete) {
          await fetch(api.delOpt, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              id,
              knx_nonce: state.nonce,
            }),
          });
        }

        for (const row of rows) {
          const optionId = row.dataset.optId ? parseInt(row.dataset.optId, 10) : 0;
          const optionName = row.querySelector(".mmOptName").value.trim();
          const optionPrice = parseFloat(row.querySelector(".mmOptPrice").value) || 0;
          const optionAction = row.querySelector(".mmOptAction")?.value || "add";

          if (!optionName) continue;

          await fetch(api.saveOpt, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              id: optionId,
              modifier_id: savedId,
              name: optionName,
              price_adjustment: optionPrice,
              option_action: optionAction,
              knx_nonce: state.nonce,
            }),
          });
        }

        toast(isEdit ? "Group updated" : "Group created");
        close();
        await loadModifiers();
      } catch (error) {
        toast("Network error", "error");
      }
    });
  }

  function initOptionDrag(container) {
    let dragged = null;
    let activeHandle = null;
    let pointerId = null;
    let startY = 0;
    let isDragging = false;
    const moveThreshold = 6;

    function moveDragged(event) {
      if (!isDragging || !dragged) return;

      const clientY = event.clientY;
      const rows = Array.from(container.querySelectorAll(".knx-option-row"));

      for (const row of rows) {
        if (row === dragged) continue;

        const rect = row.getBoundingClientRect();
        const midpoint = rect.top + rect.height / 2;

        if (clientY < midpoint) {
          container.insertBefore(dragged, row);
          break;
        }
      }

      const last = container.querySelector(".knx-option-row:last-child");
      if (last && last !== dragged) {
        const lastRect = last.getBoundingClientRect();
        if (clientY > lastRect.top + lastRect.height / 2) {
          container.insertBefore(dragged, last.nextSibling);
        }
      }
    }

    function endPointer(event) {
      if (pointerId !== null && event.pointerId !== pointerId) return;

      if (isDragging && dragged) {
        dragged.classList.remove("dragging");
      }

      if (activeHandle) {
        activeHandle.classList.remove("active");
      }

      dragged = null;
      activeHandle = null;
      pointerId = null;
      isDragging = false;

      document.removeEventListener("pointermove", onPointerMove);
      document.removeEventListener("pointerup", endPointer);
      document.removeEventListener("pointercancel", endPointer);

      document.body.style.userSelect = "";
    }

    function onPointerMove(event) {
      if (pointerId !== event.pointerId) return;

      const moved = Math.abs(event.clientY - startY);

      if (!isDragging) {
        if (moved > moveThreshold) {
          isDragging = true;
          dragged = activeHandle.closest(".knx-option-row");

          if (dragged) dragged.classList.add("dragging");
          if (activeHandle) activeHandle.classList.add("active");

          document.body.style.userSelect = "none";
        } else {
          return;
        }
      }

      moveDragged(event);
    }

    container.addEventListener("pointerdown", (event) => {
      const handle = event.target.closest(".knx-opt-drag");
      if (!handle) return;

      const row = handle.closest(".knx-option-row");
      if (!row) return;

      pointerId = event.pointerId;
      startY = event.clientY;
      activeHandle = handle;

      document.addEventListener("pointermove", onPointerMove);
      document.addEventListener("pointerup", endPointer);
      document.addEventListener("pointercancel", endPointer);

      event.preventDefault();
    });
  }

  /* =========================
     Single option modal
  ========================= */
  function openOptionModal(modifierId, option) {
    const isEdit = !!option;
    const modal = document.createElement("div");

    modal.className = "knx-modal-overlay";
    modal.innerHTML = `
      <div class="knx-modal-content knx-modal-sm">
        <div class="knx-modal-header">
          <h3>${isEdit ? "Edit option" : "New option"}</h3>
          <button class="knx-modal-close" aria-label="Close">&times;</button>
        </div>

        <form id="optForm">
          <div class="knx-form-group">
            <label>Name <span class="knx-required">*</span></label>
            <input id="opName" value="${isEdit ? esc(option.name) : ""}" required>
          </div>

          <div class="knx-form-group">
            <label>Price adjustment (USD)</label>
            <input id="opPrice" type="number" step="0.01" value="${isEdit ? (option.price_adjustment || 0) : 0}">
            <small>0.00 = FREE</small>
          </div>

          <div class="knx-form-group">
            <label>Action</label>
            <select id="opAction">
              <option value="add"${isEdit && option.option_action === "remove" ? "" : " selected"}>Add (+)</option>
              <option value="remove"${isEdit && option.option_action === "remove" ? " selected" : ""}>Remove (No…)</option>
            </select>
            <small>"Remove" shows as <strong>No [name]</strong> in the cart &amp; order</small>
          </div>

          <div class="knx-modal-actions">
            <button class="knx-btn-secondary knx-modal-close" type="button">Cancel</button>
            <button class="knx-btn" type="submit">
              <i class="fas fa-save"></i> ${isEdit ? "Update" : "Create"}
            </button>
          </div>
        </form>
      </div>
    `;

    document.body.appendChild(modal);

    const close = () => modal.remove();

    modal.addEventListener("click", (event) => {
      if (event.target === modal) close();
    });

    modal.querySelectorAll(".knx-modal-close").forEach((button) => {
      button.addEventListener("click", close);
    });

    modal.querySelector("#optForm").addEventListener("submit", async (event) => {
      event.preventDefault();

      const name = modal.querySelector("#opName").value.trim();
      const price = parseFloat(modal.querySelector("#opPrice").value) || 0;
      const action = modal.querySelector("#opAction").value || "add";

      if (!name) {
        toast("Name is required", "error");
        return;
      }

      try {
        const response = await fetch(api.saveOpt, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            id: isEdit ? option.id : 0,
            modifier_id: modifierId,
            name,
            price_adjustment: price,
            option_action: action,
            knx_nonce: state.nonce,
          }),
        });

        const json = await response.json();

        if (json.success) {
          toast(isEdit ? "Option updated" : "Option created");
          close();
          loadModifiers();
        } else {
          toast(json.error || "Error saving option", "error");
        }
      } catch (error) {
        toast("Network error", "error");
      }
    });
  }

  /* =========================
     Modifier reorder / delete
  ========================= */
  async function reorderModifier(id, direction) {
    try {
      const response = await fetch(api.reMod, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          id,
          direction,
          knx_nonce: state.nonce,
        }),
      });

      const json = await response.json();

      if (json.success) {
        modifiersList.style.opacity = "0.5";

        setTimeout(async () => {
          await loadModifiers();
          modifiersList.style.opacity = "1";
        }, 140);

        toast("Order updated");
      } else {
        toast(json.error || "Reorder failed", "error");
      }
    } catch (error) {
      toast("Network error", "error");
    }
  }

  async function deleteModifier(id) {
    knxConfirm("Delete this group?", "This will delete the group and its options.", async () => {
      try {
        const response = await fetch(api.delMod, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            id,
            knx_nonce: state.nonce,
          }),
        });

        const json = await response.json();

        if (json.success) {
          toast("Group deleted");
          loadModifiers();
        } else {
          toast(json.error || "Delete failed", "error");
        }
      } catch (error) {
        toast("Network error", "error");
      }
    });
  }

  async function deleteOption(id) {
    knxConfirm("Delete this option?", "This action cannot be undone.", async () => {
      try {
        const response = await fetch(api.delOpt, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            id,
            knx_nonce: state.nonce,
          }),
        });

        const json = await response.json();

        if (json.success) {
          toast("Option deleted");
          loadModifiers();
        } else {
          toast(json.error || "Delete failed", "error");
        }
      } catch (error) {
        toast("Network error", "error");
      }
    });
  }

  /* =========================
     Global library
  ========================= */
  async function openGlobalLibrary() {
    try {
      const response = await fetch(`${api.globals}?hub_id=${state.hubId}`);
      const json = await response.json();

      const content = (!json.success || !json.modifiers || !json.modifiers.length)
        ? `
          <div class="knx-global-empty" style="padding:24px;text-align:center;color:#6b7280">
            <i class="fas fa-box-open fa-2x" style="color:#9ca3af;margin-bottom:10px;"></i>
            <div>No global groups yet</div>
          </div>
        `
        : `
          <input
            id="knxGlobalSearch"
            class="knx-global-search"
            placeholder="Search groups…"
            style="margin:10px 14px 0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;width:calc(100% - 28px);"
          >

          <div class="knx-global-library-list" style="padding-top:10px;">
            ${json.modifiers.map((modifier) => `
              <div class="knx-global-item" data-id="${modifier.id}">
                <div class="knx-global-head">
                  <div class="knx-global-title">
                    <h4>${esc(modifier.name)}</h4>
                    <div class="knx-global-meta">
                      ${esc(metaTextPlain(modifier))} • Used in ${modifier.usage_count || 0} item${(modifier.usage_count || 0) === 1 ? "" : "s"}
                    </div>
                  </div>

                  <div class="knx-global-actions">
                    <button class="knx-icon-btn" data-act="edit" title="Edit">
                      <i class="fas fa-pen"></i>
                    </button>

                    <button class="knx-icon-btn danger" data-act="delete" title="Delete">
                      <i class="fas fa-trash"></i>
                    </button>

                    <button class="knx-btn knx-btn-sm" data-act="add" style="padding:8px 14px;height:38px;">
                      <i class="fas fa-plus"></i> Add
                    </button>
                  </div>
                </div>

                ${(modifier.options && modifier.options.length)
                  ? `
                    <div class="knx-global-options">
                      ${modifier.options.map((option) => `
                        <div class="knx-global-line">
                          <span>${esc(option.name)}</span>
                          <span>${priceTextUSD(option.price_adjustment)}</span>
                        </div>
                      `).join("")}
                    </div>
                  `
                  : ""}
              </div>
            `).join("")}
          </div>
        `;

      const modal = document.createElement("div");
      modal.className = "knx-modal-overlay";
      modal.innerHTML = `
        <div class="knx-modal-content knx-modal-lg">
          <div class="knx-modal-header">
            <h3><i class="fas fa-globe"></i> Global library</h3>
            <button class="knx-modal-close" aria-label="Close">&times;</button>
          </div>
          ${content}
        </div>
      `;

      document.body.appendChild(modal);

      const close = () => modal.remove();

      modal.addEventListener("click", (event) => {
        if (event.target === modal) close();
      });

      modal.querySelector(".knx-modal-close").addEventListener("click", close);

      modal.querySelector("#knxGlobalSearch")?.addEventListener("input", (event) => {
        const query = event.target.value.toLowerCase();

        modal.querySelectorAll(".knx-global-item").forEach((item) => {
          const name = item.querySelector("h4")?.textContent.toLowerCase() || "";
          item.style.display = name.includes(query) ? "" : "none";
        });
      });

      modal.querySelectorAll(".knx-global-item").forEach((item) => {
        const id = parseInt(item.dataset.id, 10);

        item.querySelector('[data-act="add"]')?.addEventListener("click", async () => {
          const button = item.querySelector('[data-act="add"]');
          button.disabled = true;
          button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding…';

          try {
            const response = await fetch(api.clone, {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                global_modifier_id: id,
                item_id: state.itemId,
                knx_nonce: state.nonce,
              }),
            });

            const result = await response.json();

            if (result.success) {
              toast("Group added to this item");
              close();
              loadModifiers();
            } else if (result.error === "already_cloned") {
              toast("This group already exists in this item", "warning");
            } else {
              toast(result.error || "Error adding group", "error");
            }
          } catch (error) {
            toast("Network error", "error");
          }

          button.disabled = false;
          button.innerHTML = '<i class="fas fa-plus"></i> Add';
        });

        item.querySelector('[data-act="edit"]')?.addEventListener("click", () => {
          const modifier = (json.modifiers || []).find((entry) => parseInt(entry.id, 10) === id);
          openModifierModal(modifier);
        });

        item.querySelector('[data-act="delete"]')?.addEventListener("click", () => {
          knxConfirm("Delete this global group?", "This will remove it from library.", async () => {
            try {
              const response = await fetch(api.delMod, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                  id,
                  knx_nonce: state.nonce,
                }),
              });

              const result = await response.json();

              if (result.success) {
                toast("Global group deleted");
                item.remove();
              } else {
                toast(result.error || "Error deleting", "error");
              }
            } catch (error) {
              toast("Network error", "error");
            }
          });
        });
      });
    } catch (error) {
      toast("Error loading global library", "error");
    }
  }
});