/**
 * ════════════════════════════════════════════════════════════════
 * MY ADDRESSES — Client Script v3.1 (Simplified + Auto-Geolocation)
 * ════════════════════════════════════════════════════════════════
 *
 * UX Improvements:
 * - Auto-detect location on modal open (GPS → IP fallback)
 * - Reverse geocoding fills city/state/zip automatically
 * - Simplified form: just label + street + optional apt
 * - Search suggestions with Nominatim
 * - Toast notifications (no alert())
 * ════════════════════════════════════════════════════════════════
 */

(function () {
  'use strict';

  /* ════════ DOM & CONFIG ════════ */
  const wrapper = document.querySelector('.knx-addr');
  if (!wrapper) return;

  const cfg = {
    customerId: wrapper.dataset.customerId,
    apiList:    wrapper.dataset.apiList,
    apiAdd:     wrapper.dataset.apiAdd,
    apiUpdate:  wrapper.dataset.apiUpdate,
    apiDelete:  wrapper.dataset.apiDelete,
    apiDefault: wrapper.dataset.apiDefault,
    apiSelect:  wrapper.dataset.apiSelect,
    nonce:      wrapper.dataset.nonce,
  };

  // DOM refs
  const dom = {
    loading:    document.getElementById('knxAddrLoading'),
    grid:       document.getElementById('knxAddrGrid'),
    empty:      document.getElementById('knxAddrEmpty'),
    addBtn:     document.getElementById('knxAddrAddBtn'),
    modal:      document.getElementById('knxAddrModal'),
    modalBG:    document.getElementById('knxAddrModalBG'),
    modalClose: document.getElementById('knxAddrModalClose'),
    modalTitle: document.getElementById('knxAddrModalTitle'),
    form:       document.getElementById('knxAddrForm'),
    cancelBtn:  document.getElementById('knxAddrCancelBtn'),
    saveBtn:    document.getElementById('knxAddrSaveBtn'),
    search:     document.getElementById('knxAddrSearch'),
    suggestions:document.getElementById('knxAddrSuggestions'),
    labelChips: document.getElementById('knxAddrLabelChips'),
    aptToggle:  document.getElementById('knxAddrAptToggle'),
    aptWrap:    document.getElementById('knxAddrAptWrap'),
    geoBtn:     document.getElementById('knxAddrGeoBtn'),
    searchMap:  document.getElementById('knxAddrSearchMapBtn'),
    mapHint:    document.getElementById('knxAddrMapHint'),
    toast:      document.getElementById('knxAddrToast'),
    // Fields
    id:      document.getElementById('knxAddrId'),
    label:   document.getElementById('knxAddrLabel'),
    line1:   document.getElementById('knxAddrLine1'),
    line2:   document.getElementById('knxAddrLine2'),
    city:    document.getElementById('knxAddrCity'),
    state:   document.getElementById('knxAddrState'),
    zip:     document.getElementById('knxAddrZip'),
    country: document.getElementById('knxAddrCountry'),
    lat:     document.getElementById('knxAddrLat'),
    lng:     document.getElementById('knxAddrLng'),
  };

  let addresses  = [];
  let editingId  = null;
  let map        = null;
  let marker     = null;
  let searchTimer = null;
  let cachedLocation = null; // Cache detected location

  /* ════════ INIT ════════ */
  function init() {
    bindEvents();
    loadAddresses();
    // Pre-detect location in background for faster modal open
    detectLocationSilent();
  }

  function bindEvents() {
    dom.addBtn?.addEventListener('click', () => openModal());
    dom.modalClose?.addEventListener('click', closeModal);
    dom.modalBG?.addEventListener('click', closeModal);
    dom.cancelBtn?.addEventListener('click', closeModal);
    dom.form?.addEventListener('submit', handleSubmit);

    // ESC closes modal
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && dom.modal?.getAttribute('aria-hidden') === 'false') {
        closeModal();
      }
    });

    // Search with debounce
    dom.search?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      const q = dom.search.value.trim();
      if (q.length < 3) {
        closeSuggestions();
        return;
      }
      searchTimer = setTimeout(() => searchNominatim(q), 350);
    });

    // Label chips
    dom.labelChips?.addEventListener('click', (e) => {
      const chip = e.target.closest('.knx-addr-form__chip');
      if (!chip) return;
      dom.labelChips.querySelectorAll('.knx-addr-form__chip').forEach(c => c.classList.remove('is-active'));
      chip.classList.add('is-active');
      dom.label.value = chip.dataset.label;
    });

    // Apt toggle
    dom.aptToggle?.addEventListener('click', () => {
      dom.aptWrap.style.display = 'block';
      dom.aptToggle.style.display = 'none';
      dom.line2?.focus();
    });

    // Map buttons
    dom.geoBtn?.addEventListener('click', detectAndApplyLocation);
    dom.searchMap?.addEventListener('click', geocodeFromFields);
  }

  /* ════════ AUTO-GEOLOCATION ════════ */

  // Silent pre-detection (runs on page load, caches result)
  async function detectLocationSilent() {
    try {
      const loc = await detectLocation();
      if (loc) cachedLocation = loc;
    } catch (_) { /* ignore */ }
  }

  // Detect location: GPS first, then IP fallback
  async function detectLocation() {
    // Try GPS first
    if (navigator.geolocation) {
      try {
        const pos = await new Promise((resolve, reject) => {
          navigator.geolocation.getCurrentPosition(resolve, reject, {
            enableHighAccuracy: true,
            timeout: 8000,
            maximumAge: 60000
          });
        });
        return { lat: pos.coords.latitude, lng: pos.coords.longitude, source: 'gps' };
      } catch (gpsErr) {
        console.log('[Addr] GPS failed, trying IP fallback:', gpsErr.message);
      }
    }

    // Fallback: IP geolocation (ipapi.co - free, no key needed)
    try {
      const res = await fetch('https://ipapi.co/json/', { timeout: 5000 });
      const data = await res.json();
      if (data.latitude && data.longitude) {
        return { 
          lat: data.latitude, 
          lng: data.longitude, 
          city: data.city,
          state: data.region_code,
          zip: data.postal,
          country: data.country_name || 'USA',
          source: 'ip' 
        };
      }
    } catch (ipErr) {
      console.log('[Addr] IP fallback failed:', ipErr);
    }

    return null;
  }

  // Button click: detect and apply location
  async function detectAndApplyLocation() {
    const btn = dom.geoBtn;
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<svg class="ka-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Detecting…';
    btn.disabled = true;

    try {
      const loc = await detectLocation();
      
      if (!loc) {
        toast('Could not detect location. Please search or drag the pin.', 'error');
        return;
      }

      // Apply to map
      panMap(loc.lat, loc.lng);

      // If IP gave us city/state/zip, pre-fill
      if (loc.source === 'ip') {
        if (loc.city && !dom.city.value) dom.city.value = loc.city;
        if (loc.state && !dom.state.value) dom.state.value = loc.state;
        if (loc.zip && !dom.zip.value) dom.zip.value = loc.zip;
        if (loc.country) dom.country.value = loc.country;
        toast('Location detected via IP — adjust pin for exact address', 'success');
      } else {
        // GPS: do reverse geocoding to fill fields
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

  // Reverse geocode: lat/lng → address fields
  async function reverseGeocode(lat, lng) {
    try {
      const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`);
      const data = await res.json();
      
      if (data && data.address) {
        const addr = data.address;
        
        // Street
        const street = [addr.house_number, addr.road].filter(Boolean).join(' ');
        if (street && !dom.line1.value) dom.line1.value = street;
        
        // City (try multiple fields)
        const city = addr.city || addr.town || addr.village || addr.municipality || '';
        if (city && !dom.city.value) dom.city.value = city;
        
        // State
        const state = addr.state || addr.region || '';
        if (state && !dom.state.value) {
          // Try to abbreviate US states
          dom.state.value = abbreviateState(state);
        }
        
        // Zip
        if (addr.postcode && !dom.zip.value) dom.zip.value = addr.postcode;
        
        // Country
        if (addr.country && !dom.country.value) dom.country.value = addr.country;
      }
    } catch (err) {
      console.log('[Addr] Reverse geocode failed:', err);
    }
  }

  // US state abbreviations
  function abbreviateState(state) {
    const abbrevs = {
      'alabama': 'AL', 'alaska': 'AK', 'arizona': 'AZ', 'arkansas': 'AR', 'california': 'CA',
      'colorado': 'CO', 'connecticut': 'CT', 'delaware': 'DE', 'florida': 'FL', 'georgia': 'GA',
      'hawaii': 'HI', 'idaho': 'ID', 'illinois': 'IL', 'indiana': 'IN', 'iowa': 'IA',
      'kansas': 'KS', 'kentucky': 'KY', 'louisiana': 'LA', 'maine': 'ME', 'maryland': 'MD',
      'massachusetts': 'MA', 'michigan': 'MI', 'minnesota': 'MN', 'mississippi': 'MS', 'missouri': 'MO',
      'montana': 'MT', 'nebraska': 'NE', 'nevada': 'NV', 'new hampshire': 'NH', 'new jersey': 'NJ',
      'new mexico': 'NM', 'new york': 'NY', 'north carolina': 'NC', 'north dakota': 'ND', 'ohio': 'OH',
      'oklahoma': 'OK', 'oregon': 'OR', 'pennsylvania': 'PA', 'rhode island': 'RI', 'south carolina': 'SC',
      'south dakota': 'SD', 'tennessee': 'TN', 'texas': 'TX', 'utah': 'UT', 'vermont': 'VT',
      'virginia': 'VA', 'washington': 'WA', 'west virginia': 'WV', 'wisconsin': 'WI', 'wyoming': 'WY'
    };
    const lower = state.toLowerCase();
    return abbrevs[lower] || state;
  }

  /* ════════ REST CALLS ════════ */

  async function apiCall(url, body = {}) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...body, knx_nonce: cfg.nonce }),
    });
    return res.json();
  }

  async function loadAddresses() {
    showLoading(true);
    try {
      const data = await apiCall(cfg.apiList);
      if (!data.success && !data.data) throw new Error(data.message || 'Failed to load');
      addresses = data.data?.addresses || data.addresses || [];
      render();
    } catch (err) {
      console.error('[Addr] Load:', err);
      dom.grid.innerHTML = '<p style="padding:2rem;text-align:center;color:#dc2626;">Failed to load addresses. Please refresh.</p>';
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

      toast(editingId ? 'Address updated ✓' : 'Address added ✓', 'success');
      closeModal();
      await loadAddresses();
    } catch (err) {
      console.error('[Addr] Save:', err);
      toast(err.message, 'error');
    } finally {
      dom.saveBtn.disabled = false;
      dom.saveBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Save Address';
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
      setTimeout(() => window.location.reload(), 500);
    } catch (err) {
      console.error('[Addr] Select:', err);
      toast(err.message, 'error');
    }
  }

  /* ════════ RENDER ════════ */

  function render() {
    if (!addresses.length) {
      dom.grid.innerHTML = '';
      dom.grid.style.display = 'none';
      dom.empty.style.display = 'flex';
      return;
    }

    dom.empty.style.display = 'none';
    dom.grid.style.display = 'grid';

    dom.grid.innerHTML = addresses.map(addr => {
      const isPrimary  = !!addr.is_default;
      const isSelected = !!addr.is_selected;
      const lat = parseFloat(addr.latitude) || 0;
      const lng = parseFloat(addr.longitude) || 0;
      const hasCoords = lat !== 0 && lng !== 0;

      // Mini-map preview removed to simplify UI (map now available in modal only)

      // Build one-line text
      const parts = [];
      if (addr.line1) parts.push(esc(addr.line1));
      if (addr.line2) parts.push(esc(addr.line2));
      const cityState = [addr.city, addr.state].filter(Boolean).join(', ');
      if (cityState || addr.postal_code) parts.push(esc([cityState, addr.postal_code].filter(Boolean).join(' ')));

      return `
        <div class="knx-addr__card ${isPrimary ? 'knx-addr__card--primary' : ''}">
          <div class="knx-addr__card-body">
            <div class="knx-addr__card-top">
              <span class="knx-addr__card-label">${esc(addr.label || 'Address')}</span>
              <span style="display:flex;gap:0.375rem;">
                ${isPrimary  ? '<span class="knx-addr__badge knx-addr__badge--primary">✓ Primary</span>' : ''}
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
    }).join('');

    // Delegate card button clicks
    dom.grid.onclick = (e) => {
      const btn = e.target.closest('button[data-edit], button[data-delete], button[data-default], button[data-select]');
      if (!btn) return;
      const id = parseInt(btn.dataset.edit || btn.dataset.delete || btn.dataset.default || btn.dataset.select, 10);
      if (btn.dataset.edit)    openModal(id);
      if (btn.dataset.delete)  deleteAddress(id);
      if (btn.dataset.default) setDefault(id);
      if (btn.dataset.select)  selectAddress(id);
    };
  }

  /* ════════ MODAL ════════ */

  function openModal(id = null) {
    editingId = id;
    dom.form.reset();
    dom.country.value = 'USA';
    dom.lat.value = '';
    dom.lng.value = '';
    closeSuggestions();

    // Reset apt toggle
    dom.aptToggle.style.display = 'block';
    dom.aptWrap.style.display = 'none';

    // Reset label chips
    dom.labelChips.querySelectorAll('.knx-addr-form__chip').forEach(c => c.classList.remove('is-active'));

    // Reset hint
    dom.mapHint.textContent = 'Detecting your location…';
    dom.mapHint.classList.remove('has-pin');

    if (id) {
      dom.modalTitle.textContent = 'Edit Address';
      const addr = addresses.find(a => a.id === id);
      if (addr) populateForm(addr);
    } else {
      dom.modalTitle.textContent = 'Add Address';
      // Auto-select "Home" chip by default for new addresses
      const homeChip = dom.labelChips.querySelector('[data-label="Home"]');
      if (homeChip) {
        homeChip.classList.add('is-active');
        dom.label.value = 'Home';
      }
    }

    dom.modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    // Init map and auto-detect location for new addresses
    setTimeout(() => {
      initMap();
      if (!id) {
        autoDetectOnOpen();
      }
    }, 200);
  }

  // Auto-detect location when opening modal for new address
  async function autoDetectOnOpen() {
    // Use cached location if available
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

    // No cache, detect now
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
    document.body.style.overflow = '';
    editingId = null;
    destroyMap();
  }

  function populateForm(addr) {
    dom.label.value   = addr.label || '';
    dom.line1.value   = addr.line1 || '';
    dom.city.value    = addr.city || '';
    dom.state.value   = addr.state || '';
    dom.zip.value     = addr.postal_code || '';
    dom.country.value = addr.country || 'USA';
    dom.lat.value     = addr.latitude || '';
    dom.lng.value     = addr.longitude || '';

    // Line 2
    if (addr.line2) {
      dom.line2.value = addr.line2;
      dom.aptToggle.style.display = 'none';
      dom.aptWrap.style.display = 'block';
    }

    // Activate matching chip
    const chipMatch = dom.labelChips.querySelector(`[data-label="${addr.label}"]`);
    if (chipMatch) chipMatch.classList.add('is-active');

    // Map hint
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
      label:       dom.label.value.trim(),
      line1:       dom.line1.value.trim(),
      line2:       (dom.line2?.value || '').trim(),
      city:        dom.city.value.trim(),
      state:       dom.state.value.trim(),
      postal_code: dom.zip.value.trim(),
      country:     dom.country.value.trim(),
      latitude:    lat,
      longitude:   lng,
    });
  }

  /* ════════ MAP (Leaflet) ════════ */

  function initMap() {
    const mapEl = document.getElementById('knxAddrMap');
    if (!mapEl || map) return;

    const lat = parseFloat(dom.lat.value) || 41.8781;
    const lng = parseFloat(dom.lng.value) || -87.6298;

    map = L.map('knxAddrMap', { scrollWheelZoom: true }).setView([lat, lng], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap',
      maxZoom: 19,
    }).addTo(map);

    marker = L.marker([lat, lng], { draggable: true }).addTo(map);

    marker.on('dragend', () => {
      const pos = marker.getLatLng();
      setCoords(pos.lat, pos.lng);
    });

    map.on('click', (e) => {
      marker.setLatLng(e.latlng);
      setCoords(e.latlng.lat, e.latlng.lng);
    });

    // Force resize (modal may not be fully visible yet)
    setTimeout(() => map.invalidateSize(), 200);
  }

  function destroyMap() {
    if (map) {
      map.remove();
      map = null;
      marker = null;
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

  /* ════════ GEOLOCATION — now handled by detectAndApplyLocation() ════════ */

  function geocodeFromFields() {
    const q = [dom.line1.value, dom.city.value, dom.state.value].filter(Boolean).join(' ').trim();
    if (!q) {
      toast('Enter an address first', 'error');
      return;
    }
    geocode(q);
  }

  /* ════════ NOMINATIM SEARCH ════════ */

  async function searchNominatim(query) {
    try {
      const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=5&q=${encodeURIComponent(query)}`);
      const results = await res.json();

      if (!results || results.length === 0) {
        closeSuggestions();
        return;
      }

      dom.suggestions.innerHTML = results.map((r, i) =>
        `<li data-idx="${i}" data-lat="${r.lat}" data-lon="${r.lon}" data-name="${esc(r.display_name)}">${esc(r.display_name)}</li>`
      ).join('');
      dom.suggestions.classList.add('is-open');

      // Click handler
      dom.suggestions.onclick = (e) => {
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

    // Parse display_name into fields
    const parts = result.display_name.split(',').map(s => s.trim());
    if (parts.length >= 1) dom.line1.value = parts[0];
    if (parts.length >= 2) dom.city.value  = parts[parts.length - 3] || parts[1] || '';
    if (parts.length >= 3) dom.state.value = parts[parts.length - 2] || '';

    const lat = parseFloat(result.lat);
    const lng = parseFloat(result.lon);
    panMap(lat, lng);

    toast('Address applied ✓', 'success');
  }

  function closeSuggestions() {
    dom.suggestions.innerHTML = '';
    dom.suggestions.classList.remove('is-open');
  }

  async function geocode(query) {
    try {
      const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(query)}`);
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

  /* ════════ CONFIRM DIALOG ════════ */

  function confirmDialog(title, message) {
    return new Promise((resolve) => {
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

      const cleanup = (result) => {
        el.remove();
        resolve(result);
      };

      el.querySelector('.knx-addr-confirm__no').onclick = () => cleanup(false);
      el.querySelector('.knx-addr-confirm__yes').onclick = () => cleanup(true);
      el.querySelector('.knx-addr-confirm__backdrop').onclick = () => cleanup(false);
    });
  }

  /* ════════ TOAST ════════ */

  function toast(msg, type = 'info') {
    const item = document.createElement('div');
    item.className = `knx-addr-toast__item knx-addr-toast__item--${type}`;
    item.textContent = msg;
    dom.toast.appendChild(item);
    setTimeout(() => item.remove(), 3200);
  }

  /* ════════ HELPERS ════════ */

  function esc(text) {
    if (!text) return '';
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
  }

  function showLoading(show) {
    if (dom.loading) dom.loading.style.display = show ? 'flex' : 'none';
  }

  /* ════════ GLOBAL EXPORTS (delegated from render) ════════ */
  window.knxEditAddress   = (id) => openModal(id);
  window.knxDeleteAddress = (id) => deleteAddress(id);
  window.knxSetDefault    = (id) => setDefault(id);
  window.knxSelectAddress = (id) => selectAddress(id);

  /* ════════ START ════════ */
  init();

})();
