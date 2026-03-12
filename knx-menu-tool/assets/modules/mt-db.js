window.KNXMT_DB = (() => {
  async function openDb(storageConfig) {
    return await new Promise((resolve, reject) => {
      const req = indexedDB.open(storageConfig.DB_NAME, storageConfig.DB_VERSION);

      req.onupgradeneeded = () => {
        const db = req.result;

        if (!db.objectStoreNames.contains(storageConfig.STORES.batches)) {
          db.createObjectStore(storageConfig.STORES.batches, { keyPath: 'id' });
        }

        if (!db.objectStoreNames.contains(storageConfig.STORES.items)) {
          const store = db.createObjectStore(storageConfig.STORES.items, { keyPath: 'id' });
          store.createIndex('workspace_id', 'workspace_id', { unique: false });
          store.createIndex('status', 'status', { unique: false });
        } else {
          const store = req.transaction.objectStore(storageConfig.STORES.items);

          if (!store.indexNames.contains('workspace_id') && store.indexNames.contains('batch_id')) {
            store.createIndex('workspace_id', 'workspace_id', { unique: false });
          }

          if (!store.indexNames.contains('status')) {
            store.createIndex('status', 'status', { unique: false });
          }
        }

        if (!db.objectStoreNames.contains(storageConfig.STORES.assets)) {
          const store = db.createObjectStore(storageConfig.STORES.assets, { keyPath: 'id' });
          store.createIndex('item_id', 'item_id', { unique: false });
        }

        if (!db.objectStoreNames.contains(storageConfig.STORES.meta)) {
          db.createObjectStore(storageConfig.STORES.meta, { keyPath: 'key' });
        }
      };

      req.onsuccess = async () => {
        const db = req.result;
        await migrateLegacyItemsIfNeeded(db, storageConfig);
        resolve(db);
      };

      req.onerror = () => reject(req.error || new Error('IndexedDB open failed.'));
    });
  }

  async function migrateLegacyItemsIfNeeded(db, storageConfig) {
    const items = await new Promise((resolve, reject) => {
      const tx = db.transaction(storageConfig.STORES.items, 'readonly');
      const store = tx.objectStore(storageConfig.STORES.items);
      const req = store.getAll();

      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error || new Error('IndexedDB migration read failed.'));
    });

    const needsMigration = items.some((item) => item.batch_id && !item.workspace_id);

    if (!needsMigration) return;

    await new Promise((resolve, reject) => {
      const tx = db.transaction(storageConfig.STORES.items, 'readwrite');
      const store = tx.objectStore(storageConfig.STORES.items);

      items.forEach((item) => {
        if (item.batch_id && !item.workspace_id) {
          item.workspace_id = item.batch_id;
          delete item.batch_id;

          if (!Object.prototype.hasOwnProperty.call(item, 'frozen_snapshot')) {
            item.frozen_snapshot = null;
          }

          if (!Object.prototype.hasOwnProperty.call(item, 'frozen_at')) {
            item.frozen_at = null;
          }

          store.put(item);
        }
      });

      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error || new Error('IndexedDB migration write failed.'));
    });
  }

  function txPromise(db, storeName, mode, executor) {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, mode);
      const store = tx.objectStore(storeName);
      const req = executor(store);

      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error || new Error(`IndexedDB ${storeName} failed.`));
    });
  }

  async function putRecord(db, storeName, value) {
    return await txPromise(db, storeName, 'readwrite', (store) => store.put(value));
  }

  async function getRecord(db, storeName, key) {
    return await txPromise(db, storeName, 'readonly', (store) => store.get(key));
  }

  async function deleteRecord(db, storeName, key) {
    return await txPromise(db, storeName, 'readwrite', (store) => store.delete(key));
  }

  async function getAllRecords(db, storeName) {
    return await txPromise(db, storeName, 'readonly', (store) => store.getAll());
  }

  async function getItemsByWorkspaceId(db, storageConfig, workspaceId) {
    return await txPromise(db, storageConfig.STORES.items, 'readonly', (store) => {
      const index = store.index('workspace_id');
      return index.getAll(workspaceId);
    });
  }

  async function setMeta(db, storageConfig, key, value) {
    return await putRecord(db, storageConfig.STORES.meta, { key, value });
  }

  async function getMeta(db, storageConfig, key) {
    const row = await getRecord(db, storageConfig.STORES.meta, key);
    return row ? row.value : null;
  }

  return {
    openDb,
    putRecord,
    getRecord,
    deleteRecord,
    getAllRecords,
    getItemsByWorkspaceId,
    setMeta,
    getMeta,
  };
})();