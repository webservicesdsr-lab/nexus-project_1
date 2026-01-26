/**
 * ==========================================================
 * Kingdom Nexus — KNX Edit City Script (SEALED v3)
 * ----------------------------------------------------------
 * City Info:
 * - Loads city data via v2 SEALED GET endpoint
 * - Saves city updates via v2 SEALED POST endpoint
 *
 * Delivery Rates:
 * - Loads rates via v2 GET endpoint
 * - Saves rates via v2 POST endpoint (UPSERT unique city_id)
 *
 * UX:
 * - Uses knxToast() when available
 * - Keeps session cookies with credentials: "same-origin"
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const cityWrapper = document.querySelector(".knx-edit-city-wrapper");
  const ratesWrapper = document.querySelector(".knx-edit-city-rates-wrapper");

  if (!cityWrapper) return;

  const toast = (msg, type) => {
    if (typeof window.knxToast === "function") return window.knxToast(msg, type);
    console[type === "error" ? "error" : "log"](msg);
    alert(msg);
  };

  const safeJson = async (res) => {
    try {
      return await res.json();
    } catch {
      return null;
    }
  };

  /**
   * Normalize payload from endpoints:
   * - Some endpoints return { success:true, data:{...} }
   * - Others return { success:true, ... }
   */
  const unwrap = (json) => {
    if (!json || typeof json !== "object") return null;
    if (json.data && typeof json.data === "object") return json.data;
    return json;
  };

  /* ==========================================================
   * City Info
   * ========================================================== */
  const apiCityGet = cityWrapper.dataset.apiGet || "";
  const apiCityUpdate = cityWrapper.dataset.apiUpdate || "";
  const cityId = parseInt(cityWrapper.dataset.cityId || "0", 10);
  const nonce = cityWrapper.dataset.nonce || "";

  const nameInput = document.getElementById("cityName");
  const statusSelect = document.getElementById("cityStatus");
  const saveCityBtn = document.getElementById("saveCity");

  async function loadCity() {
    if (!apiCityGet || !cityId) {
      toast("Invalid city configuration", "error");
      return;
    }

    try {
      const res = await fetch(`${apiCityGet}?city_id=${encodeURIComponent(cityId)}`, {
        method: "GET",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });

      const json = await safeJson(res);
      const data = unwrap(json);

      if (!res.ok) {
        toast((json && json.error) ? json.error : "Unable to load city", "error");
        return;
      }

      // Expected shape: { success:true, city:{...} }
      if (json && json.success && json.city) {
        if (nameInput) nameInput.value = json.city.name || "";
        if (statusSelect) statusSelect.value = json.city.status || "active";
        return;
      }

      // Fallback if response shape changes
      if (data && data.city) {
        if (nameInput) nameInput.value = data.city.name || "";
        if (statusSelect) statusSelect.value = data.city.status || "active";
        return;
      }

      toast((json && json.error) ? json.error : "Unable to load city", "error");
    } catch {
      toast("Network error while loading city", "error");
    }
  }

  async function saveCity() {
    if (!apiCityUpdate || !cityId) {
      toast("Invalid city configuration", "error");
      return;
    }

    const payload = {
      city_id: cityId,
      name: (nameInput ? nameInput.value : "").trim(),
      status: statusSelect ? statusSelect.value : "active",
      knx_nonce: nonce,
    };

    if (!payload.name) {
      toast("City name is required", "error");
      return;
    }

    const originalText = saveCityBtn ? saveCityBtn.textContent : "";
    if (saveCityBtn) {
      saveCityBtn.disabled = true;
      saveCityBtn.textContent = "Saving...";
    }

    try {
      const res = await fetch(apiCityUpdate, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(payload),
      });

      const json = await safeJson(res);

      if (!res.ok) {
        toast((json && json.error) ? json.error : "Update failed", "error");
        return;
      }

      if (json && json.success) {
        toast(json.message || "✅ City updated successfully", "success");
      } else {
        toast((json && json.error) ? json.error : "Update failed", "error");
      }
    } catch {
      toast("Network error while saving city", "error");
    } finally {
      if (saveCityBtn) {
        saveCityBtn.disabled = false;
        saveCityBtn.textContent = originalText || "Save City";
      }
    }
  }

  if (saveCityBtn) saveCityBtn.addEventListener("click", saveCity);

  /* ==========================================================
   * Delivery Rates (NO min_order)
   * ========================================================== */
  if (ratesWrapper) {
    const apiRatesGet = ratesWrapper.dataset.apiGet || "";
    const apiRatesUpdate = ratesWrapper.dataset.apiUpdate || "";
    const ratesCityId = parseInt(ratesWrapper.dataset.cityId || "0", 10);
    const ratesNonce = ratesWrapper.dataset.nonce || "";

    const flatRateInput = document.getElementById("knxFlatRate");
    const perDistanceInput = document.getElementById("knxRatePerDistance");
    const unitSelect = document.getElementById("knxDistanceUnit");
    const statusHidden = document.getElementById("knxRatesStatus");
    const updateBtn = document.getElementById("knxUpdateRates");

    const setBusy = (isBusy) => {
      if (!updateBtn) return;
      updateBtn.disabled = !!isBusy;
      if (isBusy) {
        updateBtn.dataset.originalText = updateBtn.textContent;
        updateBtn.textContent = "Updating...";
      } else {
        updateBtn.textContent = updateBtn.dataset.originalText || "Update";
      }
    };

    const moneyClean = (v) => {
      const raw = String(v ?? "").trim();
      if (!raw) return "0.00";

      const cleaned = raw.replace(/[^0-9.]/g, "");
      if (!cleaned) return "0.00";

      const num = Number(cleaned);
      if (Number.isNaN(num)) return "0.00";

      return Math.max(0, num).toFixed(2);
    };

    async function loadRates() {
      if (!apiRatesGet || !ratesCityId) return;

      try {
        const res = await fetch(`${apiRatesGet}?city_id=${encodeURIComponent(ratesCityId)}`, {
          method: "GET",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });

        const json = await safeJson(res);
        const data = unwrap(json);

        if (!res.ok) {
          toast((json && json.error) ? json.error : "Unable to load delivery rates", "error");
          return;
        }

        if (!json || !json.success || !data) {
          toast((json && json.error) ? json.error : "Unable to load delivery rates", "error");
          return;
        }

        if (flatRateInput) flatRateInput.value = data.flat_rate ?? "0.00";
        if (perDistanceInput) perDistanceInput.value = data.rate_per_distance ?? "0.00";
        if (unitSelect) unitSelect.value = data.distance_unit === "kilometer" ? "kilometer" : "mile";
        if (statusHidden) statusHidden.value = data.status ?? "active";

        // Optional: beautify select if Choices exists
        if (window.Choices && unitSelect && !unitSelect.dataset.choicesApplied) {
          unitSelect.dataset.choicesApplied = "1";
          new window.Choices(unitSelect, {
            searchEnabled: false,
            shouldSort: false,
            itemSelectText: "",
          });
        }
      } catch {
        toast("Network error while loading delivery rates", "error");
      }
    }

    async function saveRates() {
      if (!apiRatesUpdate || !ratesCityId) {
        toast("Invalid delivery rates configuration", "error");
        return;
      }

      const payload = {
        city_id: ratesCityId,
        flat_rate: moneyClean(flatRateInput ? flatRateInput.value : "0"),
        rate_per_distance: moneyClean(perDistanceInput ? perDistanceInput.value : "0"),
        distance_unit: unitSelect ? unitSelect.value : "mile",
        status: statusHidden ? statusHidden.value : "active",
        knx_nonce: ratesNonce,
      };

      setBusy(true);

      try {
        const res = await fetch(apiRatesUpdate, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify(payload),
        });

        const json = await safeJson(res);
        const data = unwrap(json);

        if (!res.ok) {
          toast((json && json.error) ? json.error : "Unable to update delivery rates", "error");
          return;
        }

        if (!json || !json.success || !data) {
          toast((json && json.error) ? json.error : "Unable to update delivery rates", "error");
          return;
        }

        // Sync inputs with saved DB truth
        if (flatRateInput) flatRateInput.value = data.flat_rate ?? payload.flat_rate;
        if (perDistanceInput) perDistanceInput.value = data.rate_per_distance ?? payload.rate_per_distance;
        if (unitSelect) unitSelect.value = data.distance_unit === "kilometer" ? "kilometer" : "mile";
        if (statusHidden) statusHidden.value = data.status ?? payload.status;

        toast(data.message || "✅ Delivery rates updated successfully", "success");
      } catch {
        toast("Network error while updating delivery rates", "error");
      } finally {
        setBusy(false);
      }
    }

    if (updateBtn) updateBtn.addEventListener("click", saveRates);

    loadRates();
  }

  loadCity();
});
