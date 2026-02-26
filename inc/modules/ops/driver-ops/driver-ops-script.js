/**
 * ==========================================================
 * Kingdom Nexus — Driver OPS Dashboard Script (v1.1 SEALED)
 * ----------------------------------------------------------
 * - No console logs, no browser alerts
 * - No hardcoded REST paths (reads from dataset/config)
 * - Uses nonces for POST assign
 * - Canon modals + focus restore + ESC close
 * - Live refresh with pause when modal open / tab hidden
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var root = document.getElementById('knx-driver-ops-dashboard');
  if (!root) return;

  var cfg = (window.KNX_DRIVER_OPS_CONFIG || {});
  var apiAvailable = root.dataset.apiAvailable || cfg.apiAvailable || '';
  var apiBase = root.dataset.apiBase || cfg.apiBase || '';
  var knxNonce = root.dataset.knxNonce || cfg.knxNonce || '';
  var wpRestNonce = root.dataset.wpRestNonce || cfg.wpRestNonce || '';
  var pollMs = parseInt(cfg.pollMs, 10) || 15000;

  var activeOrdersUrl = root.dataset.activeOrdersUrl || cfg.activeOrdersUrl || '/driver-active-orders';
  var pastOrdersUrl = root.dataset.pastOrdersUrl || cfg.pastOrdersUrl || '';

  var listEl = document.getElementById('knxDriverOpsList');

  var searchEl = document.getElementById('knxDriverOpsSearch');
  var searchContainer = document.getElementById('knxSearchContainer');
  var searchToggleBtn = document.getElementById('knxDriverOpsSearchToggle');
  var liveEl = document.getElementById('knxDriverOpsLive');
  var refreshBtn = document.getElementById('knxDriverOpsRefresh');

  // Modals
  var modalOrder = document.getElementById('knxDriverOpsOrderModal');
  var modalConfirm = document.getElementById('knxDriverOpsConfirm');
  var modalMap = document.getElementById('knxDriverOpsMapModal');

  var modalOrderBody = document.getElementById('knxDriverOpsOrderBody');
  var modalOrderTitle = document.getElementById('knxDriverOpsOrderTitle');

  var modalMapEmbed = document.getElementById('knxDriverOpsMapEmbed');
  var modalMapTitle = document.getElementById('knxDriverOpsMapTitle');
  var modalMapPickup = document.getElementById('knxMapPickupAddress');
  var modalMapDelivery = document.getElementById('knxMapDeliveryAddress');

  var lastFocusEl = null;

  var state = {
    loading: false,
    orders: [],
    filtered: [],
    meta: null,
    selectedOrderId: null,
    pendingAcceptId: null,
    pollTimer: null,
    isModalOpen: false,
    aborter: null
  };

  function $(sel, el) { return (el || root).querySelector(sel); }
  function $all(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }

  // Toast: delegate to global knxToast (loaded by shortcode)
  function toast(message, type) {
    var msg = (message || '').toString().trim() || 'Something went wrong.';
    var t = (type || 'info').toString();
    if (typeof window.knxToast === 'function') {
      window.knxToast(msg, t);
    }
  }

  function escHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function debounce(fn, wait) {
    var t = null;
    return function () {
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(null, args); }, wait);
    };
  }

  function money(val) {
    var n = parseFloat(val);
    if (!isFinite(n)) return '$—';
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(n);
    } catch (e) {
      return '$' + n.toFixed(2);
    }
  }

  function modalOpen(modal) {
    if (!modal) return;
    lastFocusEl = document.activeElement;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    state.isModalOpen = true;

    setTimeout(function () {
      var focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (focusable) focusable.focus();
    }, 120);
  }

  function modalClose(modal) {
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    state.isModalOpen = false;

    if (lastFocusEl && typeof lastFocusEl.focus === 'function') {
      setTimeout(function () { try { lastFocusEl.focus(); } catch (e) {} }, 0);
    }
  }

  function wireModal(modal) {
    if (!modal) return;

    $all('.knx-modal-x', modal).forEach(function (btn) {
      btn.addEventListener('click', function () { modalClose(modal); });
    });

    $all('.knx-modal-cancel, .knx-confirm-cancel', modal).forEach(function (btn) {
      btn.addEventListener('click', function () { modalClose(modal); });
    });

    modal.addEventListener('click', function (e) {
      if (e.target === modal) modalClose(modal);
    });
  }

  wireModal(modalOrder);
  wireModal(modalConfirm);
  wireModal(modalMap);

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (modalConfirm && modalConfirm.classList.contains('active')) return modalClose(modalConfirm);
    if (modalMap && modalMap.classList.contains('active')) return modalClose(modalMap);
    if (modalOrder && modalOrder.classList.contains('active')) return modalClose(modalOrder);
  });

  function buildAssignUrl(orderId) {
    var id = parseInt(orderId, 10) || 0;
    if (!id || !apiBase) return '';
    return apiBase + id + '/assign';
  }

  async function fetchJson(url, opts) {
    if (!url) return { ok: false, status: 0, data: null };

    if (opts && opts._abortKey === 'list') {
      try { if (state.aborter) state.aborter.abort(); } catch (e) {}
      state.aborter = new AbortController();
      opts.signal = state.aborter.signal;
    }

    var res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}));

    var data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    return { ok: res.ok, status: res.status, data: data };
  }

  function applyFilter() {
    if (!searchEl) {
      state.filtered = state.orders.slice();
      return;
    }

    var q = (searchEl.value || '').trim().toLowerCase();
    if (!q) {
      state.filtered = state.orders.slice();
      return;
    }

    state.filtered = state.orders.filter(function (o) {
      var num = String(o.order_number || o.id || '').toLowerCase();
      var delivery = String(o.delivery_address_text || o.delivery_address || '').toLowerCase();
      var pickup = String(o.pickup_address_text || o.pickup_address || '').toLowerCase();
      var hub = String(o.hub_name || o.restaurant_name || '').toLowerCase();
      return num.indexOf(q) !== -1 || delivery.indexOf(q) !== -1 || pickup.indexOf(q) !== -1 || hub.indexOf(q) !== -1;
    });
  }

  function statusPill(status) {
    var s = String(status || '').toLowerCase();
    if (!s) s = 'unknown';
    var label = s.charAt(0).toUpperCase() + s.slice(1);
    var cls = 'knx-pill';
    if (s === 'confirmed' || s === 'ready') cls += ' is-good';
    if (s === 'placed' || s === 'preparing') cls += ' is-warn';
    if (s === 'out_for_delivery') cls += ' is-info';
    return '<span class="' + cls + '">' + escHtml(label) + '</span>';
  }

  function payPill(paymentStatus, method) {
    var ps = String(paymentStatus || '').toLowerCase();
    var pm = String(method || '').toLowerCase();

    var cls = 'knx-pill is-muted';
    var label = 'Payment';
    if (ps === 'paid') { cls = 'knx-pill is-good'; label = 'Paid'; }
    else if (ps === 'pending') { cls = 'knx-pill is-warn'; label = 'Pending'; }

    if (pm) label += ' • ' + pm.toUpperCase();
    return '<span class="' + cls + '">' + escHtml(label) + '</span>';
  }

  function renderList() {
    if (!listEl) return;

    if (state.loading) {
      listEl.innerHTML = '<div class="knx-empty">Loading available orders…</div>';
      return;
    }

    if (!state.filtered.length) {
      listEl.innerHTML = '<div class="knx-empty">No available orders right now.</div>';
      return;
    }

    var html = state.filtered.map(function (o) {
      var id = parseInt(o && (o.id || o.order_id), 10) || 0;
      var restaurant = (o && (o.hub_name || o.restaurant_name || o.restaurant)) ? String(o.hub_name || o.restaurant_name || o.restaurant) : '';

      var pickup = (o && (o.pickup_address_text || o.pickup_address || o.pickup || o.pickup_address_line)) ? String(o.pickup_address_text || o.pickup_address || o.pickup || o.pickup_address_line) : '';
      var delivery = (o && (o.delivery_address_text || o.delivery_address || o.delivery || o.dropoff_address)) ? String(o.delivery_address_text || o.delivery_address || o.delivery || o.dropoff_address) : '';

      var isPickup = String(o && o.fulfillment_type ? o.fulfillment_type : '').toLowerCase() === 'pickup';

      var totalVal = (o && (o.total_amount || o.total || o.amount)) ? (o.total_amount || o.total || o.amount) : null;
      var tipVal = (o && (o.tip_amount || o.tip)) ? (o.tip_amount || o.tip) : null;

      var total = money(totalVal);
      var tip = money(tipVal);

      var distance = '';
      if (!isPickup && o && (o.distance_miles || o.distance || o.distance_mi)) {
        var d = o.distance_miles || o.distance || o.distance_mi;
        var n = parseFloat(d);
        if (isFinite(n)) distance = (Math.round(n * 10) / 10) + ' mi';
      }

      var addressBlock = isPickup
        ? '<div class="knx-pickup-chip" style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:0.9rem;color:#166534;">' +
            '<span aria-hidden="true">🛍️</span>' +
            '<span><strong>Customer Pickup</strong> — The customer will pick up at the restaurant. No delivery needed.</span>' +
          '</div>'
        : '<div class="knx-address-block">' +
            '<div class="knx-address-row">' +
              '<div class="knx-address-header"><span class="knx-addr-icon" aria-hidden="true">📍</span><span class="knx-address-label">PICKUP</span></div>' +
              '<div class="knx-address-text">' + escHtml(pickup || '—') + '</div>' +
            '</div>' +
            '<div class="knx-address-row">' +
              '<div class="knx-address-header"><span class="knx-addr-icon" aria-hidden="true">📦</span><span class="knx-address-label">DELIVERY</span></div>' +
              '<div class="knx-address-text">' + escHtml(delivery || '—') + '</div>' +
            '</div>' +
          '</div>';

      return (
        '<div class="knx-order-card" data-order-id="' + id + '" data-id="' + id + '">' +
          '<div class="knx-order-header-inline">' +
            '<span class="knx-restaurant-name">' + escHtml(restaurant || '—') + '</span>' +
            '<span class="knx-order-id">#' + String(id) + '</span>' +
          '</div>' +

          addressBlock +

          '<div class="knx-money-distance" style="margin-top:12px;">' +
            '<div class="knx-money-group">' +
              '<div class="knx-money-item"><div class="label">Total</div><div class="amount">' + escHtml(total) + '</div></div>' +
              '<div class="knx-money-item"><div class="label">Tips</div><div class="amount">' + escHtml(tip) + '</div></div>' +
            '</div>' +
            (distance ? '<div class="knx-distance">' + escHtml(distance) + '</div>' : '') +
          '</div>' +

          '<div class="knx-order-actions">' +
            '<button type="button" class="knx-btn knx-order-accept" data-id="' + id + '">Accept</button>' +
            (isPickup ? '' : '<button type="button" class="knx-btn-secondary knx-order-view" data-id="' + id + '">See Map</button>') +
          '</div>' +
        '</div>'
      );
    }).join('');

    listEl.innerHTML = html;

    $all('.knx-order-view', listEl).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        openMapModal(id);
      });
    });

    $all('.knx-order-accept', listEl).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        doAssign(id);
      });
    });
  }

  function openMapModal(id) {
    if (!modalMap || !modalMapEmbed || !modalMapTitle) return;

    var o = state.orders.find(function (x) { return parseInt(x.id, 10) === id; });
    if (!o) return;

    state.selectedOrderId = id;

    var orderNum = o.order_number || ('#' + id);
    modalMapTitle.textContent = 'Order ' + orderNum;

    var pickupAddress = o.pickup_address_text || o.pickup_address || '—';
    var deliveryAddress = o.delivery_address_text || o.delivery_address || '—';

    if (modalMapPickup) modalMapPickup.textContent = pickupAddress;
    if (modalMapDelivery) modalMapDelivery.textContent = deliveryAddress;

    // Coordinates - focus on PICKUP (restaurant) for drivers
    var pickupLat = parseFloat(o.pickup_lat);
    var pickupLng = parseFloat(o.pickup_lng);

    var hasPickup = isFinite(pickupLat) && isFinite(pickupLng);

    // Google Maps embed showing PICKUP location (restaurant)
    var embedHtml = '';
    if (hasPickup) {
      var mapUrl = 'https://www.google.com/maps?q=' + 
        encodeURIComponent(pickupLat + ',' + pickupLng) + 
        '&z=15&output=embed';
      embedHtml = '<iframe src="' + escHtml(mapUrl) + '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>';
    } else {
      embedHtml = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--nxs-muted);font-weight:700;font-size:14px;">No location available</div>';
    }

    modalMapEmbed.innerHTML = embedHtml;

    // Setup navigate button to restaurant
    var navBtn = $('.knx-map-navigate', modalMap);
    if (navBtn) {
      if (hasPickup) {
        navBtn.style.display = '';
        navBtn.onclick = function () {
          var navUrl = 'https://www.google.com/maps/dir/?api=1&destination=' + 
            encodeURIComponent(pickupLat + ',' + pickupLng) + '&travelmode=driving';
          
          var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
          if (isIOS) {
            var appleMapsUrl = 'maps://maps.apple.com/?daddr=' + 
              encodeURIComponent(pickupLat + ',' + pickupLng) + '&dirflg=d';
            window.location.href = appleMapsUrl;
            setTimeout(function () { window.open(navUrl, '_blank'); }, 500);
          } else {
            window.open(navUrl, '_blank');
          }
        };
      } else {
        navBtn.style.display = 'none';
      }
    }

    modalOpen(modalMap);
  }

  function openNativeNavigation(pickupLat, pickupLng, deliveryLat, deliveryLng) {
    var hasPickup = isFinite(pickupLat) && isFinite(pickupLng);
    var hasDelivery = isFinite(deliveryLat) && isFinite(deliveryLng);

    if (!hasPickup || !hasDelivery) {
      toast('Coordinates not available', 'error');
      return;
    }

    // Build navigation URL (works for iOS, Android, web browsers)
    var origin = pickupLat + ',' + pickupLng;
    var destination = deliveryLat + ',' + deliveryLng;

    // Universal navigation URL (Google Maps)
    var navUrl = 'https://www.google.com/maps/dir/?api=1' +
      '&origin=' + encodeURIComponent(origin) +
      '&destination=' + encodeURIComponent(destination) +
      '&travelmode=driving';

    // For iOS devices, try Apple Maps first
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    if (isIOS) {
      var appleMapsUrl = 'maps://maps.apple.com/?saddr=' + encodeURIComponent(origin) +
        '&daddr=' + encodeURIComponent(destination) +
        '&dirflg=d';
      window.location.href = appleMapsUrl;
      
      // Fallback to Google Maps after delay
      setTimeout(function () {
        window.open(navUrl, '_blank');
      }, 500);
    } else {
      window.open(navUrl, '_blank');
    }
  }

  function openOrderModal(id) {
    if (!modalOrder || !modalOrderBody || !modalOrderTitle) return;

    var o = state.orders.find(function (x) { return parseInt(x.id, 10) === id; });
    if (!o) return;

    state.selectedOrderId = id;
    modalOrderTitle.textContent = o.order_number ? ('Order ' + o.order_number) : ('Order #' + id);

    var body = '';
    body += '<div class="knx-modal-grid">';
    body +=   '<div class="knx-modal-row"><div class="knx-k">Status</div><div class="knx-v">' + statusPill(o.status) + '</div></div>';
    body +=   '<div class="knx-modal-row"><div class="knx-k">Payment</div><div class="knx-v">' + payPill(o.payment_status, o.payment_method) + '</div></div>';
    body +=   '<div class="knx-modal-row"><div class="knx-k">Total</div><div class="knx-v"><strong>' + escHtml(money(o.total)) + '</strong></div></div>';
    body +=   '<div class="knx-modal-row"><div class="knx-k">Address</div><div class="knx-v">' + escHtml(o.delivery_address || '—') + '</div></div>';
    body += '</div>';

    body += '<div class="knx-breakdown">';
    body +=   '<div class="knx-break-row"><span>Subtotal</span><span>' + escHtml(money(o.subtotal)) + '</span></div>';
    body +=   '<div class="knx-break-row"><span>Tax</span><span>' + escHtml(money(o.tax_amount)) + '</span></div>';
    body +=   '<div class="knx-break-row"><span>Delivery fee</span><span>' + escHtml(money(o.delivery_fee)) + '</span></div>';
    body +=   '<div class="knx-break-row"><span>Software fee</span><span>' + escHtml(money(o.software_fee)) + '</span></div>';
    body +=   '<div class="knx-break-row"><span>Tip</span><span>' + escHtml(money(o.tip_amount)) + '</span></div>';
    body +=   '<div class="knx-break-row"><span>Discount</span><span>' + escHtml(money(o.discount_amount)) + '</span></div>';
    body += '</div>';

    modalOrderBody.innerHTML = body;

    var acceptBtn = $('.knx-modal-accept', modalOrder);
    if (acceptBtn) {
      acceptBtn.disabled = false;
      acceptBtn.textContent = 'Accept Order';
      acceptBtn.onclick = function () { doAssign(id); };
    }

    modalOpen(modalOrder);
  }

  async function doAssign(orderId) {
    var id = parseInt(orderId, 10) || 0;
    if (!id) return;

    var url = buildAssignUrl(id);
    if (!url) {
      toast('Assign endpoint missing.', 'error');
      return;
    }

    var listBtn = listEl ? listEl.querySelector('.knx-order-accept[data-id="' + id + '"]') : null;
    if (listBtn) {
      listBtn.disabled = true;
      listBtn.textContent = 'Assigning…';
    }

    var out = await fetchJson(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpRestNonce || ''
      },
      body: JSON.stringify({ knx_nonce: knxNonce || '' })
    });

    var json = out.data;

    if (!out.ok || !json || json.success !== true) {
      var reason = (json && json.data && (json.data.reason || json.data.message)) ? (json.data.reason || json.data.message) : 'Assign failed.';
      toast(reason, 'error');

      if (listBtn) {
        listBtn.disabled = false;
        listBtn.textContent = 'Accept';
      }
      return;
    }

    toast('Order assigned.', 'success');

    state.orders = state.orders.filter(function (o) { return parseInt(o.id, 10) !== id; });
    applyFilter();
    renderList();

    if (modalOrder && modalOrder.classList.contains('active')) modalClose(modalOrder);

    // Redirect after accept (canonical)
    setTimeout(function () {
      var to = (activeOrdersUrl || '/driver-active-orders').toString().trim();
      if (to) window.location.href = to;
    }, 650);
  }

  async function loadOrders() {
    if (!apiAvailable) {
      toast('Available orders endpoint missing.', 'error');
      return;
    }

    state.loading = true;
    renderList();

    var out = await fetchJson(apiAvailable, { method: 'GET', _abortKey: 'list' });
    var json = out.data;

    state.loading = false;

    if (!out.ok || !json || json.success !== true) {
      if (listEl) listEl.innerHTML = '<div class="knx-empty">Unable to load orders.</div>';
      return;
    }

    var data = json.data || {};
    state.orders = Array.isArray(data.orders) ? data.orders : [];
    state.meta = data.meta || null;

    applyFilter();
    renderList();
  }

  function startPolling() {
    stopPolling();
    if (!liveEl || !liveEl.checked) return;

    state.pollTimer = setInterval(function () {
      if (document.hidden) return;
      if (state.isModalOpen) return;
      loadOrders();
    }, pollMs);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  // Events (fail-safe)
  if (searchToggleBtn && searchContainer && searchEl) {
    searchToggleBtn.addEventListener('click', function () {
      if (searchContainer.style.display === 'none') {
        searchContainer.style.display = 'block';
        setTimeout(function () { searchEl.focus(); }, 50);
      } else {
        searchContainer.style.display = 'none';
        searchEl.value = '';
        applyFilter();
        renderList();
      }
    });

    searchEl.addEventListener('input', debounce(function () {
      applyFilter();
      renderList();
    }, 180));
  }

  if (liveEl) {
    liveEl.addEventListener('change', function () { startPolling(); });
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () { loadOrders(); });
  }

  var pastBtn = document.getElementById('knxViewPastOrders');
  if (pastBtn) {
    pastBtn.addEventListener('click', function () {
      var url = (pastOrdersUrl || '').toString().trim();
      if (url) { window.location.href = url; return; }
      toast('View past orders not available here.');
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) return;
    if (liveEl && liveEl.checked && !state.isModalOpen) loadOrders();
  });

  // Init
  loadOrders();
  startPolling();
});