/**
 * ==========================================================
 * Kingdom Nexus — Drivers Admin Script (v2.4)
 * ----------------------------------------------------------
 * - REST-only UI (knx/v2)
 * - Mobile-first UX, desktop inline rows
 * - Single Cities modal (multi-select)
 * - Reset + Delete confirmations INSIDE the same Driver modal
 * - No console logs, no browser alerts
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const root = document.querySelector('#knx-drivers-admin');
  if (!root) return;

  const cfg = (window.KNX_DRIVERS_CONFIG || {});
  const api = {
    list: root.dataset.apiList || cfg.apiList || '',
    create: root.dataset.apiCreate || cfg.apiCreate || '',
    base: root.dataset.apiBase || cfg.apiBase || '',
    cities: root.dataset.apiCities || cfg.apiCities || '',
  };

  const nonces = {
    knx: root.dataset.knxNonce || cfg.knxNonce || '',
    wpRest: root.dataset.wpRestNonce || cfg.wpRestNonce || '',
  };

  const $ = (sel, el) => (el || root).querySelector(sel);
  const $$ = (sel, el) => Array.from((el || root).querySelectorAll(sel));

  const tbody = $('.knx-drivers-tbody');
  const pager = $('.knx-pagination');

  const qInput = $('.knx-drivers-q');
  const statusSel = $('.knx-drivers-status');
  const addBtn = $('.knx-drivers-add');

  // Modals
  const modalDriver = document.getElementById('knxDriverModal');
  const modalConfirm = document.getElementById('knxDriverConfirmModal');
  const modalCities = document.getElementById('knxDriverCitiesModal');

  const driverForm = document.getElementById('knxDriverForm');
  const driverTitle = document.getElementById('knxDriverModalTitle');

  const chipsWrap = $('.knx-drv-chips', modalDriver);
  const pickCitiesBtn = $('.knx-drv-pick-cities', modalDriver);

  const driverCancelBtn = $('.knx-drv-cancel', modalDriver);
  const driverSaveBtn = $('.knx-drv-save', modalDriver);

  const resetBtn = $('.knx-drv-reset', modalDriver);
  const deleteBtn = $('.knx-drv-delete', modalDriver);

  const credsBox = $('.knx-drv-credentials', modalDriver);
  const credUsername = $('[data-cred="username"]', modalDriver);
  const credPassword = $('[data-cred="password"]', modalDriver);

  // Confirm (toggle deactivate only)
  const confirmTitleEl = document.getElementById('knxDriverConfirmTitle');
  const confirmTextEl = modalConfirm ? modalConfirm.querySelector('p') : null;
  const confirmCancel = $('.knx-drv-confirm-cancel', modalConfirm);
  const confirmOk = $('.knx-drv-confirm-ok', modalConfirm);

  // Cities modal
  const citiesQ = $('.knx-drv-cities-q', modalCities);
  const citiesList = $('.knx-drv-cities-list', modalCities);
  const citiesCancel = $('.knx-drv-cities-cancel', modalCities);
  const citiesSave = $('.knx-drv-cities-save', modalCities);

  // Inline confirm inside Driver modal (Reset/Delete)
  let inlineConfirm = $('.knx-drv-inline-confirm', modalDriver);
  if (!inlineConfirm) {
    // Safe fallback if shortcode was not updated yet
    inlineConfirm = document.createElement('div');
    inlineConfirm.className = 'knx-drv-inline-confirm';
    inlineConfirm.hidden = true;
    inlineConfirm.innerHTML = `
      <div class="knx-drv-inline-confirm__top">
        <div class="knx-drv-inline-confirm__title"></div>
        <button type="button" class="knx-drv-inline-confirm__x" aria-label="Close">✕</button>
      </div>
      <div class="knx-drv-inline-confirm__msg"></div>
      <div class="knx-drv-inline-confirm__reason" hidden>
        <label class="knx-drv-inline-confirm__label">Reason (optional)</label>
        <textarea class="knx-drv-inline-confirm__textarea" rows="2" placeholder="Optional reason…"></textarea>
      </div>
      <div class="knx-drv-inline-confirm__actions">
        <button type="button" class="knx-btn-secondary" data-inline-cancel>Cancel</button>
        <button type="button" class="knx-btn danger" data-inline-ok>Confirm</button>
      </div>
    `;
    const anchor = $('.knx-drv-secondary-actions', modalDriver) || driverForm;
    anchor.parentNode.insertBefore(inlineConfirm, anchor.nextSibling);
  }

  const inlineTitleEl = $('.knx-drv-inline-confirm__title', inlineConfirm);
  const inlineMsgEl = $('.knx-drv-inline-confirm__msg', inlineConfirm);
  const inlineReasonWrap = $('.knx-drv-inline-confirm__reason', inlineConfirm);
  const inlineReasonInput = $('.knx-drv-inline-confirm__textarea', inlineConfirm);
  const inlineCancelBtn = $('[data-inline-cancel]', inlineConfirm);
  const inlineOkBtn = $('[data-inline-ok]', inlineConfirm);
  const inlineXBtn = $('.knx-drv-inline-confirm__x', inlineConfirm);

  // State
  const state = {
    page: 1,
    perPage: 20,
    q: '',
    status: '',
    totalPages: 0,

    drivers: [],
    driversById: new Map(),

    allowedCitiesLoaded: false,
    cities: [],
    citiesById: new Map(),

    mode: 'add', // add | edit
    editingId: 0,
    selectedCityIds: [],

    pendingToggle: null, // {id, inputEl}

    busy: {
      list: false,
      submit: false,
      toggle: false,
      inlineAction: false,
    },

    inlineMode: null, // 'reset' | 'delete' | null
  };

  // ------------------------------------------------------
  // Toast (uses global knxToast if available)
  // ------------------------------------------------------
  function toast(message, type) {
    const msg = (message || '').toString().trim() || 'Something went wrong.';
    const t = (type || 'info').toString();

    if (typeof window.knxToast === 'function') {
      window.knxToast(msg, t);
      return;
    }

    // Minimal fallback (no alerts)
    let el = document.getElementById('knxDrvToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'knxDrvToast';
      el.className = 'knx-drv-toast';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.setAttribute('data-type', t);
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 2200);
  }

  // ------------------------------------------------------
  // Helpers
  // ------------------------------------------------------
  function escHtml(str) {
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function debounce(fn, wait) {
    let t = null;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait);
    };
  }

  async function fetchJson(url, opts) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      ...opts,
    });
    const data = await res.json().catch(() => null);
    return { ok: res.ok, status: res.status, data };
  }

  function apiDriverUrl(id, suffix) {
    const base = String(api.base || '').trim();
    const safeId = parseInt(id, 10) || 0;
    if (!base || safeId <= 0) return '';
    return base + safeId + (suffix || '');
  }

  function setLoading(isLoading) {
    root.classList.toggle('is-loading', !!isLoading);
  }

  function setButtonBusy(btn, busy, busyText) {
    if (!btn) return;
    if (busy) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      if (busyText) {
        btn.dataset._txt = btn.textContent;
        btn.textContent = busyText;
      }
    } else {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      if (btn.dataset._txt) {
        btn.textContent = btn.dataset._txt;
        delete btn.dataset._txt;
      }
    }
  }

  // ------------------------------------------------------
  // Modals
  // ------------------------------------------------------
  function openModal(modalEl) {
    if (!modalEl) return;
    modalEl.classList.add('active');
    modalEl.setAttribute('aria-hidden', 'false');
    document.body.classList.add('knx-drv-modal-open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal(modalEl) {
    if (!modalEl) return;
    modalEl.classList.remove('active');
    modalEl.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.knx-drv-modal.active')) {
      document.body.classList.remove('knx-drv-modal-open');
      document.body.style.overflow = '';
    }
  }

  function wireModalClosers(modalEl) {
    if (!modalEl) return;

    $$('.knx-drv-x', modalEl).forEach(btn => {
      btn.addEventListener('click', () => closeModal(modalEl));
    });

    modalEl.addEventListener('click', (e) => {
      if (e.target === modalEl) closeModal(modalEl);
    });
  }

  wireModalClosers(modalDriver);
  wireModalClosers(modalConfirm);
  wireModalClosers(modalCities);

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (modalCities.classList.contains('active')) return closeModal(modalCities);
    if (modalConfirm.classList.contains('active')) return closeModal(modalConfirm);
    if (modalDriver.classList.contains('active')) return closeModal(modalDriver);
  });

  // ------------------------------------------------------
  // Inline confirm (inside Driver modal)
  // ------------------------------------------------------
  function hideInlineConfirm() {
    state.inlineMode = null;
    inlineConfirm.hidden = true;
    inlineConfirm.classList.remove('is-reset', 'is-delete');
    if (inlineReasonInput) inlineReasonInput.value = '';
    if (inlineReasonWrap) inlineReasonWrap.hidden = true;

    // Re-enable base buttons
    resetBtn.disabled = false;
    deleteBtn.disabled = false;
    driverSaveBtn.disabled = false;
    driverCancelBtn.disabled = false;
    pickCitiesBtn.disabled = false;
  }

  function showInlineConfirm(opts) {
    const mode = opts && opts.mode ? String(opts.mode) : null;
    if (!mode) return;

    state.inlineMode = mode;
    inlineConfirm.hidden = false;
    inlineConfirm.classList.toggle('is-reset', mode === 'reset');
    inlineConfirm.classList.toggle('is-delete', mode === 'delete');

    if (inlineTitleEl) inlineTitleEl.textContent = String(opts.title || '');
    if (inlineMsgEl) inlineMsgEl.textContent = String(opts.message || '');

    const okLabel = String(opts.okLabel || 'Confirm');
    if (inlineOkBtn) inlineOkBtn.textContent = okLabel;

    const showReason = !!opts.showReason;
    if (inlineReasonWrap) inlineReasonWrap.hidden = !showReason;
    if (inlineReasonInput) inlineReasonInput.value = '';

    // Disable other buttons while confirm is visible (prevents confusion)
    driverSaveBtn.disabled = true;
    driverCancelBtn.disabled = true;
    pickCitiesBtn.disabled = true;
  }

  if (inlineCancelBtn) inlineCancelBtn.addEventListener('click', hideInlineConfirm);
  if (inlineXBtn) inlineXBtn.addEventListener('click', hideInlineConfirm);

  if (inlineOkBtn) {
    inlineOkBtn.addEventListener('click', async () => {
      if (state.busy.inlineAction) return;

      const id = state.editingId;
      if (!id) return;

      if (state.inlineMode === 'reset') {
        await doResetPassword(id);
        return;
      }

      if (state.inlineMode === 'delete') {
        const reason = inlineReasonInput ? String(inlineReasonInput.value || '').trim() : '';
        await doSoftDelete(id, reason);
        return;
      }
    });
  }

  // ------------------------------------------------------
  // Allowed Cities (SSOT)
  // ------------------------------------------------------
  async function loadAllowedCitiesIfNeeded() {
    if (state.allowedCitiesLoaded) return true;

    const url = String(api.cities || '').trim();
    if (!url) {
      toast('Cities endpoint missing.', 'error');
      return false;
    }

    const out = await fetchJson(url, { method: 'GET' });
    const body = out.data;

    if (!out.ok || !body || body.success !== true) {
      toast((body && (body.message || body.error)) || 'Failed to load allowed cities.', 'error');
      return false;
    }

    const cities = (body.data && body.data.cities) ? body.data.cities : [];
    state.cities = Array.isArray(cities) ? cities : [];
    state.citiesById = new Map();
    state.cities.forEach(c => state.citiesById.set(parseInt(c.id, 10), String(c.name || '')));

    state.allowedCitiesLoaded = true;
    return true;
  }

  function cityLabelForIds(ids) {
    const arr = Array.isArray(ids) ? ids : [];
    if (!arr.length) return '—';

    const names = arr
      .map(id => state.citiesById.get(parseInt(id, 10)))
      .filter(Boolean);

    if (!names.length) return '—';
    if (names.length === 1) return names[0];
    return names[0] + ' +' + (names.length - 1);
  }

  function cityTitleForIds(ids) {
    const arr = Array.isArray(ids) ? ids : [];
    const names = arr
      .map(id => state.citiesById.get(parseInt(id, 10)))
      .filter(Boolean);
    return names.join(', ');
  }

  // ------------------------------------------------------
  // Drivers list
  // ------------------------------------------------------
  async function loadDrivers() {
    const listUrl = String(api.list || '').trim();
    if (!listUrl) {
      toast('List endpoint missing.', 'error');
      return;
    }

    if (state.busy.list) return;
    state.busy.list = true;

    setLoading(true);

    try {
      const u = new URL(listUrl);
      u.searchParams.set('page', String(state.page));
      u.searchParams.set('per_page', String(state.perPage));
      if (state.q) u.searchParams.set('q', state.q);
      if (state.status) u.searchParams.set('status', state.status);

      const out = await fetchJson(u.toString(), { method: 'GET' });
      const body = out.data;

      if (!out.ok || !body || body.success !== true) {
        const msg = (body && (body.message || body.error)) || 'Failed to load drivers.';
        renderError(msg);
        return;
      }

      const data = body.data || {};
      const drivers = Array.isArray(data.drivers) ? data.drivers : [];
      const pagination = data.pagination || {};

      state.drivers = drivers;
      state.driversById = new Map();
      drivers.forEach(d => state.driversById.set(parseInt(d.id, 10), d));

      state.totalPages = parseInt(pagination.total_pages, 10) || 0;

      renderDrivers();
      renderPagination();
    } finally {
      state.busy.list = false;
      setLoading(false);
    }
  }

  function renderError(message) {
    const msg = escHtml(message || 'Unable to load drivers.');
    tbody.innerHTML = `
      <tr>
        <td colspan="6">
          <div class="knx-drivers-empty">${msg}</div>
        </td>
      </tr>
    `;
    pager.innerHTML = '';
  }

  function renderDrivers() {
    if (!state.drivers.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6">
            <div class="knx-drivers-empty">No drivers found.</div>
          </td>
        </tr>
      `;
      return;
    }

    const rows = state.drivers.map(d => {
      const id = parseInt(d.id, 10) || 0;
      const name = escHtml(d.full_name || '');
      const phone = escHtml(d.phone || '');
      const status = (String(d.status || '') === 'active') ? 'active' : 'inactive';
      const cityIds = Array.isArray(d.city_ids) ? d.city_ids : [];
      const cityLabel = escHtml(cityLabelForIds(cityIds));
      const cityTitle = escHtml(cityTitleForIds(cityIds));

      const checked = status === 'active' ? 'checked' : '';

      return `
        <tr data-id="${id}">
          <td>
            <div class="knx-driver__who">
              <div class="knx-driver__name">${name}</div>
              <div class="knx-driver__meta">${escHtml((d.email || '') || '')}</div>
            </div>
          </td>
          <td>${phone || '—'}</td>
          <td title="${cityTitle || ''}">${cityLabel}</td>
          <td><span class="status-${status}">${status.charAt(0).toUpperCase() + status.slice(1)}</span></td>
          <td class="knx-col-center">
            <button type="button" class="knx-icon-btn knx-driver-edit" data-id="${id}" aria-label="Edit driver">✎</button>
          </td>
          <td class="knx-col-center">
            <label class="knx-switch" aria-label="Toggle driver status">
              <input type="checkbox" class="knx-driver-toggle" data-id="${id}" ${checked}>
              <span class="knx-slider"></span>
            </label>
          </td>
        </tr>
      `;
    }).join('');

    tbody.innerHTML = rows;

    $$('.knx-driver-edit', root).forEach(btn => {
      btn.addEventListener('click', () => openEditDriver(parseInt(btn.dataset.id, 10) || 0));
    });

    $$('.knx-driver-toggle', root).forEach(inp => {
      inp.addEventListener('change', () => onToggleChange(inp));
    });
  }

  function renderPagination() {
    const total = state.totalPages || 0;
    if (total <= 1) {
      pager.innerHTML = '';
      return;
    }

    const cur = state.page;
    const buttons = [];

    const mk = (label, page, extraClass, disabled) => {
      const dis = disabled ? 'disabled' : '';
      return `<button type="button" class="knx-page ${extraClass || ''} ${dis}" data-page="${page}">${label}</button>`;
    };

    buttons.push(mk('Prev', Math.max(1, cur - 1), '', cur === 1));

    const windowSize = 5;
    const start = Math.max(1, cur - Math.floor(windowSize / 2));
    const end = Math.min(total, start + windowSize - 1);
    const adjStart = Math.max(1, end - windowSize + 1);

    for (let p = adjStart; p <= end; p++) {
      buttons.push(mk(String(p), p, p === cur ? 'active' : '', false));
    }

    buttons.push(mk('Next', Math.min(total, cur + 1), '', cur === total));

    pager.innerHTML = buttons.join('');

    $$('.knx-page', pager).forEach(btn => {
      btn.addEventListener('click', () => {
        if (btn.classList.contains('disabled')) return;
        const p = parseInt(btn.dataset.page, 10) || 1;
        if (p === state.page) return;
        state.page = p;
        loadDrivers();
      });
    });
  }

  // ------------------------------------------------------
  // Toggle (confirm only when deactivating)
  // ------------------------------------------------------
  function onToggleChange(inputEl) {
    const id = parseInt(inputEl.dataset.id, 10) || 0;
    if (!id) return;

    const driver = state.driversById.get(id);
    if (!driver) return;

    const nowChecked = !!inputEl.checked;
    const currentStatus = (String(driver.status || '') === 'active') ? 'active' : 'inactive';
    const goingInactive = (currentStatus === 'active' && nowChecked === false);

    if (goingInactive) {
      state.pendingToggle = { id, inputEl };
      if (confirmTitleEl) confirmTitleEl.textContent = 'Deactivate Driver';
      if (confirmTextEl) confirmTextEl.textContent = 'This driver will be unavailable. Continue?';
      if (confirmOk) confirmOk.textContent = 'Deactivate';
      openModal(modalConfirm);
      return;
    }

    doToggle(id, inputEl);
  }

  async function doToggle(id, inputEl) {
    if (state.busy.toggle) return;

    const url = apiDriverUrl(id, '/toggle');
    if (!url) {
      toast('Toggle endpoint missing.', 'error');
      inputEl.checked = !inputEl.checked;
      return;
    }

    state.busy.toggle = true;

    const prevChecked = !inputEl.checked;

    try {
      const out = await fetchJson(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonces.wpRest || '',
        },
        body: JSON.stringify({
          knx_nonce: nonces.knx || '',
        }),
      });

      const body = out.data;

      if (!out.ok || !body || body.success !== true) {
        inputEl.checked = prevChecked;
        toast((body && (body.message || body.error)) || 'Toggle failed.', 'error');
        return;
      }

      const newStatus = (body.data && body.data.status) ? String(body.data.status) : null;
      if (!newStatus) {
        inputEl.checked = prevChecked;
        toast('Toggle failed (missing status).', 'error');
        return;
      }

      const driver = state.driversById.get(id);
      if (driver) driver.status = newStatus;

      const tr = inputEl.closest('tr');
      const pill = tr ? tr.querySelector('span.status-active, span.status-inactive') : null;
      if (pill) {
        pill.className = 'status-' + newStatus;
        pill.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
      }

      inputEl.checked = newStatus === 'active';
      toast('Driver ' + (newStatus === 'active' ? 'activated' : 'deactivated') + '.', 'success');
    } finally {
      state.busy.toggle = false;
    }
  }

  confirmCancel.addEventListener('click', () => {
    const pending = state.pendingToggle;
    closeModal(modalConfirm);
    if (pending && pending.inputEl) {
      pending.inputEl.checked = true;
    }
    state.pendingToggle = null;
  });

  confirmOk.addEventListener('click', () => {
    const pending = state.pendingToggle;
    closeModal(modalConfirm);
    state.pendingToggle = null;
    if (!pending) return;
    doToggle(pending.id, pending.inputEl);
  });

  // ------------------------------------------------------
  // Driver modal (Add/Edit)
  // ------------------------------------------------------
  function resetDriverModalUI() {
    hideInlineConfirm();
    credsBox.hidden = true;
    credUsername.textContent = '';
    credPassword.textContent = '';
  }

  function setCredentials(username, tempPassword) {
    credUsername.textContent = String(username || '');
    credPassword.textContent = String(tempPassword || '');
    credsBox.hidden = false;
  }

  function renderCityChips() {
    const ids = Array.isArray(state.selectedCityIds) ? state.selectedCityIds : [];
    if (!ids.length) {
      chipsWrap.innerHTML = '<span class="knx-muted">No cities selected</span>';
      return;
    }
    const chips = ids.map(id => {
      const name = state.citiesById.get(parseInt(id, 10)) || ('City #' + id);
      return `<span class="knx-chip">${escHtml(name)}</span>`;
    }).join('');
    chipsWrap.innerHTML = chips;
  }

  function openAddDriver() {
    state.mode = 'add';
    state.editingId = 0;
    state.selectedCityIds = [];

    driverTitle.textContent = 'Add Driver';
    driverSaveBtn.textContent = 'Create';

    driverForm.driver_id.value = '';
    driverForm.full_name.value = '';
    driverForm.email.value = '';
    driverForm.phone.value = '';
    driverForm.vehicle_info.value = '';
    driverForm.status.value = 'active';

    resetBtn.hidden = true;
    deleteBtn.hidden = true;

    resetDriverModalUI();
    renderCityChips();
    openModal(modalDriver);

    setTimeout(() => driverForm.full_name.focus(), 80);
  }

  async function openEditDriver(id) {
    if (!id) return;

    state.mode = 'edit';
    state.editingId = id;

    driverTitle.textContent = 'Edit Driver';
    driverSaveBtn.textContent = 'Save';

    resetBtn.hidden = false;
    deleteBtn.hidden = false;

    resetDriverModalUI();

    const d = state.driversById.get(id);
    if (!d) {
      toast('Driver not found in list.', 'error');
      return;
    }

    driverForm.driver_id.value = String(id);
    driverForm.full_name.value = d.full_name || '';
    driverForm.email.value = d.email || '';
    driverForm.phone.value = d.phone || '';
    driverForm.vehicle_info.value = d.vehicle_info || '';
    driverForm.status.value = (String(d.status) === 'inactive') ? 'inactive' : 'active';

    state.selectedCityIds = Array.isArray(d.city_ids) ? d.city_ids.slice() : [];
    renderCityChips();

    openModal(modalDriver);
    setTimeout(() => driverForm.full_name.focus(), 80);
  }

  driverCancelBtn.addEventListener('click', () => closeModal(modalDriver));

  addBtn.addEventListener('click', async () => {
    const ok = await loadAllowedCitiesIfNeeded();
    if (!ok) return;
    openAddDriver();
  });

  // ------------------------------------------------------
  // Cities picker modal (single)
  // ------------------------------------------------------
  let citiesModalOnSave = null;
  let citiesModalSelected = new Set();

  function renderCitiesList(filter) {
    const q = String(filter || '').trim().toLowerCase();
    const items = state.cities.filter(c => {
      if (!q) return true;
      return String(c.name || '').toLowerCase().includes(q);
    });

    if (!items.length) {
      citiesList.innerHTML = '<div class="knx-drivers-empty">No cities found.</div>';
      return;
    }

    citiesList.innerHTML = items.map(c => {
      const id = parseInt(c.id, 10);
      const checked = citiesModalSelected.has(id) ? 'checked' : '';
      return `
        <label class="knx-city-row" role="listitem">
          <input type="checkbox" data-city-id="${id}" ${checked}>
          <span class="knx-city-name">${escHtml(c.name || '')}</span>
        </label>
      `;
    }).join('');

    $$('.knx-city-row input', citiesList).forEach(inp => {
      inp.addEventListener('change', () => {
        const id = parseInt(inp.dataset.cityId, 10) || 0;
        if (!id) return;
        if (inp.checked) citiesModalSelected.add(id);
        else citiesModalSelected.delete(id);
      });
    });
  }

  async function openCitiesPicker(initialIds, onSave) {
    const ok = await loadAllowedCitiesIfNeeded();
    if (!ok) return;

    citiesModalOnSave = onSave;
    citiesModalSelected = new Set((Array.isArray(initialIds) ? initialIds : []).map(x => parseInt(x, 10)).filter(Boolean));

    citiesQ.value = '';
    renderCitiesList('');

    openModal(modalCities);
    setTimeout(() => citiesQ.focus(), 80);
  }

  pickCitiesBtn.addEventListener('click', () => {
    hideInlineConfirm();
    openCitiesPicker(state.selectedCityIds, (ids) => {
      state.selectedCityIds = ids.slice();
      renderCityChips();
    });
  });

  citiesQ.addEventListener('input', debounce(() => {
    renderCitiesList(citiesQ.value);
  }, 120));

  citiesCancel.addEventListener('click', () => {
    closeModal(modalCities);
    citiesModalOnSave = null;
  });

  citiesSave.addEventListener('click', () => {
    const ids = Array.from(citiesModalSelected.values()).sort((a, b) => a - b);
    if (typeof citiesModalOnSave === 'function') citiesModalOnSave(ids);
    closeModal(modalCities);
    citiesModalOnSave = null;
  });

  // ------------------------------------------------------
  // Create / Update
  // ------------------------------------------------------
  driverForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideInlineConfirm();

    if (state.busy.submit) return;

    const full_name = driverForm.full_name.value.trim();
    const email = driverForm.email.value.trim();
    const phone = driverForm.phone.value.trim();
    const vehicle_info = driverForm.vehicle_info.value.trim();
    const status = driverForm.status.value === 'inactive' ? 'inactive' : 'active';

    if (!full_name) return toast('Full name is required.', 'warning');
    if (!email) return toast('Email is required.', 'warning');

    // IMPORTANT: phone is REQUIRED (DB constraint)
    if (!phone) return toast('Phone is required.', 'warning');

    if (!Array.isArray(state.selectedCityIds) || state.selectedCityIds.length < 1) {
      return toast('Select at least 1 city.', 'warning');
    }

    const payload = {
      full_name,
      email,
      phone,
      vehicle_info,
      status,
      city_ids: state.selectedCityIds.map(x => parseInt(x, 10)).filter(Boolean),
      knx_nonce: nonces.knx || '',
    };

    resetDriverModalUI();
    setLoading(true);
    state.busy.submit = true;
    setButtonBusy(driverSaveBtn, true, state.mode === 'add' ? 'Creating…' : 'Saving…');

    try {
      if (state.mode === 'add') {
        const out = await fetchJson(api.create, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonces.wpRest || '',
          },
          body: JSON.stringify(payload),
        });

        const body = out.data;
        if (!out.ok || !body || body.success !== true) {
          toast((body && (body.message || body.error)) || 'Failed to create driver.', 'error');
          return;
        }

        const info = body.data || {};
        if (info.username && info.temp_password) {
          setCredentials(info.username, info.temp_password);
        }

        // Switch to edit mode immediately (so Reset/Delete work without closing)
        const newId = parseInt(info.driver_id || info.user_id || 0, 10) || 0;
        if (newId > 0) {
          state.mode = 'edit';
          state.editingId = newId;
          driverForm.driver_id.value = String(newId);
          driverTitle.textContent = 'Edit Driver';
          driverSaveBtn.textContent = 'Save';
          resetBtn.hidden = false;
          deleteBtn.hidden = false;
        }

        toast('Driver created.', 'success');

        state.page = 1;
        await loadDrivers();
        return;
      }

      // edit mode
      const id = state.editingId;
      if (!id) {
        toast('Invalid driver ID.', 'error');
        return;
      }

      const url = apiDriverUrl(id, '/update');
      if (!url) {
        toast('Update endpoint missing.', 'error');
        return;
      }

      const out = await fetchJson(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonces.wpRest || '',
        },
        body: JSON.stringify(payload),
      });

      const body = out.data;
      if (!out.ok || !body || body.success !== true) {
        toast((body && (body.message || body.error)) || 'Failed to update driver.', 'error');
        return;
      }

      toast('Driver updated.', 'success');
      closeModal(modalDriver);
      await loadDrivers();
    } finally {
      state.busy.submit = false;
      setButtonBusy(driverSaveBtn, false);
      setLoading(false);
    }
  });

  // ------------------------------------------------------
  // Reset Password / Delete (inline confirm)
  // ------------------------------------------------------
  function askResetPassword() {
    if (state.mode !== 'edit' || !state.editingId) return;
    resetDriverModalUI();
    showInlineConfirm({
      mode: 'reset',
      title: 'Reset password',
      message: 'This will generate a new temporary password for this driver. Continue?',
      okLabel: 'Reset Password',
      showReason: false,
    });
  }

  function askSoftDelete() {
    if (state.mode !== 'edit' || !state.editingId) return;
    resetDriverModalUI();
    showInlineConfirm({
      mode: 'delete',
      title: 'Delete driver',
      message: 'Soft delete will remove this driver from lists and prevent login. Continue?',
      okLabel: 'Delete',
      showReason: true,
    });
  }

  async function doResetPassword(id) {
    const url = apiDriverUrl(id, '/reset-password');
    if (!url) {
      toast('Reset endpoint missing.', 'error');
      return;
    }

    if (state.busy.inlineAction) return;
    state.busy.inlineAction = true;

    setLoading(true);
    setButtonBusy(inlineOkBtn, true, 'Resetting…');

    try {
      const out = await fetchJson(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonces.wpRest || '',
        },
        body: JSON.stringify({ knx_nonce: nonces.knx || '' }),
      });

      const body = out.data;
      if (!out.ok || !body || body.success !== true) {
        toast((body && (body.message || body.error)) || 'Failed to reset password.', 'error');
        return;
      }

      const info = body.data || {};
      if (info.username && info.temp_password) {
        setCredentials(info.username, info.temp_password);
      }

      toast('Password reset.', 'success');
      hideInlineConfirm();
    } finally {
      setButtonBusy(inlineOkBtn, false);
      state.busy.inlineAction = false;
      setLoading(false);
    }
  }

  async function doSoftDelete(id, reason) {
    const url = apiDriverUrl(id, '/delete');
    if (!url) {
      toast('Delete endpoint missing.', 'error');
      return;
    }

    if (state.busy.inlineAction) return;
    state.busy.inlineAction = true;

    setLoading(true);
    setButtonBusy(inlineOkBtn, true, 'Deleting…');

    try {
      const payload = { knx_nonce: nonces.knx || '' };
      if (reason && reason.length) payload.reason = reason;

      const out = await fetchJson(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonces.wpRest || '',
        },
        body: JSON.stringify(payload),
      });

      const body = out.data;
      if (!out.ok || !body || body.success !== true) {
        toast((body && (body.message || body.error)) || 'Delete failed.', 'error');
        return;
      }

      toast('Driver deleted.', 'success');
      hideInlineConfirm();
      closeModal(modalDriver);
      await loadDrivers();
    } finally {
      setButtonBusy(inlineOkBtn, false);
      state.busy.inlineAction = false;
      setLoading(false);
    }
  }

  resetBtn.addEventListener('click', askResetPassword);
  deleteBtn.addEventListener('click', askSoftDelete);

  // Copy credential buttons
  $$('.knx-drv-copy', modalDriver).forEach(btn => {
    btn.addEventListener('click', async () => {
      const key = btn.dataset.copy;
      const val = (key === 'username') ? credUsername.textContent : credPassword.textContent;
      if (!val) return;

      try {
        await navigator.clipboard.writeText(val);
        toast('Copied.', 'success');
      } catch (e) {
        toast('Copy failed.', 'error');
      }
    });
  });

  // ------------------------------------------------------
  // Search / filters
  // ------------------------------------------------------
  qInput.addEventListener('input', debounce(() => {
    state.q = qInput.value.trim();
    state.page = 1;
    loadDrivers();
  }, 220));

  statusSel.addEventListener('change', () => {
    state.status = statusSel.value || '';
    state.page = 1;
    loadDrivers();
  });

  // ------------------------------------------------------
  // Init
  // ------------------------------------------------------
  (async function init() {
    await loadAllowedCitiesIfNeeded();
    await loadDrivers();
  })();
});
