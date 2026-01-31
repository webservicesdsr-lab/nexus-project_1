/**
 * KNX OPS — View Order Script (Read-only)
 * - Fetches /knx/v1/ops/view-order?order_id=...
 * - Renders an operational snapshot without exposing internal IDs in UI.
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const app = document.getElementById('knxOpsViewOrderApp');
    if (!app) return;

    const apiUrl = app.dataset.apiUrl || '';
    const orderId = parseInt(app.dataset.orderId || '0', 10);
    const stateEl = document.getElementById('knxOpsVOState');
    const contentEl = document.getElementById('knxOpsVOContent');

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

    function money(n) {
      return (Number(n) || 0).toFixed(2);
    }

    function setState(msg) {
      if (stateEl) stateEl.textContent = msg || '';
    }

    function renderError(title, detail) {
      contentEl.innerHTML = `
        <div class="knx-ops-vo__error">
          <div class="knx-ops-vo__error-title">${esc(title || 'Error')}</div>
          <div class="knx-ops-vo__error-detail">${esc(detail || '')}</div>
        </div>
      `;
    }

    function renderItems(items) {
      if (!items) return '<div class="knx-ops-vo__muted">No items available.</div>';

      // Array of line items?
      if (Array.isArray(items)) {
        const rows = items.map((it) => {
          const name = esc(it.name || it.title || 'Item');
          const qty = esc(it.qty || it.quantity || 1);
          const price = (it.price !== undefined || it.amount !== undefined) ? money(it.price ?? it.amount) : null;
          return `
            <div class="knx-ops-vo__item">
              <div class="knx-ops-vo__item-name">${name}</div>
              <div class="knx-ops-vo__item-meta">
                <span>Qty: ${qty}</span>
                ${price !== null ? `<span>$${price}</span>` : ''}
              </div>
            </div>
          `;
        }).join('');

        return `<div class="knx-ops-vo__items">${rows}</div>`;
      }

      // Object? Show compact JSON preview
      try {
        const json = JSON.stringify(items, null, 2);
        return `<pre class="knx-ops-vo__pre">${esc(json)}</pre>`;
      } catch (e) {
        return `<pre class="knx-ops-vo__pre">${esc(String(items))}</pre>`;
      }
    }

    function renderOrder(o) {
      const hubName = esc(o?.hub?.name || '');
      const status = esc(o?.status || '');
      const createdHuman = esc(o?.created_human || '');
      const customerName = esc(o?.customer?.name || '');
      const total = money(o?.totals?.total);
      const tip = money(o?.totals?.tip);

      const driverAssigned = !!o?.driver?.assigned;
      const driverName = esc(o?.driver?.name || '');
      const driverLine = driverAssigned
        ? (driverName ? `Assigned: ${driverName}` : 'Assigned')
        : 'Unassigned';

      const mapUrl = o?.location?.view_url ? String(o.location.view_url) : '';
      const notes = (o?.raw?.notes || '').trim();
      const items = o?.raw?.items ?? null;

      contentEl.innerHTML = `
        <div class="knx-ops-vo__header">
          <div class="knx-ops-vo__h-left">
            <div class="knx-ops-vo__hub">${hubName}</div>
            <div class="knx-ops-vo__sub">${createdHuman}</div>
          </div>
          <div class="knx-ops-vo__badge">${status}</div>
        </div>

        <div class="knx-ops-vo__grid">
          <div class="knx-ops-vo__card">
            <div class="knx-ops-vo__card-title">Customer</div>
            <div class="knx-ops-vo__card-body">${customerName || '<span class="knx-ops-vo__muted">Unknown</span>'}</div>
          </div>

          <div class="knx-ops-vo__card">
            <div class="knx-ops-vo__card-title">Totals</div>
            <div class="knx-ops-vo__card-body">
              <div class="knx-ops-vo__row"><span>Total</span><strong>$${total}</strong></div>
              <div class="knx-ops-vo__row"><span>Tip</span><strong>$${tip}</strong></div>
            </div>
          </div>

          <div class="knx-ops-vo__card">
            <div class="knx-ops-vo__card-title">Driver</div>
            <div class="knx-ops-vo__card-body">
              <div class="knx-ops-vo__pill ${driverAssigned ? 'is-on' : 'is-off'}">${esc(driverLine)}</div>
            </div>
          </div>

          <div class="knx-ops-vo__card">
            <div class="knx-ops-vo__card-title">Location</div>
            <div class="knx-ops-vo__card-body">
              ${
                mapUrl
                  ? `<a class="knx-ops-vo__btn" href="${esc(mapUrl)}" target="_blank" rel="noopener">View Location</a>`
                  : `<span class="knx-ops-vo__muted">No location available.</span>`
              }
            </div>
          </div>
        </div>

        <div class="knx-ops-vo__card knx-ops-vo__card--full">
          <div class="knx-ops-vo__card-title">Items</div>
          <div class="knx-ops-vo__card-body">
            ${renderItems(items)}
          </div>
        </div>

        <div class="knx-ops-vo__card knx-ops-vo__card--full">
          <div class="knx-ops-vo__card-title">Notes</div>
          <div class="knx-ops-vo__card-body">
            ${notes ? `<div>${esc(notes)}</div>` : `<span class="knx-ops-vo__muted">No notes.</span>`}
          </div>
        </div>
      `;
    }

    async function fetchOrder() {
      if (!apiUrl || !orderId) {
        setState('Missing order_id');
        renderError('Missing order_id', 'Open this page with ?order_id=123');
        return;
      }

      setState('Loading…');

      try {
        const url = apiUrl + '?order_id=' + encodeURIComponent(String(orderId));
        const res = await fetch(url, { credentials: 'same-origin' });

        let json = null;
        try { json = await res.json(); } catch (e) {}

        if (!res.ok) {
          const msg = (json && json.message) ? json.message : (res.statusText || 'Request failed');
          setState('');
          renderError('Unable to load order', msg + ' (HTTP ' + res.status + ')');
          toast('Unable to load order', 'error');
          return;
        }

        if (!json || !json.success || !json.data || !json.data.order) {
          setState('');
          renderError('Bad response', 'The server returned an unexpected payload.');
          toast('Unexpected response', 'error');
          return;
        }

        setState('');
        renderOrder(json.data.order);

      } catch (err) {
        console.error('View order fetch error', err);
        setState('');
        renderError('Network error', 'Check connection or session.');
        toast('Network error', 'error');
      }
    }

    fetchOrder();
  });
})();
