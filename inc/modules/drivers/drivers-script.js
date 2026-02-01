/**
 * ==========================================================
 * Kingdom Nexus — Drivers Admin Script (v2.2) — REST v2 UI
 * ----------------------------------------------------------
 * Goals:
 * - No hardcoded "/drivers/*" paths (always use REST v2)
 * - Fail-closed if endpoints/nonce are missing
 * - Mobile-first friendly interactions
 * - Single Cities Modal (created once if missing)
 * - No debug logs / no console.log
 *
 * Expected (preferred) root markup:
 *  <div class="knx-drivers-wrapper"
 *    data-api-list="(absolute or relative)"
 *    data-api-create="..."
 *    data-api-get="..."
 *    data-api-update="..."
 *    data-api-toggle="..."
 *    data-api-reset="..."
 *    data-api-delete="..."
 *    data-api-allowed-cities="..."
 *    data-knx-nonce="..."
 *    data-wp-nonce="...">
 *  </div>
 *
 * Backward-compatible:
 * - Also accepts window.KNX_DRIVERS_CONFIG with same keys.
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const root =
    document.querySelector('.knx-drivers-wrapper') ||
    document.querySelector('#knx-drivers-admin') ||
    document.querySelector('[data-knx-drivers]');
  if (!root) return;

  // -----------------------------
  // Config / Endpoints
  // -----------------------------
  const cfg = getConfig(root);

  // Fail-closed if list endpoint is missing (everything depends on it)
  if (!cfg.apiList) {
    safeToast('Missing drivers list endpoint.', 'error');
    return;
  }

  // -----------------------------
  // DOM (all optional; script tolerates missing parts)
  // -----------------------------
  const els = {
    // Search / filters
    q: root.querySelector('[data-knx-q]') || root.querySelector('input[name="q"]') || root.querySelector('input[name="search"]'),
    status: root.querySelector('[data-knx-status]') || root.querySelector('select[name="status"]'),

    // List render targets
    tbody: root.querySelector('[data-knx-drivers-tbody]') || root.querySelector('tbody'),
    empty: root.querySelector('[data-knx-empty]'),
    pagination: root.querySelector('[data-knx-pagination]') || root.querySelector('.knx-pagination'),

    // Buttons
    addBtn: root.querySelector('#knxAddDriverBtn') || root.querySelector('[data-knx-add-driver]'),

    // Driver modal (Add/Edit)
    driverModal: document.getElementById('knxDriverModal'),
    driverForm: document.getElementById('knxDriverForm'),
    driverClose: document.getElementById('knxCloseDriverModal'),

    // Confirm modals (optional)
    confirmToggle: document.getElementById('knxConfirmDeactivateDriver'),
    confirmToggleCancel: document.getElementById('knxCancelDeactivateDriver'),
    confirmToggleOk: document.getElementById('knxConfirmDeactivateDriverBtn'),

    confirmDelete: document.getElementById('knxConfirmDeleteDriver'),
    confirmDeleteCancel: document.getElementById('knxCancelDeleteDriver'),
    confirmDeleteOk: document.getElementById('knxConfirmDeleteDriverBtn'),
    deleteReason: document.getElementById('knxDeleteReason'),

    // Reset password modal (optional)
    resetModal: document.getElementById('knxResetPasswordModal'),
    resetClose: document.getElementById('knxCloseResetPasswordModal'),
    resetOut: document.getElementById('knxResetPasswordOutput'),
  };

  // Cities modal (single instance)
  const citiesModal = ensureCitiesModal();

  // -----------------------------
  // State
  // -----------------------------
  const state = {
    page: 1,
    perPage: 20,
    q: '',
    status: '',
    allowedCities: null, // [{id,name}]
    modalMode: 'create', // 'create' | 'edit'
    editingId: 0,
    pendingToggleId: 0,
    pendingDeleteId: 0,
    selectedCityIds: [], // for modal + form
  };

  // -----------------------------
  // Init
  // -----------------------------
  wireBaseEvents();
  hydrateInitialStateFromURL();
  init();

  async function init() {
    await loadAllowedCitiesIfNeeded();
    await loadDrivers();
  }

  // ==========================================================
  // Networking
  // ==========================================================
  async function fetchJson(url, options) {
    const opts = options || {};
    opts.credentials = 'same-origin';

    opts.headers = Object.assign({}, opts.headers || {});
    // WP cookie-auth for REST writes needs X-WP-Nonce
    const wpNonce = cfg.wpNonce || getWpNonceFallback();
    if (wpNonce) opts.headers['X-WP-Nonce'] = wpNonce;

    const res = await fetch(url, opts);
    let data = null;

    try {
      data = await res.json();
    } catch (_) {
      // Fail-closed: non-JSON response is treated as an error
      data = null;
    }

    if (!res.ok) {
      const msg = (data && (data.message || data.error)) ? (data.message || data.error) : `Request failed (${res.status})`;
      throw new Error(msg);
    }
    return data;
  }

  function buildUrl(base, paramsObj) {
    const resolved = resolveUrl(base);
    if (!resolved) return null;

    const u = new URL(resolved, window.location.origin);
    const p = paramsObj || {};
    Object.keys(p).forEach((k) => {
      const v = p[k];
      if (v === null || v === undefined || v === '') return;
      u.searchParams.set(k, String(v));
    });
    return u.toString();
  }

  function resolveUrl(value) {
    const v = (value || '').trim();
    if (!v) return null;

    // absolute
    if (/^https?:\/\//i.test(v)) return v;

    // relative starting with /
    if (v.startsWith('/')) return window.location.origin + v;

    // relative without leading slash
    return window.location.origin + '/' + v.replace(/^\/+/, '');
  }

  // ==========================================================
  // Loaders
  // ==========================================================
  async function loadAllowedCitiesIfNeeded() {
    if (state.allowedCities) return;
    const url = resolveUrl(cfg.apiAllowedCities || '/wp-json/knx/v2/drivers/allowed-cities');
    if (!url) {
      // Not fatal for list, but required for create/edit cities
      state.allowedCities = [];
      return;
    }

    try {
      const out = await fetchJson(url, { method: 'GET' });
      if (!out || out.success !== true) {
        state.allowedCities = [];
        return;
      }
      const cities = (out.data && out.data.cities) ? out.data.cities : [];
      state.allowedCities = Array.isArray(cities) ? cities : [];
      renderCitiesModalList();
    } catch (e) {
      // Fail-closed: keep empty cities list (prevents unsafe assignment)
      state.allowedCities = [];
    }
  }

  async function loadDrivers() {
    const url = buildUrl(cfg.apiList, {
      page: state.page,
      per_page: state.perPage,
      q: state.q,
      status: state.status,
    });

    if (!url) {
      safeToast('Missing drivers list endpoint.', 'error');
      return;
    }

    setLoading(true);

    try {
      const out = await fetchJson(url, { method: 'GET' });

      if (!out || out.success !== true) {
        renderDrivers([]);
        renderPagination({ page: 1, total_pages: 0 });
        safeToast(out && out.message ? out.message : 'Unable to load drivers.', 'error');
        return;
      }

      const drivers = (out.data && out.data.drivers) ? out.data.drivers : [];
      const pag = (out.data && out.data.pagination) ? out.data.pagination : null;

      renderDrivers(Array.isArray(drivers) ? drivers : []);
      renderPagination(pag || { page: state.page, total_pages: 0 });
    } catch (e) {
      renderDrivers([]);
      renderPagination({ page: 1, total_pages: 0 });
      safeToast(e.message || 'Network error loading drivers.', 'error');
    } finally {
      setLoading(false);
    }
  }

  // ==========================================================
  // Mutations
  // ==========================================================
  async function createDriver(payload) {
    if (!cfg.apiCreate) return safeToast('Missing create endpoint.', 'error');
    if (!cfg.knxNonce) return safeToast('Missing security token.', 'error');

    payload.knx_nonce = cfg.knxNonce;

    const url = resolveUrl(cfg.apiCreate);
    try {
      const out = await fetchJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      if (!out || out.success !== true) {
        safeToast(out && out.message ? out.message : 'Failed to create driver.', 'error');
        return;
      }

      // temp password is sensitive; show only if your UI expects it
      const temp = out.data && out.data.temp_password ? String(out.data.temp_password) : '';
      if (temp) {
        showResetPassword(temp);
      } else {
        safeToast('Driver created.', 'success');
      }

      closeDriverModal();
      state.page = 1;
      await loadDrivers();
    } catch (e) {
      safeToast(e.message || 'Network error creating driver.', 'error');
    }
  }

  async function updateDriver(id, payload) {
    const endpoint = interpolateEndpoint(cfg.apiUpdate, id) || `/wp-json/knx/v2/drivers/${id}/update`;
    const url = resolveUrl(endpoint);
    if (!url) return safeToast('Missing update endpoint.', 'error');
    if (!cfg.knxNonce) return safeToast('Missing security token.', 'error');

    payload.knx_nonce = cfg.knxNonce;

    try {
      const out = await fetchJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      if (!out || out.success !== true) {
        safeToast(out && out.message ? out.message : 'Failed to update driver.', 'error');
        return;
      }

      safeToast('Driver updated.', 'success');
      closeDriverModal();
      await loadDrivers();
    } catch (e) {
      safeToast(e.message || 'Network error updating driver.', 'error');
    }
  }

  async function toggleDriver(id) {
    const endpoint = interpolateEndpoint(cfg.apiToggle, id) || `/wp-json/knx/v2/drivers/${id}/toggle`;
    const url = resolveUrl(endpoint);
    if (!url) return safeToast('Missing toggle endpoint.', 'error');
    if (!cfg.knxNonce) return safeToast('Missing security token.', 'error');

    try {
      const out = await fetchJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ knx_nonce: cfg.knxNonce }),
      });

      if (!out || out.success !== true) {
        safeToast(out && out.message ? out.message : 'Toggle failed.', 'error');
        return;
      }

      safeToast('Status updated.', 'success');
      await loadDrivers();
    } catch (e) {
      safeToast(e.message || 'Network error toggling driver.', 'error');
    }
  }

  async function resetPassword(id) {
    const endpoint = interpolateEndpoint(cfg.apiReset, id) || `/wp-json/knx/v2/drivers/${id}/reset-password`;
    const url = resolveUrl(endpoint);
    if (!url) return safeToast('Missing reset endpoint.', 'error');
    if (!cfg.knxNonce) return safeToast('Missing security token.', 'error');

    try {
      const out = await fetchJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ knx_nonce: cfg.knxNonce }),
      });

      if (!out || out.success !== true) {
        safeToast(out && out.message ? out.message : 'Reset failed.', 'error');
        return;
      }

      const temp = out.data && out.data.temp_password ? String(out.data.temp_password) : '';
      if (!temp) {
        safeToast('Password reset, but no temp password returned.', 'warning');
        return;
      }

      showResetPassword(temp);
    } catch (e) {
      safeToast(e.message || 'Network error resetting password.', 'error');
    }
  }

  async function softDeleteDriver(id, reason) {
    const endpoint = interpolateEndpoint(cfg.apiDelete, id) || `/wp-json/knx/v2/drivers/${id}/delete`;
    const url = resolveUrl(endpoint);
    if (!url) return safeToast('Missing delete endpoint.', 'error');
    if (!cfg.knxNonce) return safeToast('Missing security token.', 'error');

    const payload = { knx_nonce: cfg.knxNonce };
    if (reason && String(reason).trim() !== '') payload.reason = String(reason).trim();

    try {
      const out = await fetchJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      if (!out || out.success !== true) {
        safeToast(out && out.message ? out.message : 'Delete failed.', 'error');
        return;
      }

      safeToast('Driver deleted.', 'success');
      await loadDrivers();
    } catch (e) {
      safeToast(e.message || 'Network error deleting driver.', 'error');
    }
  }

  // ==========================================================
  // Rendering
  // ==========================================================
  function renderDrivers(drivers) {
    if (!els.tbody) return;

    const list = Array.isArray(drivers) ? drivers : [];

    if (list.length === 0) {
      els.tbody.innerHTML = '';
      if (els.empty) els.empty.style.display = '';
      return;
    }
    if (els.empty) els.empty.style.display = 'none';

    els.tbody.innerHTML = list.map((d) => rowHtml(d)).join('');

    // Row actions (delegated)
    els.tbody.querySelectorAll('[data-action="edit"]').forEach((btn) => {
      btn.addEventListener('click', function () {
        const id = toInt(btn.getAttribute('data-id'));
        openEditDriver(id);
      });
    });

    els.tbody.querySelectorAll('[data-action="toggle"]').forEach((sw) => {
      sw.addEventListener('change', function () {
        const id = toInt(sw.getAttribute('data-id'));
        const checked = !!sw.checked;

        // If switching off, ask confirmation if confirm modal exists
        if (!checked) {
          if (els.confirmToggle && els.confirmToggleOk) {
            state.pendingToggleId = id;
            openModal(els.confirmToggle);
          } else {
            // Native confirm fallback (still acceptable if modal missing)
            const ok = window.confirm('Deactivate this driver?');
            if (!ok) {
              sw.checked = true;
              return;
            }
            toggleDriver(id);
          }
          return;
        }

        // switching on
        toggleDriver(id);
      });
    });

    els.tbody.querySelectorAll('[data-action="reset"]').forEach((btn) => {
      btn.addEventListener('click', function () {
        const id = toInt(btn.getAttribute('data-id'));
        resetPassword(id);
      });
    });

    els.tbody.querySelectorAll('[data-action="delete"]').forEach((btn) => {
      btn.addEventListener('click', function () {
        const id = toInt(btn.getAttribute('data-id'));
        if (els.confirmDelete && els.confirmDeleteOk) {
          state.pendingDeleteId = id;
          if (els.deleteReason) els.deleteReason.value = '';
          openModal(els.confirmDelete);
        } else {
          const ok = window.confirm('Delete this driver?');
          if (!ok) return;
          softDeleteDriver(id, '');
        }
      });
    });
  }

  function rowHtml(d) {
    const id = toInt(d && d.id);
    const name = esc(d && d.full_name ? d.full_name : '');
    const email = esc(d && d.email ? d.email : '');
    const phone = esc(d && d.phone ? d.phone : '');
    const vehicle = esc(d && d.vehicle_info ? d.vehicle_info : '');
    const status = (d && d.status === 'active') ? 'active' : 'inactive';
    const checked = status === 'active' ? 'checked' : '';

    // City labels (if allowedCities loaded)
    const cityNames = cityNamesFromIds(d && d.city_ids ? d.city_ids : []);
    const cityLine = cityNames.length ? `<div class="knx-driver__meta">${esc(cityNames.join(', '))}</div>` : '';

    return `
      <tr data-id="${id}">
        <td>
          <div class="knx-driver__who">
            <div class="knx-driver__name">${name}</div>
            <div class="knx-driver__meta">${email}${phone ? ` • ${phone}` : ''}</div>
            ${vehicle ? `<div class="knx-driver__meta">${vehicle}</div>` : ''}
            ${cityLine}
          </div>
        </td>

        <td>
          <span class="status-${status}">${status === 'active' ? 'Active' : 'Inactive'}</span>
        </td>

        <td class="knx-actions">
          <button type="button" class="knx-icon-btn" data-action="edit" data-id="${id}" aria-label="Edit">
            ✎
          </button>

          <label class="knx-switch" aria-label="Toggle driver">
            <input type="checkbox" data-action="toggle" data-id="${id}" ${checked}>
            <span class="knx-slider"></span>
          </label>

          <button type="button" class="knx-icon-btn" data-action="reset" data-id="${id}" aria-label="Reset password">
            🔑
          </button>

          <button type="button" class="knx-icon-btn danger" data-action="delete" data-id="${id}" aria-label="Delete">
            🗑
          </button>
        </td>
      </tr>
    `;
  }

  function renderPagination(pag) {
    if (!els.pagination) return;

    const page = toInt(pag && pag.page) || state.page;
    const totalPages = toInt(pag && pag.total_pages) || 0;

    state.page = page;

    if (totalPages <= 1) {
      els.pagination.innerHTML = '';
      return;
    }

    const btn = (label, target, disabled, active) => {
      const cls = [
        'knx-page',
        active ? 'active' : '',
        disabled ? 'disabled' : ''
      ].join(' ').trim();
      const disAttr = disabled ? 'aria-disabled="true"' : '';
      return `<button type="button" class="${cls}" data-page="${target}" ${disAttr}>${label}</button>`;
    };

    let html = '';
    html += btn('Prev', Math.max(1, page - 1), page <= 1, false);

    // Compact window
    const windowSize = 5;
    const start = Math.max(1, page - Math.floor(windowSize / 2));
    const end = Math.min(totalPages, start + windowSize - 1);
    const realStart = Math.max(1, end - windowSize + 1);

    for (let i = realStart; i <= end; i++) {
      html += btn(String(i), i, false, i === page);
    }

    html += btn('Next', Math.min(totalPages, page + 1), page >= totalPages, false);

    els.pagination.innerHTML = html;

    els.pagination.querySelectorAll('button[data-page]').forEach((b) => {
      b.addEventListener('click', function () {
        if (b.classList.contains('disabled')) return;
        const p = toInt(b.getAttribute('data-page'));
        if (!p || p === state.page) return;
        state.page = p;
        syncURL();
        loadDrivers();
      });
    });
  }

  function setLoading(isLoading) {
    root.classList.toggle('is-loading', !!isLoading);
  }

  // ==========================================================
  // Driver Modal (Add/Edit) + Single Cities Modal
  // ==========================================================
  function wireBaseEvents() {
    // Search
    if (els.q) {
      els.q.addEventListener('input', debounce(function () {
        state.q = String(els.q.value || '').trim();
        state.page = 1;
        syncURL();
        loadDrivers();
      }, 250));
    }

    if (els.status) {
      els.status.addEventListener('change', function () {
        state.status = String(els.status.value || '').trim();
        state.page = 1;
        syncURL();
        loadDrivers();
      });
    }

    // Add button
    if (els.addBtn) {
      els.addBtn.addEventListener('click', function () {
        openCreateDriver();
      });
    }

    // Close driver modal
    if (els.driverClose) {
      els.driverClose.addEventListener('click', function () {
        closeDriverModal();
      });
    }

    // Submit driver form
    if (els.driverForm) {
      els.driverForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitDriverForm();
      });

      // Open cities picker (single modal) via any button inside form with data-action="pick-cities"
      els.driverForm.querySelectorAll('[data-action="pick-cities"]').forEach((btn) => {
        btn.addEventListener('click', function (ev) {
          ev.preventDefault();
          openCitiesModalFromForm();
        });
      });
    }

    // Confirm toggle modal
    if (els.confirmToggle && els.confirmToggleCancel) {
      els.confirmToggleCancel.addEventListener('click', function () {
        closeModal(els.confirmToggle);
        state.pendingToggleId = 0;
        // restore switch UI by reloading list
        loadDrivers();
      });
    }
    if (els.confirmToggle && els.confirmToggleOk) {
      els.confirmToggleOk.addEventListener('click', function () {
        const id = state.pendingToggleId;
        closeModal(els.confirmToggle);
        state.pendingToggleId = 0;
        if (id) toggleDriver(id);
      });
    }

    // Confirm delete modal
    if (els.confirmDelete && els.confirmDeleteCancel) {
      els.confirmDeleteCancel.addEventListener('click', function () {
        closeModal(els.confirmDelete);
        state.pendingDeleteId = 0;
      });
    }
    if (els.confirmDelete && els.confirmDeleteOk) {
      els.confirmDeleteOk.addEventListener('click', function () {
        const id = state.pendingDeleteId;
        const reason = els.deleteReason ? String(els.deleteReason.value || '') : '';
        closeModal(els.confirmDelete);
        state.pendingDeleteId = 0;
        if (id) softDeleteDriver(id, reason);
      });
    }

    // Reset password modal close
    if (els.resetClose && els.resetModal) {
      els.resetClose.addEventListener('click', function () {
        closeModal(els.resetModal);
        if (els.resetOut) els.resetOut.textContent = '';
      });
    }

    // ESC closes any open modal we manage
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (els.driverModal && els.driverModal.classList.contains('active')) closeDriverModal();
      if (citiesModal && citiesModal.classList.contains('active')) closeModal(citiesModal);
      if (els.confirmToggle && els.confirmToggle.classList.contains('active')) closeModal(els.confirmToggle);
      if (els.confirmDelete && els.confirmDelete.classList.contains('active')) closeModal(els.confirmDelete);
      if (els.resetModal && els.resetModal.classList.contains('active')) closeModal(els.resetModal);
    });
  }

  function openCreateDriver() {
    state.modalMode = 'create';
    state.editingId = 0;
    state.selectedCityIds = [];
    fillDriverForm({});
    openDriverModal();
  }

  async function openEditDriver(id) {
    if (!id) return;

    state.modalMode = 'edit';
    state.editingId = id;

    // Load driver details if endpoint is available, otherwise rely on list-only UI
    const endpoint = interpolateEndpoint(cfg.apiGet, id) || `/wp-json/knx/v2/drivers/${id}`;
    const url = resolveUrl(endpoint);
    if (!url) {
      safeToast('Missing driver get endpoint.', 'error');
      return;
    }

    try {
      const out = await fetchJson(url, { method: 'GET' });
      if (!out || out.success !== true || !out.data) {
        safeToast(out && out.message ? out.message : 'Unable to load driver.', 'error');
        return;
      }

      const d = out.data;
      state.selectedCityIds = Array.isArray(d.city_ids) ? d.city_ids.map(toInt).filter(Boolean) : [];
      fillDriverForm(d);
      openDriverModal();
    } catch (e) {
      safeToast(e.message || 'Network error loading driver.', 'error');
    }
  }

  function openDriverModal() {
    if (!els.driverModal) return;
    openModal(els.driverModal);

    // Focus first input
    const first = els.driverModal.querySelector('input,select,textarea,button');
    if (first) setTimeout(function () { first.focus(); }, 120);
  }

  function closeDriverModal() {
    if (!els.driverModal) return;
    closeModal(els.driverModal);
    // do not reset state automatically; keep predictable
  }

  function fillDriverForm(d) {
    if (!els.driverForm) return;

    setVal('full_name', d.full_name || '');
    setVal('email', d.email || '');
    setVal('phone', d.phone || '');
    setVal('vehicle_info', d.vehicle_info || '');
    setVal('status', d.status || 'active');

    // Store city ids in hidden input as JSON (safe for arrays)
    const hid = els.driverForm.querySelector('input[name="city_ids_json"]') || els.driverForm.querySelector('input[name="city_ids"]');
    if (hid) hid.value = JSON.stringify(state.selectedCityIds || []);

    // Update any chips/label area if present
    const chips = els.driverForm.querySelector('[data-knx-city-chips]');
    if (chips) chips.innerHTML = renderCityChips(state.selectedCityIds);

    function setVal(name, value) {
      const el = els.driverForm.querySelector(`[name="${name}"]`);
      if (!el) return;
      el.value = value;
    }
  }

  function submitDriverForm() {
    if (!els.driverForm) return;

    const payload = readDriverFormPayload();
    if (!payload) return;

    if (state.modalMode === 'create') return createDriver(payload);
    if (state.modalMode === 'edit' && state.editingId) return updateDriver(state.editingId, payload);

    safeToast('Invalid modal state.', 'error');
  }

  function readDriverFormPayload() {
    if (!els.driverForm) return null;

    const fullName = readStr('full_name');
    const email = readStr('email');
    const phone = readStr('phone');
    const vehicle = readStr('vehicle_info');
    const status = readStr('status') || 'active';

    if (!fullName) return safeToast('full_name is required.', 'warning');
    if (!email || !isEmail(email)) return safeToast('Valid email is required.', 'warning');

    // city_ids is required on create by API contract
    const ids = Array.isArray(state.selectedCityIds) ? state.selectedCityIds.map(toInt).filter(Boolean) : [];
    if (state.modalMode === 'create' && ids.length === 0) {
      safeToast('Please select at least one city.', 'warning');
      return null;
    }

    return {
      full_name: fullName,
      email: email,
      phone: phone,
      vehicle_info: vehicle,
      status: (status === 'inactive') ? 'inactive' : 'active',
      city_ids: ids,
    };

    function readStr(name) {
      const el = els.driverForm.querySelector(`[name="${name}"]`);
      return el ? String(el.value || '').trim() : '';
    }
  }

  function openCitiesModalFromForm() {
    // Ensure allowed cities loaded
    if (!state.allowedCities) {
      loadAllowedCitiesIfNeeded().then(function () {
        openCitiesModal();
      });
      return;
    }
    openCitiesModal();
  }

  function openCitiesModal() {
    renderCitiesModalList();
    // Mark current selection
    syncCitiesModalChecks();
    openModal(citiesModal);
    const first = citiesModal.querySelector('input,button');
    if (first) setTimeout(function () { first.focus(); }, 120);
  }

  function renderCitiesModalList() {
    if (!citiesModal) return;

    const list = citiesModal.querySelector('[data-knx-cities-list]');
    if (!list) return;

    const cities = Array.isArray(state.allowedCities) ? state.allowedCities : [];
    if (cities.length === 0) {
      list.innerHTML = `<div class="knx-cities-empty">No cities available.</div>`;
      return;
    }

    list.innerHTML = cities
      .map(function (c) {
        const id = toInt(c.id);
        const name = esc(String(c.name || ''));
        return `
          <label class="knx-city-row">
            <input type="checkbox" class="knx-city-check" data-city-id="${id}">
            <span class="knx-city-name">${name}</span>
          </label>
        `;
      })
      .join('');

    // Search
    const search = citiesModal.querySelector('[data-knx-cities-search]');
    if (search && !search.__knxBound) {
      search.__knxBound = true;
      search.addEventListener('input', debounce(function () {
        filterCitiesInModal(String(search.value || '').trim().toLowerCase());
      }, 150));
    }
  }

  function filterCitiesInModal(q) {
    const list = citiesModal.querySelector('[data-knx-cities-list]');
    if (!list) return;
    const rows = list.querySelectorAll('.knx-city-row');
    rows.forEach(function (row) {
      const nameEl = row.querySelector('.knx-city-name');
      const text = nameEl ? String(nameEl.textContent || '').toLowerCase() : '';
      row.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
    });
  }

  function syncCitiesModalChecks() {
    const checks = citiesModal.querySelectorAll('.knx-city-check');
    const selected = new Set((state.selectedCityIds || []).map(toInt).filter(Boolean));
    checks.forEach(function (ch) {
      const id = toInt(ch.getAttribute('data-city-id'));
      ch.checked = selected.has(id);
    });
  }

  function commitCitiesSelectionFromModal() {
    const checks = citiesModal.querySelectorAll('.knx-city-check');
    const ids = [];
    checks.forEach(function (ch) {
      if (!ch.checked) return;
      const id = toInt(ch.getAttribute('data-city-id'));
      if (id) ids.push(id);
    });
    state.selectedCityIds = ids;

    // sync to form hidden input
    if (els.driverForm) {
      const hid = els.driverForm.querySelector('input[name="city_ids_json"]') || els.driverForm.querySelector('input[name="city_ids"]');
      if (hid) hid.value = JSON.stringify(ids);

      const chips = els.driverForm.querySelector('[data-knx-city-chips]');
      if (chips) chips.innerHTML = renderCityChips(ids);
    }
  }

  function renderCityChips(ids) {
    const names = cityNamesFromIds(ids);
    if (!names.length) return `<span class="knx-muted">No cities selected</span>`;
    return names.map(function (n) {
      return `<span class="knx-chip">${esc(n)}</span>`;
    }).join(' ');
  }

  function showResetPassword(tempPassword) {
    if (els.resetModal && els.resetOut) {
      els.resetOut.textContent = tempPassword;
      openModal(els.resetModal);
      return;
    }
    safeToast(`Temp password: ${tempPassword}`, 'success');
  }

  // ==========================================================
  // Cities Modal creation (single instance)
  // ==========================================================
  function ensureCitiesModal() {
    let modal = document.getElementById('knxDriverCitiesModal');
    if (modal) {
      wireCitiesModal(modal);
      return modal;
    }

    // Create once (small + scoped)
    modal = document.createElement('div');
    modal.id = 'knxDriverCitiesModal';
    modal.className = 'knx-modal knx-cities-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
      <div class="knx-modal-content knx-cities-content" role="dialog" aria-modal="true" aria-label="Select Cities">
        <div class="knx-cities-head">
          <h3>Select Cities</h3>
          <button type="button" class="knx-icon-btn" data-knx-close-cities aria-label="Close">✕</button>
        </div>

        <div class="knx-cities-tools">
          <input type="text" data-knx-cities-search placeholder="Search cities..." autocomplete="off">
        </div>

        <div class="knx-cities-list" data-knx-cities-list></div>

        <div class="knx-cities-actions">
          <button type="button" class="knx-btn-secondary" data-knx-cancel-cities>Cancel</button>
          <button type="button" class="knx-btn" data-knx-save-cities>Save</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    wireCitiesModal(modal);
    return modal;
  }

  function wireCitiesModal(modal) {
    const closeBtn = modal.querySelector('[data-knx-close-cities]');
    const cancelBtn = modal.querySelector('[data-knx-cancel-cities]');
    const saveBtn = modal.querySelector('[data-knx-save-cities]');

    if (closeBtn && !closeBtn.__knxBound) {
      closeBtn.__knxBound = true;
      closeBtn.addEventListener('click', function () { closeModal(modal); });
    }
    if (cancelBtn && !cancelBtn.__knxBound) {
      cancelBtn.__knxBound = true;
      cancelBtn.addEventListener('click', function () { closeModal(modal); });
    }
    if (saveBtn && !saveBtn.__knxBound) {
      saveBtn.__knxBound = true;
      saveBtn.addEventListener('click', function () {
        commitCitiesSelectionFromModal();
        closeModal(modal);
      });
    }
  }

  // ==========================================================
  // URL sync (optional but helpful)
  // ==========================================================
  function hydrateInitialStateFromURL() {
    const p = new URLSearchParams(window.location.search);
    const q = p.get('q') || p.get('search') || '';
    const status = p.get('status') || '';
    const page = toInt(p.get('page') || p.get('paged') || '1') || 1;

    state.q = String(q || '').trim();
    state.status = String(status || '').trim();
    state.page = page;

    if (els.q && state.q) els.q.value = state.q;
    if (els.status && state.status) els.status.value = state.status;
  }

  function syncURL() {
    // Non-authoritative; only affects URL params
    const u = new URL(window.location.href);
    u.searchParams.set('page', String(state.page || 1));
    if (state.q) u.searchParams.set('q', state.q);
    else u.searchParams.delete('q');

    if (state.status) u.searchParams.set('status', state.status);
    else u.searchParams.delete('status');

    window.history.replaceState({}, '', u.toString());
  }

  // ==========================================================
  // Helpers
  // ==========================================================
  function getConfig(rootEl) {
    const d = rootEl.dataset || {};
    const g = window.KNX_DRIVERS_CONFIG || {};

    const apiList = firstNonEmpty(d.apiList, d.apiDriversList, g.apiList, g.apiDriversList, '/wp-json/knx/v2/drivers/list');
    const apiCreate = firstNonEmpty(d.apiCreate, d.apiDriversCreate, g.apiCreate, g.apiDriversCreate, '/wp-json/knx/v2/drivers/create');
    const apiGet = firstNonEmpty(d.apiGet, d.apiDriversGet, g.apiGet, g.apiDriversGet, '/wp-json/knx/v2/drivers/{id}');
    const apiUpdate = firstNonEmpty(d.apiUpdate, d.apiDriversUpdate, g.apiUpdate, g.apiDriversUpdate, '/wp-json/knx/v2/drivers/{id}/update');
    const apiToggle = firstNonEmpty(d.apiToggle, d.apiDriversToggle, g.apiToggle, g.apiDriversToggle, '/wp-json/knx/v2/drivers/{id}/toggle');
    const apiReset = firstNonEmpty(d.apiReset, d.apiDriversReset, g.apiReset, g.apiDriversReset, '/wp-json/knx/v2/drivers/{id}/reset-password');
    const apiDelete = firstNonEmpty(d.apiDelete, d.apiDriversDelete, g.apiDelete, g.apiDriversDelete, '/wp-json/knx/v2/drivers/{id}/delete');
    const apiAllowedCities = firstNonEmpty(
      d.apiAllowedCities, d.apiDriversAllowedCities, g.apiAllowedCities, g.apiDriversAllowedCities,
      '/wp-json/knx/v2/drivers/allowed-cities'
    );

    const knxNonce = firstNonEmpty(d.knxNonce, d.nonce, g.knxNonce, g.nonce, window.KNX_NONCE);
    const wpNonce = firstNonEmpty(d.wpNonce, g.wpNonce, window.KNX_WP_NONCE);

    return {
      apiList: resolveUrl(apiList),
      apiCreate: resolveUrl(apiCreate),
      apiGet: resolveUrl(apiGet),
      apiUpdate: resolveUrl(apiUpdate),
      apiToggle: resolveUrl(apiToggle),
      apiReset: resolveUrl(apiReset),
      apiDelete: resolveUrl(apiDelete),
      apiAllowedCities: resolveUrl(apiAllowedCities),
      knxNonce: (knxNonce || '').trim(),
      wpNonce: (wpNonce || '').trim(),
    };
  }

  function interpolateEndpoint(template, id) {
    const t = (template || '').trim();
    if (!t) return null;
    if (t.indexOf('{id}') !== -1) return t.replace('{id}', String(id));
    // If someone passed "/drivers/(?P<id>\d+)" style, just fallback; we won’t parse that.
    return t;
  }

  function getWpNonceFallback() {
    // Common WP pattern
    if (window.wpApiSettings && window.wpApiSettings.nonce) return String(window.wpApiSettings.nonce);
    // Some installs expose it on a global
    if (window.WP_NONCE) return String(window.WP_NONCE);
    return '';
  }

  function firstNonEmpty() {
    for (let i = 0; i < arguments.length; i++) {
      const v = arguments[i];
      if (typeof v === 'string' && v.trim() !== '') return v.trim();
    }
    return '';
  }

  function openModal(el) {
    if (!el) return;
    el.classList.add('active');
    el.setAttribute('aria-hidden', 'false');
    document.body.classList.add('knx-modal-open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal(el) {
    if (!el) return;
    el.classList.remove('active');
    el.setAttribute('aria-hidden', 'true');
    // Only restore if no other modal is open
    const anyOpen = document.querySelector('.knx-modal.active');
    if (!anyOpen) {
      document.body.classList.remove('knx-modal-open');
      document.body.style.overflow = '';
    }
  }

  function safeToast(msg, type) {
    if (typeof window.knxToast === 'function') {
      window.knxToast(String(msg || ''), String(type || 'info'));
      return null;
    }
    // Fail silently if toast system is unavailable
    return null;
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function toInt(v) {
    const n = parseInt(String(v || '0'), 10);
    return Number.isFinite(n) ? n : 0;
  }

  function isEmail(v) {
    const s = String(v || '').trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
  }

  function debounce(fn, wait) {
    let t = 0;
    return function () {
      const args = arguments;
      window.clearTimeout(t);
      t = window.setTimeout(function () { fn.apply(null, args); }, wait);
    };
  }

  function cityNamesFromIds(ids) {
    if (!Array.isArray(ids) || !Array.isArray(state.allowedCities)) return [];
    const map = new Map(state.allowedCities.map((c) => [toInt(c.id), String(c.name || '')]));
    return ids.map(toInt).filter(Boolean).map((id) => map.get(id)).filter(Boolean);
  }
});
