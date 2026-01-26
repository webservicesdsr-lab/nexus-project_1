/**
 * Kingdom Nexus - Admin Settings JS (v4.0 CANONICAL)
 * --------------------------------------------------
 * Handles Google Maps API key management through WordPress REST API.
 * Features:
 * - Save API key with validation
 * - Clear API key with confirmation
 * - Live map preview
 * - Toast notifications
 * - Uses wp_options for storage
 */

document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector("#knxSettingsForm");
  const clearBtn = document.querySelector("#knxClearKey");
  const toast = document.querySelector("#knxToast");
  const mapCard = document.querySelector("#mapCard");
  const mapContainer = document.querySelector("#knxMapPreview");

  if (!form) return;

  // Save API key
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const api = document.querySelector("#knxApiUrl").value;
    const nonce = document.querySelector("#knxNonce").value;
    const google_maps_key = document.querySelector("#google_maps_key").value.trim();

    if (!google_maps_key) {
      showToast("⚠️ Please enter your Google Maps API key", "error");
      return;
    }

    try {
      const res = await fetch(api, {
        method: "POST",
        headers: { 
          "Content-Type": "application/json",
          "X-WP-Nonce": nonce
        },
        body: JSON.stringify({ google_maps_api: google_maps_key }),
      });

      const data = await res.json();

      if (data.success) {
        showToast("✅ Settings saved successfully", "success");
        loadMapPreview(google_maps_key);
        setTimeout(() => location.reload(), 1500); // Reload to show new status
      } else {
        showToast("⚠️ " + (data.error || "Error saving settings"), "error");
      }
    } catch (err) {
      showToast("❌ Network or permission error", "error");
    }
  });

  // Clear API key
  if (clearBtn) {
    clearBtn.addEventListener("click", async () => {
      if (!confirm("Clear Google Maps API key?\n\nThe system will fallback to OpenStreetMap (Leaflet).")) {
        return;
      }

      const api = document.querySelector("#knxApiUrl").value;
      const nonce = document.querySelector("#knxNonce").value;

      try {
        const res = await fetch(api, {
          method: "POST",
          headers: { 
            "Content-Type": "application/json",
            "X-WP-Nonce": nonce
          },
          body: JSON.stringify({ google_maps_api: "" }), // Empty string clears key
        });

        const data = await res.json();

        if (data.success) {
          showToast("✅ API key cleared - Leaflet will be used", "success");
          setTimeout(() => location.reload(), 1500);
        } else {
          showToast("⚠️ " + (data.error || "Error clearing key"), "error");
        }
      } catch (err) {
        showToast("❌ Network error", "error");
      }
    });
  }

  const existingKey = document.querySelector("#google_maps_key")?.value?.trim();
  if (existingKey) loadMapPreview(existingKey);

  function loadMapPreview(apiKey) {
    mapCard.style.display = "block";
    mapContainer.innerHTML = "";

    // Remove any previous Google script
    document.querySelectorAll("script[src*='maps.googleapis']").forEach(s => s.remove());

    const script = document.createElement("script");
    script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&callback=initMapPreview&libraries=places`;
    script.async = true;

    window.initMapPreview = function () {
      const map = new google.maps.Map(mapContainer, {
        center: { lat: 41.12, lng: -87.86 },
        zoom: 12,
        disableDefaultUI: false
      });

      new google.maps.Marker({
        position: { lat: 41.12, lng: -87.86 },
        map,
        title: "Kankakee, IL",
      });
    };

    script.onerror = () => {
      showToast("❌ Invalid or restricted API key", "error");
      mapCard.style.display = "none";
    };

    document.head.appendChild(script);
  }

  function showToast(message, type) {
    toast.innerText = message;
    toast.style.display = "block";
    toast.style.position = "fixed";
    toast.style.bottom = "30px";
    toast.style.right = "30px";
    toast.style.padding = "10px 16px";
    toast.style.borderRadius = "6px";
    toast.style.fontWeight = "600";
    toast.style.transition = "all 0.4s ease";
    toast.style.background = type === "success" ? "#0B793A" : "#cc0000";
    toast.style.color = "#fff";
    setTimeout(() => (toast.style.display = "none"), 2500);
  }
});
