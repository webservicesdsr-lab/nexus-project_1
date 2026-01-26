<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Edit Hub Settings (v1.1)
 * ----------------------------------------------------------
 * Settings block for hubs:
 * - Time Zone (dropdown with world regions)
 * - Currency (multi-region)
 * - Taxes & Fee %
 * - Minimum Order
 * ----------------------------------------------------------
 * Connected to REST endpoint: knx/v1/api-update-settings
 * and JS controller: edit-hub-settings.js
 * ==========================================================
 */

global $wpdb;
$hub_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$hub_id) return;

$table = $wpdb->prefix . 'knx_hubs';
$hub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $hub_id));

$nonce = wp_create_nonce('knx_edit_hub_nonce');
?>

<!-- ==========================================================
     SETTINGS BLOCK
     ========================================================== -->
<div class="knx-card" id="settingsBlock">
  <h2>Settings</h2>

  <div class="knx-two-col">

    <!-- 🕒 Time Zone -->
    <div class="knx-field">
      <label for="timezone">Time Zone</label>
      <select id="timezone">
        <optgroup label="America">
          <option value="America/Chicago" <?php selected($hub->timezone, 'America/Chicago'); ?>>Chicago (CST)</option>
          <option value="America/New_York" <?php selected($hub->timezone, 'America/New_York'); ?>>New York (EST)</option>
          <option value="America/Los_Angeles" <?php selected($hub->timezone, 'America/Los_Angeles'); ?>>Los Angeles (PST)</option>
          <option value="America/Denver" <?php selected($hub->timezone, 'America/Denver'); ?>>Denver (MST)</option>
          <option value="America/Mexico_City" <?php selected($hub->timezone, 'America/Mexico_City'); ?>>Mexico City (CST)</option>
        </optgroup>
        <optgroup label="Europe">
          <option value="Europe/London" <?php selected($hub->timezone, 'Europe/London'); ?>>London (GMT)</option>
          <option value="Europe/Paris" <?php selected($hub->timezone, 'Europe/Paris'); ?>>Paris (CET)</option>
          <option value="Europe/Madrid" <?php selected($hub->timezone, 'Europe/Madrid'); ?>>Madrid (CET)</option>
          <option value="Europe/Berlin" <?php selected($hub->timezone, 'Europe/Berlin'); ?>>Berlin (CET)</option>
        </optgroup>
        <optgroup label="Asia">
          <option value="Asia/Tokyo" <?php selected($hub->timezone, 'Asia/Tokyo'); ?>>Tokyo (JST)</option>
          <option value="Asia/Dubai" <?php selected($hub->timezone, 'Asia/Dubai'); ?>>Dubai (GST)</option>
          <option value="Asia/Singapore" <?php selected($hub->timezone, 'Asia/Singapore'); ?>>Singapore (SGT)</option>
          <option value="Asia/Kolkata" <?php selected($hub->timezone, 'Asia/Kolkata'); ?>>India (IST)</option>
        </optgroup>
      </select>
    </div>

    <!-- 💲 Currency -->
    <div class="knx-field">
      <label for="currency">Currency</label>
      <select id="currency">
        <optgroup label="North America">
          <option value="USD" <?php selected($hub->currency, 'USD'); ?>>US Dollar (USD)</option>
          <option value="CAD" <?php selected($hub->currency, 'CAD'); ?>>Canadian Dollar (CAD)</option>
          <option value="MXN" <?php selected($hub->currency, 'MXN'); ?>>Mexican Peso (MXN)</option>
        </optgroup>
        <optgroup label="Europe">
          <option value="EUR" <?php selected($hub->currency, 'EUR'); ?>>Euro (EUR)</option>
          <option value="GBP" <?php selected($hub->currency, 'GBP'); ?>>British Pound (GBP)</option>
        </optgroup>
        <optgroup label="Asia-Pacific">
          <option value="JPY" <?php selected($hub->currency, 'JPY'); ?>>Japanese Yen (JPY)</option>
          <option value="AUD" <?php selected($hub->currency, 'AUD'); ?>>Australian Dollar (AUD)</option>
          <option value="SGD" <?php selected($hub->currency, 'SGD'); ?>>Singapore Dollar (SGD)</option>
        </optgroup>
      </select>
    </div>

    <!-- 💰 Taxes & Fee -->
    <div class="knx-field">
      <label for="tax_rate">Taxes & Fee (%)</label>
      <input type="number" id="tax_rate" min="0" max="100" step="0.1"
             value="<?php echo esc_attr($hub->tax_rate ?? 0); ?>">
    </div>

    <!-- 🛒 Minimum Order -->
    <div class="knx-field">
      <label for="min_order">Minimum Order ($)</label>
      <input type="number" id="min_order" min="0" step="0.01"
             value="<?php echo esc_attr($hub->min_order ?? 0); ?>">
    </div>

  </div>

  <!-- Save button -->
  <div class="knx-save-row">
    <button id="knxSaveSettingsBtn"
            class="knx-btn"
            data-hub-id="<?php echo esc_attr($hub_id); ?>"
            data-nonce="<?php echo esc_attr($nonce); ?>">
      Save Settings
    </button>
  </div>
</div>

<!-- Load Styles & JS -->
<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-settings.css'); ?>">
<script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-settings.js'); ?>"></script>
