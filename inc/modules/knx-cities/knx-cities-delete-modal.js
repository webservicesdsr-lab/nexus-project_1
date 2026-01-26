/* ==========================================================
   File: inc/modules/knx-cities/knx-cities-delete-modal.js
   KNX Cities — Delete Modal Controller (PROD)
   Exposes: window.knxOpenCityDeleteModal({ city_id, api, nonce, onDeleted })
   - Uses GLOBAL toast: window.knxToast(message, type)
   - No browser confirm
   - Safe listeners (no stacking)
========================================================== */

(function () {
  const MODAL_ID = "knxCityDeleteModal";
  const CANCEL_ID = "knxCityDeleteCancel";
  const CONFIRM_ID = "knxCityDeleteConfirm";
  const COUNTDOWN_ID = "knxDeleteCountdown";

  let active = {
    cityId: "",
    api: "",
    nonce: "",
    onDeleted: null,
    timer: null
  };

  function notify(message, type) {
    // Prefer your global toast function
    if (typeof window.knxToast === "function") {
      window.knxToast(message, type || "info");
      return;
    }
    // Fallback (older toast wrapper versions)
    if (window.knxToast && typeof window.knxToast.show === "function") {
      window.knxToast.show(message, type || "info");
      return;
    }
    try { console.log("[KNX]", message); } catch (_) {}
  }

  function apiPost(url, payload) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload || {})
    })
      .then(async (res) => {
        let json = null;
        try { json = await res.json(); } catch (_) {}
        return { ok: res.ok, status: res.status, json };
      });
  }

  function getModalParts() {
    const el = document.getElementById(MODAL_ID);
    if (!el) return null;

    const cancel = document.getElementById(CANCEL_ID);
    const confirm = document.getElementById(CONFIRM_ID);
    const countdownEl = document.getElementById(COUNTDOWN_ID);
    const backdrop = el.querySelector(".knx-modal-backdrop");

    return { el, cancel, confirm, countdownEl, backdrop };
  }

  function open(modal) {
    modal.el.classList.add("active");
    modal.el.style.display = "block";
    modal.el.setAttribute("aria-hidden", "false");
  }

  function close(modal) {
    modal.el.classList.remove("active");
    modal.el.style.display = "none";
    modal.el.setAttribute("aria-hidden", "true");

    // cleanup timer
    if (active.timer) {
      window.clearInterval(active.timer);
      active.timer = null;
    }

    // cleanup active payload
    active.cityId = "";
    active.api = "";
    active.nonce = "";
    active.onDeleted = null;
  }

  function removeFromUI(cityId) {
    const id = String(cityId);

    // rows (support multiple naming styles)
    const row =
      document.querySelector(`tr[data-city-id="${id}"]`) ||
      document.querySelector(`tr[data-cityid="${id}"]`) ||
      document.querySelector(`tr[data-cityId="${id}"]`) ||
      document.querySelector(`tr[data-id="${id}"]`);

    if (row) row.remove();

    // cards
    const card =
      document.querySelector(`.knx-citycard[data-city-id="${id}"]`) ||
      document.querySelector(`.knx-citycard[data-cityid="${id}"]`) ||
      document.querySelector(`.knx-citycard[data-cityId="${id}"]`);

    if (card) card.remove();
  }

  function bindOnce(modal) {
    if (modal.el.dataset.knxBound === "1") return;
    modal.el.dataset.knxBound = "1";

    // Backdrop click closes
    if (modal.backdrop) {
      modal.backdrop.addEventListener("click", () => close(modal));
    }

    // ESC closes
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        const m = document.getElementById(MODAL_ID);
        if (m && m.classList.contains("active")) {
          const parts = getModalParts();
          if (parts) close(parts);
        }
      }
    });

    // Cancel closes
    if (modal.cancel) {
      modal.cancel.addEventListener("click", () => close(modal));
    }

    // Confirm uses current "active" payload (no stacked handlers)
    if (modal.confirm) {
      modal.confirm.addEventListener("click", async () => {
        if (!active.cityId || !active.api || !active.nonce) {
          notify("Delete modal missing parameters.", "error");
          return;
        }

        modal.confirm.disabled = true;

        const { ok, json } = await apiPost(active.api, {
          city_id: active.cityId,
          knx_nonce: active.nonce
        });

        if (!ok || !json || json.success === false) {
          modal.confirm.disabled = false;
          notify((json && (json.message || json.error)) || "Delete failed", "error");
          return;
        }

        notify((json && json.message) || "City deleted", "success");

        try { removeFromUI(active.cityId); } catch (_) {}

        // Notify caller (for re-render)
        try {
          if (typeof active.onDeleted === "function") {
            active.onDeleted(String(active.cityId));
          }
        } catch (_) {}

        close(modal);
      });
    }
  }

  function startCountdown(modal, secondsStart) {
    let seconds = Number(secondsStart || 5);

    if (modal.countdownEl) modal.countdownEl.textContent = String(seconds);
    if (modal.confirm) modal.confirm.disabled = true;

    // Clear old timer if any
    if (active.timer) {
      window.clearInterval(active.timer);
      active.timer = null;
    }

    active.timer = window.setInterval(() => {
      seconds -= 1;
      if (modal.countdownEl) modal.countdownEl.textContent = String(Math.max(0, seconds));

      if (seconds <= 0) {
        window.clearInterval(active.timer);
        active.timer = null;
        if (modal.confirm) modal.confirm.disabled = false;
      }
    }, 1000);
  }

  window.knxOpenCityDeleteModal = function (opts) {
    const modal = getModalParts();
    if (!modal) {
      notify("Delete modal markup is missing.", "error");
      return;
    }

    bindOnce(modal);

    const cityId = opts && opts.city_id ? String(opts.city_id) : "";
    const api = opts && opts.api ? String(opts.api) : "";
    const nonce = opts && opts.nonce ? String(opts.nonce) : "";
    const onDeleted = opts && typeof opts.onDeleted === "function" ? opts.onDeleted : null;

    if (!cityId || !api || !nonce) {
      notify("Delete modal missing parameters.", "error");
      return;
    }

    // set active payload
    active.cityId = cityId;
    active.api = api;
    active.nonce = nonce;
    active.onDeleted = onDeleted;

    // reset state
    startCountdown(modal, 5);
    open(modal);
  };
})();
