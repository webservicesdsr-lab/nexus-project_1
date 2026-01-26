<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Admin Settings (v4.0 CANONICAL)
 * ------------------------------------------------
 * Visual panel to manage global API keys and system configurations.
 * Uses WordPress options table (wp_options) as SSOT.
 */

// Get saved key from WordPress options
$google_key = get_option('knx_google_maps_key', '');
?>

<div class="knx-admin-wrap">
  <div class="knx-admin-header">
      <h1><i class="dashicons dashicons-admin-generic"></i> Nexus Settings</h1>
      <p class="subtitle">Configure your API keys and system-wide integrations.</p>
  </div>

  <div class="knx-card">
      <h2><i class="dashicons dashicons-location-alt"></i> Google Maps API</h2>
      <p style="margin-top:-4px;color:#555;">Your Google Maps API key enables autocompletion and maps in Hub Location editor.</p>

      <?php if (!empty($google_key)): ?>
      <div class="knx-key-status" style="background:#d1fae5;padding:12px;border-radius:6px;margin:15px 0;border-left:4px solid #065f46;">
          <strong style="color:#065f46;">✅ API Key Active</strong><br>
          <span style="font-size:13px;color:#047857;">
              Key: <?php echo esc_html(substr($google_key, 0, 20)); ?>...<?php echo esc_html(substr($google_key, -4)); ?>
          </span>
      </div>
      <?php else: ?>
      <div class="knx-key-status" style="background:#fef3c7;padding:12px;border-radius:6px;margin:15px 0;border-left:4px solid #92400e;">
          <strong style="color:#92400e;">⚠️ No API Key</strong><br>
          <span style="font-size:13px;color:#78350f;">
              System will use OpenStreetMap (Leaflet) as fallback.
          </span>
      </div>
      <?php endif; ?>

      <form id="knxSettingsForm" style="margin-top:15px;">
          <input type="hidden" id="knxApiUrl" value="<?php echo esc_url(rest_url('knx/v1/update-settings')); ?>">
          <input type="hidden" id="knxNonce" value="<?php echo wp_create_nonce('wp_rest'); ?>">

          <div class="knx-input">
              <label for="google_maps_key"><strong>Google Maps API Key</strong></label><br>
              <input type="text" id="google_maps_key" name="google_maps_key"
                     value="<?php echo esc_attr($google_key); ?>"
                     placeholder="Enter your Google Maps API Key"
                     style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;">
          </div>

          <p style="display:flex;gap:10px;margin-top:15px;">
              <button type="submit" class="button button-primary">
                  <i class="dashicons dashicons-yes-alt"></i> Save API Key
              </button>
              <?php if (!empty($google_key)): ?>
              <button type="button" id="knxClearKey" class="button button-secondary" style="background:#ef4444;color:white;border-color:#dc2626;">
                  <i class="dashicons dashicons-trash"></i> Clear API Key
              </button>
              <?php endif; ?>
          </p>
      </form>
  </div>

  <div id="mapCard" class="knx-card" style="display:none;margin-top:20px;">
      <h2><i class="dashicons dashicons-location"></i> Map Preview</h2>
      <div id="knxMapPreview" style="width:100%;height:300px;border-radius:8px;"></div>
  </div>

  <div id="knxToast" style="display:none;"></div>

  <div class="knx-card" style="margin-top:20px;">
      <h2><i class="dashicons dashicons-info"></i> Notes</h2>
      <p style="font-size:14px;line-height:1.5;color:#444;">
        - If no API key is configured, location-based features will show a warning.<br>
        - You can replace or remove your key anytime for security reasons.<br>
        - The map preview below confirms your key is valid.
      </p>
  </div>
</div>

<?php
// Enqueue admin settings JavaScript with cache busting
wp_enqueue_script(
  'knx-admin-settings',
  KNX_URL . 'inc/modules/admin/admin-settings.js',
  [],
  KNX_VERSION . '-' . time(), // Cache buster
  true
);
?>
