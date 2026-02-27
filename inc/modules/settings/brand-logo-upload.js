// File: inc/modules/settings/settings-upload.js

/**
 * ==========================================================
 * Kingdom Nexus — Settings Branding Upload (v1.0)
 * ----------------------------------------------------------
 * - Preview on select
 * - Upload via REST (FormData)
 * - Uses knxToast if available, otherwise falls back
 * - Expects knx_rest_response() shape:
 *   { success, message, data: { url } }
 * ==========================================================
 */

document.addEventListener('DOMContentLoaded', () => {
  const uploadBtn = document.getElementById('knxBrandingSaveBtn');
  const fileInput = document.getElementById('knxBrandingFileInput');
  const previewImg = document.getElementById('knxBrandingPreview');

  if (!uploadBtn || !fileInput) return;

  const toast = (msg, type) => {
    if (typeof window.knxToast === 'function') return window.knxToast(msg, type);
    // Minimal fallback (never break flow)
    console[type === 'error' ? 'error' : 'log']('[KNX-SETTINGS]', msg);
    alert(msg);
  };

  function validateFile(file) {
    const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!validTypes.includes(file.type)) {
      toast('❌ Invalid file type. Please upload JPG, PNG, or WEBP.', 'error');
      return false;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast('⚠️ File too large. Max 5MB allowed.', 'error');
      return false;
    }
    return true;
  }

  fileInput.addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    if (!validateFile(file)) {
      fileInput.value = '';
      return;
    }

    const reader = new FileReader();
    reader.onload = (ev) => {
      if (previewImg) previewImg.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  });

  uploadBtn.addEventListener('click', async () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return toast('Please select an image first.', 'error');
    if (!validateFile(file)) return;

    if (!window.knx_settings || !window.knx_settings.root || !window.knx_settings.wp_nonce) {
      return toast('❌ Missing settings config (knx_settings).', 'error');
    }

    uploadBtn.disabled = true;
    const originalText = uploadBtn.textContent;
    uploadBtn.textContent = 'Uploading...';

    const formData = new FormData();
    formData.append('file', file);

    try {
      const res = await fetch(`${window.knx_settings.root}knx/v1/save-branding`, {
        method: 'POST',
        headers: {
          'X-WP-Nonce': window.knx_settings.wp_nonce
        },
        body: formData
      });

      const payload = await res.json().catch(() => ({}));

      if (payload && payload.success) {
        const url = payload.data && payload.data.url ? payload.data.url : '';
        if (url && previewImg) previewImg.src = url;
        toast(payload.message || '✅ Logo saved successfully', 'success');
      } else {
        const msg = (payload && payload.message) ? payload.message : '❌ Upload failed. Please try again.';
        toast(msg, 'error');
      }
    } catch (err) {
      console.error('[KNX-SETTINGS] Upload error:', err);
      toast('❌ Unexpected error during upload.', 'error');
    } finally {
      uploadBtn.disabled = false;
      uploadBtn.textContent = originalText;
    }
  });
});