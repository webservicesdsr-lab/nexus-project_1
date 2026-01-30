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
    eventsBound: false,
    mapClicksBound: false
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
    suppressMapsErrors();
    loadHubData();
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
      DOM.addressInput.value = json.data.address || '';
      DOM.latInput.value = json.data.lat || 41.1179;
      DOM.lngInput.value = json.data.lng || -87.8656;
      DOM.radiusInput.value = json.data.delivery_radius || 5;

      updateCoverageStatus();
      initMap();
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

    STATE.googleMarker = new google.maps.Marker({ position: { lat, lng }, map: STATE.googleMap, draggable: true });
    STATE.googleMarker.addListener('dragend', () => {
      const p = STATE.googleMarker.getPosition();
      updateCoordinates(p.lat(), p.lng());
    });
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
    STATE.leafletMarker = L.marker([lat, lng], { draggable: true }).addTo(STATE.leafletMap);
    STATE.leafletMarker.on('dragend', () => { const p = STATE.leafletMarker.getLatLng(); updateCoordinates(p.lat, p.lng); });
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
    const address = DOM.addressInput.value.trim(); const lat = parseFloat(DOM.latInput.value); const lng = parseFloat(DOM.lngInput.value); const radius = parseFloat(DOM.radiusInput.value);
    if (!address || !lat || !lng) { showToast('Please enter a valid address and coordinates', 'error'); return; }
    const hasPolygon = STATE.polygonPath.length >= 3; const zoneType = hasPolygon ? 'polygon' : 'radius';
    const payload = { hub_id: STATE.hubId, address, lat, lng, delivery_radius: radius, delivery_zone_type: zoneType, polygon_points: hasPolygon ? STATE.polygonPath : [] };
    if (DOM.saveBtn) { DOM.saveBtn.disabled = true; DOM.saveBtn.textContent = '⏳ Saving...'; }
    try {
      const res = await fetch(STATE.apiSave, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': STATE.nonce }, body: JSON.stringify(payload) });
      const json = await res.json(); if (json.success) { showToast('✅ Location saved successfully', 'success'); console.log('Save response:', json); } else { showToast('Failed to save: ' + (json.message || 'Unknown error'), 'error'); console.error('Save error:', json); }
    } catch (err) { console.error('Save request error:', err); showToast('Error saving location', 'error'); }
    finally { if (DOM.saveBtn) { DOM.saveBtn.disabled = false; DOM.saveBtn.textContent = '💾 Save Location & Coverage'; } }
  }

  // Helpers
  function updateCoverageStatus() { if (STATE.polygonPath && STATE.polygonPath.length >= 3) { DOM.coverageStatus.className = 'knx-badge knx-badge-success'; DOM.coverageStatus.textContent = '✅ Polygon Configured'; } else { const radius = parseFloat(DOM.radiusInput.value) || 5; DOM.coverageStatus.className = 'knx-badge knx-badge-info'; DOM.coverageStatus.textContent = `🔵 Radius Fallback (${radius} mi)`; } }

  function updateCoordinates(lat, lng) { if (DOM.latInput) DOM.latInput.value = lat; if (DOM.lngInput) DOM.lngInput.value = lng; }

  function showToast(message, type = 'info') { if (typeof knxToast === 'function') knxToast(message, type); else console.log(`[${type.toUpperCase()}] ${message}`); }

  // Expose a single, minimal hook so external autocomplete can apply a selected location
  function applySelectedLocation(obj) {
    try {
      if (!obj) return;
      const address = obj.address || obj.display || '';
      if (DOM.addressInput) DOM.addressInput.value = address;
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
