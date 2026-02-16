// inc/modules/ops/view-order/view-order-actions.js
/**
 * KNX OPS — View Order Actions (Manager/Super Admin)
 * - Renders "ORDER STATUS" button (Image 1) and status update modal (Image 2)
 * - Calls POST /knx/v1/ops/update-status with { order_id, to_status }
 * - Enforces CANON transition rules client-side:
 *   - Only next status allowed (index + 1)
 *   - cancelled allowed from any non-terminal (requires confirmation)
 * - Does NOT expose order_id in DOM datasets (reads from URL)
 */

(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  onReady(function () {
    const app = document.getElementById('knxOpsViewOrderApp');
    const mount = document.getElementById('knxViewOrderActions');
    if (!app || !mount) return;

    const updateUrl = String(app.dataset.updateStatusUrl || '').trim();
    const restNonce = String(app.dataset.nonce || '').trim();

    function toast(msg, type) {
      if (typeof window.knxToast === 'function') return window.knxToast(msg, type || 'info');
      console.log('[knx-toast]', type || 'info', msg);
    }

    function esc(s) {
      if (s === null || s === undefined) return '';
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function getOrderIdFromUrl() {
      try {
        const u = new URL(window.location.href);
        const p = parseInt(u.searchParams.get('order_id') || '0', 10);
        return (p && Number.isFinite(p) && p > 0) ? p : 0;
      } catch (e) {
        return 0;
      }
    }

    const orderId = getOrderIdFromUrl();

    // SSOT operational flow (driver-first)
    const FLOW = ['confirmed', 'accepted_by_driver', 'accepted_by_hub', 'preparing', 'prepared', 'picked_up', 'completed'];

    function normalizeStatus(s) {
      const st = String(s || '').trim().toLowerCase();
      if (st === 'placed') return 'confirmed';
      if (st === 'accepted_by_restaurant') return 'accepted_by_hub';
      if (st === 'out_for_delivery') return 'picked_up';
      if (st === 'ready') return 'prepared';
      return st;
    }

    function isTerminal(st) {
      return st === 'completed' || st === 'cancelled';
    }

    function idx(st) {
      const i = FLOW.indexOf(st);
      return i >= 0 ? i : -1;
    }

    function nextAllowed(cur) {
      const i = idx(cur);
      if (i < 0) return '';
      return FLOW[i + 1] || '';
    }

    // Human label mapping (OPS UI)
    function label(st) {
      const v = normalizeStatus(st);
      const map = {
        pending_payment: 'Processing payment',
        confirmed: 'Waiting for driver',
        accepted_by_driver: 'Accepted by Driver',
        accepted_by_hub: 'Accepted by Hub',
        preparing: 'Preparing',
        prepared: 'Prepared',
        picked_up: 'Picked up',
        completed: 'Completed',
        cancelled: 'Cancelled',
      };
      return map[v] || (v ? v.replace(/_/g, ' ').replace(/\b\w/g, m => m.toUpperCase()) : '—');
    }

    let currentOrder = null;
    let currentStatus = '';
    let selectedStatus = '';
    let cancelArmed = false;

    // Status button (Image 1)
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'knx-ops-vo-statusbtn';
    btn.innerHTML = `
      <div class="knx-ops-vo-statusbtn__kicker">ORDER STATUS</div>
      <div class="knx-ops-vo-statusbtn__value">—</div>
    `;
    const valueEl = btn.querySelector('.knx-ops-vo-statusbtn__value');

    // Modal (Image 2)
    const modal = document.createElement('div');
    modal.className = 'knx-ops-vo-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
      <div class="knx-ops-vo-modal__backdrop" data-close="1"></div>
      <div class="knx-ops-vo-modal__panel" role="dialog" aria-modal="true" aria-labelledby="knxOpsVOStatusModalTitle">
        <div class="knx-ops-vo-modal__head">
          <div>
            <div class="knx-ops-vo-modal__title" id="knxOpsVOStatusModalTitle">Update Order Status</div>
            <div class="knx-ops-vo-modal__sub">Choose the current status of this<br>order</div>
          </div>
          <button type="button" class="knx-ops-vo-modal__x" data-close="1" aria-label="Close">&times;</button>
        </div>

        <div class="knx-ops-vo-modal__list" role="listbox" aria-label="Order status options"></div>

        <div class="knx-ops-vo-modal__footer">
          <button type="button" class="knx-ops-vo-modal__btn knx-ops-vo-modal__btn--cancel" data-close="1">Cancel</button>
          <button type="button" class="knx-ops-vo-modal__btn knx-ops-vo-modal__btn--update" id="knxOpsVOStatusUpdateBtn">Update</button>
        </div>
      </div>
    `;

    const listEl = modal.querySelector('.knx-ops-vo-modal__list');
    const updateBtn = modal.querySelector('#knxOpsVOStatusUpdateBtn');

    mount.innerHTML = '';
    mount.appendChild(btn);
    document.body.appendChild(modal);

    function openModal() {
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      cancelArmed = false;
      syncUpdateBtn();
    }

    function closeModal() {
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      cancelArmed = false;
      syncUpdateBtn();
    }

    modal.addEventListener('click', (ev) => {
      const t = ev.target;
      if (t && t.getAttribute && t.getAttribute('data-close') === '1') closeModal();
      if (t === modal) closeModal();
    });

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') closeModal();
    });

    btn.addEventListener('click', () => {
      if (!currentOrder) {
        toast('Order not loaded yet', 'info');
        return;
      }
      openModal();
    });

    function buildOption(status, tone) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = `knx-ops-vo-statusopt knx-ops-vo-statusopt--${tone}`;
      b.setAttribute('role', 'option');
      b.setAttribute('data-status', status);

      const isCancel = status === 'cancelled';

      b.innerHTML = `
        <span class="knx-ops-vo-statusopt__text">${esc(label(status))}</span>
        <span class="knx-ops-vo-statusopt__dot" aria-hidden="true"></span>
        ${isCancel ? `<span class="knx-ops-vo-statusopt__warn">This action requires confirmation</span>` : ``}
      `;

      b.addEventListener('click', () => {
        if (isTerminal(currentStatus)) return;

        const wanted = normalizeStatus(status);
        cancelArmed = false;

        const next = nextAllowed(currentStatus);

        const valid =
          (wanted === normalizeStatus(currentStatus)) ||
          (wanted === next) ||
          (wanted === 'cancelled');

        selectedStatus = wanted;
        renderSelection();

        if (!valid) {
          toast('Invalid transition. Only the next status is allowed.', 'error');
        }
      });

      return b;
    }

    function renderOptions() {
      if (!listEl) return;
      listEl.innerHTML = '';

      // Modal order 1:1 (Image 2) — only change: Restaurant -> Hub
      const opts = [
        { status: 'accepted_by_driver', tone: 'green' },
        { status: 'accepted_by_hub', tone: 'green' },
        { status: 'preparing', tone: 'orange' },
        { status: 'prepared', tone: 'blue' },
        { status: 'picked_up', tone: 'purple' },
        { status: 'completed', tone: 'green' },
        { status: 'cancelled', tone: 'red' },
      ];

      opts.forEach(o => listEl.appendChild(buildOption(o.status, o.tone)));
      renderSelection();
    }

    function renderSelection() {
      const buttons = listEl ? listEl.querySelectorAll('.knx-ops-vo-statusopt') : [];
      buttons.forEach((b) => {
        const st = normalizeStatus(b.getAttribute('data-status') || '');
        const selected = (st === normalizeStatus(selectedStatus || ''));
        b.classList.toggle('is-selected', selected);
        b.setAttribute('aria-selected', selected ? 'true' : 'false');
      });

      syncUpdateBtn();
    }

    function selectionIsValid() {
      const cur = normalizeStatus(currentStatus);
      const sel = normalizeStatus(selectedStatus);

      if (!cur || !sel) return false;
      if (isTerminal(cur)) return false;
      if (sel === cur) return false;

      if (sel === 'cancelled') return true;

      const next = nextAllowed(cur);
      return !!next && sel === next;
    }

    function syncUpdateBtn() {
      if (!updateBtn) return;
      const enabled = selectionIsValid() && !!updateUrl && !!orderId;
      updateBtn.disabled = !enabled;
    }

    async function postUpdate(toStatus) {
      const headers = { 'Content-Type': 'application/json' };
      if (restNonce) headers['X-WP-Nonce'] = restNonce;

      const res = await fetch(updateUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify({ order_id: orderId, to_status: toStatus }),
      });

      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        const msg = (json && (json.message || json.error)) ? (json.message || json.error) : (res.statusText || 'Request failed');
        throw new Error(msg + ' (HTTP ' + res.status + ')');
      }
      return json;
    }

    updateBtn.addEventListener('click', async () => {
      if (!selectionIsValid()) return;

      const to = normalizeStatus(selectedStatus);

      if (to === 'cancelled') {
        if (!cancelArmed) {
          cancelArmed = true;
          toast('Press Update again to confirm cancellation.', 'error');
          return;
        }
      }

      cancelArmed = false;
      updateBtn.disabled = true;

      try {
        await postUpdate(to);
        toast('Status updated', 'success');
        window.location.reload(); // SSOT refresh
      } catch (e) {
        console.warn('Update status failed', e);
        toast((e && e.message) ? e.message : 'Unable to update status', 'error');
        updateBtn.disabled = false;
      }
    });

    function setFromOrder(order) {
      currentOrder = order || null;
      currentStatus = normalizeStatus(order && order.status ? order.status : '');
      selectedStatus = '';
      cancelArmed = false;

      if (valueEl) valueEl.textContent = label(currentStatus);

      renderOptions();
      syncUpdateBtn();
    }

    // Event fired by view-order-script.js when SSOT order loads
    document.addEventListener('knx:view-order:loaded', (ev) => {
      if (!ev || !ev.detail || !ev.detail.order) return;
      setFromOrder(ev.detail.order);
    });

    // If already loaded
    if (window.KNX_VIEW_ORDER && window.KNX_VIEW_ORDER.order) {
      setFromOrder(window.KNX_VIEW_ORDER.order);
    }
  });
})();
