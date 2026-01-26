/**
 * Kingdom Nexus - Home Page Script (v3.1)
 * - Autocomplete con Nominatim (US/CA/MX)
 * - Redirección a /explore-hubs/?location=...
 * - Sin alerts nativos; mensajes en #knx-geolocation-status
 */

document.addEventListener("DOMContentLoaded", () => {
  const input       = document.getElementById("knx-address-input");
  const searchBtn   = document.getElementById("knx-search-btn");
  const statusDiv   = document.getElementById("knx-geolocation-status");
  const dropdown    = document.getElementById("knx-autocomplete-dropdown");

  let debounceTimer = null;
  let isLoading     = false;

  // ========= Utils =========
  const showStatus = (msg, type = "info", autohide = true) => {
    if (!statusDiv) return;
    statusDiv.textContent = msg;
    statusDiv.className   = `knx-status-message knx-status-${type}`;
    statusDiv.style.display = "block";
    if (autohide) setTimeout(() => statusDiv.style.display = "none", 4000);
  };

  const hideStatus = () => {
    if (!statusDiv) return;
    statusDiv.style.display = "none";
  };

  const ensureDropdown = () => {
    if (!dropdown) return null;
    dropdown.style.display = "block";
    return dropdown;
  };

  const removeDropdown = () => {
    if (!dropdown) return;
    dropdown.innerHTML = "";
    dropdown.style.display = "none";
  };

  const escapeHtml = (s) => {
    const div = document.createElement("div");
    div.textContent = s || "";
    return div.innerHTML;
  };

  const redirectToExplore = (locationStr) => {
    const base = (window.knxHome && knxHome.exploreUrl) ? knxHome.exploreUrl : "/explore-hubs/";
    const url  = new URL(base, window.location.origin);
    url.searchParams.set("location", locationStr);

    // opcional: guardar ubicación “actual”
    try { localStorage.setItem("knx_location", locationStr); } catch {}
    if (window.knxNavbar?.setLocation) {
      try { window.knxNavbar.setLocation(locationStr); } catch {}
    }

    window.location.href = url.toString();
  };

  // ========= Autocomplete (Nominatim) =========
  const tokenizeUSAddress = (input) => {
    const s = (input || "").trim();
    const re = /^\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([A-Za-z]{2}|[A-Za-z ]+)(?:\s+(\d{5}(?:-\d{4})?))?/;
    const m = s.match(re);
    if (!m) return null;
    let [, street, city, state, zip] = m;
    street = street.replace(/\.+$/,"");
    return { street, city, state, zip: zip || "" };
  };

  const buildStructuredUrl = (tokens, limit) => {
    const base = "https://nominatim.openstreetmap.org/search";
    const p = new URLSearchParams({
      format: "json",
      addressdetails: "1",
      extratags: "1",
      namedetails: "1",
      limit: String(limit || 10),
      dedupe: "0",
      countrycodes: "us,ca,mx",
      street: tokens.street,
      city: tokens.city,
      state: tokens.state
    });
    if (tokens.zip) p.set("postalcode", tokens.zip);
    return `${base}?${p.toString()}`;
  };

  const buildQueryUrl = (q, limit) => {
    const base = "https://nominatim.openstreetmap.org/search";
    const p = new URLSearchParams({
      format: "json",
      q,
      addressdetails: "1",
      extratags: "1",
      namedetails: "1",
      countrycodes: "us,ca,mx",
      limit: String(limit || 10),
      dedupe: "0"
    });
    return `${base}?${p.toString()}`;
  };

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

  const formatOSMDisplay = (item) => {
    const a = item.address || {};
    // Para explorar por ciudad/estado → "City, ST" si aplica
    const city = a.city || a.town || a.village || a.county || "";
    const st   = (a.state && stateAbbr[a.state]) ? stateAbbr[a.state] : (a.state || "");
    const parts = [];
    if (city) parts.push(city);
    if (st)   parts.push(st);
    return parts.join(", ");
  };

  const renderSuggestions = (results) => {
    const dd = ensureDropdown();
    if (!dd) return;

    dd.innerHTML = results.map(r => {
      const label = formatOSMDisplay(r) || (r.display_name || "").split(",").slice(0,2).join(", ");
      return `
        <div class="knx-autocomplete-item" data-location="${escapeHtml(label)}">
          <span class="knx-autocomplete-icon">🏙️</span>
          <div class="knx-autocomplete-text">
            <div class="knx-autocomplete-main">${escapeHtml(label)}</div>
          </div>
        </div>
      `;
    }).join("");

    dd.querySelectorAll(".knx-autocomplete-item[data-location]").forEach(row => {
      row.addEventListener("click", () => {
        const location = row.getAttribute("data-location");
        input.value = location;
        removeDropdown();
        redirectToExplore(location);
      });
    });
  };

  const showLoading = () => {
    const dd = ensureDropdown(); if (!dd) return;
    dd.innerHTML = `
      <div class="knx-autocomplete-item" style="justify-content:center;color:#999;">
        <i class="fas fa-spinner fa-spin"></i>
      </div>
    `;
  };

  const showNoResults = () => {
    const dd = ensureDropdown(); if (!dd) return;
    dd.innerHTML = `<div class="knx-autocomplete-item knx-no-results">No locations found</div>`;
  };

  const fetchSuggestions = (q) => {
    clearTimeout(debounceTimer);
    if (!q || q.trim().length < 2) { removeDropdown(); return; }

    debounceTimer = setTimeout(async () => {
      if (isLoading) return;
      isLoading = true;
      showLoading();

      try {
        const tokens = tokenizeUSAddress(q.trim());
        let data = [];

        if (tokens) {
          const u1 = buildStructuredUrl(tokens, 8);
          data = await (await fetch(u1, { headers: { "Accept-Language": "en-US" } })).json();
        }
        if (!data || data.length === 0) {
          const u2 = buildQueryUrl(q.trim(), 8);
          data = await (await fetch(u2, { headers: { "Accept-Language": "en-US" } })).json();
        }

        if (!data || data.length === 0) { showNoResults(); return; }
        renderSuggestions(data);
      } catch (err) {
        showNoResults();
      } finally {
        isLoading = false;
      }
    }, 200);
  };

  // ========= Events =========
  input.addEventListener("input", (e) => {
    fetchSuggestions(e.target.value);
  });

  input.addEventListener("focus", () => {
    const v = input.value.trim();
    if (v.length >= 2) fetchSuggestions(v);
  });

  input.addEventListener("blur", () => {
    setTimeout(() => removeDropdown(), 150);
  });

  document.addEventListener("click", (e) => {
    if (!dropdown) return;
    if (!dropdown.contains(e.target) && e.target !== input) removeDropdown();
  });

  input.addEventListener("keypress", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      const v = input.value.trim();
      if (v) redirectToExplore(v);
      else showStatus("Please enter a city or address", "error");
    }
  });

  searchBtn.addEventListener("click", () => {
    const v = input.value.trim();
    if (v) redirectToExplore(v);
    else showStatus("Please enter a city or address", "error");
  });
});
