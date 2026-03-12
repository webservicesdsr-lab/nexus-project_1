window.KNXMT_Export = (() => {
  async function countFrozenItems(ctx) {
    const { state, DB, STORAGE } = ctx;
    const allItems = await DB.getAllRecords(state.db, STORAGE.STORES.items);
    return allItems.filter((item) => item.status === 'frozen' && item.frozen_snapshot).length;
  }

  async function getFrozenItems(ctx) {
    const { state, DB, STORAGE, U } = ctx;
    const allItems = await DB.getAllRecords(state.db, STORAGE.STORES.items);

    return allItems
      .filter((item) => item.status === 'frozen' && item.frozen_snapshot)
      .sort((a, b) => U.safeInt(a.frozen_at, 0) - U.safeInt(b.frozen_at, 0));
  }

  async function getFrozenSnapshots(ctx) {
    const frozenItems = await getFrozenItems(ctx);
    return frozenItems.map((item) => item.frozen_snapshot);
  }

  async function refreshFrozenCounter(ctx) {
    const { refs } = ctx;
    const count = await countFrozenItems(ctx);

    if (refs.frozenCounter) {
      refs.frozenCounter.textContent = `${count} item${count === 1 ? '' : 's'} ready`;
    }

    if (refs.downloadCsvBtn) {
      refs.downloadCsvBtn.disabled = count === 0;
    }

    if (refs.frozenCollectionCount) {
      refs.frozenCollectionCount.textContent = `${count} frozen`;
    }
  }

  async function freezeCurrentItem(ctx) {
    const { state, DB, STORAGE, U, persistItem, getCurrentItem, hydrateUiFromState, refreshFrozenCounter } = ctx;
    const item = getCurrentItem();
    if (!item) return null;

    const snapshot = U.buildSnapshotFromItem(item);

    item.status = 'frozen';
    item.frozen_snapshot = snapshot;
    item.frozen_at = Date.now();
    item.updated_at = Date.now();

    state.lastFrozenSnapshot = snapshot;
    await DB.setMeta(state.db, STORAGE, 'lastFrozenSnapshot', snapshot);
    await persistItem(item);
    await hydrateUiFromState();
    await refreshFrozenCounter();

    return snapshot;
  }

  function postCsvDownload(config, snapshots) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = config.ajaxUrl;
    form.style.display = 'none';

    const fields = {
      action: 'knx_menu_tool_download_csv',
      nonce: config.nonce || '',
      snapshot_json: JSON.stringify(snapshots),
    };

    Object.entries(fields).forEach(([name, value]) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
  }

  return {
    countFrozenItems,
    getFrozenItems,
    getFrozenSnapshots,
    refreshFrozenCounter,
    freezeCurrentItem,
    postCsvDownload,
  };
})();