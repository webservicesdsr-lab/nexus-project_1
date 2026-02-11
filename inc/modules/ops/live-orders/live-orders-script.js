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
 * - Assign driver dropdown (enabled only if drivers endpoint is configured)
 * - View order (blue)
 *
 * City selection behaviors:
 * - Persist city selection in localStorage.
 * - Super Admin defaults to All Cities (city_id=all).
 * - Manager defaults to assigned cities (fail-closed).
 *
 * Notes:
 * - No API call when no cities selected (fail-closed UX).
 * - Expects REST response shape tolerant:
 *   { success, message, data: { orders: [...] } } OR { data:{orders} } OR { orders } OR { results } OR []
 *
 * IMPORTANT (Assignment UX):
 * - Live Orders GET might not always include assigned_driver_name.
 * - We derive the assigned label from:
 *   1) order payload (preferred)
 *   2) drivers list (map id -> name)
 *   3) optimistic assignment map stored in localStorage (short TTL) after POST assign
 * - This keeps dropdown label in sync even when backend returns only an ID.
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('knxLiveOrdersApp');
    if (!app) return;

    const apiUrl = app.dataset.apiUrl || '';
    const citiesUrl = app.dataset.citiesUrl || '';
    const driversUrl = app.dataset.driversUrl || '';
    const assignDriverUrl = app.dataset.assignDriverUrl || '';
    const restNonce = app.dataset.restNonce || '';

    const role = app.dataset.role || '';
    const viewOrderUrl = app.dataset.viewOrderUrl || '/view-order';

    const pollMs = Math.max(6000, Math.min(60000, parseInt(app.dataset.pollMs, 10) || 12000));
    const includeResolved = String(app.dataset.includeResolved || '1') === '1' ? 1 : 0;
    const resolvedHours = Math.max(1, Math.min(168, parseInt(app.dataset.resolvedHours, 10) || 24));

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

    // Short-lived (UI-only) optimistic assignment map.
    // This is a fallback when Live Orders GET doesn't include driver name.
    const LS_KEY_ASSIGNED = 'knx_ops_live_orders_assigned_map';
    const ASSIGNED_TTL_MS = 15 * 60 * 1000; // 15 minutes
    let assignedMapVersion = 0;

    function loadAssignedMap() {
      try {
        const raw = localStorage.getItem(LS_KEY_ASSIGNED);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return {};
        const now = Date.now();
        // purge expired
        Object.keys(parsed).forEach((k) => {
          const v = parsed[k];
          const at = v && typeof v.at === 'number' ? v.at : 0;
          if (!at || (now - at) > ASSIGNED_TTL_MS) delete parsed[k];
        });
        return parsed;
      } catch (e) {
        return {};
      }
    }

    function saveAssignedMap(map) {
      try {
        localStorage.setItem(LS_KEY_ASSIGNED, JSON.stringify(map || {}));
        assignedMapVersion++;
      } catch (e) {}
    }

    function setOptimisticAssigned(orderId, driverId, driverName) {
      const oid = Number(orderId || 0);
      const did = Number(driverId || 0);
      const name = String(driverName || '').trim();
      if (!oid || !did || !name) return;

      const m = loadAssignedMap();
      m[String(oid)] = { id: did, name, at: Date.now() };
      saveAssignedMap(m);
    }

    function clearOptimisticAssigned(orderId) {
      const oid = Number(orderId || 0);
      if (!oid) return;
      const m = loadAssignedMap();
      if (m[String(oid)]) {
        delete m[String(oid)];
        saveAssignedMap(m);
      }
    }

    function getOptimisticAssigned(orderId) {
      const oid = Number(orderId || 0);
      if (!oid) return null;
      const m = loadAssignedMap();
      const v = m[String(oid)];
      if (!v) return null;
      const now = Date.now();
      const at = typeof v.at === 'number' ? v.at : 0;
      if (!at || (now - at) > ASSIGNED_TTL_MS) {
        delete m[String(oid)];
        saveAssignedMap(m);
        return null;
      }
      return v;
    }

    // State
    let selectedCities = [];     // numeric ids OR ['all'] for super_admin
    let activeTab = 'new';       // default new
    let expandedOrderId = 0;     // numeric order id
    let polling = false;
    let pollTimer = null;
    let abortController = null;
    let lastOrdersHash = '';

    // Drivers cache by city
    const driversCache = {}; // { [cityId]: { at:number, ok:boolean, drivers:[], message:string } }
    const DRIVERS_TTL_MS = 120000;

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

    async function fetchJson(url, opts) {
      const options = opts || {};
      const headers = Object.assign({}, options.headers || {});
      if (restNonce) headers['X-WP-Nonce'] = restNonce;

      const res = await fetch(url, Object.assign({}, options, {
        credentials: 'same-origin',
        headers
      }));

      const json = await res.json().catch(() => ({}));
      return { res, json };
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
        const { res, json } = await fetchJson(citiesUrl, { method: 'GET' });
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

      p.set('include_resolved', String(includeResolved));
      p.set('resolved_hours', String(resolvedHours));

      if (Array.isArray(selectedCities) && selectedCities.length > 0) {
        if (selectedCities.length === 1 && selectedCities[0] === 'all') {
          p.set('city_id', 'all');
          return p.toString();
        }

        selectedCities.forEach((id) => p.append('city_ids[]', String(id)));
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
      else panel.style.height = 'auto';
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

    // ---------- Driver dropdown helpers ----------
    function setDriverSelectDisabled(select, label) {
      try {
        select.innerHTML = `<option value="">${escapeHtml(label || 'Drivers not configured')}</option>`;
        select.disabled = true;
        select.value = '';
        const wrap = select.closest('.knx-lo-driver');
        if (wrap) wrap.classList.add('is-disabled');
      } catch (e) {}
    }

    function setDriverSelectLoading(select) {
      try {
        select.innerHTML = '<option value="">Loading drivers…</option>';
        select.disabled = true;
        select.value = '';
        const wrap = select.closest('.knx-lo-driver');
        if (wrap) wrap.classList.add('is-disabled');
      } catch (e) {}
    }

    function setDriverSelectEnabled(select, drivers, assignedDriverId, assignedDriverName) {
      const wrap = select.closest('.knx-lo-driver');
      if (wrap) wrap.classList.remove('is-disabled');

      const opts = [];

      // If we know the assigned name, show it (name is enough; id may be legacy/user_id).
      const assignedName = String(assignedDriverName || '').trim();
      if (assignedName) {
        opts.push(`<option value="">Assigned: ${escapeHtml(assignedName)}</option>`);
      } else {
        opts.push('<option value="">Assign driver</option>');
      }

      (drivers || []).forEach(d => {
        const id = Number(d.id || d.driver_id || d.user_id || d.driver_user_id || d.ID || 0);
        const name = String(d.name || d.display_name || d.full_name || d.label || 'Driver').trim();
        if (!id) return;
        opts.push(`<option value="${escapeHtml(String(id))}">${escapeHtml(name)}</option>`);
      });

      select.innerHTML = opts.join('');
      select.disabled = false;
      select.value = '';
    }

    async function getDriversForCity(cityId) {
      const cid = Number(cityId || 0);
      if (!cid || !driversUrl) return { ok: false, drivers: [], message: 'Drivers not configured' };

      const now = Date.now();
      const cached = driversCache[cid];
      if (cached && (now - cached.at) < DRIVERS_TTL_MS) return cached;

      driversCache[cid] = { at: now, ok: false, drivers: [], message: 'Loading' };

      const url = driversUrl + (driversUrl.includes('?') ? '&' : '?') + 'city_id=' + encodeURIComponent(String(cid));

      try {
        const { res, json } = await fetchJson(url, { method: 'GET' });

        if (!res.ok) {
          const msg = (json && json.message) ? json.message : (res.status === 404 ? 'Drivers not configured' : 'Drivers unavailable');
          driversCache[cid] = { at: now, ok: false, drivers: [], message: msg };
          return driversCache[cid];
        }

        // Normalize drivers array
        let drivers = [];
        if (Array.isArray(json)) drivers = json;
        else if (json && json.data && Array.isArray(json.data.drivers)) drivers = json.data.drivers;
        else if (json && Array.isArray(json.data)) drivers = json.data;
        else if (Array.isArray(json.results)) drivers = json.results;

        driversCache[cid] = { at: now, ok: true, drivers: drivers || [], message: '' };
        return driversCache[cid];
      } catch (e) {
        driversCache[cid] = { at: now, ok: false, drivers: [], message: 'Drivers unavailable' };
        return driversCache[cid];
      }
    }

    function pickAssignedFromOrder(order) {
      const o = order || {};
      const driverObj = (o.assigned_driver && typeof o.assigned_driver === 'object') ? o.assigned_driver : null;
      const drv = (o.driver && typeof o.driver === 'object') ? o.driver : null;

      // Try many possible keys without assuming backend schema.
      const id =
        Number(
          o.assigned_driver_id ||
          o.driver_id ||
          o.assigned_driver_user_id ||
          o.driver_user_id ||
          (driverObj && (driverObj.id || driverObj.driver_id || driverObj.user_id || driverObj.driver_user_id)) ||
          (drv && (drv.id || drv.driver_id || drv.user_id || drv.driver_user_id)) ||
          0
        ) || 0;

      const name =
        String(
          o.assigned_driver_name ||
          o.driver_name ||
          (driverObj && (driverObj.name || driverObj.full_name || driverObj.display_name)) ||
          (drv && (drv.name || drv.full_name || drv.display_name)) ||
          ''
        ).trim();

      return { id, name };
    }

    function findDriverNameInList(drivers, assignedId) {
      const id = Number(assignedId || 0);
      if (!id || !Array.isArray(drivers) || drivers.length === 0) return '';
      const hit = drivers.find((d) => {
        const did = Number(d.id || d.driver_id || d.user_id || d.driver_user_id || d.ID || 0);
        return did === id;
      });
      if (!hit) return '';
      return String(hit.name || hit.full_name || hit.display_name || hit.label || '').trim();
    }

    async function hydrateDriverDropdowns(orders) {
      const selects = app.querySelectorAll('select[data-action="assign-driver"]');
      if (!selects || selects.length === 0) return;

      // Map orders by id for assignment labels
      const byId = new Map();
      (orders || []).forEach(o => {
        const oid = Number(o.order_id || 0);
        if (oid) byId.set(oid, o);
      });

      // Collect unique cities from DOM
      const cityIds = new Set();
      selects.forEach(s => {
        const cid = Number(s.getAttribute('data-city-id') || 0);
        if (cid > 0) cityIds.add(cid);
      });

      // If no drivers endpoint, keep everything disabled but visible as dropdown
      if (!driversUrl) {
        selects.forEach(s => setDriverSelectDisabled(s, 'Drivers not configured'));
        return;
      }

      // Set all to loading first (keeps UI consistent)
      selects.forEach(s => setDriverSelectLoading(s));

      // Fetch drivers for each city (in parallel)
      const cities = Array.from(cityIds);
      await Promise.all(cities.map(async (cid) => getDriversForCity(cid)));

      // Apply per select
      selects.forEach(s => {
        const cid = Number(s.getAttribute('data-city-id') || 0);
        const oid = Number(s.getAttribute('data-order-id') || 0);
        const order = oid ? byId.get(oid) : null;

        if (!cid || cid <= 0) {
          setDriverSelectDisabled(s, 'City missing');
          return;
        }

        const cached = driversCache[cid];
        if (!cached || !cached.ok) {
          setDriverSelectDisabled(s, (cached && cached.message) ? cached.message : 'Drivers not configured');
          return;
        }

        if (!cached.drivers || cached.drivers.length === 0) {
          setDriverSelectDisabled(s, 'No drivers for this city');
          return;
        }

        // Assigned from payload
        let assigned = pickAssignedFromOrder(order);

        // If payload doesn't include assignment, try optimistic (recent) UI-only assignment.
        if ((!assigned.id || !assigned.name) && oid) {
          const opt = getOptimisticAssigned(oid);
          if (opt && opt.id && opt.name) {
            // Only use optimistic if server didn't provide better data.
            if (!assigned.id) assigned.id = Number(opt.id || 0);
            if (!assigned.name) assigned.name = String(opt.name || '').trim();
          }
        }

        // If we have an ID but no name, map from drivers list.
        if (assigned.id && !assigned.name) {
          assigned.name = findDriverNameInList(cached.drivers, assigned.id) || '';
        }

        setDriverSelectEnabled(s, cached.drivers, assigned.id, assigned.name);
      });

      // Bind change handler once per select instance (DOM is re-rendered, so bind is safe)
      selects.forEach(s => {
        if (s.dataset.bound === '1') return;
        s.dataset.bound = '1';

        s.addEventListener('change', async () => {
          const driverId = Number(s.value || 0);
          if (!driverId) return;

          const orderId = Number(s.getAttribute('data-order-id') || 0);
          if (!orderId) {
            toast('Invalid order', 'error');
            s.value = '';
            return;
          }

          if (!assignDriverUrl) {
            toast('Assign endpoint not configured', 'error');
            s.value = '';
            return;
          }

          // Capture label for optimistic UI
          const selectedOption = s.options && s.selectedIndex >= 0 ? s.options[s.selectedIndex] : null;
          const selectedName = selectedOption ? String(selectedOption.textContent || '').trim() : '';

          s.disabled = true;

          try {
            const { res, json } = await fetchJson(assignDriverUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ order_id: orderId, driver_id: driverId })
            });

            if (!res.ok) {
              const msg = (json && json.message) ? json.message : 'Unable to assign driver';
              throw new Error(msg);
            }

            // Optimistic label (short TTL) so the dropdown reflects immediately
            // even if Live Orders GET doesn't include name.
            if (selectedName) {
              setOptimisticAssigned(orderId, driverId, selectedName);
            }

            toast((json && json.message) ? json.message : 'Driver assigned', 'success');

            // Force next render/hydration even if orders hash doesn't change much.
            lastOrdersHash = '';
            restartPolling();
          } catch (err) {
            console.warn('Assign driver failed', err);
            toast((err && err.message) ? err.message : 'Unable to assign driver', 'error');
          } finally {
            s.disabled = false;
            s.value = '';
          }
        });
      });
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

        // Determine if assigned (payload OR optimistic map)
        const assignedFromPayload = pickAssignedFromOrder(it);
        const optimistic = oid ? getOptimisticAssigned(oid) : null;

        const hasDriver =
          !!(assignedFromPayload && (assignedFromPayload.id || assignedFromPayload.name)) ||
          !!(optimistic && optimistic.id && optimistic.name);

        const mapUrl = it.view_location_url ? String(it.view_location_url) : '';

        const itemEl = document.createElement('div');
        itemEl.className = 'knx-lo-item';
        itemEl.setAttribute('data-order-id', String(oid));

        const thumbUrl = String(it.hub_thumbnail || it.logo_url || '');
        const thumbHtml = thumbUrl
          ? `<div class="knx-lo-thumb"><img src="${escapeHtml(thumbUrl)}" alt="" loading="lazy" /></div>`
          : '<div class="knx-lo-thumb" aria-hidden="true"></div>';

        const viewUrl = buildViewUrl(oid);
        const cityId = Number(it.city_id || it.city || it.hub_city_id || (it.hub && it.hub.city_id) || 0);

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

                <div class="knx-lo-driver is-disabled">
                  <label class="knx-visually-hidden" for="knxDriver_${escapeHtml(oid)}">Assign driver</label>
                  <select id="knxDriver_${escapeHtml(oid)}"
                          class="knx-lo-driver-select"
                          data-action="assign-driver"
                          data-order-id="${escapeHtml(String(oid))}"
                          data-city-id="${escapeHtml(String(cityId))}"
                          disabled>
                    <option value="">Loading drivers…</option>
                  </select>
                </div>

                <a class="knx-lo-action knx-lo-action--blue" data-action="open-order" href="${escapeHtml(viewUrl)}">View order</a>
              </div>
            </div>
          </div>
        `;

        const row = itemEl.querySelector('.knx-lo-row');
        const panel = itemEl.querySelector('.knx-lo-expand');

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

        if (panel) panel.style.height = '0px';

        container.appendChild(itemEl);
      });
    }

    function syncActiveTabToExpanded(orders) {
      if (!expandedOrderId) return;
      const found = (Array.isArray(orders) ? orders : []).find(o => Number(o.order_id || 0) === expandedOrderId);
      if (!found) return;
      setActiveTab(statusBucket(found.status));
    }

    async function renderOrders(orders, opts) {
      const options = opts || {};
      const data = Array.isArray(orders) ? orders : [];

      // Include assignmentMapVersion so a recent POST assign can force UI refresh.
      const hash = (() => {
        try {
          return data
            .map(o => {
              const oid = Number(o.order_id || 0);
              const ass = pickAssignedFromOrder(o);
              const assKey = `${ass.id || 0}:${ass.name || ''}`;
              return `${oid}:${o.status}:${o.created_at || ''}:${o.total_amount || ''}:${assKey}`;
            })
            .join('|') + `|v=${assignedMapVersion}`;
        } catch (e) {
          return String(Date.now());
        }
      })();

      if (hash && hash === lastOrdersHash && !options.force) {
        // Still hydrate dropdowns: driver list/status can change even if list hash didn't.
        syncActiveTabToExpanded(data);
        await hydrateDriverDropdowns(data);
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

      if (expandedOrderId > 0) {
        const exists = data.some(o => Number(o.order_id || 0) === expandedOrderId);
        if (!exists) {
          expandedOrderId = 0;
          persistExpanded();
        }
      }

      syncActiveTabToExpanded(data);

      if (expandedOrderId > 0) {
        openExpandedInDom(expandedOrderId, false);
      }

      // IMPORTANT: hydrate driver dropdowns AFTER DOM render
      await hydrateDriverDropdowns(data);
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
          signal: abortController.signal,
          headers: restNonce ? { 'X-WP-Nonce': restNonce } : {}
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
        await renderOrders(orders || [], { force: false });
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

    setActiveTab('new');
    updatePill();
    persistSelection();

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
