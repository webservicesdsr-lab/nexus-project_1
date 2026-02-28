// File: inc/modules/settings/settings-upload.js

/**
 * ==========================================================
 * Kingdom Nexus — Settings Upload + Display Adjust (v4.1)
 * ----------------------------------------------------------
 * Targets:
 * - site_logo       (file + optional view_json)
 * - home_center     (file + optional view_json)
 * - home_copy       (home_headline)
 * - city_grid_theme (theme_json)  -> SSOT DB singleton {prefix}knx_city_branding (id=1)
 *
 * Endpoint:
 * POST /wp-json/knx/v1/save-branding
 *
 * Notes:
 * - Uses existing IDs from settings-shortcode.php
 * - Uses shared SSOT CSS for city grid preview (knx-city-grid.css)
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', () => {
  const cfg = window.knx_settings || {};
  const root = cfg.root || '';
  const wpNonce = cfg.wp_nonce || '';
  const placeholder = cfg.placeholder || '';
  const targetsCfg = cfg.targets || {};
  const themeCfg = cfg.city_grid_theme || {};

  const toast = (msg, type) => {
    if (typeof window.knxToast === 'function') return window.knxToast(msg, type);
    console[type === 'error' ? 'error' : 'log']('[KNX-SETTINGS]', msg);
    alert(msg);
  };

  // ==========================================================
  // Shared REST helper
  // ==========================================================
  async function postBranding(formData) {
    const res = await fetch(`${root}knx/v1/save-branding`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': wpNonce },
      body: formData
    });
    const payload = await res.json().catch(() => ({}));
    return { res, payload };
  }

  async function saveBranding({ target, file = null, view = null, homeHeadline = null, themeJson = null }) {
    if (!root || !wpNonce) {
      toast('❌ Missing settings config.', 'error');
      return { ok: false };
    }

    const formData = new FormData();
    formData.append('target', target);

    if (target === 'home_copy') {
      formData.append('home_headline', homeHeadline || '');
    } else if (target === 'city_grid_theme') {
      formData.append('theme_json', themeJson || '');
    } else {
      if (view) formData.append('view_json', JSON.stringify(view));
      if (file) formData.append('file', file);
    }

    const { res, payload } = await postBranding(formData);

    if (!res.ok || !payload || !payload.success) {
      toast((payload && payload.message) ? payload.message : '❌ Save failed.', 'error');
      return { ok: false, payload };
    }

    toast(payload.message || '✅ Saved', 'success');
    return { ok: true, payload };
  }

  // ==========================================================
  // Image upload + display adjust (pan/zoom)
  // ==========================================================
  const validTypes = ['image/jpeg', 'image/png', 'image/webp'];

  function validateFile(file) {
    if (!file) return false;
    if (!validTypes.includes(file.type)) {
      toast('❌ Invalid file type. Please upload JPG, PNG, or WEBP.', 'error');
      return false;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast('⚠️ File too large. Max 5MB allowed.', 'error');
      return false;
    }
    return true;
  }

  function normalizeView(v) {
    const out = {
      scale: v && v.scale != null ? Number(v.scale) : 1,
      x: v && v.x != null ? Number(v.x) : 0,
      y: v && v.y != null ? Number(v.y) : 0
    };
    out.scale = Math.max(0.6, Math.min(2.6, out.scale));
    out.x = Math.max(-520, Math.min(520, out.x));
    out.y = Math.max(-320, Math.min(320, out.y));
    return out;
  }

  const modal = document.getElementById('knxBrandingModal');
  const modalOverlay = document.getElementById('knxBrandingModalOverlay');
  const modalClose = document.getElementById('knxBrandingModalClose');

  const frame = document.getElementById('knxBrandingFrame');
  const frameImg = document.getElementById('knxBrandingFrameImg');
  const zoomEl = document.getElementById('knxBrandingZoom');
  const resetBtn = document.getElementById('knxBrandingReset');
  const applyBtn = document.getElementById('knxBrandingApply');

  const state = {
    activeTarget: 'site_logo',
    views: {
      site_logo: normalizeView(targetsCfg.site_logo && targetsCfg.site_logo.view),
      home_center: normalizeView(targetsCfg.home_center && targetsCfg.home_center.view),
    },
    urls: {
      site_logo: (targetsCfg.site_logo && targetsCfg.site_logo.url) || '',
      home_center: (targetsCfg.home_center && targetsCfg.home_center.url) || '',
    },
    frames: {
      site_logo: (targetsCfg.site_logo && targetsCfg.site_logo.frame) || { w: 160, h: 45 },
      home_center: (targetsCfg.home_center && targetsCfg.home_center.frame) || { w: 420, h: 150 },
    }
  };

  function applyViewToFrame() {
    if (!frame) return;
    const v = normalizeView(state.views[state.activeTarget]);
    state.views[state.activeTarget] = v;

    frame.style.setProperty('--knx-logo-scale', String(v.scale));
    frame.style.setProperty('--knx-logo-x', `${v.x}px`);
    frame.style.setProperty('--knx-logo-y', `${v.y}px`);
    if (zoomEl) zoomEl.value = String(v.scale);
  }

  function openModal(target) {
    if (!modal || !frame || !frameImg) return;

    state.activeTarget = target;

    const f = state.frames[target] || { w: 160, h: 45 };
    frame.style.width = `${f.w}px`;
    frame.style.height = `${f.h}px`;

    const srcFromDom = (target === 'home_center')
      ? (document.getElementById('knxHomeCenterPreview')?.src || '')
      : (document.getElementById('knxBrandingPreview')?.src || '');

    frameImg.src = srcFromDom || state.urls[target] || placeholder;

    applyViewToFrame();

    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('is-open');
    document.documentElement.classList.add('knx-modal-open');
  }

  function closeModal() {
    if (!modal) return;
    modal.setAttribute('aria-hidden', 'true');
    modal.classList.remove('is-open');
    document.documentElement.classList.remove('knx-modal-open');
  }

  if (modalOverlay) modalOverlay.addEventListener('click', closeModal);
  if (modalClose) modalClose.addEventListener('click', closeModal);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) closeModal();
  });

  // Drag to pan
  let dragging = false, startX = 0, startY = 0, baseX = 0, baseY = 0;

  function pointerDown(e) {
    if (!frame) return;
    dragging = true;

    const ptX = (e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
    const ptY = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;

    const v = normalizeView(state.views[state.activeTarget]);
    startX = ptX;
    startY = ptY;
    baseX = v.x;
    baseY = v.y;

    frame.classList.add('is-dragging');
    e.preventDefault();
  }

  function pointerMove(e) {
    if (!dragging) return;

    const ptX = (e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
    const ptY = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;

    const dx = ptX - startX;
    const dy = ptY - startY;

    const v = normalizeView(state.views[state.activeTarget]);
    v.x = baseX + dx;
    v.y = baseY + dy;
    state.views[state.activeTarget] = v;

    applyViewToFrame();
    e.preventDefault();
  }

  function pointerUp() {
    if (!dragging) return;
    dragging = false;
    if (frame) frame.classList.remove('is-dragging');
  }

  if (frame) {
    frame.addEventListener('mousedown', pointerDown);
    frame.addEventListener('touchstart', pointerDown, { passive: false });
  }
  document.addEventListener('mousemove', pointerMove);
  document.addEventListener('touchmove', pointerMove, { passive: false });
  document.addEventListener('mouseup', pointerUp);
  document.addEventListener('touchend', pointerUp);

  if (zoomEl) {
    zoomEl.addEventListener('input', () => {
      const v = normalizeView(state.views[state.activeTarget]);
      v.scale = Number(zoomEl.value);
      state.views[state.activeTarget] = v;
      applyViewToFrame();
    });
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      state.views[state.activeTarget] = { scale: 1, x: 0, y: 0 };
      applyViewToFrame();
    });
  }

  if (applyBtn) {
    applyBtn.addEventListener('click', async () => {
      const target = state.activeTarget;
      const view = normalizeView(state.views[target]);

      const btnText = applyBtn.textContent;
      applyBtn.disabled = true;
      applyBtn.textContent = 'Saving...';

      const { ok, payload } = await saveBranding({ target, view });
      if (ok && payload && payload.data && payload.data.view) {
        state.views[target] = normalizeView(payload.data.view);
      }
      applyBtn.disabled = false;
      applyBtn.textContent = btnText || 'Apply';

      if (ok) closeModal();
    });
  }

  function wireImageTarget({ target, fileInputId, previewId, uploadBtnId, adjustBtnId }) {
    const fileInput = document.getElementById(fileInputId);
    const previewImg = document.getElementById(previewId);
    const uploadBtn = document.getElementById(uploadBtnId);
    const adjustBtn = document.getElementById(adjustBtnId);

    if (adjustBtn) adjustBtn.addEventListener('click', () => openModal(target));

    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        if (!validateFile(file)) { fileInput.value = ''; return; }

        const reader = new FileReader();
        reader.onload = (ev) => {
          if (previewImg) previewImg.src = ev.target.result;
          openModal(target);
        };
        reader.readAsDataURL(file);
      });
    }

    if (uploadBtn) {
      uploadBtn.addEventListener('click', async () => {
        const file = fileInput && fileInput.files ? fileInput.files[0] : null;
        if (!file) return toast('Please select an image first.', 'error');
        if (!validateFile(file)) return;

        const old = uploadBtn.textContent;
        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Uploading...';

        const { ok, payload } = await saveBranding({
          target,
          file,
          view: normalizeView(state.views[target])
        });

        uploadBtn.disabled = false;
        uploadBtn.textContent = old || 'Upload';

        if (ok && payload && payload.data && payload.data.url) {
          state.urls[target] = payload.data.url;
          if (previewImg) previewImg.src = payload.data.url;
          fileInput.value = '';
        }
      });
    }
  }

  wireImageTarget({
    target: 'site_logo',
    fileInputId: 'knxBrandingFileInput',
    previewId: 'knxBrandingPreview',
    uploadBtnId: 'knxBrandingSaveBtn',
    adjustBtnId: 'knxBrandingAdjustBtn'
  });

  wireImageTarget({
    target: 'home_center',
    fileInputId: 'knxHomeCenterFileInput',
    previewId: 'knxHomeCenterPreview',
    uploadBtnId: 'knxHomeCenterSaveBtn',
    adjustBtnId: 'knxHomeCenterAdjustBtn'
  });

  // ==========================================================
  // Home headline save + counter
  // ==========================================================
  const headlineInput = document.getElementById('knxHomeHeadlineInput');
  const headlineSaveBtn = document.getElementById('knxHomeHeadlineSaveBtn');
  const headlineCount = document.getElementById('knxHomeHeadlineCount');
  const headlineMaxEl = document.getElementById('knxHomeHeadlineMax');
  const headlineMax = (cfg.home_headline && cfg.home_headline.max) ? parseInt(cfg.home_headline.max, 10) : 160;

  if (headlineMaxEl) headlineMaxEl.textContent = String(headlineMax);

  function updateHeadlineCount() {
    if (!headlineInput || !headlineCount) return;
    headlineCount.textContent = String((headlineInput.value || '').length);
  }
  updateHeadlineCount();
  if (headlineInput) headlineInput.addEventListener('input', updateHeadlineCount);

  if (headlineSaveBtn) {
    headlineSaveBtn.addEventListener('click', async () => {
      if (!headlineInput) return;
      let value = (headlineInput.value || '').trim();
      if (!value) return toast('Please enter a headline.', 'error');

      if (value.length > headlineMax) {
        value = value.slice(0, headlineMax);
        headlineInput.value = value;
        updateHeadlineCount();
      }

      const old = headlineSaveBtn.textContent;
      headlineSaveBtn.disabled = true;
      headlineSaveBtn.textContent = 'Saving...';

      await saveBranding({ target: 'home_copy', homeHeadline: value });

      headlineSaveBtn.disabled = false;
      headlineSaveBtn.textContent = old || 'Save text';
    });
  }

  // ==========================================================
  // City Grid Theme (GLOBAL SSOT DB) — preview SSOT
  // ==========================================================
  const previewWrap = document.getElementById('knxCityGridThemePreviewWrap');
  const citySaveBtn = document.getElementById('knxCityGridThemeSaveBtn');

  const inpGradFrom = document.getElementById('knxCityGridGradientFrom');
  const inpGradTo = document.getElementById('knxCityGridGradientTo');
  const inpAngle = document.getElementById('knxCityGridAngle');

  const inpTitleSize = document.getElementById('knxCityGridTitleFontSize');
  const inpTitleFill = document.getElementById('knxCityGridTitleFillColor');
  const inpTitleStrokeColor = document.getElementById('knxCityGridTitleStrokeColor');
  const inpTitleStrokeWidth = document.getElementById('knxCityGridTitleStrokeWidth');
  const inpTitleWeight = document.getElementById('knxCityGridTitleFontWeight');
  const inpTitleLineHeight = document.getElementById('knxCityGridTitleLineHeight');
  const inpTitleLetterSpacing = document.getElementById('knxCityGridTitleLetterSpacing');

  const inpCtaBg = document.getElementById('knxCityGridCtaBg');
  const inpCtaText = document.getElementById('knxCityGridCtaTextColor');
  const inpCtaRadius = document.getElementById('knxCityGridCtaRadius');
  const inpCtaBorderWidth = document.getElementById('knxCityGridCtaBorderWidth');
  const inpCtaDotted = document.getElementById('knxCityGridCtaBorderDotted');
  const inpCtaTwoLines = document.getElementById('knxCityGridCtaTwoLines');

  const inpCardRadius = document.getElementById('knxCityGridCardRadius');
  const inpCardPaddingY = document.getElementById('knxCityGridCardPaddingY');
  const inpCardPaddingX = document.getElementById('knxCityGridCardPaddingX');
  const inpCardMinHeight = document.getElementById('knxCityGridCardMinHeight');

  function setVar(el, k, v) {
    if (!el) return;
    el.style.setProperty(k, String(v));
  }

  function boolTo01(v) { return v ? '1' : '0'; }

  function applyThemeToPreview(theme) {
    if (!previewWrap) return;
    if (!theme) return;

    setVar(previewWrap, '--knx-city-grad-from', theme.gradient.from);
    setVar(previewWrap, '--knx-city-grad-to', theme.gradient.to);
    setVar(previewWrap, '--knx-city-grad-angle', theme.gradient.angle + 'deg');

    setVar(previewWrap, '--knx-city-card-radius', theme.card.radius + 'px');
    setVar(previewWrap, '--knx-city-card-min-height', theme.card.minHeight + 'px');
    setVar(previewWrap, '--knx-city-card-padding-y', theme.card.paddingY + 'px');
    setVar(previewWrap, '--knx-city-card-padding-x', theme.card.paddingX + 'px');
    setVar(previewWrap, '--knx-city-card-shadow', theme.card.shadow ? '1' : '0');

    setVar(previewWrap, '--knx-city-title-font-size', theme.title.fontSize + 'px');
    setVar(previewWrap, '--knx-city-title-fill', theme.title.fill);
    setVar(previewWrap, '--knx-city-title-font-weight', theme.title.fontWeight);
    setVar(previewWrap, '--knx-city-title-line-height', theme.title.lineHeight);
    setVar(previewWrap, '--knx-city-title-letter-spacing', theme.title.letterSpacing + 'px');
    setVar(previewWrap, '--knx-city-title-stroke-color', theme.title.strokeColor);
    setVar(previewWrap, '--knx-city-title-stroke-width', theme.title.strokeWidth + 'px');

    setVar(previewWrap, '--knx-city-cta-bg', theme.cta.bg);
    setVar(previewWrap, '--knx-city-cta-text', theme.cta.textColor);
    setVar(previewWrap, '--knx-city-cta-radius', theme.cta.radius + 'px');
    setVar(previewWrap, '--knx-city-cta-border-color', theme.cta.borderColor);
    setVar(previewWrap, '--knx-city-cta-border-width', theme.cta.borderWidth + 'px');
    setVar(previewWrap, '--knx-city-cta-padding-y', theme.cta.paddingY + 'px');
    setVar(previewWrap, '--knx-city-cta-padding-x', theme.cta.paddingX + 'px');
    setVar(previewWrap, '--knx-city-cta-font-size', theme.cta.fontSize + 'px');
    setVar(previewWrap, '--knx-city-cta-font-weight', theme.cta.fontWeight);
    setVar(previewWrap, '--knx-city-cta-dotted', boolTo01(theme.cta.borderDotted));
    setVar(previewWrap, '--knx-city-cta-two-lines', boolTo01(theme.cta.twoLines));

    const ctaEl = previewWrap.querySelector('.knx-city-banner-cta');
    if (ctaEl) {
      ctaEl.setAttribute('data-dotted', theme.cta.borderDotted ? '1' : '0');
      ctaEl.setAttribute('data-two-lines', theme.cta.twoLines ? '1' : '0');

      if (theme.cta.twoLines) {
        ctaEl.innerHTML = `<span class="knx-city-cta-line">Tap to</span><span class="knx-city-cta-line">EXPLORE HUBS</span>`;
      } else {
        ctaEl.textContent = theme.cta.text || 'Tap to EXPLORE HUBS';
      }
    }
  }

  function buildThemeFromInputs() {
    return {
      gradient: {
        from: inpGradFrom ? inpGradFrom.value : '#FF7A00',
        to: inpGradTo ? inpGradTo.value : '#FFB100',
        angle: inpAngle ? Number(inpAngle.value || 180) : 180,
      },
      card: {
        radius: inpCardRadius ? Number(inpCardRadius.value || 18) : 18,
        minHeight: inpCardMinHeight ? Number(inpCardMinHeight.value || 240) : 240,
        paddingY: inpCardPaddingY ? Number(inpCardPaddingY.value || 35) : 35,
        paddingX: inpCardPaddingX ? Number(inpCardPaddingX.value || 20) : 20,
        shadow: true
      },
      title: {
        fontFamily: 'system',
        fontWeight: inpTitleWeight ? Number(inpTitleWeight.value || 800) : 800,
        fontSize: (function(){
          const v = inpTitleSize ? Number(inpTitleSize.value || 20) : 20;
          return Math.max(12, Math.min(52, isNaN(v) ? 20 : v));
        })(),
        lineHeight: inpTitleLineHeight ? Number(inpTitleLineHeight.value || 1.00) : 1.00,
        letterSpacing: inpTitleLetterSpacing ? Number(inpTitleLetterSpacing.value || 1.00) : 1.00,
        fill: inpTitleFill ? inpTitleFill.value : '#FFFFFF',
        strokeColor: inpTitleStrokeColor ? inpTitleStrokeColor.value : '#083B58',
        strokeWidth: inpTitleStrokeWidth ? Number(inpTitleStrokeWidth.value || 0) : 0
      },
      cta: {
        text: 'Tap to EXPLORE HUBS',
        twoLines: !!(inpCtaTwoLines && inpCtaTwoLines.checked),
        bg: inpCtaBg ? inpCtaBg.value : '#083B58',
        textColor: inpCtaText ? inpCtaText.value : '#FFFFFF',
        radius: inpCtaRadius ? Number(inpCtaRadius.value || 999) : 999,
        borderDotted: !!(inpCtaDotted && inpCtaDotted.checked),
        borderColor: '#FFFFFF',
        borderWidth: inpCtaBorderWidth ? Number(inpCtaBorderWidth.value || 2) : 2,
        paddingY: 14,
        paddingX: 26,
        fontSize: 18,
        fontWeight: 800
      }
    };
  }

  function initThemeInputsFromCfg() {
    const t = themeCfg || {};
    try {
      if (t.gradient) {
        if (inpGradFrom && t.gradient.from) inpGradFrom.value = t.gradient.from;
        if (inpGradTo && t.gradient.to) inpGradTo.value = t.gradient.to;
        if (inpAngle && typeof t.gradient.angle !== 'undefined') inpAngle.value = t.gradient.angle;
      }
      if (t.title) {
        if (inpTitleSize && typeof t.title.fontSize !== 'undefined') inpTitleSize.value = t.title.fontSize;
        if (inpTitleFill && t.title.fill) inpTitleFill.value = t.title.fill;
        if (inpTitleStrokeColor && t.title.strokeColor) inpTitleStrokeColor.value = t.title.strokeColor;
        if (inpTitleStrokeWidth && typeof t.title.strokeWidth !== 'undefined') inpTitleStrokeWidth.value = t.title.strokeWidth;
        if (inpTitleWeight && typeof t.title.fontWeight !== 'undefined') inpTitleWeight.value = t.title.fontWeight;
        if (inpTitleLineHeight && typeof t.title.lineHeight !== 'undefined') inpTitleLineHeight.value = t.title.lineHeight;
        if (inpTitleLetterSpacing && typeof t.title.letterSpacing !== 'undefined') inpTitleLetterSpacing.value = t.title.letterSpacing;
      }
      if (t.cta) {
        if (inpCtaBg && t.cta.bg) inpCtaBg.value = t.cta.bg;
        if (inpCtaText && t.cta.textColor) inpCtaText.value = t.cta.textColor;
        if (inpCtaRadius && typeof t.cta.radius !== 'undefined') inpCtaRadius.value = t.cta.radius;
        if (inpCtaBorderWidth && typeof t.cta.borderWidth !== 'undefined') inpCtaBorderWidth.value = t.cta.borderWidth;
        if (inpCtaDotted && typeof t.cta.borderDotted !== 'undefined') inpCtaDotted.checked = !!t.cta.borderDotted;
        if (inpCtaTwoLines && typeof t.cta.twoLines !== 'undefined') inpCtaTwoLines.checked = !!t.cta.twoLines;
      }
      if (t.card) {
        if (inpCardRadius && typeof t.card.radius !== 'undefined') inpCardRadius.value = t.card.radius;
        if (inpCardPaddingY && typeof t.card.paddingY !== 'undefined') inpCardPaddingY.value = t.card.paddingY;
        if (inpCardPaddingX && typeof t.card.paddingX !== 'undefined') inpCardPaddingX.value = t.card.paddingX;
        if (inpCardMinHeight && typeof t.card.minHeight !== 'undefined') inpCardMinHeight.value = t.card.minHeight;
      }
    } catch (e) { /* ignore */ }
  }

  function refreshPreviewFromInputs() {
    const theme = buildThemeFromInputs();
    applyThemeToPreview(theme);
  }

  initThemeInputsFromCfg();
  refreshPreviewFromInputs();

  const watch = [
    inpGradFrom, inpGradTo, inpAngle,
    inpTitleSize, inpTitleFill, inpTitleStrokeColor, inpTitleStrokeWidth, inpTitleWeight, inpTitleLineHeight, inpTitleLetterSpacing,
    inpCtaBg, inpCtaText, inpCtaRadius, inpCtaBorderWidth, inpCtaDotted, inpCtaTwoLines,
    inpCardRadius, inpCardPaddingY, inpCardPaddingX, inpCardMinHeight
  ];

  watch.forEach(el => {
    if (!el) return;
    const evt = (el.type === 'checkbox' || el.tagName === 'SELECT') ? 'change' : 'input';
    el.addEventListener(evt, refreshPreviewFromInputs);
    if (evt !== 'input') el.addEventListener('input', refreshPreviewFromInputs);
  });

  if (citySaveBtn) {
    citySaveBtn.addEventListener('click', async () => {
      const theme = buildThemeFromInputs();
      const old = citySaveBtn.textContent;
      citySaveBtn.disabled = true;
      citySaveBtn.textContent = 'Saving...';

      const { ok, payload } = await saveBranding({
        target: 'city_grid_theme',
        themeJson: JSON.stringify(theme)
      });

      citySaveBtn.disabled = false;
      citySaveBtn.textContent = old || 'Save City Grid Theme';

      if (ok) {
        try { localStorage.setItem('knx_city_grid_theme', JSON.stringify(payload.data.theme || theme)); } catch (e) {}
      }
    });
  }
});