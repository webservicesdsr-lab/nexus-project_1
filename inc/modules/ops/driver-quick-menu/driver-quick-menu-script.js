// inc/modules/ops/driver-quick-menu/driver-quick-menu-script.js
/**
 * ==========================================================
 * KNX — Driver Quick Menu Script — CANON v1.0
 * - Disables Active tile if no active_order_id provided
 * ==========================================================
 */

(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  onReady(function () {
    var root = document.getElementById('knx-driver-quick-menu');
    if (!root) return;

    var activeOrderId = parseInt(root.dataset.activeOrderId || '0', 10);
    if (!isFinite(activeOrderId) || activeOrderId < 0) activeOrderId = 0;

    var activeUrl = String(root.dataset.activeUrl || '').trim();
    var activeLink = document.getElementById('knxDqmActiveLink');

    var toastEl = document.getElementById('knxDqmToast');
    var toastTimer = null;

    function toast(msg, type) {
      var message = String(msg || '').trim() || 'Something went wrong.';
      var t = String(type || 'info');

      if (typeof window.knxToast === 'function') {
        window.knxToast(message, t);
        return;
      }

      if (!toastEl) return;

      toastEl.className = 'knx-dqm__toast is-' + t;
      toastEl.textContent = message;
      toastEl.style.opacity = '1';

      clearTimeout(toastTimer);
      toastTimer = setTimeout(function () {
        toastEl.style.opacity = '0';
      }, 2200);
    }

    if (!activeLink) return;

    if (activeOrderId > 0) {
      var href = activeUrl;
      if (href) {
        href += (href.indexOf('?') >= 0 ? '&' : '?') + 'order_id=' + encodeURIComponent(String(activeOrderId));
        activeLink.href = href;
      }
      activeLink.classList.remove('is-disabled');
      activeLink.setAttribute('aria-disabled', 'false');
    } else {
      activeLink.href = '#';
      activeLink.classList.add('is-disabled');
      activeLink.setAttribute('aria-disabled', 'true');

      activeLink.addEventListener('click', function (e) {
        e.preventDefault();
        toast('No active order right now.', 'info');
      });
    }
  });
})();
