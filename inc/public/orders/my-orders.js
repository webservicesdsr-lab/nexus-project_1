/**
 * ==========================================================
 * Kingdom Nexus — Customer Order List (Canonical JS)
 * ----------------------------------------------------------
 * - Fetches GET /knx/v1/orders (paginated)
 * - Renders: Active orders + Past orders (separated)
 * - "Load more" pagination via limit/offset
 * - Each card links to /order-status?order_id=X
 * - Nexus Shell UX
 * ==========================================================
 */
(function () {
  'use strict';

  /* ── Constants ── */
  var TERMINAL = ['completed', 'cancelled'];

  /* ── DOM refs ── */
  var root = document.getElementById('knx-my-orders');
  if (!root) return;

  var contentEl = document.getElementById('knxMyOrdersContent');
  if (!contentEl) return;

  var apiBase        = (root.dataset.apiBase || '/wp-json/knx/v1/orders').replace(/\/$/, '');
  var orderStatusUrl = (root.dataset.orderStatusUrl || '/order-status').replace(/\/$/, '');
  var homeUrl        = root.dataset.homeUrl || '/';
  var PAGE_SIZE      = parseInt(root.dataset.pageSize || '15', 10);

  /* ── State ── */
  var allOrders = [];
  var offset    = 0;
  var total     = 0;
  var hasMore   = false;
  var loading   = false;

  /* ── Helpers ── */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function money(val) {
    var n = parseFloat(val);
    if (!isFinite(n)) return '$0.00';
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(n);
    } catch (e) {
      return '$' + n.toFixed(2);
    }
  }

  function relativeTime(dateStr) {
    if (!dateStr) return '';
    try {
      var d = new Date(dateStr.replace(' ', 'T'));
      var now = new Date();
      var diff = Math.floor((now - d) / 1000);
      if (diff < 60) return 'Just now';
      if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
      var days = Math.floor(diff / 86400);
      if (days === 1) return 'Yesterday';
      if (days < 7) return days + ' days ago';
      return d.toLocaleDateString();
    } catch (e) {
      return dateStr;
    }
  }

  function statusLabel(s) {
    var labels = {
      'pending_payment': 'Pending Payment',
      'confirmed': 'Confirmed',
      'order_created': 'Order Created',
      'accepted_by_driver': 'Driver Assigned',
      'accepted_by_hub': 'Restaurant Accepted',
      'preparing': 'Preparing',
      'prepared': 'Ready',
      'picked_up': 'On the Way',
      'ready_for_pickup': 'Ready for Pickup',
      'completed': 'Completed',
      'cancelled': 'Cancelled'
    };
    var k = String(s || '').toLowerCase();
    return labels[k] || k.replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
  }

  function statusTone(s) {
    var k = String(s || '').toLowerCase();
    if (k === 'completed') return 'done';
    if (k === 'cancelled') return 'cancelled';
    if (k === 'preparing' || k === 'prepared' || k === 'ready_for_pickup') return 'progress';
    if (k === 'picked_up') return 'active';
    if (k === 'confirmed' || k === 'accepted_by_driver' || k === 'accepted_by_hub') return 'info';
    return 'muted';
  }

  function isActive(status) {
    return TERMINAL.indexOf(String(status || '').toLowerCase()) === -1;
  }

  function buildOrderUrl(orderId) {
    return orderStatusUrl + '?order_id=' + encodeURIComponent(orderId);
  }

  /* ── Render: error ── */
  function renderError(title, detail) {
    contentEl.innerHTML =
      '<div class="knx-mo__error-card">' +
        '<div class="knx-mo__error-icon">!</div>' +
        '<div class="knx-mo__error-title">' + esc(title) + '</div>' +
        (detail ? '<div class="knx-mo__error-detail">' + esc(detail) + '</div>' : '') +
        '<a class="knx-mo__btn knx-mo__btn--primary" href="' + esc(homeUrl) + '">Go Home</a>' +
      '</div>';
  }

  /* ── Render: empty state ── */
  function renderEmpty() {
    contentEl.innerHTML =
      '<div class="knx-mo__empty">' +
        '<div class="knx-mo__empty-icon">📦</div>' +
        '<h2 class="knx-mo__empty-title">No orders yet</h2>' +
        '<p class="knx-mo__empty-text">When you place your first order, it will appear here.</p>' +
        '<a class="knx-mo__btn knx-mo__btn--primary" href="' + esc(homeUrl) + '">Explore Restaurants</a>' +
      '</div>';
  }

  /* ── Render: single order card ── */
  function renderCard(o) {
    var status = String(o.status || '').toLowerCase();
    var tone   = statusTone(status);
    var isFulfillPickup = String(o.fulfillment_type || '').toLowerCase() === 'pickup';
    var hubName  = esc(o.hub_name || 'Restaurant');
    var hubLogo  = o.hub_logo || '';

    var html = '';
    html += '<a class="knx-mo__card" href="' + esc(buildOrderUrl(o.order_id)) + '">';

    // Left: logo
    if (hubLogo) {
      html += '<img class="knx-mo__card-logo" src="' + esc(hubLogo) + '" alt="" loading="lazy">';
    } else {
      html += '<div class="knx-mo__card-logo knx-mo__card-logo--placeholder"></div>';
    }

    // Center: info
    html += '<div class="knx-mo__card-body">';
    html +=   '<div class="knx-mo__card-hub">' + hubName + '</div>';
    html +=   '<div class="knx-mo__card-meta">';
    html +=     '<span class="knx-mo__id-label">Order ID:</span> <span class="knx-mo__id-chip">#' + esc(String(o.order_id)) + '</span>';
    html +=     '<span class="knx-mo__dot"></span>';
    html +=     '<span>' + esc(relativeTime(o.created_at)) + '</span>';
    html +=   '</div>';
    html +=   '<div class="knx-mo__card-tags">';
    html +=     '<span class="knx-mo__pill is-' + tone + '">' + esc(statusLabel(status)) + '</span>';
    if (isFulfillPickup) {
      html +=   '<span class="knx-mo__pill is-pickup">Pickup</span>';
    }
    html +=   '</div>';
    html += '</div>';

    // Right: total + arrow
    html += '<div class="knx-mo__card-end">';
    html +=   '<div class="knx-mo__card-total">' + money(o.total) + '</div>';
    html +=   '<div class="knx-mo__card-arrow">&rsaquo;</div>';
    html += '</div>';

    html += '</a>';
    return html;
  }

  /* ── Render: full list ── */
  function renderOrders() {
    if (allOrders.length === 0) {
      renderEmpty();
      return;
    }

    var active = [];
    var past   = [];

    for (var i = 0; i < allOrders.length; i++) {
      if (isActive(allOrders[i].status)) {
        active.push(allOrders[i]);
      } else {
        past.push(allOrders[i]);
      }
    }

    var html = '';

    // ── Active orders section ──
    if (active.length > 0) {
      html += '<div class="knx-mo__section">';
      html +=   '<div class="knx-mo__section-header">';
      html +=     '<span class="knx-mo__section-dot is-pulse"></span>';
      html +=     '<h2 class="knx-mo__section-title">Active Orders</h2>';
      html +=     '<span class="knx-mo__section-count">' + active.length + '</span>';
      html +=   '</div>';
      html +=   '<div class="knx-mo__list">';
      for (var a = 0; a < active.length; a++) {
        html += renderCard(active[a]);
      }
      html +=   '</div>';
      html += '</div>';
    }

    // ── Past orders section ──
    if (past.length > 0) {
      html += '<div class="knx-mo__section">';
      html +=   '<div class="knx-mo__section-header">';
      html +=     '<h2 class="knx-mo__section-title">Past Orders</h2>';
      html +=     '<span class="knx-mo__section-count">' + past.length + '</span>';
      html +=   '</div>';
      html +=   '<div class="knx-mo__list">';
      for (var p = 0; p < past.length; p++) {
        html += renderCard(past[p]);
      }
      html +=   '</div>';
      html += '</div>';
    }

    // ── Load more button ──
    if (hasMore) {
      html += '<div class="knx-mo__load-more-wrap">';
      html +=   '<button id="knxLoadMoreBtn" class="knx-mo__btn knx-mo__btn--secondary" type="button">';
      html +=     'Load more orders';
      html +=   '</button>';
      html += '</div>';
    }

    // ── Summary ──
    html += '<div class="knx-mo__summary">';
    html +=   'Showing ' + allOrders.length + ' of ' + total + ' orders';
    html += '</div>';

    contentEl.innerHTML = html;

    // Bind Load more
    var loadBtn = document.getElementById('knxLoadMoreBtn');
    if (loadBtn) {
      loadBtn.addEventListener('click', function () {
        loadBtn.disabled = true;
        loadBtn.textContent = 'Loading…';
        fetchOrders(true);
      });
    }
  }

  /* ── Fetch ── */
  function fetchOrders(append) {
    if (loading) return;
    loading = true;

    var url = apiBase + '?limit=' + PAGE_SIZE + '&offset=' + offset;

    fetch(url, { method: 'GET', credentials: 'include' })
      .then(function (res) {
        return res.json().catch(function () { return null; });
      })
      .then(function (raw) {
        loading = false;

        if (!raw) {
          renderError('Invalid Response', 'Server returned an unexpected response.');
          return;
        }
        if (raw.success !== true) {
          var msg = raw.error || raw.message || 'Unable to load orders.';
          renderError('Unable to load orders', msg);
          return;
        }

        var orders = Array.isArray(raw.orders) ? raw.orders : [];
        var pg     = raw.pagination || {};

        total   = pg.total || 0;
        hasMore = !!pg.has_more;
        offset  = offset + orders.length;

        if (append) {
          allOrders = allOrders.concat(orders);
        } else {
          allOrders = orders;
        }

        renderOrders();
      })
      .catch(function () {
        loading = false;
        renderError('Network Error', 'Please check your connection and try again.');
      });
  }

  /* ── Init ── */
  fetchOrders(false);

})();
