// ==========================================================
// FILE: inc/modules/fees/fees-script.js
// ==========================================================
/**
 * ==========================================================
 * KNX Software Fees — Admin UI Script
 * ----------------------------------------------------------
 * - City cards view
 * - Edit view: city fee + per-hub overrides
 * - Aligns payload with DB schema: city_id, hub_id, fee_amount
 * ==========================================================
 */

(function () {
  'use strict';

  const root = document.querySelector('.knx-fees');
  if (!root) return;

  const API = {
    list: root.getAttribute('data-api-list') || '',
    save: root.getAttribute('data-api-save') || '',
    toggle: root.getAttribute('data-api-toggle') || '',
    hubs: root.getAttribute('data-api-hubs') || '',
    nonce: root.getAttribute('data-nonce') || ''
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

  const elCards = document.getElementById('knxFeesCards');
  const elEdit = document.getElementById('knxFeesEdit');
  const elBack = document.getElementById('knxFeesBackBtn');

  const elEditTitle = document.getElementById('knxFeesEditTitle');
  const elEditCityName = document.getElementById('knxFeesEditCityName');

  const elCityForm = document.getElementById('knxFeesCityForm');
  const elCityId = document.getElementById('knxFeesCityId');
  const elCityFeeId = document.getElementById('knxFeesCityFeeId');
  const elCityAmount = document.getElementById('knxFeesCityAmount');
  const elCityActive = document.getElementById('knxFeesCityActive');
  const elCitySave = document.getElementById('knxFeesCitySaveBtn');

  const elHubsList = document.getElementById('knxFeesHubsList');

  function toast(msg, type) {
    if (typeof window.knx_toast === 'function') {
      window.knx_toast(msg, type || 'info');
      return;
    }
    console.log('[KNX]', msg);
  }

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function fetchJson(url, options) {
    const opts = options || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});

    try {
      const res = await fetch(url, opts);
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        return { success: false, message: 'Invalid JSON response.' };
      }
    } catch (err) {
      return { success: false, message: 'Network error.' };
    }
  }

  // Normalize knx_rest_response shape
  function pickData(res) {
    if (!res) return null;
    if (res.data) return res.data;
    return res;
  }

  function buildFeeIndex(list) {
    // We want the most recent row per key
    // City key: city_id + hub_id=0
    // Hub key: hub_id
    const byCity = new Map();
    const byHub = new Map();

    (list || []).forEach(row => {
      const scope = String(row.scope || '');
      const id = Number(row.id || 0);

      if (scope === 'city') {
        const cityId = Number(row.city_id || 0);
        const hubId = Number(row.hub_id || 0);
        if (cityId > 0 && hubId === 0) {
          const prev = byCity.get(cityId);
          if (!prev || Number(prev.id || 0) < id) byCity.set(cityId, row);
        }
      }

      if (scope === 'hub') {
        const hubId = Number(row.hub_id || 0);
        if (hubId > 0) {
          const prev = byHub.get(hubId);
          if (!prev || Number(prev.id || 0) < id) byHub.set(hubId, row);
        }
      }
    });

    return { byCity, byHub };
  }

  function fmtMoney(n) {
    const x = Number(n);
    if (!isFinite(x)) return '$0.00';
    return '$' + x.toFixed(2);
  }

  async function loadFees() {
    const res = await fetchJson(API.list, { method: 'GET' });
    if (!res || res.success !== true) {
      toast((res && (res.message || res.error)) || 'Failed to load fees.', 'error');
      if (elCards) elCards.innerHTML = `<div class="knx-fees__empty"><p>${esc('Failed to load fees.')}</p></div>`;
      return;
    }

    const data = pickData(res);
    fees = (data && Array.isArray(data.fees)) ? data.fees : [];

    renderCards();
  }

  function renderCards() {
    if (!elCards) return;

    if (!cities.length) {
      elCards.innerHTML = `<div class="knx-fees__empty"><p>${esc('No cities found.')}</p></div>`;
      return;
    }

    const { byCity } = buildFeeIndex(fees);

    elCards.innerHTML = cities.map(c => {
      const cityId = Number(c.id || 0);
      const cityName = c.name || `City #${cityId}`;

      const row = byCity.get(cityId);
      const has = !!row;
      const active = has && String(row.status || '') === 'active';

      const badge = has ? (active ? 'Active' : 'Inactive') : 'Not set';
      const badgeClass = has ? (active ? 'is-active' : 'is-inactive') : 'is-muted';

      const amount = has ? fmtMoney(row.fee_amount) : '—';

      return `
        <div class="knx-fees__card">
          <div class="knx-fees__cardTop">
            <h3>${esc(cityName)}</h3>
            <span class="knx-fees__badge ${esc(badgeClass)}">${esc(badge)}</span>
          </div>
          <div class="knx-fees__cardMid">
            <div class="knx-fees__kv">
              <span>Fee</span>
              <strong>${esc(amount)}</strong>
            </div>
          </div>
          <div class="knx-fees__cardBot">
            <button class="knx-fees__btn knx-fees__btn--primary" data-action="edit" data-city-id="${esc(cityId)}">Edit</button>
          </div>
        </div>
      `;
    }).join('');
  }

  function showEdit(cityId) {
    const city = cities.find(x => String(x.id) === String(cityId));
    if (!city) {
      toast('City not found.', 'error');
      return;
    }

    currentCityId = Number(cityId);

    if (elCards) elCards.style.display = 'none';
    if (elEdit) elEdit.style.display = 'block';

    if (elEditTitle) elEditTitle.textContent = `Edit City Fee`;
    if (elEditCityName) elEditCityName.textContent = city.name || `City #${city.id}`;

    // Load city fee row (latest)
    const { byCity } = buildFeeIndex(fees);
    const row = byCity.get(currentCityId);

    if (elCityId) elCityId.value = String(currentCityId);
    if (elCityFeeId) elCityFeeId.value = row ? String(row.id || '') : '';

    if (elCityAmount) elCityAmount.value = row ? String(row.fee_amount ?? '') : '';
    if (elCityActive) elCityActive.checked = row ? (String(row.status) === 'active') : true;

    loadHubs(currentCityId);
  }

  function hideEdit() {
    currentCityId = null;
    if (elEdit) elEdit.style.display = 'none';
    if (elCards) elCards.style.display = 'grid';
  }

  async function saveCityFee(e) {
    e.preventDefault();

    const cityId = elCityId ? Number(elCityId.value) : NaN;
    const feeId = elCityFeeId ? String(elCityFeeId.value || '').trim() : '';

    if (!isFinite(cityId) || cityId <= 0) {
      toast('Invalid city id.', 'error');
      return;
    }

    const amount = elCityAmount ? Number(elCityAmount.value) : NaN;
    if (!isFinite(amount) || amount < 0) {
      toast('Fee amount must be >= 0.', 'error');
      return;
    }

    const status = (elCityActive && elCityActive.checked) ? 'active' : 'inactive';

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

    toast('City fee saved.', 'success');
    await loadFees();
    showEdit(cityId);
  }

  async function loadHubs(cityId) {
    if (!elHubsList) return;

    elHubsList.innerHTML = `
      <div class="knx-fees__loading">
        <div class="knx-fees__spinner"></div>
        <p>Loading hubs...</p>
      </div>
    `;

    const res = await fetchJson(API.hubs + '?city_id=' + encodeURIComponent(String(cityId)), { method: 'GET' });
    if (!res || res.success !== true) {
      elHubsList.innerHTML = `<div class="knx-fees__empty"><p>${esc('Failed to load hubs.')}</p></div>`;
      return;
    }

    const data = pickData(res);
    const hubs = (data && Array.isArray(data.hubs)) ? data.hubs : [];

    if (!hubs.length) {
      elHubsList.innerHTML = `<div class="knx-fees__empty"><p>${esc('No hubs found in this city.')}</p></div>`;
      return;
    }

    renderHubs(hubs);
  }

  function renderHubs(hubs) {
    if (!elHubsList) return;

    const { byHub } = buildFeeIndex(fees);

    elHubsList.innerHTML = hubs.map(h => {
      const hubId = Number(h.id || 0);
      const hubName = h.name || `Hub #${hubId}`;

      const row = byHub.get(hubId);
      const has = !!row;
      const active = has && String(row.status || '') === 'active';

      const amount = has ? String(row.fee_amount ?? '') : '';
      const statusText = has ? (active ? 'Active override' : 'Inactive override') : 'Uses city fee';

      return `
        <div class="knx-fees__hub">
          <div class="knx-fees__hubLeft">
            <div class="knx-fees__hubName">${esc(hubName)}</div>
            <div class="knx-fees__hubMeta">${esc(statusText)}</div>
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
              <button class="knx-fees__btn knx-fees__btn--small knx-fees__btn--ghost"
                      data-action="remove-hub"
                      data-fee-id="${esc(row.id || '')}">
                Remove
              </button>
            ` : ``}
          </div>
        </div>
      `;
    }).join('');
  }

  async function saveHubOverride(hubId, feeId) {
    const input = root.querySelector(`[data-hub-amount="${CSS.escape(String(hubId))}"]`);
    const amount = input ? Number(input.value) : NaN;

    if (!isFinite(amount) || amount < 0) {
      toast('Fee amount must be >= 0.', 'error');
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

  async function removeHubOverride(feeId) {
    if (!feeId) return;

    const ok = confirm('Remove this hub override? The hub will use the city fee.');
    if (!ok) return;

    const res = await fetchJson(API.toggle, {
      method: 'POST',
      body: JSON.stringify({ id: Number(feeId), knx_nonce: API.nonce })
    });

    if (!res || res.success !== true) {
      toast((res && (res.message || res.error)) || 'Failed to remove override.', 'error');
      return;
    }

    toast('Hub override removed.', 'success');
    await loadFees();
    if (currentCityId) showEdit(currentCityId);
  }

  function onCardsClick(e) {
    const btn = e.target.closest('[data-action="edit"]');
    if (!btn) return;
    const cityId = btn.getAttribute('data-city-id');
    if (cityId) showEdit(cityId);
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

  function init() {
    if (elBack) elBack.addEventListener('click', hideEdit);
    if (elCards) elCards.addEventListener('click', onCardsClick);
    if (elCityForm) elCityForm.addEventListener('submit', saveCityFee);
    if (elHubsList) elHubsList.addEventListener('click', onHubsClick);

    loadFees();
  }

  init();
})();
