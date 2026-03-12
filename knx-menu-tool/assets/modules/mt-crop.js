window.KNXMT_Crop = (() => {
  function openCropModal(refs, state, fileBlob, U) {
    return U.blobToImage(fileBlob).then((image) => {
      state.cropSession.image = image;
      state.cropSession.rect = U.getDefaultCropRect(image.width, image.height);
      refs.cropModal.hidden = false;
      document.body.style.overflow = 'hidden';
      renderCropCanvas(refs, state);
    });
  }

  function closeCropModal(refs, state) {
    refs.cropModal.hidden = true;
    document.body.style.overflow = '';
    state.cropSession.image = null;
    state.cropSession.rect = null;
    state.cropSession.dragging = false;
    state.cropSession.mode = null;
  }

  function resetCropRect(refs, state, U) {
    const image = state.cropSession.image;
    if (!image) return;
    state.cropSession.rect = U.getDefaultCropRect(image.width, image.height);
    renderCropCanvas(refs, state);
  }

  async function buildCropAssets(state, itemId, U) {
    if (!state.currentImageOriginalBlob || !state.cropSession.image || !state.cropSession.rect) {
      throw new Error('Crop session is incomplete.');
    }

    const originalAsset = {
      id: U.uid('asset'),
      item_id: itemId,
      slot: 'A',
      kind: 'original',
      blob: state.currentImageOriginalBlob,
      created_at: Date.now(),
    };

    const croppedBlob = await U.cropBlob(state.cropSession.image, state.cropSession.rect);
    const croppedAsset = {
      id: U.uid('asset'),
      item_id: itemId,
      slot: 'A',
      kind: 'cropped',
      blob: croppedBlob,
      created_at: Date.now(),
    };

    const cropMeta = {
      x: state.cropSession.rect.x,
      y: state.cropSession.rect.y,
      w: state.cropSession.rect.w,
      h: state.cropSession.rect.h,
      source_w: state.cropSession.image.width,
      source_h: state.cropSession.image.height,
    };

    return {
      originalAsset,
      croppedAsset,
      cropMeta,
    };
  }

  function wireCropCanvas(refs, state, U) {
    if (!refs.cropCanvas) return;

    const onPointerDown = (event) => {
      if (!state.cropSession.rect || !state.cropSession.image) return;
      event.preventDefault();

      const point = getCanvasPoint(event, refs.cropCanvas, state);
      const hit = getCropHit(point, state.cropSession.rect, state.cropSession.handleSize);
      if (!hit) return;

      state.cropSession.dragging = true;
      state.cropSession.mode = hit;
      state.cropSession.startX = point.x;
      state.cropSession.startY = point.y;
    };

    const onPointerMove = (event) => {
      if (!state.cropSession.dragging || !state.cropSession.rect || !state.cropSession.image) return;
      event.preventDefault();

      const point = getCanvasPoint(event, refs.cropCanvas, state);
      const dx = point.x - state.cropSession.startX;
      const dy = point.y - state.cropSession.startY;

      const rect = state.cropSession.rect;
      const maxW = state.cropSession.image.width;
      const maxH = state.cropSession.image.height;

      if (state.cropSession.mode === 'move') {
        rect.x = U.clamp(rect.x + dx, 0, maxW - rect.w);
        rect.y = U.clamp(rect.y + dy, 0, maxH - rect.h);
      }

      if (state.cropSession.mode === 'resize-br') {
        rect.w = U.clamp(rect.w + dx, 40, maxW - rect.x);
        rect.h = U.clamp(rect.h + dy, 40, maxH - rect.y);
      }

      state.cropSession.startX = point.x;
      state.cropSession.startY = point.y;
      renderCropCanvas(refs, state);
    };

    const onPointerUp = (event) => {
      if (!state.cropSession.dragging) return;
      event.preventDefault?.();
      state.cropSession.dragging = false;
      state.cropSession.mode = null;
    };

    refs.cropCanvas.addEventListener('mousedown', onPointerDown);
    refs.cropCanvas.addEventListener('mousemove', onPointerMove);
    window.addEventListener('mouseup', onPointerUp);

    refs.cropCanvas.addEventListener('touchstart', onPointerDown, { passive: false });
    refs.cropCanvas.addEventListener('touchmove', onPointerMove, { passive: false });
    window.addEventListener('touchend', onPointerUp, { passive: false });
  }

  function renderCropCanvas(refs, state) {
    const image = state.cropSession.image;
    const rect = state.cropSession.rect;
    const canvas = refs.cropCanvas;

    if (!image || !rect || !canvas) return;

    const wrap = canvas.parentElement;
    const maxWidth = Math.max(320, wrap.clientWidth - 20);
    const scale = Math.min(1, maxWidth / image.width);
    state.cropSession.scale = scale;

    canvas.width = image.width * scale;
    canvas.height = image.height * scale;
    canvas.style.width = `${canvas.width}px`;
    canvas.style.height = `${canvas.height}px`;

    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(image, 0, 0, canvas.width, canvas.height);

    const rx = rect.x * scale;
    const ry = rect.y * scale;
    const rw = rect.w * scale;
    const rh = rect.h * scale;

    ctx.save();
    ctx.fillStyle = 'rgba(15,23,42,0.45)';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    ctx.clearRect(rx, ry, rw, rh);
    ctx.drawImage(image, rect.x, rect.y, rect.w, rect.h, rx, ry, rw, rh);

    ctx.strokeStyle = '#0b793a';
    ctx.lineWidth = 3;
    ctx.strokeRect(rx, ry, rw, rh);

    ctx.fillStyle = '#0b793a';
    ctx.fillRect(rx + rw - 10, ry + rh - 10, 20, 20);
    ctx.restore();
  }

  function getCanvasPoint(event, canvas, state) {
    const rect = canvas.getBoundingClientRect();
    const touch = event.touches?.[0] || event.changedTouches?.[0] || null;
    const clientX = touch ? touch.clientX : event.clientX;
    const clientY = touch ? touch.clientY : event.clientY;

    return {
      x: (clientX - rect.left) / state.cropSession.scale,
      y: (clientY - rect.top) / state.cropSession.scale,
    };
  }

  function getCropHit(point, rect, handleSize) {
    const brX = rect.x + rect.w;
    const brY = rect.y + rect.h;

    if (
      point.x >= brX - handleSize &&
      point.x <= brX + handleSize &&
      point.y >= brY - handleSize &&
      point.y <= brY + handleSize
    ) {
      return 'resize-br';
    }

    if (
      point.x >= rect.x &&
      point.x <= rect.x + rect.w &&
      point.y >= rect.y &&
      point.y <= rect.y + rect.h
    ) {
      return 'move';
    }

    return null;
  }

  return {
    openCropModal,
    closeCropModal,
    resetCropRect,
    buildCropAssets,
    wireCropCanvas,
    renderCropCanvas,
  };
})();