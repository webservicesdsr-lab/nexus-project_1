/* ============================================================
   KNX Price Calculator — Floating Bubble Widget
   Standalone self-initialising IIFE.
   Works in any KNX page — no dependencies.
   ============================================================ */
(function () {
  'use strict';

  /* ── Prevent double-load ────────────────────────────── */
  if (window.__knxPriceCalcLoaded) return;
  window.__knxPriceCalcLoaded = true;

  /* ── Constants ──────────────────────────────────────── */
  const BUBBLE_SIZE   = 52;
  const EDGE_MARGIN   = 10;
  const SNAP_DURATION = 250;           // ms for edge-snap animation
  const TAP_THRESHOLD = 6;             // px — less than this = tap, not drag
  const MAX_COMPARE   = 5;
  const DEFAULT_PCT   = 13;
  const PANEL_GAP     = 10;            // space between bubble and panel

  /* ── State ──────────────────────────────────────────── */
  let posX = 0;
  let posY = 0;
  let isOpen     = false;
  let dragging   = false;
  let startPX    = 0;
  let startPY    = 0;
  let startBX    = 0;
  let startBY    = 0;
  let toastTimer = null;

  /* ── DOM creation ───────────────────────────────────── */
  const root = document.createElement('div');
  root.className = 'knx-pcalc';

  // Mini bubble
  const mini = document.createElement('div');
  mini.className = 'knx-pcalc__mini';
  mini.textContent = '%';
  root.appendChild(mini);

  // Panel
  const panel = document.createElement('div');
  panel.className = 'knx-pcalc__panel';
  panel.innerHTML = buildPanelHTML();
  root.appendChild(panel);

  // Toast
  const toastEl = document.createElement('div');
  toastEl.className = 'knx-pcalc__toast';
  root.appendChild(toastEl);

  /* ── Panel HTML builder ─────────────────────────────── */
  function buildPanelHTML() {
    return (
      '<div class="knx-pcalc__header">' +
        '<h4 class="knx-pcalc__title">Price Calculator</h4>' +
        '<button type="button" class="knx-pcalc__close" id="knxPcClose">&times;</button>' +
      '</div>' +
      '<div class="knx-pcalc__body" id="knxPcBody">' +

        /* Percentage */
        '<div class="knx-pcalc__pct-row">' +
          '<span class="knx-pcalc__label">Mark-up</span>' +
          '<input type="number" class="knx-pcalc__pct-input" id="knxPcPct" value="' + DEFAULT_PCT + '" min="0" max="100" step="1" inputmode="decimal">' +
          '<span class="knx-pcalc__pct-suffix">%</span>' +
        '</div>' +

        /* Base price */
        '<div class="knx-pcalc__base-row">' +
          '<span class="knx-pcalc__field-label">Base price</span>' +
          '<div class="knx-pcalc__price-input-wrap">' +
            '<span class="knx-pcalc__dollar">$</span>' +
            '<input type="text" class="knx-pcalc__price-input" id="knxPcBase" placeholder="0.00" inputmode="decimal">' +
          '</div>' +
        '</div>' +

        /* Base result */
        '<div class="knx-pcalc__base-result" id="knxPcBaseResult" style="display:none;">' +
          '<span class="knx-pcalc__base-result-label">Your price</span>' +
          '<span class="knx-pcalc__base-result-value" id="knxPcBaseVal">—</span>' +
        '</div>' +

        '<div class="knx-pcalc__divider"></div>' +

        /* Compare section */
        '<div class="knx-pcalc__compare-section">' +
          '<div class="knx-pcalc__compare-header">' +
            '<span class="knx-pcalc__compare-title">Higher prices</span>' +
            '<button type="button" class="knx-pcalc__add-btn" id="knxPcAdd" title="Add price">+</button>' +
          '</div>' +
          '<div id="knxPcCompareList"></div>' +
        '</div>' +

      '</div>'
    );
  }

  /* ── Refs (resolved after insertion) ────────────────── */
  let pctInput, baseInput, baseResultRow, baseValEl, compareList, addBtn, closeBtn;

  function resolveRefs() {
    pctInput      = panel.querySelector('#knxPcPct');
    baseInput     = panel.querySelector('#knxPcBase');
    baseResultRow = panel.querySelector('#knxPcBaseResult');
    baseValEl     = panel.querySelector('#knxPcBaseVal');
    compareList   = panel.querySelector('#knxPcCompareList');
    addBtn        = panel.querySelector('#knxPcAdd');
    closeBtn      = panel.querySelector('#knxPcClose');
  }

  /* ── Math helpers ───────────────────────────────────── */
  function parseMoney(s) {
    const n = parseFloat(String(s || '').replace(/[^0-9.\-]/g, ''));
    return Number.isFinite(n) ? n : NaN;
  }

  function applyMarkup(price, pct) {
    return price * (1 + pct / 100);
  }

  /**
   * Round to nearest X.X9
   * e.g. 4.51 → 4.59,  5.64 → 5.69,  12.03 → 11.99 or 12.09
   * Rule: find the nearest value ending in .X9
   */
  function roundTo9(val) {
    // Get to the nearest dime below, then add 0.09
    const floor9 = Math.floor(val * 10) / 10 + 0.09;
    const ceil9  = floor9 + 0.10;

    // Pick whichever .X9 is closest
    return (Math.abs(val - floor9) <= Math.abs(val - ceil9)) ? floor9 : ceil9;
  }

  function fmt(n) {
    return '$' + n.toFixed(2);
  }

  /* ── Recalculate everything ─────────────────────────── */
  function recalc() {
    const pct  = parseMoney(pctInput.value);
    const base = parseMoney(baseInput.value);

    // Base result
    if (Number.isFinite(base) && base > 0 && Number.isFinite(pct)) {
      const baseMarked = applyMarkup(base, pct);
      const baseRec    = roundTo9(baseMarked);
      baseValEl.textContent = fmt(baseRec);
      baseResultRow.style.display = '';
    } else {
      baseResultRow.style.display = 'none';
      baseValEl.textContent = '—';
    }

    // Compare items
    const items = compareList.querySelectorAll('.knx-pcalc__compare-item');
    items.forEach(item => {
      const input    = item.querySelector('.knx-pcalc__price-input');
      const chipFin  = item.querySelector('[data-role="final"]');
      const chipDiff = item.querySelector('[data-role="diff"]');
      const chipRec  = item.querySelector('[data-role="rec"]');

      const higher = parseMoney(input.value);

      if (Number.isFinite(higher) && higher > 0 && Number.isFinite(base) && base > 0 && Number.isFinite(pct)) {
        const baseMarked   = applyMarkup(base, pct);
        const higherMarked = applyMarkup(higher, pct);
        const baseRec      = roundTo9(baseMarked);
        const higherRec    = roundTo9(higherMarked);
        const diff         = higherRec - baseRec;

        chipFin.textContent  = fmt(higherRec);
        chipDiff.textContent = '+' + fmt(diff).replace('$', '$');
        chipRec.textContent  = 'Mod: ' + fmt(diff);

        chipFin.style.display  = '';
        chipDiff.style.display = '';
        chipRec.style.display  = '';
      } else {
        chipFin.style.display  = 'none';
        chipDiff.style.display = 'none';
        chipRec.style.display  = 'none';
      }
    });

    syncAddBtn();
  }

  /* ── Compare items ──────────────────────────────────── */
  let compareCount = 0;

  function syncAddBtn() {
    const count = compareList.querySelectorAll('.knx-pcalc__compare-item').length;
    addBtn.disabled = count >= MAX_COMPARE;
  }

  function addCompareItem(prefillValue) {
    const count = compareList.querySelectorAll('.knx-pcalc__compare-item').length;
    if (count >= MAX_COMPARE) return;

    compareCount++;
    const idx = compareCount;
    const val = prefillValue || '';

    const el = document.createElement('div');
    el.className = 'knx-pcalc__compare-item';
    el.innerHTML =
      '<div class="knx-pcalc__compare-item-header">' +
        '<span class="knx-pcalc__compare-item-label">Price #' + idx + '</span>' +
        '<button type="button" class="knx-pcalc__remove-btn" data-action="remove">&times;</button>' +
      '</div>' +
      '<div class="knx-pcalc__price-input-wrap">' +
        '<span class="knx-pcalc__dollar">$</span>' +
        '<input type="text" class="knx-pcalc__price-input" placeholder="0.00" inputmode="decimal" value="' + val + '">' +
      '</div>' +
      '<div class="knx-pcalc__compare-results">' +
        '<span class="knx-pcalc__result-chip knx-pcalc__result-chip--final" data-role="final" style="display:none;"></span>' +
        '<span class="knx-pcalc__result-chip knx-pcalc__result-chip--diff" data-role="diff" style="display:none;"></span>' +
        '<span class="knx-pcalc__result-chip knx-pcalc__result-chip--rec" data-role="rec" style="display:none;"></span>' +
      '</div>';

    // Events
    el.querySelector('.knx-pcalc__price-input').addEventListener('input', recalc);
    el.querySelector('[data-action="remove"]').addEventListener('click', () => {
      el.remove();
      recalc();
    });

    compareList.appendChild(el);
    recalc();

    // Focus the new input
    el.querySelector('.knx-pcalc__price-input').focus();
  }

  /* ── Toast ──────────────────────────────────────────── */
  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('is-visible'), 2200);
  }

  /* ── Position helpers ───────────────────────────────── */
  function clampPos(x, y) {
    const maxX = window.innerWidth  - BUBBLE_SIZE - EDGE_MARGIN;
    const maxY = window.innerHeight - BUBBLE_SIZE - EDGE_MARGIN;
    return {
      x: Math.max(EDGE_MARGIN, Math.min(maxX, x)),
      y: Math.max(EDGE_MARGIN, Math.min(maxY, y)),
    };
  }

  function applyPos(x, y) {
    posX = x;
    posY = y;
    root.style.left = x + 'px';
    root.style.top  = y + 'px';
  }

  function snapToEdge(animate) {
    const midX = window.innerWidth / 2;
    const targetX = (posX + BUBBLE_SIZE / 2) < midX
      ? EDGE_MARGIN
      : window.innerWidth - BUBBLE_SIZE - EDGE_MARGIN;

    if (animate) {
      root.classList.add('knx-pcalc--snap-animate');
      requestAnimationFrame(() => applyPos(targetX, posY));
      setTimeout(() => root.classList.remove('knx-pcalc--snap-animate'), SNAP_DURATION + 20);
    } else {
      applyPos(targetX, posY);
    }
  }

  /* ── Panel positioning ──────────────────────────────── */
  function positionPanel() {
    const bx = posX;
    const by = posY;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const pw = 300;               // panel width
    const ph = panel.offsetHeight || 380;

    // Decide horizontal: open towards center
    const onRight = (bx + BUBBLE_SIZE / 2) > vw / 2;
    let px, py;

    if (onRight) {
      // Bubble is on the right → panel opens to the left
      px = bx - pw - PANEL_GAP;
      if (px < EDGE_MARGIN) px = bx + BUBBLE_SIZE + PANEL_GAP;
    } else {
      // Bubble is on the left → panel opens to the right
      px = bx + BUBBLE_SIZE + PANEL_GAP;
      if (px + pw > vw - EDGE_MARGIN) px = bx - pw - PANEL_GAP;
    }

    // Decide vertical: try to align top of panel with bubble, then clamp
    py = by - ph + BUBBLE_SIZE;
    if (py < EDGE_MARGIN) py = EDGE_MARGIN;
    if (py + ph > vh - EDGE_MARGIN) py = vh - ph - EDGE_MARGIN;

    // Clamp horizontal too
    px = Math.max(EDGE_MARGIN, Math.min(vw - pw - EDGE_MARGIN, px));

    panel.style.left = (px - bx) + 'px';
    panel.style.top  = (py - by) + 'px';

    // Set transform-origin for nice scale animation
    const fromRight = px < bx;
    const fromBottom = py < by;
    panel.style.transformOrigin =
      (fromRight ? 'right' : 'left') + ' ' +
      (fromBottom ? 'bottom' : 'top');

    // Arrow direction
    panel.classList.remove(
      'knx-pcalc__panel--anchor-br',
      'knx-pcalc__panel--anchor-bl',
      'knx-pcalc__panel--anchor-tr',
      'knx-pcalc__panel--anchor-tl'
    );
    // We skip the arrow nub for now to keep it clean — the shadow is enough
  }

  /* ── Open / Close ───────────────────────────────────── */
  function openPanel() {
    if (isOpen) return;
    isOpen = true;
    positionPanel();
    panel.classList.add('is-open');
  }

  function closePanel() {
    if (!isOpen) return;
    isOpen = false;
    panel.classList.remove('is-open');
  }

  function togglePanel() {
    isOpen ? closePanel() : openPanel();
  }

  /* ── Drag handling ──────────────────────────────────── */
  function onPointerDown(e) {
    if (isOpen) return;         // don't drag while panel is open
    dragging = true;
    startPX = e.clientX;
    startPY = e.clientY;
    startBX = posX;
    startBY = posY;
    mini.setPointerCapture(e.pointerId);
    e.preventDefault();
  }

  function onPointerMove(e) {
    if (!dragging) return;
    const dx = e.clientX - startPX;
    const dy = e.clientY - startPY;
    const clamped = clampPos(startBX + dx, startBY + dy);
    applyPos(clamped.x, clamped.y);
  }

  function onPointerUp(e) {
    if (!dragging) return;
    dragging = false;

    const dx = Math.abs(e.clientX - startPX);
    const dy = Math.abs(e.clientY - startPY);

    if (dx < TAP_THRESHOLD && dy < TAP_THRESHOLD) {
      // It was a tap → toggle panel
      togglePanel();
    } else {
      // It was a drag → snap to edge
      snapToEdge(true);
    }
  }

  /* ── Close on outside click ─────────────────────────── */
  function onDocClick(e) {
    if (!isOpen) return;
    if (root.contains(e.target)) return;
    closePanel();
  }

  /* ── Keyboard ───────────────────────────────────────── */
  function onKeyDown(e) {
    if (e.key === 'Escape' && isOpen) {
      closePanel();
    }
  }

  /* ── Resize ─────────────────────────────────────────── */
  function onResize() {
    const clamped = clampPos(posX, posY);
    applyPos(clamped.x, clamped.y);
    if (isOpen) positionPanel();
  }

  /* ── Initialise ─────────────────────────────────────── */
  function init() {
    document.body.appendChild(root);

    resolveRefs();

    // Initial position: bottom-right
    const initX = window.innerWidth  - BUBBLE_SIZE - EDGE_MARGIN;
    const initY = window.innerHeight - BUBBLE_SIZE - 80;
    applyPos(initX, initY);

    // Events — bubble drag
    mini.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('pointermove', onPointerMove);
    document.addEventListener('pointerup', onPointerUp);

    // Events — panel
    closeBtn.addEventListener('click', closePanel);
    addBtn.addEventListener('click', () => addCompareItem(''));

    // Events — inputs
    pctInput.addEventListener('input', recalc);
    baseInput.addEventListener('input', recalc);

    // Events — global
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeyDown);
    window.addEventListener('resize', onResize);

    // Add one compare slot by default
    addCompareItem('');
  }

  /* ── Boot ────────────────────────────────────────────── */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }

})();
