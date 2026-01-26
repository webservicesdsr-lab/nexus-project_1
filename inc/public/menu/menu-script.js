/**
 * ==========================================================
 * Kingdom Nexus - Menu Script (Local Bites v1 - Production)
 * Scoped to #olc-menu
 * - Category chips + search (mobile & desktop)
 * - Item modal with modifiers, quantity and notes
 * - Add To Cart guarded by Availability (Nexus modal)
 * ==========================================================
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('#olc-menu');
    if (!root) return;

    initTabs(root);
    initFilter(root);
    initAvailabilityModal(root);
    initModal(root);
  });

  /* ==========================================================
   * AVAILABILITY DATA (from PHP data-availability)
   * ========================================================== */
  function getAvailability(root) {
    try {
      var raw = root.getAttribute('data-availability');
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  /* ==========================================================
   * AVAILABILITY MODAL (Nexus style, like Explore)
   * ========================================================== */
  var countdownTimer = null;

  function initAvailabilityModal(root) {
    var modal = document.getElementById('knx-availability-modal');
    if (!modal) return;

    var iconEl = document.getElementById('knxAvailIcon');
    var titleEl = document.getElementById('knxAvailTitle');
    var msgEl = document.getElementById('knxAvailMessage');
    var cdEl = document.getElementById('knxAvailCountdown');

    var backdrop = modal.querySelector('.knx-avail-backdrop');
    var closeBtn = modal.querySelector('.knx-avail-close');
    var xBtn = modal.querySelector('.knx-avail-x');

    function stopCountdown() {
      if (countdownTimer) {
        window.clearInterval(countdownTimer);
        countdownTimer = null;
      }
    }

    function startCountdown(reopenAtIso) {
      if (!cdEl) return;

      var target = new Date(reopenAtIso).getTime();
      if (!isFinite(target)) return;

      cdEl.style.display = 'block';

      function tick() {
        var now = Date.now();
        var d = target - now;

        if (d <= 0) {
          cdEl.textContent = 'Reopening soon…';
          stopCountdown();
          return;
        }

        var totalSeconds = Math.floor(d / 1000);
        var h = Math.floor(totalSeconds / 3600);
        var m = Math.floor((totalSeconds % 3600) / 60);
        var s = totalSeconds % 60;

        var hh = String(h).padStart(2, '0');
        var mm = String(m).padStart(2, '0');
        var ss = String(s).padStart(2, '0');

        cdEl.textContent = 'Reopens in ' + hh + ':' + mm + ':' + ss;
      }

      tick();
      countdownTimer = window.setInterval(tick, 1000);
    }

    function openModal() {
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      stopCountdown();
      if (cdEl) {
        cdEl.style.display = 'none';
        cdEl.textContent = '';
      }
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    function goExplore() {
      // Safe navigation: prefer same-origin referrer (Explore page), else go home.
      try {
        var ref = document.referrer || '';
        if (ref && ref.indexOf(window.location.origin) === 0) {
          window.location.href = ref;
          return;
        }
      } catch (e) {}
      window.location.href = '/';
    }

    function showAvailabilityModal(payload) {
      payload = payload || {};
      var name = payload.name || (root.getAttribute('data-hub-name') || 'This restaurant');
      var availability = payload.availability || getAvailability(root) || {};
      var reason = availability.reason || 'UNKNOWN';
      var reopenAt = availability.reopen_at || null;

      var closureReason = '';
      try {
        closureReason = (root.getAttribute('data-closure-reason') || '').trim();
      } catch (e) {
        closureReason = '';
      }

      stopCountdown();
      if (cdEl) {
        cdEl.style.display = 'none';
        cdEl.textContent = '';
      }

      var icon = '⏸️';
      var title = name + ' is unavailable';
      var message = 'Please check back later or explore other local spots.';

      if (reason === 'HUB_TEMP_CLOSED') {
        icon = '⏰';
        title = name + ' is temporarily closed';
        message = closureReason ? closureReason : (availability.message || 'We’ll be back soon.');
        if (reopenAt) startCountdown(reopenAt);
      } else if (reason === 'HUB_CLOSED_INDEFINITELY') {
        icon = '🔒';
        title = name + ' is temporarily closed';
        message = closureReason ? closureReason : (availability.message || 'Please check back later.');
      } else if (reason === 'HUB_OUTSIDE_HOURS') {
        icon = '🌙';
        title = name + ' is closed right now';
        message = availability.message || 'We’re currently outside operating hours. Please check back later.';
      } else if (reason === 'HUB_CLOSING_SOON') {
        icon = '🕗';
        title = name + ' is closed right now';
        message = availability.message || 'Orders are paused near closing time. Please try another restaurant for now.';
      } else if (reason === 'HUB_NO_HOURS_SET') {
        icon = 'ℹ️';
        title = name + ' is closed right now';
        message = availability.message || 'Hours aren’t available right now. Please check back later.';
      } else if (reason === 'CITY_NOT_OPERATIONAL') {
        icon = '⛔';
        title = 'Ordering is paused in this city';
        message = availability.message || 'Orders are temporarily paused in your area. Please check back later.';
      } else if (reason === 'CITY_INACTIVE') {
        icon = '📍';
        title = 'This area is currently unavailable';
        message = availability.message || 'We’re not accepting orders in this location right now.';
      } else if (reason === 'HUB_INACTIVE') {
        icon = '🚫';
        title = name + ' is unavailable';
        message = availability.message || 'This restaurant is currently unavailable.';
      }

      if (iconEl) iconEl.textContent = icon;
      if (titleEl) titleEl.textContent = title;
      if (msgEl) msgEl.textContent = message;

      openModal();
    }

    // Expose for internal calls
    window.showAvailabilityModal = showAvailabilityModal;
    window.closeAvailabilityModal = closeModal;

    if (backdrop) backdrop.addEventListener('click', closeModal);
    if (xBtn) xBtn.addEventListener('click', closeModal);

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        closeModal();
        goExplore();
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
        closeModal();
      }
    });
  }

  /* ==========================================================
   * TABS (prepared for future, safe if not present)
   * ========================================================== */
  function initTabs(root) {
    var tabs = root.querySelectorAll('.knx-menu__tab');
    var contents = root.querySelectorAll('.knx-menu__tab-content');
    if (!tabs.length || !contents.length) return;

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var target = tab.getAttribute('data-tab');

        tabs.forEach(function (t) { t.classList.remove('active'); });
        contents.forEach(function (c) { c.classList.remove('active'); });

        tab.classList.add('active');
        var panel = root.querySelector('.knx-menu__tab-content[data-content="' + target + '"]');
        if (panel) panel.classList.add('active');
      });
    });
  }

  /* ==========================================================
   * FILTER (chips + search mobile/desktop)
   * ========================================================== */
  function initFilter(root) {
    var chips = root.querySelectorAll('.knx-menu__category-chip');
    var cards = root.querySelectorAll('.knx-menu__card');
    var empty = root.querySelector('#knxMenuEmpty');

    var searchMobile  = root.querySelector('#knxMenuSearchMobile');
    var searchDesktop = root.querySelector('#knxMenuSearchDesktop');

    var currentQuery = '';

    function getActiveCategory() {
      var activeChip = root.querySelector('.knx-menu__category-chip.active');
      return activeChip ? activeChip.getAttribute('data-category') : 'All';
    }

    function applyFilter() {
      var category = getActiveCategory();
      var q = currentQuery;
      var visible = 0;

      cards.forEach(function (card) {
        var cat  = card.getAttribute('data-category') || '';
        var name = (card.getAttribute('data-name') || '').toLowerCase();
        var desc = (card.getAttribute('data-description') || '').toLowerCase();

        var matchCat    = category === 'All' || category === cat;
        var matchSearch = !q || name.indexOf(q) !== -1 || desc.indexOf(q) !== -1;

        if (matchCat && matchSearch) {
          card.style.display = '';
          visible++;
        } else {
          card.style.display = 'none';
        }
      });

      if (empty) {
        empty.style.display = visible ? 'none' : 'block';
      }
    }

    function syncAndFilter(sourceInput) {
      var value = (sourceInput && sourceInput.value ? sourceInput.value : '').toLowerCase().trim();
      currentQuery = value;

      if (searchMobile && sourceInput !== searchMobile) {
        searchMobile.value = value;
      }
      if (searchDesktop && sourceInput !== searchDesktop) {
        searchDesktop.value = value;
      }

      applyFilter();
    }

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        chips.forEach(function (c) { c.classList.remove('active'); });
        chip.classList.add('active');
        applyFilter();
      });
    });

    var timerMobile = null;
    var timerDesktop = null;

    if (searchMobile) {
      searchMobile.addEventListener('input', function () {
        if (timerMobile) window.clearTimeout(timerMobile);
        timerMobile = window.setTimeout(function () {
          syncAndFilter(searchMobile);
        }, 180);
      });
    }

    if (searchDesktop) {
      searchDesktop.addEventListener('input', function () {
        if (timerDesktop) window.clearTimeout(timerDesktop);
        timerDesktop = window.setTimeout(function () {
          syncAndFilter(searchDesktop);
        }, 180);
      });
    }
  }

  /* ==========================================================
   * MODAL
   * ========================================================== */
  function initModal(root) {
    var modal      = root.querySelector('#knxMenuModal');
    var modalBody  = root.querySelector('#knxMenuModalBody');
    var modalClose = root.querySelector('#knxMenuModalClose');
    var cards      = root.querySelectorAll('.knx-menu__card');

    if (!modal || !modalBody || !modalClose) return;

    var hubId   = root.getAttribute('data-hub-id') || null;
    var hubName = root.getAttribute('data-hub-name') || '';

    function openModal() {
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.style.display = 'none';
      modalBody.innerHTML = '';
      document.body.style.overflow = '';
    }

    modalClose.addEventListener('click', function () {
      closeModal();
    });

    modal.addEventListener('click', function (e) {
      if (e.target.classList.contains('knx-menu__modal-backdrop')) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.style.display === 'flex') {
        closeModal();
      }
    });

    cards.forEach(function (card) {
      card.addEventListener('click', function () {
        var ds = card.dataset;
        var price = parseFloat(ds.itemPrice || '0');
        var modifiers = [];

        try {
          modifiers = JSON.parse(ds.itemModifiers || '[]') || [];
        } catch (e) {
          modifiers = [];
        }

        var meta = {
          hubId: hubId,
          hubName: hubName,
          itemId: ds.itemId || null,
          itemName: ds.itemName || '',
          itemImage: ds.itemImage || '',
          itemDesc: ds.itemDesc || ''
        };

        buildModalBody(modalBody, {
          name: meta.itemName,
          img: meta.itemImage,
          desc: meta.itemDesc,
          basePrice: price,
          modifiers: modifiers
        });

        openModal();
        attachModalLogic(modalBody, price, modifiers, meta, closeModal, root);
      });
    });
  }

  /* ==========================================================
   * BUILD MODAL HTML
   * ========================================================== */
  function buildModalBody(container, data) {
    var basePrice = data.basePrice || 0;
    var html = '';

    if (data.img) {
      html +=
        '<div class="knx-modal-hero">' +
          '<img src="' + esc(data.img) + '" alt="' + esc(data.name || '') + '">' +
        '</div>';
    }

    html +=
      '<div class="knx-modal-header-card">' +
        '<h2 class="knx-modal-title">' + esc(data.name || '') + '</h2>' +
        (data.desc ? '<p class="knx-modal-desc">' + esc(data.desc) + '</p>' : '') +
        '<div class="knx-modal-price-main">$ ' +
          '<span id="knxModalBasePrice">' + basePrice.toFixed(2) + '</span>' +
        '</div>' +
      '</div>';

    (data.modifiers || []).forEach(function (mod) {
      var requiredText = mod.required ? '1 Required' : 'Optional';

      html +=
        '<section class="knx-modal-group" data-mod-id="' + esc(mod.id) + '" data-type="' + esc(mod.type || 'single') + '">' +
          '<div class="knx-modal-group-header">' +
            '<h3 class="knx-modal-group-title">' + esc(mod.name || '') + '</h3>' +
            '<span class="knx-modal-group-required">' + esc(requiredText) + '</span>' +
          '</div>' +
          '<div class="knx-modal-options">';

      (mod.options || []).forEach(function (opt) {
        var adj = parseFloat(opt.price_adjustment || 0);

        html +=
          '<button type="button" class="knx-modal-option"' +
            ' data-option-id="' + esc(opt.id) + '"' +
            ' data-price="' + adj.toFixed(2) + '">' +
            '<div class="knx-modal-option-label">' + esc(opt.name || '') + '</div>' +
            '<div class="knx-modal-option-cta">' +
              '<span class="knx-modal-option-cta-main">Tap to select +</span>' +
              '<strong class="knx-modal-option-cta-price">$' + adj.toFixed(2) + '</strong>' +
            '</div>' +
          '</button>';
      });

      html += '</div></section>';
    });

    html +=
      '<div class="knx-modal-notes">' +
        '<label for="knxModalNotes">Special instructions</label>' +
        '<textarea id="knxModalNotes" placeholder="e.g. No mayo"></textarea>' +
      '</div>' +

      '<div class="knx-modal-footer">' +
        '<div class="knx-modal-qty-row">' +
          '<span>Quantity:</span>' +
          '<button type="button" class="knx-modal-qty-btn" data-qty="-1">-</button>' +
          '<span id="knxModalQty">1</span>' +
          '<button type="button" class="knx-modal-qty-btn" data-qty="1">+</button>' +
        '</div>' +

        '<div class="knx-modal-total-row">' +
          'Total = $ <span id="knxModalTotal">' + basePrice.toFixed(2) + '</span>' +
        '</div>' +

        '<button type="button" class="knx-modal-add-btn" id="knxModalAddBtn">Add To Cart</button>' +
      '</div>';

    container.innerHTML = html;
  }

  /* ==========================================================
   * MODAL LOGIC + ADD TO CART (Availability guarded)
   * ========================================================== */
  function attachModalLogic(container, basePrice, modifiers, meta, closeModalFn, root) {
    var qty = 1;
    var selectedSingles = {};
    var selectedMultiples = {};

    container.querySelectorAll('.knx-modal-group').forEach(function (groupEl) {
      var modId = groupEl.getAttribute('data-mod-id');
      var type = groupEl.getAttribute('data-type');

      groupEl.querySelectorAll('.knx-modal-option').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var optId = btn.getAttribute('data-option-id');

          if (type === 'single') {
            groupEl.querySelectorAll('.knx-modal-option').forEach(function (b) {
              b.classList.remove('is-selected');
              var span = b.querySelector('.knx-modal-option-cta-main');
              if (span) span.textContent = 'Tap to select +';
            });

            btn.classList.add('is-selected');
            var labelSingle = btn.querySelector('.knx-modal-option-cta-main');
            if (labelSingle) labelSingle.textContent = 'SELECTED';
            selectedSingles[String(modId)] = String(optId);

          } else {
            var key = String(modId);
            if (!selectedMultiples[key]) selectedMultiples[key] = new Set();
            var set = selectedMultiples[key];

            var isActive = set.has(String(optId));
            if (isActive) {
              set.delete(String(optId));
              btn.classList.remove('is-selected');
              var spanOff = btn.querySelector('.knx-modal-option-cta-main');
              if (spanOff) spanOff.textContent = 'Tap to select +';
            } else {
              set.add(String(optId));
              btn.classList.add('is-selected');
              var spanOn = btn.querySelector('.knx-modal-option-cta-main');
              if (spanOn) spanOn.textContent = 'SELECTED';
            }
          }

          updateTotal();
        });
      });
    });

    var qtyLabel = container.querySelector('#knxModalQty');
    container.querySelectorAll('.knx-modal-qty-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var delta = parseInt(btn.getAttribute('data-qty'), 10);
        var newQty = qty + delta;
        if (newQty < 1) return;

        qty = newQty;
        if (qtyLabel) qtyLabel.textContent = String(qty);
        updateTotal();
      });
    });

    function computeSingleQuantityTotal() {
      var total = basePrice;

      (modifiers || []).forEach(function (mod) {
        var modId = String(mod.id);

        if (mod.type === 'single') {
          var selectedId = selectedSingles[modId];
          if (selectedId) {
            var opt = (mod.options || []).find(function (o) {
              return String(o.id) === String(selectedId);
            });
            if (opt) total += parseFloat(opt.price_adjustment || 0);
          }
        } else {
          var set = selectedMultiples[modId];
          if (set && set.size) {
            set.forEach(function (optId) {
              var opt = (mod.options || []).find(function (o) {
                return String(o.id) === String(optId);
              });
              if (opt) total += parseFloat(opt.price_adjustment || 0);
            });
          }
        }
      });

      return total;
    }

    function updateTotal() {
      var perUnit = computeSingleQuantityTotal();
      var total = perUnit * qty;
      var totalEl = container.querySelector('#knxModalTotal');
      if (totalEl) totalEl.textContent = total.toFixed(2);
    }

    updateTotal();

    var addBtn = container.querySelector('#knxModalAddBtn');
    if (!addBtn) return;

    addBtn.addEventListener('click', function () {
      // Availability guard happens exactly at Add To Cart.
      var availability = getAvailability(root);
      if (availability && availability.can_order === false) {
        if (typeof closeModalFn === 'function') closeModalFn();
        if (typeof window.showAvailabilityModal === 'function') {
          window.showAvailabilityModal({
            name: (meta && meta.hubName) ? meta.hubName : (root.getAttribute('data-hub-name') || 'This restaurant'),
            availability: availability
          });
        }
        return;
      }

      var badGroup = validateRequiredGroups();
      if (badGroup) {
        badGroup.scrollIntoView({ behavior: 'smooth', block: 'center' });
        badGroup.classList.add('required-error');

        var old = badGroup.querySelector('.knx-modal-required-msg');
        if (old && old.parentNode) old.parentNode.removeChild(old);

        var msg = document.createElement('div');
        msg.className = 'knx-modal-required-msg';
        msg.textContent = 'Please select one option';
        badGroup.appendChild(msg);

        window.setTimeout(function () {
          badGroup.classList.remove('required-error');
        }, 1800);

        return;
      }

      var notesEl = container.querySelector('#knxModalNotes');
      var notes = notesEl ? notesEl.value.trim() : '';

      var lineTotalEl = container.querySelector('#knxModalTotal');
      var lineTotal = lineTotalEl ? parseFloat(lineTotalEl.textContent || '0') : 0;
      if (!isFinite(lineTotal)) lineTotal = 0;

      var modifiersPayload = buildSelectedModifiersPayload(modifiers, selectedSingles, selectedMultiples);
      var perUnit = computeSingleQuantityTotal();

      var cartItem = {
        id: generateCartItemId(),
        hub_id: meta && meta.hubId ? parseInt(meta.hubId, 10) || null : null,
        hub_name: meta && meta.hubName ? String(meta.hubName) : '',
        item_id: meta && meta.itemId ? meta.itemId : null,
        name: meta && meta.itemName ? String(meta.itemName) : '',
        image: meta && meta.itemImage ? String(meta.itemImage) : '',
        description: meta && meta.itemDesc ? String(meta.itemDesc) : '',
        base_price: basePrice,
        unit_price_with_modifiers: perUnit,
        quantity: qty,
        notes: notes,
        line_total: lineTotal,
        modifiers: modifiersPayload
      };

      var cart = readCart();
      cart.push(cartItem);
      saveCart(cart);
      syncCartServer(cart);

      if (typeof closeModalFn === 'function') {
        closeModalFn();
      }
    });

    function validateRequiredGroups() {
      var groups = container.querySelectorAll('.knx-modal-group');

      for (var i = 0; i < groups.length; i++) {
        var group = groups[i];
        var modId = group.getAttribute('data-mod-id');
        var mod = (modifiers || []).find(function (m) {
          return String(m.id) === String(modId);
        });

        if (!mod || !mod.required) continue;

        if (mod.type === 'single') {
          if (!selectedSingles[String(modId)]) return group;
        } else {
          var set = selectedMultiples[String(modId)];
          if (!set || !set.size) return group;
        }
      }

      return null;
    }
  }

  /* ==========================================================
   * HELPERS - CART STORAGE (localStorage + DB sync)
   * ========================================================== */
  function readCart() {
    try {
      var raw = window.localStorage.getItem('knx_cart');
      if (!raw) return [];
      var parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];
      return parsed;
    } catch (e) {
      return [];
    }
  }

  function saveCart(cartArray) {
    try {
      window.localStorage.setItem('knx_cart', JSON.stringify(cartArray || []));
    } catch (e) {}

    try {
      var ev = new Event('knx-cart-updated');
      window.dispatchEvent(ev);
    } catch (e2) {
      if (typeof document.createEvent === 'function') {
        var evt = document.createEvent('Event');
        evt.initEvent('knx-cart-updated', true, true);
        window.dispatchEvent(evt);
      }
    }
  }

  function getCartToken() {
    var cookieName = 'knx_cart_token';
    var existing = getCookie(cookieName);
    if (existing) return existing;

    var token = 'knx_' + Math.random().toString(16).slice(2) + Date.now().toString(16);
    var maxAge = 60 * 60 * 24 * 30;

    document.cookie =
      cookieName + '=' + encodeURIComponent(token) +
      '; Max-Age=' + maxAge +
      '; Path=/' +
      '; SameSite=Lax';

    return token;
  }

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([$?*|{}\]\\\/\+\^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function syncCartServer(cartArray) {
    try {
      var cart = Array.isArray(cartArray) ? cartArray : readCart();
      if (!cart.length) return;

      var subtotal = 0;
      var hubId = null;

      var itemsPayload = cart.map(function (item) {
        if (!hubId && item.hub_id) {
          hubId = parseInt(item.hub_id, 10) || null;
        }

        var qty = parseInt(item.quantity || 1, 10);
        if (!isFinite(qty) || qty < 1) qty = 1;

        var unit = parseFloat(
          (item.unit_price_with_modifiers != null
            ? item.unit_price_with_modifiers
            : item.base_price) || 0
        );
        if (!isFinite(unit)) unit = 0;

        var lineTotal = parseFloat(item.line_total || (unit * qty));
        if (!isFinite(lineTotal)) lineTotal = unit * qty;

        subtotal += lineTotal;

        return {
          item_id: item.item_id || null,
          name: item.name || '',
          image: item.image || '',
          unit_price: unit,
          quantity: qty,
          line_total: lineTotal,
          modifiers: Array.isArray(item.modifiers) ? item.modifiers : []
        };
      });

      if (!hubId || !itemsPayload.length) return;

      var payload = {
        session_token: getCartToken(),
        hub_id: hubId,
        items: itemsPayload,
        subtotal: subtotal
      };

      fetch('/wp-json/knx/v1/cart/sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      }).catch(function () {});
    } catch (e) {}
  }

  function generateCartItemId() {
    var rand = Math.random().toString(16).slice(2);
    return 'knx_item_' + Date.now() + '_' + rand;
  }

  function buildSelectedModifiersPayload(modifiers, selectedSingles, selectedMultiples) {
    var output = [];
    (modifiers || []).forEach(function (mod) {
      var modId = String(mod.id);
      var selectedIds = [];

      if (mod.type === 'single') {
        var sel = selectedSingles[modId];
        if (sel) selectedIds.push(String(sel));
      } else {
        var set = selectedMultiples[modId];
        if (set && set.size) {
          set.forEach(function (id) {
            selectedIds.push(String(id));
          });
        }
      }

      if (!selectedIds.length) return;

      var opts = [];
      (mod.options || []).forEach(function (opt) {
        var idStr = String(opt.id);
        if (selectedIds.indexOf(idStr) !== -1) {
          opts.push({
            id: opt.id,
            name: opt.name,
            price_adjustment: parseFloat(opt.price_adjustment || 0)
          });
        }
      });

      if (!opts.length) return;

      output.push({
        id: mod.id,
        name: mod.name,
        type: mod.type || 'single',
        required: !!mod.required,
        options: opts
      });
    });

    return output;
  }

  function esc(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
})();
