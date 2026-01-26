<?php
// filepath: inc/modules/hubs/edit-hub-location.php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Edit Hub Location
 * Dual Maps: Google Maps (if key) or Leaflet (fallback)
 * Version: 3.0 - Polygon Support
 */

global $hub;
$maps_key = get_option('knx_google_maps_key', '');
?>

<div class="knx-edit-hub-wrapper"
  data-api-get="<?php echo esc_url(rest_url('knx/v1/get-hub')); ?>"
  data-api-location="<?php echo esc_url(rest_url('knx/v1/update-hub-location')); ?>"
  data-hub-id="<?php echo esc_attr($hub->id); ?>"
  data-nonce="<?php echo esc_attr(wp_create_nonce('knx_edit_hub')); ?>">

  <h2>📍 Edit Hub Location & Delivery Zone</h2>

  <!-- Address Input -->
  <div style="margin-bottom: 20px;">
    <label for="hubAddress" style="display:block; font-weight:600; margin-bottom:6px;">
      Address
    </label>
    <input type="text" id="hubAddress" placeholder="Enter address..."
      style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; font-size:15px;" />
  </div>

  <!-- Coordinates -->
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
    <div>
      <label for="hubLat" style="display:block; font-weight:600; margin-bottom:6px;">Latitude</label>
      <input type="text" id="hubLat" readonly
        style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; background:#f9fafb;" />
    </div>
    <div>
      <label for="hubLng" style="display:block; font-weight:600; margin-bottom:6px;">Longitude</label>
      <input type="text" id="hubLng" readonly
        style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; background:#f9fafb;" />
    </div>
  </div>

  <!-- Delivery Zone Configuration -->
  <div style="margin-bottom: 20px; padding: 20px; background: linear-gradient(to bottom, #f8fafc 0%, #ffffff 100%); border-radius: 12px; border: 1px solid #e5e7eb;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
      <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #111;">
        🚚 Delivery Coverage Area
      </h3>
      <div id="coverageStatus" style="padding: 6px 12px; background: #fef3c7; color: #92400e; border-radius: 6px; font-size: 13px; font-weight: 500;">
        ⚠️ No coverage configured
      </div>
    </div>

    <!-- Info Box -->
    <div style="background: #eff6ff; padding: 14px; border-radius: 8px; border-left: 4px solid #3b82f6; margin-bottom: 16px;">
      <p style="margin: 0 0 8px; font-size: 14px; color: #1e40af; font-weight: 500;">
        📐 Draw Custom Delivery Area (Recommended)
      </p>
      <p style="margin: 0; font-size: 13px; color: #1e3a8a; line-height: 1.6;">
        Define exactly where you deliver by drawing a polygon on the map. 
        <strong>Fallback:</strong> If no polygon is drawn, system uses a <span id="radiusFallbackText"><?php echo esc_html($hub->delivery_radius ?? 5); ?></span> mile radius from your location.
      </p>
    </div>

    <!-- Radius Fallback (Always visible for reference) -->
    <div style="margin-bottom: 16px; padding: 14px; background: white; border: 1px solid #e5e7eb; border-radius: 8px;">
      <label for="deliveryRadius" style="display:block; font-weight:500; margin-bottom:8px; color:#374151; font-size: 14px;">
        🔵 Fallback Radius (miles)
      </label>
      <div style="display: flex; align-items: center; gap: 12px;">
        <input type="number" id="deliveryRadius" step="0.5" min="1" max="50" value="5"
          style="width:100px; padding:10px; border:1px solid #d1d5db; border-radius:6px; font-size: 15px;" />
        <span style="font-size: 13px; color: #6b7280;">
          Used when no custom polygon is configured
        </span>
      </div>
    </div>

    <!-- Polygon Drawing Controls -->
    <div style="background: white; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px;">
      <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;">
        <button type="button" id="startDrawing" 
          style="padding: 11px 20px; background: #0b793a; color: white; border: none; border-radius: 7px; cursor: pointer; font-weight: 500; font-size: 14px; transition: all 0.2s;">
          🖊️ Start Drawing Polygon
        </button>
        <button type="button" id="completePolygon" disabled
          style="padding: 11px 20px; background: #6b7280; color: white; border: none; border-radius: 7px; cursor: not-allowed; font-weight: 500; font-size: 14px;">
          ✅ Complete Polygon
        </button>
        <button type="button" id="clearPolygon" disabled
          style="padding: 11px 20px; background: #ef4444; color: white; border: none; border-radius: 7px; cursor: not-allowed; font-weight: 500; font-size: 14px;">
          🗑️ Clear Polygon
        </button>
        <button type="button" id="toggleRadius" 
          style="padding: 11px 20px; background: #6366f1; color: white; border: none; border-radius: 7px; cursor: pointer; font-weight: 500; font-size: 14px;">
          👁️ Show Radius Preview
        </button>
      </div>

      <div id="polygonStatus" style="padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; font-size: 14px; color: #374151;">
        <strong>Instructions:</strong> Click "Start Drawing" → Click points on map → Click "Complete" when done
      </div>
    </div>
  </div>

  <!-- Map -->
  <div style="margin-bottom: 20px;">
    <label style="display:block; font-weight:600; margin-bottom:8px;">Map</label>
    <div id="map" style="width:100%; height:500px; border:1px solid #ddd; border-radius:8px;"></div>
  </div>

  <!-- Save Button -->
  <button type="button" id="saveLocation"
    style="padding:12px 24px; background:#0b793a; color:white; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:15px;">
    💾 Save Location & Delivery Zone
  </button>

</div>

<style>
#startDrawing:not(:disabled) {
  background: #0b793a;
  cursor: pointer;
}
#startDrawing:not(:disabled):hover {
  background: #095a2a;
}

#completePolygon:not(:disabled) {
  background: #10b981;
  cursor: pointer;
}
#completePolygon:not(:disabled):hover {
  background: #059669;
}

#clearPolygon:not(:disabled) {
  background: #ef4444;
  cursor: pointer;
}
#clearPolygon:not(:disabled):hover {
  background: #dc2626;
}
</style>

<script>
  window.KNX_MAPS_KEY = <?php echo $maps_key ? '"' . esc_js($maps_key) . '"' : 'null'; ?>;
</script>

<?php
wp_enqueue_script(
  'knx-edit-hub-location',
  KNX_URL . 'inc/modules/hubs/edit-hub-location.js',
  [],
  KNX_VERSION,
  true
);
?>