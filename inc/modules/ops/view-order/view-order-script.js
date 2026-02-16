// inc/modules/ops/view-order/view-order-script.js
/**
 * KNX OPS — View Order Script (Read-only)
 * - Fetches /knx/v1/ops/view-order?order_id=...
 * - Renders a 1:1 "Order tracking" layout (left details, right map + history).
 * - Exposes SSOT to addons via window.KNX_VIEW_ORDER = { order }.
 * - Dispatches event for addons: document.dispatchEvent('knx:view-order:loaded')
 *
 * Contract (from Network):
 * data.order = {
 *   status, created_at, created_human, city_id,
 *   delivery:{method,address,time_slot},
 *   payment:{method,status},
 *   restaurant:{id,name,phone,email,address,logo_url},
 *   customer:{name,email?,phone?},
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

    function escAttr(s) {
      return esc(s).replace(/\s+/g, ' ').trim();
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

    function firstNonEmpty() {
      for (let i = 0; i < arguments.length; i++) {
        const v = String(arguments[i] || '').trim();
        if (v) return v;
      }
      return '';
    }

    function sanitizeTelNumber(raw) {
      const s = String(raw || '').trim();
      if (!s) return '';
      // Keep digits; allow leading +
      let out = s.replace(/[^\d+]/g, '');
      // Only allow + at the start
      out = out.replace(/\+/g, function (m, offset) { return offset === 0 ? '+' : ''; });
      // Must contain at least some digits
      const digits = out.replace(/[^\d]/g, '');
      if (digits.length < 7) return '';
      return out;
    }

    function sanitizeEmail(raw) {
      const s = String(raw || '').trim();
      if (!s) return '';
      // Basic safety (not strict validation)
      if (!s.includes('@') || s.includes(' ')) return '';
      return s;
    }

    function telHref(rawPhone) {
      const n = sanitizeTelNumber(rawPhone);
      if (!n) return '';
      return 'tel:' + encodeURIComponent(n);
    }

    function mailtoHref(rawEmail) {
      const e = sanitizeEmail(rawEmail);
      if (!e) return '';
      return 'mailto:' + encodeURIComponent(e);
    }

    function renderPaymentPill(status) {
      const raw = String(status || '').trim();
      if (!raw) return `<span class="knx-ops-vo__muted">—</span>`;

      const st = raw.toLowerCase();
      let cls = 'knx-ops-vo__pill';
      let label = raw;

      if (st === 'paid' || st === 'succeeded' || st === 'completed' || st === 'success') {
        cls += ' knx-ops-vo__pill--paid';
        label = 'PAID';
      } else if (st === 'pending' || st === 'processing' || st === 'requires_payment_method' || st === 'requires_action') {
        cls += ' knx-ops-vo__pill--pending';
        label = 'PENDING';
      } else if (st === 'failed' || st === 'declined' || st === 'canceled' || st === 'cancelled' || st === 'error') {
        cls += ' knx-ops-vo__pill--failed';
        label = 'FAILED';
      } else {
        label = raw.toUpperCase();
      }

      return `<span class="${escAttr(cls)}">${esc(label)}</span>`;
    }

    // ---------- CANON helpers ----------
    function normalizeStatus(s) {
      const v = String(s || '').trim().toLowerCase();

      // legacy aliases
      if (v === 'placed') return 'confirmed';
      if (v === 'accepted_by_restaurant') return 'accepted_by_hub';
      if (v === 'out_for_delivery') return 'picked_up';
      if (v === 'ready') return 'prepared';

      return v;
    }

    // Canon labels:
    // - Never expose pending_payment
    // - Never show "confirmed" literal (use "Waiting for driver")
    function statusLabelHuman(s) {
      const v = normalizeStatus(s);
      const map = {
        order_created: 'Order Created',
        pending_payment: 'Processing payment', // never shown
        confirmed: 'Waiting for driver',
        accepted_by_driver: 'Accepted by Driver',
        accepted_by_hub: 'Accepted by Hub',
        preparing: 'Preparing',
        prepared: 'Prepared',
        picked_up: 'Picked up',
        completed: 'Completed',
        cancelled: 'Cancelled',
      };
      if (!v) return '—';
      if (map[v]) return map[v];
      return v.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase()).replace(/\bBy\b/g, 'by');
    }

    // Timeline normalization (CANON):
    // - Hide pending_payment
    // - De-dupe "order_created"
    // - Ensure "Waiting for driver" exists for operational orders (if missing)
    function normalizeHistory(order) {
      const raw = Array.isArray(order && order.status_history) ? order.status_history : [];
      const createdAt = String(pick(order, 'created_at', '') || '').trim();

      const cleaned = raw
        .filter(h => h && typeof h === 'object')
        .map(h => Object.assign({}, h, { status: normalizeStatus(h.status) }))
        .filter(h => {
          const st = normalizeStatus(h.status);
          if (!st) return false;
          if (st === 'pending_payment') return false;
          return true;
        });

      const out = [];

      // Always keep ONE "Order Created" on top (use order.created_at)
      if (createdAt) {
        out.push({ status: 'order_created', created_at: createdAt, changed_by_label: '' });
      }

      // Push all history except any "order_created"/"created" rows (avoid duplicates)
      cleaned.forEach(h => {
        const st = normalizeStatus(h.status);
        if (st === 'order_created' || st === 'created') return;
        out.push(h);
      });

      // Ensure confirmed exists for operational orders (but do not duplicate)
      const hasConfirmed = out.some(h => normalizeStatus(h.status) === 'confirmed');
      const curSt = normalizeStatus(order && order.status);
      const isOperational = !!curSt && curSt !== 'pending_payment';

      if (isOperational && !hasConfirmed) {
        const insertAt = (out.length && normalizeStatus(out[0].status) === 'order_created') ? 1 : 0;
        out.splice(insertAt, 0, { status: 'confirmed', created_at: createdAt, changed_by_label: '' });
      }

      return out;
    }

    // ---------- Items ----------
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
          group: String(gName || ''),
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

        const line = money(lineRaw);

        const mods = normalizeModifiers(it?.modifiers);
        const modsHtml = mods.length
          ? `<div class="knx-ops-vo__item-mods">${
              mods.map(m => {
                const left = esc(String(m.group || '').trim());
                const right = String(m.optionsHtml || '').trim();
                if (!left && !right) return '';
                return `<div class="knx-ops-vo__item-mod"><span class="knx-ops-vo__item-mod-g">${left}</span>${left && right ? ': ' : ''}<span class="knx-ops-vo__item-mod-o">${right}</span></div>`;
              }).join('')
            }</div>`
          : '';

        const qtyLabel = qty > 1 ? `<span class="knx-ops-vo__item-qty">${qty}x</span>` : '';

        return `
          <div class="knx-ops-vo__item">
            <div class="knx-ops-vo__item-row">
              <span class="knx-ops-vo__item-name">${qtyLabel}${name}</span>
              <span class="knx-ops-vo__item-price">$${line}</span>
            </div>
            ${modsHtml}
          </div>
        `;
      }).join('');

      return `<div class="knx-ops-vo__items">${rows}</div>`;
    }

    // ---------- History ----------
    function renderHistory(list) {
      const arr = Array.isArray(list) ? list : [];
      if (!arr.length) return `<div class="knx-ops-vo__muted">No history.</div>`;

      const rows = arr.map((h) => {
        const stNorm = normalizeStatus(h?.status);
        const st = statusLabelHuman(stNorm);
        const at = esc(String(h?.created_at || '').trim());

        // For "order_created", never show "Status from"
        const byRaw = String(h?.changed_by_label || '').trim();
        const by = (stNorm === 'order_created') ? '' : esc(byRaw);

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
      const q = encodeURIComponent(la + ',' + ln);
      return `https://www.google.com/maps?q=${q}&z=13&output=embed`;
    }

    function niceCap(s) {
      const raw = String(s || '').trim();
      if (!raw) return '';
      return raw.charAt(0).toUpperCase() + raw.slice(1);
    }

    function renderOrder(order) {
      const rName  = esc(String(pick(order, 'restaurant.name', '') || ''));
      const rPhone = esc(String(pick(order, 'restaurant.phone', '') || ''));
      const rEmail = esc(String(pick(order, 'restaurant.email', '') || ''));
      const rAddr  = esc(String(pick(order, 'restaurant.address', '') || ''));
      const rLogo  = safeHttpUrl(pick(order, 'restaurant.logo_url', '') || '');

      // Customer fields (API should provide; accept fallbacks)
      const cNameRaw  = firstNonEmpty(pick(order, 'customer.name', ''), pick(order, 'customer_name', ''));
      const cPhoneRaw = firstNonEmpty(pick(order, 'customer.phone', ''), pick(order, 'customer_phone', ''));
      const cEmailRaw = firstNonEmpty(pick(order, 'customer.email', ''), pick(order, 'customer_email', ''));

      const cName  = esc(cNameRaw);
      const cPhoneDisplay = esc(cPhoneRaw);
      const cEmailDisplay = esc(cEmailRaw);

      const cTel = telHref(cPhoneRaw);
      const cMailto = mailtoHref(cEmailRaw);

      const dAddrRaw  = String(pick(order, 'delivery.address', '') || '').trim();
      const dSlotRaw  = String(pick(order, 'delivery.time_slot', '') || '').trim();
      const fulfillmentRaw = String(pick(order, 'delivery.method', '') || '').trim();

      const dAddr  = esc(dAddrRaw);
      const dSlot  = esc(dSlotRaw);

      const created = esc(String(pick(order, 'created_at', '') || ''));
      const statusRaw = String(pick(order, 'status', '') || '');
      const statusNice = statusLabelHuman(statusRaw);

      const subtotal = (function () {
        const raw = pick(order, 'raw.items.subtotal', null);
        if (raw === null || raw === undefined) return null;
        const n = Number(raw);
        return Number.isFinite(n) ? n : null;
      })();

      const total = Number(pick(order, 'totals.total', 0));
      const tip = Number(pick(order, 'totals.tip', 0));

      const quote = pick(order, 'totals.quote', null);
      const taxesAndFees = (quote && typeof quote === 'object') ? Number(quote.taxes_and_fees ?? quote.taxes ?? quote.fees ?? NaN) : NaN;
      const deliveryFee = (quote && typeof quote === 'object') ? Number(quote.delivery_fee ?? quote.delivery ?? NaN) : NaN;
      const discount = (quote && typeof quote === 'object') ? Number(quote.discount ?? NaN) : NaN;

      const payMethodRaw = String(pick(order, 'payment.method', '') || '').trim();
      const payStatusRaw = String(pick(order, 'payment.status', '') || '').trim();

      const payMethod = esc(payMethodRaw);
      const payStatus = esc(payStatusRaw);

      const lat = pick(order, 'location.lat', null);
      const lng = pick(order, 'location.lng', null);
      const mapEmbed = buildMapEmbed(lat, lng);
      const mapExternal = safeHttpUrl(pick(order, 'location.view_url', '') || '');

      const items = normalizeItems(order);
      const notes = String(pick(order, 'raw.notes', '') || '').trim();
      const history = normalizeHistory(order);

      contentEl.innerHTML = `
        <div class="knx-ops-vo__layout">

          <div class="knx-ops-vo__left">
            <div id="knxViewOrderActions" class="knx-ops-vo__actions knx-ops-vo__actions--in-left"></div>

            <div class="knx-ops-vo__panel">
              <div class="knx-ops-vo__panel-head">
                <div class="knx-ops-vo__panel-title">Restaurant information</div>
                <div class="knx-ops-vo__panel-pill">${esc(statusNice)}</div>
              </div>

              <div class="knx-ops-vo__info">
                <div class="knx-ops-vo__info-row">
                  ${rLogo ? `<img class="knx-ops-vo__logo" src="${escAttr(rLogo)}" alt="" loading="lazy">` : ``}
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
                  <div class="knx-ops-vo__kv-row knx-ops-vo__kv-row--name">
                    <strong>${cName || '<span class="knx-ops-vo__muted">Unknown</span>'}</strong>
                  </div>

                  ${(cTel || cMailto) ? `
                    <div class="knx-ops-vo__client-actions">
                      ${cTel ? `<a href="${escAttr(cTel)}" class="knx-ops-vo__btn knx-ops-vo__btn--call">📞 Call <span class="knx-ops-vo__btn-sub">${cPhoneDisplay}</span></a>` : ``}
                      ${cMailto ? `<a href="${escAttr(cMailto)}" class="knx-ops-vo__btn knx-ops-vo__btn--email">✉️ Email <span class="knx-ops-vo__btn-sub">${cEmailDisplay}</span></a>` : ``}
                    </div>
                  ` : ``}

                  ${dAddr ? `<div class="knx-ops-vo__address">${dAddr}</div>` : ``}
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
                <div class="knx-ops-vo__meta-row">
                  <span>Payment status:</span>
                  ${renderPaymentPill(payStatusRaw)}
                </div>
                <div class="knx-ops-vo__meta-row">
                  <span>Payment method:</span>
                  <strong>${payMethodRaw ? esc(niceCap(payMethodRaw)) : '<span class="knx-ops-vo__muted">—</span>'}</strong>
                </div>
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__meta">
                <div class="knx-ops-vo__meta-row">
                  <span>Fulfillment:</span>
                  <strong>${fulfillmentRaw ? esc(niceCap(fulfillmentRaw)) : '<span class="knx-ops-vo__muted">—</span>'}</strong>
                </div>
                ${dSlotRaw ? `<div class="knx-ops-vo__meta-row"><span>Time slot:</span><strong>${dSlot}</strong></div>` : ``}
                <div class="knx-ops-vo__meta-row"><span>Created:</span><strong>${created || '<span class="knx-ops-vo__muted">—</span>'}</strong></div>
              </div>

              ${notes ? `
                <div class="knx-ops-vo__divider"></div>
                <div class="knx-ops-vo__section">
                  <div class="knx-ops-vo__section-title">Notes</div>
                  <div class="knx-ops-vo__notes">${esc(notes)}</div>
                </div>
              ` : ``}
            </div>
          </div>

          <div class="knx-ops-vo__right">
            <div class="knx-ops-vo__panel">
              <div class="knx-ops-vo__section-title">Order tracking</div>

              <div class="knx-ops-vo__map">
                ${
                  mapEmbed
                    ? `<iframe class="knx-ops-vo__map-iframe" src="${escAttr(mapEmbed)}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>`
                    : `<div class="knx-ops-vo__map-empty">No map available.</div>`
                }
                ${
                  mapExternal
                    ? `<a class="knx-ops-vo__map-link" href="${escAttr(mapExternal)}" target="_blank" rel="noopener">Open in Google Maps</a>`
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

        try {
          const st = normalizeStatus(order.status);
          app.dataset.currentStatus = st;
          window.KNX_VIEW_ORDER = { order: order };
          try {
            document.dispatchEvent(new CustomEvent('knx:view-order:loaded', { detail: { order: order } }));
          } catch (e) {}
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
