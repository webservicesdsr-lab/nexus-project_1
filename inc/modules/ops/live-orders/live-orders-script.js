/**
 * KNX OPS — Live Orders Script (Production)
 *
 * Canon behaviors:
 * - Tabs are used on ALL screen sizes (New / Progress / Done).
 * - Only one bucket panel is visible at a time based on active tab.
 * - Row UI with single expandable detail panel (one open at a time).
 * - Persist expanded order across reloads.
 *
 * Expand actions:
 * - Map (red)
 * - Assign driver dropdown (placeholder for future integration)
 * - View order (blue)
 *
 * City selection behaviors:
 * - Persist city selection in localStorage.
 * - Super Admin defaults to All Cities (city_id=all).
 * - Manager defaults to assigned cities (fail-closed).
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

    const apiUrl = app.dataset.apiUrl || '';
    const citiesUrl = app.dataset.citiesUrl || '';
    const role = app.dataset.role || '';
    const viewOrderUrl = app.dataset.viewOrderUrl || '/view-order';
    const pollMs = Math.max(6000, Math.min(60000, parseInt(app.dataset.pollMs, 10) || 12000));

    const managedCities = (() => {
      try { return JSON.parse(app.dataset.managedCities || '[]'); } catch (e) { return []; }
    })();

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

    const countNewTab = document.getElementById('knxLOCountNewTab');
    const countProgressTab = document.getElementById('knxLOCountProgressTab');
    const countDoneTab = document.getElementById('knxLOCountDoneTab');

    const tabNew = document.getElementById('knxLOTabNew');
    const tabProgress = document.getElementById('knxLOTabProgress');
    const tabDone = document.getElementById('knxLOTabDone');

    const modal = document.getElementById('knxLOModal');
    const cityListNode = document.getElementById('knxLOCityList');
    const applyBtn = document.getElementById('knxLOApplyBtn');
    const selectAllBtn = document.getElementById('knxLOSelectAllBtn');
    const clearBtn = document.getElementById('knxLOClearBtn');

    // Friendly empty-board element
    const board = app.querySelector('.knx-live-orders__board');
    let emptyBoard = board ? board.querySelector('.knx-lo-empty-board') : null;
    if (board && !emptyBoard) {
      emptyBoard = document.createElement('div');
      emptyBoard.className = 'knx-lo-empty-board';
      emptyBoard.textContent = '';
      emptyBoard.style.display = 'none';
      board.parentNode.insertBefore(emptyBoard, board);
    }

    // Persistent keys
    const LS_KEY_CITY = 'knx_ops_live_orders_city';
    const LS_KEY_EXPANDED = 'knx_ops_live_orders_expanded';

    // State
    let selectedCities = [];     // numeric ids OR ['all'] for super_admin
    let activeTab = 'new';       // default new
    let expandedOrderId = 0;     // numeric order id
    let polling = false;
    let pollTimer = null;
    let abortController = null;
    let lastOrdersHash = '';

    function toast(msg, type = 'info') {
      if (typeof window.knxToast === 'function') return window.knxToast(msg, type);
      console.log('[knx-toast]', type, msg);
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"]/g, (s) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[s]));
    }

    function setActiveTab(tabKey) {
      const t = (tabKey === 'progress' || tabKey === 'done') ? tabKey : 'new';
      activeTab = t;
      app.dataset.activeTab = t;

      const all = [
        { el: tabNew, key: 'new' },
        { el: tabProgress, key: 'progress' },
        { el: tabDone, key: 'done' },
      ];

      all.forEach(x => {
        if (!x.el) return;
        const on = x.key === t;
        x.el.classList.toggle('is-active', on);
        x.el.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }

    function buildViewUrl(orderId) {
      const oid = Number(orderId || 0);
      const u = new URL(viewOrderUrl, window.location.origin);
      u.searchParams.set('order_id', String(oid));
      return u.toString();
    }

    function persistSelection() {
      try {
        if (Array.isArray(selectedCities) && selectedCities.length === 1 && selectedCities[0] === 'all') {
          localStorage.setItem(LS_KEY_CITY, 'all');
        } else {
          localStorage.setItem(LS_KEY_CITY, JSON.stringify(selectedCities));
        }
      } catch (e) {}
    }

    function loadPersistedSelection() {
      try {
        const v = localStorage.getItem(LS_KEY_CITY);
        if (!v) return null;
        if (v === 'all') return ['all'];
        const parsed = JSON.parse(v);
        if (Array.isArray(parsed)) {
          return parsed
            .map(n => (typeof n === 'number' ? n : Number(n)))
            .filter(n => Number.isFinite(n) && n > 0);
        }
      } catch (e) {}
      return null;
    }

    function persistExpanded() {
      try {
        if (expandedOrderId > 0) localStorage.setItem(LS_KEY_EXPANDED, String(expandedOrderId));
        else localStorage.removeItem(LS_KEY_EXPANDED);
      } catch (e) {}
    }

    function loadPersistedExpanded() {
      try {
        const v = localStorage.getItem(LS_KEY_EXPANDED);
        const n = Number(v || 0);
        return Number.isFinite(n) && n > 0 ? n : 0;
      } catch (e) {}
      return 0;
    }

    function updatePill() {
      if (!selectedCitiesPill) return;

      if (!selectedCities || selectedCities.length === 0) {
        selectedCitiesPill.textContent = 'No cities selected';
        return;
      }

      if (selectedCities.length === 1 && selectedCities[0] === 'all') {
        selectedCitiesPill.textContent = 'All cities';
        return;
      }

      const n = selectedCities.length;
      selectedCitiesPill.textContent = `${n} city${n > 1 ? 'ies' : ''} selected`;
    }

    // ---------- Modal behaviors ----------
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

      if (role === 'super_admin') {
        const allEl = document.createElement('label');
        allEl.className = 'knx-lo-city';
        const checked = (selectedCities.length === 1 && selectedCities[0] === 'all') ? 'checked' : '';
        allEl.innerHTML = `
          <input type="checkbox" data-city-id="all" ${checked} />
          <div class="knx-lo-city-name">All Cities</div>
        `;
        allEl.addEventListener('click', (ev) => {
          if (ev.target && ev.target.tagName === 'INPUT') return;
          const cb = allEl.querySelector('input[type="checkbox"]');
          if (cb) cb.checked = !cb.checked;
        });
        cityListNode.appendChild(allEl);
      }

      allowed.forEach(c => {
        const id = Number(c.id);
        const el = document.createElement('label');
        el.className = 'knx-lo-city';
        const checked = (selectedCities.includes('all')) ? '' : (selectedCities.includes(id) ? 'checked' : '');
        el.innerHTML = `
          <input type="checkbox" data-city-id="${id}" ${checked} />
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
        if (!cb.checked) return;
        const v = cb.getAttribute('data-city-id') || cb.value;
        if (v === 'all') sel.push('all');
        else sel.push(Number(v));
      });

      if (sel.includes('all')) selectedCities = ['all'];
      else selectedCities = [...new Set(sel)].filter(n => Number.isFinite(n) && n > 0);

      persistSelection();
      updatePill();
      closeModal();

      // Always start on New when applying city changes
      setActiveTab('new');

      restartPolling();
    }

    function selectAllCities() {
      if (!cityListNode) return;
      cityListNode.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = true; });

      const allCb = cityListNode.querySelector('input[data-city-id="all"]');
      if (allCb) allCb.checked = true;
    }

    function clearCities() {
      if (!cityListNode) return;
      cityListNode.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });

      selectedCities = [];
      persistSelection();
      updatePill();

      stopPolling();
      renderOrders([], { force: true });

      if (stateLine) stateLine.textContent = 'Select at least one city to view live orders.';
    }

    // ---------- Query params ----------
    function buildQueryParams() {
      const p = new URLSearchParams();

      if (Array.isArray(selectedCities) && selectedCities.length > 0) {
        if (selectedCities.length === 1 && selectedCities[0] === 'all') {
          p.set('city_id', 'all');
          return p.toString();
        }

        p.set('cities', selectedCities.join(','));
        selectedCities.forEach((id) => {
          p.append('city_ids[]', String(id));
        });
      }

      return p.toString();
    }

    function parseOrdersPayload(json) {
      if (json && typeof json === 'object') {
        if (json.data && Array.isArray(json.data.orders)) return json.data.orders;
        if (Array.isArray(json.orders)) return json.orders;
        if (Array.isArray(json.results)) return json.results;
      }
      return Array.isArray(json) ? json : [];
    }

    // UI-only bucketing
    function statusBucket(status) {
      const st = String(status || '').toLowerCase();
      if (st === 'placed' || st === 'confirmed') return 'new';
      if (st === 'preparing' || st === 'assigned' || st === 'in_progress') return 'progress';
      if (st === 'completed' || st === 'cancelled') return 'done';
      return 'progress';
    }

    function statusChipClass(status) {
      const b = statusBucket(status);
      if (b === 'new') return 'is-new';
      if (b === 'done') return 'is-done';
      return 'is-progress';
    }

    function statusLabel(status) {
      const st = String(status || '').toLowerCase();
      const map = {
        placed: 'Placed',
        confirmed: 'Confirmed',
        preparing: 'Preparing',
        assigned: 'Assigned',
        in_progress: 'In progress',
        completed: 'Completed',
        cancelled: 'Cancelled',
      };
      return map[st] || (st ? st.replace(/_/g, ' ') : 'Status');
    }

    function money(n) {
      const v = Number(n || 0);
      return v.toFixed(2);
    }

    // ---------- Expand/collapse animations ----------
    function animateOpen(panel) {
      if (!panel) return;
      panel.style.display = 'block';
      panel.style.height = '0px';
      panel.offsetHeight;
      const target = panel.scrollHeight;
      panel.style.height = target + 'px';

      const onEnd = () => {
        panel.removeEventListener('transitionend', onEnd);
        panel.style.height = 'auto';
      };
      panel.addEventListener('transitionend', onEnd);
    }

    function animateClose(panel) {
      if (!panel) return;
      const current = panel.scrollHeight;
      panel.style.height = current + 'px';
      panel.offsetHeight;
      panel.style.height = '0px';

      const onEnd = () => {
        panel.removeEventListener('transitionend', onEnd);
      };
      panel.addEventListener('transitionend', onEnd);
    }

    function closeExpandedInDom() {
      if (!expandedOrderId) return;

      const item = app.querySelector(`.knx-lo-item[data-order-id="${expandedOrderId}"]`);
      if (!item) return;

      const panel = item.querySelector('.knx-lo-expand');
      item.classList.remove('is-expanded');
      if (panel) animateClose(panel);
    }

    function openExpandedInDom(orderId, animate = true) {
      const item = app.querySelector(`.knx-lo-item[data-order-id="${orderId}"]`);
      if (!item) return;

      const panel = item.querySelector('.knx-lo-expand');
      item.classList.add('is-expanded');

      if (!panel) return;

      panel.style.display = 'block';
      panel.style.overflow = 'hidden';

      if (animate) animateOpen(panel);
      else {
        panel.style.height = 'auto';
      }
    }

    function toggleExpand(orderId) {
      const oid = Number(orderId || 0);
      if (!oid) return;

      if (expandedOrderId === oid) {
        closeExpandedInDom();
        expandedOrderId = 0;
        persistExpanded();
        return;
      }

      if (expandedOrderId) closeExpandedInDom();

      expandedOrderId = oid;
      persistExpanded();
      openExpandedInDom(oid, true);
    }

    // ---------- Rendering ----------
    function populateList(container, items) {
      if (!container) return;

      container.innerHTML = '';

      if (!items || items.length === 0) {
        container.innerHTML = '<div class="knx-lo-empty">No orders</div>';
        return;
      }

      items.forEach(it => {
        const oid = Number(it.order_id || 0);
        const restaurant = escapeHtml(it.restaurant_name || it.hub_name || 'Restaurant');
        const created = escapeHtml(it.created_human || it.created_at || '');
        const st = String(it.status || '');
        const stLabel = escapeHtml(statusLabel(st));
        const stClass = statusChipClass(st);

        const customer = escapeHtml(it.customer_name || 'Customer');
        const total = money(it.total_amount);
        const tip = money(it.tip_amount);
        const hasDriver = !!it.assigned_driver;
        const mapUrl = it.view_location_url ? String(it.view_location_url) : '';

        const itemEl = document.createElement('div');
        itemEl.className = 'knx-lo-item';
        itemEl.setAttribute('data-order-id', String(oid));

        // Thumbnail (keeps existing DOM structure/UX)
        const thumbUrl = String(it.hub_thumbnail || it.logo_url || '');
        const thumbHtml = thumbUrl
          ? `<div class="knx-lo-thumb"><img src="${escapeHtml(thumbUrl)}" alt="" loading="lazy" /></div>`
          : '<div class="knx-lo-thumb" aria-hidden="true"></div>';

        const viewUrl = buildViewUrl(oid);

        itemEl.innerHTML = `
          <div class="knx-lo-row" role="button" tabindex="0">
            <a class="knx-lo-idview" data-action="open-order" href="${escapeHtml(viewUrl)}">#${escapeHtml(oid)}</a>

            ${thumbHtml}

            <div class="knx-lo-main">
              <div class="knx-lo-restaurant">${restaurant}</div>
              <div class="knx-lo-time">${created}</div>
            </div>

            <div class="knx-lo-status ${stClass}">${stLabel}</div>

            <div class="knx-lo-chevron" aria-hidden="true">
              <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
          </div>

          <div class="knx-lo-expand" aria-hidden="true">
            <div class="knx-lo-expand__inner">
              <div class="knx-lo-detail">
                <div class="knx-lo-detail__title">Customer</div>
                <div class="knx-lo-detail__value">${customer}</div>
                <div class="knx-lo-detail__sub">${hasDriver ? 'Driver assigned' : 'No driver assigned'}</div>
              </div>

              <div class="knx-lo-detail">
                <div class="knx-lo-detail__title">Totals</div>
                <div class="knx-lo-detail__value">$${escapeHtml(total)}</div>
                <div class="knx-lo-detail__sub">Tip: $${escapeHtml(tip)}</div>
              </div>

              <div class="knx-lo-actions">
                ${mapUrl
                  ? `<a class="knx-lo-action knx-lo-action--danger" href="${escapeHtml(mapUrl)}" target="_blank" rel="noopener">Map</a>`
                  : `<span class="knx-lo-action knx-lo-action--muted">No map</span>`
                }

                <div class="knx-lo-driver">
                  <label class="knx-visually-hidden" for="knxDriver_${escapeHtml(oid)}">Assign driver</label>
                  <select id="knxDriver_${escapeHtml(oid)}" class="knx-lo-driver-select" data-action="assign-driver" disabled>
                    <option value="">Assign driver (soon)</option>
                  </select>
                </div>

                <a class="knx-lo-action knx-lo-action--blue" data-action="open-order" href="${escapeHtml(viewUrl)}">View order</a>
              </div>
            </div>
          </div>
        `;

        const row = itemEl.querySelector('.knx-lo-row');
        const panel = itemEl.querySelector('.knx-lo-expand');

        // Toggle expand by row click (ignore clicks on the order links)
        if (row) {
          row.addEventListener('click', (ev) => {
            const t = ev.target;
            if (t && t.closest && t.closest('[data-action="open-order"]')) return;
            toggleExpand(oid);
          });

          row.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' || ev.key === ' ') {
              ev.preventDefault();
              toggleExpand(oid);
            }
          });
        }

        // placeholder future behavior: assign driver
        const driverSel = itemEl.querySelector('[data-action="assign-driver"]');
        if (driverSel) {
          driverSel.addEventListener('change', () => {
            toast('Driver assignment is coming soon.', 'info');
            driverSel.value = '';
          });
        }

        if (panel) panel.style.height = '0px';

        container.appendChild(itemEl);
      });
    }

    function syncActiveTabToExpanded(orders) {
      // If there is a persisted expanded order, tab MUST switch to its bucket so it stays visible.
      if (!expandedOrderId) return;

      const found = (Array.isArray(orders) ? orders : []).find(o => Number(o.order_id || 0) === expandedOrderId);
      if (!found) return;

      setActiveTab(statusBucket(found.status));
    }

    function renderOrders(orders, opts) {
      const options = opts || {};
      const data = Array.isArray(orders) ? orders : [];

      // Hash to avoid rerender if nothing changed
      const hash = (() => {
        try {
          return data
            .map(o => `${o.order_id}:${o.status}:${o.created_at || ''}:${o.total_amount || ''}:${o.assigned_driver ? 1 : 0}`)
            .join('|');
        } catch (e) {
          return String(Date.now());
        }
      })();

      if (hash && hash === lastOrdersHash && !options.force) {
        syncActiveTabToExpanded(data);
        return;
      }
      lastOrdersHash = hash;

      const newList = [];
      const progressList = [];
      const doneList = [];

      data.forEach(o => {
        const b = statusBucket(o.status);
        if (b === 'new') newList.push(o);
        else if (b === 'done') doneList.push(o);
        else progressList.push(o);
      });

      populateList(listNew, newList);
      populateList(listProgress, progressList);
      populateList(listDone, doneList);

      const n1 = newList.length;
      const n2 = progressList.length;
      const n3 = doneList.length;

      if (countNew) countNew.textContent = String(n1);
      if (countProgress) countProgress.textContent = String(n2);
      if (countDone) countDone.textContent = String(n3);

      if (countNewTab) countNewTab.textContent = String(n1);
      if (countProgressTab) countProgressTab.textContent = String(n2);
      if (countDoneTab) countDoneTab.textContent = String(n3);

      const total = n1 + n2 + n3;

      if (stateLine) {
        if (!Array.isArray(selectedCities) || selectedCities.length === 0) {
          stateLine.textContent = 'Select at least one city to view live orders.';
        } else {
          stateLine.textContent = total === 0
            ? 'No live orders in selected cities.'
            : `Showing ${total} order${total !== 1 ? 's' : ''}.`;
        }
      }

      if (emptyBoard) {
        if (Array.isArray(selectedCities) && selectedCities.length > 0 && total === 0) {
          emptyBoard.textContent = 'No live orders for the selected city(ies).';
          emptyBoard.style.display = 'block';
        } else {
          emptyBoard.style.display = 'none';
        }
      }

      // If expanded order no longer exists, clear it.
      if (expandedOrderId > 0) {
        const exists = data.some(o => Number(o.order_id || 0) === expandedOrderId);
        if (!exists) {
          expandedOrderId = 0;
          persistExpanded();
        }
      }

      // If expanded exists, force the tab to match it.
      syncActiveTabToExpanded(data);

      // Restore expanded panel without animation after rerender
      if (expandedOrderId > 0) {
        openExpandedInDom(expandedOrderId, false);
      }
    }

    // ---------- Fetch / Poll ----------
    async function fetchOrdersOnce() {
      if (!apiUrl) return [];

      if (!Array.isArray(selectedCities) || selectedCities.length === 0) {
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
        if (pulse) pulse.style.opacity = '0.85';
      }
    }

    async function pollLoop() {
      if (polling) return;
      polling = true;

      try {
        const orders = await fetchOrdersOnce();
        renderOrders(orders || [], { force: false });
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

    // ---------- Tabs wiring ----------
    function wireTabs() {
      const buttons = [tabNew, tabProgress, tabDone].filter(Boolean);

      buttons.forEach(btn => {
        btn.addEventListener('click', () => {
          const key = btn.getAttribute('data-tab') || 'new';
          setActiveTab(key);
        });
      });
    }

    // ---------- Wire UI ----------
    if (selectCitiesBtn) selectCitiesBtn.addEventListener('click', openModal);
    if (applyBtn) applyBtn.addEventListener('click', applySelection);
    if (selectAllBtn) selectAllBtn.addEventListener('click', selectAllCities);
    if (clearBtn) clearBtn.addEventListener('click', clearCities);

    wireTabs();

    // ---------- Init selection ----------
    const persisted = loadPersistedSelection();
    if (persisted && Array.isArray(persisted) && persisted.length > 0) {
      if (role === 'manager' && Array.isArray(managedCities) && managedCities.length > 0) {
        const allowed = persisted.filter(n => Number.isFinite(n) && managedCities.includes(Number(n)));
        if (allowed.length > 0) selectedCities = allowed.map(Number);
        else selectedCities = managedCities.slice().map(Number).filter(n => Number.isFinite(n) && n > 0);
      } else {
        selectedCities = persisted;
      }
    } else {
      if (role === 'manager' && Array.isArray(managedCities) && managedCities.length > 0) {
        selectedCities = managedCities.slice().map(Number).filter(n => Number.isFinite(n) && n > 0);
      } else if (role === 'super_admin') {
        selectedCities = ['all'];
      }
    }

    // Default: always New first (unless expanded forces the bucket after data loads)
    setActiveTab('new');

    updatePill();
    persistSelection();

    // Restore expanded order id (tab sync will happen after first fetch)
    expandedOrderId = loadPersistedExpanded();

    // Initial empty render
    renderOrders([], { force: true });

    // Start polling
    if (Array.isArray(selectedCities) && selectedCities.length > 0) {
      startPolling();
    } else {
      stopPolling();
      renderOrders([], { force: true });
    }

    window.addEventListener('beforeunload', () => stopPolling());
  });
})();
