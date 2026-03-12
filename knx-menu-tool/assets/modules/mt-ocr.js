window.KNXMT_OCR = (() => {
  async function runBrowserOcr(blob, setStatus, U) {
    if (!window.Tesseract) {
      throw new Error('Tesseract.js is not loaded.');
    }

    const objectUrl = URL.createObjectURL(blob);

    try {
      const result = await window.Tesseract.recognize(objectUrl, 'eng', {
        logger: (m) => {
          if (m?.status && typeof setStatus === 'function') {
            setStatus(`${m.status}${m.progress ? ` ${Math.round(m.progress * 100)}%` : ''}`);
          }
        },
      });

      URL.revokeObjectURL(objectUrl);

      const rawText = result?.data?.text || '';
      const cleaned = U.normalizeOcrText(rawText);

      if (!cleaned.trim()) {
        throw new Error('OCR returned empty text.');
      }

      return cleaned;
    } catch (error) {
      URL.revokeObjectURL(objectUrl);
      throw error;
    }
  }

  return {
    runBrowserOcr,
  };
})();