// inc/modules/ops/view-order/view-order-actions.js
/**
 * ==========================================================
 * KNX OPS — View Order Actions (OPS v1)
 *
 * Allowed modals on Active Orders:
 * - Status change modal
 * - Release (unassign) modal
 *
 * This file:
 * - Renders status transition button(s) on top bar
 * - Renders Release Driver (unassign) when applicable
 * - NO native alert/confirm
 * - SSOT only: window.KNX_VIEW_ORDER.order
 * ==========================================================
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('knxOpsViewOrderApp');
    if (!app) return;

    if (app.dataset.knxActionsMounted === '1') return;
    if (app.dataset.knxActionsWaiting === '1') return;
    app.dataset.knxActionsWaiting = '1';

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

    function toast(msg, type) {
      if (typeof window.knxToast === 'function') return window.knxToast(msg, type || 'info');
      console.log('[knx-toast]', type || 'info', msg);
    }

    function ensureEscCloseOnce() {
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
      ensureEscCloseOnce();

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

    function setBusy(root, v) {
      root.classList.toggle('knx-actions--busy', !!v);
      Array.from(root.querySelectorAll('button')).forEach((b) => { b.disabled = !!v; });
    }

    async function postJSON(url, payload, nonce) {
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

    function statusLabel(s) {
      const v = String(s || '').trim().toLowerCase();
      if (!v) return '—';
      return v.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
    }

    function showStatusModal(currentStatus, nextStatus) {
      return new Promise((resolve) => {
        const m = ensureModalShell('knxVoStatusModal');

        m.innerHTML = `
          <div data-close="1" class="knx-modal-overlay"></div>
          <div role="dialog" aria-modal="true" class="knx-modal-dialog">
            <div class="knx-modal-header">
              <div class="knx-modal-title">Change status</div>
              <button type="button" data-close="1" class="knx-modal-close">✕</button>
            </div>
            <div class="knx-modal-body">
              <div class="knx-modal-message">
                <strong>${statusLabel(currentStatus)}</strong> → <strong>${statusLabel(nextStatus)}</strong>
              </div>
              Confirm this status change?
            </div>
            <div class="knx-modal-actions">
              <button type="button" data-close="1" class="knx-btn knx-btn-secondary">Cancel</button>
              <button type="button" data-ok="1" class="knx-btn knx-btn-primary">Confirm</button>
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

    function showReleaseModal() {
      return new Promise((resolve) => {
        const m = ensureModalShell('knxVoReleaseModal');

        m.innerHTML = `
          <div data-close="1" class="knx-modal-overlay"></div>
          <div role="dialog" aria-modal="true" class="knx-modal-dialog">
            <div class="knx-modal-header">
              <div class="knx-modal-title">Release order</div>
              <button type="button" data-close="1" class="knx-modal-close">✕</button>
            </div>
            <div class="knx-modal-body">
              This will unassign the driver from this order.
            </div>
            <div class="knx-modal-actions">
              <button type="button" data-close="1" class="knx-btn knx-btn-secondary">Cancel</button>
              <button type="button" data-ok="1" class="knx-btn knx-btn-danger">Release</button>
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

    (async () => {
      const order = await waitForSSOT(50, 7000);
      try { delete app.dataset.knxActionsWaiting; } catch (e) { app.dataset.knxActionsWaiting = undefined; }

      if (!order) return; // fail-closed
      if (app.dataset.knxActionsMounted === '1') return;
      app.dataset.knxActionsMounted = '1';

      const orderId = Number(order.order_id || 0);
      const status = String(order.status || '').toLowerCase().trim();
      const assigned = Boolean(order.driver && order.driver.assigned);

      if (!orderId || !status) return;

      const actionsRoot =
        document.getElementById('knxViewOrderActions') ||
        document.querySelector('[data-knx-view-order-actions="1"]');

      if (!actionsRoot) return;

      actionsRoot.innerHTML = `
        <div class="knx-actions-wrapper">
          <div class="knx-actions-main"></div>
          <div class="knx-actions-divider"></div>
          <div class="knx-actions-danger"></div>
        </div>
      `;

      const wrapper = actionsRoot.querySelector('.knx-actions-wrapper');
      const mainGroup = actionsRoot.querySelector('.knx-actions-main');
      const dangerGroup = actionsRoot.querySelector('.knx-actions-danger');

      const updateStatusUrl = String(app.dataset.updateStatusUrl || '/wp-json/knx/v1/ops/update-status');
      const unassignDriverUrl = String(app.dataset.unassignDriverUrl || '/wp-json/knx/v1/ops/unassign-driver');
      const nonce = String(app.dataset.nonce || '');

      // Canon status flow (simple forward)
      const FLOW = {
        placed:      { label: 'Confirm',     to: 'confirmed' },
        confirmed:   { label: 'Preparing',   to: 'preparing' },
        preparing:   { label: 'Assigned',    to: 'assigned' },
        assigned:    { label: 'In progress', to: 'in_progress' },
        in_progress: { label: 'Delivered',   to: 'delivered' },
      };

      function mkBtn(label, className) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'knx-btn ' + (className || '');
        b.textContent = label;
        return b;
      }

      // Status change (modal)
      if (FLOW[status]) {
        const next = FLOW[status];
        const b = mkBtn(next.label, 'knx-btn-primary');
        b.addEventListener('click', async () => {
          const ok = await showStatusModal(status, next.to);
          if (!ok) return;

          setBusy(wrapper, true);
          try {
            await postJSON(updateStatusUrl, { order_id: orderId, to_status: next.to }, nonce);
            toast('Status updated', 'success');
            window.location.reload();
          } catch (e) {
            toast(String(e.message || e), 'error');
          } finally {
            setBusy(wrapper, false);
          }
        });
        mainGroup.appendChild(b);
      }

      // Release (unassign) (modal)
      if (assigned && ['assigned', 'in_progress'].includes(status)) {
        const b = mkBtn('Release', 'knx-btn-danger');
        b.addEventListener('click', async () => {
          const ok = await showReleaseModal();
          if (!ok) return;

          setBusy(wrapper, true);
          try {
            await postJSON(unassignDriverUrl, { order_id: orderId }, nonce);
            toast('Order released', 'success');
            window.location.reload();
          } catch (e) {
            toast(String(e.message || e), 'error');
          } finally {
            setBusy(wrapper, false);
          }
        });
        dangerGroup.appendChild(b);
      }

      // If no actions exist, keep spacing clean (like screenshot)
      if (!mainGroup.children.length && !dangerGroup.children.length) {
        actionsRoot.innerHTML = `<div class="knx-ops-vo__muted">No actions for you right now!</div>`;
      }
    })();
  });
})();
