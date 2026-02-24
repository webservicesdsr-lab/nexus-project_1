/**
 * ==========================================================
 * Kingdom Nexus — Driver Active Orders Script (BRIDGE)
 * ----------------------------------------------------------
 * - No alerts
 * - Renders Nexus-shell cards similar to driver-ops
 * - Entire card clickable -> /driver-view-order?order_id=...
 * - Uses DB-canon status only (confirmed => "Order Created" UI label)
 * - Live polling (pause when tab hidden)
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var root = document.getElementById('knx-driver-active-orders');
  if (!root) return;

  var cfg = (window.KNX_DRIVER_ACTIVE_CONFIG || {});
  var apiActive = root.dataset.apiActive || cfg.apiActive || '';
  var viewOrderUrl = root.dataset.viewOrderUrl || cfg.viewOrderUrl || '/driver-view-order';
  var wpRestNonce = root.dataset.wpRestNonce || cfg.wpRestNonce || '';
  var pollMs = parseInt(root.dataset.pollMs || cfg.pollMs, 10) || 15000;

  var listEl = document.getElementById('knxActiveOrdersList');
  var searchEl = document.getElementById('knxActiveSearch');
  var refreshBtn = document.getElementById('knxActiveRefresh');
  var liveEl = document.getElementById('knxActiveLive');
  var archiveBtn = null;

  var state = {
    loading: false,
    orders: [],
    filtered: [],
    pollTimer: null,
    aborter: null,
    showArchived: false
  };

  function toast(message, type) {
    var msg = (message || '').toString().trim() || 'Something went wrong.';
    var t = (type || 'info').toString();
    if (typeof window.knxToast === 'function') window.knxToast(msg, t);
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

  function normalizeStatus(s) {
    var st = String(s || '').trim().toLowerCase();
    // tolerate legacy aliases safely (UI only)
    if (st === 'placed') return 'confirmed';
    if (st === 'accepted_by_restaurant') return 'accepted_by_hub';
    if (st === 'out_for_delivery') return 'picked_up';
    if (st === 'ready') return 'prepared';
    return st;
  }

  // Canonical status labels (same across ALL modules)
  var STATUS_LABELS = {
    pending_payment: 'Pending Payment',
    confirmed: 'Order Created',
    accepted_by_driver: 'Accepted by Driver',
    accepted_by_hub: 'Accepted by Restaurant',
    preparing: 'Preparing',
    prepared: 'Prepared',
    picked_up: 'Picked Up',
    completed: 'Completed',
    cancelled: 'Cancelled'
  };

  function statusLabel(st) {
    var s = normalizeStatus(st);
    return STATUS_LABELS[s] || s.replace(/_/g, ' ');
  }

  function statusChipClass(st) {
    var s = normalizeStatus(st);
    if (s === 'confirmed') return 'is-new';
    if (s === 'cancelled') return 'is-cancelled';
    if (s === 'completed') return 'is-done';
    return 'is-progress';
  }

  function buildViewUrl(orderId) {
    var base = (viewOrderUrl || '/driver-view-order').toString().trim();
    var sep = base.indexOf('?') === -1 ? '?' : '&';
    return base + sep + 'order_id=' + encodeURIComponent(String(orderId));
  }

  function parseMysqlToDate(mysql) {
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
    var q = (searchEl && searchEl.value ? searchEl.value : '').trim().toLowerCase();
    if (!q) {
      state.filtered = state.orders.slice();
      return;
    }

    state.filtered = state.orders.filter(function (o) {
      var id = String(o.id || o.order_id || '').toLowerCase();
      var num = String(o.order_number || '').toLowerCase();
      var restaurant = String(pickRestaurantName(o) || '').toLowerCase();
      var customer = String(o.customer_name || o.customer || '').toLowerCase();
      var delivery = String(o.delivery_address_text || o.delivery_address || '').toLowerCase();
      return (
        id.indexOf(q) !== -1 ||
        num.indexOf(q) !== -1 ||
        restaurant.indexOf(q) !== -1 ||
        customer.indexOf(q) !== -1 ||
        delivery.indexOf(q) !== -1
      );
    });
  }

  function pickRestaurantName(o) {
    if (!o) return '';
    // direct string fields
    if (o.hub_name) return String(o.hub_name);
    if (o.restaurant_name) return String(o.restaurant_name);
    if (o.store_name) return String(o.store_name);
    if (o.merchant_name) return String(o.merchant_name);
    if (o.restaurant && typeof o.restaurant === 'string') return String(o.restaurant);

    // nested object shapes
    if (o.hub && typeof o.hub === 'object') {
      if (o.hub.name) return String(o.hub.name);
      if (o.hub.title) return String(o.hub.title);
    }

    if (o.restaurant && typeof o.restaurant === 'object') {
      if (o.restaurant.name) return String(o.restaurant.name);
      if (o.restaurant.title) return String(o.restaurant.title);
    }

    if (o.merchant && typeof o.merchant === 'object') {
      if (o.merchant.name) return String(o.merchant.name);
      if (o.merchant.title) return String(o.merchant.title);
      if (o.merchant.business_name) return String(o.merchant.business_name);
    }

    if (o.store && typeof o.store === 'object') {
      if (o.store.name) return String(o.store.name);
      if (o.store.title) return String(o.store.title);
    }

    return '';
  }

  function pickPickup(o) {
    return (o && (o.pickup_address_text || o.pickup_address || o.pickup || o.pickup_address_line)) ? String(o.pickup_address_text || o.pickup_address || o.pickup || o.pickup_address_line) : '';
  }

  function pickDelivery(o) {
    return (o && (o.delivery_address_text || o.delivery_address || o.delivery || o.dropoff_address)) ? String(o.delivery_address_text || o.delivery_address || o.delivery || o.dropoff_address) : '';
  }

  function pickCustomerName(o) {
    if (!o) return '';
    if (o.customer_name) return String(o.customer_name);
    if (o.customer) return String(o.customer);
    if (o.name) return String(o.name);
    return '';
  }

  function renderList() {
    if (!listEl) return;

    if (state.loading) {
      listEl.innerHTML = '<div class="knx-empty">Loading your active orders…</div>';
      return;
    }

    if (!state.filtered.length) {
      listEl.innerHTML = '<div class="knx-empty">No active orders right now.</div>';
      return;
    }

    var html = state.filtered.map(function (o) {
      var id = parseInt(o && (o.id || o.order_id), 10) || 0;
      var restaurant = pickRestaurantName(o);
      var customer = pickCustomerName(o);
      var pickup = pickPickup(o);
      var delivery = pickDelivery(o);

      var st = normalizeStatus(o && o.status ? o.status : '');
      var stLabel = statusLabel(st);
      var stClass = statusChipClass(st);

      var totalVal = (o && (o.total_amount || o.total || o.amount)) ? (o.total_amount || o.total || o.amount) : null;
      var tipVal = (o && (o.tip_amount || o.tip)) ? (o.tip_amount || o.tip) : null;

      var total = money(totalVal);
      var tip = money(tipVal);

      var timeRaw = (o && (o.assigned_at || o.updated_at || o.created_at)) ? (o.assigned_at || o.updated_at || o.created_at) : '';
      var ago = relTime(timeRaw);

      var viewUrl = buildViewUrl(id);

      return (
        '<a class="knx-aocard" href="' + escHtml(viewUrl) + '" data-id="' + id + '" aria-label="View order ' + id + '">' +
          '<div class="knx-aocard__head">' +
            '<div class="knx-aocard__idwrap">' +
              '<div class="knx-aocard__idpill">#' + escHtml(String(id)) + '</div>' +
            '</div>' +

            '<div class="knx-aocard__titlewrap">' +
              '<div class="knx-aocard__restaurant">' + escHtml(restaurant || '—') + '</div>' +
              '<div class="knx-aocard__sub">' + escHtml(customer || '') + '</div>' +
              '<div class="knx-aocard__sub">' +
                (ago ? '<span class="knx-aocard__time">' + escHtml(ago) + '</span>' : '<span class="knx-aocard__time"> </span>') +
              '</div>' +
            '</div>' +

            '<div class="knx-aocard__right">' +
              '<div class="knx-aocard__chip ' + escHtml(stClass) + '">' + escHtml(stLabel) + '</div>' +
              '<div class="knx-aocard__total">' + escHtml(total) + '</div>' +
            '</div>' +
          '</div>' +

          '<div class="knx-aocard__addresses">' +
            '<div class="knx-aocard__addr">' +
              '<div class="knx-aocard__k"><span class="knx-addr-icon" aria-hidden="true">📍</span> PICKUP</div>' +
              '<div class="knx-aocard__v">' + escHtml(pickup || restaurant || '—') + '</div>' +
            '</div>' +
            '<div class="knx-aocard__addr">' +
              '<div class="knx-aocard__k"><span class="knx-addr-icon" aria-hidden="true">📦</span> DELIVERY</div>' +
              '<div class="knx-aocard__v">' + escHtml(delivery || '—') + '</div>' +
            '</div>' +
          '</div>' +

          '' +
        '</a>'
      );
    }).join('');

    listEl.innerHTML = html;
  }

  async function loadOrders(opts) {
    if (!apiActive) {
      toast('Active orders endpoint missing.', 'error');
      return;
    }

    state.loading = true;
    renderList();

    var u = apiActive;
    if (u.indexOf('?') === -1) u += '?limit=100';
    else u += '&limit=100';

    var out = await fetchJson(u, {
      method: 'GET',
      _abortKey: 'list',
      headers: wpRestNonce ? { 'X-WP-Nonce': wpRestNonce } : {}
    });

    var json = out.data;
    state.loading = false;

    if (!out.ok || !json || json.success !== true) {
      listEl.innerHTML = '<div class="knx-empty">Unable to load active orders.</div>';
      return;
    }

    // Accept both shapes:
    // - v2: { success:true, data:{ orders:[...] } }
    // - fallback: { success:true, orders:[...] }
    var orders = [];
    if (json && json.data && Array.isArray(json.data.orders)) orders = json.data.orders;
    else if (json && Array.isArray(json.orders)) orders = json.orders;

    // client-side filtering:
    // - default: hide completed/cancelled orders (active view)
    // - archived view: show only completed orders from the same local day
    if (opts && opts.showCompleted) {
      var today = new Date();
      orders = orders.filter(function (o) {
        var st = normalizeStatus(o && o.status ? o.status : '');
        if (st !== 'completed') return false;
        var dateStr = (o && (o.completed_at || o.updated_at || o.created_at || o.assigned_at)) ? (o.completed_at || o.updated_at || o.created_at || o.assigned_at) : '';
        var d = parseMysqlToDate(dateStr);
        if (!d) return false;
        // compare local date parts
        return d.getFullYear() === today.getFullYear() && d.getMonth() === today.getMonth() && d.getDate() === today.getDate();
      });
    } else {
      orders = orders.filter(function (o) {
        var st = normalizeStatus(o && o.status ? o.status : '');
        return (st !== 'completed' && st !== 'cancelled');
      });
    }

    state.orders = orders;
    applyFilter();
    renderList();

    if (!opts || !opts.silent) {
      if (opts && opts.showCompleted) toast('Loaded ' + state.filtered.length + ' completed order(s) (today).', 'success');
      else toast('Loaded ' + state.filtered.length + ' active order(s).', 'success');
    }
  }

  function startPolling() {
    // Polling disabled: switched to manual refresh only.
    // Kept function as noop for compatibility with older markup.
    stopPolling();
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  if (searchEl) {
    searchEl.addEventListener('input', debounce(function () {
      applyFilter();
      renderList();
    }, 180));
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      loadOrders({ showCompleted: state.showArchived });
    });
  }

  // Replace `live` control with an archive/tab button for "Completed Today".
  (function setupArchiveControl(){
    try {
      // create button
      archiveBtn = document.createElement('button');
      archiveBtn.type = 'button';
      archiveBtn.id = 'knxActiveArchiveBtn';
      archiveBtn.className = 'knx-btn-secondary';
      archiveBtn.setAttribute('aria-pressed', 'false');
      archiveBtn.textContent = 'Completed Today';

      archiveBtn.addEventListener('click', function () {
        state.showArchived = !state.showArchived;
        archiveBtn.classList.toggle('is-active', state.showArchived);
        archiveBtn.setAttribute('aria-pressed', state.showArchived ? 'true' : 'false');
        // change label slightly to indicate mode
        archiveBtn.textContent = state.showArchived ? 'Showing: Completed Today' : 'Completed Today';
        // reload orders in the selected mode
        loadOrders({ showCompleted: state.showArchived });
      });

      // try to remove the visible live control (may be an input inside a container)
      var replaced = false;
      if (liveEl) {
        var liveRoot = (typeof liveEl.closest === 'function') ? (liveEl.closest('.knx-live') || liveEl) : liveEl;
        if (!liveRoot || !liveRoot.parentNode) {
          liveRoot = document.querySelector('.knx-live') || liveEl;
        }
        if (liveRoot && liveRoot.parentNode) {
          liveRoot.parentNode.replaceChild(archiveBtn, liveRoot);
          replaced = true;
        } else if (liveEl.parentNode) {
          try { liveEl.parentNode.removeChild(liveEl); } catch (e) {}
          if (refreshBtn && refreshBtn.parentNode) refreshBtn.parentNode.insertBefore(archiveBtn, refreshBtn.nextSibling);
          else root.appendChild(archiveBtn);
          replaced = true;
        }
      }
      if (!replaced) {
        if (refreshBtn && refreshBtn.parentNode) {
          refreshBtn.parentNode.insertBefore(archiveBtn, refreshBtn.nextSibling);
        } else {
          root.appendChild(archiveBtn);
        }
      }
    } catch (e) {
      // fail silently — UI enhancement only
      archiveBtn = null;
    }
  })();

  (function init() {
    loadOrders({ silent: true, showCompleted: state.showArchived });
    startPolling();
  })();
});