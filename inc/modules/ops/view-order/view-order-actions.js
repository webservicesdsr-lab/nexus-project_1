/**
 * ==========================================================
 * KNX OPS — View Order Actions (OPS v1)
 *
 * Single Action Surface for View Order.
 *
 * Responsibilities:
 * - Render ALL OPS actions in one place:
 *   - Status transitions (forward)
 *   - Cancel order (reason required)
 *   - Assign driver (modal + GET drivers + POST assign)
 *   - Unassign driver (confirm modal + POST unassign)
 *
 * Rules:
 * - Uses SSOT only: window.KNX_VIEW_ORDER.order
 * - No DOM heuristics
 * - No native alert/confirm
 * - No wp_enqueue / no wp_footer
 * - Fail-closed by default
 *
 * Requires:
 * - view-order-script.js must set:
 *     window.KNX_VIEW_ORDER = { order: <order> }
 * ==========================================================
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('knxOpsViewOrderApp');
    if (!app) return;

    // Prevent double mounts
    if (app.dataset.knxActionsMounted === '1') return;
    if (app.dataset.knxActionsWaiting === '1') return;
    app.dataset.knxActionsWaiting = '1';

    function waitForSSOT(msInterval = 50, timeoutMs = 5000) {
      return new Promise((resolve) => {
        const start = Date.now();
        const iv = setInterval(() => {
          const ssot = (window.KNX_VIEW_ORDER && window.KNX_VIEW_ORDER.order) ? window.KNX_VIEW_ORDER.order : null;
          if (ssot) { clearInterval(iv); return resolve(ssot); }
          if (Date.now() - start > timeoutMs) { clearInterval(iv); return resolve(null); }
        }, msInterval);
      });
    }

    function toast(msg, type) {
      if (typeof window.knxToast === 'function') return window.knxToast(msg, type || 'info');
      console.log('[knx-toast]', type || 'info', msg);
    }

    function btn(label, className) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = label;
      b.className = 'knx-btn ' + (className || '');
      return b;
    }

    function ensureActiveModalCloseOnEsc() {
      if (document.body.dataset.knxVoEscBound === '1') return;
      document.body.dataset.knxVoEscBound = '1';

      document.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Escape') return;
        const m = document.querySelector('.knx-ops-vo-modal.knx-modal-open');
        if (m) hideModal(m);
      });
    }

    function ensureModalShell(id) {
      let m = document.getElementById(id);
      if (m) return m;

      ensureActiveModalCloseOnEsc();

      m = document.createElement('div');
      m.id = id;
      m.className = 'knx-ops-vo-modal knx-modal-shell';
      m.setAttribute('aria-hidden', 'true');
      document.body.appendChild(m);
      return m;
    }

    function showModal(m) {
      m.classList.add('knx-modal-open');
      m.setAttribute('aria-hidden', 'false');
      document.body.classList.add('knx-ops-vo__modal-lock');
    }

    function hideModal(m) {
      m.classList.remove('knx-modal-open');
      m.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('knx-ops-vo__modal-lock');
    }

    (async () => {
      const ssot = await waitForSSOT(50, 5000);
      try { delete app.dataset.knxActionsWaiting; } catch (e) { app.dataset.knxActionsWaiting = undefined; }

      if (!ssot) return; // fail-closed
      if (app.dataset.knxActionsMounted === '1') return;
      app.dataset.knxActionsMounted = '1';

      const orderId = Number(ssot.order_id || 0);
      const cityId = Number(ssot.city_id || 0);
      const status = String(ssot.status || '').toLowerCase().trim();
      const assigned = Boolean(ssot.driver && ssot.driver.assigned);

      if (!orderId || !cityId || !status) return; // fail-closed

      const actionsRoot =
        document.getElementById('knxViewOrderActions') ||
        document.querySelector('[data-knx-view-order-actions="1"]');

      if (!actionsRoot) return;

      const wrapper = document.createElement('div');
      wrapper.className = 'knx-actions-wrapper';

      const mainGroup = document.createElement('div');
      mainGroup.className = 'knx-actions-main';

      const divider = document.createElement('div');
      divider.className = 'knx-actions-divider';

      const dangerGroup = document.createElement('div');
      dangerGroup.className = 'knx-actions-danger';

      wrapper.appendChild(mainGroup);
      wrapper.appendChild(divider);
      wrapper.appendChild(dangerGroup);

      actionsRoot.innerHTML = '';
      actionsRoot.appendChild(wrapper);

      const updateStatusUrl = app.dataset.updateStatusUrl || '/wp-json/knx/v1/ops/update-status';
      const assignDriverUrl = app.dataset.assignDriverUrl || '/wp-json/knx/v1/ops/assign-driver';
      const unassignDriverUrl = app.dataset.unassignDriverUrl || '/wp-json/knx/v1/ops/unassign-driver';
      const driversUrlBase = app.dataset.driversUrl || '/wp-json/knx/v1/ops/drivers';
      const nonce = app.dataset.nonce || '';

      function setBusy(v) {
        wrapper.classList.toggle('knx-actions--busy', !!v);
        Array.from(wrapper.querySelectorAll('button')).forEach((b) => { b.disabled = !!v; });
      }

      async function postJSON(url, payload) {
        const headers = { 'Content-Type': 'application/json' };
        if (nonce) {
          headers['X-WP-Nonce'] = nonce;
          headers['X-KNX-Nonce'] = nonce;
        }

        const res = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers,
          body: JSON.stringify(payload || {}),
        });

        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
          const msg = (json && json.message) ? json.message : (res.statusText || 'Request failed');
          throw new Error(msg);
        }
        return json;
      }

      function showConfirm(message) {
        return new Promise((resolve) => {
          const m = ensureModalShell('knxConfirmModal');

          m.innerHTML = `
            <div data-close="1" class="knx-modal-overlay"></div>
            <div role="dialog" aria-modal="true" class="knx-modal-dialog">
              <div class="knx-modal-message">${String(message || '')}</div>
              <div class="knx-modal-actions">
                <button type="button" data-close="1" class="knx-btn knx-btn-secondary">Cancel</button>
                <button type="button" data-ok="1" class="knx-btn knx-btn-danger">Confirm</button>
              </div>
            </div>
          `;

          const onClick = (ev) => {
            const t = ev.target;
            if (!t) return;

            if (t.matches('[data-close="1"]')) {
              m.removeEventListener('click', onClick);
              hideModal(m);
              return resolve(false);
            }

            if (t.matches('[data-ok="1"]')) {
              m.removeEventListener('click', onClick);
              hideModal(m);
              return resolve(true);
            }
          };

          m.addEventListener('click', onClick);
          showModal(m);
        });
      }

      function showCancelReason() {
        return new Promise((resolve) => {
          const m = ensureModalShell('knxCancelReasonModal');

          m.innerHTML = `
            <div data-close="1" class="knx-modal-overlay"></div>
            <div role="dialog" aria-modal="true" class="knx-modal-dialog">
              <div class="knx-modal-header">
                <div class="knx-modal-title">Cancel Order</div>
                <button type="button" data-close="1" class="knx-modal-close">✕</button>
              </div>

              <div class="knx-modal-body">Provide a reason (min 3 characters).</div>
              <textarea data-reason="1" rows="4" class="knx-modal-textarea"></textarea>
              <div data-err="1" class="knx-modal-err"></div>

              <div class="knx-modal-actions">
                <button type="button" data-close="1" class="knx-btn knx-btn-secondary">Close</button>
                <button type="button" data-ok="1" class="knx-btn knx-btn-danger">Cancel Order</button>
              </div>
            </div>
          `;

          const ta = m.querySelector('[data-reason="1"]');
          const err = m.querySelector('[data-err="1"]');

          const cleanup = (val) => {
            m.removeEventListener('click', onClick);
            hideModal(m);
            resolve(val);
          };

          const onClick = (ev) => {
            const t = ev.target;
            if (!t) return;

            if (t.matches('[data-close="1"]')) return cleanup(null);

            if (t.matches('[data-ok="1"]')) {
              const v = String((ta && ta.value) ? ta.value : '').trim();
              if (v.length < 3) {
                if (err) { err.style.display = 'block'; err.textContent = 'Reason must be at least 3 characters.'; }
                return;
              }
              const reason = v.length > 250 ? v.slice(0, 250) : v;
              return cleanup(reason);
            }
          };

          m.addEventListener('click', onClick);
          if (err) { err.style.display = 'none'; err.textContent = ''; }
          if (ta) ta.value = '';
          showModal(m);
        });
      }

      const STATUS_FLOW = {
        placed:      { label: 'Confirm',     to: 'confirmed' },
        confirmed:   { label: 'Preparing',   to: 'preparing' },
        preparing:   { label: 'Assigning',   to: 'assigned' },
        assigned:    { label: 'In Progress', to: 'in_progress' },
        in_progress: { label: 'Complete',    to: 'completed' },
      };

      if (STATUS_FLOW[status]) {
        const s = STATUS_FLOW[status];
        const b = btn(s.label, 'knx-btn-primary');
        b.addEventListener('click', async () => {
          setBusy(true);
          try {
            await postJSON(updateStatusUrl, { order_id: orderId, to_status: s.to });
            toast('Status updated', 'success');
            window.location.reload();
          } catch (e) {
            toast(String(e.message || e), 'error');
          } finally {
            setBusy(false);
          }
        });
        mainGroup.appendChild(b);
      }

      if (!['completed', 'cancelled'].includes(status)) {
        const cancelBtn = btn('Cancel', 'knx-btn-danger');
        cancelBtn.addEventListener('click', async () => {
          const reason = await showCancelReason();
          if (!reason) return;

          setBusy(true);
          try {
            await postJSON(updateStatusUrl, { order_id: orderId, to_status: 'cancelled', reason });
            toast('Order cancelled', 'success');
            window.location.reload();
          } catch (e) {
            toast(String(e.message || e), 'error');
          } finally {
            setBusy(false);
          }
        });
        dangerGroup.appendChild(cancelBtn);
      }

      let driversCache = null;

      async function fetchDriversForCity() {
        const url = driversUrlBase + '?city_id=' + encodeURIComponent(String(cityId));
        const res = await fetch(url, { credentials: 'same-origin' });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
          if (res.status === 403) throw new Error('No drivers available for this city');
          if (res.status === 409) throw new Error('Drivers not configured');
          const msg = (json && json.message) ? json.message : (res.statusText || 'Failed to load drivers');
          throw new Error(msg);
        }

        const list = (json && json.data && Array.isArray(json.data.drivers)) ? json.data.drivers : [];
        return list;
      }

      function ensureAssignModal() {
        const m = ensureModalShell('knxAssignDriverModal');

        m.innerHTML = `
          <div data-close="1" class="knx-modal-overlay"></div>
          <div role="dialog" aria-modal="true" class="knx-modal-dialog">
            <div class="knx-modal-header">
              <div class="knx-modal-title">Assign Driver</div>
              <button type="button" data-close="1" class="knx-modal-close">✕</button>
            </div>

            <div class="knx-modal-body">Select an active driver for this order.</div>

            <div class="knx-modal-field">
              <select data-select="1" class="knx-modal-select"></select>
            </div>

            <div data-err="1" class="knx-modal-err"></div>

            <div class="knx-modal-actions">
              <button type="button" data-close="1" class="knx-btn knx-btn-secondary">Cancel</button>
              <button type="button" data-assign="1" class="knx-btn knx-btn-primary">Assign</button>
            </div>
          </div>
        `;

        m.onclick = (ev) => {
          const t = ev.target;
          if (t && t.matches('[data-close="1"]')) hideModal(m);
        };

        return m;
      }

      // Assign driver action intentionally removed from View Order
      // to comply with OPS UI rules: View Order only exposes status
      // transitions and release (unassign) actions. Assignments are
      // available from the Live Orders board (expand-row assign dropdown).

      if (assigned && ['assigned', 'in_progress'].includes(status)) {
        const unassignBtn = btn('Unassign Driver', 'knx-btn-danger');
        dangerGroup.appendChild(unassignBtn);

        unassignBtn.addEventListener('click', async () => {
          const ok = await showConfirm('Unassign the current driver from this order?');
          if (!ok) return;

          setBusy(true);
          try {
            await postJSON(unassignDriverUrl, { order_id: orderId });
            toast('Driver unassigned', 'success');
            window.location.reload();
          } catch (e) {
            toast(String(e.message || e), 'error');
          } finally {
            setBusy(false);
          }
        });
      }
    })();
  });
})();
