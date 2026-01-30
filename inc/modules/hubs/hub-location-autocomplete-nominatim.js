/*
 * Hub Location — Nominatim Autocomplete (vanilla JS)
 * Provides a safe, fail-closed address autocomplete using server-side Nominatim endpoint
 * Contract: items in response must include { display, lat, lng, provider }
 */

(function(){
  'use strict';

  const AUTOPICK_ENABLED = false; // Default: never autopick pasted addresses
  const DEBOUNCE_MS = 300;

  let input = null;
  let wrapper = null;
  let dropdown = null;
  let controller = null;
  let debounceTimer = null;
  let items = [];
  let activeIndex = -1;
  let userNavigated = false; // true when user used arrow keys to change selection

  document.addEventListener('DOMContentLoaded', init);

  function init() {
    // Only initialize Nominatim autocomplete when provider is not explicitly Google
    if (typeof window.KNX_LOCATION_PROVIDER !== 'undefined' && window.KNX_LOCATION_PROVIDER === 'google') return;

    input = document.getElementById('knxHubAddress');
    if (!input) return;

    // create wrapper and dropdown
    wrapper = document.createElement('div'); wrapper.className = 'knx-autocomplete-wrapper';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    dropdown = document.createElement('div'); dropdown.className = 'knx-autocomplete-dropdown';
    dropdown.style.display = 'none';
    wrapper.appendChild(dropdown);

    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKeyDown);
    input.addEventListener('paste', onPaste);
    document.addEventListener('click', onDocumentClick);
  }

  function onInput(e) {
    userNavigated = false;
    const q = input.value.trim();
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => fetchSuggestions(q), DEBOUNCE_MS);
  }

  function onKeyDown(e) {
    if (dropdown.style.display === 'none') return;
    if (e.key === 'ArrowDown') {
      e.preventDefault(); userNavigated = true; changeActive(1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault(); userNavigated = true; changeActive(-1);
    } else if (e.key === 'Enter') {
      if (userNavigated && activeIndex >= 0 && items[activeIndex]) {
        e.preventDefault(); selectItem(items[activeIndex]);
      } else {
        // fail-closed: do not implicitly select first suggestion
        // Let form submission happen if needed, but do not change coordinates
      }
    } else if (e.key === 'Escape') {
      hideDropdown();
    }
  }

  function onPaste(e) {
    // Paste should not auto-pick by default. We still trigger suggestions.
    userNavigated = false;
    const q = input.value.trim();
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => fetchSuggestions(q), DEBOUNCE_MS + 50);
  }

  function onDocumentClick(e) {
    if (!wrapper.contains(e.target)) hideDropdown();
  }

  function changeActive(delta) {
    const rows = dropdown.querySelectorAll('.knx-autocomplete-row');
    if (!rows || !rows.length) return;
    activeIndex = Math.max(0, Math.min(rows.length - 1, activeIndex + delta));
    rows.forEach(r => r.classList.remove('active'));
    if (rows[activeIndex]) rows[activeIndex].classList.add('active');
    // Ensure active visible
    const el = rows[activeIndex]; if (el && typeof el.scrollIntoView === 'function') el.scrollIntoView({ block: 'nearest' });
  }

  function fetchSuggestions(q) {
    if (controller) {
      try { controller.abort(); } catch (e) {}
      controller = null;
    }

    if (!q || q.length < 2) { hideDropdown(); return; }

    controller = new AbortController();
    const url = (window.KNX_GEOCODE_API || '/wp-json/knx/v1/geocode-search') + '?q=' + encodeURIComponent(q);

    fetch(url, { signal: controller.signal, credentials: 'same-origin' })
      .then(res => res.json())
      .then(json => {
        if (!json || !Array.isArray(json.items)) { items = []; render(); return; }
        items = json.items;
        activeIndex = -1;
        render();
      })
      .catch(err => {
        if (err && err.name === 'AbortError') return;
        console.warn('Autocomplete fetch error', err);
        items = []; render();
      });
  }

  function render() {
    dropdown.innerHTML = '';
    if (!items || !items.length) {
      const r = document.createElement('div'); r.className = 'knx-no-results'; r.textContent = 'No results'; dropdown.appendChild(r); dropdown.style.display = 'block'; return;
    }

    items.forEach((it, idx) => {
      const row = document.createElement('div'); row.className = 'knx-autocomplete-row';
      const title = document.createElement('div'); title.className = 'knx-autocomplete-row-title'; title.textContent = it.display || '';
      row.appendChild(title);
      if (it.city || it.state || it.postcode) {
        const sub = document.createElement('div'); sub.className = 'knx-autocomplete-row-sub'; sub.textContent = [it.city, it.state, it.postcode].filter(Boolean).join(', ');
        row.appendChild(sub);
      }
      row.addEventListener('click', () => selectItem(it));
      dropdown.appendChild(row);
    });
    dropdown.style.display = 'block';
  }

  function selectItem(it) {
    if (!it) return;
    const cleaned = (it.display || '').replace(/,\s*United States(,|$)/i, '').trim();
    input.value = cleaned;
    hideDropdown();

    // Apply via canonical hook — UI contract
    if (window.KNX_HUB_LOCATION && typeof window.KNX_HUB_LOCATION.applySelectedLocation === 'function') {
      window.KNX_HUB_LOCATION.applySelectedLocation({ address: cleaned, lat: it.lat, lng: it.lng, provider: it.provider || 'nominatim', housenumber: it.housenumber || '', street: it.street || '', city: it.city || '', state: it.state || '', postcode: it.postcode || '' });
    }
  }

  function hideDropdown() { dropdown.style.display = 'none'; items = []; activeIndex = -1; }

})();
