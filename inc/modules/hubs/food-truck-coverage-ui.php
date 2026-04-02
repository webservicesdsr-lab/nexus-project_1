<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Food Truck Delivery Coverage UI (v1.0)
 * ----------------------------------------------------------
 * Replaces the standard "Hub Location & Delivery Coverage"
 * block when food-truck mode is active.
 *
 * Purpose:
 *   Manager / Super Admin draws a polygon or sets a radius
 *   that defines how far the food truck's drivers can deliver.
 *   The food truck operator later picks from saved addresses
 *   within /hub-settings/ — if outside this zone, ordering
 *   is blocked.
 *
 * Reuses:
 *   - Leaflet (fallback) / Google Maps (if key)
 *   - Same knx_delivery_zones table
 *   - Same coverage-engine.php for validation
 * ==========================================================
 */

$hub = $GLOBALS['knx_current_hub'] ?? null;
if (!$hub || !is_object($hub)) {
    echo '<div class="knx-warning">⚠️ Hub data not available.</div>';
    return;
}

$hub_id     = (int) $hub->id;
$hub_lat    = !empty($hub->latitude) ? floatval($hub->latitude) : 41.1179;
$hub_lng    = !empty($hub->longitude) ? floatval($hub->longitude) : -87.8656;
$hub_radius = !empty($hub->delivery_radius) ? floatval($hub->delivery_radius) : 5;
$maps_key   = get_option('knx_google_maps_key', '');
?>

<div class="knx-ft-coverage-editor"
     data-hub-id="<?php echo esc_attr($hub_id); ?>"
     data-api-save="<?php echo esc_url(rest_url('knx/v1/hub-location')); ?>"
     data-api-get="<?php echo esc_url(rest_url('knx/v1/hub-location/' . $hub_id)); ?>"
     data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">

  <div class="knx-section-header">
    <h2>🚚 Food Truck Delivery Coverage</h2>
    <p style="color:#6b7280;font-size:14px;margin:4px 0 0;">
      Define the delivery area for this food truck. Drivers will only be able to deliver
      within the polygon or radius you set here. The food truck operator selects their
      current location from <strong>Hub Settings → Saved Locations</strong>.
    </p>
  </div>

  <!-- Map Container -->
  <div class="knx-map-wrapper" style="margin:20px 0;">
    <label class="knx-label">Interactive Map</label>
    <div id="knxFtMap" class="knx-map" style="height:500px;border-radius:8px;border:1px solid #d1d5db;background:#f3f4f6;"></div>
  </div>

  <!-- Hidden coords — used only to store centroid for radius fallback -->
  <div style="display:none;">
    <input type="text" id="knxFtLat" value="<?php echo esc_attr($hub_lat); ?>" />
    <input type="text" id="knxFtLng" value="<?php echo esc_attr($hub_lng); ?>" />
  </div>

  <!-- Coverage Panel -->
  <div class="knx-coverage-panel">
    <div class="knx-panel-header">
      <h3>🚚 Delivery Area Controls</h3>
      <span id="knxFtCoverageStatus" class="knx-badge knx-badge-warning">
        ⚠️ Not Configured
      </span>
    </div>

    <!-- Info -->
    <div class="knx-info-box">
      <div class="knx-info-title">📐 Custom Delivery Zones (Recommended)</div>
      <div class="knx-info-text">
        Draw a polygon on the map to define the area within which the food truck can operate.
        If the truck's selected location falls outside this zone, customers will not be able to
        place delivery orders.
        <strong>Fallback:</strong> If no polygon is drawn, the system uses a
        <span id="knxFtRadiusFallbackText"><?php echo esc_html($hub_radius); ?></span> mile radius.
      </div>
    </div>

    <!-- Radius -->
    <div class="knx-radius-config">
      <label for="knxFtDeliveryRadius" class="knx-label">
        🔵 Fallback Radius (miles)
      </label>
      <div class="knx-radius-control">
        <input type="number" id="knxFtDeliveryRadius" class="knx-input knx-input-sm"
               min="1" max="50" step="0.5" value="<?php echo esc_attr($hub_radius); ?>" />
        <span class="knx-helper-text">Used when no custom polygon is configured</span>
      </div>
      <button type="button" id="knxFtToggleRadius" class="knx-btn knx-btn-secondary knx-btn-sm">
        👁️ Toggle Radius Preview
      </button>
    </div>

    <!-- Polygon Controls -->
    <div class="knx-polygon-controls">
      <div class="knx-btn-group">
        <button type="button" id="knxFtStartDrawing" class="knx-btn knx-btn-success">
          🖊️ Start Drawing
        </button>
        <button type="button" id="knxFtCompletePolygon" class="knx-btn knx-btn-primary" disabled>
          ✅ Complete Polygon
        </button>
        <button type="button" id="knxFtClearPolygon" class="knx-btn knx-btn-danger" disabled>
          🗑️ Clear Polygon
        </button>
      </div>
      <div id="knxFtPolygonStatus" class="knx-status-box">
        <strong>Instructions:</strong> Click "Start Drawing" → Click points on map → Click "Complete" when done
      </div>
    </div>
  </div>

  <!-- Save -->
  <div class="knx-form-actions" style="margin-top:16px;">
    <button type="button" id="knxFtSaveCoverage" class="knx-btn knx-btn-primary knx-btn-lg">
      💾 Save Food Truck Coverage
    </button>
  </div>
</div>

<style>
.knx-ft-coverage-editor { max-width:1200px; margin:0 auto; }
</style>

<!-- Food Truck Coverage JS -->
<script>
window.KNX_FT_MAPS_CONFIG = {
  key: <?php echo (!empty($maps_key) && $maps_key !== '') ? '"' . esc_js($maps_key) . '"' : 'null'; ?>,
  hubId: <?php echo $hub_id; ?>,
  initialLat: <?php echo $hub_lat; ?>,
  initialLng: <?php echo $hub_lng; ?>,
  initialRadius: <?php echo $hub_radius; ?>
};
</script>
<script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/food-truck-coverage-editor.js?v=' . KNX_VERSION); ?>"></script>
<?php
