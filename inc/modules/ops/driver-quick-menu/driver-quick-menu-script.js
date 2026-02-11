/**
 * ==========================================================
 * Kingdom Nexus — Driver Quick Menu Script (v1.0)
 * ----------------------------------------------------------
 * - No console logs, no browser alerts
 * - Toggle is UX-only (localStorage)
 * - Location is best-effort (geolocation -> lat/lng)
 * - Available count best-effort (GET endpoint if provided)
 * ==========================================================
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('knx-driver-quick-menu');
    if (!root) return;

    var onlineKey = root.dataset.onlineStorageKey || 'knx_driver_online_v1';
    var locKey = root.dataset.locationStorageKey || 'knx_driver_location_v1';

    var apiAvailable = root.dataset.apiAvailable || '';
    var wpRestNonce = root.dataset.wpRestNonce || '';

    var onlineLabel = document.getElementById('knxQmOnlineLabel');
    var onlineSwitch = document.getElementById('knxQmOnlineSwitch');
    var banner = document.getElementById('knxQmBanner');
    var bannerText = document.getElementById('knxQmBannerText');

    var availableCountEl = document.getElementById('knxQmAvailableCount');
    var activeCountEl = document.getElementById('knxQmActiveCount');

    var locCard = document.getElementById('knxQmLocationCard');
    var locText = document.getElementById('knxQmLocationText');

    function toast(message, type) {
      var msg = (message || '').toString().trim() || 'Something went wrong.';
      var t = (type || 'info').toString();

      if (typeof window.knxToast === 'function') {
        window.knxToast(msg, t);
        return;
      }

      // Minimal fallback
      var el = document.createElement('div');
      el.textContent = msg;
      el.style.position = 'fixed';
      el.style.left = '50%';
      el.style.transform = 'translateX(-50%)';
      el.style.bottom = '84px';
      el.style.background = 'rgba(0,0,0,0.85)';
      el.style.color = '#fff';
      el.style.padding = '10px 12px';
      el.style.borderRadius = '12px';
      el.style.fontWeight = '700';
      el.style.fontSize = '14px';
      el.style.zIndex = '999999';
      document.body.appendChild(el);
      setTimeout(function () {
        el.style.opacity = '0';
        setTimeout(function () {
          try { document.body.removeChild(el); } catch (e) {}
        }, 300);
      }, 2200);
    }

    function setOnlineUI(isOnline) {
      if (!onlineSwitch || !onlineLabel || !banner || !bannerText) return;

      onlineSwitch.setAttribute('aria-checked', isOnline ? 'true' : 'false');
      if (isOnline) onlineSwitch.classList.add('is-on');
      else onlineSwitch.classList.remove('is-on');

      onlineLabel.textContent = isOnline ? 'Online' : 'Offline';
      bannerText.textContent = isOnline ? 'You are online' : 'You are offline';

      if (isOnline) banner.classList.add('is-online');
      else banner.classList.remove('is-online');
    }

    function readOnlineState() {
      try {
        var v = localStorage.getItem(onlineKey);
        return v === '1';
      } catch (e) {
        return false;
      }
    }

    function writeOnlineState(isOnline) {
      try {
        localStorage.setItem(onlineKey, isOnline ? '1' : '0');
      } catch (e) {}
    }

    function readLocation() {
      try {
        var raw = localStorage.getItem(locKey);
        if (!raw) return null;
        var obj = JSON.parse(raw);
        if (!obj || typeof obj !== 'object') return null;
        if (!obj.text) return null;
        return obj;
      } catch (e) {
        return null;
      }
    }

    function writeLocation(obj) {
      try {
        localStorage.setItem(locKey, JSON.stringify(obj));
      } catch (e) {}
    }

    function setLocationText(text) {
      if (!locText) return;
      locText.textContent = (text || '').toString().trim() || '—';
    }

    async function fetchJson(url, opts) {
      if (!url) return { ok: false, status: 0, data: null };

      var res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}));
      var data = null;
      try { data = await res.json(); } catch (e) { data = null; }
      return { ok: res.ok, status: res.status, data: data };
    }

    async function loadAvailableCount() {
      if (!apiAvailable || !availableCountEl) return;

      // Keep it light; we only need a quick count
      var url = apiAvailable;
      if (url.indexOf('?') === -1) url += '?';
      url += (url.slice(-1) === '?' ? '' : '&') + 'limit=50&offset=0';

      var out = await fetchJson(url, {
        method: 'GET',
        headers: {
          'X-WP-Nonce': wpRestNonce || ''
        }
      });

      if (!out.ok || !out.data || out.data.success !== true) return;

      var orders = out.data && out.data.data && Array.isArray(out.data.data.orders) ? out.data.data.orders : [];
      availableCountEl.textContent = String(orders.length);
    }

    function initActiveCountPlaceholder() {
      // Quick menu doesn't own active-orders API yet; keep visual pattern.
      if (activeCountEl) activeCountEl.textContent = '—';
    }

    function wireOnlineToggle() {
      if (!onlineSwitch) return;

      onlineSwitch.addEventListener('click', function () {
        var current = readOnlineState();
        var next = !current;
        writeOnlineState(next);
        setOnlineUI(next);
      });
    }

    function wireLocationCard() {
      if (!locCard) return;

      locCard.addEventListener('click', function () {
        if (!navigator.geolocation) {
          toast('Geolocation not supported on this device.', 'error');
          return;
        }

        locCard.disabled = true;
        locCard.classList.add('is-loading');

        navigator.geolocation.getCurrentPosition(function (pos) {
          var lat = pos.coords.latitude;
          var lng = pos.coords.longitude;

          var text = lat.toFixed(6) + ', ' + lng.toFixed(6);
          setLocationText(text);

          writeLocation({
            text: text,
            lat: lat,
            lng: lng,
            at: Date.now()
          });

          toast('Location updated.', 'success');

          locCard.disabled = false;
          locCard.classList.remove('is-loading');
        }, function () {
          toast('Unable to get location.', 'error');
          locCard.disabled = false;
          locCard.classList.remove('is-loading');
        }, {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 0
        });
      });
    }

    // Init
    (function init() {
      var isOnline = readOnlineState();
      setOnlineUI(isOnline);

      var loc = readLocation();
      if (loc && loc.text) setLocationText(loc.text);
      else setLocationText('—');

      initActiveCountPlaceholder();
      loadAvailableCount();

      wireOnlineToggle();
      wireLocationCard();
    })();
  });
})();
