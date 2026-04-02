/**
 * ==========================================================
 * KNX Hub Management — Items Overlay Script
 * ----------------------------------------------------------
 * Loaded AFTER the canonical edit-hub-items.js.
 *
 * Responsibilities:
 *  1. Toggle availability fields in the Add Item modal
 *     (daily day/time ↔ seasonal date range ↔ regular)
 *  2. Inject availability columns into FormData before submit
 *  3. Render availability badges on item cards after load
 *  4. Enforce hub_management ownership (data-mode flag)
 *  5. Search form — intercept submit for SPA search
 *
 * No duplicate DOMContentLoaded: we execute immediately since
 * this script tag comes after the canonical one and the DOM
 * is already parsed (deferred scripts load in order).
 * ==========================================================
 */
(function () {
  "use strict";

  const wrap = document.querySelector(".knx-items-wrapper");
  if (!wrap || wrap.dataset.mode !== "hub-management") return;

  /* ────────────────────────────────────────────────────────
     1. AVAILABILITY TYPE TOGGLE
  ──────────────────────────────────────────────────────── */
  const availSelect = document.getElementById("knxItemAvailability");
  const dailyBlock  = document.getElementById("knxAvailDaily");
  const seasonalBlock = document.getElementById("knxAvailSeasonal");

  function toggleAvailFields() {
    if (!availSelect) return;
    const v = availSelect.value;
    if (dailyBlock) dailyBlock.style.display = v === "daily" ? "" : "none";
    if (seasonalBlock) seasonalBlock.style.display = v === "seasonal" ? "" : "none";
  }

  if (availSelect) {
    availSelect.addEventListener("change", toggleAvailFields);
    toggleAvailFields(); // init
  }

  /* ────────────────────────────────────────────────────────
     2. INTERCEPT ADD-ITEM FORM SUBMIT
        — Append availability fields to FormData before
          the canonical handler fires.
  ──────────────────────────────────────────────────────── */
  const addForm = document.getElementById("knxAddItemForm");

  if (addForm) {
    // Use capturing listener so we fire BEFORE the canonical handler
    addForm.addEventListener(
      "submit",
      function (e) {
        // Nothing to do for regular — the API ignores extra fields
        if (!availSelect) return;

        const type = availSelect.value || "regular";

        // Store values in hidden inputs so the canonical FormData picks them up
        injectHidden(addForm, "availability_type", type);

        if (type === "daily") {
          const checked = [];
          addForm
            .querySelectorAll('input[name="daily_days[]"]:checked')
            .forEach((cb) => checked.push(cb.value));
          injectHidden(addForm, "daily_day_of_week", checked.join(","));
          injectHidden(
            addForm,
            "daily_start_time",
            document.getElementById("knxDailyStart")?.value || ""
          );
          injectHidden(
            addForm,
            "daily_end_time",
            document.getElementById("knxDailyEnd")?.value || ""
          );
        }

        if (type === "seasonal") {
          injectHidden(
            addForm,
            "seasonal_starts_at",
            document.getElementById("knxSeasonalStart")?.value || ""
          );
          injectHidden(
            addForm,
            "seasonal_ends_at",
            document.getElementById("knxSeasonalEnd")?.value || ""
          );
        }
      },
      true // capture phase — runs before the bubble handler in canonical JS
    );
  }

  /**
   * Inject or update a hidden input inside a form.
   */
  function injectHidden(form, name, value) {
    let el = form.querySelector(`input[type="hidden"][name="${name}"]`);
    if (!el) {
      el = document.createElement("input");
      el.type = "hidden";
      el.name = name;
      form.appendChild(el);
    }
    el.value = value;
  }

  /* ────────────────────────────────────────────────────────
     3. AVAILABILITY BADGES ON ITEM CARDS
        — Observe the items container and inject badges after
          each render cycle from canonical loadItems().
  ──────────────────────────────────────────────────────── */
  const grid = document.getElementById("knxCategoriesContainer");
  if (grid) {
    const observer = new MutationObserver(() => {
      decorateItemCards();
    });
    observer.observe(grid, { childList: true, subtree: true });
  }

  function decorateItemCards() {
    const cards = document.querySelectorAll(".knx-item-card");
    cards.forEach((card) => {
      // Avoid double-decorating
      if (card.dataset.availDecorated) return;
      card.dataset.availDecorated = "1";

      // The canonical API returns all columns; locate the rendered item data
      // from the card's inner link href which contains item_id
      const editLink = card.querySelector('a[href*="item_id="]');
      if (!editLink) return;

      // We'll read availability from a data attribute we inject during render.
      // Since we can't modify the canonical renderItems, we use a lighter approach:
      // after all items are loaded, we fetch the hub items ourselves (once) to build
      // an availability map, then apply badges.
    });

    // Only fetch once per render cycle
    if (!grid._availFetched) {
      grid._availFetched = true;
      fetchAndBadge();
    }
  }

  async function fetchAndBadge() {
    try {
      const hubId = wrap.dataset.hubId;
      const res = await fetch(
        `${wrap.dataset.apiGet}?hub_id=${hubId}`
      );
      const data = await res.json();
      if (!data.success || !data.items) return;

      // Build a map: item.id → availability_type
      const map = {};
      data.items.forEach((it) => {
        if (it.availability_type && it.availability_type !== "regular") {
          map[it.id] = it.availability_type;
        }
      });

      // Apply badges
      document.querySelectorAll(".knx-item-card").forEach((card) => {
        const editLink = card.querySelector("a[href*='item_id=']");
        if (!editLink) return;

        const match = editLink.href.match(/item_id=(\d+)/);
        if (!match) return;
        const itemId = match[1];

        if (map[itemId] && !card.querySelector(".knx-avail-badge")) {
          const badge = document.createElement("span");
          badge.className = "knx-avail-badge";
          badge.style.cssText =
            "position:absolute;top:6px;left:6px;padding:2px 8px;border-radius:10px;" +
            "font-size:11px;font-weight:600;z-index:2;pointer-events:none;";

          if (map[itemId] === "daily") {
            badge.textContent = "Daily";
            badge.style.background = "#3b82f6";
            badge.style.color = "#fff";
          } else if (map[itemId] === "seasonal") {
            badge.textContent = "Seasonal";
            badge.style.background = "#f59e0b";
            badge.style.color = "#fff";
          }

          const imgWrap = card.querySelector(".knx-item-card__img-wrap");
          if (imgWrap) {
            imgWrap.style.position = "relative";
            imgWrap.appendChild(badge);
          }
        }
      });
    } catch (err) {
      console.error("[hub-items-overlay] badge fetch error:", err);
    }
  }

  // Reset the fetch flag whenever the grid is cleared (new render cycle)
  if (grid) {
    const origObserver = new MutationObserver((mutations) => {
      for (const m of mutations) {
        if (m.removedNodes.length > 0) {
          grid._availFetched = false;
          break;
        }
      }
    });
    origObserver.observe(grid, { childList: true });
  }

  /* ────────────────────────────────────────────────────────
     4. SEARCH FORM — SPA intercept
        (canonical JS may not handle our search form since
         our hidden input is `hub_id` not `id`)
  ──────────────────────────────────────────────────────── */
  const searchForm = document.getElementById("knxSearchForm");
  const searchInput = document.getElementById("knxSearchInput");

  if (searchForm && searchInput && grid) {
    searchForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const q = searchInput.value.trim();
      const hubId = wrap.dataset.hubId;
      try {
        grid.innerHTML =
          "<p style='text-align:center;'>Searching...</p>";
        const url = `${wrap.dataset.apiGet}?hub_id=${hubId}${q ? "&search=" + encodeURIComponent(q) : ""}`;
        const res = await fetch(url);
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        // Use the canonical renderItems if it's exposed; otherwise do a full reload
        // Since edit-hub-items.js scopes renderItems inside its closure, we trigger
        // a reload by dispatching a custom event it can listen to, OR we simply
        // inject the results into the DOM ourselves.
        renderSearchResults(data.items || [], q);
      } catch (err) {
        console.error(err);
        grid.innerHTML =
          "<p style='text-align:center;color:red;'>Search error</p>";
      }
    });
  }

  /**
   * Lightweight renderer for search results.
   * Replicates the canonical card structure so existing event
   * handlers (delete, reorder) still bind via delegation.
   */
  function renderSearchResults(items, query) {
    if (!grid) return;
    grid.innerHTML = "";

    if (!items.length) {
      grid.innerHTML = `<p style='text-align:center;'>No items match "${escHtml(query)}".</p>`;
      return;
    }

    // Group by category (same as canonical)
    const grouped = {};
    items.forEach((item) => {
      const catId = item.category_id || 0;
      if (!grouped[catId]) grouped[catId] = [];
      grouped[catId].push(item);
    });

    const hubId = wrap.dataset.hubId;

    Object.keys(grouped).forEach((catId) => {
      const block = document.createElement("div");
      block.className = "knx-category-block";
      block.dataset.catId = catId;

      const catName =
        items.find((i) => String(i.category_id) === String(catId))
          ?.category_name || "Uncategorized";

      const header = document.createElement("div");
      header.className = "knx-category-header";
      header.innerHTML = `<h3>${escHtml(catName)}</h3>`;
      block.appendChild(header);

      const container = document.createElement("div");
      container.className = "knx-item-grid";
      if (grouped[catId].length === 1) {
        container.classList.add("knx-item-grid--single");
      }

      grouped[catId].forEach((item) => {
        const card = document.createElement("div");
        card.className = "knx-item-card";
        if (item.status === "inactive") card.classList.add("inactive");
        card.innerHTML = `
          <div class="knx-item-card__img-wrap">
            <img src="${item.image_url || "https://via.placeholder.com/400x200?text=No+Image"}" alt="${escHtml(item.name)}">
          </div>
          <div class="knx-item-card__body">
            <span class="knx-item-price-pill">$${parseFloat(item.price).toFixed(2)}</span>
            <div class="knx-item-info">
              <h4>${escHtml(item.name)}</h4>
              <p>${item.description ? escHtml(item.description.substring(0, 80)) : ""}</p>
            </div>
            <div class="knx-item-footer">
              <div class="knx-actions">
                <button class="knx-action-icon move-up" data-id="${item.id}" title="Move Up"><i class="fas fa-chevron-up"></i></button>
                <button class="knx-action-icon move-down" data-id="${item.id}" title="Move Down"><i class="fas fa-chevron-down"></i></button>
                <a href="/edit-item?hub_id=${hubId}&item_id=${item.id}" class="knx-action-icon" title="Edit"><i class="fas fa-pen"></i></a>
                <button class="knx-action-icon delete" data-id="${item.id}" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </div>
          </div>
        `;
        container.appendChild(card);
      });

      block.appendChild(container);
      grid.appendChild(block);
    });

    // Trigger badge decoration
    grid._availFetched = false;
    decorateItemCards();
  }

  function escHtml(s) {
    const d = document.createElement("div");
    d.textContent = s || "";
    return d.innerHTML;
  }
})();
