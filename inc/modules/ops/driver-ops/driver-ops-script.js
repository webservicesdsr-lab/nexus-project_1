/**
 * ==========================================================
 * Kingdom Nexus — Driver OPS Dashboard Script (v1.0)
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

  // Page URLs (not REST) — configurable; fallback is safe.
  var activeOrderUrl = root.dataset.activeOrderUrl || cfg.activeOrderUrl || '/driver-active-orders';

  var listEl = document.getElementById('knxDriverOpsList');
  var metaEl = document.getElementById('knxDriverOpsMetaText');

  var searchEl = document.getElementById('knxDriverOpsSearch');
  var searchContainer = document.getElementById('knxSearchContainer');
  var searchToggleBtn = document.getElementById('knxDriverOpsSearchToggle');
  var liveEl = document.getElementById('knxDriverOpsLive');
  var refreshBtn = document.getElementById('knxDriverOpsRefresh');

  // Modals
  var modalOrder = document.getElementById('knxDriverOpsOrderModal');
  var modalConfirm = document.getElementById('knxDriverOpsConfirm');

  var modalOrderBody = document.getElementById('knxDriverOpsOrderBody');
  var modalOrderTitle = document.getElementById('knxDriverOpsOrderTitle');

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

  function parseMysqlToDate(mysql) {
    // Parse "YYYY-MM-DD HH:MM:SS" as UTC (best-effort)
    if (!mysql || typeof mysql !== 'string') return null;
    var parts = mysql.split(' ');
    if (parts.length !== 2) return null;
    var d = parts[0].split('-').map(Number);
    var t = parts[1].split(':').map(Number);
    if (d.length !== 3 || t.length < 2) return null;
    return new Date(Date.UTC(d[0], d[1] - 1, d[2], t[0] || 0, t[1] || 0, t[2] || 0));
  }

  function relTime(mysql) {
    var dt = parseMysqlToDate(mysql);
    if (!dt) return '';
    var diff = Date.now() - dt.getTime();
    if (diff < 0) diff = 0;
    var sec = Math.floor(diff / 1000);
    if (sec < 60) return sec + 's ago';
    var min = Math.floor(sec / 60);
    if (min < 60) return min + 'm ago';
    var hr = Math.floor(min / 60);
    if (hr < 24) return hr + 'h ago';
    var day = Math.floor(hr / 24);
    return day + 'd ago';
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

    // Focus first relevant control
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

    // Close X
    $all('.knx-modal-x', modal).forEach(function (btn) {
      btn.addEventListener('click', function () { modalClose(modal); });
    });

    // Cancel buttons
    $all('.knx-modal-cancel, .knx-confirm-cancel', modal).forEach(function (btn) {
      btn.addEventListener('click', function () { modalClose(modal); });
    });

    // Overlay click
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modalClose(modal);
    });
  }

  wireModal(modalOrder);
  wireModal(modalConfirm);

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (modalConfirm && modalConfirm.classList.contains('active')) return modalClose(modalConfirm);
    if (modalOrder && modalOrder.classList.contains('active')) return modalClose(modalOrder);
  });

  function normalizeUrlBase(u) {
    var s = (u || '').toString().trim();
    if (!s) return '';
    // Ensure trailing slash
    if (s.charAt(s.length - 1) !== '/') s += '/';
    return s;
  }

  function isBadAssignBase(u) {
    var s = (u || '').toString().toLowerCase();
    // Driver UI must never call ops/manager assign endpoints
    if (s.indexOf('/ops/') !== -1) return true;
    if (s.indexOf('assign-driver') !== -1) return true;
    if (s.indexOf('/manager/') !== -1) return true;
    return false;
  }

  function buildAssignUrl(orderId) {
    var id = parseInt(orderId, 10) || 0;
    if (!id) return '';

    var base = normalizeUrlBase(apiBase);
    if (!base) return '';

    // Fail-closed if apiBase looks like an OPS/manager endpoint base
    if (isBadAssignBase(base)) return '';

    // apiBase is expected to end with "/.../driver/orders/"
    return base + id + '/assign';
  }

  function buildActiveOrderRedirectUrl(orderId) {
    var id = parseInt(orderId, 10) || 0;
    if (!id) return '';

    var base = (activeOrderUrl || '/driver-active-orders').toString().trim();
    if (!base) base = '/driver-active-orders';

    // Preserve existing query if any
    var sep = base.indexOf('?') === -1 ? '?' : '&';
    return base + sep + 'order_id=' + encodeURIComponent(String(id)) + '&from=ops';
  }

  async function fetchJson(url, opts) {
    if (!url) return { ok: false, status: 0, data: null };

    // Abort previous request (list only)
    if (opts && opts._abortKey === 'list') {
      try { if (state.aborter) state.aborter.abort(); } catch (e) {}
      state.aborter = new AbortController();
      opts.signal = state.aborter.signal;
    }

    var res = await fetch(url, Object.assign({
      credentials: 'same-origin'
    }, opts || {}));

    var data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    return { ok: res.ok, status: res.status, data: data };
  }

  function applyFilter() {
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
      var ord = (o && (o.order_number || o.id)) ? String(o.order_number || o.id) : ('#' + id);
      var orderLabel = ord ? ('Order ' + escHtml(ord)) : ('Order #' + id);

      var restaurant = (o && (o.hub_name || o.restaurant_name || o.restaurant)) ? String(o.hub_name || o.restaurant_name || o.restaurant) : '';

      var pickup = (o && (o.pickup_address_text || o.pickup_address || o.pickup || o.pickup_address_line)) ? String(o.pickup_address_text || o.pickup_address || o.pickup || o.pickup_address_line) : '';
      var delivery = (o && (o.delivery_address_text || o.delivery_address || o.delivery || o.dropoff_address)) ? String(o.delivery_address_text || o.delivery_address || o.delivery || o.dropoff_address) : '';

      var totalVal = (o && (o.total_amount || o.total || o.amount)) ? (o.total_amount || o.total || o.amount) : null;
      var tipVal = (o && (o.tip_amount || o.tip || o.tip_amount)) ? (o.tip_amount || o.tip || o.tip_amount) : null;
      var total = money(totalVal);
      var tip = money(tipVal);

      var distance = null;
      if (o && (o.distance_miles || o.distance || o.distance_mi)) {
        var d = o.distance_miles || o.distance || o.distance_mi;
        var n = parseFloat(d);
        if (isFinite(n)) distance = (Math.round(n * 10) / 10) + ' mi';
      }

      // 'is_new' is authoritative; do not infer from timestamps here (pure renderer)
      var isNew = !!(o && o.is_new === true);

      return (
        '<div class="knx-order-card" data-order-id="' + id + '" data-id="' + id + '">' +
            '<div class="knx-order-header-inline">' +
              '<span class="knx-restaurant-name">' + escHtml(restaurant || '—') + '</span>' +
              '<span class="knx-order-id">#' + String(id) + '</span>' +
            '</div>' +

            '<div class="knx-address-block">' +
              '<div class="knx-address-row">' +
                '<div class="knx-address-header"><span class="knx-addr-icon" aria-hidden="true">📍</span><span class="knx-address-label">PICKUP</span></div>' +
                '<div class="knx-address-text">' + escHtml(pickup || '—') + '</div>' +
              '</div>' +
              '<div class="knx-address-row">' +
                '<div class="knx-address-header"><span class="knx-addr-icon" aria-hidden="true">📦</span><span class="knx-address-label">DELIVERY</span></div>' +
                '<div class="knx-address-text">' + escHtml(delivery || '—') + '</div>' +
              '</div>' +
            '</div>' +

            '<div class="knx-money-distance" style="margin-top:12px;">' +
              '<div class="knx-money-group">' +
                '<div class="knx-money-item"><div class="label">Total</div><div class="amount">' + escHtml(total) + '</div></div>' +
                '<div class="knx-money-item"><div class="label">Tips</div><div class="amount">' + escHtml(tip) + '</div></div>' +
              '</div>' +
              '<div class="knx-distance">' + escHtml(distance || '') + '</div>' +
            '</div>' +

          '<div class="knx-order-actions">' +
            '<button type="button" class="knx-btn knx-order-accept" data-id="' + id + '">Accept</button>' +
            '<button type="button" class="knx-btn-secondary knx-order-view" data-id="' + id + '">See Map</button>' +
          '</div>' +
        '</div>'
      );
    }).join('');

    listEl.innerHTML = html;

    // Wire actions
    $all('.knx-order-view', listEl).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        openOrderModal(id);
      });
    });

    $all('.knx-order-accept', listEl).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        // Direct assignment - no confirmation modal (drivers need speed)
        doAssign(id);
      });
    });
  }

  // Update header new-count badge (if present)
  function updateNewBadge() {
    var badge = document.getElementById('knxDriverNewBadge');
    if (!badge) return;
    var NEW_MS = 1000 * 60 * 15;
    var now = Date.now();
    var newCount = state.orders.filter(function (o) {
      var c = parseMysqlToDate(o.created_at);
      return c ? ((now - c.getTime()) < NEW_MS) : false;
    }).length;
    if (newCount > 0) {
      badge.style.display = '';
      badge.textContent = String(newCount) + ' New';
    } else {
      badge.style.display = 'none';
      badge.textContent = '';
    }
  }

  function openOrderModal(id) {
    var o = state.orders.find(function (x) { return parseInt(x.id, 10) === id; });
    if (!o) return;

    state.selectedOrderId = id;
    modalOrderTitle.textContent = o.order_number ? ('Order ' + o.order_number) : ('Order #' + id);

    var body = '';
    body += '<div class="knx-modal-grid">';
    body +=   '<div class="knx-modal-row"><div class="knx-k">Status</div><div class="knx-v">' + statusPill(o.status) + '</div></div>';
    body +=   '<div class="knx-modal-row"><div class="knx-k">Payment</div><div class="knx-v">' + payPill(o.payment_status, o.payment_method) + '</div></div>';
    body +=   '<div class="knx-modal-row"><div class="knx-k">Total</div><div class="knx-v"><strong>' + escHtml(money(o.total)) + '</strong></div></div>';
    body +=   '<div class="knx-modal-row"><div class="knx-k">Address</div><div class="knx-v">' + escHtml(o.delivery_address_text || o.delivery_address || '—') + '</div></div>';
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

    // Hook accept button in modal - direct assignment (no confirmation)
    var acceptBtn = $('.knx-modal-accept', modalOrder);
    if (acceptBtn) {
      acceptBtn.disabled = false;
      acceptBtn.textContent = 'Accept Order';
      acceptBtn.onclick = function () {
        doAssign(id);
      };
    }

    modalOpen(modalOrder);
  }

  async function doAssign(orderId) {
    var id = parseInt(orderId, 10) || 0;
    if (!id) return;

    var url = buildAssignUrl(id);
    if (!url) {
      toast('Assign endpoint misconfigured for driver.', 'error');
      return;
    }

    // Disable list button during assignment
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

      // Re-enable button on failure
      if (listBtn) {
        listBtn.disabled = false;
        listBtn.textContent = 'Accept';
      }
      return;
    }

    toast('Order assigned.', 'success');

    // Remove from state and UI
    state.orders = state.orders.filter(function (o) { return parseInt(o.id, 10) !== id; });
    applyFilter();
    renderList();
    updateNewBadge();

    // Close modal if open
    if (modalOrder && modalOrder.classList.contains('active')) modalClose(modalOrder);

    // Redirect after successful accept to active order detail.
    // Prefer API returned id, but fall back to the local id (known accepted order).
    var returnedOrderId = 0;
    if (json && json.data) {
      if (typeof json.data.order_id !== 'undefined') returnedOrderId = parseInt(json.data.order_id, 10) || 0;
      else if (typeof json.data.orderId !== 'undefined') returnedOrderId = parseInt(json.data.orderId, 10) || 0;
      else if (typeof json.data.id !== 'undefined') returnedOrderId = parseInt(json.data.id, 10) || 0;
      else if (json.data.order && typeof json.data.order.id !== 'undefined') returnedOrderId = parseInt(json.data.order.id, 10) || 0;
    }
    if (returnedOrderId <= 0) returnedOrderId = id;

    var redirectUrl = buildActiveOrderRedirectUrl(returnedOrderId);
    if (redirectUrl) {
      window.location.href = redirectUrl;
      return;
    }
  }

  async function loadOrders(opts) {
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
      listEl.innerHTML = '<div class="knx-empty">Unable to load orders.</div>';
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
      loadOrders({ silent: true });
    }, pollMs);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  // Events
  if (searchToggleBtn) {
    searchToggleBtn.addEventListener('click', function () {
      if (searchContainer.style.display === 'none') {
        searchContainer.style.display = 'block';
        setTimeout(function() { searchEl.focus(); }, 50);
      } else {
        searchContainer.style.display = 'none';
        searchEl.value = '';
        applyFilter();
        renderList();
      }
    });
  }

  searchEl.addEventListener('input', debounce(function () {
    applyFilter();
    renderList();
  }, 180));

  liveEl.addEventListener('change', function () {
    startPolling();
  });

  refreshBtn.addEventListener('click', function () {
    loadOrders();
  });

  var pastBtn = document.getElementById('knxViewPastOrders');
  if (pastBtn) {
    pastBtn.addEventListener('click', function () {
      var url = root.dataset.pastUrl || cfg.pastUrl || '';
      if (url) return window.location.href = url;
      toast('View past orders not available here.');
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) return;
    if (liveEl && liveEl.checked && !state.isModalOpen) loadOrders({ silent: true });
  });

  // Init
  (function init() {
    loadOrders();
    startPolling();
  })();
});
