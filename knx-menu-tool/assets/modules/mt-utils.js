window.KNXMT_Utils = (() => {
  function safeJson(value) {
    try {
      return JSON.parse(value);
    } catch (error) {
      return {};
    }
  }

  function byId(id) {
    return document.getElementById(id);
  }

  function safeNumber(value) {
    const parsed = parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function safeInt(value, fallback = 0) {
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function formatMoneyInput(value) {
    const num = safeNumber(value);
    return num ? String(num) : '';
  }

  function formatMoneyDisplay(value) {
    return safeNumber(value).toFixed(2);
  }

  function formatFrozenDate(ts) {
    if (!ts) return 'Frozen just now';
    const d = new Date(ts);
    return `Frozen ${d.toLocaleDateString()} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
  }

  function uid(prefix) {
    return `${prefix}_${Math.random().toString(36).slice(2, 10)}_${Date.now().toString(36)}`;
  }

  function deepClone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (m) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[m]));
  }

  function escapeAttr(str) {
    return escapeHtml(str);
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function normalizeOcrText(text) {
    return String(text || '')
      .replace(/\r\n/g, '\n')
      .replace(/\r/g, '\n')
      .replace(/[ \t]+/g, ' ')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  function getDefaultCropRect(width, height) {
    const marginX = Math.round(width * 0.08);
    const marginY = Math.round(height * 0.08);

    return {
      x: marginX,
      y: marginY,
      w: Math.max(40, width - marginX * 2),
      h: Math.max(40, height - marginY * 2),
    };
  }

  function inferGroupType(group) {
    const min = safeInt(group?.min_selection, 0);
    const max = group?.max_selection === null || group?.max_selection === '' ? null : safeInt(group?.max_selection, 1);

    if (max === null) return 'multiple';
    if (max <= 1 && min <= 1) return 'single';
    return 'multiple';
  }

  function formatRuleLabel(group) {
    const min = safeInt(group?.min_selection, 0);
    const rawMax = group?.max_selection;
    const max = rawMax === null || rawMax === '' ? null : safeInt(rawMax, 0);

    if (min === 1 && max === 1) return '1 Required';
    if (min > 1 && max === min) return `${min} Required`;
    if (min === 0 && max === null) return 'Optional';
    if (min === 0 && max !== null) return `Up to ${max}`;
    if (max === null) return `${min}+`;
    if (min === max) return `${min} Required`;
    return `${min}-${max}`;
  }

  function createEmptyOption() {
    return {
      name: '',
      price_adjustment: 0,
    };
  }

  function createEmptyGroup() {
    return {
      name: '',
      type: 'single',
      required: false,
      min_selection: 0,
      max_selection: 1,
      options: [],
    };
  }

  function normalizeGroup(group) {
    const normalized = {
      name: group?.name || '',
      type: group?.type || 'single',
      required: !!group?.required,
      min_selection: safeInt(group?.min_selection, 0),
      max_selection: group?.max_selection === null || group?.max_selection === '' ? null : safeInt(group?.max_selection, 1),
      options: Array.isArray(group?.options)
        ? group.options.map((option) => ({
            name: option?.name || '',
            price_adjustment: safeNumber(option?.price_adjustment),
          }))
        : [],
    };

    normalized.type = inferGroupType(normalized);

    if (normalized.type === 'single' && normalized.max_selection === null) {
      normalized.max_selection = 1;
    }

    if (normalized.required && normalized.min_selection < 1) {
      normalized.min_selection = 1;
    }

    return normalized;
  }

  function normalizeGroups(groups) {
    if (!Array.isArray(groups)) return [];
    return groups.map((group) => normalizeGroup(group));
  }

  function buildSnapshotFromItem(item) {
    return {
      title: item?.title || '',
      description: item?.description || '',
      base_price: safeNumber(item?.base_price),
      globals: {
        special_instructions_allowed: !!item?.globals?.special_instructions_allowed,
      },
      groups: normalizeGroups(item?.groups || []).map((group) => ({
        name: group.name || '',
        type: group.type || inferGroupType(group),
        required: !!group.required,
        min_selection: safeInt(group.min_selection, 0),
        max_selection: group.max_selection === null || group.max_selection === '' ? null : safeInt(group.max_selection, 1),
        options: (group.options || []).map((option) => ({
          name: option.name || '',
          price_adjustment: safeNumber(option.price_adjustment),
        })),
      })),
    };
  }

  async function blobToImage(blob) {
    const url = URL.createObjectURL(blob);

    return await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = reject;
      img.src = url;
    });
  }

  async function cropBlob(image, rect) {
    const canvas = document.createElement('canvas');
    canvas.width = rect.w;
    canvas.height = rect.h;

    const ctx = canvas.getContext('2d');
    ctx.drawImage(
      image,
      rect.x, rect.y, rect.w, rect.h,
      0, 0, rect.w, rect.h
    );

    return await new Promise((resolve) => {
      canvas.toBlob((blob) => resolve(blob), 'image/png', 1);
    });
  }

  return {
    safeJson,
    byId,
    safeNumber,
    safeInt,
    formatMoneyInput,
    formatMoneyDisplay,
    formatFrozenDate,
    uid,
    deepClone,
    escapeHtml,
    escapeAttr,
    clamp,
    normalizeOcrText,
    getDefaultCropRect,
    inferGroupType,
    formatRuleLabel,
    createEmptyOption,
    createEmptyGroup,
    normalizeGroup,
    normalizeGroups,
    buildSnapshotFromItem,
    blobToImage,
    cropBlob,
  };
})();