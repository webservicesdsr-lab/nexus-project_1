window.KNXMT_Workspace = (() => {
  async function recoverOrCreateWorkspace(ctx) {
    const { state, DB, STORAGE } = ctx;

    const lastWorkspaceId = await DB.getMeta(state.db, STORAGE, 'lastWorkspaceId');

    if (lastWorkspaceId) {
      const recovered = await DB.getRecord(state.db, STORAGE.STORES.batches, lastWorkspaceId);

      if (recovered) {
        state.workspace = recovered;
        state.items = await DB.getItemsByWorkspaceId(state.db, STORAGE, recovered.id);
        state.currentItemId = recovered.current_item_id || (state.items[0]?.id || null);
        state.lastFrozenSnapshot = await DB.getMeta(state.db, STORAGE, 'lastFrozenSnapshot');
        return;
      }
    }

    await createFreshWorkspace(ctx);
  }

  async function createFreshWorkspace(ctx) {
    const { state, DB, STORAGE, U, addItem } = ctx;

    const now = Date.now();
    const workspace = {
      id: U.uid('workspace'),
      name: 'Current Workspace',
      status: 'active',
      created_at: now,
      updated_at: now,
      current_item_id: null,
    };

    await DB.putRecord(state.db, STORAGE.STORES.batches, workspace);

    state.workspace = workspace;
    state.items = [];
    state.currentItemId = null;
    state.lastFrozenSnapshot = null;

    await DB.setMeta(state.db, STORAGE, 'lastWorkspaceId', workspace.id);
    await DB.setMeta(state.db, STORAGE, 'lastFrozenSnapshot', null);

    await addItem();
  }

  async function resetWorkspace(ctx) {
    const { state, DB, STORAGE, createFreshWorkspace } = ctx;

    const allItems = await DB.getAllRecords(state.db, STORAGE.STORES.items);

    for (const item of allItems) {
      if (item?.image?.original_asset_id) {
        await DB.deleteRecord(state.db, STORAGE.STORES.assets, item.image.original_asset_id);
      }

      if (item?.image?.cropped_asset_id) {
        await DB.deleteRecord(state.db, STORAGE.STORES.assets, item.image.cropped_asset_id);
      }

      await DB.deleteRecord(state.db, STORAGE.STORES.items, item.id);
    }

    const allWorkspaces = await DB.getAllRecords(state.db, STORAGE.STORES.batches);
    for (const workspace of allWorkspaces) {
      await DB.deleteRecord(state.db, STORAGE.STORES.batches, workspace.id);
    }

    state.currentImageOriginalBlob = null;
    state.currentImageOriginalUrl = '';

    await createFreshWorkspace();
  }

  async function addItem(ctx) {
    const { state, DB, STORAGE, U } = ctx;
    if (!state.workspace) return;

    const now = Date.now();
    const item = {
      id: U.uid('item'),
      workspace_id: state.workspace.id,
      status: 'needs_crop',
      title: '',
      description: '',
      base_price: 0,
      globals: {
        special_instructions_allowed: false,
      },
      groups: [],
      parser_meta: {
        ocr_text: '',
        cleaned_text: '',
        confidence: {},
        warnings: [],
        evidence: {},
      },
      image: {
        original_asset_id: null,
        cropped_asset_id: null,
        crop_meta: null,
      },
      frozen_snapshot: null,
      frozen_at: null,
      created_at: now,
      updated_at: now,
    };

    await DB.putRecord(state.db, STORAGE.STORES.items, item);
    state.items.push(item);
    state.currentItemId = item.id;
    state.workspace.current_item_id = item.id;
    state.workspace.updated_at = now;
    await DB.putRecord(state.db, STORAGE.STORES.batches, state.workspace);
    await DB.setMeta(state.db, STORAGE, 'lastWorkspaceId', state.workspace.id);
  }

  async function duplicateCurrentItem(ctx, current) {
    const { state, DB, STORAGE, U } = ctx;

    const now = Date.now();
    const copy = U.deepClone(current);

    copy.id = U.uid('item');
    copy.title = current.title ? `${current.title} Copy` : 'Untitled Copy';
    copy.created_at = now;
    copy.updated_at = now;
    copy.status = current.status === 'frozen' ? 'draft' : current.status;
    copy.frozen_snapshot = null;
    copy.frozen_at = null;
    copy.image = {
      original_asset_id: null,
      cropped_asset_id: null,
      crop_meta: null,
    };
    copy.groups = U.normalizeGroups(copy.groups || []);

    await DB.putRecord(state.db, STORAGE.STORES.items, copy);
    state.items.push(copy);
    state.currentItemId = copy.id;
    state.workspace.current_item_id = copy.id;
    state.workspace.updated_at = now;
    await DB.putRecord(state.db, STORAGE.STORES.batches, state.workspace);
  }

  async function deleteCurrentItem(ctx, itemId) {
    const {
      state,
      DB,
      STORAGE,
      getFrozenSnapshots,
      addItem,
    } = ctx;

    await DB.deleteRecord(state.db, STORAGE.STORES.items, itemId);

    const item = state.items.find((entry) => entry.id === itemId);

    if (item?.image?.original_asset_id) {
      await DB.deleteRecord(state.db, STORAGE.STORES.assets, item.image.original_asset_id);
    }

    if (item?.image?.cropped_asset_id) {
      await DB.deleteRecord(state.db, STORAGE.STORES.assets, item.image.cropped_asset_id);
    }

    state.items = state.items.filter((entry) => entry.id !== itemId);

    if (!state.items.length) {
      await addItem();
    } else {
      state.currentItemId = state.items[0].id;
      state.workspace.current_item_id = state.currentItemId;
      state.workspace.updated_at = Date.now();
      await DB.putRecord(state.db, STORAGE.STORES.batches, state.workspace);
    }

    const frozenSnapshots = await getFrozenSnapshots();
    state.lastFrozenSnapshot = frozenSnapshots.length ? frozenSnapshots[frozenSnapshots.length - 1] : null;
    await DB.setMeta(state.db, STORAGE, 'lastFrozenSnapshot', state.lastFrozenSnapshot);
  }

  async function hydrateUiFromState(ctx) {
    const {
      state,
      refs,
      DB,
      STORAGE,
      R,
      U,
      rendererApi,
      getStatusMeta,
      getFrozenItems,
      refreshFrozenCounter,
      logConsole,
      countFrozenItems,
    } = ctx;

    state.items = state.workspace
      ? await DB.getItemsByWorkspaceId(state.db, STORAGE, state.workspace.id)
      : [];

    if (!state.currentItemId && state.items.length) {
      state.currentItemId = state.items[0].id;
    }

    const item = getCurrentItem(state);

    refs.batchName.value = state.workspace?.name || '';
    refs.batchCountChip.textContent = `${state.items.length} item${state.items.length === 1 ? '' : 's'}`;

    R.renderItemPicker(refs, state.items, state.currentItemId);
    R.renderCurrentItemSummary(refs, item, getStatusMeta, U);
    R.renderFrozenCollection(refs, await getFrozenItems(), U);
    R.renderWarnings(refs, item?.parser_meta?.warnings?.map((text) => ({ text, danger: false })) || []);
    R.hydrateImage(refs, item, state, (assetId) => DB.getRecord(state.db, STORAGE.STORES.assets, assetId));
    R.hydrateEditor(refs, item, U);
    R.renderGroups(refs, item, rendererApi, U);
    R.renderKnxPreview(refs, item, U);
    await hydratePreviewImage(refs, item, state, DB, STORAGE);
    await refreshFrozenCounter();

    logConsole('STATE', {
      workspace: state.workspace,
      currentItemId: state.currentItemId,
      item,
      frozenCount: await countFrozenItems(),
    });
  }

  async function hydratePreviewImage(refs, item, state, DB, STORAGE) {
    if (!refs.knxPreview) return;

    const img = refs.knxPreview.querySelector('#knxmtKnxPreviewImage');
    if (!img) return;

    if (!item?.image?.cropped_asset_id) {
      img.removeAttribute('src');
      return;
    }

    const asset = await DB.getRecord(state.db, STORAGE.STORES.assets, item.image.cropped_asset_id);
    if (!asset?.blob) {
      img.removeAttribute('src');
      return;
    }

    img.src = URL.createObjectURL(asset.blob);
  }

  function getCurrentItem(state) {
    return state.items.find((item) => item.id === state.currentItemId) || null;
  }

  return {
    recoverOrCreateWorkspace,
    createFreshWorkspace,
    resetWorkspace,
    addItem,
    duplicateCurrentItem,
    deleteCurrentItem,
    hydrateUiFromState,
    getCurrentItem,
  };
})();