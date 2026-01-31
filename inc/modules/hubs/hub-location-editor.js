/**
 * ==========================================================
 * Kingdom Nexus - Hub Location Editor (v5.0 CANONICAL)
 * ----------------------------------------------------------
 * Clean, modular JavaScript for hub location editing
 * Features:
 * - Dual Maps: Google Maps (primary) + Leaflet (fallback)
 * - Polygon drawing with markers
 * - Radius preview circle
 * - Address geocoding
 * - Consistent error handling
 * ==========================================================
 */

 (function() {
  'use strict';

  // ========================================
  // STATE MANAGEMENT
  // ========================================
  const STATE = {
    hubId: null,
    apiGet: null,
    apiSave: null,
    nonce: null,
    mapsKey: null,
    useLeaflet: false,
    hubData: null,
    provider: null,
    googleMap: null,
    googleMarker: null,
    googleCircle: null,
    googlePolygon: null,
    googleDrawingMarkers: [],
    leafletMap: null,
    leafletMarker: null,
    leafletCircle: null,
    leafletPolygon: null,
    leafletDrawingMarkers: [],
    isDrawing: false,
    polygonPath: [],
    radiusVisible: false,
    autocomplete: null,
    autocompleteTimer: null,
    autocompleteActive: false,
    autocompleteMode: 'early', // 'early' | 'normal'
    autocompleteEarlyThreshold: 3,
    autocompleteNormalThreshold: 5,
    autocompleteDebounceMs: 500,
    eventsBound: false,
    mapClicksBound: false
    ,
    isAddressLocked: false,
    tempMarker: null,
    originalCoords: null
  };

  // ========================================
  // DOM REFERENCES
  // ========================================
  const DOM = {
    wrapper: null,
    addressInput: null,
    latInput: null,
    lngInput: null,
    radiusInput: null,
    mapDiv: null,
    saveBtn: null,
    coverageStatus: null,
    startDrawingBtn: null,
    completePolygonBtn: null,
    clearPolygonBtn: null,
    toggleRadiusBtn: null,
    polygonStatus: null
    ,
    googleAssistBtn: null,
    searchBtn: null,
    geocodeResults: null,
    geocodeList: null,
    geocodeStatus: null,
    selectedPreview: null,
    selectedDisplay: null,
    selectedCoords: null,
    useSelectedBtn: null,
    replaceAddressBtn: null,
    clearSelectionBtn: null,
    coordinateMode: null,
    manualLat: null,
    manualLng: null,
    applyManualBtn: null,
    cancelManualBtn: null
  };

  // Initialization
  document.addEventListener('DOMContentLoaded', init);

  // DIAGNOSTIC FLAG: when true we load Places JS but do NOT initialize any map (client-side experiments)
  // Set to `false` in production. This is temporary and reversible.
  const DIAG_PLACES_ONLY = false;

  function init() {
    DOM.wrapper = document.querySelector('.knx-hub-location-editor');
    if (!DOM.wrapper) return;

    STATE.hubId = DOM.wrapper.dataset.hubId;
    STATE.apiGet = DOM.wrapper.dataset.apiGet;
    STATE.apiSave = DOM.wrapper.dataset.apiSave;
    STATE.nonce = DOM.wrapper.dataset.nonce;
    STATE.mapsKey = window.KNX_MAPS_CONFIG?.key || null;

    cacheDOMElements();
    // Hide the legacy "Get coordinates" / Google Assist button entirely — modal handles coords.
    try { if (DOM.googleAssistBtn) DOM.googleAssistBtn.style.display = 'none'; } catch (e) {}
    // Ensure Clear button exists (hidden by default) next to Search
    ensureClearButton();
    suppressMapsErrors();
    // Adjust UI for provider
    const provider = (typeof window.KNX_LOCATION_PROVIDER !== 'undefined') ? window.KNX_LOCATION_PROVIDER : 'nominatim';
    STATE.provider = provider;
    // If Google provider is in use, simplify UI: hide coordinates/search UI and enable Google autocomplete
    if (provider === 'google') {
      if (DOM.searchBtn) DOM.searchBtn.style.display = 'none';
      if (DOM.googleAssistBtn) DOM.googleAssistBtn.style.display = 'none';
      if (DOM.geocodeResults) DOM.geocodeResults.style.display = 'none';
    }
    loadHubData();
  }

  // Create a Clear button next to the Search button. Visible only when an address is confirmed/locked.
  function ensureClearButton() {
    try {
      if (!DOM.searchBtn) return;
      // If already created, hook reference
      if (DOM.clearAddressBtn) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'knxClearAddress';
      btn.className = 'knx-btn knx-btn-ghost';
      btn.textContent = 'Clear';
      btn.style.marginLeft = '8px';
      btn.style.display = 'none';
      btn.addEventListener('click', function() {
        clearAddressLock();
      });
      DOM.searchBtn.parentNode && DOM.searchBtn.parentNode.insertBefore(btn, DOM.searchBtn.nextSibling);
      DOM.clearAddressBtn = btn;
    } catch (e) {
      // If DOM layout unexpected, ignore — Clear remains unavailable
    }
  }

  function cacheDOMElements() {
    DOM.addressInput = document.getElementById('knxHubAddress');
    DOM.latInput = document.getElementById('knxHubLat');
    DOM.lngInput = document.getElementById('knxHubLng');
    DOM.radiusInput = document.getElementById('knxDeliveryRadius');
    DOM.mapDiv = document.getElementById('knxMap');
    DOM.saveBtn = document.getElementById('knxSaveLocation');
    DOM.coverageStatus = document.getElementById('knxCoverageStatus');
    DOM.startDrawingBtn = document.getElementById('knxStartDrawing');
    DOM.completePolygonBtn = document.getElementById('knxCompletePolygon');
    DOM.clearPolygonBtn = document.getElementById('knxClearPolygon');
    DOM.toggleRadiusBtn = document.getElementById('knxToggleRadius');
    DOM.polygonStatus = document.getElementById('knxPolygonStatus');
    DOM.googleAssistBtn = document.getElementById('knxGoogleAssist');
    DOM.searchBtn = document.getElementById('knxSearchAddress');
    DOM.geocodeResults = document.getElementById('knxGeocodeResults');
    DOM.geocodeList = document.getElementById('knxGeocodeList');
    DOM.geocodeStatus = document.getElementById('knxGeocodeStatus');
    DOM.selectedPreview = document.getElementById('knxSelectedPreview');
    DOM.selectedDisplay = document.getElementById('knxSelectedDisplay');
    DOM.selectedCoords = document.getElementById('knxSelectedCoords');
    DOM.useSelectedBtn = document.getElementById('knxUseSelected');
    DOM.replaceAddressBtn = document.getElementById('knxReplaceAddress');
    DOM.clearSelectionBtn = document.getElementById('knxClearSelection');
    DOM.coordinateMode = document.getElementById('knxCoordinateMode');
    DOM.manualLat = document.getElementById('knxManualLat');
    DOM.manualLng = document.getElementById('knxManualLng');
    DOM.applyManualBtn = document.getElementById('knxApplyManualCoords');
    DOM.cancelManualBtn = document.getElementById('knxCancelManualCoords');
    DOM.addressStatusBadge = document.getElementById('knxAddressStatusBadge');
  }


  function suppressMapsErrors() {
    window.addEventListener('error', (e) => {
      if (e.message && e.message.includes('mapsjs/gen_204')) {
        e.stopImmediatePropagation();
        return true;
      }
    }, true);
  }

  // Load hub data
  async function loadHubData() {
    try {
      const res = await fetch(STATE.apiGet, { headers: { 'X-WP-Nonce': STATE.nonce } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json = await res.json();
      if (!json.success || !json.data) {
        showToast('Failed to load hub data', 'error');
        return;
      }

      STATE.hubData = json.data;
      DOM.addressInput.value = json.data.address_label || json.data.address || '';
      DOM.latInput.value = json.data.lat || 41.1179;
      DOM.lngInput.value = json.data.lng || -87.8656;
      DOM.radiusInput.value = json.data.delivery_radius || 5;
      // Restore saved resolution/source if present
      STATE.locationSource = json.data.location_source || null;
      STATE.addressResolutionStatus = json.data.address_resolution_status || null;
      // If hub was previously set by external/manual coordinates, respect locked state
      STATE.isAddressLocked = !!(json.data.location_source && (json.data.location_source === 'external_map' || json.data.location_source === 'manual'));

      updateCoverageStatus();
      initMap();
      setAddressLocked(STATE.isAddressLocked);
      // In Google provider, keep the input editable regardless of previous lock
      if (STATE.provider === 'google') setAddressLocked(false);
      updateAddressStatusBadge();
    } catch (err) {
      showToast('Error loading hub data', 'error');
      console.error(err);
    }
  }

  // Map initialization: prefer Google if key present, else Leaflet
  function initMap() {
    if (!STATE.mapsKey || STATE.mapsKey === 'null' || STATE.mapsKey === '') {
      STATE.useLeaflet = true;
      initLeafletMap();
      return;
    }

    loadGoogleMaps()
      .then(() => {
        // Diagnostic mode: optionally skip creating a Google Map so we can test Places-only autocomplete.
        if (typeof DIAG_PLACES_ONLY !== 'undefined' && DIAG_PLACES_ONLY) {
          try {
            if (DOM.mapDiv) DOM.mapDiv.style.display = 'none';
          } catch (e) {}
          // Do not initialize map; only bind event listeners needed for the editor.
          setupEventListeners();
          return;
        }

        initGoogleMap();
        // When provider is google, enable Places autocomplete
        if (STATE.provider === 'google') {
          try { setupGoogleAutocomplete(); } catch (e) {}
        }
        setupEventListeners();
      })
      .catch((err) => {
        console.warn('Google Maps failed, falling back to Leaflet', err);
        STATE.useLeaflet = true;
        initLeafletMap();
      });
  }

  function loadGoogleMaps() {
    return new Promise((resolve, reject) => {
      if (window.google && window.google.maps) return resolve();
      const needsPlaces = (typeof window.KNX_LOCATION_PROVIDER !== 'undefined' && window.KNX_LOCATION_PROVIDER === 'google') || (typeof DIAG_PLACES_ONLY !== 'undefined' && DIAG_PLACES_ONLY);
      const libs = needsPlaces ? '&libraries=places' : '';
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${STATE.mapsKey}${libs}`;
      script.async = true;
      script.onload = () => {
        setTimeout(() => {
          if (window.google && window.google.maps) resolve();
          else reject(new Error('Google Maps did not initialize'));
        }, 200);
      };
      script.onerror = (e) => reject(e);
      document.head.appendChild(script);
    });
  }

  // Google maps init
  function initGoogleMap() {
    const lat = parseFloat(DOM.latInput.value) || 41.1179;
    const lng = parseFloat(DOM.lngInput.value) || -87.8656;
    STATE.googleMap = new google.maps.Map(DOM.mapDiv, { center: { lat, lng }, zoom: 13 });

        // Main editor marker is display-only; editing happens inside modal.
        STATE.googleMarker = new google.maps.Marker({ position: { lat, lng }, map: STATE.googleMap, draggable: false });
    // Google Places autocomplete intentionally disabled by default.
    // Nominatim provides the canonical autocomplete for address interpretation.
    // setupGoogleAutocomplete();
    loadExistingPolygons();
  }

  function setupGoogleAutocomplete() {
    try {
      const ac = new google.maps.places.Autocomplete(DOM.addressInput, { types: ['address'], componentRestrictions: { country: 'us' } });
      ac.bindTo('bounds', STATE.googleMap);
      ac.addListener('place_changed', () => {
        const place = ac.getPlace();
        if (!place.geometry) return showToast('No location found for that address', 'warning');
        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();
        updateCoordinates(lat, lng);
        STATE.googleMap.setCenter({ lat, lng });
        STATE.googleMap.setZoom(15);
        STATE.googleMarker.setPosition({ lat, lng });
        DOM.addressInput.value = place.formatted_address || DOM.addressInput.value;
        // mark source and resolution
        STATE.locationSource = 'google';
        STATE.addressResolutionStatus = 'resolved';
        // Google autocomplete should not lock the address
        try { setAddressLocked(false); } catch (e) {}
        updateAddressStatusBadge();
      });
    } catch (err) {
      // ignore
    }
  }

function initLeafletMap() {
  if (!window.L) {
    loadLeafletLibrary()
      .then(() => {
        createLeafletMap();
        setupEventListeners();
      })
      .catch(err => console.error(err));
  } else {
    createLeafletMap();
    setupEventListeners(); // ✅ critical: bind events when Leaflet already present
  }
}


  function loadLeafletLibrary() {
    return new Promise((resolve, reject) => {
      if (window.L) return resolve();
      const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(css);
      const script = document.createElement('script'); script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; script.onload = () => setTimeout(resolve, 100); script.onerror = reject; document.head.appendChild(script);
    });
  }

  function createLeafletMap() {
    const lat = parseFloat(DOM.latInput.value) || 41.1179;
    const lng = parseFloat(DOM.lngInput.value) || -87.8656;
    STATE.leafletMap = L.map(DOM.mapDiv).setView([lat, lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(STATE.leafletMap);
    // Main editor marker is display-only; editing happens inside modal.
    STATE.leafletMarker = L.marker([lat, lng], { draggable: false }).addTo(STATE.leafletMap);
    // Leaflet geocoding disabled: Nominatim handles address text interpretation.
    // setupLeafletGeocoding();
    loadExistingPolygons();
  }

  function setupLeafletGeocoding() {
    DOM.addressInput.addEventListener('keypress', async (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const address = DOM.addressInput.value.trim(); if (!address) return;
      try {
        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}&countrycodes=us&limit=1`);
        const data = await res.json();
        if (data && data.length) {
          const lat = parseFloat(data[0].lat); const lng = parseFloat(data[0].lon);
          updateCoordinates(lat, lng);
          STATE.leafletMap.setView([lat, lng], 15);
          STATE.leafletMarker.setLatLng([lat, lng]);
          DOM.addressInput.value = data[0].display_name;
          showToast('Location found', 'success');
        } else showToast('Location not found', 'warning');
      } catch (err) { showToast('Geocoding failed', 'error'); }
    });
  }

  // Polygons
  function loadExistingPolygons() {
    if (!STATE.hubData || !STATE.hubData.delivery_zones) return;
    const zones = STATE.hubData.delivery_zones; if (!zones.length) return;
    const zone = zones[0]; if (!zone.polygon_points || zone.polygon_points.length < 3) return;
    if (STATE.useLeaflet) loadLeafletPolygon(zone); else loadGooglePolygon(zone);
  }

  function loadGooglePolygon(zone) {
    const path = zone.polygon_points.map(p => ({ lat: p.lat, lng: p.lng }));
    STATE.googlePolygon = new google.maps.Polygon({ paths: path, strokeColor: zone.stroke_color || '#0b793a', strokeOpacity: 1, strokeWeight: zone.stroke_weight || 2, fillColor: zone.fill_color || '#0b793a', fillOpacity: zone.fill_opacity || 0.35, editable: false, map: STATE.googleMap });
    STATE.polygonPath = zone.polygon_points; DOM.clearPolygonBtn.disabled = false; updateCoverageStatus();
  }

  function loadLeafletPolygon(zone) {
    const latLngs = zone.polygon_points.map(p => [p.lat, p.lng]);
    STATE.leafletPolygon = L.polygon(latLngs, { color: zone.stroke_color || '#0b793a', weight: zone.stroke_weight || 2, fillColor: zone.fill_color || '#0b793a', fillOpacity: zone.fill_opacity || 0.35 }).addTo(STATE.leafletMap);
    STATE.polygonPath = zone.polygon_points; DOM.clearPolygonBtn.disabled = false; updateCoverageStatus();
  }

  // Drawing controls
  function setupEventListeners() {
    // Prevent double-binding
    if (STATE.eventsBound) return;
    STATE.eventsBound = true;

    if (DOM.startDrawingBtn) DOM.startDrawingBtn.addEventListener('click', startDrawing);
    if (DOM.completePolygonBtn) DOM.completePolygonBtn.addEventListener('click', completePolygon);
    if (DOM.clearPolygonBtn) DOM.clearPolygonBtn.addEventListener('click', clearPolygon);
    if (DOM.toggleRadiusBtn) DOM.toggleRadiusBtn.addEventListener('click', toggleRadiusPreview);
    // Legacy Google Assist / Get Coordinates button removed from active bindings.
    // Coordinates are managed exclusively via the modal; do not bind DOM.googleAssistBtn.
    if (DOM.searchBtn) DOM.searchBtn.addEventListener('click', onSearchButtonClick);
    // NOTE: autocomplete and geocoding now happen ONLY inside the modal.
    // The main `addressInput` is display-only (snapshot). Do NOT bind autocomplete here.
    if (DOM.useSelectedBtn) DOM.useSelectedBtn.addEventListener('click', applySelectedFromPreview);
    if (DOM.replaceAddressBtn) DOM.replaceAddressBtn.addEventListener('click', replaceAddressFromPreview);
    if (DOM.clearSelectionBtn) DOM.clearSelectionBtn.addEventListener('click', clearSelectionPreview);
    if (DOM.applyManualBtn) DOM.applyManualBtn.addEventListener('click', applyManualCoords);
    if (DOM.cancelManualBtn) DOM.cancelManualBtn.addEventListener('click', () => toggleCoordinateMode(false));
    if (DOM.saveBtn) DOM.saveBtn.addEventListener('click', saveLocation);

    if (DOM.radiusInput) {
      DOM.radiusInput.addEventListener('input', () => {
        const el = document.getElementById('knxRadiusFallbackText');
        if (el) el.textContent = DOM.radiusInput.value;
        updateCoverageStatus();
      });
    }

    attachMapClickListeners();
  }

  function onSearchButtonClick(e) {
    // Per spec: Search ALWAYS opens the modal-driven Location Decision Engine.
    // Do not perform any external searches from the main editor.
    openCoordinatesModal(false);
  }

  // Geocode search flow (explicit — user clicks Search)
  async function doGeocodeSearch(e) {
    try {
      const address = DOM.addressInput ? DOM.addressInput.value.trim() : '';
      if (!address) { showToast('Enter an address to search', 'warning'); return; }
      if (!window.KNX_GEOCODE_API) { showToast('Geocode API not configured', 'error'); return; }

      // UI state
      if (DOM.geocodeResults) { DOM.geocodeResults.style.display = 'block'; }
      if (DOM.geocodeStatus) { DOM.geocodeStatus.textContent = 'Searching...'; }
      clearResults();

      const url = `${window.KNX_GEOCODE_API}?q=${encodeURIComponent(address)}`;
      const res = await fetch(url, { headers: { 'X-WP-Nonce': STATE.nonce } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json = await res.json();

      // Flexible response handling: accept array or { success,data }
      let items = [];
      if (Array.isArray(json)) items = json;
      else if (json && Array.isArray(json.data)) items = json.data;
      else if (json && Array.isArray(json.results)) items = json.results;
      else if (json && json.display && json.lat) items = [json];

      if (!items.length) {
        if (DOM.geocodeStatus) DOM.geocodeStatus.textContent = "We couldn't resolve this address precisely.";
        // Fallback: open the Coordinates modal (legacy coordinateMode removed)
        openCoordinatesModal(false);
        return;
      }

      // Limit to 5 results
      items = items.slice(0, 5);
      renderResults(items);
      if (DOM.geocodeStatus) DOM.geocodeStatus.textContent = `Found ${items.length} result${items.length>1?'s':''}. Click an entry to preview.`;
    } catch (err) {
      console.error('Geocode search error', err);
      showToast('Address search failed', 'error');
      if (DOM.geocodeStatus) DOM.geocodeStatus.textContent = 'Search failed. Try again or use manual coordinates.';
      // Open modal fallback instead of legacy coordinate panel
      openCoordinatesModal(false);
    }
  }

  // Lightweight autocomplete handler (user typing)
  function onAddressInput(e) {
    // Conditions to not run autocomplete
    if (STATE.provider === 'google' || STATE.isAddressLocked) { clearAutocomplete(); return; }
    if (!DOM.addressInput) return;
    if (DOM.addressInput.readOnly) { clearAutocomplete(); return; }

    const val = DOM.addressInput.value.trim();
    // Determine threshold based on mode
    const mode = STATE.autocompleteMode || 'normal';
    const earlyThreshold = Number(STATE.autocompleteEarlyThreshold) || 3;
    const normalThreshold = Number(STATE.autocompleteNormalThreshold) || 5;
    const debounceMs = Number(STATE.autocompleteDebounceMs) || 500;

    // In early mode we trigger on fewer chars
    const thresh = (mode === 'early') ? earlyThreshold : normalThreshold;
    if (!val || val.length < thresh) { clearAutocomplete(); return; }

    // Debounce
    if (STATE.autocompleteTimer) clearTimeout(STATE.autocompleteTimer);
    STATE.autocompleteTimer = setTimeout(() => {
      // Build query differently when in 'early' mode
      const query = (mode === 'early') ? buildEarlyQuery(val) : val;
      if (!query || query.length < 1) { clearAutocomplete(); return; }
      runAutocompleteQuery(query);
    }, debounceMs);
  }

  async function runAutocompleteQuery(q) {
    // runAutocompleteQuery: accepts raw user input; we'll normalize for US addresses
    if (!q || !window.KNX_GEOCODE_API) return;
    // Prevent running when provider switched to google or address locked meanwhile
    if (STATE.provider === 'google' || STATE.isAddressLocked) return;

    STATE.autocompleteActive = true;
    try {
      // Primary: normalized US address
      const normalized = normalizeUSAddress(q);
      const params = `q=${encodeURIComponent(normalized)}&countrycodes=us&addressdetails=1&dedupe=1&limit=5&namedetails=1&format=json`;
      let url = `${window.KNX_GEOCODE_API}?${params}`;
      let res = await fetch(url, { headers: { 'X-WP-Nonce': STATE.nonce } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      let json = await res.json();

      let items = [];
      if (Array.isArray(json)) items = json;
      else if (json && Array.isArray(json.data)) items = json.data;
      else if (json && Array.isArray(json.results)) items = json.results;

      // Fallback: try a reduced query (number + street + city) if no results
      if ((!items || !items.length)) {
        const reduced = buildReducedQuery(normalized);
        if (reduced && reduced.length) {
          const params2 = `q=${encodeURIComponent(reduced)}&countrycodes=us&addressdetails=1&dedupe=1&limit=5&namedetails=1&format=json`;
          const url2 = `${window.KNX_GEOCODE_API}?${params2}`;
          try {
            const res2 = await fetch(url2, { headers: { 'X-WP-Nonce': STATE.nonce } });
            if (res2.ok) {
              const json2 = await res2.json();
              if (Array.isArray(json2)) items = json2;
              else if (json2 && Array.isArray(json2.data)) items = json2.data;
              else if (json2 && Array.isArray(json2.results)) items = json2.results;
            }
          } catch (err2) {
            // ignore secondary errors
            console.warn('Autocomplete fallback failed', err2);
          }
        }
      }

      if (!items || !items.length) { clearAutocomplete(); return; }
      renderAutocomplete(items.slice(0,5));
      // If we were in early mode and the input now exceeds normal threshold, transition to normal
      const curVal = DOM.addressInput ? DOM.addressInput.value.trim() : '';
      if (STATE.autocompleteMode === 'early' && curVal.length >= (STATE.autocompleteNormalThreshold || 5)) {
        STATE.autocompleteMode = 'normal';
      }
    } catch (err) {
      // Quietly fail (autocomplete is advisory)
      console.warn('Autocomplete query failed', err);
      clearAutocomplete();
    } finally {
      STATE.autocompleteActive = false;
    }
  }

  function renderAutocomplete(items) {
    if (!DOM.geocodeResults || !DOM.geocodeList) return;
    DOM.geocodeResults.style.display = 'block';
    DOM.geocodeList.innerHTML = '';
    items.forEach((it) => {
      const row = document.createElement('div');
      row.className = 'knx-autocomplete-item';
      row.style.padding = '6px 8px';
      row.style.cursor = 'pointer';
      row.style.borderBottom = '1px solid #eef2f7';

      const title = document.createElement('div');
      title.textContent = it.display || it.address || it.display_name || '';
      title.style.fontSize = '14px'; title.style.fontWeight = '500';

      const meta = document.createElement('div');
      meta.style.fontSize = '12px'; meta.style.color = '#6b7280'; meta.textContent = (it.provider || 'nominatim');

      row.appendChild(title); row.appendChild(meta);
      row.addEventListener('click', function(ev) { ev.preventDefault(); onSuggestionClick(it); });
      DOM.geocodeList.appendChild(row);
    });
  }

  // Build a compact early query from user input for better early matching
  function buildEarlyQuery(input) {
    if (!input) return '';
    // Remove commas and excessive whitespace
    let s = input.replace(/,/g, ' ').replace(/\s+/g, ' ').trim();
    // Remove common country/state tokens to reduce noise
    s = s.replace(/\b(usa|united states|united states of america|us|il|illinois)\b/ig, '').trim();
    if (!s) return '';
    const parts = s.split(' ');
    // If first token looks like a street number and next tokens exist, take first 2-3 tokens
    if (parts[0] && parts[0].length >= 2 && /\d/.test(parts[0])) {
      return parts.slice(0, Math.min(3, parts.length)).join(' ');
    }
    // Otherwise prefer the first 2 words (or first word if short)
    if (parts.length === 1) return parts[0];
    if (parts[0].length >= 3) return parts.slice(0, Math.min(3, parts.length)).join(' ');
    return parts.slice(0, Math.min(2, parts.length)).join(' ');
  }

  // Lightweight US address normalizer to improve Nominatim hit-rate.
  // Pure, frontend-only heuristics: expand common directionals and street suffixes,
  // remove country tokens, collapse punctuation and extra whitespace.
  function normalizeUSAddress(q) {
    if (!q || !q.trim()) return q;
    let s = String(q);
    // Lowercase for normalization, then restore case when displaying
    s = s.replace(/[,]/g, ' ');
    s = s.replace(/\s+/g, ' ').trim();
    // Replace common directionals when standalone (e.g., ' W ' -> ' West ')
    s = s.replace(/\bN\b/ig, 'North').replace(/\bS\b/ig, 'South').replace(/\bE\b/ig, 'East').replace(/\bW\b/ig, 'West');
    // Replace common abbreviations for street types
    s = s.replace(/\bSt\b\.?/ig, 'Street').replace(/\bRd\b\.?/ig, 'Road').replace(/\bAve\b\.?/ig, 'Avenue').replace(/\bBlvd\b\.?/ig, 'Boulevard').replace(/\bLn\b\.?/ig, 'Lane').replace(/\bDr\b\.?/ig, 'Drive').replace(/\bCt\b\.?/ig, 'Court');
    // Expand common short forms like w. -> west
    s = s.replace(/\bW\.\b/ig, 'West').replace(/\bE\.\b/ig, 'East').replace(/\bN\.\b/ig, 'North').replace(/\bS\.\b/ig, 'South');
    // Remove country/state verbosity
    s = s.replace(/\b(usa|united states( of america)?|united states|u\.s\.a\.|us)\b/ig, '');
    // Trim and collapse spaces
    s = s.replace(/\s+/g, ' ').trim();
    return s;
  }

  // Build a reduced fallback query from a normalized string: try to keep number + street + city
  function buildReducedQuery(normalized) {
    if (!normalized) return '';
    const s = normalized.replace(/\s+/g, ' ').trim();
    // Try to capture a leading house number + street
    const m = s.match(/^(\d+\s+[^,]+)(?:,\s*([^,]+))?/);
    if (m) {
      // return number + street + optional city
      return (m[1] + (m[2] ? (', ' + m[2]) : '')).trim();
    }
    // As fallback, return up to first 5 tokens
    const parts = s.split(' ');
    return parts.slice(0, Math.min(5, parts.length)).join(' ');
  }

  function onSuggestionClick(item) {
    if (!item) return;
    const lat = item.lat || item.latitude || (item.location && item.location.lat) || item.geometry?.lat || null;
    const lng = item.lon || item.lng || item.longitude || (item.location && item.location.lng) || item.geometry?.lng || null;
    const housenumber = item.housenumber || item.address?.housenumber || item.house_number || null;
    if (lat == null || lng == null) return;

    // Update state, move pin and update canonical inputs. Do NOT replace or lock address.
    STATE.locationSource = item.provider || 'nominatim';
    STATE.addressResolutionStatus = housenumber ? 'resolved' : 'unresolved_house_number';
    // User selected a suggestion -> move to normal mode for subsequent typing
    STATE.autocompleteMode = 'normal';
    updateAddressStatusBadge();

    // Use canonical updater so inputs are SSOT and map syncs
    updateCoordinates(parseFloat(lat), parseFloat(lng));
    // Hide suggestions
    clearAutocomplete();
  }

  function clearAutocomplete() {
    if (STATE.autocompleteTimer) { clearTimeout(STATE.autocompleteTimer); STATE.autocompleteTimer = null; }
    if (DOM.geocodeList) DOM.geocodeList.innerHTML = '';
    if (DOM.geocodeResults) DOM.geocodeResults.style.display = 'none';
    STATE.autocompleteActive = false;
  }

  function clearResults() {
    if (DOM.geocodeList) DOM.geocodeList.innerHTML = '';
    if (DOM.selectedPreview) DOM.selectedPreview.style.display = 'none';
    if (DOM.selectedDisplay) DOM.selectedDisplay.textContent = '';
    if (DOM.selectedCoords) DOM.selectedCoords.textContent = '';
  }

  function renderResults(items) {
    if (!DOM.geocodeList) return;
    DOM.geocodeList.innerHTML = '';
    items.forEach((it, idx) => {
      const container = document.createElement('div');
      container.className = 'knx-result-item';
      container.style.padding = '8px';
      container.style.border = '1px solid #e5e7eb';
      container.style.borderRadius = '8px';
      container.style.background = '#fff';

      const title = document.createElement('div');
      title.style.fontWeight = '600';
      title.textContent = it.display || it.address || it.display_name || '';

      const meta = document.createElement('div');
      meta.style.fontSize = '13px'; meta.style.color = '#6b7280'; meta.style.marginTop = '6px';
      const provider = it.provider || 'nominatim';
      const housenumber = it.housenumber || it.address?.housenumber || it.house_number || null;
      meta.textContent = `${provider}${housenumber ? ' · House number found' : ' · No house number'}`;

      const actions = document.createElement('div');
      actions.style.marginTop = '8px';
      const previewBtn = document.createElement('button');
      previewBtn.className = 'knx-btn knx-btn-sm knx-btn-secondary';
      previewBtn.textContent = 'Preview';
      previewBtn.addEventListener('click', () => previewResult(it));

      actions.appendChild(previewBtn);
      container.appendChild(title);
      container.appendChild(meta);
      container.appendChild(actions);

      DOM.geocodeList.appendChild(container);
    });
  }

  function previewResult(item) {
    if (!DOM.selectedPreview) return;
    DOM.selectedPreview.style.display = 'block';
    DOM.selectedDisplay.textContent = item.display || item.address || item.display_name || '';
    const lat = item.lat || item.latitude || (item.location && item.location.lat) || item.geometry?.lat || '';
    const lng = item.lng || item.longitude || (item.location && item.location.lng) || item.geometry?.lng || '';
    DOM.selectedCoords.textContent = `Lat: ${lat} · Lng: ${lng} · Provider: ${item.provider || 'nominatim'}`;
    // Store currently previewed item on DOM for apply
    DOM.selectedPreview._current = item;
    // Track resolution status: whether housenumber exists
    const housenumber = item.housenumber || item.address?.housenumber || item.house_number || null;
    if (housenumber) STATE.addressResolutionStatus = 'resolved'; else STATE.addressResolutionStatus = 'unresolved_house_number';
    // mark pending source as nominatim for now
    STATE.locationSource = item.provider || 'nominatim';
    updateAddressStatusBadge();
  }

  function applySelectedFromPreview() {
    if (!DOM.selectedPreview || !DOM.selectedPreview._current) return;
    const item = DOM.selectedPreview._current;
    const payload = { display: item.display || item.address || item.display_name || '', lat: item.lat || item.latitude || item.geometry?.lat || item.location?.lat, lng: item.lng || item.longitude || item.geometry?.lng || item.location?.lng, provider: item.provider || 'nominatim' };
    // Do NOT replace the address input automatically (user must opt-in to replace it)
    // Update state to indicate selection and move pin via canonical hook
    STATE.locationSource = payload.provider || 'nominatim';
    // addressResolutionStatus was set in previewResult; keep it
    if (window.KNX_HUB_LOCATION && typeof window.KNX_HUB_LOCATION.applySelectedLocation === 'function') {
      window.KNX_HUB_LOCATION.applySelectedLocation(payload);
    } else if (typeof applySelectedLocation === 'function') {
      applySelectedLocation(payload);
    } else {
      // Fallback: use canonical updater so inputs remain SSOT and maps sync
      updateCoordinates(payload.lat, payload.lng);
    }
    // Close results
    clearResults();
    if (DOM.geocodeResults) DOM.geocodeResults.style.display = 'none';
    updateAddressStatusBadge();
  }

  function replaceAddressFromPreview() {
    if (!DOM.selectedPreview || !DOM.selectedPreview._current) return;
    const item = DOM.selectedPreview._current;
    const suggested = item.display || item.address || item.display_name || '';
    if (!confirm('This will replace the original address text with the suggested format. Continue?')) return;
    if (DOM.addressInput) DOM.addressInput.value = suggested;
    // optional: mark that address text was replaced by user
    STATE.addressTextReplaced = true;
    clearResults();
    if (DOM.geocodeResults) DOM.geocodeResults.style.display = 'none';
    showToast('Address text replaced with suggested format', 'success');
    updateAddressStatusBadge();
  }

  function clearSelectionPreview() { clearResults(); if (DOM.geocodeResults) DOM.geocodeResults.style.display = 'none'; }

  function toggleCoordinateMode(show) {
    if (!DOM.coordinateMode) return;
    DOM.coordinateMode.style.display = show ? 'block' : 'none';
    if (show) {
      // Pre-fill with current coords if present
      if (DOM.manualLat) DOM.manualLat.value = DOM.latInput ? DOM.latInput.value : '';
      if (DOM.manualLng) DOM.manualLng.value = DOM.lngInput ? DOM.lngInput.value : '';
    }
  }

  // Open a richer Coordinates modal that lets the user paste coords, preview on map, or open external map.
  function openCoordinatesModal(fromExternal) {
    const overlay = document.createElement('div');
    overlay.style.position = 'fixed'; overlay.style.left = 0; overlay.style.top = 0; overlay.style.right = 0; overlay.style.bottom = 0;
    overlay.style.background = 'rgba(0,0,0,0.35)'; overlay.style.display = 'flex'; overlay.style.alignItems = 'center'; overlay.style.justifyContent = 'center'; overlay.style.zIndex = 99999;

    const modal = document.createElement('div');
    modal.style.maxWidth = '760px'; modal.style.width = '96%'; modal.style.background = '#fff'; modal.style.padding = '18px'; modal.style.borderRadius = '10px'; modal.style.boxShadow = '0 8px 32px rgba(0,0,0,0.18)';

    const title = document.createElement('h3'); title.textContent = 'Exact address required — coordinates will be used'; title.style.marginTop = '0';
    const p = document.createElement('p'); p.textContent = "Paste or edit the exact address. The map pin (latitude & longitude) defines the real location.";
    p.style.color = '#374151';

    const formRow = document.createElement('div'); formRow.style.display = 'flex'; formRow.style.flexDirection = 'column'; formRow.style.gap = '8px'; formRow.style.marginTop = '10px';
    const addrLabel = document.createElement('input'); addrLabel.type = 'text'; addrLabel.value = DOM.addressInput ? DOM.addressInput.value : ''; addrLabel.readOnly = false; addrLabel.placeholder = 'Paste exact address used in Google'; addrLabel.style.width = '100%'; addrLabel.className = 'knx-input knx-input-sm';
    const controls = document.createElement('div'); controls.style.display = 'flex'; controls.style.gap = '8px'; controls.style.alignItems = 'center'; controls.style.marginTop = '6px';
    const latInput = document.createElement('input'); latInput.type = 'text'; latInput.placeholder = 'Latitude'; latInput.className = 'knx-input knx-input-sm'; latInput.style.width = '140px';
    const lngInput = document.createElement('input'); lngInput.type = 'text'; lngInput.placeholder = 'Longitude'; lngInput.className = 'knx-input knx-input-sm'; lngInput.style.width = '140px';
    const openExternal = document.createElement('button'); openExternal.className = 'knx-btn knx-btn-secondary'; openExternal.textContent = 'Open external map';
    const previewBtn = document.createElement('button'); previewBtn.className = 'knx-btn knx-btn-primary'; previewBtn.textContent = 'Preview on map';
    controls.appendChild(latInput); controls.appendChild(lngInput); controls.appendChild(previewBtn); controls.appendChild(openExternal);

    // mini map placeholder
    const mapWrap = document.createElement('div'); mapWrap.style.height = '200px'; mapWrap.style.marginTop = '10px'; mapWrap.style.border = '1px solid #e5e7eb'; mapWrap.style.borderRadius = '6px';

    // Modal-local geocode results (autocomplete + search results live here)
    const modalGeocodeResults = document.createElement('div');
    modalGeocodeResults.style.display = 'none';
    modalGeocodeResults.style.marginTop = '8px';
    modalGeocodeResults.style.maxHeight = '220px';
    modalGeocodeResults.style.overflowY = 'auto';
    modalGeocodeResults.style.border = '1px solid #e6eef6';
    modalGeocodeResults.style.borderRadius = '6px';
    modalGeocodeResults.style.background = '#fff';
    const modalGeocodeList = document.createElement('div');
    modalGeocodeList.style.padding = '6px';
    modalGeocodeResults.appendChild(modalGeocodeList);

    // Insert results area before the mini-map for quick preview
    modal.appendChild(modalGeocodeResults);

    const btnRow = document.createElement('div'); btnRow.style.display = 'flex'; btnRow.style.justifyContent = 'flex-end'; btnRow.style.gap = '8px'; btnRow.style.marginTop = '12px';
    const applyBtn = document.createElement('button'); applyBtn.className = 'knx-btn knx-btn-primary'; applyBtn.textContent = 'Apply coordinates';
    const cancelBtn = document.createElement('button'); cancelBtn.className = 'knx-btn knx-btn-secondary'; cancelBtn.textContent = 'Cancel';
    btnRow.appendChild(cancelBtn); btnRow.appendChild(applyBtn);

    formRow.appendChild(addrLabel); formRow.appendChild(controls);
    modal.appendChild(title); modal.appendChild(p); modal.appendChild(formRow);
    // small hint
    const hint = document.createElement('div'); hint.style.fontSize = '13px'; hint.style.color = '#6b7280'; hint.style.marginTop = '8px'; hint.textContent = 'Use Preview to see the pin. You can drag the pin to adjust coordinates.';
    modal.appendChild(hint);
    modal.appendChild(mapWrap);
    modal.appendChild(btnRow);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Prefill with current coords
    latInput.value = DOM.latInput ? DOM.latInput.value : '';
    lngInput.value = DOM.lngInput ? DOM.lngInput.value : '';

    // Modal-local map + marker
    const modalMapId = 'knxModalMap_' + Date.now() + '_' + Math.floor(Math.random()*1000);
    mapWrap.id = modalMapId;
    let modalMap = null;
    let modalMarker = null;
    let externalClicked = !!fromExternal;
    // Track a selected suggestion provider and whether it had a house number
    let modalSelectedProvider = null;
    let modalSelectedHousenumber = null;

    // close modal by clicking overlay
    const onOverlayClick = function(e) {
      if (e.target === overlay) {
        try { removeModalMap(); document.body.removeChild(overlay); } catch(e) {}
        document.removeEventListener('keydown', onKeyDown);
      }
    };
    overlay.addEventListener('click', onOverlayClick);

    // close on ESC
    const onKeyDown = function(e) {
      if (e.key === 'Escape' || e.key === 'Esc') {
        try { removeModalMap(); document.body.removeChild(overlay); } catch(e) {}
        document.removeEventListener('keydown', onKeyDown);
        overlay.removeEventListener('click', onOverlayClick);
      }
    };
    document.addEventListener('keydown', onKeyDown);

    function createModalMap(lat, lng) {
      removeModalMap();
      const initialLat = (typeof lat !== 'undefined' && lat !== null) ? parseFloat(lat) : (DOM.latInput ? parseFloat(DOM.latInput.value) : 41.1179);
      const initialLng = (typeof lng !== 'undefined' && lng !== null) ? parseFloat(lng) : (DOM.lngInput ? parseFloat(DOM.lngInput.value) : -87.8656);
      // Prefer Leaflet for modal preview if available or if main map is Leaflet
      if (STATE.useLeaflet || window.L) {
        const ensure = () => {
          try {
            modalMap = L.map(modalMapId, { attributionControl: false, zoomControl: false }).setView([initialLat, initialLng], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(modalMap);
            modalMarker = L.marker([initialLat, initialLng], { draggable: true }).addTo(modalMap);
            modalMarker.on('dragend', function(e) { const p = e.target.getLatLng(); latInput.value = p.lat; lngInput.value = p.lng; });
          } catch (e) {
            // ignore map init errors
          }
        };
        if (!window.L) {
          loadLeafletLibrary().then(ensure).catch(() => {});
        } else ensure();
      } else if (window.google || STATE.mapsKey) {
        // Attempt Google Maps modal map
        loadGoogleMaps().then(() => {
          try {
            modalMap = new google.maps.Map(document.getElementById(modalMapId), { center: { lat: initialLat, lng: initialLng }, zoom: 13 });
            modalMarker = new google.maps.Marker({ position: { lat: initialLat, lng: initialLng }, map: modalMap, draggable: true });
            modalMarker.addListener('dragend', function() { const p = modalMarker.getPosition(); latInput.value = p.lat(); lngInput.value = p.lng(); });
          } catch (e) {}
        }).catch(() => {});
      }
    }

    function removeModalMap() {
      try {
        if (!modalMap) return;
        if (STATE.useLeaflet || window.L) {
          modalMap.remove();
        } else if (window.google && modalMap) {
            // clear Google map
            const el = document.getElementById(modalMapId);
            if (el) el.innerHTML = '';
          }
      } catch (e) {}
      modalMap = null; modalMarker = null;
    }

    previewBtn.addEventListener('click', function() {
      const lat = parseFloat(latInput.value); const lng = parseFloat(lngInput.value);
      if (isNaN(lat) || isNaN(lng)) { showToast('Enter valid numeric coordinates to preview', 'warning'); return; }
      createModalMap(lat, lng);
      if (modalMap && (STATE.useLeaflet || window.L)) modalMap.setView([lat, lng], 15);
      else if (modalMap && window.google) modalMap.setCenter({ lat, lng });
    });

    // Modal autocomplete: run only inside modal using its own UI
    let modalTimer = null;
    function modalClearAutocomplete() {
      try { if (modalTimer) { clearTimeout(modalTimer); modalTimer = null; } } catch(e) {}
      modalGeocodeList.innerHTML = '';
      modalGeocodeResults.style.display = 'none';
    }

    addrLabel.addEventListener('input', function() {
      const val = String(addrLabel.value || '').trim();
      const mode = STATE.autocompleteMode || 'normal';
      const earlyThreshold = Number(STATE.autocompleteEarlyThreshold) || 3;
      const normalThreshold = Number(STATE.autocompleteNormalThreshold) || 5;
      const thresh = (mode === 'early') ? earlyThreshold : normalThreshold;
      if (!val || val.length < thresh) { modalClearAutocomplete(); return; }
      if (modalTimer) clearTimeout(modalTimer);
      modalTimer = setTimeout(() => {
        const query = (mode === 'early') ? buildEarlyQuery(val) : val;
        if (!query) { modalClearAutocomplete(); return; }
        modalRunAutocomplete(query);
      }, Number(STATE.autocompleteDebounceMs) || 500);
    });
    addrLabel.addEventListener('blur', function() { setTimeout(modalClearAutocomplete, 180); });

    async function modalRunAutocomplete(q) {
      if (!q || !window.KNX_GEOCODE_API) return;
      modalGeocodeResults.style.display = 'none';
      try {
        const normalized = normalizeUSAddress(q);
        const params = `q=${encodeURIComponent(normalized)}&countrycodes=us&addressdetails=1&dedupe=1&limit=6&namedetails=1&format=json`;
        const url = `${window.KNX_GEOCODE_API}?${params}`;
        const res = await fetch(url, { headers: { 'X-WP-Nonce': STATE.nonce } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        let items = [];
        if (Array.isArray(json)) items = json;
        else if (json && Array.isArray(json.data)) items = json.data;
        else if (json && Array.isArray(json.results)) items = json.results;

        if ((!items || !items.length)) {
          const reduced = buildReducedQuery(normalized);
          if (reduced && reduced.length) {
            const params2 = `q=${encodeURIComponent(reduced)}&countrycodes=us&addressdetails=1&dedupe=1&limit=6&namedetails=1&format=json`;
            const url2 = `${window.KNX_GEOCODE_API}?${params2}`;
            try {
              const res2 = await fetch(url2, { headers: { 'X-WP-Nonce': STATE.nonce } });
              if (res2.ok) {
                const json2 = await res2.json();
                if (Array.isArray(json2)) items = json2;
                else if (json2 && Array.isArray(json2.data)) items = json2.data;
                else if (json2 && Array.isArray(json2.results)) items = json2.results;
              }
            } catch (err2) { console.warn('Modal autocomplete fallback failed', err2); }
          }
        }
        if (!items || !items.length) { modalClearAutocomplete(); return; }
        modalRenderAutocomplete(items.slice(0,6));
      } catch (err) {
        console.warn('Modal autocomplete failed', err);
        modalClearAutocomplete();
      }
    }

    function modalRenderAutocomplete(items) {
      modalGeocodeList.innerHTML = '';
      items.forEach((it) => {
        const row = document.createElement('div');
        row.style.padding = '8px'; row.style.cursor = 'pointer'; row.style.borderBottom = '1px solid #eef2f7';
        const title = document.createElement('div'); title.style.fontWeight = '600'; title.textContent = it.display || it.address || it.display_name || '';
        const meta = document.createElement('div'); meta.style.fontSize = '12px'; meta.style.color = '#6b7280'; meta.textContent = it.provider || 'nominatim';
        row.appendChild(title); row.appendChild(meta);
        row.addEventListener('click', function(ev) { ev.preventDefault(); modalOnSuggestionClick(it); });
        modalGeocodeList.appendChild(row);
      });
      modalGeocodeResults.style.display = 'block';
    }

    function modalOnSuggestionClick(item) {
      if (!item) return;
      const lat = item.lat || item.latitude || (item.location && item.location.lat) || item.geometry?.lat || null;
      const lng = item.lon || item.lng || item.longitude || (item.location && item.location.lng) || item.geometry?.lng || null;
      if (lat == null || lng == null) return;
      // Fill modal inputs and preview
      addrLabel.value = item.display || item.address || item.display_name || addrLabel.value;
      latInput.value = lat; lngInput.value = lng;
      modalSelectedProvider = item.provider || 'nominatim';
      modalSelectedHousenumber = item.housenumber || item.address?.housenumber || item.house_number || null;
      // create or update modal marker
      try { createModalMap(lat, lng); } catch(e) {}
      // keep modal results visible for user to preview/apply
      modalGeocodeResults.style.display = 'block';
    }

    // Initialize modal mini-map immediately so user sees pin without pressing Preview
    try {
      createModalMap(latInput.value, lngInput.value);
      setTimeout(() => { try { if (modalMap && modalMap.invalidateSize) modalMap.invalidateSize(); } catch (e) {} }, 60);
    } catch (e) {}

    openExternal.addEventListener('click', function() {
      // Prefer an explicit config URL, otherwise open latlong.net as the external tool
      const defaultUrl = window.KNX_COORD_TOOL_URL || 'https://www.latlong.net/';
      const q = encodeURIComponent(addrLabel.value || DOM.addressInput.value || '');
      try {
        const urlToOpen = (defaultUrl && defaultUrl.indexOf('{q}') !== -1) ? defaultUrl.replace('{q}', q) : defaultUrl;
        window.open(urlToOpen, '_blank', 'noopener,noreferrer');
        externalClicked = true;
      } catch (e) {
        if (window && typeof window.openGoogleMaps === 'function') {
          window.openGoogleMaps(addrLabel.value || DOM.addressInput.value || '');
          externalClicked = true;
        }
      }
    });

    cancelBtn.addEventListener('click', function() { removeModalMap(); try { document.body.removeChild(overlay); } catch(e) {} document.removeEventListener('keydown', onKeyDown); overlay.removeEventListener('click', onOverlayClick); });

    applyBtn.addEventListener('click', function() {
      const lat = parseFloat(latInput.value); const lng = parseFloat(lngInput.value);
      if (isNaN(lat) || isNaN(lng)) { showToast('Enter valid numeric coordinates before applying', 'warning'); return; }
      // Apply to main inputs and replace address text with modal value (this becomes the locked text)
      if (DOM.latInput) DOM.latInput.value = lat; if (DOM.lngInput) DOM.lngInput.value = lng;
      if (DOM.addressInput) DOM.addressInput.value = addrLabel.value;
      // determine source/status based on user selection or external flag
      const source = modalSelectedProvider || (externalClicked ? 'external_map' : 'manual');
      let status = 'manual_confirmed';
      if (source === 'nominatim') {
        status = modalSelectedHousenumber ? 'resolved' : 'unresolved_house_number';
      } else if (source === 'external_map') {
        status = 'manual_confirmed';
      }
      STATE.locationSource = source;
      STATE.addressResolutionStatus = status;
      // Lock the address input per spec
      setAddressLocked(true);
      // Move main marker to applied coords (canonical hook)
      if (window.KNX_HUB_LOCATION && typeof window.KNX_HUB_LOCATION.applySelectedLocation === 'function') {
        window.KNX_HUB_LOCATION.applySelectedLocation({ display: DOM.addressInput ? DOM.addressInput.value : '', lat, lng, provider: STATE.locationSource });
      } else {
        updateCoordinates(lat, lng);
        if (STATE.useLeaflet && STATE.leafletMap && STATE.leafletMarker) { STATE.leafletMap.setView([lat, lng], 15); STATE.leafletMarker.setLatLng([lat, lng]); }
        else if (STATE.googleMap && STATE.googleMarker) { STATE.googleMap.setCenter({ lat, lng }); STATE.googleMap.setZoom(15); STATE.googleMarker.setPosition({ lat, lng }); }
      }
      removeModalMap();
      try { document.body.removeChild(overlay); } catch(e) {}
      document.removeEventListener('keydown', onKeyDown);
      overlay.removeEventListener('click', onOverlayClick);
      showToast('Coordinates applied and address locked', 'success');
    });
  }

  function applyManualCoords() {
    if (!DOM.manualLat || !DOM.manualLng) return;
    const lat = parseFloat(DOM.manualLat.value.trim());
    const lng = parseFloat(DOM.manualLng.value.trim());
    if (isNaN(lat) || isNaN(lng)) { showToast('Enter valid numeric coordinates', 'warning'); return; }
    // Update inputs but do not overwrite address
    if (DOM.latInput) DOM.latInput.value = lat;
    if (DOM.lngInput) DOM.lngInput.value = lng;
    // Determine source: external_map if coordinate mode was opened from external map, otherwise manual
    STATE.locationSource = STATE.openedCoordinateModeFromExternal ? 'external_map' : 'manual';
    // Move pin explicitly via canonical hook
    if (window.KNX_HUB_LOCATION && typeof window.KNX_HUB_LOCATION.applySelectedLocation === 'function') {
      window.KNX_HUB_LOCATION.applySelectedLocation({ display: DOM.addressInput ? DOM.addressInput.value : '', lat, lng, provider: STATE.locationSource });
    } else {
      updateCoordinates(lat, lng);
      if (STATE.useLeaflet && STATE.leafletMap && STATE.leafletMarker) { STATE.leafletMap.setView([lat, lng], 15); STATE.leafletMarker.setLatLng([lat, lng]); }
      else if (STATE.googleMap && STATE.googleMarker) { STATE.googleMap.setCenter({ lat, lng }); STATE.googleMap.setZoom(15); STATE.googleMarker.setPosition({ lat, lng }); }
    }
    STATE.openedCoordinateModeFromExternal = false;
    // Mark resolution status as manually confirmed when manual/external coords were used
    STATE.addressResolutionStatus = 'manual_confirmed';
    updateAddressStatusBadge();
  }

  function attachMapClickListeners() {
    // Prevent double-binding map click handlers
    if (STATE.mapClicksBound) return;
    STATE.mapClicksBound = true;

    if (STATE.useLeaflet && STATE.leafletMap) {
      // Leaflet already handles tap-to-click on mobile; touchend often lacks latlng
      STATE.leafletMap.on('click', handleMapClick);
    } else if (STATE.googleMap) {
      STATE.googleMap.addListener('click', handleMapClick);
    }
  }

  function startDrawing() {
    STATE.polygonPath = [];
    clearDrawingMarkers();
    if (STATE.leafletPolygon) { STATE.leafletMap.removeLayer(STATE.leafletPolygon); STATE.leafletPolygon = null; }
    if (STATE.googlePolygon) { STATE.googlePolygon.setMap(null); STATE.googlePolygon = null; }
    STATE.isDrawing = true;
    if (DOM.startDrawingBtn) DOM.startDrawingBtn.disabled = true;
    if (DOM.completePolygonBtn) DOM.completePolygonBtn.disabled = false;
    if (DOM.clearPolygonBtn) DOM.clearPolygonBtn.disabled = true;
    if (DOM.polygonStatus) DOM.polygonStatus.innerHTML = '<strong>Drawing Mode:</strong> Click or tap points on the map to create polygon';
    showToast('Drawing mode enabled. Click/tap points on map.', 'info');
  }

  function handleMapClick(e) {
    if (!STATE.isDrawing) return;

    let lat, lng;

    if (STATE.useLeaflet) {
      if (!e || !e.latlng) return; // Defensive: ignore invalid events
      lat = e.latlng.lat;
      lng = e.latlng.lng;
    } else {
      if (!e || !e.latLng) return; // Defensive: ignore invalid events
      lat = e.latLng.lat();
      lng = e.latLng.lng();
    }

    STATE.polygonPath.push({ lat, lng });

    if (STATE.useLeaflet) {
      const m = L.circleMarker([lat, lng], {
        radius: 8,
        color: '#fff',
        weight: 2,
        fillColor: '#0b793a',
        fillOpacity: 1
      }).addTo(STATE.leafletMap);
      STATE.leafletDrawingMarkers.push(m);
    } else {
      const m = new google.maps.Marker({
        position: { lat, lng },
        map: STATE.googleMap,
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 8,
          fillColor: '#0b793a',
          fillOpacity: 1,
          strokeColor: '#fff',
          strokeWeight: 2
        }
      });
      STATE.googleDrawingMarkers.push(m);
    }

    if (DOM.polygonStatus) {
      DOM.polygonStatus.innerHTML = `<strong>${STATE.polygonPath.length} points</strong> placed. Need at least 3 to complete.`;
    }
  }

  function completePolygon() {
    if (STATE.polygonPath.length < 3) return showToast('Need at least 3 points to complete polygon', 'warning');

    if (STATE.useLeaflet) {
      const latLngs = STATE.polygonPath.map(p => [p.lat, p.lng]);
      STATE.leafletPolygon = L.polygon(latLngs, {
        color: '#0b793a',
        weight: 2,
        fillColor: '#0b793a',
        fillOpacity: 0.35
      }).addTo(STATE.leafletMap);
    } else {
      const path = STATE.polygonPath.map(p => ({ lat: p.lat, lng: p.lng }));
      STATE.googlePolygon = new google.maps.Polygon({
        paths: path,
        strokeColor: '#0b793a',
        strokeOpacity: 1,
        strokeWeight: 2,
        fillColor: '#0b793a',
        fillOpacity: 0.35,
        map: STATE.googleMap
      });
    }

    STATE.isDrawing = false; // Stop capturing points after polygon completion

    clearDrawingMarkers();
    if (DOM.startDrawingBtn) DOM.startDrawingBtn.disabled = false;
    if (DOM.completePolygonBtn) DOM.completePolygonBtn.disabled = true;
    if (DOM.clearPolygonBtn) DOM.clearPolygonBtn.disabled = false;
    if (DOM.polygonStatus) DOM.polygonStatus.innerHTML = `<strong>✅ Polygon Complete:</strong> ${STATE.polygonPath.length} points. Click "Save" to apply.`;
    updateCoverageStatus();
    showToast('Polygon completed', 'success');
  }

  function clearPolygon() {
    STATE.polygonPath = []; STATE.isDrawing = false; clearDrawingMarkers();
    if (STATE.leafletPolygon) { STATE.leafletMap.removeLayer(STATE.leafletPolygon); STATE.leafletPolygon = null; }
    if (STATE.googlePolygon) { STATE.googlePolygon.setMap(null); STATE.googlePolygon = null; }
    if (DOM.startDrawingBtn) DOM.startDrawingBtn.disabled = false; if (DOM.completePolygonBtn) DOM.completePolygonBtn.disabled = true; if (DOM.clearPolygonBtn) DOM.clearPolygonBtn.disabled = true; if (DOM.polygonStatus) DOM.polygonStatus.innerHTML = '<strong>Instructions:</strong> Click "Start Drawing" → Click points on map → Click "Complete" when done'; updateCoverageStatus(); showToast('Polygon cleared', 'info');
  }

  function clearDrawingMarkers() {
    if (STATE.useLeaflet) { STATE.leafletDrawingMarkers.forEach(m => STATE.leafletMap.removeLayer(m)); STATE.leafletDrawingMarkers = []; } else { STATE.googleDrawingMarkers.forEach(m => m.setMap(null)); STATE.googleDrawingMarkers = []; }
  }

  // Radius preview
  function toggleRadiusPreview() { STATE.radiusVisible = !STATE.radiusVisible; if (STATE.radiusVisible) { showRadiusCircle(); if (DOM.toggleRadiusBtn) DOM.toggleRadiusBtn.textContent = '👁️ Hide Radius Preview'; } else { hideRadiusCircle(); if (DOM.toggleRadiusBtn) DOM.toggleRadiusBtn.textContent = '👁️ Show Radius Preview'; } }
  function showRadiusCircle() { const lat = parseFloat(DOM.latInput.value); const lng = parseFloat(DOM.lngInput.value); const radiusMiles = parseFloat(DOM.radiusInput.value) || 5; const radiusMeters = radiusMiles * 1609.34; if (STATE.useLeaflet) { if (STATE.leafletCircle) { STATE.leafletMap.removeLayer(STATE.leafletCircle); } STATE.leafletCircle = L.circle([lat, lng], { radius: radiusMeters, color: '#6366f1', fillColor: '#6366f1', fillOpacity: 0.1, weight: 2, dashArray: '5, 5' }).addTo(STATE.leafletMap); } else { if (STATE.googleCircle) STATE.googleCircle.setMap(null); STATE.googleCircle = new google.maps.Circle({ center: { lat, lng }, radius: radiusMeters, strokeColor: '#6366f1', strokeOpacity: 1, strokeWeight: 2, fillColor: '#6366f1', fillOpacity: 0.1, map: STATE.googleMap }); } }
  function hideRadiusCircle() { if (STATE.leafletCircle) { STATE.leafletMap.removeLayer(STATE.leafletCircle); STATE.leafletCircle = null; } if (STATE.googleCircle) { STATE.googleCircle.setMap(null); STATE.googleCircle = null; } }

  // Save location
  async function saveLocation() {
    const address_label = DOM.addressInput ? DOM.addressInput.value : '';
    const address = (DOM.addressInput && DOM.addressInput.value) ? DOM.addressInput.value.trim() : '';
    const lat = parseFloat(DOM.latInput.value);
    const lng = parseFloat(DOM.lngInput.value);
    const radius = parseFloat(DOM.radiusInput.value);
    if (!lat || !lng) { showToast('Please provide valid coordinates before saving', 'error'); return; }
    const hasPolygon = STATE.polygonPath.length >= 3; const zoneType = hasPolygon ? 'polygon' : 'radius';
    // Determine location_source and address_resolution_status
    const location_source = STATE.locationSource || 'manual';
    const address_resolution_status = STATE.addressResolutionStatus || (location_source === 'nominatim' ? 'resolved' : 'unresolved_house_number');
    const payload = { hub_id: STATE.hubId, address_label: address_label, lat, lng, location_source, address_resolution_status, delivery_radius: radius, delivery_zone_type: zoneType, polygon_points: hasPolygon ? STATE.polygonPath : [] };
    if (DOM.saveBtn) { DOM.saveBtn.disabled = true; DOM.saveBtn.textContent = '⏳ Saving...'; }
    try {
      const res = await fetch(STATE.apiSave, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': STATE.nonce }, body: JSON.stringify(payload) });
      const json = await res.json(); if (json.success) { showToast('✅ Location saved successfully', 'success'); console.log('Save response:', json); } else { showToast('Failed to save: ' + (json.message || 'Unknown error'), 'error'); console.error('Save error:', json); }
    } catch (err) { console.error('Save request error:', err); showToast('Error saving location', 'error'); }
    finally { if (DOM.saveBtn) { DOM.saveBtn.disabled = false; DOM.saveBtn.textContent = '💾 Save Location & Coverage'; } }
  }

  // Helpers
  function updateCoverageStatus() { if (STATE.polygonPath && STATE.polygonPath.length >= 3) { DOM.coverageStatus.className = 'knx-badge knx-badge-success'; DOM.coverageStatus.textContent = '✅ Polygon Configured'; } else { const radius = parseFloat(DOM.radiusInput.value) || 5; DOM.coverageStatus.className = 'knx-badge knx-badge-info'; DOM.coverageStatus.textContent = `🔵 Radius Fallback (${radius} mi)`; } }

  function updateCoordinates(lat, lng) {
    const nlat = (typeof lat === 'string') ? parseFloat(lat) : lat;
    const nlng = (typeof lng === 'string') ? parseFloat(lng) : lng;
    if (DOM.latInput) DOM.latInput.value = (typeof nlat !== 'undefined' && nlat !== null) ? String(nlat) : '';
    if (DOM.lngInput) DOM.lngInput.value = (typeof nlng !== 'undefined' && nlng !== null) ? String(nlng) : '';

    // Ensure map markers reflect the canonical inputs (inputs are SSOT)
    if (!isNaN(nlat) && !isNaN(nlng)) {
      try {
        if (STATE.useLeaflet && STATE.leafletMap && STATE.leafletMarker) {
          STATE.leafletMarker.setLatLng([nlat, nlng]);
          try { STATE.leafletMap.setView([nlat, nlng], 15); } catch (e) {}
        } else if (STATE.googleMap && STATE.googleMarker) {
          STATE.googleMarker.setPosition({ lat: nlat, lng: nlng });
          try { STATE.googleMap.setCenter({ lat: nlat, lng: nlng }); STATE.googleMap.setZoom(15); } catch (e) {}
        }
      } catch (e) {
        // swallow map update errors
      }
    }
  }

  function updateAddressStatusBadge() {
    if (!DOM.addressStatusBadge) return;
    const source = STATE.locationSource || 'manual';
    const status = STATE.addressResolutionStatus || (source === 'nominatim' ? 'resolved' : 'unresolved_house_number');
    const sourceLabel = source === 'external_map' ? 'External map' : (source === 'manual' ? 'Manual' : (source === 'nominatim' ? 'Nominatim' : (source === 'google' ? 'Google' : source)));
    let statusLabel = '';
    if (status === 'resolved') statusLabel = 'House number resolved';
    else if (status === 'unresolved_house_number') statusLabel = 'No house number';
    else if (status === 'manual_confirmed') statusLabel = 'Manually confirmed';
    else statusLabel = status;
    DOM.addressStatusBadge.textContent = `Source: ${sourceLabel} · ${statusLabel}`;
  }

  // Lock/unlock address input UI and state
  function setAddressLocked(locked) {
    STATE.isAddressLocked = !!locked;
    if (DOM.addressInput) {
      DOM.addressInput.readOnly = !!locked;
      if (locked) DOM.addressInput.classList.add('knx-readonly'); else DOM.addressInput.classList.remove('knx-readonly');
    }
    // Per spec: Search always visible and opens modal; Clear is a separate button visible only when locked
    try { if (DOM.searchBtn) DOM.searchBtn.style.display = 'inline-block'; } catch(e) {}
    try { if (DOM.clearAddressBtn) DOM.clearAddressBtn.style.display = locked ? 'inline-block' : 'none'; } catch(e) {}

    // Enforce main map marker non-draggable when locked (and keep non-draggable otherwise per modal-driven UX)
    try {
      if (STATE.googleMarker && typeof STATE.googleMarker.setDraggable === 'function') STATE.googleMarker.setDraggable(false);
    } catch (e) {}
    try {
      if (STATE.leafletMarker && STATE.leafletMarker.dragging) {
        try { STATE.leafletMarker.dragging.disable(); } catch (ee) {}
      }
    } catch (e) {}

    updateAddressStatusBadge();
  }

  function clearAddressLock() {
    setAddressLocked(false);
    STATE.locationSource = null;
    STATE.addressResolutionStatus = null;
    // Reset autocomplete state fully so Clear acts like first-time mount
    try {
      if (STATE.autocompleteTimer) { clearTimeout(STATE.autocompleteTimer); STATE.autocompleteTimer = null; }
    } catch (e) {}
    STATE.autocompleteActive = false;
    STATE.autocompleteMode = 'early';
    // hide/clear geocode results and previews
    if (DOM.geocodeList) DOM.geocodeList.innerHTML = '';
    if (DOM.geocodeResults) DOM.geocodeResults.style.display = 'none';
    clearResults();
    // Reset the main address snapshot and coordinates so user can start fresh
    try { if (DOM.addressInput) DOM.addressInput.value = ''; } catch (e) {}
    try { if (DOM.latInput) DOM.latInput.value = ''; if (DOM.lngInput) DOM.lngInput.value = ''; } catch (e) {}
    try { updateCoverageStatus(); } catch (e) {}
    // Focus and select the address input so user can quickly edit
    try { if (DOM.addressInput) { DOM.addressInput.focus(); DOM.addressInput.select(); } } catch (e) {}
    showToast('Address unlocked', 'info');
  }

  function showToast(message, type = 'info') { if (typeof knxToast === 'function') knxToast(message, type); else console.log(`[${type.toUpperCase()}] ${message}`); }

  // Coordinate Assist: open Google Maps in a new tab with the current address.
  // This is intentionally minimal: no validation, no API calls — user-driven only.
  // NOTE: `openGoogleMaps` helper is defined in the UI template and exposed as
  // `window.openGoogleMaps`. Do not define a local duplicate here.

  // Expose a single, minimal hook so external autocomplete can apply a selected location
  function applySelectedLocation(obj) {
    try {
      if (!obj) return;
      // Do NOT overwrite the user's original address text automatically.
      // The system should only move the pin and update coordinates on explicit confirmation.
      const address = obj.address || obj.display || '';
      if (typeof obj.lat !== 'undefined' && typeof obj.lng !== 'undefined') {
        const lat = parseFloat(obj.lat);
        const lng = parseFloat(obj.lng);
        if (!isNaN(lat) && !isNaN(lng)) {
          // Update internal coordinates and markers
          updateCoordinates(lat, lng);
          if (STATE.useLeaflet && STATE.leafletMap && STATE.leafletMarker) {
            try { STATE.leafletMap.setView([lat, lng], 15); STATE.leafletMarker.setLatLng([lat, lng]); } catch(e) {}
          } else if (STATE.googleMap && STATE.googleMarker) {
            try { STATE.googleMap.setCenter({ lat, lng }); STATE.googleMap.setZoom(15); STATE.googleMarker.setPosition({ lat, lng }); } catch(e) {}
          }
        }
      }
    } catch (e) {
      console.error('applySelectedLocation error', e);
    }
  }

  window.KNX_HUB_LOCATION = window.KNX_HUB_LOCATION || {};
  window.KNX_HUB_LOCATION.applySelectedLocation = applySelectedLocation;

})();
