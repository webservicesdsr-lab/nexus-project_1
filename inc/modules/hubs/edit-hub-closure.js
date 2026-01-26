document.addEventListener("DOMContentLoaded", () => {
  const toggle = document.querySelector("#closureToggle");
  const typeSelect = document.querySelector("#closureType");
  const reason = document.querySelector("#closureReason");
  const reopenWrapper = document.querySelector("#reopenWrapper");
  const reopenDate = document.querySelector("#reopenDate");
    const reopenHour = document.getElementById("reopenHour");
    const reopenMinute = document.getElementById("reopenMinute");
    const reopenAMPM = document.getElementById("reopenAMPM");
  const saveBtn = document.querySelector("#saveClosureBtn");

  if (!toggle || !saveBtn) return;

  toggle.addEventListener("change", () => {
    typeSelect.disabled = !toggle.checked;
    reason.disabled = !toggle.checked;
    if (!toggle.checked) {
      typeSelect.value = "";
      reopenWrapper.style.display = "none";
    }
  });

  typeSelect.addEventListener("change", () => {
    reopenWrapper.style.display = typeSelect.value === "temporary" ? "block" : "none";
  });

  saveBtn.addEventListener("click", async () => {
    const hub_id = saveBtn.dataset.hubId;
    // Usar el nonce correcto del botón o window.knx_edit_hub.nonce
    const knx_nonce = saveBtn.dataset.nonce || (window.knx_edit_hub && window.knx_edit_hub.nonce) || "";
      // Construir reopen_time desde los campos individuales
      let reopenTime = "";
      if (
        typeSelect.value === "temporary" &&
        reopenHour && reopenMinute && reopenAMPM &&
        reopenHour.value && reopenMinute.value && reopenAMPM.value
      ) {
        reopenTime = `${reopenHour.value}:${reopenMinute.value} ${reopenAMPM.value}`;
      }

    const payload = {
      hub_id,
      knx_nonce,
      is_closed: toggle.checked ? 1 : 0,
      closure_type: typeSelect.value,
      closure_reason: reason.value,
      reopen_date: reopenDate.value,
      reopen_time: reopenTime
    };

    try {
      const res = await fetch(`${knx_api.root}knx/v1/update-closure`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const result = await res.json();
      if (result.success) knxToast("Closure settings updated!", "success");
      else knxToast(result.error || "Error saving closure", "error");
    } catch (err) {
      console.error(err);
      knxToast("Network error", "error");
    }
  });
});
