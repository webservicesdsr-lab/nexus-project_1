// inc/modules/ops/view-order/view-order-script.js
/**
 * KNX OPS — View Order Script (Read-only)
 * - Fetches /knx/v1/ops/view-order?order_id=...
 * - Renders a 1:1 "Order tracking" layout (left details, right map + history).
 * - Exposes SSOT to addons via window.KNX_VIEW_ORDER = { order }.
 *
 * Contract (from Network):
 * data.order = {
 *   status, created_at, created_human, city_id,
 *   delivery:{method,address,time_slot},
 *   payment:{method,status},
 *   restaurant:{id,name,phone,email,address,logo_url},
 *   customer:{name},
 *   totals:{total,tip,quote},
 *   driver:{assigned,driver_id,name?},
 *   location:{lat,lng,view_url},
 *   raw:{items,notes},
 *   status_history:[{status,changed_by,changed_by_label,created_at}]
 * }
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const app = document.getElementById('knxOpsViewOrderApp');
    if (!app) return;

    const apiUrl = String(app.dataset.apiUrl || '').trim();
    const restNonce = String(app.dataset.nonce || '').trim();

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

    function setState(msg) {
      if (stateEl) stateEl.textContent = msg || '';
    }

    function money(n) {
      const v = Number(n);
      return (Number.isFinite(v) ? v : 0).toFixed(2);
    }

    function safeHttpUrl(url) {
      const s = String(url || '').trim();
      if (!s) return '';
      if (s.startsWith('http://') || s.startsWith('https://')) return s;
      return '';
    }

    function pick(obj, path, fallback) {
      try {
        const parts = String(path || '').split('.');
        let cur = obj;
        for (let i = 0; i < parts.length; i++) {
          if (!cur || typeof cur !== 'object' || !(parts[i] in cur)) return fallback;
          cur = cur[parts[i]];
        }
        return (cur === null || cur === undefined) ? fallback : cur;
      } catch (e) {
        return fallback;
      }
    }

    function normalizeItems(order) {
      const rawItems = order && order.raw ? order.raw.items : null;
      if (Array.isArray(rawItems)) return rawItems;
      if (rawItems && typeof rawItems === 'object' && Array.isArray(rawItems.items)) return rawItems.items;

      // back-compat
      if (order && Array.isArray(order.items)) return order.items;
      if (order && order.items && typeof order.items === 'object' && Array.isArray(order.items.items)) return order.items.items;

      return [];
    }

    function normalizeModifiers(mods) {
      if (!Array.isArray(mods) || !mods.length) return [];

      const out = [];
      mods.forEach((group) => {
        const gName = String(group?.group || group?.name || '').trim();
        const options = Array.isArray(group?.options) ? group.options : (Array.isArray(group?.selected) ? group.selected : []);
        if (!gName && !options.length) return;

        const rendered = [];
        options.forEach((opt) => {
          const oName = String(opt?.option || opt?.name || opt?.value || '').trim();
          if (!oName) return;

          const deltaRaw =
            (opt?.price_adjustment !== undefined) ? opt.price_adjustment :
            (opt?.price !== undefined) ? opt.price :
            (opt?.delta !== undefined) ? opt.delta :
            undefined;

          const delta = Number(deltaRaw);
          const deltaTxt = (Number.isFinite(delta) && delta !== 0) ? ` (+$${money(delta)})` : '';
          rendered.push(`${esc(oName)}${deltaTxt}`);
        });

        out.push({
          group: esc(gName),
          optionsHtml: rendered.length ? rendered.join(', ') : '',
        });
      });

      return out;
    }

    function renderItems(items) {
      if (!Array.isArray(items) || !items.length) {
        return '<div class="knx-ops-vo__muted">No items.</div>';
      }

      const rows = items.map((it) => {
        const name = esc(String(it?.name_snapshot || it?.name || it?.title || 'Item'));
        const qty = Number(it?.qty ?? it?.quantity ?? 1) || 1;

        const unitRaw = (it?.unit_price !== undefined) ? it.unit_price : (it?.price !== undefined ? it.price : 0);
        const lineRaw = (it?.line_total !== undefined) ? it.line_total : (qty * (Number(unitRaw) || 0));

        const unit = money(unitRaw);
        const line = money(lineRaw);

        const mods = normalizeModifiers(it?.modifiers);
        const modsHtml = mods.length
          ? `<div class="knx-ops-vo__order-mods">${
              mods.map(m => {
                const left = m.group ? `<span class="knx-ops-vo__order-mod-g">${m.group}</span>` : '';
                const right = m.optionsHtml ? `<span class="knx-ops-vo__order-mod-o">${m.optionsHtml}</span>` : '';
                if (!left && !right) return '';
                return `<div class="knx-ops-vo__order-mod">${left}${left && right ? ': ' : ''}${right}</div>`;
              }).join('')
            }</div>`
          : '';

        return `
          <div class="knx-ops-vo__order-row">
            <div class="knx-ops-vo__order-left">
              <div class="knx-ops-vo__order-title">
                <span class="knx-ops-vo__dot">•</span>
                <span><strong>${qty} x</strong> ${name}</span>
              </div>
              <div class="knx-ops-vo__order-sub">Unit: $${unit}</div>
              ${modsHtml}
            </div>
            <div class="knx-ops-vo__order-price">$${line}</div>
          </div>
        `;
      }).join('');

      return `<div class="knx-ops-vo__order-list">${rows}</div>`;
    }

    function humanStatusLabel(s) {
      const v = String(s || '').trim().toLowerCase();
      if (!v) return '—';
      return v.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
    }

    function renderHistory(list) {
      const arr = Array.isArray(list) ? list : [];
      if (!arr.length) return `<div class="knx-ops-vo__muted">No history.</div>`;

      const rows = arr.map((h) => {
        const st = humanStatusLabel(h?.status);
        const at = esc(String(h?.created_at || '').trim());
        const by = esc(String(h?.changed_by_label || '').trim());

        return `
          <div class="knx-ops-vo__hist-item">
            <div class="knx-ops-vo__hist-icon" aria-hidden="true"></div>
            <div class="knx-ops-vo__hist-body">
              <div class="knx-ops-vo__hist-title">${esc(st)}</div>
              ${by ? `<div class="knx-ops-vo__hist-sub"><strong>Status from:</strong> ${by}</div>` : ``}
            </div>
            <div class="knx-ops-vo__hist-time">${at || '—'}</div>
          </div>
        `;
      }).join('');

      return `<div class="knx-ops-vo__hist">${rows}</div>`;
    }

    function buildMapEmbed(lat, lng) {
      const la = Number(lat);
      const ln = Number(lng);
      if (!Number.isFinite(la) || !Number.isFinite(ln)) return '';

      // No API key embed, compatible everywhere.
      const q = encodeURIComponent(la + ',' + ln);
      return `https://www.google.com/maps?q=${q}&z=13&output=embed`;
    }

    function renderOrder(order) {
      // Restaurant
      const rName  = esc(String(pick(order, 'restaurant.name', '') || ''));
      const rPhone = esc(String(pick(order, 'restaurant.phone', '') || ''));
      const rEmail = esc(String(pick(order, 'restaurant.email', '') || ''));
      const rAddr  = esc(String(pick(order, 'restaurant.address', '') || ''));
      const rLogo  = safeHttpUrl(pick(order, 'restaurant.logo_url', '') || '');

      // Customer
      const cName = esc(String(pick(order, 'customer.name', '') || ''));
      const cEmail = ''; // not in contract right now
      const dAddr  = esc(String(pick(order, 'delivery.address', '') || ''));
      const dSlot  = esc(String(pick(order, 'delivery.time_slot', '') || ''));
      const dMethod = esc(String(pick(order, 'delivery.method', '') || ''));

      // Order basics
      const created = esc(String(pick(order, 'created_at', '') || ''));
      const status = esc(String(pick(order, 'status', '') || ''));
      const statusNice = humanStatusLabel(status);

      // Totals
      const subtotal = (function () {
        const raw = pick(order, 'raw.items.subtotal', null);
        if (raw === null || raw === undefined) return null;
        const n = Number(raw);
        return Number.isFinite(n) ? n : null;
      })();

      const total = Number(pick(order, 'totals.total', 0));
      const tip = Number(pick(order, 'totals.tip', 0));

      // Best-effort: use quote breakdown if present (taxes/fees/delivery/discount)
      const quote = pick(order, 'totals.quote', null);
      const taxesAndFees = (quote && typeof quote === 'object') ? Number(quote.taxes_and_fees ?? quote.taxes ?? quote.fees ?? NaN) : NaN;
      const deliveryFee = (quote && typeof quote === 'object') ? Number(quote.delivery_fee ?? quote.delivery ?? NaN) : NaN;
      const discount = (quote && typeof quote === 'object') ? Number(quote.discount ?? NaN) : NaN;

      // Payment
      const payMethod = esc(String(pick(order, 'payment.method', '') || ''));
      const payStatus = esc(String(pick(order, 'payment.status', '') || ''));

      // Location
      const lat = pick(order, 'location.lat', null);
      const lng = pick(order, 'location.lng', null);
      const mapEmbed = buildMapEmbed(lat, lng);
      const mapExternal = safeHttpUrl(pick(order, 'location.view_url', '') || '');

      // Items + notes + history
      const items = normalizeItems(order);
      const notes = String(pick(order, 'raw.notes', '') || '').trim();
      const history = Array.isArray(order?.status_history) ? order.status_history : [];

      // Actions text (screenshot shows "No actions for you right now!" when none)
      // Actual actions UI is rendered by view-order-actions.js in top bar.

      contentEl.innerHTML = `
        <div class="knx-ops-vo__layout">

          <!-- LEFT -->
          <div class="knx-ops-vo__left">

            <div class="knx-ops-vo__panel">
              <div class="knx-ops-vo__panel-head">
                <div class="knx-ops-vo__panel-title">Restaurant information</div>
                <div class="knx-ops-vo__panel-pill">${esc(statusNice)}</div>
              </div>

              <div class="knx-ops-vo__info">
                <div class="knx-ops-vo__info-row">
                  ${rLogo ? `<img class="knx-ops-vo__logo" src="${esc(rLogo)}" alt="" loading="lazy">` : ``}
                  <div class="knx-ops-vo__info-main">
                    <div class="knx-ops-vo__info-name">${rName || '<span class="knx-ops-vo__muted">Unknown</span>'}</div>
                    <div class="knx-ops-vo__info-sub">${rAddr || ''}</div>
                    <div class="knx-ops-vo__info-links">
                      ${rPhone ? `<div class="knx-ops-vo__link">${rPhone}</div>` : ``}
                      ${rEmail ? `<div class="knx-ops-vo__link">${rEmail}</div>` : ``}
                    </div>
                  </div>
                </div>
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__section">
                <div class="knx-ops-vo__section-title">Client information</div>
                <div class="knx-ops-vo__kv">
                  <div class="knx-ops-vo__kv-row"><strong>${cName || '<span class="knx-ops-vo__muted">Unknown</span>'}</strong></div>
                  ${cEmail ? `<div class="knx-ops-vo__kv-row">${cEmail}</div>` : ``}
                  ${dAddr ? `<div class="knx-ops-vo__kv-row">${dAddr}</div>` : ``}
                </div>
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__section">
                <div class="knx-ops-vo__section-title">Order</div>
                ${renderItems(items)}
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__totals">
                ${subtotal !== null ? `<div class="knx-ops-vo__tot-row"><span>Sub Total:</span><strong>$${money(subtotal)}</strong></div>` : ``}
                ${Number.isFinite(taxesAndFees) ? `<div class="knx-ops-vo__tot-row"><span>Taxes and Fees:</span><strong>$${money(taxesAndFees)}</strong></div>` : ``}
                ${Number.isFinite(deliveryFee) ? `<div class="knx-ops-vo__tot-row"><span>Delivery:</span><strong>$${money(deliveryFee)}</strong></div>` : ``}
                ${Number.isFinite(discount) ? `<div class="knx-ops-vo__tot-row"><span>Discount:</span><strong>$${money(discount)}</strong></div>` : ``}
                <div class="knx-ops-vo__tot-row"><span>Tip:</span><strong>$${money(tip)}</strong></div>

                <div class="knx-ops-vo__tot-grand">
                  <span>TOTAL:</span>
                  <strong>$${money(total)}</strong>
                </div>
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__meta">
                <div class="knx-ops-vo__meta-row"><span>Payment method:</span><strong>${payMethod || '<span class="knx-ops-vo__muted">—</span>'}</strong></div>
                <div class="knx-ops-vo__meta-row"><span>Payment status:</span><strong>${payStatus || '<span class="knx-ops-vo__muted">—</span>'}</strong></div>
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__meta">
                <div class="knx-ops-vo__meta-row"><span>Delivery method:</span><strong>${dMethod || '<span class="knx-ops-vo__muted">—</span>'}</strong></div>
                ${dSlot ? `<div class="knx-ops-vo__meta-row"><span>Time slot:</span><strong>${dSlot}</strong></div>` : ``}
                <div class="knx-ops-vo__meta-row"><span>Created:</span><strong>${created || '<span class="knx-ops-vo__muted">—</span>'}</strong></div>
              </div>

              ${notes ? `
                <div class="knx-ops-vo__divider"></div>
                <div class="knx-ops-vo__section">
                  <div class="knx-ops-vo__section-title">Notes</div>
                  <div class="knx-ops-vo__notes">${esc(notes)}</div>
                </div>
              ` : ``}

              <div class="knx-ops-vo__divider"></div>
              <div class="knx-ops-vo__actions-card">
                <div class="knx-ops-vo__section-title">Actions</div>
                <div class="knx-ops-vo__muted">Actions are available in the top bar.</div>
              </div>
            </div>
          </div>

          <!-- RIGHT -->
          <div class="knx-ops-vo__right">

            <div class="knx-ops-vo__panel">
              <div class="knx-ops-vo__section-title">Order tracking</div>

              <div class="knx-ops-vo__map">
                ${
                  mapEmbed
                    ? `<iframe class="knx-ops-vo__map-iframe" src="${esc(mapEmbed)}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>`
                    : `<div class="knx-ops-vo__map-empty">No map available.</div>`
                }
                ${
                  mapExternal
                    ? `<a class="knx-ops-vo__map-link" href="${esc(mapExternal)}" target="_blank" rel="noopener">Open in Google Maps</a>`
                    : ``
                }
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__section">
                <div class="knx-ops-vo__section-title">Status History</div>
                ${renderHistory(history)}
              </div>
            </div>

          </div>
        </div>
      `;
    }

    function extractOrder(json) {
      if (!json || typeof json !== 'object') return null;
      if (json.data && json.data.order) return json.data.order;
      if (json.order) return json.order;
      if (json.data && json.data.data && json.data.data.order) return json.data.data.order;
      return null;
    }

    function renderError(title, detail) {
      contentEl.innerHTML = `
        <div class="knx-ops-vo__error">
          <div class="knx-ops-vo__error-title">${esc(title || 'Error')}</div>
          <div class="knx-ops-vo__error-detail">${esc(detail || '')}</div>
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
        const headers = {};
        if (restNonce) headers['X-WP-Nonce'] = restNonce;

        const res = await fetch(url, { credentials: 'same-origin', headers });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
          const msg = (json && json.message) ? json.message : (res.statusText || 'Request failed');
          setState('');
          renderError('Unable to load order', msg + ' (HTTP ' + res.status + ')');
          toast('Unable to load order', 'error');
          return;
        }

        const order = extractOrder(json);
        if (!order) {
          setState('');
          renderError('Bad response', 'The server returned an unexpected payload.');
          toast('Unexpected response', 'error');
          return;
        }

        setState('');

        // SSOT for addons
        try {
          const st = String((order.status || '')).toLowerCase();
          app.dataset.currentStatus = st;
          window.KNX_VIEW_ORDER = { order: order };
        } catch (e) {}

        renderOrder(order);
      } catch (e) {
        setState('');
        renderError('Network error', 'Check connection or session.');
        toast('Network error', 'error');
      }
    }

    fetchOrder();
  });
})();
