/**
 * Kingdom Nexus - Edit Hub Logo (v1.5)
 * ----------------------------------------------------------
 * Handles:
 * ✅ Image preview before upload
 * ✅ REST upload with toast feedback (uses global knxToast)
 * ✅ File validation (type + size)
 */

document.addEventListener("DOMContentLoaded", () => {
    const uploadBtn = document.getElementById("uploadLogoBtn");
    const fileInput = document.getElementById("hubLogoInput");
    const previewImg = document.getElementById("hubLogoPreview");

    if (!uploadBtn || !fileInput) return;

    /** Validate file */
    function validateFile(file) {
        const validTypes = ["image/jpeg", "image/png", "image/webp"];
        if (!validTypes.includes(file.type)) {
            knxToast("❌ Invalid file type. Please upload JPG, PNG, or WEBP.", "error");
            return false;
        }
        if (file.size > 5 * 1024 * 1024) {
            knxToast("⚠️ File too large. Max 5MB allowed.", "error");
            return false;
        }
        return true;
    }

    /** Preview before upload */
    fileInput.addEventListener("change", (e) => {
        const file = e.target.files[0];
        if (file && validateFile(file)) {
            const reader = new FileReader();
            reader.onload = (ev) => previewImg && (previewImg.src = ev.target.result);
            reader.readAsDataURL(file);
        }
    });

    /** Upload process */
    uploadBtn.addEventListener("click", async () => {
        const file = fileInput.files[0];
        if (!file) return knxToast("Please select an image first.", "error");
        if (!validateFile(file)) return;

        uploadBtn.disabled = true;
        uploadBtn.textContent = "Uploading...";

        const formData = new FormData();
        formData.append("file", file);
        formData.append("hub_id", knx_edit_hub.hub_id);
        formData.append("knx_nonce", knx_edit_hub.nonce);

        try {
            const response = await fetch(`${knx_api.root}knx/v1/upload-logo`, {
                method: "POST",
                headers: { "X-WP-Nonce": knx_edit_hub.wp_nonce },
                body: formData
            });

            const data = await response.json().catch(() => ({}));

            if (data.success) {
                if (data.url && previewImg) previewImg.src = data.url;
                knxToast(data.message || "✅ Logo uploaded successfully", "success");
            } else {
                knxToast(data.error || "❌ Upload failed. Please try again.", "error");
            }
        } catch (err) {
            console.error("Upload error:", err);
            knxToast("❌ Unexpected error during upload.", "error");
        } finally {
            uploadBtn.disabled = false;
            uploadBtn.textContent = "Upload";
        }
    });
});
