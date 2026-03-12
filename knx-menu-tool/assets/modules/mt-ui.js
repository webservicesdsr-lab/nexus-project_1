window.KNXMT_UI = (() => {
  function setStatus(refs, text) {
    if (refs.statusPill) {
      refs.statusPill.textContent = text;
    }
  }

  function toast(refs, text) {
    setStatus(refs, text);

    setTimeout(() => {
      if (refs.statusPill?.textContent === text) {
        refs.statusPill.textContent = 'Saved';
      }
    }, 1200);
  }

  function logConsole(refs, title, payload) {
    if (!refs.console) return;

    const safe = typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
    refs.console.textContent = `[${title}]\n${safe}`;
  }

  async function postAjax(config, action, extra = {}) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', config.nonce || '');

    Object.entries(extra).forEach(([key, value]) => {
      fd.append(key, value);
    });

    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
    });

    const text = await response.text();
    let json = null;

    try {
      json = JSON.parse(text);
    } catch (error) {
      throw new Error(`Non-JSON response: ${text}`);
    }

    if (!response.ok || !json || json.success !== true) {
      throw new Error(json?.data?.message || `Request failed (${response.status})`);
    }

    return json.data;
  }

  return {
    setStatus,
    toast,
    logConsole,
    postAjax,
  };
})();