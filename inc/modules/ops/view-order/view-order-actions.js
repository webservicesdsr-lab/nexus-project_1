// inc/modules/ops/view-order/view-order-actions.js
/**
 * ==========================================================
 * KNX OPS — View Order Actions (Dropdown Only) — CANON MIN
 *
 * Behavior:
 * - Renders ONE full-width dropdown (1:1 with Live Orders).
 * - Options:
 *   - "Assigned: Name" (or "Assign driver")
 *   - Drivers list
 *   - "Unassign / Release order" (value="__unassign__")
 *
 * Calls:
 * - GET  drivers:   /wp-json/knx/v1/ops/drivers?city_id=...
 * - POST assign:    /wp-json/knx/v1/ops/assign-driver {order_id, driver_id}
 * - POST unassign:  /wp-json/knx/v1/ops/unassign-driver {order_id}
 *
 * Notes:
 * - No extra containers.
 * - No modals.
 * - No native alerts.
 * - SSOT: window.KNX_VIEW_ORDER.order
 * ==========================================================
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('knxOpsViewOrderApp');
    if (!app) return;

    if (app.dataset.knxVoDropdownMounted === '1') return;
    app.dataset.knxVoDropdownMounted = '1';

    const driversUrl = String(app.dataset.driversUrl || '').trim();
    const assignUrl = String(app.dataset.assignDriverUrl || '').trim();
    const unassignUrl = String(app.dataset.unassignDriverUrl || '').trim();
    const nonce = String(app.dataset.nonce || '').trim();

    function toast(msg, type) {
      if (typeof window.knxToast === 'function') return window.knxToast(msg, type || 'info');
      console.log('[knx-toast]', type || 'info', msg);
    }

    function esc(s) {
      if (s === null || s === undefined) return '';
      return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function waitForSSOT(msInterval = 50, timeoutMs = 7000) {
      return new Promise((resolve) => {
        const start = Date.now();
        const iv = setInterval(() => {
          const ssot = (window.KNX_VIEW_ORDER && window.KNX_VIEW_ORDER.order) ? window.KNX_VIEW_ORDER.order : null;
          if (ssot) { clearInterval(iv); return resolve(ssot); }
          if (Date.now() - start > timeoutMs) { clearInterval(iv); return resolve(null); }
        }, msInterval);
      });
    }

    async function fetchJson(url, opts) {
      const options = opts || {};
      const headers = Object.assign({}, options.headers || {});
      if (nonce) headers['X-WP-Nonce'] = nonce;

      const res = await fetch(url, Object.assign({}, options, {
        credentials: 'same-origin',
        headers
      }));

      const json = await res.json().catch(() => ({}));
      return { res, json };
    }

    function normalizeDrivers(json) {
      if (Array.isArray(json)) return json;
      if (json && json.data && Array.isArray(json.data.drivers)) return json.data.drivers;
      if (json && Array.isArray(json.data)) return json.data;
      if (json && Array.isArray(json.results)) return json.results;
      return [];
    }

    function driverIdOf(d) {
      return Number(d.id || d.driver_id || d.user_id || d.driver_user_id || d.ID || 0) || 0;
    }

    function driverNameOf(d) {
      return String(d.name || d.full_name || d.display_name || d.label || '').trim();
    }

    async function loadDrivers(cityId) {
      if (!driversUrl) return { ok: false, drivers: [], message: 'Drivers endpoint missing' };
      const cid = Number(cityId || 0);
      if (!cid) return { ok: false, drivers: [], message: 'City missing' };

      const url = driversUrl + (driversUrl.includes('?') ? '&' : '?') + 'city_id=' + encodeURIComponent(String(cid));
      try {
        const { res, json } = await fetchJson(url, { method: 'GET' });
        if (!res.ok) {
          const msg = (json && json.message) ? json.message : 'Drivers unavailable';
          return { ok: false, drivers: [], message: msg };
        }
        const drivers = normalizeDrivers(json);
        return { ok: true, drivers: drivers || [], message: '' };
      } catch (e) {
        return { ok: false, drivers: [], message: 'Drivers unavailable' };
      }
    }

    async function postAssign(orderId, driverId) {
      if (!assignUrl) throw new Error('Assign endpoint missing');
      const { res, json } = await fetchJson(assignUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: Number(orderId), driver_id: Number(driverId) })
      });
      if (!res.ok) {
        const msg = (json && json.message) ? json.message : 'Unable to assign driver';
        throw new Error(msg);
      }
      return json;
    }

    async function postUnassign(orderId) {
      if (!unassignUrl) throw new Error('Unassign endpoint missing');
      const { res, json } = await fetchJson(unassignUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: Number(orderId) })
      });
      if (!res.ok) {
        const msg = (json && json.message) ? json.message : 'Unable to unassign driver';
        throw new Error(msg);
      }
      return json;
    }

    function renderDropdown(root, drivers, assignedLabel, canUnassign) {
      root.innerHTML = '';

      const wrap = document.createElement('div');
      wrap.className = 'knx-vo-driver';

      const sel = document.createElement('select');
      sel.className = 'knx-vo-driver-select';
      sel.setAttribute('aria-label', 'Assign driver');

      // Header option (shows assignment state; not selectable)
      const head = document.createElement('option');
      head.value = '';
      head.textContent = assignedLabel || 'Assign driver';
      sel.appendChild(head);

      // Drivers
      (drivers || []).forEach(d => {
        const id = driverIdOf(d);
        const name = driverNameOf(d);
        if (!id || !name) return;

        const opt = document.createElement('option');
        opt.value = String(id);
        opt.textContent = name;
        sel.appendChild(opt);
      });

      // Separator (disabled)
      const sep = document.createElement('option');
      sep.disabled = true;
      sep.textContent = '──────────';
      sel.appendChild(sep);

      // Unassign
      const un = document.createElement('option');
      un.value = '__unassign__';
      un.textContent = 'Unassign / Release order';
      if (!canUnassign) un.disabled = true;
      sel.appendChild(un);

      wrap.appendChild(sel);
      root.appendChild(wrap);

      return { wrap, sel };
    }

    (async () => {
      const order = await waitForSSOT(50, 7000);
      if (!order) return;

      const actionsRoot =
        document.getElementById('knxViewOrderActions') ||
        document.querySelector('[data-knx-view-order-actions="1"]');

      if (!actionsRoot) return;

      const orderId = Number(order.order_id || 0);
      const cityId = Number(order.city_id || 0);

      // Driver info from SSOT (best-effort)
      const assigned = Boolean(order.driver && order.driver.assigned);
      const assignedName = String((order.driver && order.driver.name) ? order.driver.name : '').trim();
      const assignedLabel = assigned
        ? ('Assigned: ' + (assignedName || 'Driver'))
        : 'Assign driver';

      // Load drivers
      const dl = await loadDrivers(cityId);
      const drivers = (dl.ok && Array.isArray(dl.drivers)) ? dl.drivers : [];

      const canUnassign = assigned === true;

      const { wrap, sel } = renderDropdown(
        actionsRoot,
        drivers,
        assignedLabel,
        canUnassign
      );

      // If drivers endpoint failed, disable the whole control but keep it visible
      if (!dl.ok) {
        wrap.classList.add('is-disabled');
        sel.disabled = true;
        sel.options[0].textContent = dl.message || 'Drivers not configured';
        return;
      }

      // If no drivers, keep dropdown but disable assignment (still allow unassign if assigned)
      const hasDrivers = drivers.some(d => driverIdOf(d) > 0 && driverNameOf(d));
      if (!hasDrivers && !canUnassign) {
        wrap.classList.add('is-disabled');
        sel.disabled = true;
        sel.options[0].textContent = 'No drivers for this city';
        return;
      }

      sel.addEventListener('change', async () => {
        const v = String(sel.value || '').trim();
        if (!v) return;

        sel.disabled = true;

        try {
          if (v === '__unassign__') {
            await postUnassign(orderId);
            toast('Order unassigned', 'success');
          } else {
            const driverId = Number(v || 0);
            if (!driverId) throw new Error('Invalid driver');
            await postAssign(orderId, driverId);
            toast('Driver assigned', 'success');
          }

          // Keep it simple and consistent: reload to refresh SSOT + timeline + labels
          window.location.reload();
        } catch (e) {
          toast(String(e && e.message ? e.message : e), 'error');
          sel.disabled = false;
          sel.value = '';
        }
      });

    })();
  });
})();