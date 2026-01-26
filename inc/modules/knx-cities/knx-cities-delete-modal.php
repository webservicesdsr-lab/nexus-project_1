<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Cities — Delete Confirmation Modal (Safe)
 * - Markup only (NO echo outside shortcode)
 * ==========================================================
 */
?>

<div id="knxCityDeleteModal" class="knx-modal" aria-hidden="true" style="display:none;">
  <div class="knx-modal-backdrop" data-knx-close="1"></div>

  <div class="knx-modal-box" role="dialog" aria-modal="true" aria-labelledby="knxCityDeleteTitle">
    <h3 id="knxCityDeleteTitle">⚠️ Delete City</h3>

    <p class="knx-modal-warning">
      This action is <strong>irreversible</strong>.<br>
      The delete button will unlock in <strong><span id="knxDeleteCountdown">5</span>s</strong>.
    </p>

    <div class="knx-modal-actions">
      <button id="knxCityDeleteCancel" class="knx-btn-secondary" type="button">
        Cancel
      </button>

      <button id="knxCityDeleteConfirm" class="knx-btn-danger" type="button" disabled>
        Yes, Delete
      </button>
    </div>
  </div>
</div>
