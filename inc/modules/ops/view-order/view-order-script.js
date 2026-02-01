/**
 * KNX OPS — View Order Script (Read-only)
 * - Fetches /knx/v1/ops/view-order?order_id=...
 * - Renders an operational snapshot without printing raw JSON.
 * - Exposes SSOT to addons via window.KNX_VIEW_ORDER = { order }.
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const app = document.getElementById('knxOpsViewOrderApp');
    if (!app) return;

    const apiUrl = String(app.dataset.apiUrl || '').trim();
    const stateEl = document.getElementById('knxOpsVOState');
    const contentEl = document.getElementById('knxOpsVOContent');
    if (!contentEl) return;

    const orderId = (function () {
      try {
        const u = new URL(window.location.href);
        const p = parseInt(u.searchParams.get('order_id') || '0', 10);
        return (p && Number.isFinite(p) && p > 0) ? p : 0;
      } catch (e) {
        return 0;
      }
    })();

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
      const v = Number(n);
      return (Number.isFinite(v) ? v : 0).toFixed(2);
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

    function safeHttpUrl(url) {
      const s = String(url || '').trim();
      if (!s) return '';
      if (s.startsWith('http://') || s.startsWith('https://')) return s;
      return '';
    }

    function pickFirst(obj, paths) {
      for (let i = 0; i < paths.length; i++) {
        const p = paths[i].split('.');
        let cur = obj;
        let ok = true;
        for (let j = 0; j < p.length; j++) {
          if (!cur || typeof cur !== 'object' || !(p[j] in cur)) { ok = false; break; }
          cur = cur[p[j]];
        }
        if (ok && cur !== null && cur !== undefined && String(cur).trim() !== '') return cur;
      }
      return '';
    }

    function normalizeItems(o) {
      // Supported shapes:
      // - array
      // - object with { items: [...] }
      // - order.items
      // - order.raw.items
      const candidate =
        (o && o.raw && o.raw.items !== undefined) ? o.raw.items :
        (o && o.items !== undefined) ? o.items :
        (o && o.raw !== undefined) ? o.raw :
        null;

      if (Array.isArray(candidate)) return candidate;

      // If raw.items is an object that contains items:[...]
      if (candidate && typeof candidate === 'object' && Array.isArray(candidate.items)) {
        return candidate.items;
      }

      // Sometimes order.raw itself may include items:[...]
      if (o && o.raw && typeof o.raw === 'object' && Array.isArray(o.raw.items)) {
        return o.raw.items;
      }

      return [];
    }

    function normalizeModifiers(mods) {
      // Snapshot can look like:
      // mods: [ { name:"Size", options:[{name:"Large", price_adjustment:4.49}] }, ... ]
      // We render: "Size: Large (+$4.49)"
      if (!Array.isArray(mods) || !mods.length) return [];

      const out = [];
      mods.forEach((group) => {
        const gName = String(group?.group || group?.name || '').trim();
        const options = Array.isArray(group?.options) ? group.options : (Array.isArray(group?.selected) ? group.selected : []);
        if (!gName && !options.length) return;

        const renderedOpts = [];
        options.forEach((opt) => {
          const oName = String(opt?.option || opt?.name || opt?.value || '').trim();
          if (!oName) return;

          const deltaRaw = (opt?.price_adjustment !== undefined) ? opt.price_adjustment
                        : (opt?.price !== undefined) ? opt.price
                        : (opt?.delta !== undefined) ? opt.delta
                        : undefined;

          const delta = Number(deltaRaw);
          const deltaTxt = (Number.isFinite(delta) && delta !== 0)
            ? ` (+$${money(delta)})`
            : '';

          renderedOpts.push(`${esc(oName)}${deltaTxt}`);
        });

        const line = {
          group: esc(gName),
          optionsHtml: renderedOpts.length ? renderedOpts.join(', ') : '',
        };
        out.push(line);
      });

      return out;
    }

    function renderItems(items) {
      if (!Array.isArray(items) || items.length === 0) {
        return '<div class="knx-ops-vo__muted">No items.</div>';
      }

      const rows = items.map((it) => {
        const name = esc(String(it?.name_snapshot || it?.name || it?.title || 'Item'));
        const qty = Number(it?.qty ?? it?.quantity ?? 1) || 1;

        const unitRaw = (it?.unit_price !== undefined) ? it.unit_price : (it?.price !== undefined ? it.price : 0);
        const lineRaw = (it?.line_total !== undefined) ? it.line_total : (qty * (Number(unitRaw) || 0));

        const unit = money(unitRaw);
        const line = money(lineRaw);

        const thumb = safeHttpUrl(it?.image_snapshot || it?.image || '');

        const mods = normalizeModifiers(it?.modifiers);
        const modsHtml = mods.length
          ? `<div class="knx-ops-vo__item-mods">${
              mods.map(m => {
                const left = m.group ? `<span class="knx-ops-vo__item-mod-group">${m.group}</span>` : '';
                const right = m.optionsHtml ? `<span class="knx-ops-vo__item-mod-opt">${m.optionsHtml}</span>` : '';
                if (!left && !right) return '';
                return `<div class="knx-ops-vo__item-mod">${left}${left && right ? ': ' : ''}${right}</div>`;
              }).join('')
            }</div>`
          : '';

        return `
          <div class="knx-ops-vo__item">
            ${thumb ? `<img class="knx-ops-vo__item-thumb" src="${esc(thumb)}" loading="lazy" alt="${name}">` : ''}
            <div class="knx-ops-vo__item-meta">
              <div class="knx-ops-vo__item-name">${name}</div>
              <div class="knx-ops-vo__item-sub">Qty: ${qty} &middot; Unit: $${unit}</div>
              ${modsHtml}
            </div>
            <div class="knx-ops-vo__item-price">$${line}</div>
          </div>
        `;
      }).join('');

      return `<div class="knx-ops-vo__items">${rows}</div>`;
    }

    function renderOrder(o) {
      const hubName = esc(String(
        pickFirst(o, ['hub.name', 'hub_name', 'hub']) || ''
      ));

      const statusRaw = String(pickFirst(o, ['status']) || '').trim();
      const status = esc(statusRaw);

      const createdHuman = esc(String(
        pickFirst(o, ['created_human', 'created_at']) || ''
      ));

      const customerName = esc(String(
        pickFirst(o, ['customer.name', 'customer_name']) || ''
      ));

      const totalsTotal = money(pickFirst(o, ['totals.total', 'totals.grand_total', 'total']) || 0);
      const totalsTip = money(pickFirst(o, ['totals.tip', 'tip']) || 0);

      const driverAssigned = !!pickFirst(o, ['driver.assigned']) || !!pickFirst(o, ['driver_assigned']);
      const driverName = esc(String(pickFirst(o, ['driver.name', 'driver_name']) || ''));
      const driverLine = driverAssigned ? (driverName ? `Assigned: ${driverName}` : 'Assigned') : 'Unassigned';

      const mapUrl = safeHttpUrl(pickFirst(o, ['location.view_url', 'location.map_url', 'location_url']) || '');
      const notesRaw = String(pickFirst(o, ['raw.notes', 'notes']) || '').trim();

      const items = normalizeItems(o);

      contentEl.innerHTML = `
        <div class="knx-ops-vo__header">
          <div class="knx-ops-vo__h-left">
            <div class="knx-ops-vo__hub">${hubName || '<span class="knx-ops-vo__muted">Unknown Hub</span>'}</div>
            <div class="knx-ops-vo__sub">${createdHuman || '<span class="knx-ops-vo__muted">—</span>'}</div>
          </div>
          <div class="knx-ops-vo__badge">${status || '—'}</div>
        </div>

        <div class="knx-ops-vo__grid">
          <div class="knx-ops-vo__card">
            <div class="knx-ops-vo__card-title">Customer</div>
            <div class="knx-ops-vo__card-body">
              ${customerName ? customerName : '<span class="knx-ops-vo__muted">Unknown</span>'}
            </div>
          </div>

          <div class="knx-ops-vo__card">
            <div class="knx-ops-vo__card-title">Totals</div>
            <div class="knx-ops-vo__card-body">
              <div class="knx-ops-vo__row2"><span>Total</span><strong>$${totalsTotal}</strong></div>
              <div class="knx-ops-vo__row2"><span>Tip</span><strong>$${totalsTip}</strong></div>
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
            ${notesRaw ? `<div class="knx-ops-vo__notes">${esc(notesRaw)}</div>` : `<span class="knx-ops-vo__muted">No notes.</span>`}
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

        // SSOT: expose current status and order to addons (non-blocking)
        try {
          const st = String((json.data.order.status || '')).toLowerCase();
          app.dataset.currentStatus = st;
          window.KNX_VIEW_ORDER = { order: json.data.order };
        } catch (e) {}

        renderOrder(json.data.order);

      } catch (err) {
        setState('');
        renderError('Network error', 'Check connection or session.');
        toast('Network error', 'error');
      }
    }

    fetchOrder();
  });
})();
