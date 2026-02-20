// inc/modules/ops/view-order/view-order-actions.js
/**
 * ==========================================================
 * KNX OPS — View Order Actions (OPS v1) — CANON FINAL (OPTION A)
 *
 * Allowed modals:
 * - Status picker modal (list of statuses; previous states disabled)
 * - Release (unassign) modal
 *
 * Notes:
 * - NO native alert/confirm
 * - SSOT only: window.KNX_VIEW_ORDER.order
 * - Uses CANON statuses (Option A):
 *   placed -> accepted_by_driver -> accepted_by_restaurant -> preparing -> prepared -> out_for_delivery -> completed
 *   cancelled allowed from any non-terminal (requires reason)
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

    function esc(s) {
      if (s === null || s === undefined) return '';
      return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
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
      Array.from(root.querySelectorAll('textarea,input')).forEach((el) => { el.disabled = !!v; });
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

    // CANON SSOT statuses (Option A)
    const STATUS_ORDER = [
      'placed',
      'accepted_by_driver',
      'accepted_by_restaurant',
      'preparing',
      'prepared',
      'out_for_delivery',
      'completed',
      'cancelled',
    ];

    const LABEL = {
      placed: 'Placed',
      accepted_by_driver: 'Accepted by driver',
      accepted_by_restaurant: 'Accepted by restaurant',
      preparing: 'Preparing',
      prepared: 'Prepared',
      out_for_delivery: 'Out for delivery',
      completed: 'Completed',
      cancelled: 'Cancelled',
    };

    function labelOf(st) {
      const s = String(st || '').trim().toLowerCase();
      return LABEL[s] || (s ? s.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase()) : '—');
    }

    function toneClass(st) {
      const s = String(st || '').toLowerCase();
      if (s === 'cancelled') return 'knx-status-opt--red';
      if (s === 'completed') return 'knx-status-opt--green';
      if (s === 'out_for_delivery') return 'knx-status-opt--blue';
      if (s === 'prepared') return 'knx-status-opt--purple';
      if (s === 'preparing') return 'knx-status-opt--orange';
      if (s === 'accepted_by_restaurant') return 'knx-status-opt--green';
      if (s === 'accepted_by_driver') return 'knx-status-opt--blue';
      return 'knx-status-opt--green';
    }

    function idxOf(st) {
      const s = String(st || '').toLowerCase();
      const i = STATUS_ORDER.indexOf(s);
      return i >= 0 ? i : -1;
    }

    function computeAllowedNext(currentStatus) {
      const s = String(currentStatus || '').toLowerCase().trim();
      if (!s) return [];

      if (s === 'completed' || s === 'cancelled') return [];

      const i = idxOf(s);
      const next = (i >= 0 && i + 1 < STATUS_ORDER.length) ? STATUS_ORDER[i + 1] : null;

      const allowed = [];
      if (next && next !== 'cancelled') allowed.push(next);
      allowed.push('cancelled');
      return allowed;
    }

    function renderStatusOptions(currentStatus) {
      const curIdx = idxOf(currentStatus);
      const allowedNext = computeAllowedNext(currentStatus);

      return STATUS_ORDER.map((st) => {
        const stIdx = idxOf(st);
        const isPrev = (curIdx >= 0 && stIdx >= 0 && stIdx < curIdx);
        const isCurrent = String(st) === String(currentStatus);
        const isAllowed = allowedNext.includes(st);

        const disabled = isPrev || isCurrent || (!isAllowed);

        const cls = [
          'knx-status-opt',
          toneClass(st),
          isCurrent ? 'is-selected' : '',
          disabled ? 'is-disabled' : '',
        ].filter(Boolean).join(' ');

        const sub = (st === 'cancelled')
          ? '<span class="knx-status-opt__sub">Requires a reason</span>'
          : '';

        return `
          <button type="button"
            class="${cls}"
            data-status="${esc(st)}"
            ${disabled ? 'disabled aria-disabled="true"' : ''}
          >
            <div>
              <div class="knx-status-opt__label">${esc(labelOf(st))}</div>
              ${sub}
            </div>
            <div class="knx-status-opt__dot" aria-hidden="true"></div>
          </button>
        `;
      }).join('');
    }

    function showStatusPickerModal(orderId, currentStatus, urls, nonce) {
      return new Promise((resolve) => {
        const m = ensureModalShell('knxVoStatusPickerModal');

        m.innerHTML = `
          <div data-close="1" class="knx-modal-overlay"></div>
          <div role="dialog" aria-modal="true" class="knx-modal-dialog knx-modal-dialog--statuspicker">
            <div class="knx-modal-header">
              <div>
                <div class="knx-modal-title">Change status</div>
                <div class="knx-modal-subtitle">Current: <strong>${esc(labelOf(currentStatus))}</strong></div>
              </div>
              <button type="button" data-close="1" class="knx-modal-close">✕</button>
            </div>

            <div class="knx-modal-body">
              <div class="knx-status-list">
                ${renderStatusOptions(currentStatus)}
              </div>

              <div class="knx-vo-cancel-reason" style="display:none; margin-top:12px;">
                <div style="font-weight:900; margin-bottom:6px;">Cancellation reason</div>
                <textarea id="knxVoCancelReason" rows="3"
                  style="width:100%; border-radius:12px; border:1px solid rgba(0,0,0,0.14); padding:10px 12px; font-weight:700; resize:vertical;"
                  placeholder="Write a short reason (min 3 chars)"></textarea>
              </div>
            </div>

            <div class="knx-modal-actions knx-modal-actions--split">
              <button type="button" data-close="1" class="knx-btn knx-btn-secondary">Close</button>
              <button type="button" data-confirm="1" class="knx-btn knx-btn-primary" disabled>Confirm</button>
            </div>
          </div>
        `;

        let selected = null;

        const dlg = m.querySelector('.knx-modal-dialog');
        const reasonWrap = m.querySelector('.knx-vo-cancel-reason');
        const reasonEl = m.querySelector('#knxVoCancelReason');
        const confirmBtn = m.querySelector('[data-confirm="1"]');

        function setConfirmEnabled(on) {
          confirmBtn.disabled = !on;
        }

        function updateReasonUI() {
          if (!reasonWrap) return;
          if (selected === 'cancelled') {
            reasonWrap.style.display = 'block';
            setTimeout(() => { try { reasonEl && reasonEl.focus(); } catch (e) {} }, 0);
          } else {
            reasonWrap.style.display = 'none';
            if (reasonEl) reasonEl.value = '';
          }
        }

        const onClick = async (ev) => {
          const t = ev.target;
          if (!t) return;

          if (t.matches('[data-close="1"]')) {
            m.removeEventListener('click', onClick);
            hideModal(m);
            return resolve({ ok: false });
          }

          const btn = t.closest && t.closest('button[data-status]');
          if (btn && btn.getAttribute) {
            const st = String(btn.getAttribute('data-status') || '').toLowerCase().trim();
            if (!st) return;
            if (btn.disabled) return;

            Array.from(m.querySelectorAll('.knx-status-opt')).forEach((x) => x.classList.remove('is-selected'));
            btn.classList.add('is-selected');

            selected = st;
            updateReasonUI();
            setConfirmEnabled(true);
            return;
          }

          if (t.matches('[data-confirm="1"]')) {
            if (!selected) return;

            let reason = '';
            if (selected === 'cancelled') {
              reason = String(reasonEl ? reasonEl.value : '').trim();
              if (reason.length < 3) {
                toast('Cancellation reason is required (min 3 chars)', 'error');
                try { reasonEl && reasonEl.focus(); } catch (e) {}
                return;
              }
            }

            setBusy(dlg, true);

            try {
              await postJSON(urls.updateStatusUrl, {
                order_id: orderId,
                to_status: selected,   // OPS alias
                status: selected,      // Canon route compatibility
                reason: (selected === 'cancelled') ? reason : undefined,
              }, nonce);

              toast('Status updated', 'success');
              m.removeEventListener('click', onClick);
              hideModal(m);
              return resolve({ ok: true });
            } catch (e) {
              toast(String(e.message || e), 'error');
              setBusy(dlg, false);
              return;
            }
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
              <div>
                <div class="knx-modal-title">Release order</div>
                <div class="knx-modal-subtitle">This will unassign the driver from this order.</div>
              </div>
              <button type="button" data-close="1" class="knx-modal-close">✕</button>
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

      if (!order) return;
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

      const urls = {
        updateStatusUrl: String(app.dataset.updateStatusUrl || '/wp-json/knx/v1/ops/update-status'),
        unassignDriverUrl: String(app.dataset.unassignDriverUrl || '/wp-json/knx/v1/ops/unassign-driver'),
      };
      const nonce = String(app.dataset.nonce || '');

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

      function mkBtn(label, className) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'knx-btn ' + (className || '');
        b.textContent = label;
        return b;
      }

      if (!['completed', 'cancelled'].includes(status)) {
        const b = mkBtn('Change status', 'knx-btn-primary');
        b.addEventListener('click', async () => {
          const res = await showStatusPickerModal(orderId, status, urls, nonce);
          if (res && res.ok) window.location.reload();
        });
        mainGroup.appendChild(b);
      }

      if (assigned && !['completed', 'cancelled'].includes(status)) {
        const b = mkBtn('Release', 'knx-btn-danger');
        b.addEventListener('click', async () => {
          const ok = await showReleaseModal();
          if (!ok) return;

          setBusy(wrapper, true);
          try {
            await postJSON(urls.unassignDriverUrl, { order_id: orderId }, nonce);
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

      if (!mainGroup.children.length && !dangerGroup.children.length) {
        actionsRoot.innerHTML = `<div class="knx-ops-vo__muted">No actions for you right now!</div>`;
      }
    })();
  });
})();