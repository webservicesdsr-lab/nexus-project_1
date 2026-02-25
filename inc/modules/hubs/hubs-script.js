/**
 * ==========================================================
 * Kingdom Nexus - Hubs Script (v4.0)
 * ----------------------------------------------------------
 * ✅ Works for BOTH desktop table rows and mobile cards
 * ✅ Add Hub modal (FormData) using wrapper dataset endpoints/nonces
 * ✅ Toggle with confirm modal on deactivate
 * ✅ Updates status pill in the same container
 * ✅ Uses knxToast() (global)
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.querySelector(".knx-hubs-wrapper");
  if (!wrapper) return;

  const API = {
    add: wrapper.dataset.apiAdd || "",
    toggle: wrapper.dataset.apiToggle || "",
    nonceAdd: wrapper.dataset.nonceAdd || "",
    nonceToggle: wrapper.dataset.nonceToggle || "",
  };

  const addBtn = document.getElementById("knxAddHubBtn");
  const modal = document.getElementById("knxAddHubModal");
  const closeModalBtn = document.getElementById("knxCloseModal");
  const addForm = document.getElementById("knxAddHubForm");
  const firstInput = addForm?.querySelector('input[name="name"]');

  const confirmModal = document.getElementById("knxConfirmDeactivate");
  const cancelDeactivateBtn = document.getElementById("knxCancelDeactivate");
  const confirmDeactivateBtn = document.getElementById("knxConfirmDeactivateBtn");

  let pendingToggle = null; // { containerEl, checkboxEl, id }

  function toast(msg, type = "info") {
    if (typeof window.knxToast === "function") return window.knxToast(msg, type);
    if (typeof window.knx_toast === "function") return window.knx_toast(msg, type);
    console.log("[KNX HUBS]", type, msg);
  }

  function openModal() {
    if (!modal) return;
    modal.classList.add("active");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("knx-modal-open");
    document.body.style.overflow = "hidden";
    setTimeout(() => firstInput?.focus(), 120);
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("knx-modal-open");
    document.body.style.overflow = "";
    addBtn?.focus();
  }

  function openConfirm() {
    if (!confirmModal) return;
    confirmModal.classList.add("active");
    confirmModal.setAttribute("aria-hidden", "false");
  }

  function closeConfirm() {
    if (!confirmModal) return;
    confirmModal.classList.remove("active");
    confirmModal.setAttribute("aria-hidden", "true");
  }

  function setStatusPill(containerEl, status) {
    if (!containerEl) return;
    containerEl.dataset.status = status;

    const pillHost =
      containerEl.querySelector(".knx-status-cell") ||
      containerEl.querySelector("td:nth-child(3)") ||
      containerEl;

    const pill = pillHost.querySelector(".status-active, .status-inactive");
    if (pill) {
      pill.textContent = status.charAt(0).toUpperCase() + status.slice(1);
      pill.className = "status-" + status;
    }
  }

  async function doToggle(id, status, checkboxEl, containerEl) {
    try {
      const payload = {
        id: String(id),
        hub_id: String(id),
        status: status,
        nonce: API.nonceToggle,
        knx_nonce: API.nonceToggle,
      };

      const res = await fetch(API.toggle, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const out = await res.json();

      if (!out || out.success !== true) {
        toast(out?.error || out?.message || "Toggle failed", "error");
        if (checkboxEl) checkboxEl.checked = !checkboxEl.checked;
        return;
      }

      setStatusPill(containerEl, status);
      toast(`Hub ${status === "active" ? "activated" : "deactivated"} successfully.`, "success");
    } catch (err) {
      console.error("Toggle error:", err);
      toast("Network error toggling hub", "error");
      if (checkboxEl) checkboxEl.checked = !checkboxEl.checked;
    }
  }

  // ---- Add Hub modal
  addBtn?.addEventListener("click", openModal);
  closeModalBtn?.addEventListener("click", closeModal);

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    if (modal?.classList.contains("active")) closeModal();
    if (confirmModal?.classList.contains("active")) {
      // if confirm is open, cancel and restore checkbox
      if (pendingToggle?.checkboxEl) pendingToggle.checkboxEl.checked = true;
      pendingToggle = null;
      closeConfirm();
    }
  });

  addForm?.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (!API.add || !API.nonceAdd) {
      toast("Missing add endpoint or nonce.", "error");
      return;
    }

    const data = new FormData(addForm);
    data.append("knx_nonce", API.nonceAdd);

    try {
      const res = await fetch(API.add, { method: "POST", body: data });
      const out = await res.json();

      if (out && out.success) {
        toast("Hub added successfully", "success");
        setTimeout(() => location.reload(), 800);
      } else {
        toast(out?.error || out?.message || "Error adding hub", "error");
      }
    } catch (err) {
      console.error("Add Hub error", err);
      toast("Network error while adding hub", "error");
    }
  });

  // ---- Toggle (delegated; works for table + cards)
  wrapper.addEventListener("change", (e) => {
    const checkbox = e.target.closest(".knx-toggle-hub");
    if (!checkbox) return;

    const container = checkbox.closest("[data-id]");
    const id = container?.dataset?.id;
    if (!id) {
      toast("Missing Hub ID.", "error");
      checkbox.checked = !checkbox.checked;
      return;
    }

    const status = checkbox.checked ? "active" : "inactive";

    // confirm only on deactivate
    if (status === "inactive") {
      pendingToggle = { containerEl: container, checkboxEl: checkbox, id: id };
      openConfirm();
      return;
    }

    doToggle(id, status, checkbox, container);
  });

  // ---- Confirm modal actions
  cancelDeactivateBtn?.addEventListener("click", () => {
    if (pendingToggle?.checkboxEl) pendingToggle.checkboxEl.checked = true;
    pendingToggle = null;
    closeConfirm();
  });

  confirmDeactivateBtn?.addEventListener("click", async () => {
    if (!pendingToggle) {
      closeConfirm();
      return;
    }

    const { id, checkboxEl, containerEl } = pendingToggle;
    pendingToggle = null;
    closeConfirm();

    await doToggle(id, "inactive", checkboxEl, containerEl);
  });

  // close modal if clicking overlay
  modal?.addEventListener("click", (e) => {
    if (e.target === modal) closeModal();
  });

  confirmModal?.addEventListener("click", (e) => {
    if (e.target === confirmModal) {
      if (pendingToggle?.checkboxEl) pendingToggle.checkboxEl.checked = true;
      pendingToggle = null;
      closeConfirm();
    }
  });
});