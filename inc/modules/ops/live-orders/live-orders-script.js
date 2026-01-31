/**
 * KNX OPS — Live Orders Script (NEXUS SHELL UX)
 * Replace this file completely.
 *
 * Features:
 * - Accessible modal for city selection
 * - Polling for live orders with graceful backoff and restart
 * - Render orders as cards (new / in progress / completed)
 * - Use data-* attributes from the shortcode container
 * - Use knxToast() if available for feedback
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('knxLiveOrdersApp');
    if (!app) return;

    // Read dataset
    const apiUrl = app.dataset.apiUrl;
    const citiesUrl = app.dataset.citiesUrl;
    const role = app.dataset.role || '';
    const managedCities = (() => {
      try { return JSON.parse(app.dataset.managedCities || '[]'); } catch (e) { return []; }
    })();
    const viewOrderUrl = app.dataset.viewOrderUrl || '/ops-view-order';
    const pollMs = Math.max(6000, Math.min(60000, parseInt(app.dataset.pollMs, 10) || 12000));
    const includeResolved = app.dataset.includeResolved === '1';
    const resolvedHours = parseInt(app.dataset.resolvedHours || '24', 10);

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
    const modalPanel = modal && modal.querySelector('.knx-lo-modal__panel');
    const cityListNode = document.getElementById('knxLOCityList');
    const applyBtn = document.getElementById('knxLOApplyBtn');
    const selectAllBtn = document.getElementById('knxLOSelectAllBtn');
    const clearBtn = document.getElementById('knxLOClearBtn');

    // State
    let selectedCities = []; // array of city ids (integers)
    let polling = false;
    let pollTimer = null;
    let abortController = null;

    // Utility: toast
    function toast(msg, type = 'info') {
      if (typeof knxToast === 'function') return knxToast(msg, type);
      console.log('[knx-toast]', type, msg);
    }

    // Set initial pill text
    function updatePill() {
      if (!selectedCities || selectedCities.length === 0) {
        selectedCitiesPill.textContent = 'No cities selected';
        selectedCitiesPill.setAttribute('aria-live', 'polite');
        return;
      }
      selectedCitiesPill.textContent = `${selectedCities.length} city${selectedCities.length > 1 ? 'ies' : ''} selected`;
    }

    // Open modal
    function openModal() {
      if (!modal) return;
      modal.setAttribute('aria-hidden', 'false');
      // fetch cities if empty
      if (!cityListNode || cityListNode.children.length === 0 || cityListNode.querySelector('.knx-lo-skel')) {
        loadCities();
      }
      // focus on modal
      setTimeout(() => {
        const firstCheckbox = cityListNode && cityListNode.querySelector('input[type="checkbox"]');
        if (firstCheckbox) firstCheckbox.focus();
      }, 140);
      document.body.style.overflow = 'hidden';
    }

    // Close modal
    function closeModal() {
      if (!modal) return;
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      selectCitiesBtn.focus();
    }

    // Modal backdrop click (close)
    if (modal) {
      modal.addEventListener('click', (ev) => {
        if (ev.target.matches('[data-close]') || ev.target === modal) closeModal();
      });
    }

    // Keyboard: ESC closes modal
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        if (modal && modal.getAttribute('aria-hidden') === 'false') closeModal();
      }
    });

    // Load cities from API
    async function loadCities() {
      if (!cityListNode) return;
      cityListNode.innerHTML = '<div class="knx-lo-skel">Loading cities…</div>';
      try {
        const res = await fetch(citiesUrl, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('Failed to fetch cities');
        const json = await res.json();
        // expected: array of { id, name }
        renderCityList(Array.isArray(json) ? json : (json.results || []));
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

      // If manager has managedCities, restrict view but still show them as checked
      const allowed = (role === 'manager' && Array.isArray(managedCities) && managedCities.length > 0)
        ? cities.filter(c => managedCities.includes(Number(c.id)))
        : cities;

      // Render each city
      allowed.forEach(c => {
        const id = Number(c.id);
        const el = document.createElement('label');
        el.className = 'knx-lo-city';
        el.tabIndex = 0;
        el.innerHTML = `
          <input type="checkbox" data-city-id="${id}" ${selectedCities.includes(id) ? 'checked' : ''} />
          <div class="knx-lo-city-name">${escapeHtml(c.name)}</div>
        `;
        // Toggle when clicking label
        el.addEventListener('click', (ev) => {
          if (ev.target.tagName === 'INPUT') return; // native toggle
          const cb = el.querySelector('input[type="checkbox"]');
          cb.checked = !cb.checked;
        });
        cityListNode.appendChild(el);
      });
    }

    // Helper: escapeHtml
    function escapeHtml(str) {
      return String(str).replace(/[&<>"]/g, (s) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[s]));
    }

    // Apply selection from modal
    function applySelection() {
      const boxes = cityListNode.querySelectorAll('input[type="checkbox"]');
      const sel = [];
      boxes.forEach(cb => {
        if (cb.checked) sel.push(Number(cb.dataset.cityId || cb.getAttribute('data-city-id') || cb.value));
      });
      selectedCities = [...new Set(sel)].filter(Boolean);
      updatePill();
      closeModal();
      restartPolling(); // refresh immediately with new filters
    }

    // Select all / Clear handlers
    function selectAllCities() {
      const boxes = cityListNode.querySelectorAll('input[type="checkbox"]');
      boxes.forEach(cb => cb.checked = true);
    }
    function clearCities() {
      const boxes = cityListNode.querySelectorAll('input[type="checkbox"]');
      boxes.forEach(cb => cb.checked = false);
      // Clear selection state and stop polling (fail-closed UX)
      selectedCities = [];
      updatePill();
      stopPolling();
      if (stateLine) stateLine.textContent = 'Select at least one city to view live orders.';
    }

    // Build API query params
    function buildQueryParams() {
      const p = new URLSearchParams();
      if (selectedCities && selectedCities.length) p.set('cities', selectedCities.join(','));
      if (includeResolved) {
        p.set('include_resolved', '1');
        p.set('resolved_hours', String(resolvedHours || 24));
      } else {
        p.set('include_resolved', '0');
      }
      return p.toString();
    }

    // Render orders into columns
    function renderOrders(data) {
      // expected structure: array of orders each with { id, status, hub_name, display_text, thumbnail_url, created_at }
      if (!Array.isArray(data)) data = [];

      const newList = [];
      const progressList = [];
      const doneList = [];

      data.forEach(o => {
        const st = (o.status || 'new').toLowerCase();
        if (st === 'new') newList.push(o);
        else if (st === 'in_progress' || st === 'accepted' || st === 'preparing') progressList.push(o);
        else doneList.push(o);
      });

      populateList(listNew, newList, 'new');
      populateList(listProgress, progressList, 'progress');
      populateList(listDone, doneList, 'done');

      countNew.textContent = String(newList.length);
      countProgress.textContent = String(progressList.length);
      countDone.textContent = String(doneList.length);

      // Update state line
      const total = newList.length + progressList.length + doneList.length;
      stateLine.textContent = total === 0 ? 'No orders currently in selection.' : `Showing ${total} order${total !== 1 ? 's' : ''}.`;
    }

    function populateList(container, items, type) {
      container.innerHTML = '';
      if (!items || items.length === 0) {
        container.innerHTML = '<div class="knx-lo-empty">No orders in this column</div>';
        return;
      }
      items.forEach(it => {
        const card = document.createElement('div');
        card.className = 'knx-order-card';
        card.innerHTML = `
          <img class="knx-order-thumb" src="${it.thumbnail_url || ''}" alt="" onerror="this.style.opacity=0.6; this.style.background='#f4f5f6';" />
          <div class="knx-order-meta">
            <div class="knx-order-row">
              <div>
                <div class="knx-order-title">${escapeHtml(it.display_text || ('Order #' + (it.id || '')))}</div>
                <div class="knx-order-sub">${escapeHtml(it.hub_name || '')} · ${escapeHtml(it.created_at || '')}</div>
              </div>
              <div class="knx-order-badges">
                ${renderStatusBadge(type)}
              </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:6px;" class="knx-order-actions">
              <button data-order-id="${it.id}" class="knx-view-order-btn">View</button>
            </div>
          </div>
        `;
        // View button
        const btn = card.querySelector('.knx-view-order-btn');
        btn.addEventListener('click', () => {
          // navigate to view order page with id
          const url = new URL(viewOrderUrl, window.location.origin);
          url.searchParams.set('order_id', String(it.id || ''));
          window.location.href = url.toString();
        });
        container.appendChild(card);
      });
    }

    function renderStatusBadge(type) {
      if (type === 'new') return `<span class="knx-status-new">NEW</span>`;
      if (type === 'progress') return `<span class="knx-status-progress">IN PROGRESS</span>`;
      return `<span class="knx-status-done">COMPLETED</span>`;
    }

    // Polling logic
    async function fetchOrdersOnce() {
      if (!apiUrl) return [];
      // Fail-closed: do not call API when no cities are selected
      if (!Array.isArray(selectedCities) || selectedCities.length === 0) {
        if (stateLine) stateLine.textContent = 'Select at least one city to view live orders.';
        return [];
      }
      // abort previous fetch if running
      if (abortController) {
        try { abortController.abort(); } catch(e) {}
        abortController = null;
      }
      abortController = new AbortController();
      const signal = abortController.signal;
      try {
        const q = buildQueryParams();
        const url = q ? `${apiUrl}?${q}` : apiUrl;
        pulse.style.opacity = '1';
        const res = await fetch(url, { credentials: 'same-origin', signal });
        pulse.style.opacity = '0.6';
        if (!res.ok) {
          const txt = await res.text().catch(()=>null);
          throw new Error(res.status + ' ' + (txt || res.statusText));
        }
        const json = await res.json();
        pulse.style.opacity = '1';
        return Array.isArray(json) ? json : (json.results || []);
      } catch (err) {
        if (err.name === 'AbortError') return [];
        console.warn('Fetch orders failed', err);
        toast('Unable to fetch live orders', 'error');
        return [];
      } finally {
        try { pulse.style.opacity = '1'; } catch(e){}
      }
    }

    async function pollLoop() {
      // Protect: do not poll if polling already active or no cities selected
      if (polling) return;
      if (!Array.isArray(selectedCities) || selectedCities.length === 0) {
        stopPolling();
        return;
      }
      polling = true;
      try {
        const orders = await fetchOrdersOnce();
        renderOrders(orders || []);
      } finally {
        polling = false;
        // schedule next poll
        if (pollTimer) clearTimeout(pollTimer);
        pollTimer = setTimeout(() => pollLoop(), pollMs);
      }
    }

    function startPolling() {
      // Do not start polling if there are no selected cities (fail-closed)
      if (!Array.isArray(selectedCities) || selectedCities.length === 0) {
        stopPolling();
        return;
      }
      if (pollTimer) clearTimeout(pollTimer);
      pollLoop();
    }
    function stopPolling() {
      if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
      if (abortController) { try { abortController.abort(); } catch(e){} abortController = null; }
      polling = false;
    }
    function restartPolling() {
      stopPolling();
      startPolling();
    }

    // Wire up UI handlers
    selectCitiesBtn && selectCitiesBtn.addEventListener('click', openModal);
    applyBtn && applyBtn.addEventListener('click', applySelection);
    selectAllBtn && selectAllBtn.addEventListener('click', selectAllCities);
    clearBtn && clearBtn.addEventListener('click', clearCities);

    // Initialize selectedCities and polling behavior.
    // Manager: auto-select managed cities and start polling when at least one exists.
    // Super Admin: do NOT start polling automatically; wait for user selection.
    if (role === 'manager' && Array.isArray(managedCities) && managedCities.length > 0) {
      selectedCities = managedCities.slice();
      updatePill();
      startPolling();
    } else {
      updatePill();
      stopPolling();
    }

    // Clean up on unload
    window.addEventListener('beforeunload', () => {
      stopPolling();
    });

    // Expose small debug API on app element
    app.knxOps = {
      restart: restartPolling,
      stop: stopPolling,
      getSelectedCities: () => selectedCities.slice()
    };
  });
})();