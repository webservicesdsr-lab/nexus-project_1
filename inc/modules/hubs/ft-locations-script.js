/**
 * ==========================================================
 * KNX Food Truck — Saved Locations Controller (v2.0)
 * ----------------------------------------------------------
 * CRUD for food-truck operator saved serving locations.
 * Used inside /hub-settings/ for hub_management role.
 *
 * Features:
 *   - List saved locations as selectable cards
 *   - Add/edit via minimal modal (autocomplete + map + note)
 *   - Coverage check on selection (shows red warning if outside zone)
 *   - Select active location (updates hub lat/lng)
 *   - Delete location
 * ==========================================================
 */
window.KnxFtLocations = (function () {
  'use strict';

  let CFG = {};
  let locations = [];
  let activeId  = null;
  let editingId = null;
  let modalMap  = null;
  let modalMarker = null;
  let searchTimer = null;
  let selectedAddress = ''; // display_name from geocode

  /* ── DOM REFS ───────────────────────────────── */
  const dom = {};

  function cacheDom() {
    dom.list         = document.getElementById('ftLocationsList');
    dom.loading      = document.getElementById('ftLocationsLoading');
    dom.empty        = document.getElementById('ftLocationsEmpty');
    dom.addBtn       = document.getElementById('ftAddLocationBtn');
    dom.addEmptyBtn  = document.getElementById('ftAddLocationEmptyBtn');
    dom.warning      = document.getElementById('ftCoverageWarning');

    dom.modal        = document.getElementById('ftLocModal');
    dom.modalBG      = document.getElementById('ftLocModalBG');
    dom.modalClose   = document.getElementById('ftLocModalClose');
    dom.modalTitle   = document.getElementById('ftLocModalTitle');
    dom.form         = document.getElementById('ftLocForm');
    dom.cancelBtn    = document.getElementById('ftLocCancelBtn');
    dom.saveBtn      = document.getElementById('ftLocSaveBtn');
    dom.searchInput  = document.getElementById('ftLocSearch');
    dom.suggestions  = document.getElementById('ftLocSuggestions');
    dom.geoBtn       = document.getElementById('ftLocGeoBtn');
    dom.mapDiv       = document.getElementById('ftLocMap');
    dom.feedback     = document.getElementById('ftLocCoverageFeedback');

    // Fields
    dom.fId    = document.getElementById('ftLocId');
    dom.fNote  = document.getElementById('ftLocNote');
    dom.fLat   = document.getElementById('ftLocLat');
    dom.fLng   = document.getElementById('ftLocLng');
  }

  /* ── INIT ───────────────────────────────────── */
  function init(config) {
    CFG = config;
    cacheDom();
    bindEvents();
    loadLocations();
  }

  function bindEvents() {
    dom.addBtn?.addEventListener('click', function () { openModal(); });
    dom.addEmptyBtn?.addEventListener('click', function () { openModal(); });
    dom.modalClose?.addEventListener('click', closeModal);
    dom.modalBG?.addEventListener('click', closeModal);
    dom.cancelBtn?.addEventListener('click', closeModal);
    dom.form?.addEventListener('submit', handleSubmit);
    dom.geoBtn?.addEventListener('click', detectLocation);
    dom.searchInput?.addEventListener('input', handleSearch);

    // ESC to close
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && dom.modal?.getAttribute('aria-hidden') === 'false') {
        closeModal();
      }
    });
  }

  /* ── API CALLS ──────────────────────────────── */

  async function apiCall(endpoint, body) {
    var url = endpoint.startsWith('http') ? endpoint : CFG.apiBase + '/' + endpoint;
    if (!body) {
      // GET
      var sep = url.indexOf('?') !== -1 ? '&' : '?';
      url += sep + 'hub_id=' + CFG.hubId;
      var res = await fetch(url, {
        headers: { 'X-WP-Nonce': CFG.wpNonce },
        credentials: 'same-origin'
      });
      return res.json();
    }
    // POST
    body.hub_id    = CFG.hubId;
    body.knx_nonce = CFG.nonce;
    var res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': CFG.wpNonce
      },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    });
    return res.json();
  }

  /* ── LOAD ───────────────────────────────────── */

  async function loadLocations() {
    try {
      var json = await apiCall('', null);
      if (json.success && json.data) {
        locations = json.data.locations || [];
        activeId  = json.data.active_id || null;
      }
    } catch (e) {
      console.error('[FT-Loc] load error:', e);
    }
    render();
  }

  /* ── RENDER ─────────────────────────────────── */

  function render() {
    if (dom.loading) dom.loading.style.display = 'none';

    if (locations.length === 0) {
      dom.list.innerHTML = '';
      dom.empty.style.display = '';
      return;
    }

    dom.empty.style.display = 'none';

    var html = '';
    locations.forEach(function (loc) {
      var isActive = activeId && loc.id === activeId;
      var cardClass = 'knx-ft-loc-card' + (isActive ? ' is-active' : '');
      var badge = '';
      if (isActive) {
        badge = '<span class="knx-ft-loc-card__badge knx-ft-loc-card__badge--active">✓ Active</span>';
      }

      // Display the address; fall back to structured fields for legacy data
      var address = loc.display_name || [loc.line1, loc.city, loc.state, loc.postal_code].filter(Boolean).join(', ') || 'No address';
      var note = loc.note ? '<div style="font-size:12px;color:#9ca3af;margin-top:2px;font-style:italic;">' + esc(loc.note) + '</div>' : '';

      html += '<div class="' + cardClass + '" data-loc-id="' + esc(loc.id) + '">';
      html += badge;
      html += '<div class="knx-ft-loc-card__label">' + esc(address) + '</div>';
      html += note;
      html += '<div class="knx-ft-loc-card__actions">';
      if (!isActive) {
        html += '<button type="button" class="ft-select-btn" data-action="select" data-id="' + esc(loc.id) + '"><i class="fas fa-check"></i> Use This</button>';
      }
      html += '<button type="button" class="ft-edit-btn" data-action="edit" data-id="' + esc(loc.id) + '"><i class="fas fa-pen"></i> Edit</button>';
      html += '<button type="button" class="ft-delete-btn" data-action="delete" data-id="' + esc(loc.id) + '"><i class="fas fa-trash"></i></button>';
      html += '</div></div>';
    });

    dom.list.innerHTML = html;

    // Bind card actions
    dom.list.querySelectorAll('button[data-action]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var action = btn.dataset.action;
        var id     = btn.dataset.id;
        if (action === 'select') selectLocation(id);
        if (action === 'edit')   editLocation(id);
        if (action === 'delete') deleteLocation(id);
      });
    });
  }

  /* ── SELECT (Set Active) ────────────────────── */

  async function selectLocation(id) {
    try {
      var json = await apiCall('select', { location_id: id });
      if (json.success) {
        activeId = id;
        toast('success', 'Location activated ✓');

        // Check coverage response
        if (json.data && json.data.coverage) {
          var cov = json.data.coverage;
          if (!cov.ok) {
            showCoverageWarning(true);
            // Mark card
            var card = dom.list.querySelector('[data-loc-id="' + id + '"]');
            if (card) card.classList.add('is-out-of-range');
          } else {
            showCoverageWarning(false);
          }
        }
        render();
      } else {
        toast('error', json.message || 'Failed to select');
      }
    } catch (e) {
      toast('error', 'Network error');
    }
  }

  function showCoverageWarning(show) {
    if (dom.warning) {
      dom.warning.style.display = show ? '' : 'none';
    }
  }

  /* ── DELETE ─────────────────────────────────── */

  async function deleteLocation(id) {
    if (!confirm('Delete this location?')) return;
    try {
      var json = await apiCall('delete', { location_id: id });
      if (json.success) {
        locations = locations.filter(function (l) { return l.id !== id; });
        if (activeId === id) activeId = null;
        toast('success', 'Location deleted');
        render();
      } else {
        toast('error', json.message || 'Delete failed');
      }
    } catch (e) {
      toast('error', 'Network error');
    }
  }

  /* ── EDIT ───────────────────────────────────── */

  function editLocation(id) {
    var loc = locations.find(function (l) { return l.id === id; });
    if (!loc) return;
    openModal(loc);
  }

  /* ── MODAL ──────────────────────────────────── */

  function openModal(loc) {
    editingId = loc ? loc.id : null;
    dom.modalTitle.textContent = loc ? 'Edit Location' : 'Add Location';

    // Reset form
    dom.fId.value       = loc ? loc.id : '';
    dom.fNote.value     = loc ? (loc.note || '') : '';
    dom.fLat.value      = loc ? (loc.latitude || '') : '';
    dom.fLng.value      = loc ? (loc.longitude || '') : '';
    dom.searchInput.value = loc ? (loc.display_name || '') : '';
    selectedAddress     = loc ? (loc.display_name || '') : '';

    // Reset feedback
    if (dom.feedback) {
      dom.feedback.style.display = 'none';
      dom.feedback.textContent = '';
    }

    dom.modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    // Init map after modal is visible
    setTimeout(function () { initModalMap(loc); }, 100);
  }

  function closeModal() {
    dom.modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    editingId = null;
    closeSuggestions();

    // Destroy map to prevent Leaflet issues
    if (modalMap) {
      modalMap.remove();
      modalMap = null;
      modalMarker = null;
    }
  }

  function initModalMap(loc) {
    if (modalMap) {
      modalMap.remove();
      modalMap = null;
      modalMarker = null;
    }

    var lat = loc ? parseFloat(loc.latitude) || 41.8781 : 41.8781;
    var lng = loc ? parseFloat(loc.longitude) || -87.6298 : -87.6298;

    modalMap = L.map(dom.mapDiv).setView([lat, lng], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OSM'
    }).addTo(modalMap);

    modalMarker = L.marker([lat, lng], { draggable: true }).addTo(modalMap);
    dom.fLat.value = lat;
    dom.fLng.value = lng;

    modalMarker.on('dragend', function () {
      var pos = modalMarker.getLatLng();
      dom.fLat.value = pos.lat.toFixed(7);
      dom.fLng.value = pos.lng.toFixed(7);
      reverseGeocodeForDisplay(pos.lat, pos.lng);
      checkCoverageInModal(pos.lat, pos.lng);
    });

    // Map click to move pin
    modalMap.on('click', function (e) {
      modalMarker.setLatLng(e.latlng);
      dom.fLat.value = e.latlng.lat.toFixed(7);
      dom.fLng.value = e.latlng.lng.toFixed(7);
      reverseGeocodeForDisplay(e.latlng.lat, e.latlng.lng);
      checkCoverageInModal(e.latlng.lat, e.latlng.lng);
    });

    // Check coverage for existing location
    if (loc && loc.latitude && loc.longitude) {
      checkCoverageInModal(lat, lng);
    }
  }

  function panModalMap(lat, lng) {
    if (!modalMap || !modalMarker) return;
    modalMarker.setLatLng([lat, lng]);
    modalMap.setView([lat, lng], 16);
    dom.fLat.value = lat.toFixed(7);
    dom.fLng.value = lng.toFixed(7);
    checkCoverageInModal(lat, lng);
  }

  /* ── COVERAGE CHECK (in modal) ──────────────── */

  async function checkCoverageInModal(lat, lng) {
    if (!dom.feedback) return;
    try {
      var json = await apiCall(CFG.apiCheck, { latitude: lat, longitude: lng });
      if (json.success && json.data && json.data.coverage) {
        var cov = json.data.coverage;
        if (cov.ok) {
          dom.feedback.style.display = '';
          dom.feedback.style.background = '#f0fdf4';
          dom.feedback.style.color = '#065f46';
          dom.feedback.innerHTML = '<i class="fas fa-check-circle"></i> This location is within the delivery coverage area.';
        } else {
          dom.feedback.style.display = '';
          dom.feedback.style.background = '#fef2f2';
          dom.feedback.style.color = '#dc2626';
          dom.feedback.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Sorry, this location is outside the allowed delivery range. Orders will not be available for delivery from here.';
        }
      }
    } catch (e) {
      dom.feedback.style.display = 'none';
    }
  }

  /* ── SEARCH / AUTOCOMPLETE ──────────────────── */

  function handleSearch() {
    clearTimeout(searchTimer);
    var q = (dom.searchInput.value || '').trim();
    if (q.length < 3) {
      closeSuggestions();
      return;
    }
    searchTimer = setTimeout(function () { searchNominatim(q); }, 350);
  }

  async function searchNominatim(query) {
    try {
      var res = await fetch(
        'https://nominatim.openstreetmap.org/search?format=json&q=' +
        encodeURIComponent(query) + '&limit=5&addressdetails=1'
      );
      var data = await res.json();
      if (data && data.length > 0) {
        showSuggestions(data);
      } else {
        closeSuggestions();
      }
    } catch (e) {
      closeSuggestions();
    }
  }

  function showSuggestions(results) {
    var html = '';
    results.forEach(function (r) {
      html += '<li data-lat="' + r.lat + '" data-lng="' + r.lon + '" data-display="' + esc(r.display_name) + '">';
      html += esc(r.display_name);
      html += '</li>';
    });
    dom.suggestions.innerHTML = html;
    dom.suggestions.style.display = 'block';

    dom.suggestions.querySelectorAll('li').forEach(function (li) {
      li.addEventListener('click', function () {
        var lat = parseFloat(li.dataset.lat);
        var lng = parseFloat(li.dataset.lng);
        panModalMap(lat, lng);

        // Set the search field to the selected address
        selectedAddress = li.dataset.display || '';
        dom.searchInput.value = selectedAddress;
        closeSuggestions();
      });
    });
  }

  function closeSuggestions() {
    if (dom.suggestions) {
      dom.suggestions.style.display = 'none';
      dom.suggestions.innerHTML = '';
    }
  }

  /* ── REVERSE GEOCODE (for display) ────────────── */

  async function reverseGeocodeForDisplay(lat, lng) {
    try {
      var res = await fetch(
        'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&addressdetails=1'
      );
      var data = await res.json();
      if (data && data.display_name) {
        selectedAddress = data.display_name;
        dom.searchInput.value = selectedAddress;
      }
    } catch (e) { /* ignore */ }
  }

  /* ── GEOLOCATION ────────────────────────────── */

  async function detectLocation() {
    if (!navigator.geolocation) {
      toast('error', 'Geolocation not supported by your browser');
      return;
    }

    var btn = dom.geoBtn;
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Locating…';
    btn.disabled = true;

    try {
      var pos = await new Promise(function (resolve, reject) {
        navigator.geolocation.getCurrentPosition(resolve, reject, {
          enableHighAccuracy: true,
          timeout: 10000
        });
      });

      var lat = pos.coords.latitude;
      var lng = pos.coords.longitude;
      panModalMap(lat, lng);
      // Reverse geocode to populate address display
      reverseGeocodeForDisplay(lat, lng);
      toast('success', 'Location detected ✓');
    } catch (err) {
      toast('error', 'Could not detect location: ' + err.message);
    }

    btn.innerHTML = orig;
    btn.disabled = false;
  }

  /* ── SUBMIT (ADD / UPDATE) ──────────────────── */

  async function handleSubmit(e) {
    e.preventDefault();

    var lat = parseFloat(dom.fLat.value);
    var lng = parseFloat(dom.fLng.value);

    if (!selectedAddress && !dom.searchInput.value.trim()) {
      toast('error', 'Search and select an address, or pin a location on the map');
      return;
    }
    if (!lat || !lng) {
      toast('error', 'Pin a location on the map');
      return;
    }

    dom.saveBtn.disabled = true;
    dom.saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    var payload = {
      display_name: selectedAddress || dom.searchInput.value.trim(),
      note:         (dom.fNote.value || '').trim(),
      latitude:     lat,
      longitude:    lng
    };

    try {
      var json;
      if (editingId) {
        payload.location_id = editingId;
        json = await apiCall('update', payload);
      } else {
        json = await apiCall(CFG.apiBase, payload);
      }

      if (json.success) {
        var msg = editingId ? 'Location updated ✓' : 'Location added and activated ✓';
        toast('success', msg);
        
        // If adding (not editing) and coverage check failed, show warning
        if (!editingId && json.data && json.data.coverage && !json.data.coverage.ok) {
          showCoverageWarning(true);
        } else if (!editingId && json.data && json.data.coverage && json.data.coverage.ok) {
          showCoverageWarning(false);
        }
        
        closeModal();
        loadLocations();
      } else {
        toast('error', json.message || 'Save failed');
      }
    } catch (e) {
      toast('error', 'Network error');
    }

    dom.saveBtn.disabled = false;
    dom.saveBtn.innerHTML = '<i class="fas fa-check"></i> Save Location';
  }

  /* ── HELPERS ────────────────────────────────── */

  function esc(str) {
    if (!str) return '';
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  function toast(type, msg) {
    if (typeof window.knxToast === 'function') {
      window.knxToast(type, msg);
    } else if (typeof window.KnxToast !== 'undefined' && typeof window.KnxToast.show === 'function') {
      window.KnxToast.show(type, msg);
    } else {
      var el = document.createElement('div');
      el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;color:#fff;font-weight:600;font-size:14px;box-shadow:0 4px 16px rgba(0,0,0,0.15);transition:opacity 0.3s;';
      el.style.background = type === 'success' ? '#0b793a' : '#dc2626';
      el.textContent = msg;
      document.body.appendChild(el);
      setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 300); }, 3000);
    }
  }

  /* ── PUBLIC API ─────────────────────────────── */
  return { init: init };

})();
