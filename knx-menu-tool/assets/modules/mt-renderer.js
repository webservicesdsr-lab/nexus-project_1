window.KNXMT_Renderer = (() => {
  function renderItemPicker(refs, items, currentItemId) {
    refs.itemPicker.innerHTML = '';

    items.forEach((item, index) => {
      const opt = document.createElement('option');
      opt.value = item.id;

      const frozenLabel = item.status === 'frozen' ? ' [Frozen]' : '';
      opt.textContent = `${index + 1}. ${item.title || 'Untitled Item'}${frozenLabel}`;

      if (item.id === currentItemId) {
        opt.selected = true;
      }

      refs.itemPicker.appendChild(opt);
    });
  }

  function renderCurrentItemSummary(refs, item, getStatusMeta, U) {
    if (!refs.currentSummary || !refs.currentItemStatusChip || !refs.currentItemCopy) return;

    if (!item) {
      refs.currentItemStatusChip.textContent = 'No item';
      refs.currentItemStatusChip.className = 'knxmt-chip knxmt-chip-warn';
      refs.currentItemCopy.textContent = 'Select or create an item to begin.';
      refs.currentSummary.innerHTML = '<div class="knxmt-current-summary-empty">Waiting for item data...</div>';
      return;
    }

    const statusMeta = getStatusMeta(item.status);
    refs.currentItemStatusChip.textContent = statusMeta.label;
    refs.currentItemStatusChip.className = `knxmt-chip ${statusMeta.className}`;
    refs.currentItemCopy.textContent = statusMeta.copy;

    const groupCount = Array.isArray(item.groups) ? item.groups.length : 0;
    const optionCount = (item.groups || []).reduce((sum, group) => sum + ((group.options || []).length), 0);

    refs.currentSummary.innerHTML = `
      <div class="knxmt-current-item-card">
        <div class="knxmt-current-item-top">
          <div class="knxmt-current-item-title">${U.escapeHtml(item.title || 'Untitled Item')}</div>
          <div class="knxmt-current-item-price">$${U.formatMoneyDisplay(item.base_price)}</div>
        </div>

        <div class="knxmt-card-copy">${U.escapeHtml(item.description || 'No description yet.')}</div>

        <div class="knxmt-current-item-meta">
          <span class="knxmt-chip knxmt-chip-muted">${groupCount} group${groupCount === 1 ? '' : 's'}</span>
          <span class="knxmt-chip knxmt-chip-muted">${optionCount} option${optionCount === 1 ? '' : 's'}</span>
          <span class="knxmt-chip knxmt-chip-muted">${item.image?.cropped_asset_id ? 'Cropped image ready' : 'No cropped image'}</span>
        </div>
      </div>
    `;
  }

  function renderFrozenCollection(refs, frozenItems, U) {
    if (!refs.frozenList || !refs.frozenCollectionCount) return;

    refs.frozenCollectionCount.textContent = `${frozenItems.length} frozen`;

    if (!frozenItems.length) {
      refs.frozenList.innerHTML = '<div class="knxmt-card-copy">No frozen items yet.</div>';
      return;
    }

    refs.frozenList.innerHTML = frozenItems.map((item, index) => {
      const snapshot = item.frozen_snapshot || {};
      const groupCount = Array.isArray(snapshot.groups) ? snapshot.groups.length : 0;
      const optionCount = (snapshot.groups || []).reduce((sum, group) => sum + ((group.options || []).length), 0);

      return `
        <div class="knxmt-frozen-item">
          <div class="knxmt-frozen-item-main">
            <div class="knxmt-frozen-item-title">${index + 1}. ${U.escapeHtml(snapshot.title || item.title || 'Untitled Item')}</div>
            <div class="knxmt-frozen-item-sub">${U.escapeHtml(snapshot.description || item.description || 'No description.')}</div>
            <div class="knxmt-actions-row knxmt-actions-row-tight">
              <span class="knxmt-chip knxmt-chip-info">Frozen</span>
              <span class="knxmt-chip knxmt-chip-muted">${groupCount} group${groupCount === 1 ? '' : 's'}</span>
              <span class="knxmt-chip knxmt-chip-muted">${optionCount} option${optionCount === 1 ? '' : 's'}</span>
            </div>
          </div>
          <div class="knxmt-frozen-item-side">
            <div class="knxmt-frozen-item-price">$${U.formatMoneyDisplay(snapshot.base_price || 0)}</div>
            <div class="knxmt-frozen-item-sub">${U.formatFrozenDate(item.frozen_at)}</div>
          </div>
        </div>
      `;
    }).join('');
  }

  function hydrateEditor(refs, item, U) {
    refs.title.value = item?.title || '';
    refs.basePrice.value = item ? U.formatMoneyInput(item.base_price) : '';
    refs.description.value = item?.description || '';
    refs.specialInstructions.checked = !!item?.globals?.special_instructions_allowed;
    refs.ocrText.value = item?.parser_meta?.ocr_text || '';
  }

  function renderWarnings(refs, items) {
    refs.warnings.innerHTML = '';

    (items || []).forEach((entry) => {
      const div = document.createElement('div');
      div.className = `knxmt-warning${entry.danger ? ' knxmt-warning-danger' : ''}`;
      div.textContent = entry.text;
      refs.warnings.appendChild(div);
    });
  }

  function setCropChip(refs, text, mode) {
    refs.cropChip.textContent = text;
    refs.cropChip.className = 'knxmt-chip';
    if (mode === 'warn') refs.cropChip.classList.add('knxmt-chip-warn');
    if (mode === 'danger') refs.cropChip.classList.add('knxmt-chip-danger');
  }

  function hydrateImage(refs, item, state, getAssetById) {
    const croppedAssetId = item?.image?.cropped_asset_id || null;
    const originalAssetId = item?.image?.original_asset_id || null;

    refs.previewWrap.hidden = true;
    refs.previewImage.removeAttribute('src');
    refs.openCropBtn.disabled = !originalAssetId && !state.currentImageOriginalBlob;
    refs.clearImageBtn.disabled = !originalAssetId && !croppedAssetId && !state.currentImageOriginalBlob;
    refs.runOcrParseBtn.disabled = !croppedAssetId;
    setCropChip(refs, croppedAssetId ? 'Cropped' : 'Needs crop', croppedAssetId ? 'ok' : 'warn');

    if (!item) return;

    if (croppedAssetId) {
      getAssetById(croppedAssetId).then((asset) => {
        if (!asset) return;
        const url = URL.createObjectURL(asset.blob);
        refs.previewWrap.hidden = false;
        refs.previewImage.src = url;
      });
      return;
    }

    if (originalAssetId) {
      getAssetById(originalAssetId).then((asset) => {
        if (!asset) return;
        const url = URL.createObjectURL(asset.blob);
        refs.previewWrap.hidden = false;
        refs.previewImage.src = url;
      });
    }
  }

  function renderGroups(refs, item, api, U) {
    refs.groupsWrap.innerHTML = '';

    if (!item) {
      refs.groupsWrap.innerHTML = '<div class="knx-mt-empty-groups">No active item.</div>';
      return;
    }

    item.groups = U.normalizeGroups(item.groups || []);
    const groups = item.groups;

    if (!groups.length) {
      refs.groupsWrap.innerHTML = '<div class="knx-mt-empty-groups">No groups yet. Add one below.</div>';
      return;
    }

    groups.forEach((group, groupIndex) => {
      refs.groupsWrap.appendChild(renderGroupCard(group, groupIndex, api, U));
    });
  }

  function renderGroupCard(group, groupIndex, api, U) {
    const card = document.createElement('div');
    card.className = 'knx-mt-group-card';

    const header = renderGroupHeader(group, groupIndex, api, U);
    const optionsWrap = renderOptions(group.options || [], groupIndex, api, U);
    const addOptionRow = document.createElement('div');
    addOptionRow.className = 'knx-mt-add-option-row';

    const addOptionBtn = document.createElement('button');
    addOptionBtn.type = 'button';
    addOptionBtn.className = 'knxmt-btn knxmt-btn-ghost';
    addOptionBtn.textContent = '+ Add Option';
    addOptionBtn.addEventListener('click', async () => {
      await api.addOptionToGroup(groupIndex);
    });

    addOptionRow.appendChild(addOptionBtn);

    card.appendChild(header);
    card.appendChild(optionsWrap);
    card.appendChild(addOptionRow);

    return card;
  }

  function renderGroupHeader(group, groupIndex, api, U) {
    const header = document.createElement('div');
    header.className = 'knx-mt-group-header';

    const main = document.createElement('div');
    main.className = 'knx-mt-group-header-main';

    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'knxmt-input knx-mt-group-name-input';
    nameInput.value = group.name || '';
    nameInput.placeholder = 'Group name';
    nameInput.addEventListener('input', () => {
      api.updateGroupName(groupIndex, nameInput.value);
    });

    const meta = document.createElement('div');
    meta.className = 'knx-mt-group-meta';

    const requiredField = document.createElement('label');
    requiredField.className = 'knxmt-inline-control';

    const requiredCheckbox = document.createElement('input');
    requiredCheckbox.type = 'checkbox';
    requiredCheckbox.checked = !!group.required;
    requiredCheckbox.addEventListener('change', () => {
      api.updateGroupRequired(groupIndex, requiredCheckbox.checked);
    });

    const requiredText = document.createElement('span');
    requiredText.textContent = 'Required';

    requiredField.appendChild(requiredCheckbox);
    requiredField.appendChild(requiredText);

    const minField = document.createElement('div');
    minField.className = 'knxmt-inline-field';

    const minLabel = document.createElement('span');
    minLabel.className = 'knxmt-label';
    minLabel.textContent = 'Min';

    const minInput = document.createElement('input');
    minInput.type = 'number';
    minInput.min = '0';
    minInput.step = '1';
    minInput.className = 'knxmt-input knxmt-inline-number';
    minInput.value = U.safeInt(group.min_selection, 0);
    minInput.addEventListener('input', () => {
      api.updateGroupMin(groupIndex, minInput.value);
    });

    minField.appendChild(minLabel);
    minField.appendChild(minInput);

    const maxField = document.createElement('div');
    maxField.className = 'knxmt-inline-field';

    const maxLabel = document.createElement('span');
    maxLabel.className = 'knxmt-label';
    maxLabel.textContent = 'Max';

    const maxInput = document.createElement('input');
    maxInput.type = 'number';
    maxInput.min = '0';
    maxInput.step = '1';
    maxInput.className = 'knxmt-input knxmt-inline-number';
    maxInput.placeholder = '∞';
    maxInput.value = group.max_selection === null || group.max_selection === '' ? '' : U.safeInt(group.max_selection, 1);
    maxInput.addEventListener('input', () => {
      api.updateGroupMax(groupIndex, maxInput.value);
    });

    maxField.appendChild(maxLabel);
    maxField.appendChild(maxInput);

    const typeField = document.createElement('div');
    typeField.className = 'knxmt-inline-field knxmt-inline-field-type';

    const typeLabel = document.createElement('span');
    typeLabel.className = 'knxmt-label';
    typeLabel.textContent = 'Type';

    const typeSelect = document.createElement('select');
    typeSelect.className = 'knxmt-select knxmt-inline-select';
    typeSelect.innerHTML = `
      <option value="single"${group.type === 'single' ? ' selected' : ''}>single</option>
      <option value="multiple"${group.type === 'multiple' ? ' selected' : ''}>multiple</option>
    `;
    typeSelect.addEventListener('change', () => {
      api.updateGroupType(groupIndex, typeSelect.value);
    });

    typeField.appendChild(typeLabel);
    typeField.appendChild(typeSelect);

    const ruleChip = document.createElement('span');
    ruleChip.className = `knxmt-chip ${group.required ? '' : 'knxmt-chip-muted'}`;
    ruleChip.textContent = U.formatRuleLabel(group);

    meta.appendChild(requiredField);
    meta.appendChild(minField);
    meta.appendChild(maxField);
    meta.appendChild(typeField);
    meta.appendChild(ruleChip);

    main.appendChild(nameInput);
    main.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'knx-mt-group-actions';

    const duplicateBtn = document.createElement('button');
    duplicateBtn.type = 'button';
    duplicateBtn.className = 'knxmt-mini-btn';
    duplicateBtn.textContent = 'Duplicate';
    duplicateBtn.addEventListener('click', async () => {
      await api.duplicateGroup(groupIndex);
    });

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'knxmt-mini-btn';
    deleteBtn.textContent = 'Delete';
    deleteBtn.addEventListener('click', async () => {
      await api.deleteGroup(groupIndex);
    });

    actions.appendChild(duplicateBtn);
    actions.appendChild(deleteBtn);

    header.appendChild(main);
    header.appendChild(actions);

    return header;
  }

  function renderOptions(options, groupIndex, api, U) {
    const wrap = document.createElement('div');
    wrap.className = 'knx-mt-options';

    const normalizedOptions = Array.isArray(options) ? options : [];

    if (!normalizedOptions.length) {
      const empty = document.createElement('div');
      empty.className = 'knx-mt-empty-groups';
      empty.textContent = 'No options yet. Add one below.';
      wrap.appendChild(empty);
      return wrap;
    }

    normalizedOptions.forEach((option, optionIndex) => {
      wrap.appendChild(renderOptionCard(option, groupIndex, optionIndex, api, U));
    });

    return wrap;
  }

  function renderOptionCard(option, groupIndex, optionIndex, api, U) {
    const card = document.createElement('div');
    card.className = 'knx-mt-option-card';

    const grid = document.createElement('div');
    grid.className = 'knx-mt-option-card-grid';

    const nameField = document.createElement('div');
    nameField.className = 'knxmt-field';

    const nameLabel = document.createElement('label');
    nameLabel.className = 'knxmt-label';
    nameLabel.textContent = 'Name';

    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'knxmt-input';
    nameInput.value = option.name || '';
    nameInput.placeholder = 'Option name';
    nameInput.addEventListener('input', () => {
      api.updateOptionName(groupIndex, optionIndex, nameInput.value);
    });

    nameField.appendChild(nameLabel);
    nameField.appendChild(nameInput);

    const priceField = document.createElement('div');
    priceField.className = 'knxmt-field';

    const priceLabel = document.createElement('label');
    priceLabel.className = 'knxmt-label';
    priceLabel.textContent = 'Price';

    const priceInput = document.createElement('input');
    priceInput.type = 'number';
    priceInput.step = '0.01';
    priceInput.className = 'knxmt-input';
    priceInput.value = U.formatMoneyInput(option.price_adjustment);
    priceInput.placeholder = '0.00';
    priceInput.addEventListener('input', () => {
      api.updateOptionPrice(groupIndex, optionIndex, U.safeNumber(priceInput.value));
    });

    priceField.appendChild(priceLabel);
    priceField.appendChild(priceInput);

    const actions = document.createElement('div');
    actions.className = 'knx-mt-option-actions';

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'knxmt-mini-btn';
    deleteBtn.textContent = 'Delete';
    deleteBtn.addEventListener('click', async () => {
      await api.deleteOptionFromGroup(groupIndex, optionIndex);
    });

    actions.appendChild(deleteBtn);

    grid.appendChild(nameField);
    grid.appendChild(priceField);
    grid.appendChild(actions);

    card.appendChild(grid);
    return card;
  }

  function renderKnxPreview(refs, item, U) {
    if (!refs.knxPreview) return;

    if (!item) {
      refs.knxPreview.innerHTML = '<div class="knxmt-knx-preview-empty">No active item to preview.</div>';
      return;
    }

    const title = U.escapeHtml(item.title || 'Untitled Item');
    const description = U.escapeHtml(item.description || 'No description yet.');
    const basePrice = U.formatMoneyDisplay(item.base_price || 0);
    const groups = U.normalizeGroups(item.groups || []);

    refs.knxPreview.innerHTML = `
      <div class="knxmt-knx-modal">
        <div class="knxmt-knx-image-wrap">
          ${item.image?.cropped_asset_id ? '<img class="knxmt-knx-image" id="knxmtKnxPreviewImage" alt="Item preview image">' : '<div class="knxmt-knx-image-placeholder">No image yet</div>'}
        </div>

        <div class="knxmt-knx-header-card">
          <div class="knxmt-knx-item-title">${title}</div>
          <div class="knxmt-knx-item-description">${description}</div>
          <div class="knxmt-knx-item-price">$ ${basePrice}</div>
        </div>

        <div class="knxmt-knx-groups">
          ${groups.length ? groups.map((group) => renderKnxGroup(group, U)).join('') : '<div class="knxmt-knx-empty-card">No groups yet.</div>'}
        </div>

        ${item?.globals?.special_instructions_allowed ? `
          <div class="knxmt-knx-special-card">
            <div class="knxmt-knx-special-title">Special instructions</div>
            <div class="knxmt-knx-special-box">e.g. No mayo</div>
          </div>
        ` : ''}
      </div>
    `;
  }

  function renderKnxGroup(group, U) {
    const label = U.formatRuleLabel(group);
    const ruleClass = group.required ? 'knxmt-knx-rule-required' : 'knxmt-knx-rule-optional';
    const options = Array.isArray(group.options) ? group.options : [];

    return `
      <div class="knxmt-knx-group-card">
        <div class="knxmt-knx-group-head">
          <div class="knxmt-knx-group-name">${U.escapeHtml(group.name || 'Options')}</div>
          <div class="knxmt-knx-rule-pill ${ruleClass}">${U.escapeHtml(label)}</div>
        </div>

        <div class="knxmt-knx-options-grid">
          ${options.map((option) => `
            <div class="knxmt-knx-option-card">
              <div class="knxmt-knx-option-name">${U.escapeHtml(option.name || 'Option')}</div>
              <div class="knxmt-knx-option-cta">Tap to select +$${U.formatMoneyDisplay(option.price_adjustment || 0)}</div>
            </div>
          `).join('')}
        </div>
      </div>
    `;
  }

  return {
    renderItemPicker,
    renderCurrentItemSummary,
    renderFrozenCollection,
    hydrateEditor,
    renderWarnings,
    setCropChip,
    hydrateImage,
    renderGroups,
    renderKnxPreview,
  };
})();