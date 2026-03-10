// File: inc/modules/ops/driver-view-order/driver-view-order-script.js
// Adapted full view-order renderer for drivers — based on ops view-order script
/* eslint-disable */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const app = document.getElementById('knxOpsViewOrderApp');
    if (!app) return;

    // Read data provided by shortcode
    const apiUrl = String(app.dataset.apiUrl || '').replace(/\/+$/, '');
    const restNonce = String(app.dataset.nonce || '').trim();
    const orderIdAttr = parseInt(app.dataset.orderId || '0', 10);
    const backUrl = String(app.dataset.backUrl || '/driver-active-orders');

    const stateEl = document.getElementById('knxOpsVOState');
    const contentEl = document.getElementById('knxOpsVOContent');
    if (!contentEl) return;

    const orderId = (function () {
      try {
        if (orderIdAttr && Number.isFinite(orderIdAttr) && orderIdAttr > 0) return orderIdAttr;
        const u = new URL(window.location.href);
        const p = parseInt(u.searchParams.get('order_id') || '0', 10);
        return (p && Number.isFinite(p) && p > 0) ? p : 0;
      } catch (e) { return 0; }
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

    function setState(msg) { if (stateEl) stateEl.textContent = msg || ''; }

    function money(n) { const v = Number(n); return (Number.isFinite(v) ? v : 0).toFixed(2); }

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
      } catch (e) { return fallback; }
    }

    function normalizeItems(order) {
      const rawItems = order && order.raw ? order.raw.items : null;
      if (Array.isArray(rawItems)) return rawItems;
      if (rawItems && typeof rawItems === 'object' && Array.isArray(rawItems.items)) return rawItems.items;
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

        out.push({ group: esc(gName), optionsHtml: rendered.length ? rendered.join(', ') : '' });
      });
      return out;
    }

    function renderItems(items) {
      if (!Array.isArray(items) || !items.length) return '<div class="knx-ops-vo__muted">No items.</div>';

      const rows = items.map((it) => {
        const name = esc(String(it?.name_snapshot || it?.name || it?.title || 'Item'));
        const qty = Number(it?.qty ?? it?.quantity ?? 1) || 1;

        const unitRaw = (it?.unit_price !== undefined) ? it.unit_price : (it?.price !== undefined ? it.price : 0);
        const lineRaw = (it?.line_total !== undefined) ? it.line_total : (qty * (Number(unitRaw) || 0));

        const unit = money(unitRaw);
        const line = money(lineRaw);

        const mods = normalizeModifiers(it?.modifiers);
        const modsHtml = mods.length
          ? `<div class="knx-ops-vo__order-mod">${mods.map(m => {
              const left = m.group ? `<span class="knx-ops-vo__order-mod-g">${m.group}</span>` : '';
              const right = m.optionsHtml ? `<span class="knx-ops-vo__order-mod-o">${m.optionsHtml}</span>` : '';
              if (!left && !right) return '';
              return `<div class="knx-ops-vo__order-mod">${left}${left && right ? ': ' : ''}${right}</div>`;
            }).join('')}</div>`
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
      // Canonical labels (same across ALL modules)
      const labels = {
        'pending_payment': 'Pending Payment',
        'confirmed': 'Order Created',
        'accepted_by_driver': 'Accepted by Driver',
        'accepted_by_hub': 'Accepted by Restaurant',
        'preparing': 'Preparing',
        'prepared': 'Prepared',
        'picked_up': 'Picked Up',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
        'order_created': 'Order Created',
      };
      return labels[v] || v.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
    }

    function renderHistory(list, isPickupOrder) {
      const arr = Array.isArray(list) ? list : [];
      if (!arr.length) return `<div class="knx-ops-vo__muted">No history.</div>`;

      const rows = arr
        // Hide financial/internal states (pending_payment, confirmed) from timeline
        // For pickup orders, also hide picked_up (it's an auto double-jump, not a manual step)
        .filter(h => {
          const st = String(h?.status || '').trim().toLowerCase();
          const ts = String(h?.created_at || '').trim();
          if (ts === '' || st === 'pending_payment' || st === 'confirmed') return false;
          if (isPickupOrder && st === 'picked_up') return false;
          return true;
        })
        .map((h) => {
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

      return rows ? `<div class="knx-ops-vo__hist">${rows}</div>` : `<div class="knx-ops-vo__muted">No history.</div>`;
    }

    function buildMapEmbed(lat, lng) {
      const la = Number(lat);
      const ln = Number(lng);
      if (!Number.isFinite(la) || !Number.isFinite(ln)) return '';
      const q = encodeURIComponent(la + ',' + ln);
      return `https://www.google.com/maps?q=${q}&z=13&output=embed`;
    }

    function normalizePhoneForTel(phone) {
      const p = String(phone || '').trim();
      if (!p) return '';
      const cleaned = p.replace(/[^\d+]/g, '');
      return cleaned;
    }

    /* ── Navigation helpers (native map schemes) ── */
    function platformIsIOS() { return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream; }
    function platformIsAndroid() { return /Android/i.test(navigator.userAgent); }

    function buildWebNav(lat, lng, address) {
      const la = (lat !== null && lat !== undefined) ? Number(lat) : NaN;
      const ln = (lng !== null && lng !== undefined) ? Number(lng) : NaN;
      const a = String(address || '').trim();
      if (Number.isFinite(la) && Number.isFinite(ln)) {
        return 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(String(la) + ',' + String(ln));
      }
      if (a) return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(a);
      return 'https://www.google.com/maps';
    }

    // Must be global for inline onclick handlers in rendered HTML
    window.openNavigation = function openNavigation(lat, lng, address, ev) {
      try { if (ev && ev.preventDefault) ev.preventDefault(); } catch (e) {}
      const web = buildWebNav(lat, lng, address);

      if (platformIsIOS()) {
        const native = (lat !== null && lng !== null)
          ? 'maps://?daddr=' + encodeURIComponent(String(lat) + ',' + String(lng)) + (address ? '&q=' + encodeURIComponent(String(address)) : '')
          : 'maps://?q=' + encodeURIComponent(String(address || ''));
        window.location.href = native;
        setTimeout(function () { window.location.href = web; }, 700);
        return;
      }

      if (platformIsAndroid()) {
        const native = (lat !== null && lng !== null)
          ? 'geo:' + encodeURIComponent(String(lat) + ',' + String(lng)) + '?q=' + encodeURIComponent(String(address || ''))
          : 'geo:0,0?q=' + encodeURIComponent(String(address || ''));
        window.location.href = native;
        setTimeout(function () { window.location.href = web; }, 700);
        return;
      }

      window.location.href = web;
    };

    function renderOrder(order) {
      const rName  = esc(String(pick(order, 'restaurant.name', '') || ''));
      const rPhoneRaw = String(pick(order, 'restaurant.phone', '') || '').trim();
      const rPhone = esc(rPhoneRaw);
      const rTel = normalizePhoneForTel(rPhoneRaw);
      const rEmail = esc(String(pick(order, 'restaurant.email', '') || ''));
      const rAddrRaw = String(pick(order, 'restaurant.address', '') || '').trim();
      const rAddr  = esc(rAddrRaw);
      const rLat = pick(order, 'restaurant.location.lat', null);
      const rLng = pick(order, 'restaurant.location.lng', null);
      const rLogo  = safeHttpUrl(pick(order, 'restaurant.logo_url', '') || '');

      const cName  = String(pick(order, 'customer.name', '') || '').trim();
      const cPhone = String(pick(order, 'customer.phone', '') || '').trim();
      const cEmail = String(pick(order, 'customer.email', '') || '').trim();

      const dAddrRaw = String(pick(order, 'delivery.address', '') || '').trim();
      const dAddr  = esc(dAddrRaw);
      const dSlot  = esc(String(pick(order, 'delivery.time_slot', '') || ''));
      const dMethodRaw = String(pick(order, 'delivery.method', '') || '').trim();
      const dMethod = esc(dMethodRaw);
      const isPickup = dMethodRaw.toLowerCase() === 'pickup';

      const created = esc(String(pick(order, 'created_at', '') || ''));
      const status = esc(String(pick(order, 'status', '') || ''));
      const statusNice = humanStatusLabel(status);

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

      const payMethod = esc(String(pick(order, 'payment.method', '') || ''));
      const payStatus = esc(String(pick(order, 'payment.status', '') || ''));

      const lat = pick(order, 'location.lat', null);
      const lng = pick(order, 'location.lng', null);
      const mapEmbed = buildMapEmbed(lat, lng);
      const mapExternal = safeHttpUrl(pick(order, 'location.view_url', '') || '');

      const items = normalizeItems(order);
      const notes = String(pick(order, 'raw.notes', '') || '').trim();
      const history = Array.isArray(order?.status_history) ? order.status_history : [];

      const tel = normalizePhoneForTel(cPhone);
      const hasCustomer = (cName !== '' || cPhone !== '' || cEmail !== '' || dAddr !== '');

      const rHasCoords = (rLat !== null && rLng !== null);
      const rNavWeb = rHasCoords
        ? `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(String(rLat) + ',' + String(rLng))}`
        : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(rAddrRaw)}`;

      const dHasCoords = (lat !== null && lng !== null);
      const dNavWeb = dHasCoords
        ? `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(String(lat) + ',' + String(lng))}`
        : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(dAddrRaw)}`;

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
                  ${rLogo ? `<img class="knx-ops-vo__logo" src="${esc(rLogo)}" alt="" loading="lazy">` : ``}
                  <div class="knx-ops-vo__info-main">
                    <div class="knx-ops-vo__info-name">${rName || '<span class="knx-ops-vo__muted">Unknown</span>'}</div>
                    <div class="knx-ops-vo__info-sub">${
                      rAddr
                        ? (rLat !== null && rLng !== null)
                          ? `<a class="knx-ops-vo__link" href="${rNavWeb}" onclick="openNavigation(${JSON.stringify(rLat)}, ${JSON.stringify(rLng)}, ${JSON.stringify(rAddrRaw)}, event)" target="_blank" rel="noopener">${rAddr}</a>`
                          : `<a class="knx-ops-vo__link" href="${rNavWeb}" onclick="openNavigation(null, null, ${JSON.stringify(rAddrRaw)}, event)" target="_blank" rel="noopener">${rAddr}</a>`
                        : ''
                    }</div>
                    <div class="knx-ops-vo__info-links">
                      ${rPhone ? `<a class="knx-ops-vo__link" href="tel:${esc(rTel || rPhone)}" aria-label="Call restaurant">${rPhone}</a>` : ``}
                      ${rEmail ? `<a class="knx-ops-vo__link" href="mailto:${esc(rEmail)}" aria-label="Email restaurant">${rEmail}</a>` : ``}
                    </div>
                  </div>
                </div>
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__section">
                <div class="knx-ops-vo__section-title">Client information</div>

                ${hasCustomer ? `
                  <div class="knx-ops-vo__client" style="display:flex;flex-direction:column;gap:0.4rem;font-size:1.05rem;">
                    <div class="knx-ops-vo__client-name">
                      <strong>${esc(cName || 'Unknown')}</strong>
                    </div>

                    ${(cPhone || cEmail) ? `
                      <div class="knx-ops-vo__client-actions" style="display:flex;flex-direction:column;gap:0.25rem;">
                        ${cPhone ? `<a class="knx-ops-vo__link" href="tel:${esc(tel || cPhone)}" aria-label="Call ${esc(cName)}">${esc(cPhone)}</a>` : ``}
                        ${cEmail ? `<a class="knx-ops-vo__link" href="mailto:${esc(cEmail)}" aria-label="Email ${esc(cName)}">${esc(cEmail)}</a>` : ``}
                      </div>
                    ` : ``}

                    ${dAddr ? `${
                      (lat !== null && lng !== null)
                        ? `<a class="knx-ops-vo__client-address knx-ops-vo__link" href="${dNavWeb}" onclick="openNavigation(${JSON.stringify(lat)}, ${JSON.stringify(lng)}, ${JSON.stringify(dAddrRaw)}, event)" target="_blank" rel="noopener">${dAddr}</a>`
                        : `<a class="knx-ops-vo__client-address knx-ops-vo__link" href="${dNavWeb}" onclick="openNavigation(null, null, ${JSON.stringify(dAddrRaw)}, event)" target="_blank" rel="noopener">${dAddr}</a>`
                    }` : ``}
                  </div>
                ` : `
                  <div class="knx-ops-vo__muted">Client info not available.</div>
                `}
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
                ${isPickup
                  ? `<div class="knx-ops-vo__meta-row"><span>Fulfillment:</span><strong>🛍️ Customer Pickup</strong></div>`
                  : `<div class="knx-ops-vo__meta-row"><span>Delivery method:</span><strong>${dMethod || '<span class="knx-ops-vo__muted">—</span>'}</strong></div>`
                }
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
            </div>
          </div>

          <div class="knx-ops-vo__right">
            <div class="knx-ops-vo__panel">
              <div class="knx-ops-vo__section-title">Order tracking</div>

              <div class="knx-ops-vo__map">
                ${isPickup
                  ? `<div class="knx-ops-vo__pickup-banner" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:32px 20px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;text-align:center;">
                      <div style="font-size:2.5rem;">🛍️</div>
                      <div style="font-size:1.1rem;font-weight:700;color:#166534;">Customer Pickup Order</div>
                      <div style="font-size:0.95rem;color:#15803d;">The customer will pick up this order directly at the restaurant. No delivery needed.</div>
                    </div>`
                  : `${mapEmbed
                      ? `<iframe class="knx-ops-vo__map-iframe" src="${esc(mapEmbed)}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>`
                      : `<div class="knx-ops-vo__map-empty">No map available.</div>`
                    }
                    ${mapExternal
                      ? `<a class="knx-ops-vo__map-link" href="${esc(mapExternal)}" target="_blank" rel="noopener">Open in Google Maps</a>`
                      : ``
                    }`
                }
              </div>

              <div class="knx-ops-vo__divider"></div>

              <div class="knx-ops-vo__section">
                <div class="knx-ops-vo__section-title">Status History</div>
                ${renderHistory(history, isPickup)}
              </div>
            </div>
          </div>
        </div>
      `;
    }

    /* ── Action buttons & status change modal ── */
    function getNextStatuses(currentStatus, pickupOrder) {
      pickupOrder = pickupOrder === true;
      const s = String(currentStatus || '').toLowerCase();

      // Driver-allowed transitions (sequential only, no skipping states)
      // For pickup orders: after prepared the backend auto-applies picked_up (double-jump),
      // so the driver's next visible step from prepared is completed.
      const map = pickupOrder ? {
        'confirmed': ['accepted_by_driver'],
        'accepted_by_driver': ['accepted_by_hub'],
        'accepted_by_hub': ['preparing'],
        'preparing': ['prepared'],
        'picked_up': ['completed'],
      } : {
        'confirmed': ['accepted_by_driver'],
        'accepted_by_driver': ['accepted_by_hub'],
        'accepted_by_hub': ['preparing'],
        'preparing': ['prepared'],
        'prepared': ['picked_up'],
        'picked_up': ['completed'],
      };

      return map[s] || [];
    }

    function getLabelForStatus(status) {
      const s = String(status || '').toLowerCase();
      // Canonical labels (same across ALL modules)
      const labels = {
        'pending_payment': 'Pending Payment',
        'confirmed': 'Order Created',
        'accepted_by_driver': 'Accepted by Driver',
        'accepted_by_hub': 'Accepted by Restaurant',
        'preparing': 'Preparing',
        'prepared': 'Prepared',
        'picked_up': 'Picked Up',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
        'order_created': 'Order Created',
      };
      return labels[s] || status.replace(/_/g, ' ').replace(/\b\w/g, m => m.toUpperCase());
    }

    function renderActionButtons(order) {
      const currentStatus = String(order?.status || '').toLowerCase();
      const orderIsPickup = String(pick(order, 'delivery.method', '') || '').toLowerCase() === 'pickup';
      const nextStatuses = getNextStatuses(currentStatus, orderIsPickup);

      const actionsContainer = document.getElementById('knxViewOrderActions');
      if (!actionsContainer) return;

      let html = '';

      // Change Status button (only if next statuses exist)
      if (nextStatuses.length > 0) {
        html += `
          <button type="button" class="knx-ops-vo__action-btn knx-ops-vo__action-btn--primary" id="knxBtnChangeStatus">
            Change Status
          </button>
        `;
      }

      // Release button (only before picked_up)
      const noReleaseStatuses = ['picked_up', 'completed', 'cancelled'];
      if (!noReleaseStatuses.includes(currentStatus)) {
        html += `
          <button type="button" class="knx-ops-vo__action-btn knx-ops-vo__action-btn--danger" id="knxBtnRelease">
            Release Order
          </button>
        `;
      }

      actionsContainer.innerHTML = html;

      const btnChange = document.getElementById('knxBtnChangeStatus');
      if (btnChange) btnChange.addEventListener('click', () => openStatusModal(order));

      const btnRelease = document.getElementById('knxBtnRelease');
      if (btnRelease) btnRelease.addEventListener('click', () => openReleaseModal(order.order_id));
    }

    function openStatusModal(order) {
      const currentStatus = String(order?.status || '').toLowerCase();
      const orderIsPickup = String(pick(order, 'delivery.method', '') || '').toLowerCase() === 'pickup';
      const nextStatuses = getNextStatuses(currentStatus, orderIsPickup);

      if (!nextStatuses.length) {
        toast('No status changes available', 'info');
        return;
      }

      const modalHTML = `
        <div class="knx-ops-modal-overlay" id="knxStatusModalOverlay">
          <div class="knx-ops-modal">
            <div class="knx-ops-modal__header">
              <h3 class="knx-ops-modal__title">Change Status</h3>
              <button type="button" class="knx-ops-modal__close" id="knxModalClose" aria-label="Close">&times;</button>
            </div>
            <div class="knx-ops-modal__body">
              <div class="knx-ops-modal__current">
                Current: <strong>${esc(getLabelForStatus(currentStatus))}</strong>
              </div>
              <div class="knx-ops-modal__status-list">
                ${nextStatuses.map(st => {
                  const btnLabel = (orderIsPickup && st === 'prepared') ? 'Ready for Pickup' : getLabelForStatus(st);
                  return `<button type="button" class="knx-ops-modal__status-btn" data-status="${esc(st)}">${esc(btnLabel)}</button>`;
                }).join('')}
              </div>
            </div>
          </div>
        </div>
      `;

      const existing = document.getElementById('knxStatusModalOverlay');
      if (existing) existing.remove();

      document.body.insertAdjacentHTML('beforeend', modalHTML);

      const overlay = document.getElementById('knxStatusModalOverlay');
      const closeBtn = document.getElementById('knxModalClose');
      const statusBtns = overlay.querySelectorAll('.knx-ops-modal__status-btn');

      const closeModal = () => { if (overlay) overlay.remove(); };

      closeBtn?.addEventListener('click', closeModal);
      overlay?.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });

      statusBtns.forEach(btn => {
        btn.addEventListener('click', async () => {
          const newStatus = btn.dataset.status;
          if (!newStatus) return;

          btn.disabled = true;
          btn.textContent = 'Processing...';

          try {
            await changeOrderStatus(order.order_id, newStatus);
            closeModal();
            toast('Status updated successfully', 'success');
            fetchOrder();
          } catch (err) {
            toast(err.message || 'Failed to update status', 'error');
            btn.disabled = false;
            btn.textContent = (orderIsPickup && newStatus === 'prepared') ? 'Ready for Pickup' : getLabelForStatus(newStatus);
          }
        });
      });
    }

    async function changeOrderStatus(orderId, newStatus) {
      const url = apiUrl.replace(/\/+$/, '') + '/' + encodeURIComponent(String(orderId)) + '/ops-status';

      const body = JSON.stringify({
        status: newStatus,
        knx_nonce: await getKnxNonce(),
      });

      const headers = { 'Content-Type': 'application/json' };
      if (restNonce) headers['X-WP-Nonce'] = restNonce;

      const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers, body });
      const json = await res.json().catch(() => ({}));

      if (!res.ok || !json.success) {
        const msg = (json && json.data && json.data.message) || (json && json.message) || 'Request failed';
        throw new Error(msg);
      }

      return json.data;
    }

    function openReleaseModal(orderId) {
      const modalHTML = `
        <div class="knx-ops-modal-overlay" id="knxReleaseModalOverlay">
          <div class="knx-ops-modal">
            <div class="knx-ops-modal__header">
              <h3 class="knx-ops-modal__title">Release Order</h3>
              <button type="button" class="knx-ops-modal__close" id="knxReleaseModalClose" aria-label="Close">&times;</button>
            </div>
            <div class="knx-ops-modal__body">
              <p style="margin:0 0 1.5rem;color:var(--text-secondary,#6b7280);">Are you sure you want to release this order? It will become available for other drivers.</p>
              <div style="display:flex;gap:0.75rem;flex-direction:column;">
                <button type="button" class="knx-ops-modal__status-btn" id="knxConfirmRelease" style="background:var(--danger,#ef4444);color:white;">
                  Yes, Release Order
                </button>
                <button type="button" class="knx-ops-modal__status-btn" id="knxCancelRelease" style="background:var(--bg-secondary,#f3f4f6);color:var(--text,#111827);">
                  No, Keep Order
                </button>
              </div>
            </div>
          </div>
        </div>
      `;

      const existing = document.getElementById('knxReleaseModalOverlay');
      if (existing) existing.remove();

      document.body.insertAdjacentHTML('beforeend', modalHTML);

      const overlay = document.getElementById('knxReleaseModalOverlay');
      const closeBtn = document.getElementById('knxReleaseModalClose');
      const confirmBtn = document.getElementById('knxConfirmRelease');
      const cancelBtn = document.getElementById('knxCancelRelease');

      const closeModal = () => { if (overlay) overlay.remove(); };

      closeBtn?.addEventListener('click', closeModal);
      cancelBtn?.addEventListener('click', closeModal);
      overlay?.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });

      confirmBtn?.addEventListener('click', async () => {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Releasing...';
        try {
          await releaseOrder(orderId);
          closeModal();
        } catch (err) {
          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Yes, Release Order';
        }
      });
    }

    async function releaseOrder(orderId) {
      const url = apiUrl.replace(/\/+$/, '') + '/' + encodeURIComponent(String(orderId)) + '/release';
      const body = JSON.stringify({ knx_nonce: await getKnxNonce() });

      const headers = { 'Content-Type': 'application/json' };
      if (restNonce) headers['X-WP-Nonce'] = restNonce;

      setState('Releasing...');

      try {
        const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers, body });
        const json = await res.json().catch(() => ({}));

        if (!res.ok || !json.success) {
          const msg = (json && json.data && json.data.message) || (json && json.message) || 'Request failed';
          setState('');
          toast(msg, 'error');
          return;
        }

        setState('');
        toast('Order released successfully', 'success');
        setTimeout(() => { window.location.href = backUrl; }, 900);
      } catch (e) {
        setState('');
        toast('Network error', 'error');
      }
    }

    async function getKnxNonce() {
      if (typeof window.knxNonce !== 'undefined') return window.knxNonce;
      if (typeof window.wpApiSettings !== 'undefined' && window.wpApiSettings.nonce) return window.wpApiSettings.nonce;
      try {
        const res = await fetch(apiUrl.replace(/\/driver\/orders.*$/, '/nonce/knx'), { credentials: 'same-origin' });
        const json = await res.json();
        if (json && json.nonce) return json.nonce;
      } catch (e) {}
      return '';
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
        renderError('Missing order_id', 'Open this page with ?order_id=123 or provide data-order-id');
        return;
      }

      setState('Loading…');

      try {
        let url = '';
        if (apiUrl.indexOf('/knx/v2/driver/orders') !== -1 || /\/knx\/v2\/driver\/orders/.test(apiUrl)) {
          url = apiUrl.replace(/\/+$/, '') + '/' + encodeURIComponent(String(orderId));
        } else {
          url = apiUrl + '?order_id=' + encodeURIComponent(String(orderId));
        }

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

        let order = null;
        if (json && json.data && json.data.order) order = json.data.order;
        else if (json && json.order) order = json.order;
        else if (json && json.data && json.data.data && json.data.data.order) order = json.data.data.order;
        else if (json && json.data) order = json.data;

        if (!order) {
          setState('');
          renderError('Bad response', 'The server returned an unexpected payload.');
          toast('Unexpected response', 'error');
          return;
        }

        setState('');

        try {
          const st = String((order.status || '')).toLowerCase();
          app.dataset.currentStatus = st;
          window.KNX_VIEW_ORDER = { order: order };
        } catch (e) {}

        renderOrder(order);
        renderActionButtons(order);

        const terminalSt = ['completed', 'cancelled'];
        const stCheck = String((order.status || '')).toLowerCase();
        initDriverChat(terminalSt.includes(stCheck));
      } catch (e) {
        setState('');
        renderError('Network error', 'Check connection or session.');
        toast('Network error', 'error');
      }
    }

    // ══════════════════════════════════════════════════════
    // DRIVER CHAT — Driver ↔ Customer (Incremental + Live)
    // Fixes:
    // - Hard no-cache GET (cache: 'no-store' + cache-buster param)
    // - Polling always calls incremental fetch (after_id cursor)
    // - Immediate refresh on visibilitychange (no "wait 10s" feeling)
    // - Stops polling when terminal
    // ══════════════════════════════════════════════════════

    const MSG_POLL_MS = 10000;
    let msgPollTimer = null;
    let chatInitialized = false;
    let chatIsTerminal = false;

    // Incremental state
    let driverChatLoadedOnce = false;     // first load window (last N)
    let driverChatLastId = 0;             // client cursor
    let driverChatServerLastId = 0;       // server cursor
    let driverChatBuffer = [];            // bounded UI buffer
    let driverChatRendering = false;      // prevents overlapping render/fetch races

    function buildMsgApiBase() {
      // v2 driver orders -> v1 orders
      return apiUrl.replace(/\/knx\/v2\/driver\/orders.*$/, '/knx/v1/orders/');
    }

    function buildDriverMessagesUrl() {
      const base = buildMsgApiBase().replace(/\/+$/, '') + '/';
      const afterId = driverChatLoadedOnce ? driverChatLastId : 0;
      const limit = 60;

      // cache-buster to defeat proxy/browser caching
      const ts = Date.now();

      return (
        base +
        orderId +
        '/messages?after_id=' +
        encodeURIComponent(String(afterId)) +
        '&limit=' +
        encodeURIComponent(String(limit)) +
        '&_ts=' +
        encodeURIComponent(String(ts))
      );
    }

    function initDriverChat(isTerminal) {
      chatIsTerminal = isTerminal === true;

      if (chatInitialized) {
        // If order became terminal after an update, lock input + stop polling
        if (chatIsTerminal) {
          const inp = document.getElementById('knxDriverChatInput');
          const btn = document.getElementById('knxDriverChatSend');
          if (inp) { inp.disabled = true; inp.placeholder = 'Order is closed.'; }
          if (btn) btn.disabled = true;
          stopDriverMsgPolling();
        }
        return;
      }

      chatInitialized = true;

      const rightPanel = contentEl.querySelector('.knx-ops-vo__right .knx-ops-vo__panel');
      if (!rightPanel) return;

      const panel = document.createElement('div');
      panel.id = 'knxDriverChatPanel';
      panel.className = 'knx-ops-vo__panel knx-ops-vo__chat';
      panel.innerHTML = `
        <div class="knx-ops-vo__chat-header">
          <span class="knx-ops-vo__chat-icon">💬</span>
          <span class="knx-ops-vo__chat-title">Chat with customer</span>
        </div>
        <div id="knxDriverChatMessages" class="knx-ops-vo__chat-messages"></div>
        <div class="knx-ops-vo__chat-footer">
          <textarea
            id="knxDriverChatInput"
            class="knx-ops-vo__chat-input"
            rows="1"
            maxlength="1000"
            ${chatIsTerminal ? 'disabled placeholder="Order is closed."' : 'placeholder="Type a message\u2026"'}
          ></textarea>
          <button id="knxDriverChatSend" class="knx-ops-vo__chat-send" ${chatIsTerminal ? 'disabled' : ''} aria-label="Send">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
          </button>
        </div>
      `;

      rightPanel.insertAdjacentElement('afterend', panel);

      const input = document.getElementById('knxDriverChatInput');
      if (input) {
        input.addEventListener('input', () => {
          input.style.height = 'auto';
          input.style.height = Math.min(input.scrollHeight, 120) + 'px';
        });
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendDriverMessage(); }
        });
      }

      const sendBtn = document.getElementById('knxDriverChatSend');
      if (sendBtn) sendBtn.addEventListener('click', sendDriverMessage);

      // Reset incremental state (first load window)
      driverChatLoadedOnce = false;
      driverChatLastId = 0;
      driverChatServerLastId = 0;
      driverChatBuffer = [];
      driverChatRendering = false;

      // Initial fetch + start polling
      fetchDriverMessages(true);
      if (!chatIsTerminal) startDriverMsgPolling();
    }

    function fetchDriverMessages(isFirst) {
      if (!orderId || chatIsTerminal) return;
      if (driverChatRendering) return; // avoid overlapping requests

      driverChatRendering = true;

      const url = buildDriverMessagesUrl();
      const headers = {};
      if (restNonce) headers['X-WP-Nonce'] = restNonce;

      // Extra no-cache hints (some proxies ignore fetch cache mode)
      headers['Cache-Control'] = 'no-cache';
      headers['Pragma'] = 'no-cache';

      fetch(url, {
        credentials: 'same-origin',
        headers,
        cache: 'no-store',
      })
        .then(r => r.json().catch(() => null))
        .then(data => {
          if (!data || !data.success) return;

          const srv = parseInt(data.server_last_id || '0', 10);
          if (Number.isFinite(srv) && srv >= 0) driverChatServerLastId = srv;

          const incoming = Array.isArray(data.messages) ? data.messages : [];

          if (!driverChatLoadedOnce) {
            // First load: window of last N messages (already ordered ASC by API)
            driverChatBuffer = incoming;
            driverChatLoadedOnce = true;
          } else if (incoming.length) {
            // Incremental append
            driverChatBuffer = driverChatBuffer.concat(incoming);
            if (driverChatBuffer.length > 120) {
              driverChatBuffer = driverChatBuffer.slice(driverChatBuffer.length - 120);
            }
          }

          // Advance cursor using server_last_id (robust even if concurrency)
          if (driverChatServerLastId > driverChatLastId) driverChatLastId = driverChatServerLastId;

          renderDriverMessages(driverChatBuffer);

          if (data.unread_count > 0) markDriverMessagesRead();
        })
        .catch(() => {})
        .finally(() => {
          driverChatRendering = false;
        });
    }

    function renderDriverMessages(messages) {
      const el = document.getElementById('knxDriverChatMessages');
      if (!el) return;

      if (!messages || !messages.length) {
        el.innerHTML = '<div class="knx-ops-vo__chat-empty">No messages yet.</div>';
        return;
      }

      const rows = messages.map(m => {
        const role = String(m.sender_role || '');

        if (role === 'system') {
          return `<div class="knx-ops-vo__chat-msg knx-ops-vo__chat-msg--system"><span>${esc(m.body)}</span></div>`;
        }

        const isMine = role === 'driver';
        const cls = `knx-ops-vo__chat-msg ${isMine ? 'knx-ops-vo__chat-msg--mine' : 'knx-ops-vo__chat-msg--theirs'}`;
        const label = isMine ? 'You' : '\u{1F464} Customer';
        const time = m.created_at ? formatMsgTime(m.created_at) : '';

        return `
          <div class="${cls}">
            <div class="knx-ops-vo__chat-bubble">
              <div class="knx-ops-vo__chat-meta">${esc(label)}${time ? ' · ' + esc(time) : ''}</div>
              <div class="knx-ops-vo__chat-text">${esc(m.body)}</div>
            </div>
          </div>`;
      }).join('');

      const prevHeight = el.scrollHeight;
      const wasAtBottom = el.scrollTop + el.clientHeight >= prevHeight - 10;

      el.innerHTML = rows;

      if (wasAtBottom) el.scrollTop = el.scrollHeight;
    }

    function sendDriverMessage() {
      const input = document.getElementById('knxDriverChatInput');
      const sendBtn = document.getElementById('knxDriverChatSend');
      if (!input) return;

      const body = input.value.trim();
      if (!body || chatIsTerminal) return;

      input.disabled = true;
      if (sendBtn) sendBtn.disabled = true;

      const base = buildMsgApiBase().replace(/\/+$/, '') + '/';
      const url = base + orderId + '/messages';

      const headers = { 'Content-Type': 'application/json' };
      if (restNonce) headers['X-WP-Nonce'] = restNonce;

      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify({ body }),
      })
        .then(r => r.json().catch(() => null))
        .then(data => {
          if (data && data.success) {
            input.value = '';
            input.style.height = 'auto';

            // Fast refresh after send (incremental)
            fetchDriverMessages(false);
          }
        })
        .catch(() => {})
        .finally(() => {
          input.disabled = chatIsTerminal;
          if (sendBtn) sendBtn.disabled = chatIsTerminal;
          if (!chatIsTerminal) input.focus();
        });
    }

    function markDriverMessagesRead() {
      if (!orderId) return;

      const base = buildMsgApiBase().replace(/\/+$/, '') + '/';
      const url = base + orderId + '/messages/read';

      const headers = {};
      if (restNonce) headers['X-WP-Nonce'] = restNonce;

      fetch(url, { method: 'POST', credentials: 'same-origin', headers }).catch(() => {});
    }

    function startDriverMsgPolling() {
      stopDriverMsgPolling();
      msgPollTimer = setInterval(() => {
        if (document.hidden) return;
        fetchDriverMessages(false);
      }, MSG_POLL_MS);
    }

    function stopDriverMsgPolling() {
      if (msgPollTimer) { clearInterval(msgPollTimer); msgPollTimer = null; }
    }

    function formatMsgTime(dateStr) {
      if (!dateStr) return '';
      try {
        const d = new Date(String(dateStr).replace(' ', 'T'));
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      } catch (e) { return ''; }
    }

    // Refresh chat immediately when returning to tab (no waiting for next interval)
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) return;
      if (!chatInitialized) return;
      if (chatIsTerminal) return;
      fetchDriverMessages(false);
    });

    fetchOrder();
  });
})();