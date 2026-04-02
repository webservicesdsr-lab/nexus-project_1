/**
 * ════════════════════════════════════════════════════════════════
 * MY ADDRESSES — Client Script v4.0 (Shared Page + Checkout)
 * ════════════════════════════════════════════════════════════════
 *
 * Supports:
 * - Full My Addresses page
 * - Checkout embedded addresses block
 *
 * UX Improvements:
 * - Auto-detect location on modal open (GPS → IP fallback)
 * - Reverse geocoding fills city/state/zip automatically
 * - Shared modal for page + checkout
 * - Search suggestions with Nominatim
 * - Toast notifications (no alert())
 * - Defensive modal mounting to <body>
 * - Defensive Leaflet lifecycle
 * ════════════════════════════════════════════════════════════════
 */

(function () {
  'use strict';

  /* ════════ ROOT CONTEXT ════════ */
  const wrapper =
    document.querySelector('.knx-addr') ||
    document.getElementById('knxCheckoutAddr');

  if (!wrapper) return;

  const isCheckout = wrapper.id === 'knxCheckoutAddr';
  const isPage = !isCheckout;

  // Prevent global duplicate boot
  if (window.__KNX_ADDR_SCRIPT_BOOTED__) {
    return;
  }
  window.__KNX_ADDR_SCRIPT_BOOTED__ = true;

  /* ════════ CONFIG ════════ */
  const cfg = {
    customerId: wrapper.dataset.customerId || '',
    apiList: wrapper.dataset.apiList || '',
    apiAdd: wrapper.dataset.apiAdd || '',
    apiUpdate: wrapper.dataset.apiUpdate || '',
    apiDelete: wrapper.dataset.apiDelete || '',
    apiDefault: wrapper.dataset.apiDefault || '',
    apiSelect: wrapper.dataset.apiSelect || '',
    nonce: wrapper.dataset.nonce || '',
  };

  const LOCATION_PROVIDER =
    typeof window.KNX_LOCATION_PROVIDER !== 'undefined'
      ? window.KNX_LOCATION_PROVIDER
      : 'nominatim';

  /* ════════ DOM REFS ════════ */
  const dom = {
    wrapper,

    loading: document.getElementById(isCheckout ? 'knxCheckoutAddrLoading' : 'knxAddrLoading'),
    grid: document.getElementById('knxAddrGrid'),
    list: document.getElementById('knxCheckoutAddrList'),
    empty: document.getElementById(isCheckout ? 'knxCheckoutAddrEmpty' : 'knxAddrEmpty'),

    addBtn:
      document.getElementById('knxAddrAddBtn') ||
      document.getElementById('knxCheckoutAddrAddBtn'),

    emptyAddBtn: document.getElementById('knxCheckoutAddrEmptyAdd'),
    toggleBtn: document.getElementById('knxCheckoutAddrToggleBtn'),

    modal: document.getElementById('knxAddrModal'),
    modalBG: document.getElementById('knxAddrModalBG'),
    modalClose: document.getElementById('knxAddrModalClose'),
    modalTitle: document.getElementById('knxAddrModalTitle'),
    form: document.getElementById('knxAddrForm'),
    cancelBtn: document.getElementById('knxAddrCancelBtn'),
    saveBtn: document.getElementById('knxAddrSaveBtn'),

    search: document.getElementById('knxAddrSearch'),
    suggestions: document.getElementById('knxAddrSuggestions'),
    labelChips: document.getElementById('knxAddrLabelChips'),
    aptToggle: document.getElementById('knxAddrAptToggle'),
    aptWrap: document.getElementById('knxAddrAptWrap'),
    geoBtn: document.getElementById('knxAddrGeoBtn'),
    searchMap: document.getElementById('knxAddrSearchMapBtn'),
    mapHint: document.getElementById('knxAddrMapHint'),
    toast:
      document.getElementById('knxAddrToast') ||
      document.getElementById('knxCheckoutAddrToast'),

    // Fields
    id: document.getElementById('knxAddrId'),
    label: document.getElementById('knxAddrLabel'),
    line1: document.getElementById('knxAddrLine1'),
    line2: document.getElementById('knxAddrLine2'),
    city: document.getElementById('knxAddrCity'),
    state: document.getElementById('knxAddrState'),
    zip: document.getElementById('knxAddrZip'),
    country: document.getElementById('knxAddrCountry'),
    lat: document.getElementById('knxAddrLat'),
    lng: document.getElementById('knxAddrLng'),
  };

  // Prevent duplicate initialization on the same modal node
  try {
    if (dom.modal) {
      if (dom.modal.__knxInitialized) return;
      dom.modal.__knxInitialized = true;
    }
  } catch (e) {
    /* ignore */
  }

  let addresses = [];
  let editingId = null;
  let map = null;
  let marker = null;
  let searchTimer = null;
  let cachedLocation = null;
  let checkoutCollapsed = false;

  /* ════════ INIT ════════ */
  function init() {
    ensureMountedToBody();
    bindEvents();
    loadAddresses();
    detectLocationSilent();
  }

  function bindEvents() {
    dom.addBtn?.addEventListener('click', function (e) {
      e.preventDefault();
      openModal();
    });

    dom.emptyAddBtn?.addEventListener('click', function (e) {
      e.preventDefault();
      openModal();
    });

    dom.toggleBtn?.addEventListener('click', function () {
      checkoutCollapsed = !checkoutCollapsed;
      updateCheckoutCollapse();
    });

    dom.modalClose?.addEventListener('click', closeModal);
    dom.modalBG?.addEventListener('click', closeModal);
    dom.cancelBtn?.addEventListener('click', closeModal);
    dom.form?.addEventListener('submit', handleSubmit);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && dom.modal?.getAttribute('aria-hidden') === 'false') {
        closeModal();
      }
    });

    if (LOCATION_PROVIDER === 'google' && dom.search) {
      const apiKey =
        window.KNX_MAPS_CONFIG && window.KNX_MAPS_CONFIG.key
          ? window.KNX_MAPS_CONFIG.key
          : null;

      if (apiKey) {
        loadGooglePlaces(apiKey)
          .then(function () {
            try {
              setupGoogleAutocomplete(dom.search);
            } catch (e) {
              console.warn('Google autocomplete setup failed', e);
              dom.search?.addEventListener('input', defaultSearchHandler);
            }
          })
          .catch(function (err) {
            console.warn('Google Places load failed', err);
            dom.search?.addEventListener('input', defaultSearchHandler);
          });
      } else {
        dom.search?.addEventListener('input', defaultSearchHandler);
      }
    } else {
      dom.search?.addEventListener('input', defaultSearchHandler);
    }

    dom.labelChips?.addEventListener('click', function (e) {
      const chip = e.target.closest('.knx-addr-form__chip');
      if (!chip) return;

      dom.labelChips
        .querySelectorAll('.knx-addr-form__chip')
        .forEach(function (c) {
          c.classList.remove('is-active');
        });

      chip.classList.add('is-active');
      dom.label.value = chip.dataset.label || '';
    });

    dom.aptToggle?.addEventListener('click', function () {
      dom.aptWrap.style.display = 'block';
      dom.aptToggle.style.display = 'none';
      dom.line2?.focus();
    });

    dom.geoBtn?.addEventListener('click', detectAndApplyLocation);
    dom.searchMap?.addEventListener('click', geocodeFromFields);
  }

  function updateCheckoutCollapse() {
    if (!isCheckout) return;

    const body = dom.wrapper.closest('.knx-co-card__body');
    if (!body) return;

    body.style.display = checkoutCollapsed ? 'none' : '';
    if (dom.toggleBtn) {
      dom.toggleBtn.setAttribute('aria-expanded', checkoutCollapsed ? 'false' : 'true');
    }
  }

  function ensureMountedToBody() {
    try {
      if (dom.modal && dom.modal.parentElement !== document.body) {
        document.body.appendChild(dom.modal);
      }

      if (dom.toast && dom.toast.parentElement !== document.body) {
        document.body.appendChild(dom.toast);
      }
    } catch (e) {
      console.error('[Addr] ensureMountedToBody error:', e);
    }
  }

  function defaultSearchHandler() {
    clearTimeout(searchTimer);
    const q = (dom.search?.value || '').trim();

    if (q.length < 3) {
      closeSuggestions();
      return;
    }

    searchTimer = setTimeout(function () {
      searchNominatim(q);
    }, 350);
  }

  /* ════════ AUTO-GEOLOCATION ════════ */

  async function detectLocationSilent() {
    try {
      const loc = await detectLocation();
      if (loc) cachedLocation = loc;
    } catch (_) {
      /* ignore */
    }
  }

  async function detectLocation() {
    if (navigator.geolocation) {
      try {
        const pos = await new Promise(function (resolve, reject) {
          navigator.geolocation.getCurrentPosition(resolve, reject, {
            enableHighAccuracy: true,
            timeout: 8000,
            maximumAge: 60000,
          });
        });

        return {
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          source: 'gps',
        };
      } catch (gpsErr) {
        console.log('[Addr] GPS failed, trying IP fallback:', gpsErr.message);
      }
    }

    try {
      const res = await fetch('https://ipapi.co/json/');
      const data = await res.json();

      if (data.latitude && data.longitude) {
        return {
          lat: data.latitude,
          lng: data.longitude,
          city: data.city,
          state: data.region_code,
          zip: data.postal,
          country: data.country_name || 'USA',
          source: 'ip',
        };
      }
    } catch (ipErr) {
      console.log('[Addr] IP fallback failed:', ipErr);
    }

    return null;
  }

  async function detectAndApplyLocation() {
    const btn = dom.geoBtn;
    if (!btn) return;

    const originalHTML = btn.innerHTML;
    btn.innerHTML =
      '<svg class="ka-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Detecting…';
    btn.disabled = true;

    try {
      const loc = await detectLocation();

      if (!loc) {
        toast('Could not detect location. Please search or drag the pin.', 'error');
        return;
      }

      panMap(loc.lat, loc.lng);

      if (loc.source === 'ip') {
        if (loc.city && !dom.city.value) dom.city.value = loc.city;
        if (loc.state && !dom.state.value) dom.state.value = loc.state;
        if (loc.zip && !dom.zip.value) dom.zip.value = loc.zip;
        if (loc.country) dom.country.value = loc.country;
        toast('Location detected via IP — adjust pin for exact address', 'success');
      } else {
        await reverseGeocode(loc.lat, loc.lng);
        toast('Location detected ✓', 'success');
      }
    } catch (err) {
      console.error('[Addr] Detect error:', err);
      toast('Location detection failed', 'error');
    } finally {
      btn.innerHTML = originalHTML;
      btn.disabled = false;
    }
  }

  async function reverseGeocode(lat, lng) {
    try {
      const res = await fetch(
        `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`
      );
      const data = await res.json();

      if (data && data.address) {
        const addr = data.address;

        const street = [addr.house_number, addr.road].filter(Boolean).join(' ');
        if (street && !dom.line1.value) dom.line1.value = street;

        const city = addr.city || addr.town || addr.village || addr.municipality || '';
        if (city && !dom.city.value) dom.city.value = city;

        const state = addr.state || addr.region || '';
        if (state && !dom.state.value) {
          dom.state.value = abbreviateState(state);
        }

        if (addr.postcode && !dom.zip.value) dom.zip.value = addr.postcode;
        if (addr.country && !dom.country.value) dom.country.value = addr.country;
      }
    } catch (err) {
      console.log('[Addr] Reverse geocode failed:', err);
    }
  }

  function abbreviateState(state) {
    const abbrevs = {
      alabama: 'AL',
      alaska: 'AK',
      arizona: 'AZ',
      arkansas: 'AR',
      california: 'CA',
      colorado: 'CO',
      connecticut: 'CT',
      delaware: 'DE',
      florida: 'FL',
      georgia: 'GA',
      hawaii: 'HI',
      idaho: 'ID',
      illinois: 'IL',
      indiana: 'IN',
      iowa: 'IA',
      kansas: 'KS',
      kentucky: 'KY',
      louisiana: 'LA',
      maine: 'ME',
      maryland: 'MD',
      massachusetts: 'MA',
      michigan: 'MI',
      minnesota: 'MN',
      mississippi: 'MS',
      missouri: 'MO',
      montana: 'MT',
      nebraska: 'NE',
      nevada: 'NV',
      'new hampshire': 'NH',
      'new jersey': 'NJ',
      'new mexico': 'NM',
      'new york': 'NY',
      'north carolina': 'NC',
      'north dakota': 'ND',
      ohio: 'OH',
      oklahoma: 'OK',
      oregon: 'OR',
      pennsylvania: 'PA',
      'rhode island': 'RI',
      'south carolina': 'SC',
      'south dakota': 'SD',
      tennessee: 'TN',
      texas: 'TX',
      utah: 'UT',
      vermont: 'VT',
      virginia: 'VA',
      washington: 'WA',
      'west virginia': 'WV',
      wisconsin: 'WI',
      wyoming: 'WY',
    };

    const lower = String(state || '').toLowerCase();
    return abbrevs[lower] || state;
  }

  /* ════════ REST CALLS ════════ */

  async function apiCall(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({}, body || {}, { knx_nonce: cfg.nonce })),
    });

    return res.json();
  }

  async function loadAddresses() {
    showLoading(true);

    try {
      const data = await apiCall(cfg.apiList);
      if (!data.success && !data.data) {
        throw new Error(data.message || 'Failed to load');
      }

      addresses = data.data?.addresses || data.addresses || [];
      render();
    } catch (err) {
      console.error('[Addr] Load:', err);

      const target = dom.grid || dom.list;
      if (target) {
        target.innerHTML =
          '<p style="padding:2rem;text-align:center;color:#dc2626;">Failed to load addresses. Please refresh.</p>';
      }
    } finally {
      showLoading(false);
    }
  }

  async function saveAddress(payload) {
    const url = editingId ? cfg.apiUpdate : cfg.apiAdd;
    if (editingId) payload.address_id = editingId;

    try {
      dom.saveBtn.disabled = true;
      dom.saveBtn.textContent = 'Saving…';

      const data = await apiCall(url, payload);
      if (!data.success) throw new Error(data.message || 'Save failed');

      const newId = data.data?.address_id || null;

      toast(editingId ? 'Address updated ✓' : 'Address added ✓', 'success');
      closeModal();

      await loadAddresses();

      if (!editingId && newId) {
        try {
          await selectAddress(newId);
        } catch (e) {
          console.warn('[Addr] Auto-select failed:', e && e.message ? e.message : e);
        }
      }
    } catch (err) {
      console.error('[Addr] Save:', err);
      toast(err.message, 'error');
    } finally {
      dom.saveBtn.disabled = false;
      dom.saveBtn.innerHTML =
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Save Address';
    }
  }

  async function deleteAddress(id) {
    const confirmed = await confirmDialog('Delete Address', 'This cannot be undone. Continue?');
    if (!confirmed) return;

    try {
      const data = await apiCall(cfg.apiDelete, { address_id: id });
      if (!data.success) throw new Error(data.message || 'Delete failed');

      toast('Address deleted', 'success');
      await loadAddresses();
    } catch (err) {
      console.error('[Addr] Delete:', err);
      toast(err.message, 'error');
    }
  }

  async function setDefault(id) {
    try {
      const data = await apiCall(cfg.apiDefault, { address_id: id });
      if (!data.success) throw new Error(data.message || 'Failed');

      toast('Primary address updated ✓', 'success');
      await loadAddresses();
    } catch (err) {
      console.error('[Addr] Default:', err);
      toast(err.message, 'error');
    }
  }

  async function selectAddress(id) {
    try {
      const data = await apiCall(cfg.apiSelect, { address_id: id });
      if (!data.success) throw new Error(data.message || 'Failed');

      toast('Address selected ✓', 'success');

      const onCheckout = !!document.getElementById('knx-checkout');
      if (onCheckout) {
        try {
          const ev = new CustomEvent('knx:address:selected', {
            detail: { address_id: id },
          });
          document.dispatchEvent(ev);
        } catch (e) {
          console.warn('[Addr] Could not dispatch checkout refresh event', e);
          setTimeout(function () {
            window.location.reload();
          }, 500);
        }
      } else {
        setTimeout(function () {
          window.location.reload();
        }, 500);
      }
    } catch (err) {
      console.error('[Addr] Select:', err);
      toast(err.message, 'error');
    }
  }

  /* ════════ RENDER ════════ */

  function render() {
    if (!addresses.length) {
      if (dom.grid) {
        dom.grid.innerHTML = '';
        dom.grid.style.display = 'none';
      }

      if (dom.list) {
        dom.list.innerHTML = '';
        dom.list.style.display = 'none';
      }

      if (dom.empty) {
        dom.empty.style.display = 'flex';
      }

      if (isCheckout && dom.addBtn) {
        dom.addBtn.style.display = 'none';
      }

      return;
    }

    if (dom.empty) dom.empty.style.display = 'none';

    if (isCheckout) {
      if (dom.list) {
        dom.list.style.display = 'block';
        dom.list.innerHTML = addresses.map(renderCheckoutRow).join('');
      }

      if (dom.addBtn) {
        dom.addBtn.style.display = 'inline-flex';
      }

      bindCheckoutListActions();
      return;
    }

    if (dom.grid) {
      dom.grid.style.display = 'grid';
      dom.grid.innerHTML = addresses.map(renderPageCard).join('');
      bindPageGridActions();
    }
  }

  function renderPageCard(addr) {
    const isPrimary = !!addr.is_default;
    const isSelected = !!addr.is_selected;

    const parts = [];
    if (addr.line1) parts.push(esc(addr.line1));
    if (addr.line2) parts.push(esc(addr.line2));
    const cityState = [addr.city, addr.state].filter(Boolean).join(', ');
    if (cityState || addr.postal_code) {
      parts.push(esc([cityState, addr.postal_code].filter(Boolean).join(' ')));
    }

    return `
      <div class="knx-addr__card ${isPrimary ? 'knx-addr__card--primary' : ''}">
        <div class="knx-addr__card-body">
          <div class="knx-addr__card-top">
            <span class="knx-addr__card-label">${esc(addr.label || 'Address')}</span>
            <span style="display:flex;gap:0.375rem;">
              ${isPrimary ? '<span class="knx-addr__badge knx-addr__badge--primary">✓ Primary</span>' : ''}
              ${isSelected ? '<span class="knx-addr__badge knx-addr__badge--selected">Selected</span>' : ''}
            </span>
          </div>
          <p class="knx-addr__card-text">${parts.join('<br>')}</p>
        </div>
        <div class="knx-addr__card-actions">
          <button type="button" class="knx-addr__card-btn knx-addr__card-btn--select" data-select="${addr.id}" title="Use for delivery">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Select
          </button>
          ${!isPrimary ? `
          <button type="button" class="knx-addr__card-btn knx-addr__card-btn--primary" data-default="${addr.id}" title="Set as primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Primary
          </button>` : ''}
          <button type="button" class="knx-addr__card-btn" data-edit="${addr.id}" title="Edit">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit
          </button>
          <button type="button" class="knx-addr__card-btn knx-addr__card-btn--danger" data-delete="${addr.id}" title="Delete">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
            Delete
          </button>
        </div>
      </div>`;
  }

  function renderCheckoutRow(addr) {
    const isPrimary = !!addr.is_default;
    const isSelected = !!addr.is_selected;

    const parts = [];
    if (addr.line1) parts.push(esc(addr.line1));
    if (addr.line2) parts.push(esc(addr.line2));
    const cityState = [addr.city, addr.state].filter(Boolean).join(', ');
    if (cityState || addr.postal_code) {
      parts.push(esc([cityState, addr.postal_code].filter(Boolean).join(' ')));
    }

    return `
      <div class="knx-co-address-row">
        <div class="knx-co-address-row__left">
          <div class="knx-co-address-row__title">
            ${esc(addr.label || 'Address')}
            ${isPrimary ? ' · Primary' : ''}
            ${isSelected ? ' · Selected' : ''}
          </div>
          <div class="knx-co-address-row__line">${parts.join(', ')}</div>
        </div>
        <div class="knx-co-address-row__right" style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="knx-co-btn knx-co-btn--small" data-select="${addr.id}">
            Select
          </button>
          <button type="button" class="knx-co-btn knx-co-btn--small" data-edit="${addr.id}">
            Edit
          </button>
        </div>
      </div>`;
  }

  function bindPageGridActions() {
    if (!dom.grid) return;

    dom.grid.onclick = function (e) {
      const btn = e.target.closest('button[data-edit], button[data-delete], button[data-default], button[data-select]');
      if (!btn) return;

      const id = parseInt(
        btn.dataset.edit || btn.dataset.delete || btn.dataset.default || btn.dataset.select,
        10
      );

      if (btn.dataset.edit) openModal(id);
      if (btn.dataset.delete) deleteAddress(id);
      if (btn.dataset.default) setDefault(id);
      if (btn.dataset.select) selectAddress(id);
    };
  }

  function bindCheckoutListActions() {
    if (!dom.list) return;

    dom.list.onclick = function (e) {
      const btn = e.target.closest('button[data-edit], button[data-select]');
      if (!btn) return;

      const id = parseInt(btn.dataset.edit || btn.dataset.select, 10);

      if (btn.dataset.edit) openModal(id);
      if (btn.dataset.select) selectAddress(id);
    };
  }

  /* ════════ MODAL ════════ */

  function openModal(id) {
    ensureMountedToBody();

    editingId = id || null;
    dom.form.reset();
    dom.country.value = 'USA';
    dom.lat.value = '';
    dom.lng.value = '';
    closeSuggestions();

    dom.aptToggle.style.display = 'block';
    dom.aptWrap.style.display = 'none';

    dom.labelChips
      .querySelectorAll('.knx-addr-form__chip')
      .forEach(function (c) {
        c.classList.remove('is-active');
      });

    dom.mapHint.textContent = 'Detecting your location…';
    dom.mapHint.classList.remove('has-pin');

    if (id) {
      dom.modalTitle.textContent = 'Edit Address';
      const addr = addresses.find(function (a) {
        return a.id === id;
      });
      if (addr) populateForm(addr);
    } else {
      dom.modalTitle.textContent = 'Add Address';
      const homeChip = dom.labelChips.querySelector('[data-label="Home"]');
      if (homeChip) {
        homeChip.classList.add('is-active');
        dom.label.value = 'Home';
      }
    }

    dom.modal.setAttribute('aria-hidden', 'false');
    dom.modal.style.visibility = 'visible';
    dom.modal.style.opacity = '1';
    document.body.style.overflow = 'hidden';

    setTimeout(function () {
      initMap();
      if (!id) {
        autoDetectOnOpen();
      }
    }, 200);
  }

  async function autoDetectOnOpen() {
    if (cachedLocation) {
      panMap(cachedLocation.lat, cachedLocation.lng);

      if (cachedLocation.source === 'ip') {
        if (cachedLocation.city) dom.city.value = cachedLocation.city;
        if (cachedLocation.state) dom.state.value = cachedLocation.state;
        if (cachedLocation.zip) dom.zip.value = cachedLocation.zip;
        if (cachedLocation.country) dom.country.value = cachedLocation.country;
      } else {
        await reverseGeocode(cachedLocation.lat, cachedLocation.lng);
      }

      dom.mapHint.textContent = '📍 Location detected — drag pin to adjust or search for address';
      dom.mapHint.classList.add('has-pin');
      return;
    }

    try {
      const loc = await detectLocation();
      if (loc) {
        cachedLocation = loc;
        panMap(loc.lat, loc.lng);

        if (loc.source === 'ip') {
          if (loc.city) dom.city.value = loc.city;
          if (loc.state) dom.state.value = loc.state;
          if (loc.zip) dom.zip.value = loc.zip;
          if (loc.country) dom.country.value = loc.country;
        } else {
          await reverseGeocode(loc.lat, loc.lng);
        }

        dom.mapHint.textContent = '📍 Location detected — drag pin to adjust or search';
        dom.mapHint.classList.add('has-pin');
      } else {
        dom.mapHint.textContent = 'Search for your address or drag the pin on the map';
      }
    } catch (_) {
      dom.mapHint.textContent = 'Search for your address or drag the pin on the map';
    }
  }

  function closeModal() {
    dom.modal.setAttribute('aria-hidden', 'true');
    dom.modal.style.visibility = '';
    dom.modal.style.opacity = '';
    document.body.style.overflow = '';
    editingId = null;
    destroyMap();
  }

  function populateForm(addr) {
    dom.label.value = addr.label || '';
    dom.line1.value = addr.line1 || '';
    dom.city.value = addr.city || '';
    dom.state.value = addr.state || '';
    dom.zip.value = addr.postal_code || '';
    dom.country.value = addr.country || 'USA';
    dom.lat.value = addr.latitude || '';
    dom.lng.value = addr.longitude || '';

    if (addr.line2) {
      dom.line2.value = addr.line2;
      dom.aptToggle.style.display = 'none';
      dom.aptWrap.style.display = 'block';
    }

    const chipMatch = dom.labelChips.querySelector(`[data-label="${addr.label}"]`);
    if (chipMatch) chipMatch.classList.add('is-active');

    if (addr.latitude && addr.longitude) {
      dom.mapHint.textContent = '📍 Pin set — drag to adjust';
      dom.mapHint.classList.add('has-pin');
    }
  }

  function handleSubmit(e) {
    e.preventDefault();

    const lat = parseFloat(dom.lat.value);
    const lng = parseFloat(dom.lng.value);

    if (!lat || !lng) {
      toast('Please set location on map', 'error');
      return;
    }

    saveAddress({
      label: dom.label.value.trim(),
      line1: dom.line1.value.trim(),
      line2: (dom.line2?.value || '').trim(),
      city: dom.city.value.trim(),
      state: dom.state.value.trim(),
      postal_code: dom.zip.value.trim(),
      country: dom.country.value.trim(),
      latitude: lat,
      longitude: lng,
    });
  }

  /* ════════ MAP (Leaflet) ════════ */

  function initMap() {
    const mapEl = document.getElementById('knxAddrMap');
    if (!mapEl) return;

    try {
      if (window.knxAddrMapInstance) {
        try {
          window.knxAddrMapInstance.remove();
        } catch (e) {
          /* ignore */
        }
        window.knxAddrMapInstance = null;
      }
    } catch (e) {
      /* ignore */
    }

    if (map) return;

    const lat = parseFloat(dom.lat.value) || 41.8781;
    const lng = parseFloat(dom.lng.value) || -87.6298;

    map = L.map('knxAddrMap', { scrollWheelZoom: true }).setView([lat, lng], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap',
      maxZoom: 19,
    }).addTo(map);

    marker = L.marker([lat, lng], { draggable: true }).addTo(map);

    marker.on('dragend', function () {
      const pos = marker.getLatLng();
      setCoords(pos.lat, pos.lng);
    });

    map.on('click', function (e) {
      marker.setLatLng(e.latlng);
      setCoords(e.latlng.lat, e.latlng.lng);
    });

    setTimeout(function () {
      map.invalidateSize();
    }, 200);

    try {
      window.knxAddrMapInstance = map;
    } catch (e) {
      /* ignore */
    }
  }

  function destroyMap() {
    if (map) {
      try {
        map.remove();
      } catch (e) {
        /* ignore */
      }

      map = null;
      marker = null;

      try {
        if (window.knxAddrMapInstance) {
          window.knxAddrMapInstance = null;
        }
      } catch (e) {
        /* ignore */
      }
    }
  }

  function setCoords(lat, lng) {
    dom.lat.value = lat.toFixed(6);
    dom.lng.value = lng.toFixed(6);
    dom.mapHint.textContent = '📍 Pin set — drag to adjust';
    dom.mapHint.classList.add('has-pin');
  }

  function panMap(lat, lng) {
    if (!map || !marker) return;

    map.setView([lat, lng], 16);
    marker.setLatLng([lat, lng]);
    setCoords(lat, lng);
  }

  /* ════════ SEARCH ════════ */

  function geocodeFromFields() {
    const q = [dom.line1.value, dom.city.value, dom.state.value]
      .filter(Boolean)
      .join(' ')
      .trim();

    if (!q) {
      toast('Enter an address first', 'error');
      return;
    }

    geocode(q);
  }

  async function searchNominatim(query) {
    try {
      const res = await fetch(
        `https://nominatim.openstreetmap.org/search?format=json&limit=5&q=${encodeURIComponent(query)}`
      );
      const results = await res.json();

      if (!results || results.length === 0) {
        closeSuggestions();
        return;
      }

      dom.suggestions.innerHTML = results
        .map(function (r, i) {
          return `<li data-idx="${i}" data-lat="${r.lat}" data-lon="${r.lon}" data-name="${esc(r.display_name)}">${esc(r.display_name)}</li>`;
        })
        .join('');

      dom.suggestions.classList.add('is-open');

      dom.suggestions.onclick = function (e) {
        const li = e.target.closest('li');
        if (!li) return;
        applySuggestion(li, results[parseInt(li.dataset.idx, 10)]);
      };
    } catch (err) {
      console.error('[Addr] Search:', err);
    }
  }

  function applySuggestion(li, result) {
    closeSuggestions();
    dom.search.value = '';

    const parts = result.display_name.split(',').map(function (s) {
      return s.trim();
    });

    if (parts.length >= 1) dom.line1.value = parts[0];
    if (parts.length >= 2) dom.city.value = parts[parts.length - 3] || parts[1] || '';
    if (parts.length >= 3) dom.state.value = parts[parts.length - 2] || '';

    const lat = parseFloat(result.lat);
    const lng = parseFloat(result.lon);
    panMap(lat, lng);

    toast('Address applied ✓', 'success');
  }

  function closeSuggestions() {
    if (!dom.suggestions) return;
    dom.suggestions.innerHTML = '';
    dom.suggestions.classList.remove('is-open');
  }

  async function geocode(query) {
    try {
      const res = await fetch(
        `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(query)}`
      );
      const data = await res.json();

      if (data && data.length > 0) {
        panMap(parseFloat(data[0].lat), parseFloat(data[0].lon));
        toast('Location found ✓', 'success');
      } else {
        toast('Address not found', 'error');
      }
    } catch (err) {
      toast('Search failed', 'error');
    }
  }

  /* ════════ GOOGLE PLACES HELPERS ════════ */

  function loadGooglePlaces(apiKey) {
    return new Promise(function (resolve, reject) {
      if (window.google && window.google.maps && window.google.maps.places) {
        return resolve();
      }

      const callbackName = 'knx_addr_places_cb_' + Math.floor(Math.random() * 1000000);

      window[callbackName] = function () {
        resolve();
        delete window[callbackName];
      };

      const script = document.createElement('script');
      script.src =
        `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&libraries=places&callback=${callbackName}`;
      script.async = true;
      script.onerror = function () {
        reject(new Error('Failed to load Google Maps JS'));
      };

      document.head.appendChild(script);
    });
  }

  function setupGoogleAutocomplete(el) {
    try {
      const ac = new google.maps.places.Autocomplete(el, { types: ['address'] });
      ac.setFields(['formatted_address', 'address_components', 'geometry']);

      ac.addListener('place_changed', function () {
        const place = ac.getPlace();
        if (!place || !place.geometry) {
          return toast('No location found for that address', 'error');
        }

        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();

        if (place.formatted_address) {
          dom.line1.value = place.formatted_address;
        }

        const comps = place.address_components || [];
        comps.forEach(function (c) {
          if (c.types.indexOf('locality') !== -1) {
            dom.city.value = c.long_name;
          }
          if (c.types.indexOf('administrative_area_level_1') !== -1) {
            dom.state.value = c.short_name || c.long_name;
          }
          if (c.types.indexOf('postal_code') !== -1) {
            dom.zip.value = c.long_name;
          }
        });

        panMap(lat, lng);
        toast('Address applied ✓', 'success');
      });
    } catch (err) {
      console.warn('setupGoogleAutocomplete error', err);
    }
  }

  /* ════════ CONFIRM DIALOG ════════ */

  function confirmDialog(title, message) {
    return new Promise(function (resolve) {
      const el = document.createElement('div');
      el.className = 'knx-addr-confirm';
      el.innerHTML = `
        <div class="knx-addr-confirm__backdrop"></div>
        <div class="knx-addr-confirm__box">
          <h3>${esc(title)}</h3>
          <p>${esc(message)}</p>
          <div class="knx-addr-confirm__btns">
            <button type="button" class="knx-addr-confirm__no">Cancel</button>
            <button type="button" class="knx-addr-confirm__yes">Delete</button>
          </div>
        </div>`;

      document.body.appendChild(el);

      const cleanup = function (result) {
        el.remove();
        resolve(result);
      };

      el.querySelector('.knx-addr-confirm__no').onclick = function () {
        cleanup(false);
      };
      el.querySelector('.knx-addr-confirm__yes').onclick = function () {
        cleanup(true);
      };
      el.querySelector('.knx-addr-confirm__backdrop').onclick = function () {
        cleanup(false);
      };
    });
  }

  /* ════════ TOAST ════════ */

  function toast(msg, type) {
    const targetToast =
      document.getElementById('knxAddrToast') ||
      document.getElementById('knxCheckoutAddrToast') ||
      dom.toast;

    if (!targetToast) {
      console.warn('[Addr] Toast container not found:', msg);
      return;
    }

    const item = document.createElement('div');
    item.className = `knx-addr-toast__item knx-addr-toast__item--${type || 'info'}`;
    item.textContent = msg;

    targetToast.appendChild(item);

    setTimeout(function () {
      item.remove();
    }, 3200);
  }

  /* ════════ HELPERS ════════ */

  function esc(text) {
    if (!text) return '';
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
  }

  function showLoading(show) {
    if (dom.loading) {
      dom.loading.style.display = show ? 'flex' : 'none';
    }
  }

  /* ════════ GLOBAL EXPORTS ════════ */
  window.knxEditAddress = function (id) {
    openModal(id);
  };

  window.knxDeleteAddress = function (id) {
    deleteAddress(id);
  };

  window.knxSetDefault = function (id) {
    setDefault(id);
  };

  window.knxSelectAddress = function (id) {
    selectAddress(id);
  };

  /* ════════ START ════════ */
  init();

})();