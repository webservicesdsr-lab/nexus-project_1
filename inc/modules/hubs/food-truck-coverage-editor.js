/**
 * ==========================================================
 * KNX Food Truck Delivery Coverage Editor (v1.0)
 * ----------------------------------------------------------
 * Polygon / radius drawing for food-truck delivery zones.
 * Uses Leaflet (fallback) or Google Maps (if key present).
 * Shares the same REST endpoint as hub-location-editor.js
 * but is scoped to the #knxFtMap element and its own controls.
 * ==========================================================
 */
(function () {
  'use strict';

  const cfg = window.KNX_FT_MAPS_CONFIG;
  if (!cfg) return;

  const PREFIX = 'knxFt';

  /* ── STATE ──────────────────────────────────────── */
  const STATE = {
    hubId:    cfg.hubId,
    lat:      cfg.initialLat,
    lng:      cfg.initialLng,
    radius:   cfg.initialRadius,
    polygon:  null,       // Leaflet polygon layer or Google polygon
    points:   [],         // [{lat, lng}, …]
    drawing:  false,
    markers:  [],         // drawing vertex markers
    radiusCircle: null,
    showRadius: false,
    useGoogle: false,
    map: null,
    marker: null,
  };

  /* ── DOM ────────────────────────────────────────── */
  const wrapper         = document.querySelector('.knx-ft-coverage-editor');
  if (!wrapper) return;

  const mapDiv          = document.getElementById(PREFIX + 'Map');
  const latInput        = document.getElementById(PREFIX + 'Lat');
  const lngInput        = document.getElementById(PREFIX + 'Lng');
  const radiusInput     = document.getElementById(PREFIX + 'DeliveryRadius');
  const radiusFallback  = document.getElementById(PREFIX + 'RadiusFallbackText');
  const coverageStatus  = document.getElementById(PREFIX + 'CoverageStatus');
  const polygonStatus   = document.getElementById(PREFIX + 'PolygonStatus');
  const startDrawBtn    = document.getElementById(PREFIX + 'StartDrawing');
  const completeBtn     = document.getElementById(PREFIX + 'CompletePolygon');
  const clearBtn        = document.getElementById(PREFIX + 'ClearPolygon');
  const toggleRadiusBtn = document.getElementById(PREFIX + 'ToggleRadius');
  const saveBtn         = document.getElementById(PREFIX + 'SaveCoverage');

  const apiGet   = wrapper.dataset.apiGet;
  const apiSave  = wrapper.dataset.apiSave;
  const nonce    = wrapper.dataset.nonce;

  /* ── INIT ───────────────────────────────────────── */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  function boot() {
    loadExistingData().then(function () {
      initMap();
      bindEvents();
    });
  }

  /* ── LOAD EXISTING DATA ─────────────────────────── */
  async function loadExistingData() {
    if (!apiGet) return;
    try {
      const res = await fetch(apiGet, {
        headers: { 'X-WP-Nonce': nonce },
        credentials: 'same-origin'
      });
      const json = await res.json();
      if (json.success && json.data) {
        const d = json.data;
        if (d.lat)  STATE.lat = parseFloat(d.lat);
        if (d.lng)  STATE.lng = parseFloat(d.lng);
        if (d.delivery_radius) STATE.radius = parseFloat(d.delivery_radius);

        // Load polygon from delivery_zones
        var zones = d.delivery_zones || d.zones || [];
        if (zones.length > 0) {
          var zone = zones[0];
          var pts  = zone.polygon_points || zone.polygon || [];
          if (pts.length >= 3) {
            STATE.points = pts.map(function(p) {
              // Support both [lat,lng] arrays and {lat,lng} objects
              if (Array.isArray(p)) return { lat: parseFloat(p[0]), lng: parseFloat(p[1]) };
              return { lat: parseFloat(p.lat), lng: parseFloat(p.lng) };
            });
          }
        }

        // Sync inputs
        if (latInput)     latInput.value = STATE.lat;
        if (lngInput)     lngInput.value = STATE.lng;
        if (radiusInput)  radiusInput.value = STATE.radius;
        if (radiusFallback) radiusFallback.textContent = STATE.radius;
      }
    } catch (e) {
      console.warn('[FT-Coverage] Failed to load existing data:', e);
    }
  }

  /* ── MAP INIT (Leaflet only for simplicity — same approach as main editor) ── */
  function initMap() {
    if (!mapDiv) return;

    // Ensure Leaflet is loaded
    if (typeof L === 'undefined') {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
      document.head.appendChild(link);

      const script = document.createElement('script');
      script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
      script.onload = function() { buildMap(); };
      document.head.appendChild(script);
    } else {
      buildMap();
    }
  }

  function buildMap() {
    STATE.map = L.map(mapDiv).setView([STATE.lat, STATE.lng], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OSM'
    }).addTo(STATE.map);

    // Center marker (draggable — acts as centroid for radius fallback)
    STATE.marker = L.marker([STATE.lat, STATE.lng], { draggable: true }).addTo(STATE.map);
    STATE.marker.bindTooltip('Delivery centroid', { direction: 'top' });

    STATE.marker.on('dragend', function () {
      const pos = STATE.marker.getLatLng();
      STATE.lat = pos.lat;
      STATE.lng = pos.lng;
      if (latInput) latInput.value = pos.lat.toFixed(7);
      if (lngInput) lngInput.value = pos.lng.toFixed(7);
      updateRadiusCircle();
    });

    // Draw existing polygon if loaded
    if (STATE.points.length >= 3) {
      drawExistingPolygon();
    }

    updateCoverageStatus();
  }

  function drawExistingPolygon() {
    const latlngs = STATE.points.map(function(p) { return [p.lat, p.lng]; });
    STATE.polygon = L.polygon(latlngs, {
      color: '#0b793a',
      fillColor: '#0b793a',
      fillOpacity: 0.2,
      weight: 2
    }).addTo(STATE.map);

    STATE.map.fitBounds(STATE.polygon.getBounds(), { padding: [30, 30] });
    clearBtn.disabled = false;
    updateCoverageStatus();
  }

  /* ── EVENTS ─────────────────────────────────────── */
  function bindEvents() {
    startDrawBtn?.addEventListener('click', startDrawing);
    completeBtn?.addEventListener('click',  completePolygon);
    clearBtn?.addEventListener('click',     clearPolygon);
    toggleRadiusBtn?.addEventListener('click', toggleRadius);
    saveBtn?.addEventListener('click',       saveCoverage);

    radiusInput?.addEventListener('change', function () {
      STATE.radius = parseFloat(radiusInput.value) || 5;
      if (radiusFallback) radiusFallback.textContent = STATE.radius;
      updateRadiusCircle();
    });
  }

  /* ── DRAWING ────────────────────────────────────── */
  function startDrawing() {
    if (STATE.drawing) return;
    STATE.drawing = true;
    STATE.points  = [];
    STATE.markers = [];

    // Clear existing polygon
    if (STATE.polygon) {
      STATE.map.removeLayer(STATE.polygon);
      STATE.polygon = null;
    }

    startDrawBtn.disabled  = true;
    completeBtn.disabled   = false;
    clearBtn.disabled      = false;
    polygonStatus.innerHTML = '<strong>Drawing:</strong> Click on the map to add points. Need at least 3 points.';

    STATE.map.getContainer().style.cursor = 'crosshair';
    STATE.map.on('click', onMapClick);
  }

  function onMapClick(e) {
    if (!STATE.drawing) return;

    const pt = { lat: e.latlng.lat, lng: e.latlng.lng };
    STATE.points.push(pt);

    const m = L.circleMarker([pt.lat, pt.lng], {
      radius: 6, color: '#0b793a', fillColor: '#0b793a', fillOpacity: 1
    }).addTo(STATE.map);
    STATE.markers.push(m);

    polygonStatus.innerHTML = '<strong>Drawing:</strong> ' + STATE.points.length +
      ' point(s) placed. ' + (STATE.points.length < 3 ? 'Need at least 3.' : 'Click "Complete" when done.');
  }

  function completePolygon() {
    if (STATE.points.length < 3) {
      polygonStatus.innerHTML = '<strong>Error:</strong> Need at least 3 points to complete a polygon.';
      return;
    }

    STATE.drawing = false;
    STATE.map.off('click', onMapClick);
    STATE.map.getContainer().style.cursor = '';

    // Remove vertex markers
    STATE.markers.forEach(function(m) { STATE.map.removeLayer(m); });
    STATE.markers = [];

    // Draw polygon
    const latlngs = STATE.points.map(function(p) { return [p.lat, p.lng]; });
    STATE.polygon = L.polygon(latlngs, {
      color: '#0b793a',
      fillColor: '#0b793a',
      fillOpacity: 0.2,
      weight: 2
    }).addTo(STATE.map);

    STATE.map.fitBounds(STATE.polygon.getBounds(), { padding: [30, 30] });

    startDrawBtn.disabled  = false;
    completeBtn.disabled   = true;
    polygonStatus.innerHTML = '<strong>✅ Polygon complete</strong> — ' + STATE.points.length + ' vertices. Click "Save" to persist.';
    updateCoverageStatus();
  }

  function clearPolygon() {
    STATE.drawing = false;
    STATE.map.off('click', onMapClick);
    STATE.map.getContainer().style.cursor = '';

    if (STATE.polygon) {
      STATE.map.removeLayer(STATE.polygon);
      STATE.polygon = null;
    }
    STATE.markers.forEach(function(m) { STATE.map.removeLayer(m); });
    STATE.markers = [];
    STATE.points  = [];

    startDrawBtn.disabled  = false;
    completeBtn.disabled   = true;
    clearBtn.disabled      = true;
    polygonStatus.innerHTML = '<strong>Instructions:</strong> Click "Start Drawing" → Click points on map → Click "Complete" when done';
    updateCoverageStatus();
  }

  /* ── RADIUS ─────────────────────────────────────── */
  function toggleRadius() {
    STATE.showRadius = !STATE.showRadius;
    if (STATE.showRadius) {
      updateRadiusCircle();
    } else if (STATE.radiusCircle) {
      STATE.map.removeLayer(STATE.radiusCircle);
      STATE.radiusCircle = null;
    }
  }

  function updateRadiusCircle() {
    if (!STATE.showRadius || !STATE.map) return;
    if (STATE.radiusCircle) {
      STATE.map.removeLayer(STATE.radiusCircle);
    }
    // Radius is in miles → convert to meters
    const meters = STATE.radius * 1609.34;
    STATE.radiusCircle = L.circle([STATE.lat, STATE.lng], {
      radius: meters,
      color: '#3b82f6',
      fillColor: '#3b82f6',
      fillOpacity: 0.08,
      weight: 2,
      dashArray: '6,6'
    }).addTo(STATE.map);
  }

  /* ── STATUS ─────────────────────────────────────── */
  function updateCoverageStatus() {
    if (!coverageStatus) return;
    if (STATE.points.length >= 3 || STATE.polygon) {
      coverageStatus.className = 'knx-badge knx-badge-success';
      coverageStatus.innerHTML = '✅ Polygon Active (' + STATE.points.length + ' vertices)';
    } else if (STATE.radius > 0) {
      coverageStatus.className = 'knx-badge knx-badge-info';
      coverageStatus.innerHTML = '🔵 Radius Fallback (' + STATE.radius + ' mi)';
    } else {
      coverageStatus.className = 'knx-badge knx-badge-warning';
      coverageStatus.innerHTML = '⚠️ Not Configured';
    }
  }

  /* ── SAVE ───────────────────────────────────────── */
  async function saveCoverage() {
    saveBtn.disabled = true;
    saveBtn.innerHTML = '⏳ Saving…';

    const payload = {
      hub_id:          STATE.hubId,
      delivery_radius: STATE.radius,
      coverage_only:   true,          // Food truck: save zone only, not hub address
    };

    // Polygon → array of [lat, lng] pairs (backend canonical format)
    if (STATE.points.length >= 3) {
      payload.polygon_points = STATE.points.map(function(p) {
        return [p.lat, p.lng];
      });
      payload.delivery_zone_type = 'polygon';
    } else {
      payload.delivery_zone_type = 'radius';
    }

    try {
      const res = await fetch(apiSave, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });
      const json = await res.json();
      if (json.success) {
        showToast('success', 'Food truck delivery coverage saved ✓');
      } else {
        showToast('error', json.message || 'Save failed');
      }
    } catch (e) {
      showToast('error', 'Network error');
    }

    saveBtn.disabled = false;
    saveBtn.innerHTML = '💾 Save Food Truck Coverage';
  }

  /* ── TOAST ──────────────────────────────────────── */
  function showToast(type, msg) {
    if (typeof window.knxToast === 'function') {
      window.knxToast(type, msg);
    } else if (typeof window.KnxToast !== 'undefined' && typeof window.KnxToast.show === 'function') {
      window.KnxToast.show(type, msg);
    } else {
      const el = document.createElement('div');
      el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;color:#fff;font-weight:600;font-size:14px;box-shadow:0 4px 16px rgba(0,0,0,0.15);transition:opacity 0.3s;';
      el.style.background = type === 'success' ? '#0b793a' : '#dc2626';
      el.textContent = msg;
      document.body.appendChild(el);
      setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 300); }, 3000);
    }
  }

})();
