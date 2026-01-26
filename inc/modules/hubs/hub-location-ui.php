<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Hub Location UI (v5.0 CANONICAL)
 * ----------------------------------------------------------
 * Clean, semantic UI for hub location and delivery zone editing
 * Dual Maps: Google Maps (primary) + Leaflet (fallback)
 * ==========================================================
 */

// Get hub from global scope (set by parent template)
$hub = $GLOBALS['knx_current_hub'] ?? null;

// Validate $hub exists
if (!isset($hub) || !is_object($hub)) {
    echo '<div class="knx-warning">⚠️ Hub data not available. Please reload the page.</div>';
    return;
}

$maps_key = get_option('knx_google_maps_key', '');

// TEMPORARY: Force Leaflet for testing (remove after testing)
// Uncomment the line below to force Leaflet even if API key exists
// $maps_key = '';
?>

<?php
// Get latitude/longitude with fallbacks
$hub_id = isset($hub->id) ? intval($hub->id) : 0;
$hub_lat = !empty($hub->latitude) ? floatval($hub->latitude) : 41.1179;
$hub_lng = !empty($hub->longitude) ? floatval($hub->longitude) : -87.8656;
$hub_radius = !empty($hub->delivery_radius) ? floatval($hub->delivery_radius) : 5;
$hub_address = isset($hub->address) ? $hub->address : '';
?>

<div class="knx-hub-location-editor"
  data-hub-id="<?php echo esc_attr($hub_id); ?>"
  data-api-get="<?php echo esc_url(rest_url('knx/v1/hub-location/' . $hub_id)); ?>"
  data-api-save="<?php echo esc_url(rest_url('knx/v1/hub-location')); ?>"
  data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">

  <div class="knx-section-header">
    <h2>📍 Hub Location & Delivery Coverage</h2>
  </div>

  <!-- Address Input -->
  <div class="knx-form-group">
    <label for="knxHubAddress" class="knx-label">Street Address</label>
    <input 
      type="text" 
      id="knxHubAddress" 
      class="knx-input" 
      value="<?php echo esc_attr($hub_address); ?>"
      placeholder="Enter full street address..."
    />
  </div>

  <!-- Map Container (right after address) -->
  <div class="knx-map-wrapper">
    <label class="knx-label">Interactive Map</label>
    <div id="knxMap" class="knx-map"></div>
  </div>

  <!-- Coordinates (Hidden but accessible to JS) -->
  <div style="display: none;">
    <input type="text" id="knxHubLat" value="<?php echo esc_attr($hub_lat); ?>" />
    <input type="text" id="knxHubLng" value="<?php echo esc_attr($hub_lng); ?>" />
  </div>

  <!-- Delivery Coverage Configuration -->
  <div class="knx-coverage-panel">
    <div class="knx-panel-header">
      <h3>🚚 Delivery Coverage</h3>
      <span id="knxCoverageStatus" class="knx-badge knx-badge-warning">
        ⚠️ Not Configured
      </span>
    </div>

    <!-- Info Box -->
    <div class="knx-info-box">
      <div class="knx-info-title">📐 Custom Delivery Zones (Recommended)</div>
      <div class="knx-info-text">
        Draw a polygon on the map to define your exact delivery area.
        <strong>Fallback:</strong> If no polygon is drawn, the system uses a <span id="knxRadiusFallbackText"><?php echo esc_html($hub_radius); ?></span> mile radius from your location.
      </div>
    </div>

    <!-- Radius Configuration -->
    <div class="knx-radius-config">
      <label for="knxDeliveryRadius" class="knx-label">
        🔵 Fallback Radius (miles)
      </label>
      <div class="knx-radius-control">
        <input 
          type="number" 
          id="knxDeliveryRadius" 
          class="knx-input knx-input-sm" 
          min="1" 
          max="50" 
          step="0.5" 
          value="<?php echo esc_attr($hub_radius); ?>"
        />
        <span class="knx-helper-text">
          Used when no custom polygon is configured
        </span>
      </div>
      <button type="button" id="knxToggleRadius" class="knx-btn knx-btn-secondary knx-btn-sm">
        👁️ Toggle Radius Preview
      </button>
    </div>

    <!-- Polygon Controls -->
    <div class="knx-polygon-controls">
      <div class="knx-btn-group">
        <button type="button" id="knxStartDrawing" class="knx-btn knx-btn-success">
          🖊️ Start Drawing
        </button>
        <button type="button" id="knxCompletePolygon" class="knx-btn knx-btn-primary" disabled>
          ✅ Complete Polygon
        </button>
        <button type="button" id="knxClearPolygon" class="knx-btn knx-btn-danger" disabled>
          🗑️ Clear Polygon
        </button>
      </div>
      <div id="knxPolygonStatus" class="knx-status-box">
        <strong>Instructions:</strong> Click "Start Drawing" → Click points on map → Click "Complete" when done
      </div>
    </div>
  </div>

  <!-- Save Button -->
  <div class="knx-form-actions">
    <button type="button" id="knxSaveLocation" class="knx-btn knx-btn-primary knx-btn-lg">
      💾 Save Location & Coverage
    </button>
  </div>

</div>

<style>
/* Kingdom Nexus - Hub Location Styles (v5.0) */
.knx-hub-location-editor {
  max-width: 1200px;
  margin: 0 auto;
  padding: 24px;
}

.knx-section-header h2 {
  margin: 0 0 24px;
  font-size: 24px;
  font-weight: 600;
  color: #111827;
}

.knx-form-group {
  margin-bottom: 20px;
}

.knx-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 20px;
}

.knx-label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
  font-size: 14px;
  color: #374151;
}

.knx-input {
  width: 100%;
  padding: 10px 14px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 15px;
  transition: border-color 0.2s;
}

.knx-input:focus {
  outline: none;
  border-color: #0b793a;
  box-shadow: 0 0 0 3px rgba(11, 121, 58, 0.1);
}

.knx-input.knx-readonly {
  background: #f9fafb;
  color: #6b7280;
}

.knx-input-sm {
  width: 120px;
  padding: 8px 12px;
}

.knx-coverage-panel {
  margin-bottom: 24px;
  padding: 24px;
  background: linear-gradient(to bottom, #f8fafc 0%, #ffffff 100%);
  border: 1px solid #e5e7eb;
  border-radius: 12px;
}

.knx-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.knx-panel-header h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: #111827;
}

.knx-badge {
  padding: 6px 14px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
}

.knx-badge-warning {
  background: #fef3c7;
  color: #92400e;
}

.knx-badge-success {
  background: #d1fae5;
  color: #065f46;
}

.knx-badge-info {
  background: #dbeafe;
  color: #1e40af;
}

.knx-info-box {
  margin-bottom: 20px;
  padding: 16px;
  background: #eff6ff;
  border-left: 4px solid #3b82f6;
  border-radius: 8px;
}

.knx-info-title {
  margin-bottom: 8px;
  font-weight: 500;
  font-size: 14px;
  color: #1e40af;
}

.knx-info-text {
  margin: 0;
  font-size: 13px;
  color: #1e3a8a;
  line-height: 1.6;
}

.knx-radius-config {
  margin-bottom: 20px;
  padding: 16px;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}

.knx-radius-control {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.knx-helper-text {
  font-size: 13px;
  color: #6b7280;
}

.knx-polygon-controls {
  padding: 16px;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}

.knx-btn-group {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.knx-btn {
  padding: 10px 20px;
  border: none;
  border-radius: 8px;
  font-weight: 500;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s;
}

.knx-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.knx-btn-primary {
  background: #0b793a;
  color: white;
}

.knx-btn-primary:hover:not(:disabled) {
  background: #095a2a;
}

.knx-btn-success {
  background: #10b981;
  color: white;
}

.knx-btn-success:hover:not(:disabled) {
  background: #059669;
}

.knx-btn-danger {
  background: #ef4444;
  color: white;
}

.knx-btn-danger:hover:not(:disabled) {
  background: #dc2626;
}

.knx-btn-secondary {
  background: #6366f1;
  color: white;
}

.knx-btn-secondary:hover:not(:disabled) {
  background: #4f46e5;
}

.knx-btn-sm {
  padding: 8px 16px;
  font-size: 13px;
}

.knx-btn-lg {
  padding: 14px 28px;
  font-size: 16px;
}

.knx-status-box {
  padding: 12px;
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  font-size: 14px;
  color: #374151;
}

.knx-map-wrapper {
  margin-bottom: 24px;
}

.knx-map {
  width: 100%;
  height: 500px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #f3f4f6;
}

.knx-form-actions {
  display: flex;
  justify-content: flex-end;
}

/* Touch-friendly improvements for tablets/iPads */
@media (hover: none) and (pointer: coarse) {
  .knx-btn {
    padding: 14px 24px;
    font-size: 16px;
    min-height: 44px;
    touch-action: manipulation;
  }
  
  .knx-btn-sm {
    padding: 12px 20px;
    font-size: 15px;
    min-height: 44px;
  }
  
  .knx-input {
    min-height: 44px;
    font-size: 16px;
  }
  
  .knx-map {
    height: 600px;
    touch-action: pan-x pan-y;
  }
}

/* iPad and tablet specific */
@media (min-width: 768px) and (max-width: 1024px) {
  .knx-hub-location-editor {
    padding: 16px;
  }
  
  .knx-map {
    height: 550px;
  }
  
  .knx-btn-group {
    flex-direction: column;
    align-items: stretch;
  }
  
  .knx-btn-group .knx-btn {
    width: 100%;
  }
}
</style>

<script>
window.KNX_MAPS_CONFIG = {
  key: <?php echo (!empty($maps_key) && $maps_key !== '') ? '"' . esc_js($maps_key) . '"' : 'null'; ?>,
  hubId: <?php echo $hub_id; ?>,
  initialLat: <?php echo $hub_lat; ?>,
  initialLng: <?php echo $hub_lng; ?>,
  initialRadius: <?php echo $hub_radius; ?>
};
</script>

<?php
wp_enqueue_script(
  'knx-hub-location-editor',
  KNX_URL . 'inc/modules/hubs/hub-location-editor.js',
  [],
  KNX_VERSION,
  true
);
?>
