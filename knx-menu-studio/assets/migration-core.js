/* ============================================================
   KNX Menu Studio — Migration Core
   Shared state · DOM refs · autosave · session · export · eye
   ============================================================ */
(function () {
  'use strict';

  if (window.KNX_MC && window.KNX_MC.__coreLoaded) return;

  const root = window.KNX_MC || (window.KNX_MC = {});
  root.__coreLoaded = true;

  const $ = s => document.querySelector(s);
  const $$ = s => document.querySelectorAll(s);

  const shell = $('.mc-shell');
  if (!shell) return;

  const dom = {
    ctxCategory: $('#ctx-category'),
    catDatalist: $('#cat-datalist'),
    btnUploadTop: $('#btn-upload-top'),
    btnNewItem: $('#btn-new-item'),
    btnExportTop: $('#btn-export-top'),
    btnResetWS: $('#btn-reset-workspace'),
    imageFileInput: $('#image-file-input'),

    builder: $('#mc-builder'),
    builderEmpty: $('#builder-empty'),
    builderContent: $('#builder-content'),
    builderItemName: $('#builder-item-name'),
    builderBasePrice: $('#builder-base-price'),
    builderDesc: $('#builder-description'),
    builderGroups: $('#builder-groups'),
    btnAddGroup: $('#btn-add-group'),
    btnAddToList: $('#btn-add-to-list'),
    btnClearItem: $('#btn-clear-item'),

    groupDraftEl: $('#group-draft'),
    draftGroupSummary: $('#draft-group-summary'),
    draftGroupName: $('#draft-group-name'),
    draftPillRequired: $('#draft-pill-required'),
    draftPillType: $('#draft-pill-type'),
    draftPillAction: $('#draft-pill-action'),
    btnGroupGuidedCapture: $('#btn-group-guided-capture'),
    btnCancelGroup: $('#btn-cancel-group'),
    btnShowRawOcr: $('#btn-show-raw-ocr'),
    draftOptionName: $('#draft-option-name'),
    draftOptionPrice: $('#draft-option-price'),
    btnQuickFill: $('#btn-quick-fill'),
    btnAddOption: $('#btn-add-option'),
    draftOptionsGrid: $('#draft-options-grid'),
    btnCommitGroup: $('#btn-commit-group'),

    sessionTitle: $('#session-title'),
    sessionEmpty: $('#session-empty'),
    sessionTable: $('#session-table'),
    sessionTbody: $('#session-tbody'),
    sessionSearch: $('#session-filter-search'),
    btnExportBottom: $('#btn-export-bottom'),

    imgBubble: $('#img-bubble'),
    bubbleMini: $('#bubble-mini'),
    bubbleMiniImg: $('#bubble-mini-img'),
    bubbleExpanded: $('#bubble-expanded'),
    bubbleExpandedImg: $('#bubble-expanded-img'),
    bubbleExpandedBody: $('#bubble-expanded-body'),
    bubbleExpandedFooter: $('#bubble-expanded-footer'),
    bubbleImageRail: $('#bubble-image-rail'),
    bubbleImageCounter: $('#bubble-image-counter'),
    bubbleStage: $('#bubble-stage'),
    ocrCanvas: $('#ocr-canvas'),
    ocrHint: $('#ocr-hint'),
    ocrPanel: $('#ocr-capture-panel'),
    ocrPanelText: $('#ocr-capture-text'),
    btnOcrPanelClose: $('#btn-ocr-panel-close'),
    btnBubbleAddMore: $('#btn-bubble-add-more'),
    btnBubbleReplace: $('#btn-bubble-replace'),
    btnBubbleMinimize: $('#btn-bubble-minimize'),
    btnBubbleClose: $('#btn-bubble-close'),
    btnGuideSkip: $('#btn-guide-skip'),
    guideActions: $('#ocr-guide-actions'),
    guideStep: $('#ocr-guide-step'),

    eyeOverlay: $('#eye-preview-overlay'),
    eyeBody: $('#eye-preview-body'),
    btnEyeClose: $('#btn-eye-close'),

    toast: $('#mc-toast'),
    autosaveBadge: $('#mc-autosave-badge'),
  };

  const ajaxUrl = shell.dataset.ajaxUrl || '';
  const nonce = shell.dataset.nonce || '';

  const state = {
    sessionItems: [],
    nextItemId: 1,
    nextGroupId: 1,
    nextOptionId: 1,

    currentDraft: null,
    groupDraft: null,

    categories: [],

    pickTarget: null,
    blockCaptureMode: false,
    blockCaptureForGroup: false,
    lastRawOcrText: '',

    currentImageUrl: '',
    currentImageUrls: [],
    activeImageIndex: 0,
    bubbleState: 'hidden',
    bubblePos: { x: 16, y: 80 },

    imageDisplayRect: null,

    ocrWorker: null,
    isOcrReady: false,

    guidedMode: null,
    guidedStep: null,

    autosaveTimer: null,
    badgeTimer: null,
    toastTimer: null,
  };

  const AUTOSAVE_KEY = 'knx_studio_mc_v4';

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function toast(msg) {
    if (!dom.toast) return;
    dom.toast.textContent = msg;
    dom.toast.classList.add('visible');
    clearTimeout(state.toastTimer);
    state.toastTimer = setTimeout(() => {
      dom.toast.classList.remove('visible');
    }, 2600);
  }

  function showAutosaveBadge() {
    if (!dom.autosaveBadge) return;
    dom.autosaveBadge.classList.add('visible');
    clearTimeout(state.badgeTimer);
    state.badgeTimer = setTimeout(() => {
      dom.autosaveBadge.classList.remove('visible');
    }, 1800);
  }

  function getCategory() {
    return (dom.ctxCategory && dom.ctxCategory.value ? dom.ctxCategory.value : '').trim();
  }

  function getActiveImageUrl() {
    if (!Array.isArray(state.currentImageUrls) || !state.currentImageUrls.length) {
      return state.currentImageUrl || '';
    }

    const idx = Math.max(0, Math.min(state.activeImageIndex, state.currentImageUrls.length - 1));
    return state.currentImageUrls[idx] || '';
  }

  function freshItemDraft() {
    return {
      id: state.nextItemId++,
      category: getCategory(),
      name: '',
      description: '',
      basePrice: '',
      groups: [],
      imageUrl: getActiveImageUrl() || '',
      imageUrls: Array.isArray(state.currentImageUrls) ? state.currentImageUrls.slice() : [],
    };
  }

  function freshGroupDraft() {
    return {
      name: '',
      action: 'add',
      required: '0',
      type: 'multiple',
      options: [],
    };
  }

  function itemStatus(item) {
    if (!item.name) return { label: 'No name', cls: 'warn' };
    if (!item.category) return { label: 'No category', cls: 'warn' };
    if (!item.basePrice || item.basePrice === '0.00') return { label: 'No price', cls: 'warn' };
    if (!item.groups.length) return { label: 'No groups', cls: 'info' };
    return { label: 'Complete', cls: 'ok' };
  }

  function csvCell(v) {
    const s = String(v == null ? '' : v);
    return (s.includes(',') || s.includes('"') || s.includes('\n'))
      ? '"' + s.replace(/"/g, '""') + '"'
      : s;
  }

  function updateCatDatalist() {
    if (!dom.catDatalist) return;
    dom.catDatalist.innerHTML = state.categories.map(c => (
      '<option value="' + escAttr(c) + '">'
    )).join('');
  }

  function onCategoryChangeUI() {
    const hasCategory = getCategory().length > 0;
    if (dom.btnUploadTop) dom.btnUploadTop.disabled = !hasCategory;
    if (dom.btnNewItem) dom.btnNewItem.disabled = !hasCategory;
  }

  function onCategoryChange() {
    const cat = getCategory();
    const hasCategory = cat.length > 0;

    if (hasCategory && !state.categories.includes(cat)) {
      state.categories.push(cat);
      updateCatDatalist();
    }

    if (dom.btnUploadTop) dom.btnUploadTop.disabled = !hasCategory;
    if (dom.btnNewItem) dom.btnNewItem.disabled = !hasCategory;

    if (hasCategory && !state.currentDraft && typeof root.startNewItem === 'function') {
      root.startNewItem();
    }

    if (state.currentDraft) {
      state.currentDraft.category = cat;
    }

    syncAll();
    scheduleAutosave();
  }

  function renderSessionList() {
    if (!dom.sessionTitle || !dom.sessionEmpty || !dom.sessionTable || !dom.sessionTbody) return;

    const search = (dom.sessionSearch && dom.sessionSearch.value ? dom.sessionSearch.value : '').trim().toLowerCase();

    const filtered = search
      ? state.sessionItems.filter(it =>
          (it.name || '').toLowerCase().includes(search) ||
          (it.category || '').toLowerCase().includes(search)
        )
      : state.sessionItems;

    dom.sessionTitle.textContent = 'Session — ' + state.sessionItems.length + ' item' + (state.sessionItems.length !== 1 ? 's' : '');

    if (!filtered.length) {
      dom.sessionEmpty.style.display = '';
      dom.sessionTable.style.display = 'none';
      return;
    }

    dom.sessionEmpty.style.display = 'none';
    dom.sessionTable.style.display = '';

    dom.sessionTbody.innerHTML = filtered.map(it => {
      const optCount = it.groups.reduce((sum, g) => sum + g.options.length, 0);
      const groupsText = it.groups.length + ' group' + (it.groups.length !== 1 ? 's' : '') + ' · ' + optCount + ' opt';
      const status = itemStatus(it);
      const imageCount = Array.isArray(it.imageUrls) ? it.imageUrls.length : (it.imageUrl ? 1 : 0);

      return '<tr data-session-item-id="' + it.id + '">'
        + '<td><input class="mc-session-table__editable" data-field="category" value="' + escAttr(it.category) + '"></td>'
        + '<td><input class="mc-session-table__editable" data-field="name" value="' + escAttr(it.name) + '"></td>'
        + '<td><input class="mc-session-table__editable mc-session-table__editable--desc" data-field="description" value="' + escAttr(it.description || '') + '" placeholder="—"></td>'
        + '<td><input class="mc-session-table__editable" data-field="basePrice" value="' + escAttr(it.basePrice) + '" style="width:76px;" inputmode="decimal"></td>'
        + '<td><span class="mc-session-table__groups-count">' + groupsText + '</span></td>'
        + '<td><span class="mc-session-table__groups-count">' + imageCount + '</span></td>'
        + '<td><span class="mc-session-table__status mc-session-table__status--' + status.cls + '">' + status.label + '</span></td>'
        + '<td><div class="mc-session-table__action-btns">'
        + '<button class="mc-session-table__eye" data-eye-item="' + it.id + '" title="Preview">👁</button>'
        + '<button class="mc-session-table__delete" data-delete-item="' + it.id + '" title="Delete">×</button>'
        + '</div></td>'
        + '</tr>';
    }).join('');
  }

  function deleteSessionItem(id) {
    state.sessionItems = state.sessionItems.filter(it => it.id !== id);
    renderSessionList();
    syncAll();
    scheduleAutosave();
    toast('Item removed from session.');
  }

  function openEyePreview(itemId) {
    const item = state.sessionItems.find(it => it.id === itemId);
    if (!item || !dom.eyeBody || !dom.eyeOverlay) return;

    let html = '<div class="mc-modal-dialog">';
    html += '<div class="mc-modal-header-card">';
    html += '<div class="mc-modal-header-card__row"><input class="mc-modal-title-input" value="' + escAttr(item.name) + '" readonly></div>';

    if (item.description) {
      html += '<div class="mc-modal-desc-row"><textarea class="mc-modal-desc-input" readonly>' + esc(item.description) + '</textarea></div>';
    }

    html += '<div class="mc-modal-price-row"><span class="mc-modal-price-label">$</span><input class="mc-modal-price-input" value="' + escAttr(item.basePrice) + '" readonly></div>';
    html += '</div>';

    item.groups.forEach(g => {
      const reqBadge = g.required === '1'
        ? '<span class="mc-modal-group-badge mc-modal-group-badge--required">1 Required</span>'
        : '<span class="mc-modal-group-badge mc-modal-group-badge--type">Optional</span>';

      const typeBadge = '<span class="mc-modal-group-badge mc-modal-group-badge--type">' + esc(g.type) + '</span>';

      html += '<section class="mc-modal-group"><div class="mc-modal-group-header">';
      html += '<h3 class="mc-modal-group-title">' + esc(g.name) + '</h3>';
      html += '<div class="mc-modal-group-meta">' + reqBadge + typeBadge + '</div></div>';
      html += '<div class="mc-modal-options">';

      g.options.forEach(opt => {
        const isRemove = opt.action === 'remove';
        const removeCls = isRemove ? ' mc-modal-option--remove' : '';
        const ctaText = isRemove ? 'Tap to remove' : 'Tap to add';
        const hasPrice = parseFloat(opt.price) > 0 || (!isRemove && String(opt.price) === '0.00');
        const priceText = hasPrice && !isRemove ? ' +$' + (parseFloat(opt.price) || 0).toFixed(2) : '';

        html += '<div class="mc-modal-option' + removeCls + '">';
        html += '<span class="mc-modal-option-label">' + esc(opt.name) + '</span>';
        html += '<span class="mc-modal-option-cta">' + ctaText + '<strong class="mc-modal-option-cta-price">' + esc(priceText) + '</strong></span>';
        html += '</div>';
      });

      html += '</div></section>';
    });

    html += '</div>';

    dom.eyeBody.innerHTML = html;
    dom.eyeOverlay.style.display = '';
  }

  function closeEyePreview() {
    if (!dom.eyeOverlay || !dom.eyeBody) return;
    dom.eyeOverlay.style.display = 'none';
    dom.eyeBody.innerHTML = '';
  }

  const CSV_COLS = [
    'category_name',
    'item_name',
    'item_description',
    'group_name',
    'group_required',
    'group_type',
    'option_name',
    'option_price',
    'option_action'
  ];

  function exportCSV() {
    if (!state.sessionItems.length) {
      toast('No items to export.');
      return;
    }

    const rows = [];

    state.sessionItems.forEach(item => {
      if (!item.groups.length) {
        rows.push({
          category_name: item.category,
          item_name: item.name,
          item_description: item.description || '',
          group_name: '',
          group_required: '',
          group_type: '',
          option_name: '',
          option_price: item.basePrice,
          option_action: '',
        });
      } else {
        item.groups.forEach(group => {
          group.options.forEach(opt => {
            rows.push({
              category_name: item.category,
              item_name: item.name,
              item_description: item.description || '',
              group_name: group.name,
              group_required: group.required,
              group_type: group.type,
              option_name: opt.name,
              option_price: opt.price,
              option_action: opt.action,
            });
          });
        });
      }
    });

    if (!rows.length) {
      toast('Nothing to export.');
      return;
    }

    const header = CSV_COLS.join(',');
    const body = rows.map(r => CSV_COLS.map(c => csvCell(r[c])).join(',')).join('\n');
    const csv = header + '\n' + body + '\n';

    const slug = s => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    const dateStr = new Date().toISOString().slice(0, 10);
    const filename = 'migration-' + slug(getCategory() || 'export') + '-' + dateStr + '.csv';

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');

    a.href = url;
    a.download = filename;
    a.style.display = 'none';

    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    toast('Exported ' + rows.length + ' rows → ' + filename);
  }

  function scheduleAutosave() {
    clearTimeout(state.autosaveTimer);
    state.autosaveTimer = setTimeout(doAutosave, 800);
  }

  function sanitizeSessionItems(items) {
    return (Array.isArray(items) ? items : []).map(item => ({
      id: item.id,
      category: item.category || '',
      name: item.name || '',
      description: item.description || '',
      basePrice: item.basePrice || '',
      groups: Array.isArray(item.groups) ? item.groups.map(group => ({
        id: group.id,
        name: group.name || '',
        action: group.action || 'add',
        required: group.required || '0',
        type: group.type || 'multiple',
        options: Array.isArray(group.options) ? group.options.map(opt => ({
          id: opt.id,
          name: opt.name || '',
          price: opt.price || '0.00',
          action: opt.action || 'add',
        })) : [],
      })) : [],
    }));
  }

  function doAutosave() {
    try {
      const payload = {
        sessionItems: sanitizeSessionItems(state.sessionItems),
        categories: state.categories,
        currentDraft: state.currentDraft,
        currentImageUrl: state.currentImageUrl,
        currentImageUrls: Array.isArray(state.currentImageUrls) ? state.currentImageUrls.slice() : [],
        activeImageIndex: state.activeImageIndex,
        nextItemId: state.nextItemId,
        nextGroupId: state.nextGroupId,
        nextOptionId: state.nextOptionId,
        categoryInput: dom.ctxCategory ? dom.ctxCategory.value : '',
      };

      localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(payload));
      showAutosaveBadge();
    } catch (e) {}
  }

  function restoreCountersFromItems(items) {
    items.forEach(item => {
      state.nextItemId = Math.max(state.nextItemId, (item.id || 0) + 1);

      (item.groups || []).forEach(g => {
        state.nextGroupId = Math.max(state.nextGroupId, (g.id || 0) + 1);

        (g.options || []).forEach(o => {
          state.nextOptionId = Math.max(state.nextOptionId, (o.id || 0) + 1);
        });
      });
    });
  }

  function tryRestore() {
    try {
      const raw = localStorage.getItem(AUTOSAVE_KEY);
      if (!raw) return false;

      const data = JSON.parse(raw);

      if (Array.isArray(data.categories) && data.categories.length) {
        state.categories = data.categories;
        updateCatDatalist();
      }

      if (data.categoryInput && dom.ctxCategory) {
        dom.ctxCategory.value = data.categoryInput;
      }

      if (Array.isArray(data.sessionItems) && data.sessionItems.length) {
        state.sessionItems = sanitizeSessionItems(data.sessionItems);
        restoreCountersFromItems(state.sessionItems);
      }

      if (data.currentDraft) {
        state.currentDraft = data.currentDraft;
        state.nextItemId = Math.max(state.nextItemId, (state.currentDraft.id || 0) + 1);

        if (dom.builderEmpty) dom.builderEmpty.style.display = 'none';
        if (dom.builderContent) dom.builderContent.style.display = '';
        if (dom.builderItemName) dom.builderItemName.value = state.currentDraft.name || '';
        if (dom.builderBasePrice) dom.builderBasePrice.value = state.currentDraft.basePrice || '';
        if (dom.builderDesc) dom.builderDesc.value = state.currentDraft.description || '';

        restoreCountersFromItems([state.currentDraft]);

        if (typeof root.renderBuilderGroups === 'function') {
          root.renderBuilderGroups();
        }
      }

      if (Array.isArray(data.currentImageUrls) && data.currentImageUrls.length) {
        state.currentImageUrls = data.currentImageUrls.slice(0, 5);
        state.activeImageIndex = Math.max(0, Math.min(Number(data.activeImageIndex || 0), state.currentImageUrls.length - 1));
        state.currentImageUrl = getActiveImageUrl();

        if (typeof root.syncActiveImageToUi === 'function') {
          root.syncActiveImageToUi();
        } else {
          if (dom.bubbleMiniImg) dom.bubbleMiniImg.src = state.currentImageUrl;
          if (dom.bubbleExpandedImg) dom.bubbleExpandedImg.src = state.currentImageUrl;
        }

        if (typeof root.showBubbleMini === 'function') {
          root.showBubbleMini();
        }
      } else if (data.currentImageUrl) {
        state.currentImageUrl = data.currentImageUrl;
        state.currentImageUrls = [data.currentImageUrl];
        state.activeImageIndex = 0;

        if (typeof root.syncActiveImageToUi === 'function') {
          root.syncActiveImageToUi();
        } else {
          if (dom.bubbleMiniImg) dom.bubbleMiniImg.src = state.currentImageUrl;
          if (dom.bubbleExpandedImg) dom.bubbleExpandedImg.src = state.currentImageUrl;
        }

        if (typeof root.showBubbleMini === 'function') {
          root.showBubbleMini();
        }
      }

      if (data.nextItemId) state.nextItemId = Math.max(state.nextItemId, data.nextItemId);
      if (data.nextGroupId) state.nextGroupId = Math.max(state.nextGroupId, data.nextGroupId);
      if (data.nextOptionId) state.nextOptionId = Math.max(state.nextOptionId, data.nextOptionId);

      return true;
    } catch (e) {
      return false;
    }
  }

  function resetWorkspace() {
    if (!state.sessionItems.length && !state.currentDraft) {
      toast('Workspace is already empty.');
      return;
    }

    if (!confirm('Reset entire workspace? This will clear all items, drafts, and local memory.')) {
      return;
    }

    state.sessionItems = [];
    state.currentDraft = null;
    state.groupDraft = null;
    state.categories = [];
    state.currentImageUrl = '';
    state.currentImageUrls = [];
    state.activeImageIndex = 0;
    state.nextItemId = 1;
    state.nextGroupId = 1;
    state.nextOptionId = 1;
    state.pickTarget = null;
    state.blockCaptureMode = false;
    state.blockCaptureForGroup = false;
    state.lastRawOcrText = '';
    state.guidedMode = null;
    state.guidedStep = null;
    state.imageDisplayRect = null;

    if (dom.ctxCategory) dom.ctxCategory.value = '';
    if (dom.builderEmpty) dom.builderEmpty.style.display = '';
    if (dom.builderContent) dom.builderContent.style.display = 'none';
    if (dom.builderItemName) dom.builderItemName.value = '';
    if (dom.builderDesc) dom.builderDesc.value = '';
    if (dom.builderBasePrice) dom.builderBasePrice.value = '';
    if (dom.builderGroups) dom.builderGroups.innerHTML = '';

    if (typeof root.closeGroupDraft === 'function') root.closeGroupDraft(true);
    if (typeof root.hideBubble === 'function') root.hideBubble();
    if (typeof root.cancelGuidedCapture === 'function') root.cancelGuidedCapture(false);

    renderSessionList();
    syncAll();
    updateCatDatalist();

    localStorage.removeItem(AUTOSAVE_KEY);
    toast('Workspace reset.');
  }

  function syncAddToListBtn() {
    const hasName = ((dom.builderItemName && dom.builderItemName.value) || '').trim().length > 0;
    const hasCat = getCategory().length > 0;

    if (dom.btnAddToList) {
      dom.btnAddToList.disabled = !(hasName && hasCat && state.currentDraft);
    }
  }

  function syncAll() {
    const hasItems = state.sessionItems.length > 0;

    if (dom.btnExportTop) dom.btnExportTop.disabled = !hasItems;
    if (dom.btnExportBottom) dom.btnExportBottom.disabled = !hasItems;

    syncAddToListBtn();
    onCategoryChangeUI();
  }

  function initCore() {
    if (dom.ctxCategory) {
      dom.ctxCategory.addEventListener('input', onCategoryChange);
      dom.ctxCategory.addEventListener('change', onCategoryChange);
    }

    if (dom.btnExportTop) dom.btnExportTop.addEventListener('click', exportCSV);
    if (dom.btnExportBottom) dom.btnExportBottom.addEventListener('click', exportCSV);
    if (dom.btnResetWS) dom.btnResetWS.addEventListener('click', resetWorkspace);

    if (dom.sessionTbody) {
      dom.sessionTbody.addEventListener('input', e => {
        if (!e.target.classList.contains('mc-session-table__editable')) return;

        const tr = e.target.closest('tr');
        const item = state.sessionItems.find(it => it.id === +(tr && tr.dataset.sessionItemId));

        if (item) {
          item[e.target.dataset.field] = e.target.value;
          scheduleAutosave();
        }
      });

      dom.sessionTbody.addEventListener('click', e => {
        const eyeId = e.target.dataset.eyeItem;
        if (eyeId !== undefined) openEyePreview(+eyeId);

        const delId = e.target.dataset.deleteItem;
        if (delId !== undefined) deleteSessionItem(+delId);
      });
    }

    if (dom.sessionSearch) {
      dom.sessionSearch.addEventListener('input', renderSessionList);
    }

    if (dom.btnEyeClose) {
      dom.btnEyeClose.addEventListener('click', closeEyePreview);
    }

    const eyeBackdrop = dom.eyeOverlay ? dom.eyeOverlay.querySelector('.mc-eye-overlay__backdrop') : null;
    if (eyeBackdrop) {
      eyeBackdrop.addEventListener('click', closeEyePreview);
    }

    window.addEventListener('beforeunload', e => {
      if (
        state.sessionItems.length > 0 ||
        (state.currentDraft && (state.currentDraft.name || state.currentDraft.groups.length > 0))
      ) {
        e.preventDefault();
        e.returnValue = '';
      }
    });

    const restored = tryRestore();
    if (restored) {
      renderSessionList();
      syncAll();

      if (typeof root.updateItemPreview === 'function') {
        root.updateItemPreview();
      }

      toast('Draft restored.');
    } else {
      syncAll();
    }
  }

  root.$ = $;
  root.$$ = $$;
  root.dom = dom;
  root.state = state;
  root.ajaxUrl = ajaxUrl;
  root.nonce = nonce;
  root.AUTOSAVE_KEY = AUTOSAVE_KEY;

  root.esc = esc;
  root.escAttr = escAttr;
  root.toast = toast;
  root.showAutosaveBadge = showAutosaveBadge;
  root.scheduleAutosave = scheduleAutosave;
  root.doAutosave = doAutosave;

  root.getCategory = getCategory;
  root.getActiveImageUrl = getActiveImageUrl;
  root.updateCatDatalist = updateCatDatalist;
  root.onCategoryChange = onCategoryChange;
  root.onCategoryChangeUI = onCategoryChangeUI;

  root.freshItemDraft = freshItemDraft;
  root.freshGroupDraft = freshGroupDraft;
  root.itemStatus = itemStatus;

  root.renderSessionList = renderSessionList;
  root.deleteSessionItem = deleteSessionItem;
  root.openEyePreview = openEyePreview;
  root.closeEyePreview = closeEyePreview;

  root.csvCell = csvCell;
  root.exportCSV = exportCSV;

  root.tryRestore = tryRestore;
  root.resetWorkspace = resetWorkspace;
  root.sanitizeSessionItems = sanitizeSessionItems;

  root.syncAddToListBtn = syncAddToListBtn;
  root.syncAll = syncAll;

  root.initCore = initCore;
})();