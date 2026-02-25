// ==========================================================
// KNX Software Fees — Nexus Shell Admin UI Script
// ----------------------------------------------------------
// City SSOT + Hub Overrides — Canonical Duality
// ==========================================================

(function () {
  'use strict';

  const root = document.querySelector('.knx-fees');
  if (!root) return;

  const API = {
    list:   root.getAttribute('data-api-list')   || '',
    save:   root.getAttribute('data-api-save')   || '',
    toggle: root.getAttribute('data-api-toggle') || '',
    hubs:   root.getAttribute('data-api-hubs')   || '',
    nonce:  root.getAttribute('data-nonce')       || ''
  };

  let cities = [];
  try {
    const raw = root.getAttribute('data-cities');
    cities = raw ? JSON.parse(raw) : [];
  } catch (e) {
    cities = [];
  }

  let fees = [];
  let currentCityId = null;

  const elCards     = document.getElementById('knxFeesCards');
  const elEdit      = document.getElementById('knxFeesEdit');
  const elBack      = document.getElementById('knxFeesBackBtn');

  const elEditTitle    = document.getElementById('knxFeesEditTitle');
  const elEditCityName = document.getElementById('knxFeesEditCityName');

  const elCityForm   = document.getElementById('knxFeesCityForm');
  const elCityId     = document.getElementById('knxFeesCityId');
  const elCityFeeId  = document.getElementById('knxFeesCityFeeId');
  const elCityAmount = document.getElementById('knxFeesCityAmount');
  const elCitySave   = document.getElementById('knxFeesCitySaveBtn');

  const elHubsList = document.getElementById('knxFeesHubsList');

  /* ── Helpers ──────────────────────────────────────────── */

  function toast(msg, type) {
    if (typeof window.knx_toast === 'function') {
      window.knx_toast(msg, type || 'info');
      return;
    }
    console.log('[KNX Fees]', type || 'info', msg);
  }

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function fmtMoney(n) {
    const x = Number(n);
    if (!isFinite(x)) return '$0.00';
    return '$' + x.toFixed(2);
  }

  async function fetchJson(url, options) {
    const opts = options || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});

    try {
      const res = await fetch(url, opts);
      const text = await res.text();
      try { return JSON.parse(text); }
      catch (e) { return { success: false, message: 'Invalid JSON response.' }; }
    } catch (err) {
      return { success: false, message: 'Network error.' };
    }
  }

  function pickData(res) {
    if (!res) return null;
    return res.data || res;
  }

  /* ── Fee Index ────────────────────────────────────────── */

  function buildFeeIndex(list) {
    const byCity = new Map();
    const byHub  = new Map();

    (list || []).forEach(row => {
      const scope = String(row.scope || '');
      const id    = Number(row.id || 0);
      const hubId = Number(row.hub_id || row.hub_id_safe || 0);

      if (scope === 'city') {
        const cityId = Number(row.city_id || 0);
        if (cityId > 0 && hubId === 0) {
          const prev = byCity.get(cityId);
          if (!prev || Number(prev.id || 0) < id) byCity.set(cityId, row);
        }
      }

      if (scope === 'hub') {
        if (hubId > 0) {
          const prev = byHub.get(hubId);
          if (!prev || Number(prev.id || 0) < id) byHub.set(hubId, row);
        }
      }
    });

    return { byCity, byHub };
  }

  /* ── Load Fees ────────────────────────────────────────── */

  async function loadFees() {
    const res = await fetchJson(API.list, { method: 'GET' });
    if (!res || res.success !== true) {
      toast((res && (res.message || res.error)) || 'Failed to load fees.', 'error');
      if (elCards) elCards.innerHTML = `<div class="knx-fees__empty"><p>Unable to load fee data.</p></div>`;
      return;
    }

    const data = pickData(res);
    fees = (data && Array.isArray(data.fees)) ? data.fees : [];

    renderCards();
  }

  /* ── Cards View ───────────────────────────────────────── */

  function renderCards() {
    if (!elCards) return;

    if (!cities.length) {
      elCards.innerHTML = `<div class="knx-fees__empty"><p>No cities have been created yet.</p></div>`;
      return;
    }

    const { byCity } = buildFeeIndex(fees);

    // Count hub overrides per city
    const hubOverrideCounts = new Map();
    (fees || []).forEach(row => {
      if (String(row.scope) === 'hub' && String(row.status) === 'active') {
        const cid = Number(row.city_id || 0);
        if (cid > 0) hubOverrideCounts.set(cid, (hubOverrideCounts.get(cid) || 0) + 1);
      }
    });

    elCards.innerHTML = cities.map(c => {
      const cityId   = Number(c.id || 0);
      const cityName = c.name || ('City #' + cityId);

      const row    = byCity.get(cityId);
      const has    = !!row;
      const active = has && String(row.status || '') === 'active';

      const badge      = has ? (active ? 'Active' : 'Inactive') : 'Not configured';
      const badgeClass = has ? (active ? 'is-active' : 'is-inactive') : 'is-muted';
      const amount     = has ? fmtMoney(row.fee_amount) : '—';

      const overrides = hubOverrideCounts.get(cityId) || 0;

      return `
        <div class="knx-fees__card">
          <div class="knx-fees__cardTop">
            <div class="knx-fees__cardLeft">
              <div class="knx-fees__cardIcon" aria-hidden="true">🏙</div>
              <h3>${esc(cityName)}</h3>
            </div>
            <span class="knx-fees__badge ${esc(badgeClass)}">${esc(badge)}</span>
          </div>
          <div class="knx-fees__cardMid">
            <div class="knx-fees__cardStats">
              <div class="knx-fees__cardStat">
                <span>City Fee</span>
                <strong>${esc(amount)}</strong>
              </div>
              ${overrides > 0 ? `
                <div class="knx-fees__cardStat">
                  <span>Overrides</span>
                  <strong>${overrides}</strong>
                </div>
              ` : ''}
            </div>
          </div>
          <div class="knx-fees__cardBot">
            <label class="knx-fees__toggle">
              <span class="knx-fees__switch">
                <input type="checkbox" ${active ? 'checked' : ''} data-action="toggle-city" data-city-id="${esc(cityId)}" data-fee-id="${esc(has ? (row.id || '') : '')}">
                <span class="knx-fees__switchTrack"></span>
              </span>
              <span class="knx-fees__toggleText">Active</span>
            </label>
            <button class="knx-btn knx-fees__btn knx-fees__btn--primary knx-fees__btn--small" data-action="edit" data-city-id="${esc(cityId)}">Manage</button>
          </div>
        </div>
      `;
    }).join('');
  }

  /* ── Edit View ────────────────────────────────────────── */

  function showEdit(cityId) {
    const city = cities.find(x => String(x.id) === String(cityId));
    if (!city) {
      toast('City not found.', 'error');
      return;
    }

    currentCityId = Number(cityId);

    if (elCards) elCards.style.display = 'none';
    if (elEdit) elEdit.style.display = 'block';

    if (elEditTitle) elEditTitle.textContent = 'Configure Fees';
    if (elEditCityName) elEditCityName.textContent = city.name || ('City #' + city.id);

    // Load city fee row (latest)
    const { byCity } = buildFeeIndex(fees);
    const row = byCity.get(currentCityId);

    if (elCityId) elCityId.value = String(currentCityId);
    if (elCityFeeId) elCityFeeId.value = row ? String(row.id || '') : '';

    if (elCityAmount) elCityAmount.value = row ? String(row.fee_amount ?? '') : '';

    loadHubs(currentCityId);
  }

  function hideEdit() {
    currentCityId = null;
    if (elEdit) elEdit.style.display = 'none';
    if (elCards) elCards.style.display = 'grid';
  }

  /* ── Save City Fee ────────────────────────────────────── */

  async function saveCityFee(e) {
    e.preventDefault();

    const cityId = elCityId ? Number(elCityId.value) : NaN;
    const feeId  = elCityFeeId ? String(elCityFeeId.value || '').trim() : '';

    if (!isFinite(cityId) || cityId <= 0) {
      toast('Invalid city.', 'error');
      return;
    }

    const amount = elCityAmount ? Number(elCityAmount.value) : NaN;
    if (!isFinite(amount) || amount < 0) {
      toast('Fee amount must be $0.00 or more.', 'error');
      return;
    }

    // Status is controlled by the card toggle - don't change it here
    // Read current status from DB row
    const { byCity } = buildFeeIndex(fees);
    const currentRow = byCity.get(cityId);
    const status = (currentRow && String(currentRow.status)) || 'active';

    const payload = {
      scope: 'city',
      city_id: cityId,
      hub_id: 0,
      fee_amount: amount,
      status: status,
      knx_nonce: API.nonce
    };
    if (feeId) payload.id = Number(feeId);

    if (elCitySave) elCitySave.disabled = true;

    const res = await fetchJson(API.save, {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    if (elCitySave) elCitySave.disabled = false;

    if (!res || res.success !== true) {
      toast((res && (res.message || res.error)) || 'Failed to save city fee.', 'error');
      return;
    }

    toast('City fee saved successfully.', 'success');
    await loadFees();
    showEdit(cityId);
  }

  /* ── Load Hubs ────────────────────────────────────────── */

  async function loadHubs(cityId) {
    if (!elHubsList) return;

    elHubsList.innerHTML = `
      <div class="knx-fees__loading">
        <div class="knx-fees__spinner"></div>
        <p>Loading hubs&hellip;</p>
      </div>
    `;

    const res = await fetchJson(API.hubs + '?city_id=' + encodeURIComponent(String(cityId)), { method: 'GET' });
    if (!res || res.success !== true) {
      elHubsList.innerHTML = `<div class="knx-fees__empty"><p>Unable to load hubs.</p></div>`;
      return;
    }

    const data = pickData(res);
    const hubs = (data && Array.isArray(data.hubs)) ? data.hubs : [];

    if (!hubs.length) {
      elHubsList.innerHTML = `<div class="knx-fees__empty"><p>No hubs found in this city.</p></div>`;
      return;
    }

    renderHubs(hubs);
  }

  /* ── Render Hubs ──────────────────────────────────────── */

  function renderHubs(hubs) {
    if (!elHubsList) return;

    const { byCity, byHub } = buildFeeIndex(fees);
    const cityRow = byCity.get(currentCityId);
    const cityFee = (cityRow && String(cityRow.status) === 'active')
      ? fmtMoney(cityRow.fee_amount)
      : null;

    elHubsList.innerHTML = hubs.map(h => {
      const hubId   = Number(h.id || 0);
      const hubName = h.name || ('Hub #' + hubId);

      const row    = byHub.get(hubId);
      const has    = !!row;
      const active = has && String(row.status || '') === 'active';

      const amount = has ? String(row.fee_amount ?? '') : '';

      let statusText, dotClass;
      if (has && active) {
        statusText = 'Custom override: ' + fmtMoney(row.fee_amount);
        dotClass   = 'knx-fees__hubDot--orange';
      } else if (cityFee) {
        statusText = 'Using city default: ' + cityFee;
        dotClass   = 'knx-fees__hubDot--green';
      } else {
        statusText = 'No fee configured';
        dotClass   = 'knx-fees__hubDot--gray';
      }

      return `
        <div class="knx-fees__hub">
          <div class="knx-fees__hubLeft">
            <div class="knx-fees__hubName">${esc(hubName)}</div>
            <div class="knx-fees__hubMeta">
              <span class="knx-fees__hubDot ${esc(dotClass)}"></span>
              ${esc(statusText)}
            </div>
          </div>

          <div class="knx-fees__hubRight">
            <input
              class="knx-fees__input knx-fees__hubInput"
              type="number"
              min="0"
              step="0.01"
              placeholder="Override $"
              value="${esc(amount)}"
              data-hub-amount="${esc(hubId)}"
            />
            <button class="knx-fees__btn knx-fees__btn--small knx-fees__btn--primary"
                    data-action="save-hub"
                    data-hub-id="${esc(hubId)}"
                    data-fee-id="${esc(has ? (row.id || '') : '')}">
              Save
            </button>
            ${has && active ? `
              <button class="knx-fees__btn knx-fees__btn--small knx-fees__btn--danger"
                      data-action="remove-hub"
                      data-fee-id="${esc(row.id || '')}">
                Remove
              </button>
            ` : ''}
          </div>
        </div>
      `;
    }).join('');
  }

  /* ── Save Hub Override ────────────────────────────────── */

  async function saveHubOverride(hubId, feeId) {
    const input  = root.querySelector('[data-hub-amount="' + CSS.escape(String(hubId)) + '"]');
    const amount = input ? Number(input.value) : NaN;

    if (!isFinite(amount) || amount < 0) {
      toast('Fee amount must be $0.00 or more.', 'error');
      return;
    }

    const payload = {
      scope: 'hub',
      city_id: Number(currentCityId),
      hub_id: Number(hubId),
      fee_amount: amount,
      status: 'active',
      knx_nonce: API.nonce
    };
    if (feeId) payload.id = Number(feeId);

    const res = await fetchJson(API.save, {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    if (!res || res.success !== true) {
      toast((res && (res.message || res.error)) || 'Failed to save hub override.', 'error');
      return;
    }

    toast('Hub override saved.', 'success');
    await loadFees();
    if (currentCityId) showEdit(currentCityId);
  }

  /* ── Remove Hub Override ──────────────────────────────── */

  async function removeHubOverride(feeId) {
    if (!feeId) return;

    const ok = confirm('Remove this hub override?\n\nThe hub will revert to the city default fee.');
    if (!ok) return;

    const res = await fetchJson(API.toggle, {
      method: 'POST',
      body: JSON.stringify({ id: Number(feeId), knx_nonce: API.nonce })
    });

    if (!res || res.success !== true) {
      toast((res && (res.message || res.error)) || 'Failed to remove override.', 'error');
      return;
    }

    toast('Hub override removed — city default restored.', 'success');
    await loadFees();
    if (currentCityId) showEdit(currentCityId);
  }

  /* ── Event Delegation ─────────────────────────────────── */

  async function onCardsClick(e) {
    // Handle toggle
    const toggle = e.target.closest('[data-action="toggle-city"]');
    if (toggle) {
      e.preventDefault();
      await handleCityToggle(toggle);
      return;
    }

    // Handle edit
    const btn = e.target.closest('[data-action="edit"]');
    if (!btn) return;
    const cityId = btn.getAttribute('data-city-id');
    if (cityId) showEdit(cityId);
  }

  async function handleCityToggle(checkbox) {
    const cityId = checkbox.getAttribute('data-city-id');
    const feeId = checkbox.getAttribute('data-fee-id');
    const isChecked = checkbox.checked;

    // If no fee exists yet, create one
    if (!feeId || feeId === '') {
      const { byCity } = buildFeeIndex(fees);
      const row = byCity.get(Number(cityId));
      const amount = row ? Number(row.fee_amount || 0) : 0;

      const payload = {
        knx_nonce: API.nonce,
        scope: 'city',
        city_id: cityId,
        hub_id: 0,
        fee_amount: amount,
        status: isChecked ? 'active' : 'inactive'
      };

      const res = await fetchJson(API.save, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      if (!res || res.success !== true) {
        checkbox.checked = !isChecked; // Revert
        toast((res && (res.message || res.error)) || 'Failed to create fee.', 'error');
        return;
      }

      toast('Fee created successfully.', 'success');
      await loadFees();
      return;
    }

    // Otherwise, toggle existing fee
    const payload = {
      knx_nonce: API.nonce,
      id: feeId
    };

    const res = await fetchJson(API.toggle, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (!res || res.success !== true) {
      checkbox.checked = !isChecked; // Revert
      toast((res && (res.message || res.error)) || 'Failed to toggle status.', 'error');
      return;
    }

    toast('Status updated.', 'success');
    await loadFees();
  }

  function onHubsClick(e) {
    const saveBtn = e.target.closest('[data-action="save-hub"]');
    if (saveBtn) {
      const hubId = saveBtn.getAttribute('data-hub-id');
      const feeId = saveBtn.getAttribute('data-fee-id');
      if (hubId) saveHubOverride(hubId, feeId);
      return;
    }

    const removeBtn = e.target.closest('[data-action="remove-hub"]');
    if (removeBtn) {
      const feeId = removeBtn.getAttribute('data-fee-id');
      if (feeId) removeHubOverride(feeId);
      return;
    }
  }

  /* ── Init ─────────────────────────────────────────────── */

  function init() {
    if (elBack) elBack.addEventListener('click', hideEdit);
    if (elCards) elCards.addEventListener('click', onCardsClick);
    if (elCityForm) elCityForm.addEventListener('submit', saveCityFee);
    if (elHubsList) elHubsList.addEventListener('click', onHubsClick);

    loadFees();
  }

  init();
})();