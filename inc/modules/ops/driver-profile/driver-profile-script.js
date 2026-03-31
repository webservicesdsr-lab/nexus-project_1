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

    // Change password handler
    (function(){
      var cfg = window.knxDriverProfile || {};
      var form = document.getElementById('knxChangePasswordForm');
      var msgEl = document.getElementById('knxPasswordMessage');
      var btn = document.getElementById('knxChangePasswordBtn');

      function setMsg(text, isError) {
        if (!msgEl) return;
        msgEl.textContent = text || '';
        msgEl.style.display = text ? 'block' : 'none';
        msgEl.style.color = isError ? '#b42318' : '#0b793a';
      }

      if (!form || !cfg.changePasswordUrl) return;

      form.addEventListener('submit', async function(ev){
        ev.preventDefault();
        var current = (document.getElementById('current_password') || {}).value || '';
        var nw = (document.getElementById('new_password') || {}).value || '';
        var confirm = (document.getElementById('confirm_password') || {}).value || '';

        // Basic client validation
        if (!current) { setMsg('Current password is required', true); return; }
        if (!nw || nw.length < 8) { setMsg('New password must be at least 8 characters', true); return; }
        if (nw !== confirm) { setMsg('Passwords do not match', true); return; }
        if (current === nw) { setMsg('New password must be different from current password', true); return; }

        try {
          if (btn) btn.disabled = true;
          setMsg('Saving...', false);

          var payload = {
            current_password: current,
            new_password: nw,
            confirm_password: confirm,
            knx_nonce: cfg.knxNonce || ''
          };

          var headers = {
            'Content-Type': 'application/json'
          };
          if (cfg.wpRestNonce) headers['X-WP-Nonce'] = cfg.wpRestNonce;

          var resp = await fetch(cfg.changePasswordUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(payload)
          });

          if (!resp.ok) {
            // try to parse error message
            var txt = await resp.text();
            setMsg('Error saving password: ' + (txt || resp.statusText), true);
            return;
          }

          var json = await resp.json();
          if (!json) {
            setMsg('Unexpected server response', true);
            return;
          }

          if (json.success) {
            setMsg(json.message || 'Password changed', false);
            // Clear inputs
            try { document.getElementById('current_password').value = ''; } catch(e){}
            try { document.getElementById('new_password').value = ''; } catch(e){}
            try { document.getElementById('confirm_password').value = ''; } catch(e){}
          } else {
            setMsg(json.message || 'Could not change password', true);
          }

        } catch (err) {
          console.error(err);
          setMsg('Network or server error while changing password', true);
        } finally {
          if (btn) btn.disabled = false;
        }
      });
    })();

    // Change username handler
    (function(){
      var cfg = window.knxDriverProfile || {};
      var form = document.getElementById('knxChangeUsernameForm');
      var msgEl = document.getElementById('knxUsernameMessage');
      var btn = document.getElementById('knxChangeUsernameBtn');

      function setMsg(text, isError) {
        if (!msgEl) return;
        msgEl.textContent = text || '';
        msgEl.style.display = text ? 'block' : 'none';
        msgEl.style.color = isError ? '#b42318' : '#0b793a';
      }

      if (!form || !cfg.changeUsernameUrl) return;

      form.addEventListener('submit', async function(ev){
        ev.preventDefault();
        var newUsername = (document.getElementById('new_username') || {}).value || '';
        var current = (document.getElementById('username_current_password') || {}).value || '';

        if (!newUsername || newUsername.length < 3) { setMsg('Username must be at least 3 characters', true); return; }
        // Basic client-side chars check
        if (!/^[A-Za-z0-9_-]+$/.test(newUsername)) { setMsg('Username contains invalid characters', true); return; }
        if (!current) { setMsg('Current password is required', true); return; }

        try {
          if (btn) btn.disabled = true;
          setMsg('Saving...', false);

          var payload = {
            new_username: newUsername,
            current_password: current,
            knx_nonce: cfg.knxNonce || ''
          };

          var headers = {
            'Content-Type': 'application/json'
          };
          if (cfg.wpRestNonce) headers['X-WP-Nonce'] = cfg.wpRestNonce;

          var resp = await fetch(cfg.changeUsernameUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(payload)
          });

          if (!resp.ok) {
            var txt = await resp.text();
            setMsg('Error saving username: ' + (txt || resp.statusText), true);
            return;
          }

          var json = await resp.json();
          if (!json) { setMsg('Unexpected server response', true); return; }

          if (json.success) {
            setMsg(json.message || 'Username changed', false);
            // Optionally update displayed name/username in the UI
            try { var displayEl = document.querySelector('.knx-profile__contact'); if (displayEl) { displayEl.textContent = (displayEl.textContent || '').replace(/^[^\u00B7]*/, '').trim(); } } catch(e){}
          } else {
            setMsg(json.message || 'Could not change username', true);
          }

        } catch (err) {
          console.error(err);
          setMsg('Network or server error while changing username', true);
        } finally {
          if (btn) btn.disabled = false;
        }
      });
    })();
  });
})();