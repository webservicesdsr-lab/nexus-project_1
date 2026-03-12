/* ============================================================
   KNX Menu Studio — Tablet App Mode Controller
   Clean tablet OCR workspace + centered bottom sheet + tap raise
   ============================================================ */
(function () {
  'use strict';

  const root = window.KNX_MC;
  if (!root || root.__appModeLoaded) return;
  root.__appModeLoaded = true;

  const dom = root.dom;
  const state = root.state;
  const toast = root.toast;

  const appState = {
    isAppMode: false,
    bottomSheetState: 'peek', // 'peek' | 'mid' | 'full' | 'hidden'
    sessionDrawerOpen: false,
    lastOrientation: null,
    isDraggingSheet: false,
    resizeTimer: null,
    bottomSheetBound: false,
    sessionDrawerBound: false,
    orientationBound: false,
    handleTapTimer: null,
    lastHandleTapAt: 0,
  };

  function isTabletViewport() {
    return window.innerWidth <= 1024;
  }

  function getShell() {
    return document.querySelector('.mc-shell');
  }

  function getOrientation() {
    return window.innerHeight > window.innerWidth ? 'portrait' : 'landscape';
  }

  function triggerHaptic(style) {
    if (!navigator.vibrate) return;

    switch (style) {
      case 'light':
        navigator.vibrate(10);
        break;
      case 'medium':
        navigator.vibrate(20);
        break;
      case 'heavy':
        navigator.vibrate([30, 10, 30]);
        break;
      case 'success':
        navigator.vibrate([10, 50, 20]);
        break;
      default:
        navigator.vibrate(15);
        break;
    }
  }

  function lockBodyScroll() {
    document.body.classList.add('app-overlay-active');
  }

  function unlockBodyScroll() {
    document.body.classList.remove('app-overlay-active');
  }

  function getPreview() {
    return document.getElementById('item-guided-preview');
  }

  function getBubbleRail() {
    return document.getElementById('bubble-image-rail');
  }

  function getBubbleFooter() {
    return document.getElementById('bubble-expanded-footer');
  }

  function hasMultipleImages() {
    return Array.isArray(state.currentImageUrls) && state.currentImageUrls.length > 1;
  }

  function syncBubbleRailVisibility() {
    const rail = getBubbleRail();
    if (!rail || !appState.isAppMode) return;
    rail.style.display = hasMultipleImages() ? '' : 'none';
  }

  function getCssVarPx(name, fallback) {
    const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    if (!raw) return fallback;

    if (raw.endsWith('px')) {
      const n = parseFloat(raw);
      return Number.isNaN(n) ? fallback : n;
    }

    if (raw.endsWith('vh')) {
      const n = parseFloat(raw);
      return Number.isNaN(n) ? fallback : (window.innerHeight * n / 100);
    }

    const parsed = parseFloat(raw);
    return Number.isNaN(parsed) ? fallback : parsed;
  }

  function getBottomSheetMetrics() {
    const vh = window.innerHeight;

    const peekVisible = getCssVarPx('--app-bottom-sheet-peek', 112);
    const midVisible = getCssVarPx('--app-bottom-sheet-mid', vh * 0.56);
    const fullVisible = getCssVarPx('--app-bottom-sheet-full', vh * 0.88);

    const safePeek = Math.max(84, Math.min(peekVisible, vh - 80));
    const safeMid = Math.max(safePeek + 40, Math.min(midVisible, vh - 40));
    const safeFull = Math.max(safeMid + 40, Math.min(fullVisible, vh - 8));

    return {
      viewportHeight: vh,
      peekVisible: safePeek,
      midVisible: safeMid,
      fullVisible: safeFull,
      peekTranslate: Math.max(0, vh - safePeek),
      midTranslate: Math.max(0, vh - safeMid),
      fullTranslate: Math.max(0, vh - safeFull),
    };
  }

  function getTranslateForState(sheetState) {
    const metrics = getBottomSheetMetrics();

    if (sheetState === 'full') return metrics.fullTranslate;
    if (sheetState === 'mid') return metrics.midTranslate;
    if (sheetState === 'hidden') return metrics.viewportHeight;

    return metrics.peekTranslate;
  }

  function applyPreviewTranslate(px) {
    const preview = getPreview();
    if (!preview) return;
    preview.style.transform = 'translateY(' + Math.round(px) + 'px)';
  }

  function clearPreviewInlineTranslate() {
    const preview = getPreview();
    if (!preview) return;
    preview.style.transform = '';
    preview.style.transition = '';
  }

  function syncFooterPosition() {
    const footer = getBubbleFooter();
    if (!footer || !appState.isAppMode) return;
    footer.setAttribute('data-sheet-state', appState.bottomSheetState || 'peek');
  }

  function setBottomSheetState(newState, options) {
    const preview = getPreview();
    if (!preview) return;

    const opts = options || {};
    const silent = !!opts.silent;

    preview.classList.remove('is-peek', 'is-mid', 'is-full', 'is-hidden');

    if (newState && newState !== 'hidden') {
      preview.classList.add('is-' + newState);
    }

    appState.bottomSheetState = newState;
    clearPreviewInlineTranslate();
    syncFooterPosition();

    if (!silent && newState !== 'peek') {
      triggerHaptic('light');
    }
  }

  function isInteractiveElement(target) {
    if (!target) return false;

    return !!target.closest(
      'button, input, textarea, select, a, label, .mc-guided-item-preview__steps, .mc-guided-item-preview__card, .mc-guided-item-preview__hint'
    );
  }

  function isHandleZone(target, clientY) {
    const preview = getPreview();
    if (!preview) return false;
    if (!preview.contains(target)) return false;

    const rect = preview.getBoundingClientRect();
    const localY = clientY - rect.top;

    return localY >= 0 && localY <= 78;
  }

  function advanceSheetByTap() {
    if (appState.bottomSheetState === 'peek') {
      setBottomSheetState('mid');
      return;
    }

    if (appState.bottomSheetState === 'mid') {
      setBottomSheetState('full');
      return;
    }

    setBottomSheetState('peek');
  }

  function activateAppMode() {
    if (appState.isAppMode) return;

    const shell = getShell();
    if (!shell) return;

    shell.dataset.appMode = 'true';
    appState.isAppMode = true;
    appState.lastOrientation = getOrientation();

    initSessionDrawer();
    initBottomSheet();
    initOrientationHandler();
    syncBubbleRailVisibility();
  }

  function deactivateAppMode() {
    if (!appState.isAppMode) return;

    const shell = getShell();
    if (!shell) return;

    shell.dataset.appMode = 'false';
    appState.isAppMode = false;
    appState.bottomSheetState = 'peek';
    appState.sessionDrawerOpen = false;
    appState.isDraggingSheet = false;

    unlockBodyScroll();

    const preview = getPreview();
    if (preview) {
      preview.classList.remove('is-peek', 'is-mid', 'is-full', 'is-hidden');
      clearPreviewInlineTranslate();
    }

    const session = document.querySelector('.mc-session');
    if (session) {
      session.classList.remove('is-expanded');
    }
  }

  function checkAndToggleAppMode() {
    const shell = getShell();
    if (!shell) return;

    if (isTabletViewport()) {
      activateAppMode();
    } else {
      deactivateAppMode();
    }
  }

  function initSessionDrawer() {
    if (appState.sessionDrawerBound) return;
    appState.sessionDrawerBound = true;

    const session = document.querySelector('.mc-session');
    const header = document.querySelector('.mc-session__header');

    if (!session || !header) return;

    header.addEventListener('click', function (e) {
      if (!appState.isAppMode) return;
      if (e.target.closest('button, input, textarea, select, a')) return;

      e.preventDefault();
      toggleSessionDrawer();
    });

    document.addEventListener('click', function (e) {
      if (!appState.isAppMode || !appState.sessionDrawerOpen) return;

      if (!session.contains(e.target)) {
        closeSessionDrawer();
      }
    });

    let touchStartY = 0;

    session.addEventListener('touchstart', function (e) {
      if (!appState.isAppMode || !appState.sessionDrawerOpen) return;
      touchStartY = e.touches[0].clientY;
    }, { passive: true });

    session.addEventListener('touchend', function (e) {
      if (!appState.isAppMode || !appState.sessionDrawerOpen) return;

      const touchEndY = e.changedTouches[0].clientY;
      const deltaY = touchEndY - touchStartY;

      if (deltaY > 60) {
        closeSessionDrawer();
        triggerHaptic('light');
      }
    }, { passive: true });
  }

  function toggleSessionDrawer() {
    if (appState.sessionDrawerOpen) {
      closeSessionDrawer();
    } else {
      openSessionDrawer();
    }
  }

  function openSessionDrawer() {
    const session = document.querySelector('.mc-session');
    if (!session) return;

    session.classList.add('is-expanded');
    appState.sessionDrawerOpen = true;
    triggerHaptic('light');
  }

  function closeSessionDrawer() {
    const session = document.querySelector('.mc-session');
    if (!session) return;

    session.classList.remove('is-expanded');
    appState.sessionDrawerOpen = false;
  }

  function initBottomSheet() {
    if (appState.bottomSheetBound) return;
    appState.bottomSheetBound = true;

    const preview = getPreview();
    if (!preview) return;

    let startY = 0;
    let startTranslate = 0;
    let currentTranslate = 0;
    let dragStartTime = 0;
    let draggingFromHandleZone = false;
    let movedEnough = false;

    setBottomSheetState('peek', { silent: true });

    preview.addEventListener('touchstart', function (e) {
      if (!appState.isAppMode) return;
      if (!state.guidedMode || state.guidedMode !== 'item') return;

      const touch = e.touches[0];

      if (!isHandleZone(e.target, touch.clientY)) return;
      if (isInteractiveElement(e.target) && !e.target.closest('.mc-guided-item-preview')) return;

      appState.isDraggingSheet = true;
      draggingFromHandleZone = true;
      movedEnough = false;
      startY = touch.clientY;
      dragStartTime = Date.now();
      startTranslate = getTranslateForState(appState.bottomSheetState || 'peek');
      currentTranslate = startTranslate;

      preview.style.transition = 'none';
      e.preventDefault();
    }, { passive: false });

    preview.addEventListener('touchmove', function (e) {
      if (!appState.isAppMode) return;
      if (!appState.isDraggingSheet || !draggingFromHandleZone) return;

      const touch = e.touches[0];
      const deltaY = touch.clientY - startY;
      const metrics = getBottomSheetMetrics();

      if (Math.abs(deltaY) > 8) {
        movedEnough = true;
      }

      currentTranslate = Math.max(
        metrics.fullTranslate,
        Math.min(metrics.viewportHeight, startTranslate + deltaY)
      );

      applyPreviewTranslate(currentTranslate);
      e.preventDefault();
    }, { passive: false });

    preview.addEventListener('touchend', function (e) {
      if (!appState.isAppMode) return;
      if (!appState.isDraggingSheet || !draggingFromHandleZone) return;

      const endY = e.changedTouches[0].clientY;
      const deltaY = endY - startY;
      const duration = Math.max(1, Date.now() - dragStartTime);
      const velocity = Math.abs(deltaY) / duration;
      const metrics = getBottomSheetMetrics();

      preview.style.transition = '';
      appState.isDraggingSheet = false;
      draggingFromHandleZone = false;

      const midpointPeekMid = (metrics.peekTranslate + metrics.midTranslate) / 2;
      const midpointMidFull = (metrics.midTranslate + metrics.fullTranslate) / 2;

      if (!movedEnough && Math.abs(deltaY) < 8) {
        clearPreviewInlineTranslate();

        const now = Date.now();
        const isDoubleTap = now - appState.lastHandleTapAt < 300;
        appState.lastHandleTapAt = now;

        if (isDoubleTap) {
          if (appState.bottomSheetState === 'peek') {
            setBottomSheetState('full');
          } else {
            setBottomSheetState('peek');
          }
          triggerHaptic('medium');
        } else {
          advanceSheetByTap();
          triggerHaptic('light');
        }

        e.preventDefault();
        return;
      }

      if (velocity > 0.75) {
        if (deltaY > 24) {
          if (appState.bottomSheetState === 'full') {
            setBottomSheetState('mid');
          } else {
            setBottomSheetState('peek');
          }
        } else if (deltaY < -24) {
          if (appState.bottomSheetState === 'peek') {
            setBottomSheetState('mid');
          } else {
            setBottomSheetState('full');
          }
        } else {
          setBottomSheetState(appState.bottomSheetState || 'peek', { silent: true });
        }
      } else {
        if (currentTranslate >= midpointPeekMid) {
          setBottomSheetState('peek', { silent: true });
        } else if (currentTranslate >= midpointMidFull) {
          setBottomSheetState('mid', { silent: true });
        } else {
          setBottomSheetState('full', { silent: true });
        }
      }

      triggerHaptic('light');
      e.preventDefault();
    }, { passive: false });

    preview.addEventListener('touchcancel', function () {
      if (!appState.isDraggingSheet) return;

      appState.isDraggingSheet = false;
      draggingFromHandleZone = false;
      movedEnough = false;
      setBottomSheetState(appState.bottomSheetState || 'peek', { silent: true });
    }, { passive: true });
  }

  function initOrientationHandler() {
    if (appState.orientationBound) return;
    appState.orientationBound = true;

    const handleOrientationChange = function () {
      if (!appState.isAppMode) return;

      const newOrientation = getOrientation();
      if (newOrientation === appState.lastOrientation) return;

      appState.lastOrientation = newOrientation;
      setBottomSheetState('peek', { silent: true });
      syncBubbleRailVisibility();

      if (typeof root.syncOcrCanvas === 'function') {
        setTimeout(function () {
          root.syncOcrCanvas();
        }, 260);
      }
    };

    window.addEventListener('orientationchange', handleOrientationChange);

    const mq = window.matchMedia('(orientation: portrait)');
    if (mq && typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', handleOrientationChange);
    }
  }

  function animateFieldCapture(inputElement, previewElement) {
    if (!appState.isAppMode) return;

    if (inputElement) {
      inputElement.classList.add('is-captured');
      setTimeout(function () {
        inputElement.classList.remove('is-captured');
      }, 600);
    }

    if (previewElement) {
      previewElement.classList.add('is-captured');
      setTimeout(function () {
        previewElement.classList.remove('is-captured');
      }, 400);
    }

    triggerHaptic('success');
  }

  function animateStepTransition(direction) {
    if (!appState.isAppMode) return;

    const hint = document.getElementById('preview-item-hint');
    if (!hint) return;

    const className = direction === 'forward' ? 'is-advancing' : 'is-retreating';

    hint.classList.remove('is-advancing', 'is-retreating');
    void hint.offsetWidth;
    hint.classList.add(className);

    setTimeout(function () {
      hint.classList.remove(className);
    }, 250);
  }

  const originalShowBubbleExpanded = root.showBubbleExpanded;
  root.showBubbleExpanded = function () {
    if (typeof originalShowBubbleExpanded === 'function') {
      originalShowBubbleExpanded();
    }

    if (!appState.isAppMode) return;

    lockBodyScroll();
    syncBubbleRailVisibility();

    const preview = getPreview();
    if (preview && state.guidedMode === 'item') {
      preview.style.display = '';
      setBottomSheetState('peek', { silent: true });
    }

    if (typeof root.syncOcrCanvas === 'function') {
      setTimeout(function () {
        root.syncOcrCanvas();
      }, 40);
    }
  };

  const originalShowBubbleMini = root.showBubbleMini;
  root.showBubbleMini = function () {
    if (typeof originalShowBubbleMini === 'function') {
      originalShowBubbleMini();
    }

    if (!appState.isAppMode) return;
    unlockBodyScroll();
  };

  const originalHideBubble = root.hideBubble;
  root.hideBubble = function () {
    if (typeof originalHideBubble === 'function') {
      originalHideBubble();
    }

    if (!appState.isAppMode) return;
    unlockBodyScroll();
  };

  const originalSetImages = root.setImages;
  if (typeof originalSetImages === 'function') {
    root.setImages = function (urls, activeIndex, silent) {
      originalSetImages(urls, activeIndex, silent);
      setTimeout(syncBubbleRailVisibility, 0);
    };
  }

  const originalAddImages = root.addImages;
  if (typeof originalAddImages === 'function') {
    root.addImages = function (urls) {
      originalAddImages(urls);
      setTimeout(syncBubbleRailVisibility, 0);
    };
  }

  function initAppMode() {
    checkAndToggleAppMode();

    window.addEventListener('resize', function () {
      clearTimeout(appState.resizeTimer);
      appState.resizeTimer = setTimeout(function () {
        checkAndToggleAppMode();

        if (!appState.isAppMode) return;

        syncBubbleRailVisibility();

        if (typeof root.syncOcrCanvas === 'function' && state.bubbleState === 'expanded') {
          root.syncOcrCanvas();
        }
      }, 150);
    });
  }

  root.appState = appState;
  root.activateAppMode = activateAppMode;
  root.deactivateAppMode = deactivateAppMode;
  root.triggerHaptic = triggerHaptic;
  root.openSessionDrawer = openSessionDrawer;
  root.closeSessionDrawer = closeSessionDrawer;
  root.setBottomSheetState = setBottomSheetState;
  root.animateFieldCapture = animateFieldCapture;
  root.animateStepTransition = animateStepTransition;
  root.initAppMode = initAppMode;
  root.syncBubbleRailVisibility = syncBubbleRailVisibility;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAppMode, { once: true });
  } else {
    setTimeout(initAppMode, 50);
  }
})();