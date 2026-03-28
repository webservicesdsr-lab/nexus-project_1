(function(){
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
      return;
    }
    fn();
  }

  onReady(function(){
    var cfg = window.knxDriverProfile || {};
    var prefsUrl = cfg.notificationPrefsUrl || '';
    var testUrl = cfg.notificationTestUrl || '';

    var browserEl = document.getElementById('knx_browser_push_enabled');
    var ntfyEl = document.getElementById('knx_ntfy_enabled');
    var emailEl = document.getElementById('knx_email_enabled');
    var ntfyIdEl = document.getElementById('knx_ntfy_id');
    var saveBtn = document.getElementById('knxSaveNotifPrefs');
    var testBtn = document.getElementById('knxTestNtfyBtn');
    var msgEl = document.getElementById('knxSaveNotifMsg');
    var ntfyWrap = document.getElementById('knxNtfyFieldWrap');

    function showMsg(text, isError) {
      if (!msgEl) return;
      msgEl.textContent = text;
      msgEl.style.display = 'inline';
      msgEl.style.color = isError ? '#b42318' : '#0b793a';
      setTimeout(function(){
        if (msgEl) msgEl.style.display = 'none';
      }, 2600);
    }

    function syncNtfyVisibility() {
      if (!ntfyWrap || !ntfyEl) return;
      ntfyWrap.style.display = ntfyEl.checked ? 'grid' : 'none';
    }

    async function loadPrefs() {
      if (!prefsUrl) return;

      try {
        var r = await fetch(prefsUrl, {
          credentials: 'same-origin',
          cache: 'no-store'
        });

        if (!r.ok) {
          showMsg('Could not load notification settings.', true);
          return;
        }

        var j = await r.json();
        if (!j || !j.ok) {
          showMsg('Could not load notification settings.', true);
          return;
        }

        if (browserEl) browserEl.checked = (j.browser_push_enabled === '1' || j.browser_push_enabled === 1 || j.browser_push_enabled === true);
        if (ntfyEl) ntfyEl.checked = (j.ntfy_enabled === '1' || j.ntfy_enabled === 1 || j.ntfy_enabled === true);
        if (emailEl) emailEl.checked = (j.email_enabled === '1' || j.email_enabled === 1 || j.email_enabled === true);
        if (ntfyIdEl) ntfyIdEl.value = j.ntfy_id || '';

        syncNtfyVisibility();
      } catch (e) {
        console.error(e);
        showMsg('Could not load notification settings.', true);
      }
    }

    async function savePrefs() {
      if (!prefsUrl) return;

      try {
        if (saveBtn) saveBtn.disabled = true;

        var payload = {
          browser_push_enabled: !!(browserEl && browserEl.checked),
          ntfy_enabled: !!(ntfyEl && ntfyEl.checked),
          email_enabled: !!(emailEl && emailEl.checked),
          ntfy_id: ntfyIdEl ? ntfyIdEl.value.trim() : ''
        };

        var resp = await fetch(prefsUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        if (!resp.ok) {
          showMsg('Could not save notification settings.', true);
          return;
        }

        var json = await resp.json();
        if (!json || !json.ok) {
          showMsg('Could not save notification settings.', true);
          return;
        }

        syncNtfyVisibility();
        showMsg('Saved', false);
      } catch (e) {
        console.error(e);
        showMsg('Could not save notification settings.', true);
      } finally {
        if (saveBtn) saveBtn.disabled = false;
      }
    }

    async function sendTestNtfy() {
      if (!testUrl) return;

      try {
        if (testBtn) testBtn.disabled = true;

        var payload = {
          ntfy_id: ntfyIdEl ? ntfyIdEl.value.trim() : ''
        };

        var resp = await fetch(testUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        if (!resp.ok) {
          showMsg('Could not send test phone notification.', true);
          return;
        }

        var json = await resp.json();
        if (!json || !json.ok) {
          showMsg('Could not send test phone notification.', true);
          return;
        }

        showMsg('Test phone notification sent.', false);
      } catch (e) {
        console.error(e);
        showMsg('Could not send test phone notification.', true);
      } finally {
        if (testBtn) testBtn.disabled = false;
      }
    }

    if (ntfyEl) {
      ntfyEl.addEventListener('change', syncNtfyVisibility);
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', savePrefs);
    }

    if (testBtn) {
      testBtn.addEventListener('click', sendTestNtfy);
    }

    syncNtfyVisibility();
    loadPrefs();
  });
})();