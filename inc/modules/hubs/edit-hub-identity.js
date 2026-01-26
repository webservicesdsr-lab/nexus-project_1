/**
 * ==========================================================
 * Kingdom Nexus - Edit Hub Identity Script (v4.3 Production)
 * ----------------------------------------------------------
 * Updates hub identity information via REST:
 * ✅ Supports City ID, Email, Phone, and Status
 * ✅ Secure nonce + unified knxToast()
 * ✅ Fully compatible with api-get-hub.php & api-edit-hub-identity.php
 * ==========================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.querySelector(".knx-edit-hub-wrapper");
  if (!wrapper) return;

  const getApi = wrapper.dataset.apiGet;
  const apiUrl = wrapper.dataset.apiIdentity;
  const hubId = wrapper.dataset.hubId;
  const nonce = wrapper.dataset.nonce;
  const wpNonce = wrapper.dataset.wpNonce;

  const nameInput = document.getElementById("hubName");
  const phoneInput = document.getElementById("hubPhone");
  const emailInput = document.getElementById("hubEmail");
  const statusSelect = document.getElementById("hubStatus");
  const citySelect = document.getElementById("hubCity");
  const categorySelect = document.getElementById("hubCategory");
  const featuredToggle = document.getElementById("hubFeatured");
  const featuredStatusText = document.getElementById("featured-status-text");
  const featuredCountBadge = document.getElementById("featured-count");
  const saveBtn = document.getElementById("saveIdentity");

  /**
   * ----------------------------------------------------------
   * Load featured count on init
   * ----------------------------------------------------------
   */
  async function loadFeaturedCount() {
    try {
      const res = await fetch('/wp-json/knx/v1/explore-hubs?featured=1');
      const data = await res.json();
      if (data.success && featuredCountBadge) {
        featuredCountBadge.textContent = `${data.hubs.length} featured hubs`;
      }
    } catch (err) {
      console.error('Load featured count error:', err);
    }
  }

  /**
   * ----------------------------------------------------------
   * Toggle featured status
   * ----------------------------------------------------------
   */
  if (featuredToggle) {
    featuredToggle.addEventListener('change', async function() {
      const isChecked = this.checked;
      
      try {
        const res = await fetch('/wp-json/knx/v1/toggle-featured', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': wpNonce
          },
          body: JSON.stringify({ hub_id: parseInt(hubId) })
        });
        
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        
        const data = await res.json();
        
        if (data.success) {
          if (featuredStatusText) {
            featuredStatusText.textContent = data.is_featured ? 'Featured' : 'Not Featured';
          }
          if (featuredCountBadge) {
            featuredCountBadge.textContent = `${data.featured_count} featured hubs`;
          }
          knxToast(data.message, 'success');
        } else {
          throw new Error(data.message || data.code || 'Failed to update');
        }
      } catch (error) {
        console.error('Toggle featured error:', error);
        knxToast(error.message || 'Failed to update featured status', 'error');
        // Revert toggle
        featuredToggle.checked = !isChecked;
      }
    });
  }

  /**
   * ----------------------------------------------------------
   * Load hub data
   * ----------------------------------------------------------
   */
  async function loadHubData() {
    try {
      const res = await fetch(`${getApi}?id=${hubId}`);
      const data = await res.json();

      if (data.success && data.hub) {
        const hub = data.hub;
        nameInput.value = hub.name || "";
        phoneInput.value = hub.phone || "";
        emailInput.value = hub.email || "";
        statusSelect.value = hub.status || "active";

        if (citySelect && hub.city_id) citySelect.value = hub.city_id;
        if (categorySelect && hub.category_id) categorySelect.value = hub.category_id;
        if (featuredToggle) featuredToggle.checked = hub.is_featured == 1;
        if (featuredStatusText) featuredStatusText.textContent = hub.is_featured == 1 ? 'Featured' : 'Not Featured';
      } else {
        knxToast("Unable to load hub data", "error");
      }
    } catch {
      knxToast("Network error while loading hub data", "error");
    }
  }

  // Initialize
  loadHubData();
  loadFeaturedCount();

  /**
   * ----------------------------------------------------------
   * Save hub identity
   * ----------------------------------------------------------
   */
  saveBtn.addEventListener("click", async () => {
    const payload = {
      hub_id: parseInt(hubId),
      city_id: citySelect ? parseInt(citySelect.value) || 0 : 0,
      category_id: categorySelect ? parseInt(categorySelect.value) || 0 : 0,
      email: emailInput.value.trim(),
      phone: phoneInput.value.trim(),
      status: statusSelect.value,
      knx_nonce: nonce,
    };

    if (!payload.email) {
      knxToast("Email is required", "error");
      return;
    }

    try {
      const res = await fetch(apiUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const data = await res.json();

      if (data.success) {
        knxToast(data.message || "Hub identity updated successfully", "success");
        loadHubData();
      } else {
        const msg =
          data.error === "invalid_city"
            ? "Invalid or inactive city selected"
            : data.error === "unauthorized"
            ? "Access denied"
            : "Update failed";
        knxToast(msg, "error");
      }
    } catch {
      knxToast("Connection error saving identity", "error");
    }
  });

  loadHubData();
});
