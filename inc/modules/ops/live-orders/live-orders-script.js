/**
 * KNX OPS — Live Orders Script (Production)
 *
 * Keeps Block 1 compatibility:
 * - Sends canonical `city_ids[]` while keeping legacy `cities` CSV.
 *
 * Notes:
 * - No API call when no cities selected (fail-closed UX).
 * - Expects KNX REST response shape:
 *   { success, message, data: { orders: [...] } }
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('knxLiveOrdersApp');
    if (!app) return;

    // Dataset
    const apiUrl = app.dataset.apiUrl || '';
    const citiesUrl = app.dataset.citiesUrl || '';
    const role = app.dataset.role || '';
    // Default path for viewing a single order; can be overridden via data-view-order-url
    const viewOrderUrl = app.dataset.viewOrderUrl || '/view-order';
    const pollMs = Math.max(6000, Math.min(60000, parseInt(app.dataset.pollMs, 10) || 12000));

    const managedCities = (() => {
      try { return JSON.parse(app.dataset.managedCities || '[]'); } catch (e) { return []; }
    })();

    // Nodes
    const selectedCitiesPill = document.getElementById('knxLOSelectedCitiesPill');
    const selectCitiesBtn = document.getElementById('knxLOSelectCitiesBtn');
    const pulse = document.getElementById('knxLOPulse');
    const stateLine = document.getElementById('knxLOState');

    const listNew = document.getElementById('knxLOListNew');
    const listProgress = document.getElementById('knxLOListProgress');
    const listDone = document.getElementById('knxLOListDone');

    const countNew = document.getElementById('knxLOCountNew');
    const countProgress = document.getElementById('knxLOCountProgress');
    const countDone = document.getElementById('knxLOCountDone');

    // Modal nodes
    const modal = document.getElementById('knxLOModal');
    const cityListNode = document.getElementById('knxLOCityList');
    const applyBtn = document.getElementById('knxLOApplyBtn');
    const selectAllBtn = document.getElementById('knxLOSelectAllBtn');
    const clearBtn = document.getElementById('knxLOClearBtn');

    // State
    let selectedCities = [];
    let polling = false;
    let pollTimer = null;
    let abortController = null;

    function toast(msg, type = 'info') {
      if (typeof window.knxToast === 'function') return window.knxToast(msg, type);
      console.log('[knx-toast]', type, msg);
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"]/g, (s) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[s]));
    }

    function updatePill() {
      if (!selectedCitiesPill) return;
      if (!selectedCities || selectedCities.length === 0) {
        selectedCitiesPill.textContent = 'No cities selected';
        return;
      }
      selectedCitiesPill.textContent = `${selectedCities.length} city${selectedCities.length > 1 ? 'ies' : ''} selected`;
    }

    function openModal() {
      if (!modal) return;
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';

      if (cityListNode && (cityListNode.children.length === 0 || cityListNode.querySelector('.knx-lo-skel'))) {
        loadCities();
      }
    }

    function closeModal() {
      if (!modal) return;
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (selectCitiesBtn) selectCitiesBtn.focus();
    }

    if (modal) {
      modal.addEventListener('click', (ev) => {
        if (ev.target && (ev.target.matches('[data-close]') || ev.target === modal)) closeModal();
      });
    }

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && modal && modal.getAttribute('aria-hidden') === 'false') closeModal();
    });

    async function loadCities() {
      if (!cityListNode) return;

      cityListNode.innerHTML = '<div class="knx-lo-skel">Loading cities…</div>';

      try {
        const res = await fetch(citiesUrl, { credentials: 'same-origin' });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) throw new Error((json && json.message) ? json.message : 'Failed to fetch cities');

        // Accept multiple response shapes:
        // - raw array
        // - {results:[...]}
        // - {data:{cities:[...]}}
        let cities = [];
        if (Array.isArray(json)) cities = json;
        else if (Array.isArray(json.results)) cities = json.results;
        else if (json && json.data && Array.isArray(json.data.cities)) cities = json.data.cities;

        renderCityList(cities);
      } catch (err) {
        console.warn('Cities load failed', err);
        cityListNode.innerHTML = '<div class="knx-lo-empty">Unable to load cities</div>';
      }
    }

    function renderCityList(cities) {
      if (!cityListNode) return;

      cityListNode.innerHTML = '';

      if (!cities || cities.length === 0) {
        cityListNode.innerHTML = '<div class="knx-lo-empty">No cities available</div>';
        return;
      }

      const allowed = (role === 'manager' && Array.isArray(managedCities) && managedCities.length > 0)
        ? cities.filter(c => managedCities.includes(Number(c.id)))
        : cities;

      allowed.forEach(c => {
        const id = Number(c.id);
        const el = document.createElement('label');
        el.className = 'knx-lo-city';
        el.innerHTML = `
          <input type="checkbox" data-city-id="${id}" ${selectedCities.includes(id) ? 'checked' : ''} />
          <div class="knx-lo-city-name">${escapeHtml(c.name)}</div>
        `;
        el.addEventListener('click', (ev) => {
          if (ev.target && ev.target.tagName === 'INPUT') return;
          const cb = el.querySelector('input[type="checkbox"]');
          if (cb) cb.checked = !cb.checked;
        });
        cityListNode.appendChild(el);
      });
    }

    function applySelection() {
      if (!cityListNode) return;

      const boxes = cityListNode.querySelectorAll('input[type="checkbox"]');
      const sel = [];
      boxes.forEach(cb => {
        if (cb.checked) sel.push(Number(cb.getAttribute('data-city-id') || cb.value));
      });

      selectedCities = [...new Set(sel)].filter(n => Number.isFinite(n) && n > 0);

      updatePill();
      closeModal();
      restartPolling();
    }

    function selectAllCities() {
      if (!cityListNode) return;
      cityListNode.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = true; });
    }

    function clearCities() {
      if (!cityListNode) return;
      cityListNode.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });

      selectedCities = [];
      updatePill();
      stopPolling();
      renderOrders([]);

      if (stateLine) stateLine.textContent = 'Select at least one city to view live orders.';
    }

    // Block 1: Build query params with both legacy and canonical formats
    function buildQueryParams() {
      const p = new URLSearchParams();

      if (Array.isArray(selectedCities) && selectedCities.length > 0) {
        // Legacy CSV param (compat)
        p.set('cities', selectedCities.join(','));

        // Canonical repeated array param
        selectedCities.forEach((id) => {
          p.append('city_ids[]', String(id));
        });
      }

      return p.toString();
    }

    function parseOrdersPayload(json) {
      // Prefer KNX REST response: { success, message, data: { orders: [...] } }
      if (json && typeof json === 'object') {
        if (json.data && Array.isArray(json.data.orders)) return json.data.orders;
        if (Array.isArray(json.orders)) return json.orders;
        if (Array.isArray(json.results)) return json.results;
      }
      return Array.isArray(json) ? json : [];
    }

    // Bucketing is UI-only; backend is already hard-filtered to live statuses (Block 2).
    function statusBucket(status) {
      const st = String(status || '').toLowerCase();
      if (st === 'placed' || st === 'confirmed') return 'new';
      if (st === 'preparing' || st === 'assigned' || st === 'in_progress') return 'progress';
      return 'progress';
    }

    function renderOrders(orders) {
      const data = Array.isArray(orders) ? orders : [];

      const newList = [];
      const progressList = [];
      const doneList = []; // kept for layout compatibility (should remain empty in OPS v1)

      data.forEach(o => {
        const bucket = statusBucket(o.status);
        if (bucket === 'new') newList.push(o);
        else if (bucket === 'progress') progressList.push(o);
        else doneList.push(o);
      });

      populateList(listNew, newList);
      populateList(listProgress, progressList);
      populateList(listDone, doneList);

      if (countNew) countNew.textContent = String(newList.length);
      if (countProgress) countProgress.textContent = String(progressList.length);
      if (countDone) countDone.textContent = String(doneList.length);

      const total = newList.length + progressList.length + doneList.length;
      if (stateLine) {
        stateLine.textContent = total === 0
          ? 'No live orders in selected cities.'
          : `Showing ${total} order${total !== 1 ? 's' : ''}.`;
      }
    }

    function populateList(container, items) {
      if (!container) return;

      container.innerHTML = '';

      if (!items || items.length === 0) {
        container.innerHTML = '<div class="knx-lo-empty">No orders in this column</div>';
        return;
      }

      items.forEach(it => {
        const card = document.createElement('div');
        card.className = 'knx-order-card';

        const restaurant = escapeHtml(it.restaurant_name || it.hub_name || '');
        const customer = escapeHtml(it.customer_name || 'Customer');
        const created = escapeHtml(it.created_human || it.created_at || '');
        const status = escapeHtml(it.status || '');
        const total = (typeof it.total_amount === 'number') ? it.total_amount.toFixed(2) : escapeHtml(it.total_amount || '');
        const hasDriver = !!it.assigned_driver;

        card.innerHTML = `
          <div class="knx-order-meta">
            <div class="knx-order-row">
              <div>
                <div class="knx-order-title">${customer}</div>
                <div class="knx-order-sub">${restaurant} · ${created}</div>
              </div>
              <div class="knx-order-badges">
                <span class="knx-status-chip">${status}</span>
                ${hasDriver ? '<span class="knx-chip">Driver</span>' : ''}
              </div>
            </div>
            <div class="knx-order-footer">
              <div class="knx-order-total">$${escapeHtml(total)}</div>
              <div class="knx-order-actions">
                <button type="button" class="knx-view-order-btn">View</button>
              </div>
            </div>
          </div>
        `;

        const btn = card.querySelector('.knx-view-order-btn');
        btn.addEventListener('click', () => {
          const oid = Number(it.order_id || 0);
          if (!oid) {
            toast('Unable to open order', 'error');
            return;
          }
          const url = new URL(viewOrderUrl, window.location.origin);
          url.searchParams.set('order_id', String(oid));
          window.location.href = url.toString();
        });

        container.appendChild(card);
      });
    }

    async function fetchOrdersOnce() {
      if (!apiUrl) return [];

      // Fail-closed: do not call API when no cities selected
      if (!Array.isArray(selectedCities) || selectedCities.length === 0) {
        if (stateLine) stateLine.textContent = 'Select at least one city to view live orders.';
        return [];
      }

      if (abortController) {
        try { abortController.abort(); } catch (e) {}
        abortController = null;
      }
      abortController = new AbortController();

      try {
        const q = buildQueryParams();
        const url = q ? `${apiUrl}?${q}` : apiUrl;

        if (pulse) pulse.style.opacity = '1';

        const res = await fetch(url, {
          credentials: 'same-origin',
          signal: abortController.signal
        });

        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
          const msg = (json && json.message) ? json.message : (res.statusText || 'Request failed');
          throw new Error(`${res.status} ${msg}`);
        }

        return parseOrdersPayload(json);
      } catch (err) {
        if (err && err.name === 'AbortError') return [];
        console.warn('Fetch orders failed', err);
        toast('Unable to fetch live orders', 'error');
        return [];
      } finally {
        if (pulse) pulse.style.opacity = '1';
      }
    }

    async function pollLoop() {
      if (polling) return;
      polling = true;

      try {
        const orders = await fetchOrdersOnce();
        renderOrders(orders || []);
      } finally {
        polling = false;
        if (pollTimer) clearTimeout(pollTimer);
        pollTimer = setTimeout(() => pollLoop(), pollMs);
      }
    }

    function startPolling() {
      if (!Array.isArray(selectedCities) || selectedCities.length === 0) {
        stopPolling();
        return;
      }
      if (pollTimer) clearTimeout(pollTimer);
      pollLoop();
    }

    function stopPolling() {
      if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
      if (abortController) { try { abortController.abort(); } catch (e) {} abortController = null; }
      polling = false;
    }

    function restartPolling() {
      stopPolling();
      startPolling();
    }

    // Wire UI
    if (selectCitiesBtn) selectCitiesBtn.addEventListener('click', openModal);
    if (applyBtn) applyBtn.addEventListener('click', applySelection);
    if (selectAllBtn) selectAllBtn.addEventListener('click', selectAllCities);
    if (clearBtn) clearBtn.addEventListener('click', clearCities);

    // Init
    if (role === 'manager' && Array.isArray(managedCities) && managedCities.length > 0) {
      selectedCities = managedCities.slice().map(Number).filter(n => Number.isFinite(n) && n > 0);
      updatePill();
      startPolling();
    } else {
      updatePill();
      stopPolling();
      renderOrders([]);
    }

    window.addEventListener('beforeunload', () => stopPolling());
  });
})();
