/* ============================================================
   KNX Menu Studio — Migration OCR
   OCR engine · precise image rect capture · responsive guided draw
   Group setup flow:
   1. Capture group name
   2. Choose action
   3. Choose required
   4. Choose type
   ============================================================ */
(function () {
  'use strict';

  const root = window.KNX_MC;
  if (!root || root.__ocrLoaded) return;
  root.__ocrLoaded = true;

  const dom = root.dom;
  const state = root.state;
  const toast = root.toast;
  const scheduleAutosave = root.scheduleAutosave;
  const $ = root.$;
  const $$ = root.$$;

  let selectionCtx = null;
  let drawing = false;
  let startX = 0;
  let startY = 0;
  let currentRect = null;

  function getPreviewDom() {
    return {
      wrap: document.getElementById('item-guided-preview'),
      name: document.getElementById('preview-item-name'),
      description: document.getElementById('preview-item-description'),
      price: document.getElementById('preview-item-price'),
      hint: document.getElementById('preview-item-hint'),
      stepName: document.getElementById('preview-step-name'),
      stepDescription: document.getElementById('preview-step-description'),
      stepPrice: document.getElementById('preview-step-price'),
      restartBtn: document.getElementById('btn-restart-item-capture'),
    };
  }

  function getGroupGuideUi() {
    let shell = document.getElementById('mc-group-guide-shell');
    if (!shell) {
      shell = document.createElement('div');
      shell.id = 'mc-group-guide-shell';
      shell.className = 'mc-group-guide-shell';
      shell.style.display = 'none';
      shell.innerHTML = `
        <div class="mc-group-guide-shell__eyebrow">Guided Group Setup</div>
        <div class="mc-group-guide-shell__title" id="mc-group-guide-title">Set up modifier group</div>
        <div class="mc-group-guide-shell__hint" id="mc-group-guide-hint">Capture the group title first.</div>
        <div class="mc-group-guide-shell__actions" id="mc-group-guide-actions"></div>
      `;

      if (dom.bubbleExpandedFooter) {
        dom.bubbleExpandedFooter.appendChild(shell);
      } else if (dom.bubbleExpanded) {
        dom.bubbleExpanded.appendChild(shell);
      }
    }

    return {
      shell,
      title: document.getElementById('mc-group-guide-title'),
      hint: document.getElementById('mc-group-guide-hint'),
      actions: document.getElementById('mc-group-guide-actions'),
    };
  }

  async function initOCR() {
    if (state.ocrWorker || !window.Tesseract) return;

    try {
      toast('Loading OCR engine…');

      state.ocrWorker = await Tesseract.createWorker('eng', 1, {
        logger: m => {
          if (m.status === 'recognizing text') {
            toast('Recognizing… ' + Math.round((m.progress || 0) * 100) + '%');
          }
        }
      });

      state.isOcrReady = true;
      toast('OCR ready.');
    } catch (err) {
      console.error('OCR init failed:', err);
      toast('OCR unavailable.');
    }
  }

  function getSelectionCtx() {
    if (!dom.ocrCanvas) return null;
    if (!selectionCtx) {
      selectionCtx = dom.ocrCanvas.getContext('2d');
    }
    return selectionCtx;
  }

  function clearSelection() {
    const canvas = dom.ocrCanvas;
    const ctx = getSelectionCtx();
    if (!canvas || !ctx) return;

    ctx.clearRect(0, 0, canvas.width, canvas.height);
    currentRect = null;
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function normalizeRect(rect) {
    if (!rect) return null;
    return {
      x: Math.round(rect.x),
      y: Math.round(rect.y),
      w: Math.round(rect.w),
      h: Math.round(rect.h),
    };
  }

  function drawSelection(rect) {
    const canvas = dom.ocrCanvas;
    const ctx = getSelectionCtx();
    if (!canvas || !ctx) return;

    clearSelection();

    if (!rect) return;

    const safe = normalizeRect(rect);

    ctx.save();

    ctx.strokeStyle = '#f97316';
    ctx.lineWidth = 2;
    ctx.setLineDash([8, 5]);
    ctx.fillStyle = 'rgba(249, 115, 22, 0.14)';
    ctx.beginPath();
    ctx.rect(safe.x, safe.y, safe.w, safe.h);
    ctx.fill();
    ctx.stroke();

    ctx.setLineDash([]);
    ctx.fillStyle = '#f97316';
    ctx.beginPath();
    ctx.arc(safe.x, safe.y, 5, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
  }

  function setHint(text, active) {
    if (!dom.ocrHint) return;
    dom.ocrHint.textContent = text;
    dom.ocrHint.classList.toggle('mc-bubble__ocr-hint--active', !!active);
  }

  function syncOcrCanvas() {
    const img = dom.bubbleExpandedImg;
    const stage = dom.bubbleStage;
    const canvas = dom.ocrCanvas;

    if (!img || !stage || !canvas) return;

    if (!img.naturalWidth) {
      img.onload = () => syncOcrCanvas();
      return;
    }

    requestAnimationFrame(() => {
      const imgRect = img.getBoundingClientRect();
      const stageRect = stage.getBoundingClientRect();

      if (!imgRect.width || !imgRect.height || !stageRect.width || !stageRect.height) {
        return;
      }

      const left = imgRect.left - stageRect.left;
      const top = imgRect.top - stageRect.top;

      canvas.width = Math.round(imgRect.width);
      canvas.height = Math.round(imgRect.height);

      canvas.style.left = left + 'px';
      canvas.style.top = top + 'px';
      canvas.style.width = imgRect.width + 'px';
      canvas.style.height = imgRect.height + 'px';

      state.imageDisplayRect = {
        left,
        top,
        width: imgRect.width,
        height: imgRect.height,
        naturalWidth: img.naturalWidth,
        naturalHeight: img.naturalHeight,
      };

      clearSelection();
    });
  }

  function updateItemPreview() {
    const preview = getPreviewDom();
    if (!preview.wrap) return;

    const name = ((dom.builderItemName && dom.builderItemName.value) || '').trim();
    const description = ((dom.builderDesc && dom.builderDesc.value) || '').trim();
    const price = ((dom.builderBasePrice && dom.builderBasePrice.value) || '').trim();

    if (preview.name) preview.name.textContent = name || 'Item name';
    if (preview.description) preview.description.textContent = description || 'Item description';
    if (preview.price) preview.price.textContent = price || '0.00';

    [preview.stepName, preview.stepDescription, preview.stepPrice].forEach(el => {
      if (el) el.classList.remove('is-active', 'is-done');
    });

    if (state.guidedMode === 'item') {
      preview.wrap.style.display = '';

      if (state.guidedStep === 'item-name') {
        if (preview.stepName) preview.stepName.classList.add('is-active');
        if (preview.hint) preview.hint.textContent = 'Select the item name from the image.';
      }

      if (state.guidedStep === 'item-description') {
        if (preview.stepName) preview.stepName.classList.add('is-done');
        if (preview.stepDescription) preview.stepDescription.classList.add('is-active');
        if (preview.hint) preview.hint.textContent = 'Select the description from the image.';
      }

      if (state.guidedStep === 'item-price') {
        if (preview.stepName) preview.stepName.classList.add('is-done');
        if (preview.stepDescription) preview.stepDescription.classList.add('is-done');
        if (preview.stepPrice) preview.stepPrice.classList.add('is-active');
        if (preview.hint) preview.hint.textContent = 'Select the base price from the image.';
      }
    } else {
      preview.wrap.style.display = 'none';
    }
  }

  function restartItemCapture() {
    if (!state.currentImageUrl) {
      toast('Upload an image first.');
      return;
    }

    if (dom.builderItemName) {
      dom.builderItemName.value = '';
      dom.builderItemName.dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (dom.builderDesc) {
      dom.builderDesc.value = '';
      dom.builderDesc.dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (dom.builderBasePrice) {
      dom.builderBasePrice.value = '';
      dom.builderBasePrice.dispatchEvent(new Event('input', { bubbles: true }));
    }

    startGuidedItemCapture();
    updateItemPreview();
    scheduleAutosave();
    toast('Item capture restarted.');
  }

  function getTapAssistProfile() {
    const fallback = { w: 140, h: 44 };

    if (!state.imageDisplayRect) return fallback;

    const isTabletLike = window.innerWidth <= 1200;
    const isSmallTablet = window.innerWidth <= 900;
    const imgW = state.imageDisplayRect.width;
    const imgH = state.imageDisplayRect.height;

    // Larger selection areas for small tablets like Lenovo M7
    const tabletMultiplier = isSmallTablet ? 1.25 : (isTabletLike ? 1.15 : 1);

    if (state.guidedStep === 'item-name') {
      return {
        w: clamp(Math.round(imgW * (isTabletLike ? 0.65 : 0.52) * tabletMultiplier), 180, 580),
        h: clamp(Math.round(imgH * 0.12 * tabletMultiplier), 48, 100),
      };
    }

    if (state.guidedStep === 'item-description') {
      return {
        w: clamp(Math.round(imgW * (isTabletLike ? 0.75 : 0.58) * tabletMultiplier), 240, 700),
        h: clamp(Math.round(imgH * 0.18 * tabletMultiplier), 70, 180),
      };
    }

    if (state.guidedStep === 'item-price') {
      return {
        w: clamp(Math.round(imgW * 0.26 * tabletMultiplier), 120, 260),
        h: clamp(Math.round(imgH * 0.13 * tabletMultiplier), 46, 100),
      };
    }

    if (state.pickTarget === 'draft-group-name') {
      return {
        w: clamp(Math.round(imgW * 0.50 * tabletMultiplier), 160, 480),
        h: clamp(Math.round(imgH * 0.12 * tabletMultiplier), 46, 96),
      };
    }

    if (String(state.pickTarget || '').includes('price')) {
      return {
        w: clamp(Math.round(imgW * 0.20 * tabletMultiplier), 100, 180),
        h: clamp(Math.round(imgH * 0.10 * tabletMultiplier), 40, 80),
      };
    }

    return {
      w: clamp(Math.round(imgW * 0.32 * tabletMultiplier), 140, 320),
      h: clamp(Math.round(imgH * 0.10 * tabletMultiplier), 44, 90),
    };
  }

  function buildSmartTapRect(x, y) {
    const canvas = dom.ocrCanvas;
    if (!canvas) return null;

    const profile = getTapAssistProfile();
    const rect = {
      x: x - (profile.w / 2),
      y: y - (profile.h / 2),
      w: profile.w,
      h: profile.h,
    };

    rect.x = clamp(rect.x, 0, Math.max(0, canvas.width - rect.w));
    rect.y = clamp(rect.y, 0, Math.max(0, canvas.height - rect.h));

    return normalizeRect(rect);
  }

  function shouldUseSmartTap(rect) {
    if (!rect) return false;
    const area = rect.w * rect.h;
    return rect.w < 18 || rect.h < 18 || area < 900;
  }

  function rectCanvasToNatural(rect) {
    if (!rect || !state.imageDisplayRect) return null;

    const scaleX = state.imageDisplayRect.naturalWidth / state.imageDisplayRect.width;
    const scaleY = state.imageDisplayRect.naturalHeight / state.imageDisplayRect.height;

    return {
      left: rect.x * scaleX,
      top: rect.y * scaleY,
      width: rect.w * scaleX,
      height: rect.h * scaleY,
    };
  }

  function isTouchDevice() {
    return (
      ('ontouchstart' in window) ||
      (navigator.maxTouchPoints > 0) ||
      (window.matchMedia && window.matchMedia('(pointer: coarse)').matches)
    );
  }

  function createTouchIndicator() {
    if (document.getElementById('mc-touch-indicator')) return;

    const indicator = document.createElement('div');
    indicator.id = 'mc-touch-indicator';
    indicator.className = 'mc-bubble__touch-indicator';

    const stage = dom.bubbleStage;
    if (stage) {
      stage.appendChild(indicator);
    }
  }

  function showTouchIndicator(x, y, expand) {
    const indicator = document.getElementById('mc-touch-indicator');
    if (!indicator) return;

    indicator.style.left = x + 'px';
    indicator.style.top = y + 'px';
    indicator.classList.remove('is-expanding');
    indicator.classList.add('is-visible');

    if (expand) {
      requestAnimationFrame(() => {
        indicator.classList.add('is-expanding');
      });

      setTimeout(() => {
        indicator.classList.remove('is-visible', 'is-expanding');
      }, 400);
    }
  }

  function hideTouchIndicator() {
    const indicator = document.getElementById('mc-touch-indicator');
    if (indicator) {
      indicator.classList.remove('is-visible', 'is-expanding');
    }
  }

  function initOcrSelection() {
    if (!dom.ocrCanvas) return;

    const canvas = dom.ocrCanvas;
    const isTouch = isTouchDevice();

    // Create touch indicator for tablet feedback
    if (isTouch) {
      createTouchIndicator();
    }

    let pointerStartTime = 0;
    let hasMoved = false;

    canvas.addEventListener('pointerdown', e => {
      drawing = true;
      hasMoved = false;
      pointerStartTime = Date.now();

      const rect = canvas.getBoundingClientRect();
      startX = clamp(e.clientX - rect.left, 0, canvas.width);
      startY = clamp(e.clientY - rect.top, 0, canvas.height);

      currentRect = { x: startX, y: startY, w: 0, h: 0 };
      drawSelection(currentRect);

      // Visual feedback for touch
      if (isTouch || e.pointerType === 'touch') {
        canvas.classList.add('is-drawing');
        showTouchIndicator(startX, startY, false);
      }

      canvas.setPointerCapture(e.pointerId);
      e.preventDefault();
    });

    canvas.addEventListener('pointermove', e => {
      if (!drawing) return;

      const rect = canvas.getBoundingClientRect();
      const cx = clamp(e.clientX - rect.left, 0, canvas.width);
      const cy = clamp(e.clientY - rect.top, 0, canvas.height);

      // Detect if user is dragging vs tapping
      const dx = Math.abs(cx - startX);
      const dy = Math.abs(cy - startY);
      if (dx > 10 || dy > 10) {
        hasMoved = true;
        hideTouchIndicator();
      }

      currentRect = normalizeRect({
        x: Math.min(startX, cx),
        y: Math.min(startY, cy),
        w: Math.abs(cx - startX),
        h: Math.abs(cy - startY),
      });

      drawSelection(currentRect);
    });

    canvas.addEventListener('pointerup', async e => {
      if (!drawing) return;
      drawing = false;

      canvas.classList.remove('is-drawing');

      const rect = canvas.getBoundingClientRect();
      const cx = clamp(e.clientX - rect.left, 0, canvas.width);
      const cy = clamp(e.clientY - rect.top, 0, canvas.height);

      const pointerDuration = Date.now() - pointerStartTime;
      const isTapGesture = !hasMoved && pointerDuration < 400;

      currentRect = normalizeRect({
        x: Math.min(startX, cx),
        y: Math.min(startY, cy),
        w: Math.abs(cx - startX),
        h: Math.abs(cy - startY),
      });

      // Smart tap: if it was a tap or small selection, use assisted rectangle
      if (isTapGesture || shouldUseSmartTap(currentRect)) {
        currentRect = buildSmartTapRect(cx, cy);
        drawSelection(currentRect);

        // Show expanding indicator for tap feedback
        if (isTouch || e.pointerType === 'touch') {
          showTouchIndicator(cx, cy, true);
        }
      } else {
        hideTouchIndicator();
      }

      if (!currentRect || currentRect.w < 8 || currentRect.h < 8) {
        clearSelection();
        hideTouchIndicator();
        return;
      }

      const naturalRect = rectCanvasToNatural(currentRect);

      if (!naturalRect) {
        clearSelection();
        toast('Image area not ready.');
        return;
      }

      setTimeout(() => clearSelection(), 80);
      await performOCR(naturalRect);
    });

    // Handle pointer cancel (finger lifted off screen edge, etc.)
    canvas.addEventListener('pointercancel', () => {
      drawing = false;
      hasMoved = false;
      canvas.classList.remove('is-drawing');
      hideTouchIndicator();
      clearSelection();
    });
  }

  function showGuideUi(label) {
    if (dom.guideActions) dom.guideActions.style.display = '';
    if (dom.guideStep) {
      dom.guideStep.style.display = '';
      dom.guideStep.textContent = label || '';
    }
  }

  function hideGuideUi() {
    if (dom.guideActions) dom.guideActions.style.display = 'none';
    if (dom.guideStep) {
      dom.guideStep.style.display = 'none';
      dom.guideStep.textContent = '';
    }
  }

  function showGroupGuideShell(title, hint, buttonsHtml) {
    const ui = getGroupGuideUi();
    if (!ui.shell) return;

    ui.shell.style.display = '';
    if (ui.title) ui.title.textContent = title || 'Set up modifier group';
    if (ui.hint) ui.hint.textContent = hint || '';
    if (ui.actions) ui.actions.innerHTML = buttonsHtml || '';
  }

  function hideGroupGuideShell() {
    const ui = getGroupGuideUi();
    if (!ui.shell) return;
    ui.shell.style.display = 'none';
    if (ui.actions) ui.actions.innerHTML = '';
  }

  function renderGroupActionStep() {
    showGroupGuideShell(
      'Step 2 — Choose action',
      'Decide if this group adds something or removes something.',
      `
        <button type="button" class="mc-group-guide-btn" data-group-guide-action="add">Add</button>
        <button type="button" class="mc-group-guide-btn mc-group-guide-btn--danger" data-group-guide-action="remove">Remove</button>
      `
    );
  }

  function renderGroupRequiredStep() {
    showGroupGuideShell(
      'Step 3 — Required or optional',
      'Decide if the customer must select from this group.',
      `
        <button type="button" class="mc-group-guide-btn" data-group-guide-required="1">Required</button>
        <button type="button" class="mc-group-guide-btn" data-group-guide-required="0">Optional</button>
      `
    );
  }

  function renderGroupTypeStep() {
    showGroupGuideShell(
      'Step 4 — Single or multi',
      'Decide how many choices the customer can make.',
      `
        <button type="button" class="mc-group-guide-btn" data-group-guide-type="single">Single</button>
        <button type="button" class="mc-group-guide-btn" data-group-guide-type="multiple">Multi</button>
      `
    );
  }

  function finishGuidedCapture() {
    state.guidedMode = null;
    state.guidedStep = null;
    hideGuideUi();
    hideGroupGuideShell();
    clearPickMode();
    deactivateBlockCapture(false);
    updateItemPreview();
  }

  function cancelGuidedCapture(minimizeBubble) {
    state.guidedMode = null;
    state.guidedStep = null;
    hideGuideUi();
    hideGroupGuideShell();

    clearPickMode();
    deactivateBlockCapture(false);
    updateItemPreview();

    if (minimizeBubble && typeof root.showBubbleMini === 'function') {
      root.showBubbleMini();
    }
  }

  function startGuidedItemCapture() {
    if (!state.currentImageUrl) return;

    const hasName = ((dom.builderItemName && dom.builderItemName.value) || '').trim().length > 0;
    const hasDesc = ((dom.builderDesc && dom.builderDesc.value) || '').trim().length > 0;
    const hasPrice = ((dom.builderBasePrice && dom.builderBasePrice.value) || '').trim().length > 0;

    state.guidedMode = 'item';

    if (!hasName) {
      state.guidedStep = 'item-name';
      showGuideUi('Step 1 of 3 — Select item name');
      hideGroupGuideShell();
      activatePickMode('builder-item-name');
      updateItemPreview();
      return;
    }

    if (!hasDesc) {
      state.guidedStep = 'item-description';
      showGuideUi('Step 2 of 3 — Select description');
      hideGroupGuideShell();
      activatePickMode('builder-description');
      updateItemPreview();
      return;
    }

    if (!hasPrice) {
      state.guidedStep = 'item-price';
      showGuideUi('Step 3 of 3 — Select base price');
      hideGroupGuideShell();
      activatePickMode('builder-base-price');
      updateItemPreview();
      return;
    }

    finishGuidedCapture();
  }

  function startGuidedGroupSetupCapture() {
    if (!state.currentImageUrl || !state.groupDraft) return;

    state.guidedMode = 'group';
    state.guidedStep = 'group-name';

    showGuideUi('Step 1 of 4 — Select group title');
    hideGroupGuideShell();
    activatePickMode('draft-group-name');
    updateItemPreview();
  }

  function startGuidedGroupCapture() {
    startGuidedGroupSetupCapture();
  }

  function applyGroupAction(value) {
    if (!state.groupDraft) return;

    state.groupDraft.action = value === 'remove' ? 'remove' : 'add';

    if (typeof root.resetChipGroup === 'function') {
      root.resetChipGroup('action', state.groupDraft.action);
    }

    if (state.groupDraft.action === 'remove') {
      state.groupDraft.options.forEach(opt => {
        opt.action = 'remove';
        opt.price = '0.00';
      });

      if (dom.draftOptionPrice) dom.draftOptionPrice.value = '0.00';
    } else {
      state.groupDraft.options.forEach(opt => {
        opt.action = 'add';
      });
    }

    if (typeof root.syncDraftHeroMeta === 'function') root.syncDraftHeroMeta();
    if (typeof root.syncDraftSummary === 'function') root.syncDraftSummary();
    if (typeof root.renderDraftOptionsGrid === 'function') root.renderDraftOptionsGrid();

    state.guidedStep = 'group-required';
    renderGroupRequiredStep();
    scheduleAutosave();
  }

  function applyGroupRequired(value) {
    if (!state.groupDraft) return;

    state.groupDraft.required = value === '1' ? '1' : '0';

    if (typeof root.resetChipGroup === 'function') {
      root.resetChipGroup('required', state.groupDraft.required);
    }

    if (typeof root.syncDraftHeroMeta === 'function') root.syncDraftHeroMeta();
    if (typeof root.syncDraftSummary === 'function') root.syncDraftSummary();

    state.guidedStep = 'group-type';
    renderGroupTypeStep();
    scheduleAutosave();
  }

  function applyGroupType(value) {
    if (!state.groupDraft) return;

    state.groupDraft.type = value === 'single' ? 'single' : 'multiple';

    if (typeof root.resetChipGroup === 'function') {
      root.resetChipGroup('type', state.groupDraft.type);
    }

    if (typeof root.syncDraftHeroMeta === 'function') root.syncDraftHeroMeta();
    if (typeof root.syncDraftSummary === 'function') root.syncDraftSummary();

    finishGuidedCapture();

    if (typeof root.showBubbleMini === 'function') {
      root.showBubbleMini();
    }

    if (dom.draftOptionName) {
      dom.draftOptionName.focus();
    }

    toast('Group setup ready. Add options below.');
    scheduleAutosave();
  }

  function advanceGuidedItemStep() {
    if (state.guidedMode !== 'item') return;

    // Trigger step transition animation
    if (typeof root.animateStepTransition === 'function') {
      root.animateStepTransition('forward');
    }

    if (state.guidedStep === 'item-name') {
      state.guidedStep = 'item-description';
      showGuideUi('Step 2 of 3 — Select description');
      activatePickMode('builder-description');
      updateItemPreview();
      return;
    }

    if (state.guidedStep === 'item-description') {
      state.guidedStep = 'item-price';
      showGuideUi('Step 3 of 3 — Select base price');
      activatePickMode('builder-base-price');
      updateItemPreview();
      return;
    }

    if (state.guidedStep === 'item-price') {
      finishGuidedCapture();
      if (typeof root.showBubbleMini === 'function') {
        root.showBubbleMini();
      }
    }
  }

  function advanceGuidedGroupStep() {
    if (state.guidedMode !== 'group') return;

    if (state.guidedStep === 'group-name') {
      state.guidedStep = 'group-action';
      hideGuideUi();
      clearPickMode();
      renderGroupActionStep();
      return;
    }
  }

  function skipGuidedStep() {
    if (state.guidedMode === 'item') {
      if (state.guidedStep === 'item-name') {
        advanceGuidedItemStep();
        return;
      }

      if (state.guidedStep === 'item-description') {
        advanceGuidedItemStep();
        return;
      }

      if (state.guidedStep === 'item-price') {
        finishGuidedCapture();
        if (typeof root.showBubbleMini === 'function') {
          root.showBubbleMini();
        }
        return;
      }
    }

    if (state.guidedMode === 'group') {
      if (state.guidedStep === 'group-name') {
        advanceGuidedGroupStep();
        return;
      }

      if (state.guidedStep === 'group-action') {
        applyGroupAction('add');
        return;
      }

      if (state.guidedStep === 'group-required') {
        applyGroupRequired('0');
        return;
      }

      if (state.guidedStep === 'group-type') {
        applyGroupType('multiple');
      }
    }
  }

  async function performOCR(rectangle) {
    if (!state.isOcrReady || !state.ocrWorker) {
      toast('OCR not ready.');
      return;
    }

    try {
      toast('Recognizing…');

      const result = await state.ocrWorker.recognize(dom.bubbleExpandedImg.src, { rectangle });
      const rawText = (result?.data?.text || '').trim();

      if (!rawText) {
        toast('No text found.');
        return;
      }

      if (state.blockCaptureMode) {
        const wasGroupCapture = state.blockCaptureForGroup;
        deactivateBlockCapture(false);

        if (wasGroupCapture) {
          const parsed = parseGroupBlock(rawText);
          fillGroupDraftFromOCR(parsed, rawText);

          if (state.guidedMode === 'group' && state.guidedStep === 'group-block') {
            finishGuidedCapture();
          }
        } else {
          showOcrPanel(rawText);
          toast('Block captured.');
        }

        return;
      }

      const cleaned = normalizeInlineText(rawText);

      if (state.pickTarget) {
        const activeTarget = state.pickTarget;

        if (activeTarget.includes(':') && activeTarget.startsWith('draft-option-')) {
          const [target, indexStr] = activeTarget.split(':');
          const index = parseInt(indexStr, 10);

          if (!Number.isNaN(index) && state.groupDraft && state.groupDraft.options[index]) {
            const field = target.endsWith('name') ? 'name' : 'price';

            if (field === 'name') {
              state.groupDraft.options[index].name = cleaned.replace(/\|/g, '').replace(/\s{2,}/g, ' ').trim();
            } else {
              state.groupDraft.options[index].price = extractFirstMoney(cleaned, true);
            }

            if (typeof root.renderDraftOptionsGrid === 'function') {
              root.renderDraftOptionsGrid();
            }

            scheduleAutosave();
            toast('Option updated.');
          }
        } else {
          const input = $('#' + activeTarget);

          if (input) {
            if (activeTarget === 'builder-description') {
              input.value = cleaned;
            } else if (activeTarget.includes('price')) {
              input.value = extractFirstMoney(cleaned, false);
            } else {
              input.value = cleaned;
            }

            input.dispatchEvent(new Event('input', { bubbles: true }));

            // On tablet app mode, avoid raising the on-screen keyboard after OCR fill
            if (root.appState && root.appState.isAppMode) {
              input.blur();
            } else {
              input.focus();
            }

            // Trigger field capture animation in app mode
            if (typeof root.animateFieldCapture === 'function') {
              let previewEl = null;
              if (activeTarget === 'builder-item-name') {
                previewEl = document.getElementById('preview-item-name');
              } else if (activeTarget === 'builder-description') {
                previewEl = document.getElementById('preview-item-description');
              } else if (activeTarget === 'builder-base-price') {
                previewEl = document.getElementById('preview-item-price');
              }
              root.animateFieldCapture(input, previewEl);
            }

            toast('Field filled.');
          }
        }

        clearPickMode();
        updateItemPreview();

        if (state.guidedMode === 'item') {
          if (
            (state.guidedStep === 'item-name' && activeTarget === 'builder-item-name') ||
            (state.guidedStep === 'item-description' && activeTarget === 'builder-description') ||
            (state.guidedStep === 'item-price' && activeTarget === 'builder-base-price')
          ) {
            advanceGuidedItemStep();
            scheduleAutosave();
            return;
          }
        }

        if (state.guidedMode === 'group') {
          if (state.guidedStep === 'group-name' && activeTarget === 'draft-group-name') {
            advanceGuidedGroupStep();
            scheduleAutosave();
            return;
          }
        }

        if (typeof root.showBubbleMini === 'function') {
          root.showBubbleMini();
        }

        scheduleAutosave();
        return;
      }

      fillNextField(cleaned);
      updateItemPreview();
    } catch (err) {
      console.error('OCR error:', err);
      toast('OCR failed.');
    }
  }

  function normalizeInlineText(text) {
    return String(text || '')
      .replace(/\s*\n+\s*/g, ' ')
      .replace(/\s{2,}/g, ' ')
      .trim();
  }

  function extractFirstMoney(text, forceZero) {
    const cleaned = String(text || '')
      .replace(/S(?=\d)/g, '$')
      .replace(/O\.OO/gi, '0.00')
      .replace(/O(?=\.\d{2})/g, '0')
      .replace(/,/g, '');

    const m = cleaned.match(/([+-]?)\s*\$?\s*(\d+(?:\.\d{2})?)/);
    if (!m) return forceZero ? '0.00' : '';

    const value = parseFloat(m[2] || '0');
    if (Number.isNaN(value)) return forceZero ? '0.00' : '';

    return value.toFixed(2);
  }

  function fillNextField(text) {
    if (dom.builderItemName && dom.builderItemName.value.trim() === '') {
      dom.builderItemName.value = text;
      dom.builderItemName.focus();
      dom.builderItemName.dispatchEvent(new Event('input', { bubbles: true }));
      toast('Item name filled.');
      updateItemPreview();
      return;
    }

    if (dom.builderDesc && dom.builderDesc.value.trim() === '') {
      dom.builderDesc.value = text;
      dom.builderDesc.focus();
      dom.builderDesc.dispatchEvent(new Event('input', { bubbles: true }));
      toast('Description filled.');
      updateItemPreview();
      return;
    }

    if (dom.builderBasePrice && dom.builderBasePrice.value.trim() === '') {
      dom.builderBasePrice.value = extractFirstMoney(text, false);
      dom.builderBasePrice.focus();
      dom.builderBasePrice.dispatchEvent(new Event('input', { bubbles: true }));
      toast('Base price filled.');
      updateItemPreview();
      return;
    }

    if (state.groupDraft) {
      if (dom.draftGroupName && dom.draftGroupName.value.trim() === '') {
        dom.draftGroupName.value = text;
        dom.draftGroupName.dispatchEvent(new Event('input', { bubbles: true }));
        toast('Group name filled.');
        return;
      }

      if (dom.draftOptionName && dom.draftOptionName.value.trim() === '') {
        dom.draftOptionName.value = text;
        toast('Option name filled.');
        return;
      }

      if (dom.draftOptionPrice && dom.draftOptionPrice.value.trim() === '') {
        dom.draftOptionPrice.value = extractFirstMoney(text, true);
        toast('Option price filled.');
      }
    }
  }

  function activatePickMode(targetId) {
    if (!state.currentImageUrl) {
      toast('Upload an image first.');
      return;
    }

    state.pickTarget = targetId;

    $$('.mc-pick-btn').forEach(b => b.classList.remove('mc-pick-btn--active'));

    const btn = $('[data-pick-target="' + targetId + '"]');
    if (btn) btn.classList.add('mc-pick-btn--active');

    let hintText = 'Draw over the text to fill this field';

    if (targetId.includes(':') && targetId.startsWith('draft-option-')) {
      const [target, indexStr] = targetId.split(':');
      const field = target.endsWith('name') ? 'name' : 'price';
      const n = parseInt(indexStr, 10);
      hintText = 'Draw over the ' + field + ' for option ' + (n + 1) + ' or tap roughly over it';
    } else if (targetId === 'builder-item-name') {
      hintText = 'Draw over the item name or tap roughly on the title';
    } else if (targetId === 'builder-description') {
      hintText = 'Draw over the description or tap roughly on the description block';
    } else if (targetId === 'builder-base-price') {
      hintText = 'Draw over the base price or tap roughly on the price';
    } else if (targetId === 'draft-group-name') {
      hintText = 'Draw over the group title or tap roughly on the header';
    } else if (targetId === 'draft-option-name') {
      hintText = 'Draw over the option name';
    } else if (targetId === 'draft-option-price') {
      hintText = 'Draw over the option price';
    }

    setHint(hintText, true);

    if (typeof root.showBubbleExpanded === 'function') {
      root.showBubbleExpanded();
    }
  }

  function clearPickMode() {
    state.pickTarget = null;
    $$('.mc-pick-btn').forEach(b => b.classList.remove('mc-pick-btn--active'));
    setHint('Draw over the text on the active image to extract it', false);
  }

  function activateBlockCapture() {
    if (!state.currentImageUrl) {
      toast('Upload an image first.');
      return;
    }

    clearPickMode();
    state.blockCaptureMode = true;
    state.blockCaptureForGroup = false;

    setHint('Draw over a full block to capture OCR text', true);

    if (typeof root.showBubbleExpanded === 'function') {
      root.showBubbleExpanded();
    }
  }

  function activateBlockCaptureForGroup() {
    clearPickMode();
    state.blockCaptureMode = true;
    state.blockCaptureForGroup = true;

    setHint('Draw over the full modifier group block', true);

    if (typeof root.showBubbleExpanded === 'function') {
      root.showBubbleExpanded();
    }

    toast('Capture the full group block.');
  }

  function deactivateBlockCapture(resetHint) {
    state.blockCaptureMode = false;
    state.blockCaptureForGroup = false;

    if (resetHint !== false) {
      setHint('Draw over the text on the active image to extract it', false);
    }
  }

  function parseGroupBlock(raw) {
    const lines = normalizeLines(raw);
    const compact = lines.join(' ');

    const mode = detectGroupMode(lines, compact);
    const header = parseGroupHeader(lines);
    const options = parseOptions(lines, mode);

    const groupName = header.name || inferGroupNameFromFirstStrongLine(lines);
    const required = header.required;
    const type = header.type;
    const action = header.action || mode.action;

    return {
      groupName: groupName || '',
      required,
      type,
      action,
      options
    };
  }

  function normalizeLines(text) {
    return String(text || '')
      .replace(/\r/g, '\n')
      .split('\n')
      .map(line => cleanOcrLine(line))
      .filter(Boolean);
  }

  function cleanOcrLine(line) {
    let s = String(line || '').trim();
    if (!s) return '';

    s = s
      .replace(/[|•·]/g, ' ')
      .replace(/S(?=\d)/g, '$')
      .replace(/O\.OO/gi, '0.00')
      .replace(/O(?=\.\d{2})/g, '0')
      .replace(/\+\s+\$/g, '+$')
      .replace(/\$\s+(\d)/g, '$$1')
      .replace(/\s{2,}/g, ' ')
      .trim();

    if (!s) return '';

    if (/^(close|done|cancel|remove|save|quantity|qty|menu|back)$/i.test(s)) return '';
    return s;
  }

  function detectGroupMode(lines, compact) {
    const joined = compact.toLowerCase();
    let action = 'add';
    let priceStyle = 'cta';

    if (/tap to remove|remove not add|without|no\s+[a-z]/i.test(joined)) {
      action = 'remove';
    }

    if (!/\$/.test(joined) && /select up to|required|optional/i.test(joined)) {
      priceStyle = 'none';
    }

    if (/^\s*[○◯o0]\s+/m.test(compact) || /^\s*[\(\[]?\s*[○◯o0]\s*[\)\]]?\s+/m.test(compact)) {
      priceStyle = 'none';
    }

    return { action, priceStyle };
  }

  function parseGroupHeader(lines) {
    let name = '';
    let required = '0';
    let type = 'multiple';
    let action = 'add';

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      const lower = line.toLowerCase();

      if (/tap to remove|remove not add/i.test(lower)) {
        action = 'remove';
      }

      if (/^\d+\s+required$/i.test(line) || /\brequired\b/i.test(lower)) {
        required = '1';
        type = 'single';
        continue;
      }

      if (/select up to\s+\d+/i.test(lower)) {
        required = '0';
        type = 'multiple';
        continue;
      }

      if (/^optional$/i.test(lower)) {
        required = '0';
        continue;
      }

      if (!name && looksLikeGroupTitle(line, i, lines)) {
        name = stripRuleFragments(line);
      }
    }

    return {
      name,
      required,
      type,
      action
    };
  }

  function looksLikeGroupTitle(line, index, lines) {
    const lower = line.toLowerCase();

    if (!line || line.length < 2) return false;
    if (/tap to (add|select|remove)/i.test(lower)) return false;
    if (/^\$?\d+(\.\d{2})?$/.test(line)) return false;
    if (/^\d+\s+required$/i.test(line)) return false;
    if (/^optional$/i.test(line)) return false;
    if (/^select up to\s+\d+/i.test(line)) return false;
    if (/^(small|large|medium|yes|no)$/i.test(line)) return false;

    if (
      /choose your|choice|extras|sauces|toppings|sides|size|additions|premium additions|add cheese|meat temp|dry topping|hot topping/i.test(lower)
    ) {
      return true;
    }

    const next = String(lines[index + 1] || '').toLowerCase();
    if (/^\d+\s+required$/.test(next) || /^optional$/.test(next) || /^select up to\s+\d+/.test(next)) {
      return true;
    }

    return index === 0;
  }

  function stripRuleFragments(line) {
    return String(line || '')
      .replace(/\b\d+\s+required\b/ig, '')
      .replace(/\boptional\b/ig, '')
      .replace(/\bselect up to\s+\d+\s+options?\b/ig, '')
      .replace(/\s{2,}/g, ' ')
      .trim();
  }

  function inferGroupNameFromFirstStrongLine(lines) {
    for (let i = 0; i < lines.length; i++) {
      const line = stripRuleFragments(lines[i]);
      if (looksLikeGroupTitle(line, i, lines)) return line;
    }
    return '';
  }

  function parseOptions(lines, mode) {
    const options = [];
    const seen = new Set();

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];

      if (!line) continue;
      if (looksLikeRuleLine(line)) continue;
      if (looksLikeGroupTitle(line, i, lines)) continue;

      const ctaMatch = parseCtaOptionLine(line, mode.action);
      if (ctaMatch) {
        const key = ctaMatch.name.toLowerCase() + '|' + ctaMatch.price + '|' + ctaMatch.action;
        if (!seen.has(key)) {
          seen.add(key);
          options.push(ctaMatch);
        }
        continue;
      }

      const bulletMatch = parseBulletOptionLine(line, mode.action);
      if (bulletMatch) {
        const key = bulletMatch.name.toLowerCase() + '|' + bulletMatch.price + '|' + bulletMatch.action;
        if (!seen.has(key)) {
          seen.add(key);
          options.push(bulletMatch);
        }
        continue;
      }

      const pairMatch = parseLoosePair(lines, i, mode.action);
      if (pairMatch) {
        const key = pairMatch.name.toLowerCase() + '|' + pairMatch.price + '|' + pairMatch.action;
        if (!seen.has(key)) {
          seen.add(key);
          options.push(pairMatch);
        }
      }
    }

    return options;
  }

  function parseCtaOptionLine(line, defaultAction) {
    const match = line.match(/^(.*?)\s+tap to\s+(select|add|remove)\s*(.*)$/i);
    if (!match) return null;

    const rawName = normalizeOptionName(match[1]);
    if (!rawName) return null;

    const verb = (match[2] || '').toLowerCase();
    const tail = match[3] || '';
    const action = verb === 'remove' ? 'remove' : defaultAction;
    const price = action === 'remove' ? '0.00' : extractFirstMoney(tail, true);

    return {
      name: rawName,
      price,
      action
    };
  }

  function parseBulletOptionLine(line, defaultAction) {
    const match = line.match(/^[○◯o0]\s+(.*)$/i);
    if (!match) return null;

    const rawName = normalizeOptionName(match[1]);
    if (!rawName) return null;

    return {
      name: rawName,
      price: defaultAction === 'remove' ? '0.00' : '0.00',
      action: defaultAction
    };
  }

  function parseLoosePair(lines, i, defaultAction) {
    const line = lines[i];
    const next = String(lines[i + 1] || '');

    if (!line || !next) return null;
    if (looksLikeRuleLine(line) || looksLikeRuleLine(next)) return null;
    if (looksLikeGroupTitle(line, i, lines)) return null;
    if (/tap to (select|add|remove)/i.test(line)) return null;
    if (/tap to (select|add|remove)/i.test(next)) return null;

    if (/^\$?\d+(\.\d{2})?$/.test(next)) {
      const name = normalizeOptionName(line);
      if (!name) return null;

      return {
        name,
        price: defaultAction === 'remove' ? '0.00' : extractFirstMoney(next, true),
        action: defaultAction
      };
    }

    if (!/\$/.test(line) && line.length > 1 && !looksLikeGroupTitle(next, i + 1, lines)) {
      return null;
    }

    if (/\$/.test(line)) {
      const money = extractFirstMoney(line, true);
      const name = normalizeOptionName(
        line
          .replace(/([+-]?)\s*\$?\s*\d+(?:\.\d{2})?/g, '')
          .replace(/tap to.*/i, '')
      );

      if (!name) return null;

      return {
        name,
        price: defaultAction === 'remove' ? '0.00' : money,
        action: defaultAction
      };
    }

    return null;
  }

  function normalizeOptionName(name) {
    return String(name || '')
      .replace(/^[○◯o0]\s+/i, '')
      .replace(/\bTap to (select|add|remove)\b.*$/i, '')
      .replace(/\+\s*\$?\d+(?:\.\d{2})?/g, '')
      .replace(/\$\s*\d+(?:\.\d{2})?/g, '')
      .replace(/\s{2,}/g, ' ')
      .trim();
  }

  function looksLikeRuleLine(line) {
    const lower = String(line || '').toLowerCase().trim();

    if (!lower) return true;
    if (/^\d+\s+required$/.test(lower)) return true;
    if (/^optional$/.test(lower)) return true;
    if (/^select up to\s+\d+\s+options?$/.test(lower)) return true;
    if (/^tap to (select|add|remove)$/i.test(lower)) return true;
    return false;
  }

  function fillGroupDraftFromOCR(parsed, rawText) {
    if (!state.groupDraft) return;

    state.groupDraft.action = parsed.action || 'add';
    state.groupDraft.required = parsed.required || '0';
    state.groupDraft.type = parsed.type || 'multiple';
    state.groupDraft.options = Array.isArray(parsed.options) ? parsed.options : [];

    state.lastRawOcrText = rawText || '';

    if (typeof root.resetChipGroup === 'function') {
      root.resetChipGroup('action', state.groupDraft.action);
      root.resetChipGroup('required', state.groupDraft.required);
      root.resetChipGroup('type', state.groupDraft.type);
    }

    if (dom.draftGroupName) {
      dom.draftGroupName.value = parsed.groupName || '';
      dom.draftGroupName.dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (typeof root.renderDraftOptionsGrid === 'function') {
      root.renderDraftOptionsGrid();
    }

    if (typeof root.syncDraftHeroMeta === 'function') {
      root.syncDraftHeroMeta();
    }

    if (typeof root.syncDraftSummary === 'function') {
      root.syncDraftSummary();
    }

    if (dom.btnCommitGroup) {
      dom.btnCommitGroup.disabled = state.groupDraft.options.length === 0;
    }

    hideOcrPanel();

    if (dom.btnShowRawOcr) {
      dom.btnShowRawOcr.style.display = state.lastRawOcrText ? '' : 'none';
    }

    const count = state.groupDraft.options.length;

    if (count > 0) {
      toast('Group parsed. Review before commit.');
    } else {
      toast('Group captured but needs manual cleanup.');
    }

    if (typeof root.showBubbleMini === 'function') {
      root.showBubbleMini();
    }

    scheduleAutosave();
  }

  function showOcrPanel(text) {
    if (dom.ocrPanelText) dom.ocrPanelText.textContent = text;
    if (dom.ocrPanel) dom.ocrPanel.style.display = '';
    syncRawOcrButtonLabel();
  }

  function hideOcrPanel() {
    if (dom.ocrPanel) dom.ocrPanel.style.display = 'none';
    if (dom.ocrPanelText) dom.ocrPanelText.textContent = '';
    syncRawOcrButtonLabel();
  }

  function toggleRawOcrPanel() {
    if (!state.lastRawOcrText) return;

    if (dom.ocrPanel && dom.ocrPanel.style.display === 'none') {
      showOcrPanel(state.lastRawOcrText);
    } else {
      hideOcrPanel();
    }
  }

  function syncRawOcrButtonLabel() {
    if (!dom.btnShowRawOcr) return;
    const isOpen = dom.ocrPanel && dom.ocrPanel.style.display !== 'none';
    dom.btnShowRawOcr.textContent = isOpen ? 'Hide Raw OCR' : 'Raw OCR';
  }

  function initOcrModule() {
    initOcrSelection();

    const preview = getPreviewDom();

    if (dom.btnOcrPanelClose) {
      dom.btnOcrPanelClose.addEventListener('click', hideOcrPanel);
    }

    if (dom.btnGuideSkip) {
      dom.btnGuideSkip.addEventListener('click', skipGuidedStep);
    }

    if (preview.restartBtn) {
      preview.restartBtn.addEventListener('click', restartItemCapture);
    }

    if (dom.builderItemName) {
      dom.builderItemName.addEventListener('input', updateItemPreview);
    }

    if (dom.builderDesc) {
      dom.builderDesc.addEventListener('input', updateItemPreview);
    }

    if (dom.builderBasePrice) {
      dom.builderBasePrice.addEventListener('input', updateItemPreview);
    }

    document.addEventListener('click', e => {
      const pickBtn = e.target.closest('.mc-pick-btn');
      if (pickBtn && pickBtn.dataset.pickTarget) {
        e.preventDefault();
        activatePickMode(pickBtn.dataset.pickTarget);
      }

      const actionBtn = e.target.closest('[data-group-guide-action]');
      if (actionBtn) {
        e.preventDefault();
        applyGroupAction(actionBtn.dataset.groupGuideAction);
      }

      const requiredBtn = e.target.closest('[data-group-guide-required]');
      if (requiredBtn) {
        e.preventDefault();
        applyGroupRequired(requiredBtn.dataset.groupGuideRequired);
      }

      const typeBtn = e.target.closest('[data-group-guide-type]');
      if (typeBtn) {
        e.preventDefault();
        applyGroupType(requiredBtn ? requiredBtn.dataset.groupGuideType : typeBtn.dataset.groupGuideType);
      }
    });

    document.addEventListener('keydown', e => {
      if (e.key !== 'Escape') return;

      if (dom.eyeOverlay && dom.eyeOverlay.style.display !== 'none') {
        if (typeof root.closeEyePreview === 'function') root.closeEyePreview();
        return;
      }

      if (state.bubbleState === 'expanded') {
        if (typeof root.showBubbleMini === 'function') root.showBubbleMini();
        return;
      }

      if (state.pickTarget) {
        clearPickMode();
      }
    });

    window.addEventListener('resize', () => {
      if (state.bubbleState === 'expanded') {
        syncOcrCanvas();
      }
    });

    updateItemPreview();
  }

  root.initOCR = initOCR;
  root.syncOcrCanvas = syncOcrCanvas;
  root.initOcrSelection = initOcrSelection;
  root.performOCR = performOCR;
  root.fillNextField = fillNextField;

  root.activatePickMode = activatePickMode;
  root.clearPickMode = clearPickMode;

  root.activateBlockCapture = activateBlockCapture;
  root.activateBlockCaptureForGroup = activateBlockCaptureForGroup;
  root.deactivateBlockCapture = deactivateBlockCapture;

  root.parseGroupBlock = parseGroupBlock;
  root.fillGroupDraftFromOCR = fillGroupDraftFromOCR;

  root.showOcrPanel = showOcrPanel;
  root.hideOcrPanel = hideOcrPanel;
  root.toggleRawOcrPanel = toggleRawOcrPanel;
  root.syncRawOcrButtonLabel = syncRawOcrButtonLabel;

  root.showGuideUi = showGuideUi;
  root.hideGuideUi = hideGuideUi;
  root.finishGuidedCapture = finishGuidedCapture;
  root.cancelGuidedCapture = cancelGuidedCapture;
  root.startGuidedItemCapture = startGuidedItemCapture;
  root.startGuidedGroupCapture = startGuidedGroupCapture;
  root.startGuidedGroupSetupCapture = startGuidedGroupSetupCapture;
  root.skipGuidedStep = skipGuidedStep;
  root.restartItemCapture = restartItemCapture;
  root.updateItemPreview = updateItemPreview;

  root.initOcrModule = initOcrModule;
})();