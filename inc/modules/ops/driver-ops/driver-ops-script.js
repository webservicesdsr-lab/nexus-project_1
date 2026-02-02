// File: inc/modules/ops/driver-ops/driver-ops-script.js
/**
 * KNX DRIVER OPS — Available Orders Script (Production)
 *
 * Canon behaviors:
 * - Single list: available orders only.
 * - One expandable panel open at a time.
 * - Self-assign (auto-assign) action from the expand panel.
 * - Polling with abort, fail-closed UX (no noisy logs).
 *
 * Expected REST shapes (best-effort):
 * - Array of orders
 * - { success, data: { orders: [...] } }
 * - { orders: [...] }
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var app = document.getElementById('knxDriverOpsApp');
    if (!app) return;

    var apiUrl = app.dataset.apiUrl || '';
    var assignUrl = app.dataset.assignUrl || '';
    var viewOrderUrl = app.dataset.viewOrderUrl || '/view-order';
    var nonce = (app.dataset.nonce || '').trim();
    var pollMs = parseInt(app.dataset.pollMs || '12000', 10);
    if (!isFinite(pollMs) || pollMs < 6000) pollMs = 12000;
    if (pollMs > 60000) pollMs = 60000;

    var stateLine = document.getElementById('knxDOState');
    var listNode = document.getElementById('knxDOList');
    var pulse = document.getElementById('knxDOPulse');
    var refreshBtn = document.getElementById('knxDORefreshBtn');
    var toastWrap = document.getElementById('knxDOToasts');

    var LS_EXPANDED = 'knx_driver_ops_expanded';
    var LS_SEEN = 'knx_driver_ops_seen';

    var expandedOrderId = loadExpanded();
    var polling = false;
    var timer = null;
    var abortController = null;
    var lastHash = '';

    function escapeHtml(str) {
      return String(str == null ? '' : str).replace(/[&<>"]/g, function (s) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[s];
      });
    }

    function toast(title, msg) {
      if (!toastWrap) return;
      var el = document.createElement('div');
      el.className = 'knx-do-toast';
      el.innerHTML = '<strong>' + escapeHtml(title) + '</strong><div>' + escapeHtml(msg) + '</div>';
      toastWrap.appendChild(el);
      setTimeout(function () {
        try { el.remove(); } catch (e) {}
      }, 3200);
    }

    function setState(text) {
      if (!stateLine) return;
      stateLine.textContent = String(text || '');
    }

    function setPulse(on) {
      if (!pulse) return;
      pulse.style.opacity = on ? '1' : '0.85';
    }

    function getHeaders(isJson) {
      var h = { 'Accept': 'application/json' };
      if (isJson) h['Content-Type'] = 'application/json';
      if (nonce) h['X-WP-Nonce'] = nonce;
      return h;
    }

    function parseOrdersPayload(json) {
      if (json && typeof json === 'object') {
        if (json.data && Array.isArray(json.data.orders)) return json.data.orders;
        if (Array.isArray(json.orders)) return json.orders;
        if (Array.isArray(json.results)) return json.results;
      }
      return Array.isArray(json) ? json : [];
    }

    function statusLabel(status) {
      var st = String(status || '').toLowerCase();
      var map = {
        placed: 'Placed',
        confirmed: 'Confirmed',
        preparing: 'Preparing',
        assigned: 'Assigned',
        in_progress: 'In progress',
        completed: 'Completed',
        cancelled: 'Cancelled'
      };
      return map[st] || (st ? st.replace(/_/g, ' ') : 'Status');
    }

    function statusClass(status) {
      var st = String(status || '').toLowerCase();
      if (st === 'placed' || st === 'confirmed') return 'is-new';
      if (st === 'assigned' || st === 'preparing' || st === 'in_progress') return 'is-busy';
      return '';
    }

    function money(n) {
      var v = Number(n || 0);
      if (!isFinite(v)) v = 0;
      return v.toFixed(2);
    }

    function buildViewUrl(orderId) {
      var oid = Number(orderId || 0);
      if (!isFinite(oid) || oid <= 0) oid = 0;

      try {
        var u = new URL(viewOrderUrl, window.location.origin);
        u.searchParams.set('order_id', String(oid));
        return u.toString();
      } catch (e) {
        // Fallback (no logging)
        return viewOrderUrl + (viewOrderUrl.indexOf('?') >= 0 ? '&' : '?') + 'order_id=' + encodeURIComponent(String(oid));
      }
    }

    function loadExpanded() {
      try {
        var v = localStorage.getItem(LS_EXPANDED);
        var n = Number(v || 0);
        return (isFinite(n) && n > 0) ? n : 0;
      } catch (e) { return 0; }
    }

    function saveExpanded(n) {
      try {
        if (n > 0) localStorage.setItem(LS_EXPANDED, String(n));
        else localStorage.removeItem(LS_EXPANDED);
      } catch (e) {}
    }

    function getSeenSet() {
      try {
        var raw = localStorage.getItem(LS_SEEN);
        var arr = raw ? JSON.parse(raw) : [];
        if (!Array.isArray(arr)) arr = [];
        var set = {};
        for (var i = 0; i < arr.length; i++) set[String(arr[i])] = true;
        return set;
      } catch (e) {
        return {};
      }
    }

    function saveSeenSet(set) {
      try {
        var keys = Object.keys(set || {});
        if (keys.length > 1200) keys = keys.slice(keys.length - 1200);
        localStorage.setItem(LS_SEEN, JSON.stringify(keys));
      } catch (e) {}
    }

    function animateOpen(panel) {
      if (!panel) return;
      panel.style.display = 'block';
      panel.style.height = '0px';
      panel.offsetHeight;
      var target = panel.scrollHeight;
      panel.style.height = target + 'px';

      var onEnd = function () {
        panel.removeEventListener('transitionend', onEnd);
        panel.style.height = 'auto';
      };
      panel.addEventListener('transitionend', onEnd);
    }

    function animateClose(panel) {
      if (!panel) return;
      var current = panel.scrollHeight;
      panel.style.height = current + 'px';
      panel.offsetHeight;
      panel.style.height = '0px';

      var onEnd = function () {
        panel.removeEventListener('transitionend', onEnd);
      };
      panel.addEventListener('transitionend', onEnd);
    }

    function closeExpandedInDom() {
      if (!expandedOrderId) return;
      var item = app.querySelector('.knx-do-item[data-order-id="' + expandedOrderId + '"]');
      if (!item) return;
      var panel = item.querySelector('.knx-do-expand');
      item.classList.remove('is-expanded');
      if (panel) animateClose(panel);
    }

    function openExpandedInDom(orderId, animate) {
      var item = app.querySelector('.knx-do-item[data-order-id="' + orderId + '"]');
      if (!item) return;
      var panel = item.querySelector('.knx-do-expand');

      item.classList.add('is-expanded');
      if (!panel) return;

      panel.style.display = 'block';
      panel.style.overflow = 'hidden';

      if (animate) animateOpen(panel);
      else panel.style.height = 'auto';
    }

    function toggleExpand(orderId) {
      var oid = Number(orderId || 0);
      if (!isFinite(oid) || oid <= 0) return;

      if (expandedOrderId === oid) {
        closeExpandedInDom();
        expandedOrderId = 0;
        saveExpanded(0);
        return;
      }

      if (expandedOrderId) closeExpandedInDom();

      expandedOrderId = oid;
      saveExpanded(oid);
      openExpandedInDom(oid, true);
    }

    function renderOrders(orders, opts) {
      var options = opts || {};
      var data = Array.isArray(orders) ? orders : [];

      var hash = '';
      try {
        hash = data.map(function (o) {
          return String(o.order_id || '') + ':' + String(o.status || '') + ':' + String(o.created_at || o.created_human || '') + ':' + String(o.total_amount || o.total || '');
        }).join('|');
      } catch (e) {
        hash = String(Date.now());
      }

      if (hash && hash === lastHash && !options.force) {
        // Restore expanded without re-render
        if (expandedOrderId > 0) openExpandedInDom(expandedOrderId, false);
        return;
      }
      lastHash = hash;

      if (!listNode) return;

      listNode.innerHTML = '';

      if (data.length === 0) {
        listNode.innerHTML = '<div class="knx-do-empty">No available orders right now.</div>';
        setState('No available orders.');
        expandedOrderId = 0;
        saveExpanded(0);
        return;
      }

      var seen = getSeenSet();
      var nowNewCount = 0;

      data.forEach(function (it) {
        var oid = Number(it.order_id || 0);
        if (!isFinite(oid) || oid <= 0) return;

        var restaurant = escapeHtml(it.restaurant_name || it.hub_name || 'Restaurant');
        var created = escapeHtml(it.created_human || it.created_at || '');
        var st = String(it.status || '');
        var stLabel = escapeHtml(statusLabel(st));
        var stCls = statusClass(st);

        var customer = escapeHtml(it.customer_name || 'Customer');
        var total = money(it.total_amount || it.total || 0);
        var tip = money(it.tip_amount || it.tip || 0);

        var mapUrl = String(it.view_location_url || it.map_url || '');
        var thumbUrl = String(it.hub_thumbnail || it.logo_url || '');

        var isNewForYou = !seen[String(oid)];
        if (isNewForYou) nowNewCount++;

        var itemEl = document.createElement('div');
        itemEl.className = 'knx-do-item';
        itemEl.setAttribute('data-order-id', String(oid));

        var thumbHtml = thumbUrl
          ? '<div class="knx-do-thumb"><img src="' + escapeHtml(thumbUrl) + '" alt="" loading="lazy" /></div>'
          : '<div class="knx-do-thumb" aria-hidden="true"></div>';

        var viewUrl = buildViewUrl(oid);

        // Expand actions:
        // - Map (red) optional
        // - Accept (primary) (self-assign)
        // - View Order (blue)
        itemEl.innerHTML =
          '<div class="knx-do-row" role="button" tabindex="0">' +
            '<a class="knx-do-idview" data-action="open-order" href="' + escapeHtml(viewUrl) + '">#' + escapeHtml(oid) + '</a>' +
            thumbHtml +
            '<div class="knx-do-main">' +
              '<div class="knx-do-restaurant">' + restaurant + '</div>' +
              '<div class="knx-do-time">' + created + '</div>' +
            '</div>' +
            '<div class="knx-do-status ' + escapeHtml(stCls) + '">' + stLabel + '</div>' +
            '<div class="knx-do-chevron" aria-hidden="true">' +
              '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                '<path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
              '</svg>' +
            '</div>' +
          '</div>' +

          '<div class="knx-do-expand" aria-hidden="true">' +
            '<div class="knx-do-expand__inner">' +
              '<div class="knx-do-detail">' +
                '<div class="knx-do-detail__title">Customer</div>' +
                '<div class="knx-do-detail__value">' + customer + '</div>' +
                '<div class="knx-do-detail__sub">' + (isNewForYou ? 'New for you' : ' ') + '</div>' +
              '</div>' +

              '<div class="knx-do-detail">' +
                '<div class="knx-do-detail__title">Totals</div>' +
                '<div class="knx-do-detail__value">$' + escapeHtml(total) + '</div>' +
                '<div class="knx-do-detail__sub">Tip: $' + escapeHtml(tip) + '</div>' +
              '</div>' +

              '<div class="knx-do-actions">' +
                (mapUrl
                  ? '<a class="knx-do-action knx-do-action--danger" href="' + escapeHtml(mapUrl) + '" target="_blank" rel="noopener">Map</a>'
                  : '<span class="knx-do-action knx-do-action--muted">No map</span>'
                ) +
                '<button type="button" class="knx-do-action knx-do-action--primary" data-action="accept" data-order-id="' + escapeHtml(oid) + '">Accept</button>' +
                '<a class="knx-do-action knx-do-action--blue" data-action="open-order" href="' + escapeHtml(viewUrl) + '">View order</a>' +
              '</div>' +
            '</div>' +
          '</div>';

        var row = itemEl.querySelector('.knx-do-row');
        var panel = itemEl.querySelector('.knx-do-expand');

        if (panel) panel.style.height = '0px';

        if (row) {
          row.addEventListener('click', function (ev) {
            var t = ev.target;
            if (t && t.closest && t.closest('[data-action="open-order"]')) return;
            toggleExpand(oid);
          });

          row.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
              ev.preventDefault();
              toggleExpand(oid);
            }
          });
        }

        listNode.appendChild(itemEl);
      });

      // Mark seen (available list) after render
      data.forEach(function (o) {
        var id = Number(o.order_id || 0);
        if (isFinite(id) && id > 0) seen[String(id)] = true;
      });
      saveSeenSet(seen);

      setState('Showing ' + data.length + ' available order' + (data.length === 1 ? '' : 's') + '.');

      // Restore expanded panel without animation after re-render
      if (expandedOrderId > 0) {
        var exists = data.some(function (o) { return Number(o.order_id || 0) === expandedOrderId; });
        if (!exists) {
          expandedOrderId = 0;
          saveExpanded(0);
        } else {
          openExpandedInDom(expandedOrderId, false);
        }
      }
    }

    function wireAcceptButtons() {
      if (!listNode) return;

      listNode.querySelectorAll('[data-action="accept"]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
          ev.preventDefault();
          ev.stopPropagation();

          var oid = Number(btn.getAttribute('data-order-id') || 0);
          if (!isFinite(oid) || oid <= 0) return;

          if (!assignUrl) {
            toast('Error', 'Assign endpoint is not configured.');
            return;
          }

          btn.disabled = true;
          btn.textContent = 'Accepting…';

          fetch(assignUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(true),
            body: JSON.stringify({ order_id: oid })
          })
            .then(function (res) {
              return res.json().catch(function () { return {}; }).then(function (json) {
                if (!res.ok) {
                  var msg = (json && (json.message || json.error)) ? (json.message || json.error) : ('HTTP ' + res.status);
                  throw new Error(msg);
                }
                return json;
              });
            })
            .then(function (json) {
              // Best-effort success detection
              var ok = false;
              if (json && typeof json === 'object') {
                if (json.success === true) ok = true;
                if (json.assigned === true) ok = true;
                if (json.data && json.data.assigned === true) ok = true;
              }

              if (ok) toast('Assigned', 'Order accepted.');
              else toast('Info', 'No change.');

              fetchOnce(true);
            })
            .catch(function (err) {
              toast('Error', err && err.message ? err.message : 'Failed to accept order.');
              btn.disabled = false;
              btn.textContent = 'Accept';
            });
        });
      });
    }

    function fetchOnce(force) {
      if (!apiUrl) {
        setState('Driver API is not configured.');
        if (listNode) listNode.innerHTML = '<div class="knx-do-empty">Unable to load orders.</div>';
        return;
      }

      if (abortController) {
        try { abortController.abort(); } catch (e) {}
        abortController = null;
      }
      abortController = new AbortController();

      setPulse(true);

      fetch(apiUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: getHeaders(false),
        signal: abortController.signal
      })
        .then(function (res) {
          return res.json().catch(function () { return {}; }).then(function (json) {
            if (!res.ok) {
              var msg = (json && (json.message || json.error)) ? (json.message || json.error) : ('HTTP ' + res.status);
              throw new Error(msg);
            }
            return json;
          });
        })
        .then(function (json) {
          var orders = parseOrdersPayload(json);

          // Keep only "available" best-effort if API leaks assigned rows:
          orders = (Array.isArray(orders) ? orders : []).filter(function (o) {
            // If assigned_to_you exists and is true, not "available" anymore.
            if (typeof o.assigned_to_you === 'boolean' && o.assigned_to_you) return false;
            // If assigned_driver_user_id exists (taken), not available.
            if (o.assigned_driver_user_id != null) return false;
            return true;
          });

          renderOrders(orders, { force: !!force });
          wireAcceptButtons();
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          setState('Unable to load available orders.');
          if (listNode) listNode.innerHTML = '<div class="knx-do-empty">Unable to load orders. Please refresh.</div>';
          toast('Error', err && err.message ? err.message : 'Request failed.');
        })
        .finally(function () {
          setPulse(false);
        });
    }

    function pollLoop() {
      if (polling) return;
      polling = true;

      fetchOnce(false);

      polling = false;
      if (timer) clearTimeout(timer);
      timer = setTimeout(pollLoop, pollMs);
    }

    function start() {
      if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
          fetchOnce(true);
        });
      }

      // Initial
      fetchOnce(true);

      // Poll
      if (timer) clearTimeout(timer);
      timer = setTimeout(pollLoop, pollMs);

      window.addEventListener('beforeunload', function () {
        if (timer) { clearTimeout(timer); timer = null; }
        if (abortController) { try { abortController.abort(); } catch (e) {} abortController = null; }
      });
    }

    start();
  });
})();
