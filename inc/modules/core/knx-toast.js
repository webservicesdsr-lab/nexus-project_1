/**
 * ==========================================================
 * Kingdom Nexus - Global Toast System (v1.0)
 * ----------------------------------------------------------
 * Simple and reusable notification component.
 * Available globally via knxToast(message, type)
 * Types: success | error | info | warning
 * ==========================================================
 */

(function() {
  // Create container if missing
  let container = document.getElementById("knxToastContainer");
  if (!container) {
    container = document.createElement("div");
    container.id = "knxToastContainer";
    document.body.appendChild(container);
  }

  // Define global function
  window.knxToast = function(message, type = "info") {
    const toast = document.createElement("div");
    toast.className = `knx-toast knx-toast-${type}`;
    toast.textContent = message;

    container.appendChild(toast);

    // Animate in
    setTimeout(() => toast.classList.add("show"), 50);

    // Auto-remove after 3s
    setTimeout(() => {
      toast.classList.remove("show");
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  };
})();
