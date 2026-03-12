/* ============================================================
   KNX Menu Studio — Migration Capture
   Image upload · bubble states · multi-image controls · OCR-safe boot
   ============================================================ */
(function () {
  'use strict';

  const root = window.KNX_MC;
  if (!root || root.__captureLoaded) return;
  root.__captureLoaded = true;

  const dom = root.dom;
  const state = root.state;
  const toast = root.toast;
  const ajaxUrl = root.ajaxUrl;
  const nonce = root.nonce;
  const scheduleAutosave = root.scheduleAutosave;

  let booted = false;

  function normalizeImageUrls(urls) {
    return (Array.isArray(urls) ? urls : [])
      .filter(Boolean)
      .slice(0, 5);
  }

  function syncDraftImages() {
    state.currentImageUrl = typeof root.getActiveImageUrl === 'function'
      ? root.getActiveImageUrl()
      : (state.currentImageUrls[state.activeImageIndex] || '');

    if (state.currentDraft) {
      state.currentDraft.imageUrl = state.currentImageUrl || '';
      state.currentDraft.imageUrls = state.currentImageUrls.slice();
    }
  }

  function renderBubbleImageRail() {
    if (!dom.bubbleImageRail) return;

    const urls = normalizeImageUrls(state.currentImageUrls);
    state.currentImageUrls = urls;

    if (!urls.length) {
      dom.bubbleImageRail.innerHTML = '';
      dom.bubbleImageRail.style.display = 'none';
      if (dom.bubbleImageCounter) dom.bubbleImageCounter.textContent = '0 / 0 active';
      return;
    }

    dom.bubbleImageRail.style.display = '';
    dom.bubbleImageRail.innerHTML = urls.map((url, idx) => {
      const active = idx === state.activeImageIndex ? ' active' : '';
      return (
        '<button type="button" class="mc-bubble__image-thumb' + active + '" data-image-idx="' + idx + '">' +
          '<img src="' + url + '" alt="Source image ' + (idx + 1) + '">' +
        '</button>'
      );
    }).join('');

    if (dom.bubbleImageCounter) {
      dom.bubbleImageCounter.textContent = (state.activeImageIndex + 1) + ' / ' + urls.length + ' active';
    }
  }

  function syncActiveImageToUi() {
    const url = typeof root.getActiveImageUrl === 'function'
      ? root.getActiveImageUrl()
      : (state.currentImageUrls[state.activeImageIndex] || '');

    state.currentImageUrl = url || '';

    if (dom.bubbleMiniImg) {
      dom.bubbleMiniImg.src = state.currentImageUrl || '';
    }

    if (dom.bubbleExpandedImg) {
      dom.bubbleExpandedImg.src = state.currentImageUrl || '';
    }

    syncDraftImages();
    renderBubbleImageRail();

    if (typeof root.syncOcrCanvas === 'function') {
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          root.syncOcrCanvas();
        });
      });
    }
  }

  function setImages(urls, activeIndex, silent) {
    const normalized = normalizeImageUrls(urls);
    state.currentImageUrls = normalized;

    if (!normalized.length) {
      state.activeImageIndex = 0;
      state.currentImageUrl = '';
      if (typeof root.hideBubble === 'function') root.hideBubble();
      return;
    }

    const safeIndex = Math.max(0, Math.min(Number(activeIndex || 0), normalized.length - 1));
    state.activeImageIndex = safeIndex;

    syncActiveImageToUi();

    if (!silent) {
      showBubbleExpanded();
      scheduleAutosave();
    }
  }

  function setActiveImage(index) {
    if (!state.currentImageUrls.length) return;

    const safeIndex = Math.max(0, Math.min(Number(index), state.currentImageUrls.length - 1));
    if (safeIndex === state.activeImageIndex) return;

    state.activeImageIndex = safeIndex;
    syncActiveImageToUi();
    scheduleAutosave();
  }

  function addImages(urls) {
    const merged = normalizeImageUrls([].concat(state.currentImageUrls || [], urls || []));
    if (!merged.length) return;

    const previousLength = (state.currentImageUrls || []).length;
    state.currentImageUrls = merged;
    state.activeImageIndex = Math.max(0, Math.min(previousLength, merged.length - 1));

    syncActiveImageToUi();
    showBubbleExpanded();
    scheduleAutosave();
  }

  function showBubbleMini() {
    if (!dom.imgBubble || !dom.bubbleMini || !dom.bubbleExpanded) return;

    state.bubbleState = 'mini';

    dom.imgBubble.style.display = '';
    dom.bubbleMini.style.display = '';
    dom.bubbleExpanded.style.display = 'none';

    dom.imgBubble.style.left = state.bubblePos.x + 'px';
    dom.imgBubble.style.top = state.bubblePos.y + 'px';
  }

  function showBubbleExpanded() {
    if (!dom.imgBubble || !dom.bubbleMini || !dom.bubbleExpanded) return;

    state.bubbleState = 'expanded';

    dom.imgBubble.style.display = '';
    dom.bubbleMini.style.display = 'none';
    dom.bubbleExpanded.style.display = '';

    dom.imgBubble.style.left = '';
    dom.imgBubble.style.top = '';

    if (typeof root.syncOcrCanvas === 'function') {
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          root.syncOcrCanvas();
        });
      });
    }
  }

  function hideBubble() {
    if (!dom.imgBubble) return;
    state.bubbleState = 'hidden';
    dom.imgBubble.style.display = 'none';
  }

  function readFilesAsDataUrls(files) {
    return Promise.all(files.map(file => (
      new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = e => resolve(e.target.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
      })
    )));
  }

  function validateFiles(files, existingCount) {
    if (!files || !files.length) {
      toast('No image selected.');
      return false;
    }

    if ((existingCount + files.length) > 5) {
      toast('Maximum 5 images per item.');
      return false;
    }

    for (const file of files) {
      if (!file.type || !file.type.startsWith('image/')) {
        toast('Only image files.');
        return false;
      }

      if (file.size > 10 * 1024 * 1024) {
        toast('Each image must be 10 MB or less.');
        return false;
      }
    }

    return true;
  }

  async function ensureOcrReady() {
    if (state.isOcrReady && state.ocrWorker) return true;

    if (typeof root.initOCR !== 'function') {
      toast('OCR module unavailable.');
      return false;
    }

    toast('Loading OCR engine…');
    await root.initOCR();

    if (state.isOcrReady && state.ocrWorker) {
      return true;
    }

    toast('OCR unavailable.');
    return false;
  }

  async function startSafeGuidedItemCapture() {
    if (!state.currentImageUrl) return;

    const ready = await ensureOcrReady();
    if (!ready) return;

    if (typeof root.startGuidedItemCapture === 'function') {
      root.startGuidedItemCapture();
    }
  }

  function parseUploadErrorText(text) {
    const trimmed = String(text || '').trim();

    if (!trimmed) return 'Empty server response.';
    if (trimmed === '0') return 'Server returned 0. Usually this means the AJAX action did not complete in WordPress.';
    if (trimmed === '-1') return 'Server returned -1. This usually means nonce validation failed.';

    try {
      const json = JSON.parse(trimmed);
      if (json && typeof json === 'object') {
        if (json.data && typeof json.data.error === 'string') {
          return json.data.error;
        }
        if (typeof json.message === 'string') {
          return json.message;
        }
      }
    } catch (e) {}

    return trimmed.slice(0, 220);
  }

  async function uploadFiles(files, appendMode) {
    const existingCount = appendMode ? (state.currentImageUrls || []).length : 0;

    if (!validateFiles(files, existingCount)) {
      if (dom.imageFileInput) dom.imageFileInput.value = '';
      return;
    }

    if (!(ajaxUrl && nonce)) {
      localFallback(files, appendMode);
      return;
    }

    const fd = new FormData();
    files.forEach(file => fd.append('images[]', file));
    fd.append('action', 'knx_studio_upload');
    fd.append('nonce', nonce);

    try {
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      const rawText = await response.text();
      let data = null;

      try {
        data = rawText ? JSON.parse(rawText) : null;
      } catch (e) {
        data = null;
      }

      if (!response.ok) {
        const detail = parseUploadErrorText(rawText);
        throw new Error(detail || ('Upload failed with status ' + response.status));
      }

      if (!data || !data.success) {
        const detail = parseUploadErrorText(rawText);
        throw new Error(detail || 'Invalid upload response');
      }

      const urls = Array.isArray(data?.data?.urls) ? data.data.urls : [];
      if (!urls.length) {
        throw new Error('Upload succeeded but no image URLs were returned.');
      }

      if (appendMode) {
        addImages(urls);
        toast(urls.length + ' image' + (urls.length !== 1 ? 's' : '') + ' added.');
      } else {
        setImages(urls, 0, false);
        toast(urls.length + ' image' + (urls.length !== 1 ? 's' : '') + ' loaded.');
        await startSafeGuidedItemCapture();
      }
    } catch (err) {
      console.warn('Server upload failed, using local fallback.', err);
      toast('Server upload failed. Using local image mode.');
      localFallback(files, appendMode);
    } finally {
      if (dom.imageFileInput) dom.imageFileInput.value = '';
    }
  }

  function localFallback(files, appendMode) {
    readFilesAsDataUrls(files)
      .then(async urls => {
        if (appendMode) {
          addImages(urls);
          toast(urls.length + ' image' + (urls.length !== 1 ? 's' : '') + ' added.');
        } else {
          setImages(urls, 0, false);
          toast(urls.length + ' image' + (urls.length !== 1 ? 's' : '') + ' loaded locally.');
          await startSafeGuidedItemCapture();
        }
      })
      .catch(err => {
        console.error('Local image fallback failed:', err);
        toast('Image load failed.');
      })
      .finally(() => {
        if (dom.imageFileInput) dom.imageFileInput.value = '';
      });
  }

  function initImageUpload() {
    if (!dom.btnUploadTop || !dom.imageFileInput) return;

    dom.btnUploadTop.addEventListener('click', () => {
      dom.imageFileInput.dataset.uploadMode = 'replace';
      dom.imageFileInput.click();
    });

    dom.imageFileInput.addEventListener('change', () => {
      const files = Array.from(dom.imageFileInput.files || []);
      const appendMode = dom.imageFileInput.dataset.uploadMode === 'append';
      uploadFiles(files, appendMode);
      dom.imageFileInput.dataset.uploadMode = 'replace';
    });
  }

  function initBubbleDrag() {
    if (!dom.bubbleMini || !dom.imgBubble) return;

    let dragging = false;
    let startX = 0;
    let startY = 0;
    let startLeft = 0;
    let startTop = 0;

    dom.bubbleMini.addEventListener('pointerdown', e => {
      if (state.bubbleState !== 'mini') return;

      dragging = true;
      startX = e.clientX;
      startY = e.clientY;
      startLeft = state.bubblePos.x;
      startTop = state.bubblePos.y;

      dom.bubbleMini.setPointerCapture(e.pointerId);
      e.preventDefault();
    });

    document.addEventListener('pointermove', e => {
      if (!dragging) return;

      const dx = e.clientX - startX;
      const dy = e.clientY - startY;

      state.bubblePos.x = Math.max(0, Math.min(window.innerWidth - 70, startLeft + dx));
      state.bubblePos.y = Math.max(0, Math.min(window.innerHeight - 70, startTop + dy));

      dom.imgBubble.style.left = state.bubblePos.x + 'px';
      dom.imgBubble.style.top = state.bubblePos.y + 'px';
    });

    document.addEventListener('pointerup', e => {
      if (!dragging) return;

      dragging = false;

      const dx = Math.abs(e.clientX - startX);
      const dy = Math.abs(e.clientY - startY);

      if (dx < 6 && dy < 6) {
        showBubbleExpanded();
      } else {
        const midX = window.innerWidth / 2;
        state.bubblePos.x = state.bubblePos.x + 35 < midX ? 12 : window.innerWidth - 76;
        dom.imgBubble.style.left = state.bubblePos.x + 'px';
      }
    });
  }

  function initBubbleControls() {
    if (dom.btnBubbleMinimize) {
      dom.btnBubbleMinimize.addEventListener('click', showBubbleMini);
    }

    if (dom.btnBubbleClose) {
      dom.btnBubbleClose.addEventListener('click', showBubbleMini);
    }

    if (dom.btnBubbleReplace && dom.imageFileInput) {
      dom.btnBubbleReplace.addEventListener('click', () => {
        dom.imageFileInput.dataset.uploadMode = 'replace';
        dom.imageFileInput.click();
      });
    }

    if (dom.btnBubbleAddMore && dom.imageFileInput) {
      dom.btnBubbleAddMore.addEventListener('click', () => {
        if ((state.currentImageUrls || []).length >= 5) {
          toast('Maximum 5 images per item.');
          return;
        }

        dom.imageFileInput.dataset.uploadMode = 'append';
        dom.imageFileInput.click();
      });
    }

    if (dom.bubbleImageRail) {
      dom.bubbleImageRail.addEventListener('click', e => {
        const thumb = e.target.closest('[data-image-idx]');
        if (!thumb) return;
        setActiveImage(parseInt(thumb.dataset.imageIdx, 10));
      });
    }

    window.addEventListener('resize', () => {
      if (state.bubbleState === 'expanded' && typeof root.syncOcrCanvas === 'function') {
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            root.syncOcrCanvas();
          });
        });
      }
    });

    window.addEventListener('orientationchange', () => {
      setTimeout(() => {
        if (state.bubbleState === 'expanded' && typeof root.syncOcrCanvas === 'function') {
          requestAnimationFrame(() => {
            requestAnimationFrame(() => {
              root.syncOcrCanvas();
            });
          });
        }
      }, 180);
    });
  }

  function initCaptureCoordinator() {
    if (booted) return;
    booted = true;

    if (typeof root.initCore === 'function') root.initCore();
    if (typeof root.initBuilder === 'function') root.initBuilder();
    if (typeof root.initOcrModule === 'function') root.initOcrModule();

    initImageUpload();
    initBubbleDrag();
    initBubbleControls();
  }

  root.normalizeImageUrls = normalizeImageUrls;
  root.setImages = setImages;
  root.addImages = addImages;
  root.setActiveImage = setActiveImage;
  root.showBubbleMini = showBubbleMini;
  root.showBubbleExpanded = showBubbleExpanded;
  root.hideBubble = hideBubble;
  root.syncActiveImageToUi = syncActiveImageToUi;
  root.ensureOcrReady = ensureOcrReady;
  root.startSafeGuidedItemCapture = startSafeGuidedItemCapture;

  root.initImageUpload = initImageUpload;
  root.initBubbleDrag = initBubbleDrag;
  root.initBubbleControls = initBubbleControls;
  root.initCaptureCoordinator = initCaptureCoordinator;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCaptureCoordinator, { once: true });
  } else {
    initCaptureCoordinator();
  }
})();