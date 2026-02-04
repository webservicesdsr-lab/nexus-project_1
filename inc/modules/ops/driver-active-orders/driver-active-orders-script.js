/**
 * ==========================================================
 * Kingdom Nexus — Driver Active Orders Script (v1.0)
 * ----------------------------------------------------------
 * Purpose:
 * - Manage active (assigned) orders for driver execution
 * - Status updates, completion, navigation
 * - NO console logs, NO browser alerts
 * - Non-authoritative UI (REST-first architecture)
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var root = document.getElementById('knx-driver-active-orders');
  if (!root) return;

  var cfg = (window.KNX_DRIVER_ACTIVE_CONFIG || {});
  var apiActive = root.dataset.apiActive || cfg.apiActive || '';
  var apiBase = root.dataset.apiBase || cfg.apiBase || '';
  var knxNonce = root.dataset.knxNonce || cfg.knxNonce || '';
  var wpRestNonce = root.dataset.wpRestNonce || cfg.wpRestNonce || '';
  var pollMs = parseInt(cfg.pollMs, 10) || 15000;

  var listEl = document.getElementById('knxActiveOrdersList');

  var state = {
    loading: false,
    orders: []
  };

  function $(sel, el) { return (el || root).querySelector(sel); }
  function $all(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }

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

  function money(val) {
    var n = parseFloat(val);
    if (!isFinite(n)) return '$—';
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(n);
    } catch (e) {
      return '$' + n.toFixed(2);
    }
  }

  function relTime(mysql) {
    if (!mysql || typeof mysql !== 'string') return '';
    var parts = mysql.split(' ');
    if (parts.length !== 2) return '';
    var d = parts[0].split('-').map(Number);
    var t = parts[1].split(':').map(Number);
    if (d.length !== 3 || t.length < 2) return '';
    var dt = new Date(Date.UTC(d[0], d[1] - 1, d[2], t[0] || 0, t[1] || 0, t[2] || 0));
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

    var res = await fetch(url, Object.assign({
      credentials: 'same-origin'
    }, opts || {}));

    var data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    return { ok: res.ok, status: res.status, data: data };
  }

  function renderList() {
    if (!listEl) return;

    if (state.loading) {
      listEl.innerHTML = '<div class="knx-empty">Loading your active orders…</div>';
      return;
    }

    if (!state.orders.length) {
      listEl.innerHTML = '<div class="knx-empty">No active orders right now.</div>';
      return;
    }

    var html = state.orders.map(function (o) {
      var id = parseInt(o && (o.id || o.order_id), 10) || 0;
      var restaurant = (o && (o.hub_name || o.restaurant_name || o.restaurant)) ? String(o.hub_name || o.restaurant_name || o.restaurant) : '';
      var delivery = (o && (o.delivery_address_text || o.delivery_address || o.delivery)) ? String(o.delivery_address_text || o.delivery_address || o.delivery) : '';
      var total = money(o && (o.total_amount || o.total || o.amount));
      var status = (o && o.status) ? String(o.status) : 'unknown';
      var time = relTime(o && o.assigned_at);

      return (
        '<div class="knx-active-card" data-order-id="' + id + '">' +
          '<div class="knx-active-header-line">' +
            '<span class="knx-active-restaurant">' + escHtml(restaurant) + '</span>' +
            '<span class="knx-active-order-id">#' + id + '</span>' +
          '</div>' +
          '<div class="knx-active-address">' + escHtml(delivery) + '</div>' +
          '<div class="knx-active-meta">' +
            '<span class="knx-active-total">' + escHtml(total) + '</span>' +
            '<span class="knx-active-time">' + escHtml(time) + '</span>' +
          '</div>' +
          '<div class="knx-active-actions">' +
            '<button type="button" class="knx-btn knx-complete-btn" data-id="' + id + '">Complete</button>' +
            '<button type="button" class="knx-btn-secondary knx-details-btn" data-id="' + id + '">Details</button>' +
          '</div>' +
        '</div>'
      );
    }).join('');

    listEl.innerHTML = html;

    // Wire buttons
    $all('.knx-complete-btn', listEl).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        completeOrder(id);
      });
    });

    $all('.knx-details-btn', listEl).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        toast('Order details feature coming soon.', 'info');
      });
    });
  }

  async function loadOrders(opts) {
    if (!apiActive) {
      toast('Active orders endpoint missing.', 'error');
      return;
    }

    state.loading = true;
    renderList();

    var out = await fetchJson(apiActive, { method: 'GET' });
    var json = out.data;

    state.loading = false;

    if (!out.ok || !json || json.success !== true) {
      listEl.innerHTML = '<div class="knx-empty">Unable to load active orders.</div>';
      return;
    }

    var data = json.data || {};
    state.orders = Array.isArray(data.orders) ? data.orders : [];

    renderList();
  }

  async function completeOrder(orderId) {
    var id = parseInt(orderId, 10) || 0;
    if (!id) return;

    var url = apiBase + id + '/complete';
    if (!url) {
      toast('Complete endpoint missing.', 'error');
      return;
    }

    var listBtn = listEl ? listEl.querySelector('.knx-complete-btn[data-id="' + id + '"]') : null;
    if (listBtn) {
      listBtn.disabled = true;
      listBtn.textContent = 'Completing…';
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
      var reason = (json && json.data && (json.data.reason || json.data.message)) ? (json.data.reason || json.data.message) : 'Complete failed.';
      toast(reason, 'error');

      if (listBtn) {
        listBtn.disabled = false;
        listBtn.textContent = 'Complete';
      }
      return;
    }

    toast('Order completed.', 'success');

    // Remove from state
    state.orders = state.orders.filter(function (o) { return parseInt(o.id, 10) !== id; });
    renderList();
  }

  // Init
  loadOrders();
});
