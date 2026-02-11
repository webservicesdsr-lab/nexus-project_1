/**
 * ==========================================================
 * KNX — Driver Active Order UI (1:1)
 * ----------------------------------------------------------
 * - Snapshot-projected fields are the UI source of truth
 * - No console logs, no browser alerts
 * - Only two modals: status update + release
 * - Endpoints come from dataset (no hardcoded REST paths)
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var root = document.getElementById('knx-driver-active-order');
  if (!root) return;

  var orderId = parseInt(root.dataset.orderId, 10) || 0;

  var apiDetail = (root.dataset.apiDetail || '').trim();
  var apiBaseV2 = (root.dataset.apiBaseV2 || '').trim(); // expected to end with "/driver/orders/"
  var backUrl = (root.dataset.backUrl || '/driver-live-orders').trim();

  var wpRestNonce = (root.dataset.wpRestNonce || '').trim();
  var knxNonce = (root.dataset.knxNonce || '').trim();

  // UI refs
  var card = document.getElementById('knxDaoCard');
  var emptyEl = document.getElementById('knxDaoEmpty');

  var statusCard = document.getElementById('knxDaoStatusCard');
  var statusValueEl = document.getElementById('knxDaoStatusValue');

  var totalEl = document.getElementById('knxDaoTotal');
  var tipsEl = document.getElementById('knxDaoTips');
  var restaurantEl = document.getElementById('knxDaoRestaurant');
  var orderNumberEl = document.getElementById('knxDaoOrderNumber');

  var itemsEl = document.getElementById('knxDaoItems');
  var pickupEl = document.getElementById('knxDaoPickup');
  var deliveryEl = document.getElementById('knxDaoDelivery');
  var customerLineEl = document.getElementById('knxDaoCustomerLine');
  var distanceValEl = document.getElementById('knxDaoDistanceVal');

  var navBtn = document.getElementById('knxDaoNavigate');
  var customerBtn = document.getElementById('knxDaoCustomer');

  var releaseBtn = document.getElementById('knxDaoReleaseBtn');

  // Modals
  var statusModal = document.getElementById('knxDaoStatusModal');
  var statusList = document.getElementById('knxDaoStatusList');
  var cancelWarn = document.getElementById('knxDaoCancelWarn');
  var updateStatusBtn = document.getElementById('knxDaoUpdateStatusBtn');

  var releaseModal = document.getElementById('knxDaoReleaseModal');
  var confirmReleaseBtn = document.getElementById('knxDaoConfirmReleaseBtn');

  // Toast
  var toastEl = document.getElementById('knxDaoToast');
  var toastTimer = null;

  var state = {
    order: null,
    selectedOpsStatus: null,
    cancelArmed: false,
    isModalOpen: false,
    lastFocus: null
  };

  function escHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function toast(msg, type) {
    var message = (msg || '').toString().trim() || 'Something went wrong.';
    var t = (type || 'info').toString();

    // If you have a global toast helper, use it.
    if (typeof window.knxToast === 'function') {
      window.knxToast(message, t);
      return;
    }

    if (!toastEl) return;

    toastEl.className = 'knx-dao__toast is-' + t;
    toastEl.textContent = message;
    toastEl.style.opacity = '1';

    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toastEl.style.opacity = '0';
    }, 2600);
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

  function toFixedMoneyNumber(val) {
    var n = parseFloat(val);
    if (!isFinite(n)) return '—';
    return n.toFixed(2);
  }

  function normalizeStatusKey(s) {
    return String(s || '').trim().toLowerCase();
  }

  // This is the canonical ops-status list for the UI (labels match your screenshots 1:1)
  // Values are the ops_status we will POST (compatible with your existing active pipeline values)
  var OPS_STATUS_CHOICES = [
    { value: 'assigned',         label: 'Accepted by Driver',      tone: 'green' },
    { value: 'accepted',         label: 'Accepted by Restaurant',  tone: 'muted' },
    { value: 'preparing',        label: 'Preparing',               tone: 'orange' },
    { value: 'ready',            label: 'Prepared',                tone: 'blue' },
    { value: 'picked_up',        label: 'Picked up',               tone: 'purple' },
    { value: 'delivered',        label: 'Completed',               tone: 'green' },
    { value: 'cancelled',        label: 'Cancelled',               tone: 'red', requiresConfirm: true }
  ];

  function labelForOpsStatus(value) {
    var v = normalizeStatusKey(value);

    // Back-compat mapping: your API list mode includes out_for_delivery too
    if (v === 'out_for_delivery') v = 'picked_up';
    if (v === 'canceled') v = 'cancelled';

    for (var i = 0; i < OPS_STATUS_CHOICES.length; i++) {
      if (OPS_STATUS_CHOICES[i].value === v) return OPS_STATUS_CHOICES[i].label;
    }

    // Default humanize
    if (!v) return '—';
    return v.replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
  }

  function toneForOpsStatus(value) {
    var v = normalizeStatusKey(value);
    if (v === 'out_for_delivery') v = 'picked_up';
    if (v === 'canceled') v = 'cancelled';

    for (var i = 0; i < OPS_STATUS_CHOICES.length; i++) {
      if (OPS_STATUS_CHOICES[i].value === v) return OPS_STATUS_CHOICES[i].tone;
    }
    return 'green';
  }

  function haversineMiles(lat1, lon1, lat2, lon2) {
    function toRad(x) { return x * Math.PI / 180; }
    if (!isFinite(lat1) || !isFinite(lon1) || !isFinite(lat2) || !isFinite(lon2)) return null;

    var R = 3958.8;
    var dLat = toRad(lat2 - lat1);
    var dLon = toRad(lon2 - lon1);

    var a =
      Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
      Math.sin(dLon / 2) * Math.sin(dLon / 2);

    var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
  }

  function formatMiles(n) {
    if (!isFinite(n)) return '—';
    var v = Math.round(n * 10) / 10;
    return v.toFixed(1);
  }

  function modalOpen(modal) {
    if (!modal) return;
    state.lastFocus = document.activeElement;

    modal.classList.add('is-active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('knx-dao__no-scroll');
    state.isModalOpen = true;

    setTimeout(function () {
      var focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (focusable) focusable.focus();
    }, 80);
  }

  function modalClose(modal) {
    if (!modal) return;

    modal.classList.remove('is-active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('knx-dao__no-scroll');
    state.isModalOpen = false;

    if (state.lastFocus && typeof state.lastFocus.focus === 'function') {
      setTimeout(function () { try { state.lastFocus.focus(); } catch (e) {} }, 0);
    }
  }

  function wireModal(modal) {
    if (!modal) return;

    // Close buttons
    var closers = modal.querySelectorAll('[data-close]');
    Array.prototype.forEach.call(closers, function (btn) {
      btn.addEventListener('click', function () { modalClose(modal); });
    });

    // Overlay click
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modalClose(modal);
    });
  }

  wireModal(statusModal);
  wireModal(releaseModal);

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (releaseModal && releaseModal.classList.contains('is-active')) return modalClose(releaseModal);
    if (statusModal && statusModal.classList.contains('is-active')) return modalClose(statusModal);
  });

  async function fetchJson(url, opts) {
    if (!url) return { ok: false, status: 0, data: null };

    var res = await fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: Object.assign({
        'X-WP-Nonce': wpRestNonce || ''
      }, (opts && opts.headers) ? opts.headers : {})
    }, opts || {}));

    var data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    return { ok: res.ok, status: res.status, data: data };
  }

  function showEmpty(title, message, withBack) {
    if (!emptyEl) return;

    var back = withBack ? ('<a href="' + escHtml(backUrl) + '">Go to Active Orders</a>') : '';
    emptyEl.innerHTML = ''
      + '<div class="knx-dao__empty-box">'
      +   '<strong>' + escHtml(title || 'Missing order') + '</strong><br>'
      +   escHtml(message || '') + '<br>'
      +   back
      + '</div>';

    emptyEl.hidden = false;

    if (card) {
      card.style.display = 'none';
    }
    if (statusCard) {
      statusCard.style.display = 'none';
    }
  }

  function setBusy(isBusy) {
    if (!card) return;
    card.setAttribute('aria-busy', isBusy ? 'true' : 'false');
  }

  function renderStatusChoices(selectedValue) {
    if (!statusList) return;

    var sel = normalizeStatusKey(selectedValue);
    if (sel === 'out_for_delivery') sel = 'picked_up';
    if (sel === 'canceled') sel = 'cancelled';

    var html = OPS_STATUS_CHOICES.map(function (c) {
      var isSel = (c.value === sel);
      var cls = 'knx-dao__status-opt is-' + c.tone + (isSel ? ' is-selected' : '');
      var sub = (c.requiresConfirm) ? '<div class="knx-dao__status-sub">This action requires confirmation</div>' : '';
      var radio = '<span class="knx-dao__radio" aria-hidden="true"></span>';

      return (
        '<button type="button" class="' + cls + '" data-ops="' + escHtml(c.value) + '">' +
          '<span class="knx-dao__status-text">' + escHtml(c.label) + '</span>' +
          radio +
          sub +
        '</button>'
      );
    }).join('');

    statusList.innerHTML = html;

    // Wire select
    var btns = statusList.querySelectorAll('[data-ops]');
    Array.prototype.forEach.call(btns, function (btn) {
      btn.addEventListener('click', function () {
        var v = normalizeStatusKey(btn.getAttribute('data-ops'));
        state.selectedOpsStatus = v;
        state.cancelArmed = false;

        // UI selection update
        Array.prototype.forEach.call(btns, function (b) { b.classList.remove('is-selected'); });
        btn.classList.add('is-selected');

        // Warning state for cancel
        var needs = (v === 'cancelled');
        if (cancelWarn) cancelWarn.hidden = !needs;

        // Reset update button text
        if (updateStatusBtn) {
          updateStatusBtn.textContent = needs ? 'Update' : 'Update';
          updateStatusBtn.disabled = false;
        }
      });
    });
  }

  function extractItemNote(it) {
    if (!it || typeof it !== 'object') return '';
    if (typeof it.note === 'string' && it.note.trim()) return it.note.trim();
    if (typeof it.notes === 'string' && it.notes.trim()) return it.notes.trim();
    if (typeof it.special_instructions === 'string' && it.special_instructions.trim()) return it.special_instructions.trim();
    if (typeof it.instructions === 'string' && it.instructions.trim()) return it.instructions.trim();
    return '';
  }

  function extractModifierNames(mods) {
    if (!mods) return [];
    if (!Array.isArray(mods)) return [];

    var out = [];
    for (var i = 0; i < mods.length; i++) {
      var m = mods[i];
      if (m == null) continue;

      if (typeof m === 'string' && m.trim()) out.push(m.trim());
      else if (typeof m === 'object') {
        if (typeof m.name === 'string' && m.name.trim()) out.push(m.name.trim());
        else if (typeof m.label === 'string' && m.label.trim()) out.push(m.label.trim());
        else if (typeof m.title === 'string' && m.title.trim()) out.push(m.title.trim());
      }
    }
    return out;
  }

  function renderOrder(o) {
    state.order = o;

    // Status
    var ops = normalizeStatusKey(o.ops_status);
    if (ops === 'out_for_delivery') ops = 'picked_up';
    if (ops === 'canceled') ops = 'cancelled';

    state.selectedOpsStatus = ops;

    var label = labelForOpsStatus(ops);
    var tone = toneForOpsStatus(ops);

    if (statusValueEl) {
      statusValueEl.textContent = label;
      statusValueEl.className = 'knx-dao__status-value is-' + tone;
    }

    // Totals
    if (totalEl) totalEl.textContent = money(o.total);
    if (tipsEl) tipsEl.textContent = money(o.tip_amount);

    // Restaurant + order number
    if (restaurantEl) restaurantEl.textContent = o.hub_name || '—';
    if (orderNumberEl) orderNumberEl.textContent = o.order_number || (o.id ? String(o.id) : '—');

    // Items
    if (itemsEl) {
      var items = Array.isArray(o.items) ? o.items : [];

      if (!items.length) {
        itemsEl.innerHTML = '<div class="knx-dao__item-empty">No items found.</div>';
      } else {
        itemsEl.innerHTML = items.map(function (it) {
          var qty = parseInt(it.quantity, 10);
          if (!isFinite(qty) || qty <= 0) qty = 1;

          var name = (it.name_snapshot || it.name || it.title || '').toString().trim() || '—';

          var mods = extractModifierNames(it.modifiers);
          var note = extractItemNote(it);

          var modsHtml = mods.length
            ? '<ul class="knx-dao__mods">' + mods.map(function (m) {
                return '<li>+ ' + escHtml(m) + '</li>';
              }).join('') + '</ul>'
            : '';

          var noteHtml = note
            ? '<div class="knx-dao__note"><em>Note: ' + escHtml(note) + '</em></div>'
            : '';

          return ''
            + '<div class="knx-dao__item">'
            +   '<div class="knx-dao__item-title"><strong>' + escHtml(qty + 'x ' + name) + '</strong></div>'
            +   modsHtml
            +   noteHtml
            + '</div>';
        }).join('');
      }
    }

    // Locations
    var pickupText = (o.pickup_address_text || '').toString().trim() || '—';
    var deliveryText = (o.delivery_address_text || '').toString().trim() || '—';
    if (pickupEl) pickupEl.textContent = pickupText;
    if (deliveryEl) deliveryEl.textContent = deliveryText;

    var custName = (o.customer_name || '').toString().trim() || '—';
    if (customerLineEl) customerLineEl.textContent = 'Customer: ' + custName;

    // Distance (use DB distance if provided, else compute from coords)
    var dist = null;
    if (typeof o.delivery_distance === 'number' && isFinite(o.delivery_distance)) dist = o.delivery_distance;
    else if (typeof o.delivery_distance === 'string' && isFinite(parseFloat(o.delivery_distance))) dist = parseFloat(o.delivery_distance);
    else {
      var pLat = parseFloat(o.pickup_lat);
      var pLng = parseFloat(o.pickup_lng);
      var dLat = parseFloat(o.delivery_lat);
      var dLng = parseFloat(o.delivery_lng);
      var calc = haversineMiles(pLat, pLng, dLat, dLng);
      if (calc != null && isFinite(calc)) dist = calc;
    }

    if (distanceValEl) {
      distanceValEl.textContent = (dist != null && isFinite(dist)) ? (formatMiles(dist) + ' mi') : '—';
    }

    // Navigate link (Google maps directions)
    if (navBtn) {
      var destLat = parseFloat(o.delivery_lat);
      var destLng = parseFloat(o.delivery_lng);

      var orgLat = parseFloat(o.pickup_lat);
      var orgLng = parseFloat(o.pickup_lng);

      if (isFinite(destLat) && isFinite(destLng)) {
        var url = 'https://www.google.com/maps/dir/?api=1'
          + '&destination=' + encodeURIComponent(destLat + ',' + destLng);

        if (isFinite(orgLat) && isFinite(orgLng)) {
          url += '&origin=' + encodeURIComponent(orgLat + ',' + orgLng);
        }

        navBtn.href = url;
        navBtn.classList.remove('is-disabled');
        navBtn.setAttribute('aria-disabled', 'false');
      } else {
        navBtn.href = '#';
        navBtn.classList.add('is-disabled');
        navBtn.setAttribute('aria-disabled', 'true');
      }
    }

    // Customer call
    if (customerBtn) {
      var phone = (o.customer_phone || '').toString().trim();
      if (phone) {
        customerBtn.href = 'tel:' + phone;
        customerBtn.classList.remove('is-disabled');
        customerBtn.setAttribute('aria-disabled', 'false');
      } else {
        customerBtn.href = '#';
        customerBtn.classList.add('is-disabled');
        customerBtn.setAttribute('aria-disabled', 'true');
      }
    }

    // Modal choices reflect current ops_status
    renderStatusChoices(ops);
  }

  function buildStatusUrl() {
    if (!apiBaseV2 || !orderId) return '';
    // Canon guess: "/driver/orders/{id}/ops-status"
    return apiBaseV2 + orderId + '/ops-status';
  }

  function buildReleaseUrl() {
    if (!apiBaseV2 || !orderId) return '';
    // Canon guess: "/driver/orders/{id}/release"
    return apiBaseV2 + orderId + '/release';
  }

  async function loadOrder() {
    if (!apiDetail) {
      showEmpty('Missing endpoint', 'Driver active orders endpoint missing.', true);
      return;
    }

    if (!orderId) {
      showEmpty('Missing order', 'No order_id provided.', true);
      return;
    }

    setBusy(true);

    var out = await fetchJson(apiDetail + '?order_id=' + encodeURIComponent(orderId), { method: 'GET' });
    setBusy(false);

    var json = out.data;

    if (!out.ok || !json || json.success !== true || !json.data || !Array.isArray(json.data.orders) || !json.data.orders.length) {
      showEmpty('Order not available', 'This order is not assigned to you.', false);
      return;
    }

    var o = json.data.orders[0];

    // Fail-closed expectation: snapshot v5 already enforced server-side
    renderOrder(o);

    if (card) {
      card.style.display = '';
    }
    if (emptyEl) {
      emptyEl.hidden = true;
    }
  }

  async function updateOpsStatus(newStatus) {
    var v = normalizeStatusKey(newStatus);
    if (!v) return;

    // Cancel requires confirmation (within same modal; no third modal)
    if (v === 'cancelled' && !state.cancelArmed) {
      state.cancelArmed = true;
      toast('Tap Update again to confirm Cancelled.', 'warning');
      return;
    }

    var url = buildStatusUrl();
    if (!url) {
      toast('Status endpoint missing.', 'error');
      return;
    }

    if (updateStatusBtn) {
      updateStatusBtn.disabled = true;
      updateStatusBtn.textContent = 'Updating…';
    }

    var payload = {
      knx_nonce: knxNonce || '',
      order_id: orderId,
      ops_status: v,
      status: v
    };

    var out = await fetchJson(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    var json = out.data;

    if (!out.ok || !json || json.success !== true) {
      var reason = (json && json.data && (json.data.reason || json.data.message)) ? (json.data.reason || json.data.message) : 'Update failed.';
      toast(reason, 'error');

      if (updateStatusBtn) {
        updateStatusBtn.disabled = false;
        updateStatusBtn.textContent = 'Update';
      }
      state.cancelArmed = false;
      return;
    }

    toast('Status updated.', 'success');

    // Update local state + UI
    if (state.order) {
      state.order.ops_status = v;
      renderOrder(state.order);
    }

    modalClose(statusModal);

    if (updateStatusBtn) {
      updateStatusBtn.disabled = false;
      updateStatusBtn.textContent = 'Update';
    }
    state.cancelArmed = false;

    // If terminal-ish, bounce back to Available
    if (v === 'delivered' || v === 'cancelled') {
      window.location.href = backUrl;
    }
  }

  async function releaseOrder() {
    var url = buildReleaseUrl();
    if (!url) {
      toast('Release endpoint missing.', 'error');
      return;
    }

    if (confirmReleaseBtn) {
      confirmReleaseBtn.disabled = true;
      confirmReleaseBtn.textContent = 'Releasing…';
    }

    var payload = {
      knx_nonce: knxNonce || '',
      order_id: orderId
    };

    var out = await fetchJson(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    var json = out.data;

    if (!out.ok || !json || json.success !== true) {
      var reason = (json && json.data && (json.data.reason || json.data.message)) ? (json.data.reason || json.data.message) : 'Release failed.';
      toast(reason, 'error');

      if (confirmReleaseBtn) {
        confirmReleaseBtn.disabled = false;
        confirmReleaseBtn.textContent = 'Release';
      }
      return;
    }

    toast('Order released.', 'success');
    modalClose(releaseModal);

    window.location.href = backUrl;
  }

  // Wire interactions
  if (statusCard) {
    statusCard.addEventListener('click', function () {
      // Build modal from current state
      var current = state.order ? state.order.ops_status : null;
      state.selectedOpsStatus = normalizeStatusKey(current);
      state.cancelArmed = false;
      if (cancelWarn) cancelWarn.hidden = true;

      renderStatusChoices(state.selectedOpsStatus);
      modalOpen(statusModal);
    });
  }

  if (updateStatusBtn) {
    updateStatusBtn.addEventListener('click', function () {
      if (!state.selectedOpsStatus) return;
      updateOpsStatus(state.selectedOpsStatus);
    });
  }

  if (releaseBtn) {
    releaseBtn.addEventListener('click', function () {
      modalOpen(releaseModal);
    });
  }

  if (confirmReleaseBtn) {
    confirmReleaseBtn.addEventListener('click', function () {
      releaseOrder();
    });
  }

  // Disable actions when missing info
  if (customerBtn) {
    customerBtn.addEventListener('click', function (e) {
      if (customerBtn.classList.contains('is-disabled')) {
        e.preventDefault();
        toast('Customer phone not available.', 'warning');
      }
    });
  }

  if (navBtn) {
    navBtn.addEventListener('click', function (e) {
      if (navBtn.classList.contains('is-disabled')) {
        e.preventDefault();
        toast('Navigation not available.', 'warning');
      }
    });
  }

  // Init
  loadOrder();
});
