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
// Load existing UI theme settings
$knx_ui_theme = get_option('knx_ui_theme', null);

// Handle form POST for UI theme (same page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['knx_ui_theme_submit'])) {
    if (!isset($_POST['_knx_ui_theme_nonce']) || !wp_verify_nonce($_POST['_knx_ui_theme_nonce'], 'knx_ui_theme_action')) {
        wp_die('Security check failed.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $font = isset($_POST['knx_ui_font']) ? sanitize_text_field($_POST['knx_ui_font']) : '';
    $primary = isset($_POST['knx_ui_primary']) ? sanitize_text_field($_POST['knx_ui_primary']) : '';
    $bg = isset($_POST['knx_ui_bg']) ? sanitize_text_field($_POST['knx_ui_bg']) : '';
    $card = isset($_POST['knx_ui_card']) ? sanitize_text_field($_POST['knx_ui_card']) : '';

    // Basic hex validation for colors (allow empty)
    $validate_color = function($c){
        if (empty($c)) return '';
        if (function_exists('sanitize_hex_color')) return sanitize_hex_color($c) ?: '';
        return preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $c) ? $c : '';
    };

    $primary = $validate_color($primary);
    $bg = $validate_color($bg);
    $card = $validate_color($card);

    // Allowed font values (stored only)
    $allowed_fonts = ['system-ui','Inter','Poppins','Roboto','Segoe UI',''];
    if (!in_array($font, $allowed_fonts, true)) $font = '';

    $payload = [
        'font' => $font,
        'primary' => $primary,
        'bg' => $bg,
        'card' => $card,
        'updated_at' => current_time('mysql')
    ];

    update_option('knx_ui_theme', $payload);
    // Refresh local var
    $knx_ui_theme = $payload;
    // Redirect to avoid resubmission
    wp_safe_redirect(add_query_arg('knx_ui_theme_saved', '1'));
    exit;
}
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

  <div class="knx-card" style="margin-top:20px;">
      <h2><i class="dashicons dashicons-admin-appearance"></i> Appearance / Theme</h2>
      <p style="margin-top:-4px;color:#555;">Control basic UI variables for the Kingdom Nexus plugin components.</p>

      <?php if (isset($_GET['knx_ui_theme_saved'])): ?>
        <div style="background:#ecfdf5;padding:12px;border-radius:6px;margin:12px 0;border-left:4px solid #065f46;color:#065f46;">Saved UI theme settings.</div>
      <?php endif; ?>

      <form method="post" style="margin-top:12px;">
          <?php wp_nonce_field('knx_ui_theme_action', '_knx_ui_theme_nonce'); ?>

          <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
              <div style="flex:1;min-width:220px;">
                  <label><strong>Font Family (stored only)</strong></label><br>
                  <select name="knx_ui_font" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;">
                      <option value="">(theme default)</option>
                      <option value="system-ui" <?php selected($knx_ui_theme['font'] ?? '', 'system-ui'); ?>>System UI</option>
                      <option value="Inter" <?php selected($knx_ui_theme['font'] ?? '', 'Inter'); ?>>Inter</option>
                      <option value="Poppins" <?php selected($knx_ui_theme['font'] ?? '', 'Poppins'); ?>>Poppins</option>
                      <option value="Roboto" <?php selected($knx_ui_theme['font'] ?? '', 'Roboto'); ?>>Roboto</option>
                      <option value="Segoe UI" <?php selected($knx_ui_theme['font'] ?? '', 'Segoe UI'); ?>>Segoe UI</option>
                  </select>
              </div>

              <div style="min-width:160px;">
                  <label><strong>Primary Button</strong></label><br>
                  <input type="text" name="knx_ui_primary" value="<?php echo esc_attr($knx_ui_theme['primary'] ?? ''); ?>" placeholder="#225638" style="width:130px;padding:8px;border-radius:6px;border:1px solid #ccc;">
              </div>

              <div style="min-width:160px;">
                  <label><strong>App Background</strong></label><br>
                  <input type="text" name="knx_ui_bg" value="<?php echo esc_attr($knx_ui_theme['bg'] ?? ''); ?>" placeholder="#ffffff" style="width:130px;padding:8px;border-radius:6px;border:1px solid #ccc;">
              </div>

              <div style="min-width:160px;">
                  <label><strong>Card Background</strong></label><br>
                  <input type="text" name="knx_ui_card" value="<?php echo esc_attr($knx_ui_theme['card'] ?? ''); ?>" placeholder="#ffffff" style="width:130px;padding:8px;border-radius:6px;border:1px solid #ccc;">
              </div>
          </div>

          <p style="margin-top:12px;">
              <button type="submit" name="knx_ui_theme_submit" class="button button-primary">Save Appearance</button>
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
