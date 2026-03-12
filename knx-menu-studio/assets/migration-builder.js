/* ============================================================
   KNX Menu Studio — Migration Builder
   Live KNX item builder + premium group draft + group rendering
   ============================================================ */
(function () {
  'use strict';

  const root = window.KNX_MC;
  if (!root || root.__builderLoaded) return;
  root.__builderLoaded = true;

  const dom = root.dom;
  const state = root.state;
  const toast = root.toast;
  const esc = root.esc;
  const escAttr = root.escAttr;
  const getCategory = root.getCategory;
  const freshItemDraft = root.freshItemDraft;
  const freshGroupDraft = root.freshGroupDraft;
  const scheduleAutosave = root.scheduleAutosave;
  const renderSessionList = root.renderSessionList;
  const syncAll = root.syncAll;
  const syncAddToListBtn = root.syncAddToListBtn;

  function startNewItem() {
    state.currentDraft = freshItemDraft();

    if (dom.builderEmpty) dom.builderEmpty.style.display = 'none';
    if (dom.builderContent) dom.builderContent.style.display = '';

    if (dom.builderItemName) dom.builderItemName.value = '';
    if (dom.builderBasePrice) dom.builderBasePrice.value = '';
    if (dom.builderDesc) dom.builderDesc.value = '';
    if (dom.builderGroups) dom.builderGroups.innerHTML = '';

    closeGroupDraft(true);

    if (typeof root.updateItemPreview === 'function') {
      root.updateItemPreview();
    }

    syncAddToListBtn();
  }

  function clearCurrentItem() {
    if (state.currentDraft && state.currentDraft.groups.length > 0) {
      if (!confirm('Clear current item and all its groups?')) return;
    }

    state.currentDraft = freshItemDraft();

    if (dom.builderItemName) dom.builderItemName.value = '';
    if (dom.builderBasePrice) dom.builderBasePrice.value = '';
    if (dom.builderDesc) dom.builderDesc.value = '';
    if (dom.builderGroups) dom.builderGroups.innerHTML = '';

    closeGroupDraft(true);

    if (typeof root.updateItemPreview === 'function') {
      root.updateItemPreview();
    }

    syncAddToListBtn();
    scheduleAutosave();
  }

  function addItemToList() {
    if (!state.currentDraft) return;

    const cat = getCategory();
    const name = dom.builderItemName ? dom.builderItemName.value.trim() : '';

    if (!cat) {
      toast('Select a category first.');
      return;
    }

    if (!name) {
      toast('Enter an item name.');
      if (dom.builderItemName) dom.builderItemName.focus();
      return;
    }

    state.currentDraft.category = cat;
    state.currentDraft.name = name;
    state.currentDraft.description = dom.builderDesc ? dom.builderDesc.value.trim() : '';
    state.currentDraft.basePrice = dom.builderBasePrice ? (dom.builderBasePrice.value.trim() || '0.00') : '0.00';
    state.currentDraft.imageUrl = state.currentImageUrl || '';
    state.currentDraft.imageUrls = (state.currentImageUrls || []).slice();

    state.sessionItems.push(state.currentDraft);

    const addedName = state.currentDraft.name;
    const groupCount = state.currentDraft.groups.length;

    startNewItem();
    renderSessionList();
    syncAll();

    toast('"' + addedName + '" added — ' + groupCount + ' group' + (groupCount !== 1 ? 's' : '') + '.');
    scheduleAutosave();
  }

  function openGroupDraft(skipCapture) {
    if (!state.currentDraft) {
      startNewItem();
    }

    state.groupDraft = freshGroupDraft();

    if (dom.groupDraftEl) dom.groupDraftEl.classList.add('active');
    if (dom.btnAddGroup) dom.btnAddGroup.style.display = 'none';

    if (dom.draftGroupName) dom.draftGroupName.value = '';
    if (dom.draftOptionName) dom.draftOptionName.value = '';
    if (dom.draftOptionPrice) dom.draftOptionPrice.value = '';
    if (dom.draftOptionsGrid) dom.draftOptionsGrid.innerHTML = '';
    if (dom.btnCommitGroup) dom.btnCommitGroup.disabled = true;

    if (dom.btnShowRawOcr) dom.btnShowRawOcr.style.display = 'none';
    state.lastRawOcrText = '';

    if (typeof root.hideOcrPanel === 'function') {
      root.hideOcrPanel();
    }

    resetChipGroup('action', 'add');
    resetChipGroup('required', '0');
    resetChipGroup('type', 'multiple');

    syncDraftHeroMeta();
    syncDraftSummary();

    if (state.currentImageUrl && !skipCapture) {
      if (typeof root.startGuidedGroupCapture === 'function') {
        root.startGuidedGroupCapture();
      } else if (typeof root.showBubbleExpanded === 'function') {
        root.showBubbleExpanded();
      }
    } else {
      if (dom.draftGroupName) dom.draftGroupName.focus();
    }

    scheduleAutosave();
  }

  function closeGroupDraft(silent) {
    if (typeof root.cancelGuidedCapture === 'function' && state.guidedMode === 'group') {
      root.cancelGuidedCapture(false);
    }

    state.groupDraft = null;

    if (dom.groupDraftEl) dom.groupDraftEl.classList.remove('active');
    if (dom.btnAddGroup) dom.btnAddGroup.style.display = '';

    if (dom.btnShowRawOcr) dom.btnShowRawOcr.style.display = 'none';
    state.lastRawOcrText = '';

    if (typeof root.hideOcrPanel === 'function') {
      root.hideOcrPanel();
    }

    if (state.blockCaptureMode && state.blockCaptureForGroup) {
      if (typeof root.deactivateBlockCapture === 'function') {
        root.deactivateBlockCapture();
      }
    }

    if (!silent) {
      scheduleAutosave();
    }
  }

  function commitGroup() {
    if (!state.groupDraft || state.groupDraft.options.length === 0) return;
    if (!state.currentDraft) return;

    const gName = dom.draftGroupName ? dom.draftGroupName.value.trim() : '';

    if (!gName) {
      toast('Enter a group name.');
      if (dom.draftGroupName) dom.draftGroupName.focus();
      return;
    }

    const group = {
      id: state.nextGroupId++,
      name: gName,
      action: state.groupDraft.action,
      required: state.groupDraft.required,
      type: state.groupDraft.type,
      options: state.groupDraft.options.map(opt => ({
        id: state.nextOptionId++,
        name: opt.name,
        price: (parseFloat(opt.price) || 0).toFixed(2),
        action: opt.action,
      })),
    };

    state.currentDraft.groups.push(group);

    closeGroupDraft(true);
    renderBuilderGroups();
    syncAddToListBtn();

    toast('Group "' + gName + '" added — ' + group.options.length + ' options.');
    scheduleAutosave();
  }

  function addOptionToDraft() {
    if (!state.groupDraft) return;

    const name = dom.draftOptionName ? dom.draftOptionName.value.trim() : '';
    if (!name) {
      toast('Enter option name.');
      if (dom.draftOptionName) dom.draftOptionName.focus();
      return;
    }

    const priceRaw = dom.draftOptionPrice ? dom.draftOptionPrice.value.trim() : '';
    const price = (parseFloat(priceRaw) || 0).toFixed(2);

    state.groupDraft.options.push({
      name,
      price,
      action: state.groupDraft.action,
    });

    if (dom.draftOptionName) dom.draftOptionName.value = '';
    if (dom.draftOptionPrice) dom.draftOptionPrice.value = state.groupDraft.action === 'remove' ? '0.00' : '';
    if (dom.draftOptionName) dom.draftOptionName.focus();

    renderDraftOptionsGrid();
    if (dom.btnCommitGroup) dom.btnCommitGroup.disabled = false;

    syncDraftSummary();
    scheduleAutosave();
  }

  function removeDraftOption(idx) {
    if (!state.groupDraft) return;

    state.groupDraft.options.splice(idx, 1);
    renderDraftOptionsGrid();

    if (dom.btnCommitGroup) {
      dom.btnCommitGroup.disabled = state.groupDraft.options.length === 0;
    }

    syncDraftSummary();
    scheduleAutosave();
  }

  function syncDraftHeroMeta() {
    if (!state.groupDraft) return;

    if (dom.draftPillRequired) {
      dom.draftPillRequired.textContent = state.groupDraft.required === '1' ? 'Required' : 'Optional';
    }

    if (dom.draftPillType) {
      dom.draftPillType.textContent = state.groupDraft.type === 'single' ? 'Single' : 'Multi';
    }

    if (dom.draftPillAction) {
      const isRemove = state.groupDraft.action === 'remove';
      dom.draftPillAction.textContent = isRemove ? 'Remove' : 'Add';
      dom.draftPillAction.classList.toggle('mc-group-draft__meta-pill--remove', isRemove);
      dom.draftPillAction.classList.toggle('mc-group-draft__meta-pill--add', !isRemove);
    }
  }

  function syncDraftSummary() {
    if (!dom.draftGroupSummary) return;

    if (!state.groupDraft) {
      dom.draftGroupSummary.textContent = 'Start with the group title, then add options.';
      return;
    }

    const count = state.groupDraft.options.length;
    const mode = state.groupDraft.action === 'remove' ? 'remove' : 'add';
    const req = state.groupDraft.required === '1' ? 'required' : 'optional';
    const type = state.groupDraft.type === 'single' ? 'single' : 'multi';

    dom.draftGroupSummary.textContent =
      count + ' option' + (count !== 1 ? 's' : '') + ' · ' + req + ' · ' + type + ' · ' + mode;
  }

  function renderDraftOptionsGrid() {
    if (!dom.draftOptionsGrid) return;

    if (!state.groupDraft) {
      dom.draftOptionsGrid.innerHTML = '';
      return;
    }

    if (!state.groupDraft.options.length) {
      dom.draftOptionsGrid.innerHTML =
        '<div class="mc-draft-options-empty">No options yet. Add the first option above.</div>';
      syncDraftHeroMeta();
      syncDraftSummary();
      return;
    }

    dom.draftOptionsGrid.innerHTML = state.groupDraft.options.map((opt, i) => {
      const price = (parseFloat(opt.price) || 0).toFixed(2);
      const isRemove = opt.action === 'remove';
      const actionClass = isRemove ? ' mc-draft-option-card--remove' : '';
      const actionLabel = isRemove ? 'remove' : 'add';
      const ctaText = isRemove ? 'Tap to remove' : 'Tap to add';

      return `
        <div class="mc-draft-option-card${actionClass}" data-draft-opt-idx="${i}">
          <div class="mc-draft-option-card__top">
            <span class="mc-draft-option-card__badge">${actionLabel}</span>
            <button class="mc-draft-option-card__delete" data-remove-draft-opt="${i}" title="Delete option">×</button>
          </div>

          <div class="mc-draft-option-card__center">
            <div class="mc-draft-option-card__name-row">
              <input
                type="text"
                class="mc-draft-option-card__name-input"
                data-draft-opt-input="name"
                data-draft-opt-idx="${i}"
                value="${escAttr(opt.name)}"
                autocomplete="off">
              <button
                class="mc-pick-btn mc-pick-btn--sm"
                data-pick-target="draft-option-name:${i}"
                title="Pick option name from image">⎗</button>
            </div>

            <div class="mc-draft-option-card__cta">
              <span class="mc-draft-option-card__cta-label">${ctaText}</span>
              ${!isRemove ? `
                <div class="mc-draft-option-card__price-shell">
                  <span class="mc-draft-option-card__price-prefix">+$</span>
                  <input
                    type="text"
                    class="mc-draft-option-card__price-input"
                    data-draft-opt-input="price"
                    data-draft-opt-idx="${i}"
                    value="${escAttr(price)}"
                    inputmode="decimal"
                    autocomplete="off">
                  <button
                    class="mc-pick-btn mc-pick-btn--sm"
                    data-pick-target="draft-option-price:${i}"
                    title="Pick option price from image">⎗</button>
                </div>
              ` : ''}
            </div>
          </div>
        </div>
      `;
    }).join('');

    syncDraftHeroMeta();
    syncDraftSummary();
  }

  function renderBuilderGroups() {
    if (!dom.builderGroups) return;

    if (!state.currentDraft) {
      dom.builderGroups.innerHTML = '';
      return;
    }

    dom.builderGroups.innerHTML = state.currentDraft.groups.map(g => {
      const reqBadge = g.required === '1'
        ? '<span class="mc-modal-group-badge mc-modal-group-badge--required">1 Required</span>'
        : '<span class="mc-modal-group-badge mc-modal-group-badge--type">Optional</span>';

      const typeBadge = '<span class="mc-modal-group-badge mc-modal-group-badge--type">' + esc(g.type) + '</span>';

      const actionBadge = g.action === 'remove'
        ? '<span class="mc-modal-group-badge mc-modal-group-badge--action-remove">remove</span>'
        : '<span class="mc-modal-group-badge mc-modal-group-badge--action-add">add</span>';

      const optionsHtml = g.options.map(opt => {
        const isRemove = opt.action === 'remove';
        const removeCls = isRemove ? ' mc-modal-option--remove' : '';
        const ctaText = isRemove ? 'Tap to remove' : 'Tap to add';
        const hasPrice = parseFloat(opt.price) > 0 || (!isRemove && String(opt.price) === '0.00');
        const priceText = hasPrice && !isRemove ? ' +$' + (parseFloat(opt.price) || 0).toFixed(2) : '';

        return '<div class="mc-modal-option' + removeCls + '">'
          + '<span class="mc-modal-option-label">' + esc(opt.name) + '</span>'
          + '<span class="mc-modal-option-cta">' + ctaText + '<strong class="mc-modal-option-cta-price">' + esc(priceText) + '</strong></span>'
          + '</div>';
      }).join('');

      return '<section class="mc-modal-group" data-group-id="' + g.id + '">'
        + '<div class="mc-modal-group-header">'
        + '<h3 class="mc-modal-group-title">' + esc(g.name) + '</h3>'
        + '<div class="mc-modal-group-meta">' + reqBadge + typeBadge + actionBadge
        + '<button class="mc-modal-group-delete" data-delete-group="' + g.id + '" title="Remove group">×</button>'
        + '</div></div>'
        + '<div class="mc-modal-options">' + optionsHtml + '</div>'
        + '</section>';
    }).join('');
  }

  function deleteBuilderGroup(groupId) {
    if (!state.currentDraft) return;

    state.currentDraft.groups = state.currentDraft.groups.filter(g => g.id !== groupId);
    renderBuilderGroups();
    syncAddToListBtn();
    scheduleAutosave();
  }

  function initChips() {
    root.$$('[data-chip-group]').forEach(container => {
      const group = container.dataset.chipGroup;

      container.querySelectorAll('.mc-chip').forEach(chip => {
        chip.addEventListener('click', () => {
          container.querySelectorAll('.mc-chip').forEach(c => {
            c.className = 'mc-chip';
          });

          if (group === 'action') {
            chip.classList.add(chip.dataset.value === 'add' ? 'mc-chip--active-add' : 'mc-chip--active-remove');
          } else {
            chip.classList.add('mc-chip--active');
          }

          if (state.groupDraft) {
            state.groupDraft[group] = chip.dataset.value;
          }

          if (group === 'action' && state.groupDraft && state.groupDraft.action === 'remove') {
            if (dom.draftOptionPrice) dom.draftOptionPrice.value = '0.00';

            state.groupDraft.options.forEach(opt => {
              opt.action = 'remove';
              opt.price = '0.00';
            });
          }

          if (group === 'action' && state.groupDraft && state.groupDraft.action === 'add') {
            state.groupDraft.options.forEach(opt => {
              opt.action = 'add';
            });
          }

          syncDraftHeroMeta();
          syncDraftSummary();
          renderDraftOptionsGrid();
          scheduleAutosave();
        });
      });
    });
  }

  function resetChipGroup(group, value) {
    const container = root.$('[data-chip-group="' + group + '"]');
    if (!container) return;

    container.querySelectorAll('.mc-chip').forEach(c => {
      c.className = 'mc-chip';

      if (c.dataset.value === value) {
        if (group === 'action') {
          c.classList.add(value === 'add' ? 'mc-chip--active-add' : 'mc-chip--active-remove');
        } else {
          c.classList.add('mc-chip--active');
        }
      }
    });
  }

  function initBuilder() {
    initChips();

    if (dom.builderItemName) {
      dom.builderItemName.addEventListener('input', () => {
        if (state.currentDraft) state.currentDraft.name = dom.builderItemName.value.trim();
        syncAddToListBtn();
        scheduleAutosave();
      });
    }

    if (dom.builderBasePrice) {
      dom.builderBasePrice.addEventListener('input', () => {
        if (state.currentDraft) state.currentDraft.basePrice = dom.builderBasePrice.value.trim();
        scheduleAutosave();
      });
    }

    if (dom.builderDesc) {
      dom.builderDesc.addEventListener('input', () => {
        if (state.currentDraft) state.currentDraft.description = dom.builderDesc.value.trim();
        scheduleAutosave();
      });
    }

    if (dom.btnAddGroup) {
      dom.btnAddGroup.addEventListener('click', () => openGroupDraft(false));
    }

    if (dom.btnGroupGuidedCapture) {
      dom.btnGroupGuidedCapture.addEventListener('click', () => {
        if (!state.groupDraft) openGroupDraft(true);

        if (!state.currentImageUrl) {
          toast('Upload an image first.');
          return;
        }

        if (typeof root.startGuidedGroupCapture === 'function') {
          root.startGuidedGroupCapture();
        } else if (typeof root.showBubbleExpanded === 'function') {
          root.showBubbleExpanded();
        }
      });
    }

    if (dom.btnCancelGroup) {
      dom.btnCancelGroup.addEventListener('click', () => closeGroupDraft(false));
    }

    if (dom.draftGroupName) {
      dom.draftGroupName.addEventListener('input', () => {
        if (state.groupDraft) state.groupDraft.name = dom.draftGroupName.value.trim();
        syncDraftSummary();
        scheduleAutosave();
      });
    }

    if (dom.btnQuickFill) {
      dom.btnQuickFill.addEventListener('click', () => {
        if (dom.draftOptionPrice) dom.draftOptionPrice.value = '0.00';
        if (dom.draftOptionName) dom.draftOptionName.focus();
      });
    }

    if (dom.btnAddOption) dom.btnAddOption.addEventListener('click', addOptionToDraft);

    if (dom.draftOptionName) {
      dom.draftOptionName.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          e.preventDefault();
          addOptionToDraft();
        }
      });
    }

    if (dom.draftOptionPrice) {
      dom.draftOptionPrice.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          e.preventDefault();
          addOptionToDraft();
        }
      });
    }

    if (dom.btnCommitGroup) {
      dom.btnCommitGroup.addEventListener('click', commitGroup);
    }

    if (dom.draftOptionsGrid) {
      dom.draftOptionsGrid.addEventListener('click', e => {
        const idx = e.target.dataset.removeDraftOpt;
        if (idx !== undefined) removeDraftOption(+idx);
      });

      dom.draftOptionsGrid.addEventListener('input', e => {
        const idx = parseInt(e.target.dataset.draftOptIdx, 10);
        const field = e.target.dataset.draftOptInput;

        if (Number.isNaN(idx) || !state.groupDraft || !state.groupDraft.options[idx]) return;

        if (field === 'name') {
          state.groupDraft.options[idx].name = e.target.value;
        }

        if (field === 'price') {
          state.groupDraft.options[idx].price = e.target.value;
        }

        syncDraftSummary();
        scheduleAutosave();
      });
    }

    if (dom.btnShowRawOcr && typeof root.toggleRawOcrPanel === 'function') {
      dom.btnShowRawOcr.addEventListener('click', root.toggleRawOcrPanel);
    }

    if (dom.builderGroups) {
      dom.builderGroups.addEventListener('click', e => {
        const gid = e.target.dataset.deleteGroup;
        if (gid !== undefined) deleteBuilderGroup(+gid);
      });
    }

    if (dom.btnAddToList) dom.btnAddToList.addEventListener('click', addItemToList);
    if (dom.btnClearItem) dom.btnClearItem.addEventListener('click', clearCurrentItem);

    if (dom.btnNewItem) {
      dom.btnNewItem.addEventListener('click', () => {
        if (state.currentDraft && (state.currentDraft.name || state.currentDraft.groups.length > 0)) {
          if (!confirm('Start a new item? Current unsaved item will be lost.')) return;
        }

        startNewItem();
        toast('New item started.');
      });
    }

    syncDraftHeroMeta();
    syncDraftSummary();
  }

  root.startNewItem = startNewItem;
  root.clearCurrentItem = clearCurrentItem;
  root.addItemToList = addItemToList;

  root.openGroupDraft = openGroupDraft;
  root.closeGroupDraft = closeGroupDraft;
  root.commitGroup = commitGroup;

  root.addOptionToDraft = addOptionToDraft;
  root.removeDraftOption = removeDraftOption;
  root.renderDraftOptionsGrid = renderDraftOptionsGrid;
  root.syncDraftHeroMeta = syncDraftHeroMeta;
  root.syncDraftSummary = syncDraftSummary;

  root.renderBuilderGroups = renderBuilderGroups;
  root.deleteBuilderGroup = deleteBuilderGroup;

  root.initChips = initChips;
  root.resetChipGroup = resetChipGroup;
  root.initBuilder = initBuilder;
})();