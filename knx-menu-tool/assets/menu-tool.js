document.addEventListener('DOMContentLoaded', () => {
  const app = document.getElementById('knxMenuToolApp');
  if (!app) return;

  const U = window.KNXMT_Utils;
  const DB = window.KNXMT_DB;
  const R = window.KNXMT_Renderer;
  const C = window.KNXMT_Crop;
  const O = window.KNXMT_OCR;
  const W = window.KNXMT_Workspace;
  const E = window.KNXMT_Export;
  const G = window.KNXMT_Groups;
  const UI = window.KNXMT_UI;

  const config = U.safeJson(app.dataset.config || '{}');

  const STORAGE = {
    DB_NAME: 'knx_menu_tool',
    DB_VERSION: 2,
    STORES: {
      batches: 'batches',
      items: 'items',
      assets: 'assets',
      meta: 'meta',
    },
  };

  const refs = {
    statusPill: U.byId('knxmtStatusPill'),
    frozenCounter: U.byId('knxmtFrozenCounter'),
    frozenCollectionCount: U.byId('knxmtFrozenCollectionCount'),
    currentItemStatusChip: U.byId('knxmtCurrentItemStatusChip'),
    currentItemCopy: U.byId('knxmtCurrentItemCopy'),
    currentSummary: U.byId('knxmtCurrentSummary'),
    frozenList: U.byId('knxmtFrozenList'),

    batchCountChip: U.byId('knxmtBatchCountChip'),
    batchName: U.byId('knxmtBatchName'),
    itemPicker: U.byId('knxmtItemPicker'),
    newBatchBtn: U.byId('knxmtNewBatchBtn'),
    addItemBtn: U.byId('knxmtAddItemBtn'),
    duplicateItemBtn: U.byId('knxmtDuplicateItemBtn'),
    deleteItemBtn: U.byId('knxmtDeleteItemBtn'),
    downloadCsvBtn: U.byId('knxmtDownloadCsvBtn'),

    imageInput: U.byId('knxmtImageInput'),
    previewWrap: U.byId('knxmtPreviewWrap'),
    previewImage: U.byId('knxmtPreviewImage'),
    openCropBtn: U.byId('knxmtOpenCropBtn'),
    runOcrParseBtn: U.byId('knxmtRunOcrParseBtn'),
    clearImageBtn: U.byId('knxmtClearImageBtn'),
    cropChip: U.byId('knxmtCropChip'),

    ocrText: U.byId('knxmtOcrText'),
    warnings: U.byId('knxmtWarnings'),

    title: U.byId('knxmtTitle'),
    basePrice: U.byId('knxmtBasePrice'),
    description: U.byId('knxmtDescription'),
    specialInstructions: U.byId('knxmtSpecialInstructions'),
    addGroupBtn: U.byId('knxmtAddGroupBtn'),
    addGroupSecondaryBtn: U.byId('knxmtAddGroupSecondaryBtn'),
    groupsWrap: U.byId('knxmtGroupsWrap'),

    freezeBtn: U.byId('knxmtFreezeBtn'),
    console: U.byId('knxmtConsole'),

    cropModal: U.byId('knxmtCropModal'),
    cropBackdrop: U.byId('knxmtCropBackdrop'),
    closeCropBtn: U.byId('knxmtCloseCropBtn'),
    resetCropBtn: U.byId('knxmtResetCropBtn'),
    confirmCropBtn: U.byId('knxmtConfirmCropBtn'),
    cropCanvas: U.byId('knxmtCropCanvas'),
  };

  const state = {
    db: null,
    workspace: null,
    items: [],
    currentItemId: null,
    currentImageOriginalBlob: null,
    currentImageOriginalUrl: '',
    cropSession: {
      image: null,
      rect: null,
      dragging: false,
      mode: null,
      startX: 0,
      startY: 0,
      handleSize: 16,
      scale: 1,
    },
    saveTimer: null,
    lastFrozenSnapshot: null,
  };

  const groupsCtx = {
    U,
    getCurrentItem,
    persistItem,
    hydrateUiFromState,
    queueSaveItem,
  };

  const rendererApi = {
    addOptionToGroup: (groupIndex) => G.addOptionToGroup(groupsCtx, groupIndex),
    deleteOptionFromGroup: (groupIndex, optionIndex) => G.deleteOptionFromGroup(groupsCtx, groupIndex, optionIndex),
    updateGroupName: (groupIndex, value) => G.updateGroupName(groupsCtx, groupIndex, value),
    duplicateGroup: (groupIndex) => G.duplicateGroup(groupsCtx, groupIndex),
    deleteGroup: (groupIndex) => G.deleteGroup(groupsCtx, groupIndex),
    updateOptionName: (groupIndex, optionIndex, value) => G.updateOptionName(groupsCtx, groupIndex, optionIndex, value),
    updateOptionPrice: (groupIndex, optionIndex, value) => G.updateOptionPrice(groupsCtx, groupIndex, optionIndex, value),
  };

  const workspaceCtx = {
    state,
    refs,
    DB,
    STORAGE,
    U,
    R,
    rendererApi,
    addItem,
    createFreshWorkspace,
    getStatusMeta,
    getFrozenItems,
    getFrozenSnapshots,
    refreshFrozenCounter,
    logConsole,
    countFrozenItems,
  };

  const exportCtx = {
    state,
    refs,
    DB,
    STORAGE,
    U,
    persistItem,
    getCurrentItem,
    hydrateUiFromState,
    refreshFrozenCounter,
  };

  boot().catch((error) => {
    console.error(error);
    setStatus('Error');
    logConsole('BOOT ERROR', { message: error.message });
  });

  async function boot() {
    state.db = await DB.openDb(STORAGE);
    C.wireCropCanvas(refs, state, U);
    wireUi();
    await recoverOrCreateWorkspace();
    await hydrateUiFromState();
    await refreshFrozenCounter();
    setStatus('Ready');
    logConsole('BOOT', {
      route: config.toolUrl || '/knx-menu-tool',
      role: config.role || null,
      userId: config.userId || null,
      version: config.version || null,
      tesseractLoaded: !!window.Tesseract,
    });
  }

  function wireUi() {
    refs.newBatchBtn?.addEventListener('click', async () => {
      const shouldReset = confirm('Reset workspace? Current local items will be removed.');
      if (!shouldReset) return;

      await resetWorkspace();
      await hydrateUiFromState();
      await refreshFrozenCounter();
      toast('Workspace reset.');
    });

    refs.batchName?.addEventListener('input', () => {
      if (!state.workspace) return;
      state.workspace.name = refs.batchName.value.trim();
      state.workspace.updated_at = Date.now();
      queueSaveWorkspace();
    });

    refs.itemPicker?.addEventListener('change', async () => {
      state.currentItemId = refs.itemPicker.value || null;
      await hydrateUiFromState();
    });

    refs.addItemBtn?.addEventListener('click', async () => {
      await addItem();
      await hydrateUiFromState();
      toast('Item added.');
    });

    refs.duplicateItemBtn?.addEventListener('click', async () => {
      const current = getCurrentItem();
      if (!current) return;
      await duplicateCurrentItem(current);
      await hydrateUiFromState();
      toast('Item duplicated.');
    });

    refs.deleteItemBtn?.addEventListener('click', async () => {
      const current = getCurrentItem();
      if (!current) return;

      const ok = confirm(`Delete item "${current.title || 'Untitled Item'}"?`);
      if (!ok) return;

      await deleteCurrentItem(current.id);
      await hydrateUiFromState();
      await refreshFrozenCounter();
      toast('Item deleted.');
    });

    refs.imageInput?.addEventListener('change', async () => {
      const file = refs.imageInput.files?.[0];
      if (!file) return;

      const allowed = ['image/jpeg', 'image/png', 'image/webp'];
      if (!allowed.includes(file.type)) {
        R.renderWarnings(refs, [{ text: `Unsupported file type: ${file.type}`, danger: true }]);
        refs.imageInput.value = '';
        return;
      }

      state.currentImageOriginalBlob = file;
      state.currentImageOriginalUrl = URL.createObjectURL(file);
      refs.previewWrap.hidden = false;
      refs.previewImage.src = state.currentImageOriginalUrl;
      refs.openCropBtn.disabled = false;
      refs.clearImageBtn.disabled = false;
      refs.runOcrParseBtn.disabled = true;
      R.setCropChip(refs, 'Needs crop', 'warn');

      const item = getCurrentItem();
      if (item) {
        item.status = item.status === 'frozen' ? 'frozen' : 'needs_crop';
        item.updated_at = Date.now();
        await persistItem(item);
      }

      refs.imageInput.value = '';
      toast('Image loaded. Open Crop.');
    });

    refs.openCropBtn?.addEventListener('click', async () => {
      if (!state.currentImageOriginalBlob) {
        const item = getCurrentItem();
        if (item?.image?.original_asset_id) {
          const asset = await DB.getRecord(state.db, STORAGE.STORES.assets, item.image.original_asset_id);
          if (asset?.blob) {
            state.currentImageOriginalBlob = asset.blob;
          }
        }
      }

      if (!state.currentImageOriginalBlob) return;
      await C.openCropModal(refs, state, state.currentImageOriginalBlob, U);
    });

    refs.runOcrParseBtn?.addEventListener('click', async () => {
      const item = getCurrentItem();
      if (!item?.image?.cropped_asset_id) {
        R.renderWarnings(refs, [{ text: 'Crop is required before OCR.', danger: true }]);
        return;
      }

      try {
        setStatus('OCR running...');
        R.renderWarnings(refs, []);

        const asset = await DB.getRecord(state.db, STORAGE.STORES.assets, item.image.cropped_asset_id);
        if (!asset?.blob) {
          throw new Error('Cropped image not found.');
        }

        const ocrText = await O.runBrowserOcr(asset.blob, setStatus, U);
        refs.ocrText.value = ocrText;

        setStatus('Parsing...');
        const parsed = await postAjax('knx_menu_tool_structure', {
          ocr_text: ocrText,
        });

        item.title = parsed.item?.title || '';
        item.description = parsed.item?.description || '';
        item.base_price = U.safeNumber(parsed.item?.base_price);
        item.globals = parsed.item?.globals || { special_instructions_allowed: false };
        item.groups = U.normalizeGroups(Array.isArray(parsed.item?.groups) ? parsed.item.groups : []);
        item.parser_meta = {
          ocr_text: ocrText,
          cleaned_text: parsed.cleaned_text || '',
          confidence: parsed.confidence || {},
          warnings: parsed.warnings || [],
          evidence: parsed.evidence || {},
        };

        if (item.status !== 'frozen') {
          item.status = 'draft';
        }

        item.updated_at = Date.now();

        await persistItem(item);
        await hydrateUiFromState();

        R.renderWarnings(refs, (parsed.warnings || []).map((text) => ({ text, danger: false })));
        setStatus('Parsed');
        toast('OCR + Parse complete.');
      } catch (error) {
        console.error(error);
        setStatus('Error');
        R.renderWarnings(refs, [{ text: error.message || 'OCR + Parse failed.', danger: true }]);
      }
    });

    refs.clearImageBtn?.addEventListener('click', async () => {
      await clearCurrentImage();
      await hydrateUiFromState();
      toast('Image cleared.');
    });

    refs.freezeBtn?.addEventListener('click', async () => {
      const snapshot = await freezeCurrentItem();
      if (!snapshot) return;
      logConsole('FROZEN SNAPSHOT', snapshot);
      toast('Item frozen and added to CSV collection.');
    });

    refs.downloadCsvBtn?.addEventListener('click', async () => {
      try {
        const frozenSnapshots = await getFrozenSnapshots();

        if (!frozenSnapshots.length) {
          R.renderWarnings(refs, [{ text: 'No frozen items yet.', danger: true }]);
          return;
        }

        setStatus('Downloading...');
        E.postCsvDownload(config, frozenSnapshots);
        setStatus('CSV ready');
      } catch (error) {
        console.error(error);
        setStatus('Error');
        R.renderWarnings(refs, [{ text: error.message || 'CSV download failed.', danger: true }]);
      }
    });

    refs.title?.addEventListener('input', () => updateCurrentItemField('title', refs.title.value));
    refs.basePrice?.addEventListener('input', () => updateCurrentItemField('base_price', U.safeNumber(refs.basePrice.value)));
    refs.description?.addEventListener('input', () => updateCurrentItemField('description', refs.description.value));

    refs.specialInstructions?.addEventListener('change', () => {
      const item = getCurrentItem();
      if (!item) return;
      item.globals = item.globals || { special_instructions_allowed: false };
      item.globals.special_instructions_allowed = !!refs.specialInstructions.checked;
      item.updated_at = Date.now();
      queueSaveItem();
    });

    refs.addGroupBtn?.addEventListener('click', async () => {
      await addEmptyGroupToCurrentItem();
      toast('Group added.');
    });

    refs.addGroupSecondaryBtn?.addEventListener('click', async () => {
      await addEmptyGroupToCurrentItem();
      toast('Group added.');
    });

    refs.closeCropBtn?.addEventListener('click', () => {
      C.closeCropModal(refs, state);
    });

    refs.cropBackdrop?.addEventListener('click', () => {
      C.closeCropModal(refs, state);
    });

    refs.resetCropBtn?.addEventListener('click', () => {
      C.resetCropRect(refs, state, U);
    });

    refs.confirmCropBtn?.addEventListener('click', async () => {
      await confirmCrop();
    });
  }

  async function recoverOrCreateWorkspace() {
    workspaceCtx.addItem = addItem;
    workspaceCtx.createFreshWorkspace = createFreshWorkspace;
    workspaceCtx.getFrozenItems = getFrozenItems;
    workspaceCtx.getFrozenSnapshots = getFrozenSnapshots;
    workspaceCtx.refreshFrozenCounter = refreshFrozenCounter;
    workspaceCtx.countFrozenItems = countFrozenItems;
    return W.recoverOrCreateWorkspace(workspaceCtx);
  }

  async function createFreshWorkspace() {
    workspaceCtx.addItem = addItem;
    return W.createFreshWorkspace(workspaceCtx);
  }

  async function resetWorkspace() {
    workspaceCtx.createFreshWorkspace = createFreshWorkspace;
    return W.resetWorkspace(workspaceCtx);
  }

  async function addItem() {
    return W.addItem(workspaceCtx);
  }

  async function duplicateCurrentItem(current) {
    return W.duplicateCurrentItem(workspaceCtx, current);
  }

  async function deleteCurrentItem(itemId) {
    workspaceCtx.getFrozenSnapshots = getFrozenSnapshots;
    workspaceCtx.addItem = addItem;
    return W.deleteCurrentItem(workspaceCtx, itemId);
  }

  async function hydrateUiFromState() {
    workspaceCtx.getFrozenItems = getFrozenItems;
    workspaceCtx.refreshFrozenCounter = refreshFrozenCounter;
    workspaceCtx.countFrozenItems = countFrozenItems;
    return W.hydrateUiFromState(workspaceCtx);
  }

  function getCurrentItem() {
    return W.getCurrentItem(state);
  }

  async function countFrozenItems() {
    return E.countFrozenItems(exportCtx);
  }

  async function getFrozenItems() {
    return E.getFrozenItems(exportCtx);
  }

  async function getFrozenSnapshots() {
    return E.getFrozenSnapshots(exportCtx);
  }

  async function refreshFrozenCounter() {
    return E.refreshFrozenCounter(exportCtx);
  }

  async function freezeCurrentItem() {
    exportCtx.hydrateUiFromState = hydrateUiFromState;
    exportCtx.refreshFrozenCounter = refreshFrozenCounter;
    return E.freezeCurrentItem(exportCtx);
  }

  async function addEmptyGroupToCurrentItem() {
    return G.addEmptyGroupToCurrentItem(groupsCtx);
  }

  function getStatusMeta(status) {
    if (status === 'frozen') {
      return {
        label: 'Frozen',
        className: 'knxmt-chip-info',
        copy: 'This item is already in the CSV collection.',
      };
    }

    if (status === 'draft') {
      return {
        label: 'Draft',
        className: 'knxmt-chip-muted',
        copy: 'This item is editable and not yet included in the CSV collection.',
      };
    }

    if (status === 'needs_crop') {
      return {
        label: 'Needs crop',
        className: 'knxmt-chip-warn',
        copy: 'Upload and crop the screenshot before OCR + parse.',
      };
    }

    return {
      label: 'Working',
      className: 'knxmt-chip-muted',
      copy: 'This item is still being prepared.',
    };
  }

  function setStatus(text) {
    UI.setStatus(refs, text);
  }

  function toast(text) {
    UI.toast(refs, text);
  }

  function logConsole(title, payload) {
    UI.logConsole(refs, title, payload);
  }

  async function postAjax(action, extra = {}) {
    return UI.postAjax(config, action, extra);
  }

  function updateCurrentItemField(field, value) {
    const item = getCurrentItem();
    if (!item) return;

    item[field] = value;
    item.updated_at = Date.now();
    queueSaveItem();
  }

  async function persistItem(item) {
    await DB.putRecord(state.db, STORAGE.STORES.items, item);

    const idx = state.items.findIndex((entry) => entry.id === item.id);
    if (idx >= 0) {
      state.items[idx] = item;
    }

    if (state.workspace) {
      state.workspace.current_item_id = item.id;
      state.workspace.updated_at = Date.now();
      await DB.putRecord(state.db, STORAGE.STORES.batches, state.workspace);
    }
  }

  function queueSaveWorkspace() {
    setStatus('Saving...');
    clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(async () => {
      if (!state.workspace) return;
      await DB.putRecord(state.db, STORAGE.STORES.batches, state.workspace);
      setStatus('Saved');
    }, 250);
  }

  function queueSaveItem() {
    setStatus('Saving...');
    clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(async () => {
      const item = getCurrentItem();
      if (!item) return;
      await persistItem(item);
      setStatus('Saved');
      logConsole('AUTOSAVED', U.buildSnapshotFromItem(item));
      await hydrateUiFromState();
    }, 250);
  }

  async function clearCurrentImage() {
    const item = getCurrentItem();
    if (!item) return;

    if (item.image?.original_asset_id) {
      await DB.deleteRecord(state.db, STORAGE.STORES.assets, item.image.original_asset_id);
    }

    if (item.image?.cropped_asset_id) {
      await DB.deleteRecord(state.db, STORAGE.STORES.assets, item.image.cropped_asset_id);
    }

    item.image = {
      original_asset_id: null,
      cropped_asset_id: null,
      crop_meta: null,
    };

    if (item.status !== 'frozen') {
      item.status = 'needs_crop';
    }

    item.parser_meta = {
      ocr_text: '',
      cleaned_text: '',
      confidence: {},
      warnings: [],
      evidence: {},
    };

    item.updated_at = Date.now();

    state.currentImageOriginalBlob = null;
    state.currentImageOriginalUrl = '';
    refs.ocrText.value = '';
    refs.runOcrParseBtn.disabled = true;
    refs.previewWrap.hidden = true;
    refs.previewImage.removeAttribute('src');

    await persistItem(item);
  }

  async function confirmCrop() {
    const item = getCurrentItem();
    if (!item || !state.currentImageOriginalBlob || !state.cropSession.image || !state.cropSession.rect) return;

    const assets = await C.buildCropAssets(state, item.id, U);

    if (item.image?.original_asset_id) {
      await DB.deleteRecord(state.db, STORAGE.STORES.assets, item.image.original_asset_id);
    }

    if (item.image?.cropped_asset_id) {
      await DB.deleteRecord(state.db, STORAGE.STORES.assets, item.image.cropped_asset_id);
    }

    await DB.putRecord(state.db, STORAGE.STORES.assets, assets.originalAsset);
    await DB.putRecord(state.db, STORAGE.STORES.assets, assets.croppedAsset);

    item.image = {
      original_asset_id: assets.originalAsset.id,
      cropped_asset_id: assets.croppedAsset.id,
      crop_meta: assets.cropMeta,
    };

    if (item.status !== 'frozen') {
      item.status = 'draft';
    }

    item.updated_at = Date.now();

    await persistItem(item);
    C.closeCropModal(refs, state);
    await hydrateUiFromState();
    refs.runOcrParseBtn.disabled = false;
    toast('Crop confirmed.');
  }
});