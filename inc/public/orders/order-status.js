/**
 * ==========================================================
 * Kingdom Nexus — Customer Order Status (Canonical JS)
 * ----------------------------------------------------------
 * - Fetches GET /knx/v1/orders/{id}
 * - Renders: timeline, restaurant, items, totals, delivery
 * - Auto-polls every 15s (pauses when tab hidden or terminal)
 * - Nexus Shell UX
 * ==========================================================
 */
(function () {
  'use strict';

  var POLL_MS = 15000;
  var TERMINAL_STATUSES = ['completed', 'cancelled'];

  var root = document.getElementById('knx-order-status');
  if (!root) return;

  var contentEl = document.getElementById('knxOrderStatusContent');
  if (!contentEl) return;

  var orderId = parseInt(root.dataset.orderId || '0', 10);
  var apiBase = (root.dataset.apiBase || '/wp-json/knx/v1/orders/').replace(/\/$/, '') + '/';
  var homeUrl = root.dataset.homeUrl || '/';

  var pollTimer = null;
  var lastStatus = null;

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
      return d.toLocaleDateString();
    } catch (e) {
      return dateStr;
    }
  }

  function formatTime(dateStr) {
    if (!dateStr) return '';
    try {
      var d = new Date(dateStr.replace(' ', 'T'));
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
      return '';
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

  function renderError(title, detail) {
    contentEl.innerHTML =
      '<div class="knx-os__error-card">' +
        '<div class="knx-os__error-icon">!</div>' +
        '<div class="knx-os__error-title">' + esc(title) + '</div>' +
        (detail ? '<div class="knx-os__error-detail">' + esc(detail) + '</div>' : '') +
        '<a class="knx-os__btn" href="' + esc(homeUrl) + '">Go Home</a>' +
      '</div>';
  }

  function renderOrder(order) {
    var o = order;
    var status = String(o.status || '').toLowerCase();
    var fulfillment = String(o.fulfillment_type || 'delivery').toLowerCase();
    var isPickup = (fulfillment === 'pickup');
    var isTerminal = TERMINAL_STATUSES.indexOf(status) !== -1;

    // Restaurant
    var rName = esc(o.restaurant && o.restaurant.name ? o.restaurant.name : 'Restaurant');
    var rAddr = esc(o.restaurant && o.restaurant.address ? o.restaurant.address : '');
    var rPhone = o.restaurant && o.restaurant.phone ? String(o.restaurant.phone).trim() : '';
    var rLogo = o.restaurant && o.restaurant.logo_url ? o.restaurant.logo_url : '';

    // Delivery
    var dAddr = esc(o.delivery && o.delivery.address ? o.delivery.address : '');

    // Totals
    var totals = o.totals || {};

    // Items
    var items = Array.isArray(o.items) ? o.items : [];

    // Timeline
    var timeline = Array.isArray(o.status_history) ? o.status_history : [];

    var html = '';

    // ── Header ──
    html += '<div class="knx-os__header">';
    html +=   '<div class="knx-os__header-top">';
    html +=     '<div class="knx-os__id-wrap">';
    html +=       '<span class="knx-os__id-label">Order ID:</span>';
    html +=       '<span class="knx-os__id-chip">#' + esc(String(o.order_id)) + '</span>';
    html +=     '</div>';
    html +=     '<div class="knx-os__status-pill is-' + statusTone(status) + '">' + esc(statusLabel(status)) + '</div>';
    html +=   '</div>';
    html +=   '<div class="knx-os__header-meta">';
    html +=     '<span>' + esc(relativeTime(o.created_at)) + '</span>';
    html +=     '<span class="knx-os__dot"></span>';
    html +=     '<span>' + esc(isPickup ? 'Pickup' : 'Delivery') + '</span>';
    html +=   '</div>';
    html += '</div>';

    // ── Timeline ──
    // Filter out hidden steps (e.g. 'confirmed' is internal, not shown to customer)
    // Additionally: for pickup orders we hide the generic 'prepared' ("Ready") step
    // because we prefer showing the explicit 'ready_for_pickup' label to the customer.
    var visibleTimeline = [];
    for (var t = 0; t < timeline.length; t++) {
      var step = timeline[t];
      if (!step) continue;
      var st = String(step.status || '').toLowerCase();
      // Hide 'prepared' on customer-facing timeline for pickup orders to avoid duplication
      if (isPickup && st === 'prepared') continue;
      if (!step.hidden) visibleTimeline.push(step);
    }

    html += '<div class="knx-os__timeline">';
    for (var i = 0; i < visibleTimeline.length; i++) {
      var step = visibleTimeline[i];
      var stepClass = 'knx-os__step';
      if (step.is_current) stepClass += ' is-current';
      else if (step.is_done) stepClass += ' is-done';

      html += '<div class="' + stepClass + '">';
      html +=   '<div class="knx-os__step-dot"></div>';
      if (i < visibleTimeline.length - 1) {
        html += '<div class="knx-os__step-line"></div>';
      }
      html +=   '<div class="knx-os__step-content">';
      html +=     '<div class="knx-os__step-label">' + esc(step.label || statusLabel(step.status)) + '</div>';
      if (step.created_at) {
        html +=   '<div class="knx-os__step-time">' + esc(formatTime(step.created_at)) + '</div>';
      }
      html +=   '</div>';
      html += '</div>';
    }
    html += '</div>';

    // ── Restaurant Card ──
    html += '<div class="knx-os__card">';
    html +=   '<div class="knx-os__card-title">Restaurant</div>';
    html +=   '<div class="knx-os__restaurant">';
    if (rLogo) {
      html += '<img class="knx-os__logo" src="' + esc(rLogo) + '" alt="" loading="lazy">';
    }
    html +=     '<div class="knx-os__restaurant-info">';
    html +=       '<div class="knx-os__restaurant-name">' + rName + '</div>';
    if (rAddr) {
      html +=     '<div class="knx-os__restaurant-addr">' + rAddr + '</div>';
    }
    html +=     '</div>';
    html +=   '</div>';
    html += '</div>';

    // ── Delivery / Pickup Card ──
    if (!isPickup && dAddr) {
      html += '<div class="knx-os__card">';
      html +=   '<div class="knx-os__card-title">Delivery Address</div>';
      html +=   '<div class="knx-os__delivery-addr">' + dAddr + '</div>';
      html += '</div>';
    } else if (isPickup) {
      html += '<div class="knx-os__card">';
      html +=   '<div class="knx-os__card-title">Pickup Location</div>';
      html +=   '<div class="knx-os__delivery-addr">' + (rAddr || rName) + '</div>';
      html += '</div>';
    }

    // ── Items ──
    if (items.length > 0) {
      html += '<div class="knx-os__card">';
      html +=   '<div class="knx-os__card-title">Items</div>';
      html +=   '<div class="knx-os__items">';
      for (var j = 0; j < items.length; j++) {
        var it = items[j];
        var itName = esc(it.name || 'Item');
        var qty = parseInt(it.quantity || 1, 10);
        var lineTotal = parseFloat(it.line_total || 0);

        html += '<div class="knx-os__item">';
        if (it.image) {
          html += '<img class="knx-os__item-img" src="' + esc(it.image) + '" alt="" loading="lazy">';
        } else {
          html += '<div class="knx-os__item-img knx-os__item-img--placeholder"></div>';
        }
        html +=   '<div class="knx-os__item-info">';
        html +=     '<div class="knx-os__item-name">' + qty + 'x ' + itName + '</div>';

        // Modifiers
        if (it.modifiers && Array.isArray(it.modifiers) && it.modifiers.length > 0) {
          html += '<div class="knx-os__item-mods">';
          for (var m = 0; m < it.modifiers.length; m++) {
            var mod = it.modifiers[m];
            if (mod && Array.isArray(mod.options)) {
              var optNames = mod.options.map(function (opt) {
                return esc(opt.option || opt.name || '');
              }).filter(Boolean).join(', ');
              if (optNames) {
                html += '<span class="knx-os__mod">' + optNames + '</span>';
              }
            }
          }
          html += '</div>';
        }

        html +=   '</div>';
        html +=   '<div class="knx-os__item-price">' + money(lineTotal) + '</div>';
        html += '</div>';
      }
      html +=   '</div>';
      html += '</div>';
    }

    // ── Totals ──
    html += '<div class="knx-os__card">';
    html +=   '<div class="knx-os__card-title">Order Summary</div>';
    html +=   '<div class="knx-os__totals">';

    html += '<div class="knx-os__total-row"><span>Subtotal</span><span>' + money(totals.subtotal) + '</span></div>';

    if (totals.tax_amount > 0) {
      html += '<div class="knx-os__total-row"><span>Tax</span><span>' + money(totals.tax_amount) + '</span></div>';
    }
    if (totals.delivery_fee > 0) {
      html += '<div class="knx-os__total-row"><span>Delivery Fee</span><span>' + money(totals.delivery_fee) + '</span></div>';
    }
    if (totals.software_fee > 0) {
      html += '<div class="knx-os__total-row"><span>Service Fee</span><span>' + money(totals.software_fee) + '</span></div>';
    }
    if (totals.tip_amount > 0) {
      html += '<div class="knx-os__total-row"><span>Tip</span><span>' + money(totals.tip_amount) + '</span></div>';
    }
    if (totals.discount_amount > 0) {
      html += '<div class="knx-os__total-row"><span>Discount</span><span>-' + money(totals.discount_amount) + '</span></div>';
    }

    html += '<div class="knx-os__total-grand"><span>TOTAL</span><strong>' + money(totals.total) + '</strong></div>';
    html +=   '</div>';
    html += '</div>';

    // ── Payment ──
    if (o.payment) {
      html += '<div class="knx-os__card">';
      html +=   '<div class="knx-os__card-title">Payment</div>';
      html +=   '<div class="knx-os__meta-rows">';
      if (o.payment.method) {
        html += '<div class="knx-os__meta-row"><span>Method</span><span>' + esc(o.payment.method.toUpperCase()) + '</span></div>';
      }
      if (o.payment.status) {
        var payTone = o.payment.status === 'paid' ? 'done' : 'muted';
        html += '<div class="knx-os__meta-row"><span>Status</span><span class="knx-os__pay-pill is-' + payTone + '">' + esc(o.payment.status.charAt(0).toUpperCase() + o.payment.status.slice(1)) + '</span></div>';
      }
      html +=   '</div>';
      html += '</div>';
    }

    // ── Live indicator (non-terminal only) ──
    if (!isTerminal) {
      html += '<div class="knx-os__live-bar">';
      html +=   '<span class="knx-os__live-dot"></span>';
      html +=   '<span>Updating live</span>';
      html += '</div>';
    }

    contentEl.innerHTML = html;
    lastStatus = status;
  }

  function fetchOrder() {
    if (!orderId) {
      renderError('Order ID Missing', 'No order ID found in the URL.');
      return;
    }

    var url = apiBase + orderId;

    fetch(url, { method: 'GET', credentials: 'include' })
      .then(function (res) {
        return res.json().catch(function () { return null; });
      })
      .then(function (raw) {
        if (!raw) { renderError('Invalid Response', 'Server returned an unexpected response.'); return; }
        if (raw.success !== true) {
          var msg = raw.error || raw.message || 'Unable to load order.';
          renderError('Order Not Found', msg);
          return;
        }
        var o = raw.order || raw.data || raw;
        renderOrder(o);

        // Stop polling if terminal
        var st = String(o.status || '').toLowerCase();
        if (TERMINAL_STATUSES.indexOf(st) !== -1) {
          stopPolling();
        }
      })
      .catch(function () {
        renderError('Network Error', 'Please check your connection.');
      });
  }

  function startPolling() {
    stopPolling();
    pollTimer = setInterval(function () {
      if (document.hidden) return;
      fetchOrder();
    }, POLL_MS);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && orderId) fetchOrder();
  });

  // Init
  if (orderId > 0) {
    fetchOrder();
    startPolling();
  }
})();
