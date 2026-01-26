/**
 * Edit Item JS — Nexus Clean v4.0
 * - Icon-only actions (Add / Edit / Delete / Sort ↑↓)
 * - Sin "pills": meta como texto plano (Single|Multiple • Optional|Required • rango)
 * - Confirm modal propio (sin alert/confirm nativo)
 * - Eliminado "Default" en opciones (UI + payload)
 * - Responsive y accesible (aria-labels, teclado en collapse)
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
  const nameInput    = document.getElementById("knxItemName");
  const descInput    = document.getElementById("knxItemDescription");
  const priceInput   = document.getElementById("knxItemPrice");
  const catSelect    = document.getElementById("knxItemCategory");
  const statusSelect = document.getElementById("knxItemStatus");
  const imageInput   = document.getElementById("knxItemImage");
  const imagePreview = document.getElementById("knxItemPreview");
  const modifiersList= document.getElementById("knxModifiersList");

  /* =========================
     Helpers
  ========================= */
  const toast = (m, t) =>
    (typeof knxToast === "function" ? knxToast(m, t || "success") : console.log(`[${t||'info'}] ${m}`));

  const esc = (s) => (s || "")
    .toString()
    .replace(/[&<>"']/g, (m) => ({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;" }[m]));

  const setPreview = (url) => (imagePreview.innerHTML = `<img src="${url}" alt="Item image">`);

  const updatePreviewStatus = (status) => {
    if (status === "inactive") {
      imagePreview.classList.add("inactive");
    } else {
      imagePreview.classList.remove("inactive");
    }
  };

  const priceTextUSD = (n) => {
    const val = parseFloat(n || 0);
    return val === 0 ? `<span class="knx-free">FREE</span>` : `+$${val.toFixed(2)}`;
  };

  const metaTextPlain = (mod) => {
    const type = mod.type === "multiple" ? "Multiple" : "Single";
    const req  = mod.required == 1 ? "Required" : "Optional";
    let range  = "";
    if (mod.type === "multiple") {
      const min = mod.min_selection > 0 ? mod.min_selection : 0;
      const max = mod.max_selection ? mod.max_selection : "∞";
      range = `${min}-${max}`;
    }
    return [type, req, range].filter(Boolean).join(" • ");
  };

  // Confirm modal Nexus
  function knxConfirm(title, message, onConfirm){
    const origin = document.activeElement;
    const modal  = document.createElement("div");
    modal.className = "knx-modal-overlay";
    modal.innerHTML = `
      <div class="knx-modal-content knx-modal-sm">
        <div class="knx-modal-header">
          <h3><i class="fas fa-exclamation-triangle" style="color:#dc3545"></i> ${esc(title)}</h3>
          <button class="knx-modal-close" aria-label="Close">&times;</button>
        </div>
        <div style="padding:14px;"><p style="margin:0;color:#374151;line-height:1.5">${esc(message)}</p></div>
        <div class="knx-modal-actions">
          <button type="button" class="knx-btn-secondary knx-modal-close">Cancel</button>
          <button type="button" class="knx-btn" id="knxDoConfirm" style="background:#dc3545"><i class="fas fa-trash"></i> Delete</button>
        </div>
      </div>`;
    document.body.appendChild(modal);

    const close = () => { modal.remove(); origin && origin.focus && origin.focus(); };
    modal.addEventListener("click", (e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll(".knx-modal-close").forEach(b=> b.addEventListener("click", close));

    const escKey = (e)=>{ if(e.key==="Escape"){ close(); document.removeEventListener("keydown", escKey); } };
    document.addEventListener("keydown", escKey);

    modal.querySelector("#knxDoConfirm").addEventListener("click", ()=>{ document.removeEventListener("keydown", escKey); close(); onConfirm && onConfirm(); });
  }

  /* =========================
     Init
  ========================= */
  (async function init(){ await loadItem(); })();

  async function loadItem() {
    try {
      const r = await fetch(`${api.get}?hub_id=${state.hubId}&id=${state.itemId}`);
      const j = await r.json();
      if (!j.success || !j.item) return toast("Item not found","error");
      const it = j.item;
      nameInput.value  = it.name || "";
      descInput.value  = it.description || "";
      priceInput.value = it.price || "0.00";
      statusSelect.value = it.status || "active";
      setPreview(it.image_url || "https://via.placeholder.com/420x260?text=No+Image");
      updatePreviewStatus(it.status || "active");
      await loadCategories(it.category_id);
      await loadModifiers();
    } catch { toast("Error loading item","error"); }
  }

  async function loadCategories(selectedId) {
    try{
      const r = await fetch(`${api.cats}?hub_id=${state.hubId}`);
      const j = await r.json();
      catSelect.innerHTML = "";
      if (!j.success || !j.categories || !j.categories.length){
        catSelect.innerHTML = '<option value="">No categories</option>'; return;
      }
      j.categories.forEach((c)=>{
        if (c.status === "active"){
          const opt = document.createElement("option");
          opt.value = c.id; opt.textContent = c.name;
          if (selectedId && +selectedId === +c.id) opt.selected = true;
          catSelect.appendChild(opt);
        }
      });
    } catch {/* noop */}
  }

  async function loadModifiers(){
    try{
      const r = await fetch(`${api.list}?item_id=${state.itemId}`);
      const j = await r.json();
      state.modifiers = j.success ? (j.modifiers || []) : [];
      renderModifiers();
    }catch{
      modifiersList.innerHTML = '<div class="knx-error-small">Error loading</div>';
    }
  }

  function renderModifiers(){
    if (!state.modifiers.length){
      modifiersList.innerHTML = `
        <div class="knx-empty-state">
          <i class="fas fa-box-open fa-2x" style="color:#9ca3af;margin-bottom:8px;"></i>
          <div>No groups yet. Add one or use the library.</div>
        </div>`;
      return;
    }
    modifiersList.innerHTML = state.modifiers.map(renderCard).join("");
    wireCardEvents();
  }

  function renderCard(mod){
    const optionsHTML = (mod.options && mod.options.length)
      ? `<div class="knx-options-list">${mod.options.map(renderOption).join("")}</div>`
      : `<div class="knx-options-list"></div>`;

    return `
      <div class="knx-modifier-card" data-id="${mod.id}">
        <div class="knx-modifier-card-header" role="button" tabindex="0" aria-expanded="true">
          <div class="knx-h-left">
            <button class="knx-chevron-btn" data-action="collapse" aria-label="Collapse group" title="Collapse">
              <i class="fas fa-chevron-up"></i>
            </button>
            <div class="knx-title-wrap">
              <h4>${esc(mod.name)}</h4>
              <div class="knx-meta">${esc(metaTextPlain(mod))}</div>
            </div>
          </div>

          <div class="knx-h-right">
            <div class="knx-actions-grid">
              <button class="knx-icon-btn" data-action="add-option" title="Add option" aria-label="Add option"><i class="fas fa-plus"></i></button>
              <button class="knx-icon-btn" data-action="edit"       title="Edit group" aria-label="Edit group"><i class="fas fa-pen"></i></button>
              <button class="knx-icon-btn danger" data-action="delete" title="Delete group" aria-label="Delete group"><i class="fas fa-trash"></i></button>


              <button class="knx-icon-btn" data-action="sort-up" title="Move up" aria-label="Move up"><i class="fas fa-chevron-up"></i></button>
              <button class="knx-icon-btn" data-action="sort-down" title="Move down" aria-label="Move down"><i class="fas fa-chevron-down"></i></button>
            </div>
          </div>
        </div>
        ${optionsHTML}
      </div>`;
  }

  function renderOption(opt){
    return `
      <div class="knx-option-item" data-option-id="${opt.id}">
        <div class="knx-option-name">${esc(opt.name)}</div>
        <div class="knx-option-price">${priceTextUSD(opt.price_adjustment)}</div>
        <div class="knx-option-actions">
          <button class="knx-icon-btn" data-action="edit-option" title="Edit option" aria-label="Edit option"><i class="fas fa-pen"></i></button>
          <button class="knx-icon-btn danger" data-action="delete-option" title="Delete option" aria-label="Delete option"><i class="fas fa-trash"></i></button>
        </div>
      </div>`;
  }

  function wireCardEvents(){
    document.querySelectorAll(".knx-modifier-card").forEach(card=>{
      const id   = parseInt(card.dataset.id,10);
      const list = card.querySelector(".knx-options-list");
      const hdr  = card.querySelector(".knx-modifier-card-header");
      const btn  = card.querySelector('[data-action="collapse"]');
      requestAnimationFrame(()=>{ list.style.maxHeight = list.scrollHeight + "px"; });

      const toggle = (e)=>{
        // no toggle cuando el click fue en acciones
        if (e && e.target.closest(".knx-actions-grid")) return;
        const collapsed = card.classList.toggle("is-collapsed");
        const icon = btn.querySelector("i");
        if (collapsed){
          icon.className = "fas fa-chevron-down";
          list.style.maxHeight = "0px";
          hdr.setAttribute("aria-expanded","false");
        }else{
          icon.className = "fas fa-chevron-up";
          list.style.maxHeight = list.scrollHeight + "px";
          hdr.setAttribute("aria-expanded","true");
        }
      };

      hdr.addEventListener("click", toggle);
      hdr.addEventListener("keydown", (e)=>{ if(e.key==="Enter"||e.key===" "){ e.preventDefault(); toggle(e); } });
      btn.addEventListener("click", (e)=>{ e.stopPropagation(); toggle(e); });

      card.querySelector('[data-action="add-option"]').addEventListener("click",(e)=>{ e.stopPropagation(); openOptionModal(id); });
      card.querySelector('[data-action="edit"]').addEventListener("click",(e)=>{ e.stopPropagation(); const m=state.modifiers.find(x=>+x.id===id); openModifierModal(m); });
      card.querySelector('[data-action="delete"]').addEventListener("click",(e)=>{ e.stopPropagation(); deleteModifier(id); });

      const up   = card.querySelector('[data-action="sort-up"]');
      const down = card.querySelector('[data-action="sort-down"]');
      up.addEventListener("click",   (e)=>{ e.stopPropagation(); reorderModifier(id, "up"); });
      down.addEventListener("click", (e)=>{ e.stopPropagation(); reorderModifier(id, "down"); });

      card.querySelectorAll('[data-action="edit-option"]').forEach(b=>{
        b.addEventListener("click",(e)=>{
          const optId = +e.currentTarget.closest(".knx-option-item").dataset.optionId;
          const mod   = state.modifiers.find(m=>+m.id===id);
          const opt   = (mod.options||[]).find(o=>+o.id===optId);
          openOptionModal(id, opt);
        });
      });
      card.querySelectorAll('[data-action="delete-option"]').forEach(b=>{
        b.addEventListener("click",(e)=>{
          const optId = +e.currentTarget.closest(".knx-option-item").dataset.optionId;
          deleteOption(optId);
        });
      });
    });
  }

  /* =========================
     Image + Save item
  ========================= */
  imageInput.addEventListener("change",(e)=>{
    const f = e.target.files[0]; if(!f) return;
    const rd = new FileReader();
    rd.onload = (ev)=> setPreview(ev.target.result);
    rd.readAsDataURL(f);
  });

  // Status change listener - update preview in real-time
  statusSelect.addEventListener("change", (e)=>{
    updatePreviewStatus(e.target.value);
  });

  document.getElementById("knxEditItemForm").addEventListener("submit", async (e)=>{
    e.preventDefault();
    const fd = new FormData();
    fd.append("hub_id", state.hubId);
    fd.append("id", state.itemId);
    fd.append("name", nameInput.value.trim());
    fd.append("description", descInput.value.trim());
    fd.append("category_id", catSelect.value);
    fd.append("price", priceInput.value.trim());
    fd.append("status", statusSelect.value);
    fd.append("knx_nonce", state.nonce);
    if (imageInput.files.length) fd.append("item_image", imageInput.files[0]);

    try{
      const r = await fetch(api.update,{ method:"POST", body:fd });
      const j = await r.json();
      j.success ? toast("Item updated") : toast(j.error||"Error updating","error");
    }catch{ toast("Network error","error"); }
  });

  document.getElementById("knxBrowseGlobalBtn")?.addEventListener("click", openGlobalLibrary);
  document.getElementById("knxAddModifierBtn")?.addEventListener("click", ()=> openModifierModal(null));

  /* =========================
     Modals (Group / Option)
  ========================= */
  function openModifierModal(mod){
    const isEdit = !!mod;
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
              <input id="mmName" value="${isEdit ? esc(mod.name) : ""}" required>
            </div>
            <div class="knx-form-group">
              <label>Type</label>
              <select id="mmType">
                <option value="single" ${isEdit && mod.type==="single" ? "selected":""}>Single</option>
                <option value="multiple" ${isEdit && mod.type==="multiple" ? "selected":""}>Multiple</option>
              </select>
            </div>
          </div>

          <div class="knx-form-row">
            <label><input type="checkbox" id="mmRequired" ${isEdit && mod.required==1 ? "checked":""}> Required</label>
            <label><input type="checkbox" id="mmGlobal" ${isEdit && mod.is_global==1 ? "checked":""}> <i class="fas fa-globe"></i> Make this global</label>
          </div>

          <div class="knx-form-row" id="mmMultiRow" style="display:${isEdit && mod.type==="multiple" ? "grid":"none"}">
            <div class="knx-form-group"><label>Min</label><input type="number" id="mmMin" min="0" value="${isEdit ? (mod.min_selection||0) : 0}"></div>
            <div class="knx-form-group"><label>Max</label><input type="number" id="mmMax" min="1" value="${isEdit && mod.max_selection ? mod.max_selection : ""}"></div>
          </div>

          <div class="knx-form-group" style="margin-top:8px;">
            <strong>Options</strong>
            <div id="mmOptions"></div>
            <div style="margin-top:8px;">
              <button type="button" class="knx-btn knx-btn-outline" id="mmAddOpt"><i class="fas fa-plus"></i> Add option</button>
            </div>
          </div>

          <div class="knx-modal-actions">
            <button class="knx-btn" type="submit"><i class="fas fa-save"></i> ${isEdit ? "Update" : "Create"}</button>
            <button type="button" class="knx-btn-secondary knx-modal-close">Cancel</button>
          </div>
        </form>
      </div>`;
    document.body.appendChild(modal);

    const close = ()=> modal.remove();
    modal.addEventListener("click",(e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll(".knx-modal-close").forEach(b=> b.addEventListener("click", close));

    const mmType = modal.querySelector("#mmType");
    const mmMultiRow = modal.querySelector("#mmMultiRow");
    mmType.addEventListener("change", ()=> mmMultiRow.style.display = mmType.value==="multiple" ? "grid" : "none");

    const list = modal.querySelector("#mmOptions");
    const addRow = (o)=>{
      const row = document.createElement("div");
      row.className = "knx-option-row";
      row.dataset.optId = o?.id || "";
      row.innerHTML = `
        <div class="knx-opt-drag"><i class="fas fa-grip-vertical"></i></div>
        <input type="text" class="mmOptName" placeholder="Option name" value="${o ? esc(o.name) : ""}">
        <input type="number" step="0.01" class="mmOptPrice" value="${o ? (o.price_adjustment || 0) : 0}">
        <button type="button" class="knx-icon-btn danger mmDel" title="Remove"><i class="fas fa-trash"></i></button>`;
      row.querySelector(".mmDel").addEventListener("click", ()=> row.remove());
      list.appendChild(row);
    };

    if (isEdit && mod.options) mod.options.forEach(addRow);
    modal.querySelector("#mmAddOpt").addEventListener("click", ()=> addRow(null));

    modal.querySelector("#mmForm").addEventListener("submit", async (e)=>{
      e.preventDefault();
      const name = modal.querySelector("#mmName").value.trim();
      const type = mmType.value;
      const required = modal.querySelector("#mmRequired").checked ? 1 : 0;
      const isGlobal = modal.querySelector("#mmGlobal").checked ? 1 : 0;
      const minSel = type==="multiple" ? (parseInt(modal.querySelector("#mmMin").value)||0) : 0;
      const maxSel = type==="multiple" && modal.querySelector("#mmMax").value ? parseInt(modal.querySelector("#mmMax").value) : null;

      if (!name) { toast("Name is required","error"); return; }

      const payload = {
        id: isEdit ? mod.id : 0,
        item_id: isGlobal ? null : state.itemId,
        hub_id: state.hubId,
        name, type, required,
        min_selection: minSel, max_selection: maxSel,
        is_global: isGlobal, knx_nonce: state.nonce,
      };

      try{
        const r = await fetch(api.saveMod,{ method:"POST", headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        const j = await r.json();
        if (!j.success){ toast(j.error||"Error saving group","error"); return; }
        const savedId = j.id || j.ID || (mod && mod.id);

        // Guardar opciones (sin is_default)
        const rows = Array.from(list.querySelectorAll(".knx-option-row"));
        const origIds = isEdit && mod.options ? mod.options.map(o=>+o.id) : [];
        const curIds  = rows.map(r=> r.dataset.optId ? +r.dataset.optId : 0).filter(Boolean);
        const toDel   = origIds.filter(id=> !curIds.includes(id));

        for (const id of toDel){
          await fetch(api.delOpt,{ method:"POST", headers:{'Content-Type':'application/json'}, body:JSON.stringify({ id, knx_nonce: state.nonce }) });
        }
        for (const row of rows){
          const oId    = row.dataset.optId ? +row.dataset.optId : 0;
          const oName  = row.querySelector(".mmOptName").value.trim();
          const oPrice = parseFloat(row.querySelector(".mmOptPrice").value)||0;
          if (!oName) continue;
          await fetch(api.saveOpt,{ method:"POST", headers:{'Content-Type':'application/json'}, body:JSON.stringify({
            id:oId, modifier_id:savedId, name:oName, price_adjustment:oPrice, knx_nonce: state.nonce
          })});
        }

        toast(isEdit ? "Group updated" : "Group created");
        close(); await loadModifiers();
      }catch{ toast("Network error","error"); }
    });
  }

  function openOptionModal(modifierId, option){
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

          <div class="knx-modal-actions">
            <button class="knx-btn" type="submit"><i class="fas fa-save"></i> ${isEdit ? "Update" : "Create"}</button>
            <button class="knx-btn-secondary knx-modal-close" type="button">Cancel</button>
          </div>
        </form>
      </div>`;
    document.body.appendChild(modal);

    const close = ()=> modal.remove();
    modal.addEventListener("click",(e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll(".knx-modal-close").forEach(b=> b.addEventListener("click", close));

    modal.querySelector("#optForm").addEventListener("submit", async (e)=>{
      e.preventDefault();
      const name  = modal.querySelector("#opName").value.trim();
      const price = parseFloat(modal.querySelector("#opPrice").value)||0;
      if (!name){ toast("Name is required","error"); return; }

      try{
        const r = await fetch(api.saveOpt,{ method:"POST", headers:{'Content-Type':'application/json'}, body:JSON.stringify({
          id: isEdit ? option.id : 0, modifier_id: modifierId, name, price_adjustment: price, knx_nonce: state.nonce
        })});
        const j = await r.json();
        if (j.success){ toast(isEdit ? "Option updated" : "Option created"); close(); loadModifiers(); }
        else toast(j.error || "Error saving option","error");
      }catch{ toast("Network error","error"); }
    });
  }

  async function reorderModifier(id, direction){
    try{
      const r = await fetch(api.reMod,{
        method:"POST",
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({ id, direction, knx_nonce: state.nonce })
      });
      const j = await r.json();
      if (j.success){ modifiersList.style.opacity="0.5"; setTimeout(async()=>{ await loadModifiers(); modifiersList.style.opacity="1"; }, 140); toast("Order updated"); }
      else toast(j.error || "Reorder failed","error");
    }catch{ toast("Network error","error"); }
  }

  async function deleteModifier(id){
    knxConfirm("Delete this group?","This will delete the group and its options.", async ()=>{
      try{
        const r = await fetch(api.delMod,{ method:"POST", headers:{'Content-Type':'application/json'}, body:JSON.stringify({ id, knx_nonce: state.nonce }) });
        const j = await r.json();
        j.success ? (toast("Group deleted"), loadModifiers()) : toast(j.error || "Delete failed","error");
      }catch{ toast("Network error","error"); }
    });
  }

  async function deleteOption(id){
    knxConfirm("Delete this option?","This action cannot be undone.", async ()=>{
      try{
        const r = await fetch(api.delOpt,{ method:"POST", headers:{'Content-Type':'application/json'}, body:JSON.stringify({ id, knx_nonce: state.nonce }) });
        const j = await r.json();
        j.success ? (toast("Option deleted"), loadModifiers()) : toast(j.error || "Delete failed","error");
      }catch{ toast("Network error","error"); }
    });
  }

  /* =========================
     Global library
  ========================= */
  async function openGlobalLibrary(){
    try{
      const r = await fetch(`${api.globals}?hub_id=${state.hubId}`);
      const j = await r.json();

      const inner = (!j.success || !j.modifiers || !j.modifiers.length)
        ? `<div class="knx-global-empty" style="padding:24px;text-align:center;color:#6b7280">
             <i class="fas fa-box-open fa-2x" style="color:#9ca3af;margin-bottom:10px;"></i>
             <div>No global groups yet</div>
           </div>`
        : `
          <input id="knxGlobalSearch" class="knx-global-search" placeholder="Search groups…" style="margin:10px 14px 0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;width:calc(100% - 28px);">
          <div class="knx-global-library-list" style="padding-top:10px;">
            ${j.modifiers.map(m=>`
              <div class="knx-global-item" data-id="${m.id}">
                <div class="knx-global-head">
                  <div class="knx-global-title">
                    <h4>${esc(m.name)}</h4>
                    <div class="knx-global-meta">${esc(metaTextPlain(m))} • Used in ${m.usage_count||0} item${(m.usage_count||0)===1?"":"s"}</div>
                  </div>
                  <div class="knx-global-actions">
                    <button class="knx-icon-btn" data-act="edit"   title="Edit"><i class="fas fa-pen"></i></button>
                    <button class="knx-icon-btn danger" data-act="delete" title="Delete"><i class="fas fa-trash"></i></button>
                    <button class="knx-btn knx-btn-sm" data-act="add" style="padding:8px 14px;height:38px;"><i class="fas fa-plus"></i> Add</button>
                  </div>
                </div>
                ${(m.options&&m.options.length)?`
                  <div class="knx-global-options">
                    ${m.options.map(o=>`<div class="knx-global-line"><span>${esc(o.name)}</span><span>${priceTextUSD(o.price_adjustment)}</span></div>`).join("")}
                  </div>`:""}
              </div>`).join("")}
          </div>`;

      const modal = document.createElement("div");
      modal.className = "knx-modal-overlay";
      modal.innerHTML = `
        <div class="knx-modal-content knx-modal-lg">
          <div class="knx-modal-header">
            <h3><i class="fas fa-globe"></i> Global library</h3>
            <button class="knx-modal-close" aria-label="Close">&times;</button>
          </div>
          ${inner}
        </div>`;
      document.body.appendChild(modal);

      const close = ()=> modal.remove();
      modal.addEventListener("click",(e)=>{ if(e.target===modal) close(); });
      modal.querySelector(".knx-modal-close").addEventListener("click", close);

      // search (simple)
      modal.querySelector("#knxGlobalSearch")?.addEventListener("input",(e)=>{
        const q = e.target.value.toLowerCase();
        modal.querySelectorAll(".knx-global-item").forEach(el=>{
          const name = el.querySelector("h4")?.textContent.toLowerCase() || "";
          el.style.display = name.includes(q) ? "" : "none";
        });
      });

      modal.querySelectorAll(".knx-global-item").forEach(el=>{
        const id = +el.dataset.id;

        el.querySelector('[data-act="add"]')?.addEventListener("click", async ()=>{
          const btn = el.querySelector('[data-act="add"]');
          btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding…';
          try{
            const r = await fetch(api.clone,{ method:"POST", headers:{'Content-Type':'application/json'}, body:JSON.stringify({
              global_modifier_id:id, item_id: state.itemId, knx_nonce: state.nonce
            })});
            const j = await r.json();
            if (j.success){ toast("Group added to this item"); close(); loadModifiers(); }
            else if (j.error === "already_cloned"){ toast("This group already exists in this item","warning"); }
            else { toast(j.error || "Error adding","error"); }
          }catch{ toast("Network error","error"); }
          btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus"></i> Add';
        });

        el.querySelector('[data-act="edit"]')?.addEventListener("click", ()=>{
          const mod = (j.modifiers||[]).find(m=>+m.id===id);
          openModifierModal(mod);
        });

        el.querySelector('[data-act="delete"]')?.addEventListener("click", ()=>{
          knxConfirm("Delete this global group?","This will remove it from library.", async ()=>{
            try{
              const r = await fetch(api.delMod,{ method:"POST", headers:{'Content-Type':'application/json'}, body:JSON.stringify({ id, knx_nonce: state.nonce }) });
              const jj = await r.json();
              jj.success ? (toast("Global group deleted"), el.remove()) : toast(jj.error || "Error deleting","error");
            }catch{ toast("Network error","error"); }
          });
        });
      });

    }catch{ toast("Error loading global library","error"); }
  }
});
