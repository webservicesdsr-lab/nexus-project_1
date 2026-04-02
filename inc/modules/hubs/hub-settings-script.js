/**
 * ==========================================================
 * KNX Hub Management — Settings JS Controller (v1.0)
 * ----------------------------------------------------------
 * Handles: Identity save, Logo upload, Hours save, Closure
 *
 * Food truck locations CRUD is handled separately by ft-locations-script.js
 *
 * Reads data-* attributes from .knx-edit-hub-wrapper
 * ==========================================================
 */
(function () {
    'use strict';

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        const wrap = document.querySelector('.knx-edit-hub-wrapper');
        if (!wrap) return;

        const hubId   = wrap.dataset.hubId;
        const nonce   = wrap.dataset.nonce;
        const wpNonce = wrap.dataset.wpNonce;

        const api = {
            identity:  wrap.dataset.apiIdentity,
            hours:     wrap.dataset.apiHours,
            closure:   wrap.dataset.apiClosure,
            logo:      wrap.dataset.apiLogo,
        };

        // ── Identity ──────────────────────────────────────
        const saveIdentityBtn = document.getElementById('saveIdentityBtn');
        if (saveIdentityBtn) {
            saveIdentityBtn.addEventListener('click', async () => {
                const body = {
                    hub_id:    hubId,
                    knx_nonce: nonce,
                    name:      val('hubName'),
                    phone:     val('hubPhone'),
                    email:     val('hubEmail'),
                };

                saveIdentityBtn.disabled = true;
                try {
                    const res = await postJSON(api.identity, body, wpNonce);
                    if (res.success) {
                        toast('success', 'Identity saved');
                    } else {
                        toast('error', res.error || res.message || 'Save failed');
                    }
                } catch (e) {
                    toast('error', 'Network error');
                }
                saveIdentityBtn.disabled = false;
            });
        }

        // ── Hours ─────────────────────────────────────────
        // ── Closure ───────────────────────────────────────
        const closureToggle = document.getElementById('closureToggle');
        const closureDetails = document.getElementById('closureDetails');
        const closureType = document.getElementById('closureType');
        const closureReopenGroup = document.getElementById('closureReopenGroup');

        if (closureToggle && closureDetails) {
            closureToggle.addEventListener('change', () => {
                closureDetails.style.display = closureToggle.checked ? '' : 'none';
                document.getElementById('closureStatusText').textContent =
                    closureToggle.checked ? 'Hub is temporarily closed' : 'Hub is open';
            });
        }

        if (closureType && closureReopenGroup) {
            closureType.addEventListener('change', () => {
                closureReopenGroup.style.display = closureType.value === 'temporary' ? '' : 'none';
            });
        }

        const saveClosureBtn = document.getElementById('saveClosureBtn');
        if (saveClosureBtn) {
            saveClosureBtn.addEventListener('click', async () => {
                const isClosed = closureToggle?.checked ? 1 : 0;

                saveClosureBtn.disabled = true;
                try {
                    const res = await postJSON(api.closure, {
                        hub_id:         hubId,
                        knx_nonce:      nonce,
                        is_closed:      isClosed,
                        closure_type:   closureType?.value || 'indefinite',
                        closure_reason: document.getElementById('closureReason')?.value || '',
                        reopen_date:    document.getElementById('closureReopenDate')?.value || '',
                        reopen_time:    document.getElementById('closureReopenTime')?.value || '',
                    }, wpNonce);
                    if (res.success) {
                        toast('success', 'Closure settings saved');
                    } else {
                        toast('error', res.error || 'Save failed');
                    }
                } catch (e) {
                    toast('error', 'Network error');
                }
                saveClosureBtn.disabled = false;
            });
        }

    }

    // ── Helpers ────────────────────────────────────────────
    function val(id) {
        const el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }

    async function postJSON(url, data, wpNonce) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpNonce,
            },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });
        return res.json();
    }

    function toast(type, msg) {
        if (typeof window.knxToast === 'function') {
            window.knxToast(type, msg);
        } else if (typeof window.KnxToast !== 'undefined' && typeof window.KnxToast.show === 'function') {
            window.KnxToast.show(type, msg);
        } else {
            // Fallback: simple notification
            const el = document.createElement('div');
            el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;color:#fff;font-weight:600;font-size:14px;box-shadow:0 4px 16px rgba(0,0,0,0.15);transition:opacity 0.3s;';
            el.style.background = type === 'success' ? '#0b793a' : type === 'error' ? '#dc2626' : '#f59e0b';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
        }
    }
})();
