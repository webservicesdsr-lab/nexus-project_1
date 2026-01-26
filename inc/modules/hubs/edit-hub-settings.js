/**
 * ==========================================================
 * Kingdom Nexus - Edit Hub Settings JS (v1.0)
 * ----------------------------------------------------------
 * Handles Timezone (Choices.js autocomplete), Currency,
 * Tax & Min Order save via REST API.
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("knxSaveSettingsBtn");
  if (!btn) return;

  /** Initialize Choices.js for Timezone select */
  if (window.Choices) {
    new Choices("#timezone", {
      searchEnabled: true,
      shouldSort: false,
      placeholder: true,
      itemSelectText: "",
      position: "bottom",
    });
  }

  btn.addEventListener("click", async () => {
    const hubId = btn.dataset.hubId;
    const nonce = btn.dataset.nonce;

    const timezone  = document.getElementById("timezone").value;
    const currency  = document.getElementById("currency").value;
    const tax_rate  = document.getElementById("tax_rate").value || 0;
    const min_order = document.getElementById("min_order").value || 0;

    const payload = {
      hub_id: hubId,
      knx_nonce: nonce,
      timezone,
      currency,
      tax_rate,
      min_order
    };

    console.log('💾 Saving settings:', payload);

    try {
      const res = await fetch(`/wp-json/knx/v1/update-hub-settings`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const data = await res.json();

      console.log('📥 API response:', data);

      if (data.success) {
        knxToast("✅ Settings updated successfully!", "success");
      } else {
        knxToast("⚠️ Error: " + (data.error || "Failed to update"), "error");
      }
    } catch (err) {
      knxToast("⚠️ Network error", "error");
    }
  });
});
