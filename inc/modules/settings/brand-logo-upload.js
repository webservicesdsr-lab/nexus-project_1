// File: inc/modules/settings/brand-logo-upload.js

/**
 * ==========================================================
 * Kingdom Nexus — Branding Settings JS (v3.4)
 * ----------------------------------------------------------
 * - Handles:
 *   - site_logo upload + view adjust
 *   - home_center upload + view adjust
 *   - home_copy headline save
 * - Endpoint:
 *   POST /wp-json/knx/v1/save-branding
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', () => {
  const cfg = window.knx_settings || {};
  const root = cfg.root || '';
  const wpNonce = cfg.wp_nonce || '';
  const placeholder = cfg.placeholder || '';
  const targetsCfg = cfg.targets || {};

  const toast = (msg, type) => {
    if (typeof window.knxToast === 'function') return window.knxToast(msg, type);
    console[type === 'error' ? 'error' : 'log']('[KNX-SETTINGS]', msg);
    alert(msg);
  };

  if (!root || !wpNonce) {
    console.warn('[KNX-SETTINGS] Missing root/wp_nonce config.');
  }

  // -----------------------------
  // Home headline editor
  // -----------------------------
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

      const ok = await saveBranding({ target: 'home_copy', homeHeadline: value });
      if (ok) toast('✅ Home headline saved', 'success');
    });
  }

  // -----------------------------
  // Image targets (upload + view)
  // -----------------------------
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

  const modal = document.getElementById('knxBrandingModal');
  const modalOverlay = document.getElementById('knxBrandingModalOverlay');
  const modalClose = document.getElementById('knxBrandingModalClose');

  const modalTitle = document.getElementById('knxBrandingModalTitle');
  const frame = document.getElementById('knxBrandingFrame');
  const frameImg = document.getElementById('knxBrandingFrameImg');
  const zoomEl = document.getElementById('knxBrandingZoom');
  const resetBtn = document.getElementById('knxBrandingReset');
  const applyBtn = document.getElementById('knxBrandingApply');

  const state = {
    activeTarget: 'site_logo',
    views: {
      site_logo: normalizeView(targetsCfg.site_logo && targetsCfg.site_logo.view),
      home_center: normalizeView(targetsCfg.home_center && targetsCfg.home_center.view)
    },
    urls: {
      site_logo: (targetsCfg.site_logo && targetsCfg.site_logo.url) ? targetsCfg.site_logo.url : '',
      home_center: (targetsCfg.home_center && targetsCfg.home_center.url) ? targetsCfg.home_center.url : ''
    },
    frames: {
      site_logo: (targetsCfg.site_logo && targetsCfg.site_logo.frame) ? targetsCfg.site_logo.frame : { w: 160, h: 45 },
      home_center: (targetsCfg.home_center && targetsCfg.home_center.frame) ? targetsCfg.home_center.frame : { w: 420, h: 150 }
    }
  };

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

  function applyViewToFrame() {
    if (!frame) return;
    const v = normalizeView(state.views[state.activeTarget] || {});
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

    if (modalTitle) {
      modalTitle.textContent = (target === 'home_center') ? 'Adjust Home Center Image' : 'Adjust Site Logo';
    }

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

    const v = normalizeView(state.views[state.activeTarget] || {});
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

    const v = normalizeView(state.views[state.activeTarget] || {});
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
      const v = normalizeView(state.views[state.activeTarget] || {});
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
      const ok = await saveBranding({
        target: state.activeTarget,
        view: state.views[state.activeTarget]
      });
      if (ok) closeModal();
    });
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

        const ok = await saveBranding({
          target,
          file,
          view: state.views[target]
        });

        if (ok) fileInput.value = '';
      });
    }
  }

  // -----------------------------
  // REST helper
  // -----------------------------
  async function saveBranding({ target, file, view, homeHeadline }) {
    if (!root || !wpNonce) {
      toast('❌ Missing settings config.', 'error');
      return false;
    }

    const formData = new FormData();
    formData.append('target', target);

    if (target === 'home_copy') {
      formData.append('home_headline', homeHeadline || '');
    } else {
      if (view) formData.append('view_json', JSON.stringify(normalizeView(view)));
      if (file) formData.append('file', file);
    }

    try {
      const res = await fetch(`${root}knx/v1/save-branding`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': wpNonce },
        body: formData
      });

      const payload = await res.json().catch(() => ({}));
      if (!payload || !payload.success) {
        toast((payload && payload.message) ? payload.message : '❌ Save failed.', 'error');
        return false;
      }

      const url = payload.data && payload.data.url ? payload.data.url : '';
      if (url && (target === 'site_logo' || target === 'home_center')) {
        state.urls[target] = url;
        if (target === 'site_logo') {
          const p = document.getElementById('knxBrandingPreview');
          if (p) p.src = url;
        } else {
          const p = document.getElementById('knxHomeCenterPreview');
          if (p) p.src = url;
        }
      }

      if (payload.data && payload.data.view && (target === 'site_logo' || target === 'home_center')) {
        state.views[target] = normalizeView(payload.data.view);
      }

      toast(payload.message || '✅ Saved successfully', 'success');
      return true;

    } catch (e) {
      console.error('[KNX-SETTINGS] saveBranding error:', e);
      toast('❌ Unexpected error while saving.', 'error');
      return false;
    }
  }
});