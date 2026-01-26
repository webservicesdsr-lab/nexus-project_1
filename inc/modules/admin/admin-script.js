/**
 * Kingdom Nexus - Admin UI Scripts (v2.5)
 * Handles toast messages and minor visual interactivity.
 */

document.addEventListener("DOMContentLoaded", () => {
  // Toast init
  const toast = document.createElement("div");
  toast.className = "knx-toast";
  document.body.appendChild(toast);

  window.showKnxToast = function (msg, ok = true) {
    toast.textContent = msg;
    toast.style.background = ok ? "#0B793A" : "#E74C3C";
    toast.classList.add("show");
    setTimeout(() => toast.classList.remove("show"), 3000);
  };

  // Example: confirm navigation
  const buttons = document.querySelectorAll(".knx-card .button");
  buttons.forEach(btn => {
    btn.addEventListener("click", e => {
      btn.classList.add("active");
      setTimeout(() => btn.classList.remove("active"), 500);
    });
  });
});
