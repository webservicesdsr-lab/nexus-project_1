/**
 * Kingdom Nexus — Edit Hub Location (v3.2 Dual Maps, Final)
 * ---------------------------------------------------------
 * - Primario Google Maps + Places (si hay API key)
 * - Fallback Leaflet + Nominatim (sin key)
 * - Autocomplete con sesgo por proximidad y parser US
 * - Formatea la dirección “estilo Google” antes de guardar
 *
 * Requiere:
 *  - wrapper .knx-edit-hub-wrapper con data:
 *    data-api-get, data-api-location, data-hub-id, data-nonce
 *  - #hubAddress, #deliveryRadius, #hubLat, #hubLng, #saveLocation, #map
 *  - window.KNX_MAPS_KEY (opcional)
 */

document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".knx-edit-hub-wrapper");
  if (!wrap) return;

  // APIs
  const API_GET  = wrap.dataset.apiGet;
  const API_SAVE = wrap.dataset.apiLocation;

  // State
  const state = {
    hubId: wrap.dataset.hubId,
    nonce: wrap.dataset.nonce,
    mapsKey: window.KNX_MAPS_KEY || null,
    useLeaflet: false,
    hubData: null, // Store full hub data including delivery_zones
    // Polygon state
    isDrawing: false,
    polygonPath: [],
    polygonMarkers: [],
    currentPolygon: null,
    // Map references (Google Maps or Leaflet)
    googleMap: null,
    googleMarker: null,
    googleCircle: null,
    leafletMap: null,
    leafletMarker: null,
    leafletCircle: null
  };

  // UI
  const addressInput = document.getElementById("hubAddress");
  const radiusInput  = document.getElementById("deliveryRadius");
  const latInput     = document.getElementById("hubLat");
  const lngInput     = document.getElementById("hubLng");
  const saveBtn      = document.getElementById("saveLocation");
  const mapDiv       = document.getElementById("map");

  // Polygon UI Elements
  const coverageStatusBadge = document.getElementById("coverageStatus");
  const startDrawingBtn = document.getElementById("startDrawing");
  const completePolygonBtn = document.getElementById("completePolygon");
  const clearPolygonBtn = document.getElementById("clearPolygon");
  const toggleRadiusBtn = document.getElementById("toggleRadius");
  const polygonStatusDiv = document.getElementById("polygonStatus");

  let radiusCircleVisible = false;

  const toast = (m,t="success") => (typeof knxToast === "function" ? knxToast(m,t) : alert(m));

  // Suprime errores de ping (ads blockers)
  window.addEventListener("error", (e) => {
    if (e.message && e.message.includes("mapsjs/gen_204")) {
      e.stopImmediatePropagation();
      return true;
    }
  });

  // ---------- Bootstrap ----------
  (async function init() {
    try {
      const res = await fetch(`${API_GET}?id=${state.hubId}`);
      const j   = await res.json();
      if (!j.success || !j.hub) return toast("Unable to load hub data","error");

      // Store hub data in state for later use
      state.hubData = j.hub;

      addressInput.value = j.hub.address || "";
      radiusInput.value  = j.hub.delivery_radius || 3;
      latInput.value     = j.hub.lat || 41.12;
      lngInput.value     = j.hub.lng || -87.86;

      console.log('🚀 Hub data loaded:', { 
        delivery_zone_type: j.hub.delivery_zone_type, 
        delivery_zones: j.hub.delivery_zones 
      });

      // Update coverage status badge
      updateCoverageStatus();

      initMap();
    } catch {
      toast("Error loading hub data","error");
    }
  })();

  // ---------- Map loader ----------
  function initMap() {
    if (!state.mapsKey) {
      state.useLeaflet = true;
      initLeaflet();
      return;
    }
    loadGoogleMaps(state.mapsKey)
      .then(initGoogle)
      .catch((err) => {
        console.warn("Google Maps failed → Leaflet fallback:", err);
        state.useLeaflet = true;
        initLeaflet();
      });
  }

  function loadGoogleMaps(key) {
    return new Promise((resolve, reject) => {
      if (window.google && window.google.maps) return resolve();
      const s = document.createElement("script");
      s.src = `https://maps.googleapis.com/maps/api/js?key=${key}&libraries=places`;
      s.async = true;
      s.onload = () => setTimeout(() => {
        (window.google && window.google.maps) ? resolve() : reject(new Error("GM init fail"));
      }, 600);
      s.onerror = () => reject(new Error("GM script error"));
      document.head.appendChild(s);
    });
  }

  // ========================================
  // POLYGON FUNCTIONS
  // ========================================
  
  function updateZoneUI() {
    const isPolygon = polygonRadio?.checked;
    
    // Use setProperty with priority 'important' to override PHP inline styles
    if (radiusOptions) {
      radiusOptions.style.setProperty('display', isPolygon ? 'none' : 'block', 'important');
    }
    if (polygonOptions) {
      polygonOptions.style.setProperty('display', isPolygon ? 'block' : 'none', 'important');
    }
    
    // Hide/show circle and polygon shapes on the map
    if (isPolygon) {
      // POLYGON MODE: Hide circle, keep polygon visible
      if (state.leafletCircle) {
        state.leafletCircle.setStyle({ opacity: 0, fillOpacity: 0 });
      }
      if (state.googleCircle) {
        state.googleCircle.setOptions({ strokeOpacity: 0, fillOpacity: 0 });
      }
    } else {
      // RADIUS MODE: Hide polygon, show circle
      if (state.currentPolygon) {
        if (state.leafletMap && state.currentPolygon.remove) {
          state.currentPolygon.remove();
        } else if (state.googleMap && state.currentPolygon.setMap) {
          state.currentPolygon.setMap(null);
        }
        state.currentPolygon = null;
      }
      // Remove polygon markers
      state.polygonMarkers.forEach(m => {
        if (m.remove) m.remove();
        else if (m.setMap) m.setMap(null);
      });
      state.polygonMarkers = [];
      state.polygonPath = [];
      
      // Show circle again
      if (state.leafletCircle) {
        state.leafletCircle.setStyle({ opacity: 1, fillOpacity: 0.25 });
      }
      if (state.googleCircle) {
        state.googleCircle.setOptions({ strokeOpacity: 1, fillOpacity: 0.25 });
      }
    }
    
    // Update status
    if (isPolygon) {
      updatePolygonStatus();
    }
    
    console.log('🔄 updateZoneUI:', { isPolygon, circleHidden: isPolygon, polygonVisible: isPolygon && state.polygonPath.length > 0 });
  }

  function updatePolygonStatus() {
    if (!polygonStatusDiv) return;
    
    const count = state.polygonPath.length;
    if (count === 0) {
      polygonStatusDiv.textContent = 'Click "Start Drawing" to begin';
      polygonStatusDiv.style.color = '#666';
    } else if (state.isDrawing) {
      polygonStatusDiv.textContent = `Drawing: ${count} point${count !== 1 ? 's' : ''} placed (minimum 3 required)`;
      polygonStatusDiv.style.color = '#0b793a';
    } else {
      polygonStatusDiv.textContent = `Polygon complete with ${count} points`;
      polygonStatusDiv.style.color = '#4caf50';
    }
  }

  function startPolygonDrawing() {
    state.isDrawing = true;
    state.polygonPath = [];
    state.polygonMarkers = [];
    
    // Clear existing polygon
    if (state.currentPolygon) {
      if (state.leafletMap && state.currentPolygon.remove) {
        state.currentPolygon.remove();
      } else if (state.googleMap && state.currentPolygon.setMap) {
        state.currentPolygon.setMap(null);
      }
      state.currentPolygon = null;
    }
    
    // Hide the circle when starting to draw polygon
    if (state.leafletCircle) {
      state.leafletCircle.setStyle({ opacity: 0, fillOpacity: 0 });
    }
    if (state.googleCircle) {
      state.googleCircle.setOptions({ strokeOpacity: 0, fillOpacity: 0 });
    }
    
    // Update buttons
    if (startDrawingBtn) startDrawingBtn.disabled = true;
    if (completePolygonBtn) completePolygonBtn.disabled = true;
    if (clearPolygonBtn) clearPolygonBtn.disabled = false;
    
    updatePolygonStatus();
    toast('Click on the map to add points', 'info');
  }

  function addPolygonPoint(lat, lng) {
    if (!state.isDrawing) return;
    
    state.polygonPath.push({lat, lng});
    
    // Add marker (LEAFLET VERSION)
    if (state.leafletMap && window.L) {
      const marker = L.circleMarker([lat, lng], {
        radius: 6,
        fillColor: '#0b793a',
        fillOpacity: 1,
        color: 'white',
        weight: 2
      }).addTo(state.leafletMap);
      
      state.polygonMarkers.push(marker);
    }
    // Google Maps fallback
    else if (state.googleMap && window.google) {
      const marker = new google.maps.Marker({
        position: {lat, lng},
        map: state.googleMap,
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 6,
          fillColor: '#0b793a',
          fillOpacity: 1,
          strokeColor: 'white',
          strokeWeight: 2
        }
      });
      state.polygonMarkers.push(marker);
    }
    
    // Draw polygon if we have enough points
    if (state.polygonPath.length >= 2) {
      if (state.currentPolygon) {
        // Remove old polygon
        if (state.leafletMap && state.currentPolygon.remove) {
          state.currentPolygon.remove();
        } else if (state.googleMap && state.currentPolygon.setMap) {
          state.currentPolygon.setMap(null);
        }
      }
      
      // Draw new polygon (LEAFLET VERSION)
      if (state.leafletMap && window.L) {
        const latlngs = state.polygonPath.map(p => [p.lat, p.lng]);
        state.currentPolygon = L.polygon(latlngs, {
          color: '#0b793a',
          weight: 2,
          fillColor: '#0b793a',
          fillOpacity: 0.35
        }).addTo(state.leafletMap);
      }
      // Google Maps fallback
      else if (state.googleMap && window.google) {
        state.currentPolygon = new google.maps.Polygon({
          paths: state.polygonPath,
          strokeColor: '#0b793a',
          strokeOpacity: 1,
          strokeWeight: 2,
          fillColor: '#0b793a',
          fillOpacity: 0.35,
          map: state.googleMap
        });
      }
    }
    
    // Enable complete button if we have enough points
    if (state.polygonPath.length >= 3 && completePolygonBtn) {
      completePolygonBtn.disabled = false;
    }
    
    updatePolygonStatus();
  }

  function completePolygonDrawing() {
    if (state.polygonPath.length < 3) {
      toast('Need at least 3 points to complete polygon', 'error');
      return;
    }
    
    state.isDrawing = false;
    
    // Update buttons
    if (startDrawingBtn) startDrawingBtn.disabled = false;
    if (completePolygonBtn) completePolygonBtn.disabled = true;
    
    updatePolygonStatus();
    toast('Polygon completed! Click Save Location to persist changes', 'success');
  }

  function clearPolygonDrawing() {
    state.isDrawing = false;
    state.polygonPath = [];
    
    // Remove markers (LEAFLET VERSION)
    if (state.leafletMap) {
      state.polygonMarkers.forEach(m => {
        if (m.remove) m.remove();
      });
    }
    // Google Maps fallback
    else if (state.googleMap) {
      state.polygonMarkers.forEach(m => {
        if (m.setMap) m.setMap(null);
      });
    }
    state.polygonMarkers = [];
    
    // Remove polygon (LEAFLET VERSION)
    if (state.currentPolygon) {
      if (state.leafletMap && state.currentPolygon.remove) {
        state.currentPolygon.remove();
      } else if (state.googleMap && state.currentPolygon.setMap) {
        state.currentPolygon.setMap(null);
      }
      state.currentPolygon = null;
    }
    
    // Reset buttons
    if (startDrawingBtn) startDrawingBtn.disabled = false;
    if (completePolygonBtn) completePolygonBtn.disabled = true;
    if (clearPolygonBtn) clearPolygonBtn.disabled = true;
    
    // Show circle again if we're still in polygon mode but cleared the polygon
    if (polygonRadio?.checked) {
      // Keep circle hidden while in polygon mode
      if (state.leafletCircle) {
        state.leafletCircle.setStyle({ opacity: 0, fillOpacity: 0 });
      }
      if (state.googleCircle) {
        state.googleCircle.setOptions({ strokeOpacity: 0, fillOpacity: 0 });
      }
    }
    
    updatePolygonStatus();
    toast('Polygon cleared', 'info');
  }

  async function loadExistingPolygon() {
    console.log('📥 Loading existing polygon for hub:', state.hubId);
    
    // Use data from state instead of making another API call
    if (!state.hubData) {
      console.log('⚠️ No hub data available in state');
      return;
    }
    
    // Check if polygon type and has delivery zones
    if (state.hubData.delivery_zone_type === 'polygon' && 
        state.hubData.delivery_zones && 
        state.hubData.delivery_zones.length > 0) {
      
      const zone = state.hubData.delivery_zones[0]; // Use first zone
      console.log('📥 Polygon zone found:', zone);
      
      if (!zone.polygon_points || zone.polygon_points.length < 3) {
        console.log('⚠️ Invalid polygon data (less than 3 points)');
        return;
      }
      
      state.polygonPath = zone.polygon_points;
      console.log('✅ Loaded', state.polygonPath.length, 'points into state');
      
      // Hide circle when showing polygon
      if (state.leafletCircle) {
        state.leafletCircle.setStyle({ opacity: 0, fillOpacity: 0 });
      }
      if (state.googleCircle) {
        state.googleCircle.setOptions({ strokeOpacity: 0, fillOpacity: 0 });
      }
        
      // Draw existing polygon (LEAFLET VERSION)
      if (state.leafletMap && window.L && state.polygonPath.length >= 3) {
          // Add markers
          state.polygonPath.forEach(point => {
            const marker = L.circleMarker([point.lat, point.lng], {
              radius: 6,
              fillColor: '#0b793a',
              fillOpacity: 1,
              color: 'white',
              weight: 2
            }).addTo(state.leafletMap);
            state.polygonMarkers.push(marker);
          });
          
          // Draw polygon
          const latlngs = state.polygonPath.map(p => [p.lat, p.lng]);
          state.currentPolygon = L.polygon(latlngs, {
            color: '#0b793a',
            weight: 2,
            fillColor: '#0b793a',
            fillOpacity: 0.35
          }).addTo(state.leafletMap);
          
          // Fit bounds
          state.leafletMap.fitBounds(state.currentPolygon.getBounds());
        }
        // Google Maps fallback
        else if (state.googleMap && window.google && state.polygonPath.length >= 3) {
          // Add markers
          state.polygonPath.forEach(point => {
            const marker = new google.maps.Marker({
              position: {lat: point.lat, lng: point.lng},
              map: state.googleMap,
              icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 6,
                fillColor: '#0b793a',
                fillOpacity: 1,
                strokeColor: 'white',
                strokeWeight: 2
              }
            });
            state.polygonMarkers.push(marker);
          });
          
          // Draw polygon
          state.currentPolygon = new google.maps.Polygon({
            paths: state.polygonPath,
            strokeColor: '#0b793a',
            strokeOpacity: 1,
            strokeWeight: 2,
            fillColor: '#0b793a',
            fillOpacity: 0.35,
            map: state.googleMap
          });
          
          // Fit bounds
          const bounds = new google.maps.LatLngBounds();
          state.polygonPath.forEach(p => bounds.extend(p));
          state.googleMap.fitBounds(bounds);
        }
        
        // Update UI
        if (clearPolygonBtn) clearPolygonBtn.disabled = false;
        updatePolygonStatus();
      console.log('✅ Polygon rendered on map');
    } else {
      console.log('⚠️ No polygon data found - zone type:', state.hubData?.delivery_zone_type);
    }
  }

  // ---------- Google Maps ----------
  function initGoogle() {
    const lat = toNum(latInput.value, 41.1200);
    const lng = toNum(lngInput.value, -87.8611);
    const rad = toNum(radiusInput.value, 3);

    const map = new google.maps.Map(mapDiv, { center:{lat,lng}, zoom: 13 });
    const marker = new google.maps.Marker({ position:{lat,lng}, map, draggable:true });
    const circle = new google.maps.Circle({
      map, center:{lat,lng}, radius: milesToMeters(rad),
      fillColor:"#0b793a", fillOpacity:.25, strokeColor:"#0b793a", strokeWeight:2, editable:true
    });

    marker.addListener("dragend", (e) => {
      latInput.value = e.latLng.lat().toFixed(8);
      lngInput.value = e.latLng.lng().toFixed(8);
      circle.setCenter(e.latLng);
    });

    const ac = new google.maps.places.Autocomplete(addressInput);
    ac.addListener("place_changed", () => {
      const p = ac.getPlace(); if (!p.geometry) return;
      const pos = p.geometry.location;
      map.setCenter(pos); marker.setPosition(pos); circle.setCenter(pos);
      latInput.value = pos.lat().toFixed(8);
      lngInput.value = pos.lng().toFixed(8);
      // Normaliza y muestra estilo Google antes de guardar
      addressInput.value = formatGoogleAddress(p);
    });

    google.maps.event.addListener(circle, "radius_changed", () => {
      radiusInput.value = metersToMiles(circle.getRadius()).toFixed(2);
    });
    radiusInput.addEventListener("input", () => {
      circle.setRadius(milesToMeters(toNum(radiusInput.value, 0)));
    });

    // Store map and shapes in state for polygon functions
    state.googleMap = map;
    state.googleMarker = marker;
    state.googleCircle = circle;

    // Setup polygon event listeners
    if (radiusRadio) radiusRadio.addEventListener('change', updateZoneUI);
    if (polygonRadio) polygonRadio.addEventListener('change', updateZoneUI);
    if (startDrawingBtn) startDrawingBtn.addEventListener('click', startPolygonDrawing);
    if (completePolygonBtn) completePolygonBtn.addEventListener('click', completePolygonDrawing);
    if (clearPolygonBtn) clearPolygonBtn.addEventListener('click', clearPolygonDrawing);

    // Map click listener for polygon drawing
    map.addListener('click', (e) => {
      if (state.isDrawing) {
        addPolygonPoint(e.latLng.lat(), e.latLng.lng());
      }
    });

    // Initial UI state
    updateZoneUI();
    
    // Load existing polygon if in polygon mode
    if (polygonRadio?.checked) {
      loadExistingPolygon();
    }

    wireSave("google");
  }

  // ---------- Leaflet + Nominatim ----------
  function initLeaflet() {
    if (!window.L) {
      const css = document.createElement("link");
      css.rel = "stylesheet";
      css.href = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.css";
      document.head.appendChild(css);
      const js = document.createElement("script");
      js.src = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";
      js.onload = renderLeaflet;
      document.head.appendChild(js);
    } else {
      renderLeaflet();
    }
  }

  function renderLeaflet() {
    const lat = toNum(latInput.value, 41.1200);
    const lng = toNum(lngInput.value, -87.8611);
    const rad = toNum(radiusInput.value, 3);

    mapDiv.innerHTML = ""; mapDiv.style.height = "500px";
    const map = L.map(mapDiv).setView([lat,lng], 13);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution:'&copy; OpenStreetMap'
    }).addTo(map);

    const marker = L.marker([lat,lng], { draggable:true }).addTo(map);
    const circle = L.circle([lat,lng], {
      radius: milesToMeters(rad), color:"#0b793a", fillColor:"#0b793a", fillOpacity:.25, weight:2
    }).addTo(map);

    marker.on("dragend", () => {
      const p = marker.getLatLng();
      latInput.value = p.lat.toFixed(8);
      lngInput.value = p.lng.toFixed(8);
      circle.setLatLng(p);
    });
    radiusInput.addEventListener("input", () => {
      circle.setRadius(milesToMeters(toNum(radiusInput.value,0)));
    });

    // Autocomplete Nominatim con sesgo y parser US
    let dropdown = null, debounceTimer = null;
    const removeDropdown = () => { dropdown?.remove(); dropdown = null; };

    addressInput.addEventListener("input", () => {
      clearTimeout(debounceTimer);
      const q = addressInput.value.trim();
      if (q.length < 3) { removeDropdown(); return; }

      debounceTimer = setTimeout(async () => {
        try {
          const center = map.getCenter();
          const bias   = { lat:center.lat, lng:center.lng, miles:35 };
          const tokens = tokenizeUSAddress(q);

          let data = [];
          if (tokens) {
            const url1 = buildStructuredUrl(tokens, bias, 10);
            data = await (await fetch(url1, { headers:{ "Accept-Language":"en-US" } })).json();
          }
          if (!data || data.length === 0) {
            const url2 = buildQueryUrl(q, bias, 10);
            data = await (await fetch(url2, { headers:{ "Accept-Language":"en-US" } })).json();
          }

          const results = scoreAndPick(data, center, 8);
          if (!results.length) { removeDropdown(); return; }
          dropdown = renderDropdown(results, ({lat,lon,display,zoom}) => {
            removeDropdown();
            const la = parseFloat(lat), lo = parseFloat(lon);
            map.setView([la,lo], zoom);
            marker.setLatLng([la,lo]); circle.setLatLng([la,lo]);
            latInput.value = la.toFixed(8); lngInput.value = lo.toFixed(8);
            addressInput.value = display; // ya formateada
            toast("📍 Address selected");
          });
          addressInput.parentElement.style.position = "relative";
          addressInput.parentElement.appendChild(dropdown);
        } catch { removeDropdown(); }
      }, 300);
    });

    addressInput.addEventListener("keydown", async (e) => {
      if (e.key !== "Enter") return;
      e.preventDefault(); removeDropdown();

      try {
        const q = addressInput.value.trim(); if (!q) return;
        const center = map.getCenter();
        const bias   = { lat:center.lat, lng:center.lng, miles:35 };
        const tokens = tokenizeUSAddress(q);

        let hit = null;
        if (tokens) {
          const u1 = buildStructuredUrl(tokens, bias, 1);
          const j1 = await (await fetch(u1, { headers:{ "Accept-Language":"en-US" } })).json();
          hit = j1 && j1[0];
        }
        if (!hit) {
          const u2 = buildQueryUrl(q, bias, 1);
          const j2 = await (await fetch(u2, { headers:{ "Accept-Language":"en-US" } })).json();
          hit = j2 && j2[0];
        }
        if (!hit) return toast("Address not found","error");

        const la = parseFloat(hit.lat), lo = parseFloat(hit.lon);
        const display = formatOSMAddress(hit);
        const zoom = hit.address?.house_number ? 18 : (hit.address?.road ? 16 : 13);

        map.setView([la,lo], zoom);
        marker.setLatLng([la,lo]); circle.setLatLng([la,lo]);
        latInput.value = la.toFixed(8); lngInput.value = lo.toFixed(8);
        addressInput.value = display;
        toast("📍 Location found");
      } catch { toast("Geocoding error","error"); }
    });

    document.addEventListener("click", (e) => {
      if (!dropdown) return;
      if (!addressInput.contains(e.target) && !dropdown.contains(e.target)) removeDropdown();
    });

    // Store map reference for polygon functions
    state.leafletMap = map;
    state.leafletMarker = marker;
    state.leafletCircle = circle;

    // Setup polygon event listeners
    if (radiusRadio) radiusRadio.addEventListener('change', updateZoneUI);
    if (polygonRadio) polygonRadio.addEventListener('change', updateZoneUI);
    if (startDrawingBtn) startDrawingBtn.addEventListener('click', startPolygonDrawing);
    if (completePolygonBtn) completePolygonBtn.addEventListener('click', completePolygonDrawing);
    if (clearPolygonBtn) clearPolygonBtn.addEventListener('click', clearPolygonDrawing);

    // Map click listener for polygon drawing (LEAFLET VERSION)
    map.on('click', (e) => {
      if (state.isDrawing) {
        addPolygonPoint(e.latlng.lat, e.latlng.lng);
      }
    });

    // Initial UI state
    updateZoneUI();
    
    // Load existing polygon if in polygon mode
    if (polygonRadio?.checked) {
      loadExistingPolygon();
    }

    wireSave("osm");
  }

  // ---------- Save ----------
  function wireSave(source) {
    saveBtn.onclick = async () => {
      const zoneType = polygonRadio?.checked ? 'polygon' : 'radius';
      
      const payload = {
        hub_id: state.hubId,
        address: addressInput.value.trim(),
        lat: toNum(latInput.value, 0),
        lng: toNum(lngInput.value, 0),
        delivery_radius: toNum(radiusInput.value, 0),
        delivery_zone_type: zoneType,
        knx_nonce: state.nonce
      };
      
      // Add polygon points if in polygon mode
      if (zoneType === 'polygon') {
        if (state.polygonPath.length < 3) {
          toast('Please draw a polygon with at least 3 points', 'error');
          return;
        }
        payload.polygon_points = state.polygonPath;
        console.log('Saving polygon with points:', state.polygonPath);
      }
      
      console.log('Save payload:', payload);
      
      try {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';
        
        const r = await fetch(API_SAVE, {
          method:"POST",
          headers:{ "Content-Type":"application/json" },
          body: JSON.stringify(payload)
        });
        const j = await r.json();
        
        if (j.success) {
          toast('✅ Location and delivery zone updated');
          // Reload after save to show updated polygon
          setTimeout(() => location.reload(), 1500);
        } else {
          toast(j.error || "Failed to update","error");
        }
      } catch {
        toast("Network error","error");
      } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Location';
      }
    };
  }

  // ---------- Helpers ----------
  function toNum(v, d=0){ const n = parseFloat(v); return isFinite(n) ? n : d; }
  function milesToMeters(m){ return m * 1609.34; }
  function metersToMiles(m){ return m / 1609.34; }

  // Formato Google-style (construido desde place.address_components)
  function formatGoogleAddress(place){
    const c = (place.address_components || []).reduce((acc, a) => {
      a.types.forEach(t => acc[t] = a); return acc;
    }, {});
    const hn = c.street_number?.long_name || "";
    const rd = c.route?.long_name || "";
    const city = c.locality?.long_name || c.sublocality?.long_name || "";
    const st = (c.administrative_area_level_1?.short_name || c.administrative_area_level_1?.long_name || "");
    const zip = c.postal_code?.long_name || "";
    const parts = [];
    const street = [hn, rd].filter(Boolean).join(" ");
    if (street) parts.push(street);
    const cityState = [city, st].filter(Boolean).join(", ");
    if (cityState) parts.push(cityState);
    // “United States” y estado completo al final para que matchee tu formato requerido
    const tail = ["United States", (c.administrative_area_level_1?.long_name || st || ""), zip].filter(Boolean).join(", ").replace(", ,", ",");
    return [parts.join(", "), tail].filter(Boolean).join(" ").replace(/\s+,/g,",");
  }

  // Parser de US: "670 w Station St, Kankakee, IL 60901"
  function tokenizeUSAddress(input){
    const s = (input || "").trim();
    const re = /^\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([A-Za-z]{2}|[A-Za-z ]+)(?:\s+(\d{5}(?:-\d{4})?))?/;
    const m = s.match(re);
    if (!m) return null;
    let [, street, city, state, zip] = m;
    street = street.replace(/\.+$/,"");
    return { street, city, state, zip: zip || "" };
  }

  function buildStructuredUrl(tokens, bias, limit){
    const base = "https://nominatim.openstreetmap.org/search";
    const p = new URLSearchParams({
      format:"json", addressdetails:"1", extratags:"1", namedetails:"1",
      limit:String(limit||10), dedupe:"0", countrycodes:"us,ca,mx",
      street: tokens.street, city: tokens.city, state: tokens.state
    });
    if (tokens.zip) p.set("postalcode", tokens.zip);
    if (bias) {
      const deg = (bias.miles || 25) / 69.0;
      const l = bias.lng - deg, b = bias.lat - deg, r = bias.lng + deg, t = bias.lat + deg;
      p.set("viewbox", `${l},${t},${r},${b}`);
      p.set("bounded","1");
    }
    return `${base}?${p.toString()}`;
  }

  function buildQueryUrl(q, bias, limit){
    const base = "https://nominatim.openstreetmap.org/search";
    const p = new URLSearchParams({
      format:"json", q, addressdetails:"1", extratags:"1", namedetails:"1",
      countrycodes:"us,ca,mx", limit:String(limit||10), dedupe:"0"
    });
    if (bias) {
      const deg = (bias.miles || 25) / 69.0;
      const l = bias.lng - deg, b = bias.lat - deg, r = bias.lng + deg, t = bias.lat + deg;
      p.set("viewbox", `${l},${t},${r},${b}`);
      p.set("bounded","1");
    }
    return `${base}?${p.toString()}`;
  }

  function scoreAndPick(data, center, take){
    return (data||[])
      .map(d => {
        const a = d.address || {};
        let score = 0;
        if (a.house_number && a.road) score = 100;
        else if (a.road) score = 60;
        else if (a.city || a.town || a.village) score = 30;
        // penalización por lejanía (grados → arbitrario pero efectivo)
        try {
          const dy = parseFloat(d.lat) - center.lat;
          const dx = parseFloat(d.lon) - center.lng;
          score -= Math.sqrt(dx*dx + dy*dy) * 50;
        } catch {}
        return { d, score };
      })
      .filter(x => x.score > 0)
      .sort((a,b) => b.score - a.score)
      .slice(0, take || 8)
      .map(x => {
        const disp = formatOSMAddress(x.d);
        const z = x.d.address?.house_number ? 18 : (x.d.address?.road ? 16 : 13);
        return { ...x.d, display: disp, zoom: z };
      });
  }

  // Formatea OSM → tu formato “estilo Google”
  function formatOSMAddress(item){
    const a = item.address || {};
    const street = [a.house_number, a.road].filter(Boolean).join(" ").trim();
    const city   = a.city || a.town || a.village || a.municipality || "";
    const stAbbr = (a.state && stateAbbr[a.state]) ? stateAbbr[a.state] : (a.state || "");
    const stLong = a.state || stAbbr || "";
    const zip    = a.postcode || "";
    const head   = [street, [city, stAbbr].filter(Boolean).join(", ")].filter(Boolean).join(", ");
    const tail   = ["United States", stLong, zip].filter(Boolean).join(", ");
    return [head, tail].filter(Boolean).join(" ").replace(/\s+,/g,",");
  }

  // Dropdown render simple
  function renderDropdown(results, onPick){
    const dd = document.createElement("div");
    dd.className = "knx-autocomplete-dropdown";
    dd.style.cssText = `
      position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:1000;
      background:#fff; border:1px solid #ddd; border-radius:8px;
      box-shadow:0 4px 12px rgba(0,0,0,.12); max-height:300px; overflow:auto;
    `;
    results.forEach(r => {
      const row = document.createElement("div");
      row.style.cssText = `
        padding:12px 14px; border-bottom:1px solid #f3f4f6; cursor:pointer;
        display:flex; gap:10px; align-items:flex-start;
      `;
      row.innerHTML = `
        <span style="flex-shrink:0;margin-top:2px">📍</span>
        <div style="flex:1">
          <div style="font-weight:600;color:#111">${escapeHtml(r.display.split(" United States")[0])}</div>
          <div style="font-size:.85rem;color:#666">${escapeHtml("United States")}</div>
        </div>`;
      row.addEventListener("mouseenter", ()=>row.style.background="#f8fafc");
      row.addEventListener("mouseleave", ()=>row.style.background="#fff");
      row.addEventListener("click", ()=> onPick({ lat:r.lat, lon:r.lon, display:r.display, zoom:r.zoom }));
      dd.appendChild(row);
    });
    return dd;
  }

  const stateAbbr = {
    "Alabama":"AL","Alaska":"AK","Arizona":"AZ","Arkansas":"AR","California":"CA","Colorado":"CO",
    "Connecticut":"CT","Delaware":"DE","Florida":"FL","Georgia":"GA","Hawaii":"HI","Idaho":"ID",
    "Illinois":"IL","Indiana":"IN","Iowa":"IA","Kansas":"KS","Kentucky":"KY","Louisiana":"LA",
    "Maine":"ME","Maryland":"MD","Massachusetts":"MA","Michigan":"MI","Minnesota":"MN","Mississippi":"MS",
    "Missouri":"MO","Montana":"MT","Nebraska":"NE","Nevada":"NV","New Hampshire":"NH","New Jersey":"NJ",
    "New Mexico":"NM","New York":"NY","North Carolina":"NC","North Dakota":"ND","Ohio":"OH","Oklahoma":"OK",
    "Oregon":"OR","Pennsylvania":"PA","Rhode Island":"RI","South Carolina":"SC","South Dakota":"SD",
    "Tennessee":"TN","Texas":"TX","Utah":"UT","Vermont":"VT","Virginia":"VA","Washington":"WA",
    "West Virginia":"WV","Wisconsin":"WI","Wyoming":"WY"
  };

  // ========== HELPER FUNCTIONS ==========

  /**
   * Update coverage status badge based on hub data
   */
  function updateCoverageStatus() {
    if (!state.hubData) return;

    const hasZones = state.hubData.delivery_zones && state.hubData.delivery_zones.length > 0;
    const hasActiveZones = hasZones && state.hubData.delivery_zones.some(z => z.is_active);
    const radius = parseFloat(state.hubData.delivery_radius) || 0;

    if (hasActiveZones) {
      // Has active polygon zones
      const activeCount = state.hubData.delivery_zones.filter(z => z.is_active).length;
      coverageStatusBadge.innerHTML = `✅ ${activeCount} Active Zone${activeCount > 1 ? 's' : ''}`;
      coverageStatusBadge.style.background = '#d1fae5';
      coverageStatusBadge.style.color = '#065f46';
    } else if (radius > 0) {
      // No polygon, but has radius fallback
      coverageStatusBadge.innerHTML = `🔵 ${radius} mi Radius (Fallback)`;
      coverageStatusBadge.style.background = '#dbeafe';
      coverageStatusBadge.style.color = '#1e40af';
    } else {
      // No coverage at all
      coverageStatusBadge.innerHTML = '⚠️ No Coverage';
      coverageStatusBadge.style.background = '#fef3c7';
      coverageStatusBadge.style.color = '#92400e';
    }
  }

  /**
   * Toggle radius circle visibility on map
   */
  function toggleRadiusCircle() {
    radiusCircleVisible = !radiusCircleVisible;
    
    if (radiusCircleVisible) {
      // Show radius circle
      const lat = parseFloat(latInput.value) || 41.12;
      const lng = parseFloat(lngInput.value) || -87.86;
      const radiusMiles = parseFloat(radiusInput.value) || 5;
      
      if (state.useLeaflet && state.leafletMap) {
        // Leaflet: radius in meters
        if (state.leafletCircle) {
          state.leafletMap.removeLayer(state.leafletCircle);
        }
        state.leafletCircle = L.circle([lat, lng], {
          radius: radiusMiles * 1609.34, // miles to meters
          color: '#6366f1',
          fillColor: '#6366f1',
          fillOpacity: 0.15,
          weight: 2,
          dashArray: '5, 10'
        }).addTo(state.leafletMap);
        
        toggleRadiusBtn.textContent = '👁️ Hide Radius Preview';
        toggleRadiusBtn.style.background = '#4f46e5';
        toast('Radius preview shown', 'info');
      } else if (state.googleMap) {
        // Google Maps: radius in meters
        if (state.googleCircle) {
          state.googleCircle.setMap(null);
        }
        state.googleCircle = new google.maps.Circle({
          map: state.googleMap,
          center: { lat, lng },
          radius: radiusMiles * 1609.34, // miles to meters
          strokeColor: '#6366f1',
          strokeOpacity: 0.8,
          strokeWeight: 2,
          fillColor: '#6366f1',
          fillOpacity: 0.15,
          clickable: false
        });
        
        toggleRadiusBtn.textContent = '👁️ Hide Radius Preview';
        toggleRadiusBtn.style.background = '#4f46e5';
        toast('Radius preview shown', 'info');
      }
    } else {
      // Hide radius circle
      if (state.useLeaflet && state.leafletCircle) {
        state.leafletMap.removeLayer(state.leafletCircle);
        state.leafletCircle = null;
      } else if (state.googleCircle) {
        state.googleCircle.setMap(null);
        state.googleCircle = null;
      }
      
      toggleRadiusBtn.textContent = '👁️ Show Radius Preview';
      toggleRadiusBtn.style.background = '#6366f1';
      toast('Radius preview hidden', 'info');
    }
  }

  // Add toggle radius button handler
  if (toggleRadiusBtn) {
    toggleRadiusBtn.addEventListener('click', toggleRadiusCircle);
  }

  // Update radius display dynamically
  if (radiusInput) {
    radiusInput.addEventListener('input', () => {
      const radius = parseFloat(radiusInput.value) || 5;
      // Update fallback text in UI
      const radiusFallbackSpan = document.getElementById('radiusFallbackText');
      if (radiusFallbackSpan) {
        radiusFallbackSpan.textContent = radius;
      }
      
      // If radius circle is visible, update it
      if (radiusCircleVisible) {
        radiusCircleVisible = false; // Reset state
        toggleRadiusCircle(); // Hide current
        toggleRadiusCircle(); // Show with new radius
      }
    });
  }

  function escapeHtml(s){ return (s||"").toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
});
