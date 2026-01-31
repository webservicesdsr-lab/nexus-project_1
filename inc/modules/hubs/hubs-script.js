/**
 * ==========================================================
 * Kingdom Nexus - Hubs Script (v3.6)
 * ----------------------------------------------------------
 * Handles modal interactions and REST API toggle (Add / Deactivate / Reactivate)
 * Unified with global knxToast() system.
 * ----------------------------------------------------------
 * Changelog v3.6:
 * ✅ Removed local toast implementation
 * ✅ All feedback now uses global knxToast()
 * ✅ Cleaner and lighter code
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const addBtn = document.getElementById("knxAddHubBtn");
  const modal = document.getElementById("knxAddHubModal");
  const closeModal = document.getElementById("knxCloseModal");
  const confirmModal = document.getElementById("knxConfirmDeactivate");
  const cancelDeactivate = document.getElementById("knxCancelDeactivate");
  const confirmDeactivateBtn = document.getElementById("knxConfirmDeactivateBtn");

  const addForm = document.getElementById("knxAddHubForm");
  const hubsWrapper = document.querySelector(".knx-hubs-wrapper");
  const nonce = hubsWrapper?.dataset.nonce || "";

  let pendingHub = null;

  /** ------------------------------------------------------
   * Open Add Hub modal
   * ------------------------------------------------------ */
  addBtn?.addEventListener("click", () => {
    modal?.classList.add("active");
    document.body.classList.add("knx-modal-open");
  });

  /** ------------------------------------------------------
   * Close Add Hub modal
   * ------------------------------------------------------ */
  closeModal?.addEventListener("click", () => {
    modal?.classList.remove("active");
    document.body.classList.remove("knx-modal-open");
  });

  // Accessibility & UX helpers: focus management, ESC to close, prevent body scroll
  (function modalEnhancements(){
    const firstInput = addForm?.querySelector('input[name="name"]');

    // When modal opens, focus first input and trap body scroll
    addBtn?.addEventListener('click', () => {
      setTimeout(() => { firstInput?.focus(); }, 150);
      document.body.style.overflow = 'hidden';
    });

    // When modal closes, restore body scroll
    const cleanup = () => { document.body.style.overflow = ''; };
    closeModal?.addEventListener('click', cleanup);

    // Close modal on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        if (modal?.classList.contains('active')) {
          modal.classList.remove('active');
          document.body.classList.remove('knx-modal-open');
          cleanup();
        }
        if (confirmModal?.classList.contains('active')) {
          confirmModal.classList.remove('active');
        }
      }
    });
  })();

  /** ------------------------------------------------------
   * Handle toggle switch (Active / Inactive)
   * ------------------------------------------------------ */
  document.querySelectorAll(".knx-toggle-hub").forEach((toggle) => {
    toggle.addEventListener("change", (e) => {
      const tr = e.target.closest("tr");
      const id = tr.getAttribute("data-id");
      const status = e.target.checked ? "active" : "inactive";

      if (!id || !nonce) {
        knxToast("Missing Hub ID or security token.", "error");
        e.target.checked = !e.target.checked;
        return;
      }

      // Ask confirmation only when deactivating
      if (status === "inactive") {
        pendingHub = { id, nonce };
        // If a confirm modal exists in DOM, show it; otherwise fall back to native confirm
        if (confirmModal) {
          confirmModal.classList.add("active");
        } else {
          const ok = window.confirm('Are you sure you want to deactivate this hub?');
          if (ok) updateHubStatus(id, status, nonce);
          else e.target.checked = true;
        }
      } else {
        updateHubStatus(id, status, nonce);
      }
    });
  });

  /** ------------------------------------------------------
   * Cancel deactivate action
   * ------------------------------------------------------ */
  cancelDeactivate?.addEventListener("click", () => {
    confirmModal?.classList.remove("active");
    if (pendingHub) {
      const row = document.querySelector(`tr[data-id="${pendingHub.id}"]`);
      if (row) row.querySelector(".knx-toggle-hub").checked = true;
    }
  });

  /** ------------------------------------------------------
   * Confirm deactivate action
   * ------------------------------------------------------ */
  confirmDeactivateBtn?.addEventListener("click", () => {
    if (pendingHub) {
      updateHubStatus(pendingHub.id, "inactive", pendingHub.nonce);
      confirmModal?.classList.remove("active");
    }
  });

  /**
   * ==========================================================
   * Update Hub Status (Active / Inactive)
   * ==========================================================
   */
  async function updateHubStatus(id, status, nonce) {
    try {
      const res = await fetch("/wp-json/knx/v1/toggle-hub", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ hub_id: id, status, knx_nonce: nonce }),
      });

      const data = await res.json();
      console.log("Toggle response:", data);

      if (!data.success) {
        knxToast(data.error || "Error updating hub", "error");
        const row = document.querySelector(`tr[data-id="${id}"]`);
        if (row) row.querySelector(".knx-toggle-hub").checked = status === "inactive";
        return;
      }

      const row = document.querySelector(`tr[data-id="${id}"]`);
      const statusCell = row?.querySelector("td:nth-child(3) span");
      if (statusCell) {
        statusCell.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        statusCell.className = "status-" + status;
      }

      knxToast(`Hub ${status === "active" ? "activated" : "deactivated"} successfully.`, "success");
    } catch (err) {
      console.error("Fetch error:", err);
      knxToast("Connection error updating hub.", "error");
    }
  }

  /**
   * ==========================================================
   * Add Hub (Modal Form)
   * ==========================================================
   */
  addForm?.addEventListener("submit", async (e) => {
    e.preventDefault();

    const formData = new FormData(addForm);
    const name = formData.get("name")?.trim();
    const email = formData.get("email")?.trim();
    const phone = formData.get("phone")?.trim();

    if (!name || !email) {
      knxToast("Please enter at least a Name and Email.", "warning");
      return;
    }

    try {
      const res = await fetch("/wp-json/knx/v1/add-hub", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name,
          email,
          phone,
          knx_nonce: nonce,
        }),
      });

      const data = await res.json();
      console.log("Add Hub response:", data);

      if (!data.success) {
        knxToast(data.error || "Error adding hub", "error");
        return;
      }

      knxToast("Hub added successfully ✅", "success");
      setTimeout(() => location.reload(), 1200);
    } catch (err) {
      console.error("Add Hub error:", err);
      knxToast("Connection error while adding hub.", "error");
    }
  });
});
