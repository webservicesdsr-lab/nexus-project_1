/**
 * ==========================================================
 * KNX — Driver Bottom Navbar Script (v1.0)
 * ----------------------------------------------------------
 * - No console logs, no alerts
 * - Active tab uses last known order_id (localStorage)
 * - Stores order_id from URL or dataset when present
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var nav = document.querySelector('.knx-driver-bottomnav');
  if (!nav) return;

  var KEY_LAST_ACTIVE = 'knx_driver_last_active_order_id';

  function toInt(v) {
    var n = parseInt(v, 10);
    return isFinite(n) ? n : 0;
  }

  function getQueryOrderId() {
    try {
      var u = new URL(window.location.href);
      var v = u.searchParams.get('order_id');
      return toInt(v);
    } catch (e) {
      return 0;
    }
  }

  function setLastActiveOrderId(id) {
    var n = toInt(id);
    if (n > 0) {
      try { localStorage.setItem(KEY_LAST_ACTIVE, String(n)); } catch (e) {}
    }
  }

  function getLastActiveOrderId() {
    try {
      return toInt(localStorage.getItem(KEY_LAST_ACTIVE) || '');
    } catch (e) {
      return 0;
    }
  }

  // Store from dataset (preferred) or from URL (fallback)
  var dsOrderId = toInt(nav.getAttribute('data-last-active-order-id') || '');
  if (dsOrderId > 0) setLastActiveOrderId(dsOrderId);

  var urlOrderId = getQueryOrderId();
  if (urlOrderId > 0) setLastActiveOrderId(urlOrderId);

  var quickUrl = nav.getAttribute('data-quick-url') || '/driver-quick-menu';
  var opsUrl = nav.getAttribute('data-ops-url') || '/driver-ops';
  var liveUrl = nav.getAttribute('data-live-url') || '/driver-live-orders';
  var profileUrl = nav.getAttribute('data-profile-url') || '/driver-profile';

  function go(url) {
    window.location.href = url;
  }

  function buildActiveHref() {
    var id = getLastActiveOrderId();
    if (id > 0) {
      // Deep-link to active order detail if we have a last known id
      return opsUrl.replace(/\/$/, '') + '/accept?order_id=' + encodeURIComponent(String(id));
    }
    return activeUrl;
  }

  var items = nav.querySelectorAll('.knx-driver-bottomnav__item');
  Array.prototype.forEach.call(items, function (a) {
    a.addEventListener('click', function (e) {
      var tab = a.getAttribute('data-tab') || '';

      // Let middle-click / new-tab behave normally
      if (e.defaultPrevented) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      e.preventDefault();

      if (tab === 'quick') return go(quickUrl);
      if (tab === 'ops') return go(opsUrl);
      if (tab === 'live') return go(liveUrl);
      if (tab === 'profile') return go(profileUrl);

      // Fallback
      return go(a.getAttribute('href') || liveUrl);
    });
  });
});
