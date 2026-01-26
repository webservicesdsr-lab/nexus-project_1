<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Edit Hub Template (v4.1 Production, Lean)
 * ----------------------------------------------------------
 * Shortcode: [knx_edit_hub]
 *
 * Sections:
 *  - Identity
 *  - Logo
 *  - Hub Settings (timezone, currency, tax, min order)
 *  - Location & Delivery (Google ↔ Leaflet dual map + datalist)
 *  - Working Hours
 *  - Temporary Closure
 *
 * Notes:
 *  - Page hides global top navbar for a focused dashboard.
 *  - Uses knx-toast for all feedback (no alert/console noise).
 *  - Google Maps key (if present) injected into window.KNX_MAPS_KEY.
 *  - Location input includes <datalist> for OSM/Nominatim suggestions.
 * ==========================================================
 */

add_shortcode('knx_edit_hub', function () {
    global $wpdb;

    /** Validate session/role */
    $session = function_exists('knx_get_session') ? knx_get_session() : null;
    if (
        !$session ||
        !in_array($session->role, [
            'super_admin',
            'manager',
            'hub_management',
            'menu_uploader',
            'vendor_owner'
        ], true)
    ) {
        return '<div class="knx-warning">⚠️ Unauthorized access.</div>';
    }

    /** Hub ID */
    $hub_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$hub_id) {
        return '<div class="knx-warning">⚠️ Invalid or missing Hub ID.</div>';
    }

    /** Tables */
    $table_hubs   = $wpdb->prefix . 'knx_hubs';
    $table_cities = $wpdb->prefix . 'knx_cities';
    $table_cats   = $wpdb->prefix . 'knx_hub_categories';

    /** Fetch hub row */
    $hub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_hubs} WHERE id = %d", $hub_id));
    if (!$hub) {
        return '<div class="knx-warning">⚠️ Hub not found.</div>';
    }

    /** Fetch active cities for dropdown */
    $cities = $wpdb->get_results("
        SELECT id, name
        FROM {$table_cities}
        WHERE status = 'active'
        ORDER BY name ASC
    ");

    /** Fetch active hub categories for dropdown */
    $categories = $wpdb->get_results("
      SELECT id, name
      FROM {$table_cats}
      WHERE status = 'active'
      ORDER BY sort_order ASC, name ASC
    ");

    /** Nonces and REST root */
    $nonce    = wp_create_nonce('knx_edit_hub_nonce');
    $wp_nonce = wp_create_nonce('wp_rest');
    $api_root = esc_url_raw(rest_url());

    /** Google Maps Key => expose to JS if available */
    // Use helper function from api-settings.php or query knx_settings table directly
    $maps_key = function_exists('knx_get_google_maps_key') 
        ? knx_get_google_maps_key() 
        : $wpdb->get_var("SELECT google_maps_api FROM {$wpdb->prefix}knx_settings ORDER BY id DESC LIMIT 1");
    
    if (!empty($maps_key)) {
        echo "<script>window.KNX_MAPS_KEY = '" . esc_js($maps_key) . "';</script>";
    }

    /** Internal navigation urls */
    $back_url       = esc_url(site_url('/hubs'));
    $edit_items_url = esc_url(add_query_arg('id', $hub_id, site_url('/edit-hub-items')));
    $preview_url    = esc_url(function_exists('knx_get_hub_public_url')
                        ? knx_get_hub_public_url($hub_id)
                        : home_url('/hub/?id=' . $hub_id));
    ?>

    <!-- Core styles -->
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.css'); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-style.css'); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-hours.css'); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-settings.css'); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-closure.css'); ?>">

    <!-- Page-only overrides -->
    <style>
      /* Hide global public header/navbar only on this page */
      #knxTopNavbar,
      .knx-top-navbar,
      .knx-navbar,
      .site-header { display: none !important; }

      /* Action bar */
      .knx-actionbar{
        display:flex;gap:10px;justify-content:flex-end;align-items:center;
        margin:8px 0 18px;flex-wrap:wrap;
      }
      .knx-actionbar .knx-btn{
        display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;
        border:1px solid #e7e7e7;background:#fff;text-decoration:none;font-weight:600;line-height:1;
        transition:transform .06s ease, box-shadow .12s ease, background .12s ease;
      }
      .knx-actionbar .knx-btn:hover{
        transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,.06);
      }
      .knx-actionbar .knx-btn.primary{
        background:#0B793A;color:#fff;border-color:#0B793A;
      }
      .knx-actionbar .knx-btn i{ font-size:14px; }
      @media (max-width: 720px){ .knx-actionbar{ justify-content:flex-start; } }

      /* Minimal height for map if CSS not loaded yet */
      .knx-map { min-height: 420px; }

      /* Polygon button styles */
      #startDrawing:not(:disabled) {
        background: #0b793a !important;
        color: white !important;
        cursor: pointer;
      }
      #startDrawing:not(:disabled):hover {
        background: #095a2b !important;
      }

      #completePolygon:not(:disabled) {
        background: #10b981 !important;
        color: white !important;
        cursor: pointer;
      }
      #completePolygon:not(:disabled):hover {
        background: #059669 !important;
      }

      #clearPolygon:not(:disabled) {
        background: #ef4444 !important;
        color: white !important;
        cursor: pointer;
      }
      #clearPolygon:not(:disabled):hover {
        background: #dc2626 !important;
      }

      /* Slug Management */
      .knx-slug-input-wrapper {
        display: flex;
        gap: 12px;
        align-items: center;
      }
      .knx-slug-input-wrapper input {
        flex: 1;
      }
      .knx-btn-secondary {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
        font-size: 14px;
        padding: 8px 16px;
      }
      .knx-btn-secondary:hover {
        background: #e5e7eb;
        color: #111827;
      }
      #slugPreview {
        color: #059669;
        font-weight: 600;
      }
      .knx-form-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
      }
      
      /* Delete Hub Card */
      .knx-danger-card {
        background: #fef2f2;
        border: 1px solid #fecaca;
        margin-top: 32px;
      }
      .knx-danger-card h2 {
        color: #dc2626;
        margin-bottom: 16px;
      }
      .knx-danger-content {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 24px;
      }
      .knx-danger-info {
        flex: 1;
      }
      .knx-danger-info p {
        margin: 0 0 12px 0;
        color: #374151;
        line-height: 1.6;
      }
      .knx-danger-info ul {
        margin: 0 0 12px 0;
        padding-left: 20px;
        color: #6b7280;
      }
      .knx-danger-info li {
        margin-bottom: 4px;
      }
      .knx-btn-danger {
        background: #dc2626;
        color: white;
        border: 1px solid #dc2626;
        white-space: nowrap;
        min-width: 200px;
      }
      .knx-btn-danger:hover {
        background: #b91c1c;
        border-color: #b91c1c;
        transform: translateY(-1px);
        box-shadow: 0 4px 16px rgba(220, 38, 38, 0.2);
      }
      
      /* Collapse styles for Temporary Closure only */
      .knx-collapse-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        padding: 16px 0;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 0;
      }
      .knx-collapse-header h2 {
        margin: 0;
        font-size: 22px;
      }
      .knx-collapse-desc {
        margin: 4px 0 0 0;
        color: #6b7280;
        font-size: 14px;
      }
      .toggle-arrow {
        transition: transform 0.2s ease;
        color: #6b7280;
      }
      .knx-collapse-header.active .toggle-arrow {
        transform: rotate(180deg);
      }
      .knx-collapse-body {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        padding-top: 0;
      }
      .knx-collapse-body.open {
        max-height: 2000px;
        padding-top: 24px;
      }
      
      /* Delete Confirmation Modal */
      .knx-delete-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.75);
        z-index: 10000;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(4px);
      }
      .knx-delete-modal.show {
        display: flex;
      }
      .knx-delete-modal-content {
        background: white;
        border-radius: 16px;
        padding: 32px;
        max-width: 520px;
        width: 90%;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: modalSlideIn 0.3s ease;
      }
      @keyframes modalSlideIn {
        from {
          opacity: 0;
          transform: translateY(-20px) scale(0.95);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }
      .knx-delete-modal h3 {
        margin: 0 0 16px 0;
        color: #dc2626;
        font-size: 24px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
      }
      .knx-delete-modal-body {
        margin-bottom: 24px;
      }
      .knx-delete-warning {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 12px;
        padding: 20px;
        margin: 16px 0;
      }
      .knx-delete-warning h4 {
        margin: 0 0 12px 0;
        color: #dc2626;
        font-weight: 600;
      }
      .knx-delete-warning ul {
        margin: 12px 0;
        padding-left: 20px;
        color: #6b7280;
      }
      .knx-delete-warning li {
        margin-bottom: 6px;
      }
      .knx-confirmation-input {
        margin: 20px 0;
      }
      .knx-confirmation-input label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #374151;
      }
      .knx-confirmation-input input {
        width: 100%;
        padding: 12px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 16px;
        transition: border-color 0.2s ease;
      }
      .knx-confirmation-input input:focus {
        outline: none;
        border-color: #dc2626;
      }
      .knx-confirmation-input .knx-target-text {
        font-family: monospace;
        background: #f3f4f6;
        padding: 8px 12px;
        border-radius: 6px;
        margin: 8px 0;
        font-weight: 600;
        color: #374151;
      }
      .knx-delete-modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
      }
      .knx-modal-btn {
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 16px;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        min-width: 120px;
      }
      .knx-modal-btn-cancel {
        background: #f3f4f6;
        color: #374151;
      }
      .knx-modal-btn-cancel:hover {
        background: #e5e7eb;
      }
      .knx-modal-btn-delete {
        background: #dc2626;
        color: white;
      }
      .knx-modal-btn-delete:hover:not(:disabled) {
        background: #b91c1c;
      }
      .knx-modal-btn-delete:disabled {
        background: #f87171;
        cursor: not-allowed;
      }
      
      @media (max-width: 768px) {
        .knx-delete-modal-content {
          padding: 24px;
          margin: 20px;
        }
        .knx-delete-modal-actions {
          flex-direction: column;
        }
        .knx-danger-content {
          flex-direction: column;
          align-items: stretch;
        }
        .knx-slug-input-wrapper {
          flex-direction: column;
          align-items: stretch;
        }
        .knx-form-actions {
          justify-content: stretch;
        }
        .knx-form-actions button {
          flex: 1;
        }
      }
    </style>

    <div class="knx-edit-hub-wrapper"
         data-hub-id="<?php echo esc_attr($hub_id); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-wp-nonce="<?php echo esc_attr($wp_nonce); ?>"
         data-api-get="<?php echo esc_url(rest_url('knx/v1/get-hub')); ?>"
         data-api-identity="<?php echo esc_url(rest_url('knx/v1/update-hub-identity')); ?>"
         data-api-location="<?php echo esc_url(rest_url('knx/v1/update-hub-location')); ?>"
         data-api-logo="<?php echo esc_url(rest_url('knx/v1/upload-logo')); ?>">

      <!-- Header -->
      <div class="knx-edit-header">
        <i class="fas fa-warehouse" style="font-size:22px;color:#0B793A;"></i>
        <h1>Edit Hub</h1>
      </div>

      <!-- Action Bar -->
      <div class="knx-actionbar">
        <a class="knx-btn" href="<?php echo $back_url; ?>">
          <i class="fas fa-arrow-left"></i> Back to Hubs
        </a>
        <a class="knx-btn" href="<?php echo $edit_items_url; ?>">
          <i class="fas fa-pen-to-square"></i> Edit Items
        </a>
        <a class="knx-btn primary" href="<?php echo $preview_url; ?>" target="_blank" rel="noopener">
          <i class="fas fa-eye"></i> Preview
        </a>
      </div>

      <!-- =========================
           Identity
      ========================== -->
      <div class="knx-card" id="identityBlock">
        <h2>Identity</h2>

        <div class="knx-form-group">
          <label>Hub Name</label>
          <input type="text" id="hubName" value="<?php echo esc_attr($hub->name ?? ''); ?>" disabled>
        </div>

        <div class="knx-form-group">
          <label>City</label>
          <select id="hubCity">
            <option value="">— Select City —</option>
            <?php if (!empty($cities)) : foreach ($cities as $city) : ?>
              <option value="<?php echo esc_attr($city->id); ?>" <?php selected(intval($hub->city_id), intval($city->id)); ?>>
                <?php echo esc_html($city->name); ?>
              </option>
            <?php endforeach; endif; ?>
          </select>
        </div>

        <div class="knx-form-group">
          <label>Category</label>
          <select id="hubCategory">
            <option value="">— Select Category —</option>
            <?php if (!empty($categories)) : foreach ($categories as $cat) : ?>
              <option value="<?php echo esc_attr($cat->id); ?>" <?php selected(intval($hub->category_id), intval($cat->id)); ?>>
                <?php echo esc_html($cat->name); ?>
              </option>
            <?php endforeach; endif; ?>
          </select>
        </div>

        <div class="knx-form-group">
          <label>Phone</label>
          <input type="text" id="hubPhone" value="<?php echo esc_attr($hub->phone ?? ''); ?>" placeholder="+1 708 000 0000">
        </div>

        <div class="knx-form-group">
          <label>Email</label>
          <input type="email" id="hubEmail" value="<?php echo esc_attr($hub->email ?? ''); ?>" placeholder="email@example.com">
        </div>

        <div class="knx-form-group">
          <label>Status</label>
          <select id="hubStatus">
            <option value="active"   <?php selected($hub->status, 'active'); ?>>Active</option>
            <option value="inactive" <?php selected($hub->status, 'inactive'); ?>>Inactive</option>
          </select>
        </div>

        <div class="knx-form-group">
          <label>
            Featured Hub
            <span class="knx-help-text">Show in "Locals Love These" section on explore page</span>
          </label>
          <div class="knx-toggle-wrapper">
            <label class="knx-toggle">
              <input type="checkbox" id="hubFeatured" <?php echo ($hub->is_featured ?? 0) == 1 ? 'checked' : ''; ?>>
              <span class="knx-toggle-slider"></span>
            </label>
            <span id="featured-status-text" class="knx-toggle-text">
              <?php echo ($hub->is_featured ?? 0) == 1 ? 'Featured' : 'Not Featured'; ?>
            </span>
            <span id="featured-count" class="knx-badge knx-badge--info" style="margin-left: 12px;">
              <!-- Filled by JS -->
            </span>
          </div>
        </div>

        <div class="knx-form-group">
          <label>Hub Slug (URL Identifier)</label>
          <div class="knx-slug-input-wrapper">
            <input type="text" id="hubSlug" value="<?php echo esc_attr($hub->slug ?? ''); ?>" placeholder="hub-slug-name">
            <button id="generateSlugBtn" class="knx-btn knx-btn-secondary">Generate from Name</button>
          </div>
          <small class="knx-help-text">
            The slug is used in the public URL: <code><?php echo home_url('/hub/'); ?><span id="slugPreview"><?php echo esc_html($hub->slug ?? 'your-hub-name'); ?></span></code>
          </small>
        </div>

        <div class="knx-form-actions">
          <button id="saveIdentity" class="knx-btn">Save Identity</button>
          <button id="saveSlugBtn" class="knx-btn knx-btn-secondary" data-hub-id="<?php echo esc_attr($hub_id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            Update Slug
          </button>
        </div>
      </div>

      <!-- =========================
           Logo
      ========================== -->
      <div class="knx-card" id="logoBlock">
        <h2>Hub Logo</h2>
        <div class="knx-logo-preview">
          <img id="hubLogoPreview"
               src="<?php echo esc_url($hub->logo_url ?: KNX_URL . 'assets/img/default-logo.jpg'); ?>"
               alt="Hub Logo"
               style="max-width:150px;border-radius:8px;">
        </div>
        <div class="knx-logo-actions">
          <input type="file" id="hubLogoInput" accept="image/*">
          <button id="uploadLogoBtn" class="knx-btn">Upload</button>
        </div>
      </div>

      <!-- =========================
           Settings
      ========================== -->
      <div class="knx-card" id="settingsBlock">
        <h2>Hub Settings</h2>

        <?php
          $timezone  = $hub->timezone ?? 'America/Chicago';
          $currency  = $hub->currency ?? 'USD';
          $tax_rate  = $hub->tax_rate ?? 0;
          $min_order = $hub->min_order ?? 0;
        ?>

        <div class="knx-two-col">
          <!-- Time Zone -->
          <div class="knx-field">
            <label for="timezone">Time Zone</label>
            <select id="timezone" name="timezone" data-search="true">
              <optgroup label="Favorite Time Zones">
                <?php
                $favorites = [
                    'America/Chicago'      => 'Chicago (CST/CDT)',
                    'America/Fort_Worth'   => 'Fort Worth, Texas (CST/CDT)',
                    'America/Mexico_City'  => 'Mexico City (CST/CDT)',
                    'America/Cancun'       => 'Cancún (EST)',
                ];
                foreach ($favorites as $zone => $label) {
                    try {
                        $tz = new DateTimeZone($zone);
                        $dt = new DateTime('now', $tz);
                        $offset = $tz->getOffset($dt);
                        $hours = intdiv($offset, 3600);
                        $minutes = abs(($offset % 3600) / 60);
                        $offset_str = sprintf('UTC%+03d:%02d', $hours, $minutes);
                        echo '<option value="' . esc_attr($zone) . '" ' . selected($timezone, $zone, false) . '>' .
                             esc_html("$label — $offset_str") . '</option>';
                    } catch (Exception $e) { /* skip invalid */ }
                }
                ?>
              </optgroup>

              <optgroup label="Global Time Zones">
                <?php
                foreach (DateTimeZone::listIdentifiers() as $zone) {
                    try {
                        $tz = new DateTimeZone($zone);
                        $dt = new DateTime('now', $tz);
                        $offset = $tz->getOffset($dt);
                        $hours = intdiv($offset, 3600);
                        $minutes = abs(($offset % 3600) / 60);
                        $offset_str = sprintf('UTC%+03d:%02d', $hours, $minutes);
                        echo '<option value="' . esc_attr($zone) . '" ' . selected($timezone, $zone, false) . '>' .
                             esc_html("$zone — $offset_str") . '</option>';
                    } catch (Exception $e) { continue; }
                }
                ?>
              </optgroup>
            </select>
          </div>

          <!-- Currency -->
          <div class="knx-field">
            <label for="currency">Currency</label>
            <select id="currency">
              <optgroup label="North America">
                <option value="USD" <?php selected($currency, 'USD'); ?>>US Dollar (USD)</option>
                <option value="CAD" <?php selected($currency, 'CAD'); ?>>Canadian Dollar (CAD)</option>
                <option value="MXN" <?php selected($currency, 'MXN'); ?>>Mexican Peso (MXN)</option>
              </optgroup>
              <optgroup label="Europe">
                <option value="EUR" <?php selected($currency, 'EUR'); ?>>Euro (EUR)</option>
                <option value="GBP" <?php selected($currency, 'GBP'); ?>>British Pound (GBP)</option>
              </optgroup>
            </select>
          </div>

          <!-- Taxes -->
          <div class="knx-field">
            <label for="tax_rate">Taxes &amp; Fee (%)</label>
            <input type="number" id="tax_rate" step="0.1" min="0" max="100" value="<?php echo esc_attr($tax_rate); ?>">
          </div>

          <!-- Minimum Order -->
          <div class="knx-field">
            <label for="min_order">Minimum Order ($)</label>
            <input type="number" id="min_order" step="0.01" min="0" value="<?php echo esc_attr($min_order); ?>">
          </div>
        </div>

        <div class="knx-save-row">
          <button id="knxSaveSettingsBtn"
                  class="knx-btn"
                  data-hub-id="<?php echo esc_attr($hub_id); ?>"
                  data-nonce="<?php echo esc_attr($nonce); ?>">
            Save Settings
          </button>
        </div>
      </div>

      <!-- =========================
           Location & Delivery (v5.0 CANONICAL)
      ========================== -->
      <div class="knx-card" id="locationBlock">
        <?php 
        // Make $hub available to the included file
        $GLOBALS['knx_current_hub'] = $hub;
        require KNX_PATH . 'inc/modules/hubs/hub-location-ui.php'; 
        ?>
      </div>

      <!-- =========================
           Working Hours
      ========================== -->
      <div class="knx-card" id="hoursBlock">
        <h2>Working Hours</h2>

        <p class="knx-hours-help">
          <strong>Tip:</strong> Select the days you are open, then choose hours in
          <strong>12‑hour format</strong> (AM / PM). Second shift is optional for split schedules.
        </p>

        <?php
        // ===== Helper: convertir hora guardada (24h o 12h) a partes 12h =====
        if (!function_exists('knx_hours_to_12h_parts')) {
          /**
           * @param string $time  "14:30" o "2:30 PM"
           * @return array [hh_12 (string 2 dígitos o ''), mm, ampm]
           */
          function knx_hours_to_12h_parts($time) {
            $time = trim((string)$time);
            if ($time === '') {
              return ['', '', 'AM'];
            }

            // Caso 12h "h:i AM/PM"
            if (preg_match('/^(\d{1,2}):(\d{2})\s*([AP]M)$/i', $time, $m)) {
              $h = max(1, min(12, (int) $m[1]));
              $mm = $m[2];
              $ampm = strtoupper($m[3]);
              return [str_pad($h, 2, '0', STR_PAD_LEFT), $mm, $ampm];
            }

            // Caso 24h "H:i"
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
              $h24 = (int) $m[1];
              $mm  = $m[2];

              if ($h24 === 0) {
                $h = 12; $ampm = 'AM';
              } elseif ($h24 === 12) {
                $h = 12; $ampm = 'PM';
              } elseif ($h24 > 12) {
                $h = $h24 - 12; $ampm = 'PM';
              } else {
                $h = $h24; $ampm = 'AM';
              }

              return [str_pad($h, 2, '0', STR_PAD_LEFT), $mm, $ampm];
            }

            // Fallback
            return ['', '', 'AM'];
          }
        }

        // ===== Cargar horas desde BD =====
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

        $hub_hours_raw = $wpdb->get_row($wpdb->prepare("
          SELECT hours_monday, hours_tuesday, hours_wednesday, hours_thursday,
                 hours_friday, hours_saturday, hours_sunday
          FROM {$table_hubs} WHERE id = %d
        ", $hub_id), ARRAY_A);

        $hours = [];
        foreach ($days as $day) {
          $column      = 'hours_' . $day;
          $value       = isset($hub_hours_raw[$column]) ? $hub_hours_raw[$column] : '';
          $hours[$day] = !empty($value) ? json_decode($value, true) : [];
          if (!is_array($hours[$day])) {
            $hours[$day] = [];
          }
        }

        // Opciones para selects
        $hour_options    = ['01','02','03','04','05','06','07','08','09','10','11','12'];
        $minute_options  = ['00','15','30','45'];
        $day_labels      = [
          'monday'    => 'Mon',
          'tuesday'   => 'Tue',
          'wednesday' => 'Wed',
          'thursday'  => 'Thu',
          'friday'    => 'Fri',
          'saturday'  => 'Sat',
          'sunday'    => 'Sun',
        ];
        ?>

        <div id="knxHoursContainer">
          <?php foreach ($days as $day):
            $intervals      = $hours[$day] ?? [];
            $first          = $intervals[0] ?? ['open' => '', 'close' => ''];
            $second         = $intervals[1] ?? ['open' => '', 'close' => ''];
            $has_first      = !empty($first['open']) && !empty($first['close']);
            $has_second     = !empty($second['open']) && !empty($second['close']);
            $day_checked    = $has_first ? 'checked' : '';
            $second_checked = $has_second ? 'checked' : '';

            list($open1_h,  $open1_m,  $open1_ampm)  = knx_hours_to_12h_parts($first['open']  ?? '');
            list($close1_h, $close1_m, $close1_ampm) = knx_hours_to_12h_parts($first['close'] ?? '');
            list($open2_h,  $open2_m,  $open2_ampm)  = knx_hours_to_12h_parts($second['open'] ?? '');
            list($close2_h, $close2_m, $close2_ampm) = knx_hours_to_12h_parts($second['close'] ?? '');

            $is_sunday   = ($day === 'sunday');
            $row_classes = 'knx-hours-row';
            if ($is_sunday) {
              $row_classes .= ' sunday-locked';
            }
          ?>
          <div class="<?php echo esc_attr($row_classes); ?>" data-day="<?php echo esc_attr($day); ?>">
            <div class="knx-hours-main">

              <!-- Día -->
              <label class="knx-day-toggle">
                <input type="checkbox"
                       class="day-check"
                       <?php echo $day_checked; ?>
                       <?php echo $is_sunday ? 'disabled' : ''; ?>>
                <span class="day-name"><?php echo esc_html($day_labels[$day]); ?></span>
              </label>

              <!-- Primer turno -->
              <div class="knx-shift-group knx-shift-primary">
                <span class="shift-label">1st</span>

                <div class="time-group">
                  <select class="time-select hh open1" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="">HH</option>
                    <?php foreach ($hour_options as $h): ?>
                      <option value="<?php echo esc_attr($h); ?>" <?php selected($open1_h, $h); ?>>
                        <?php echo esc_html($h); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <span class="time-sep">:</span>

                  <select class="time-select mm open1m" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="">MM</option>
                    <?php foreach ($minute_options as $m): ?>
                      <option value="<?php echo esc_attr($m); ?>" <?php selected($open1_m, $m); ?>>
                        <?php echo esc_html($m); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <select class="time-select ampm open1ampm" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="AM" <?php selected($open1_ampm, 'AM'); ?>>AM</option>
                    <option value="PM" <?php selected($open1_ampm, 'PM'); ?>>PM</option>
                  </select>
                </div>

                <span class="to-label">to</span>

                <div class="time-group">
                  <select class="time-select hh close1" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="">HH</option>
                    <?php foreach ($hour_options as $h): ?>
                      <option value="<?php echo esc_attr($h); ?>" <?php selected($close1_h, $h); ?>>
                        <?php echo esc_html($h); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <span class="time-sep">:</span>

                  <select class="time-select mm close1m" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="">MM</option>
                    <?php foreach ($minute_options as $m): ?>
                      <option value="<?php echo esc_attr($m); ?>" <?php selected($close1_m, $m); ?>>
                        <?php echo esc_html($m); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <select class="time-select ampm close1ampm" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="AM" <?php selected($close1_ampm, 'AM'); ?>>AM</option>
                    <option value="PM" <?php selected($close1_ampm, 'PM'); ?>>PM</option>
                  </select>
                </div>
              </div>

              <!-- Segundo turno -->
              <div class="knx-shift-group knx-shift-second">
                <label class="second-toggle">
                  <input type="checkbox"
                         class="second-check"
                         <?php echo $second_checked; ?>
                         <?php echo $is_sunday ? 'disabled' : ''; ?>>
                  <span class="second-label"></span>
                </label>

                <div class="time-group second-range">
                  <select class="time-select hh open2" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="">HH</option>
                    <?php foreach ($hour_options as $h): ?>
                      <option value="<?php echo esc_attr($h); ?>" <?php selected($open2_h, $h); ?>>
                        <?php echo esc_html($h); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <span class="time-sep">:</span>

                  <select class="time-select mm open2m" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="">MM</option>
                    <?php foreach ($minute_options as $m): ?>
                      <option value="<?php echo esc_attr($m); ?>" <?php selected($open2_m, $m); ?>>
                        <?php echo esc_html($m); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <select class="time-select ampm open2ampm" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="AM" <?php selected($open2_ampm, 'AM'); ?>>AM</option>
                    <option value="PM" <?php selected($open2_ampm, 'PM'); ?>>PM</option>
                  </select>
                </div>

                <span class="to-label second-to">to</span>

                <div class="time-group second-range">
                  <select class="time-select hh close2" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="">HH</option>
                    <?php foreach ($hour_options as $h): ?>
                      <option value="<?php echo esc_attr($h); ?>" <?php selected($close2_h, $h); ?>>
                        <?php echo esc_html($h); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <span class="time-sep">:</span>

                  <select class="time-select mm close2m" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="">MM</option>
                    <?php foreach ($minute_options as $m): ?>
                      <option value="<?php echo esc_attr($m); ?>" <?php selected($close2_m, $m); ?>>
                        <?php echo esc_html($m); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <select class="time-select ampm close2ampm" <?php echo $is_sunday ? 'disabled' : ''; ?>>
                    <option value="AM" <?php selected($close2_ampm, 'AM'); ?>>AM</option>
                    <option value="PM" <?php selected($close2_ampm, 'PM'); ?>>PM</option>
                  </select>
                </div>
              </div>

            </div><!-- /.knx-hours-main -->
          </div><!-- /.knx-hours-row -->
          <?php endforeach; ?>
        </div><!-- /#knxHoursContainer -->

        <div class="knx-hours-footer">
          <button id="knxSaveHoursBtn"
                  class="knx-btn"
                  data-hub-id="<?php echo esc_attr($hub_id); ?>"
                  data-nonce="<?php echo esc_attr($nonce); ?>">
            Save Working Hours
          </button>
        </div>
      </div>



      <!-- =========================
           Temporary Closure (Optimized)
      ========================== -->
      <div class="knx-card" id="closureBlock">
        <div class="knx-collapse-header" onclick="this.classList.toggle('active'); document.getElementById('closureBody').classList.toggle('open');">
          <div>
            <h2>Temporary Closure</h2>
            <p class="knx-collapse-desc">Set this hub as closed (temporary or indefinite) and optionally schedule a reopening.</p>
          </div>
          <span class="toggle-arrow">▼</span>
        </div>
        <?php
        $is_closed = !empty($hub->closure_start);
        $closure_type = ($is_closed && !empty($hub->closure_end)) ? 'temporary' : ($is_closed ? 'indefinite' : '');
        $reopen_date = $hub->closure_end ?? '';
        $reopen_time = $hub->closure_end_time ?? '';
        ?>
        <div id="closureBody" class="knx-collapse-body">
          <div class="knx-field">
            <label>Status</label>
            <label class="knx-switch">
              <input type="checkbox" id="closureToggle" <?php checked($is_closed, true); ?>>
              <span class="slider"></span>
            </label>
            <span id="closureStatusText" style="margin-left:12px;color:#dc2626;font-weight:600;">
              <?php echo $is_closed ? ($closure_type === 'temporary' ? 'Temporarily Closed' : 'Indefinitely Closed') : 'Open'; ?>
            </span>
          </div>
          <div class="knx-field">
            <label>Closure Type</label>
            <select id="closureType" <?php echo !$is_closed ? 'disabled' : ''; ?>>
              <option value="">— Select —</option>
              <option value="temporary" <?php selected($closure_type, 'temporary'); ?>>Temporary</option>
              <option value="indefinite" <?php selected($closure_type, 'indefinite'); ?>>Indefinite</option>
            </select>
          </div>
          <div class="knx-field">
            <label>Note (optional)</label>
            <textarea id="closureReason" placeholder="Add internal note..." <?php echo !$is_closed ? 'disabled' : ''; ?>><?php echo esc_textarea($hub->closure_reason ?? ''); ?></textarea>
          </div>
          <div class="knx-field" id="reopenWrapper" style="<?php echo ($closure_type === 'temporary') ? '' : 'display:none;'; ?>">
            <label>Reopen Date & Time</label>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
              <input type="date" id="reopenDate" value="<?php echo $reopen_date ? esc_attr($reopen_date) : ''; ?>" <?php echo ($closure_type === 'temporary' && $is_closed) ? '' : 'disabled'; ?>>
              <select id="reopenHour" style="min-width:60px;" <?php echo ($closure_type === 'temporary' && $is_closed) ? '' : 'disabled'; ?>>
                <option value="">HH</option>
                <?php foreach (["01","02","03","04","05","06","07","08","09","10","11","12"] as $h): ?>
                  <option value="<?php echo $h; ?>" <?php echo (!empty($reopen_time) && substr($reopen_time,0,2) == $h) ? 'selected' : ''; ?>><?php echo $h; ?></option>
                <?php endforeach; ?>
              </select>
              <span>:</span>
              <select id="reopenMinute" style="min-width:60px;" <?php echo ($closure_type === 'temporary' && $is_closed) ? '' : 'disabled'; ?>>
                <option value="">MM</option>
                <?php foreach (["00","15","30","45"] as $m): ?>
                  <option value="<?php echo $m; ?>" <?php echo (!empty($reopen_time) && substr($reopen_time,3,2) == $m) ? 'selected' : ''; ?>><?php echo $m; ?></option>
                <?php endforeach; ?>
              </select>
              <select id="reopenAMPM" style="min-width:60px;" <?php echo ($closure_type === 'temporary' && $is_closed) ? '' : 'disabled'; ?>>
                <option value="AM" <?php echo (!empty($reopen_time) && intval(substr($reopen_time,0,2)) < 12) ? 'selected' : ''; ?>>AM</option>
                <option value="PM" <?php echo (!empty($reopen_time) && intval(substr($reopen_time,0,2)) >= 12) ? 'selected' : ''; ?>>PM</option>
              </select>
            </div>
            <small class="knx-help-text">If set, hub will automatically reopen at this date/time.</small>
          </div>
          <div class="knx-save-row">
            <button id="saveClosureBtn" class="knx-btn" data-hub-id="<?php echo esc_attr($hub_id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
              Save Closure
            </button>
          </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
          const closureToggle = document.getElementById('closureToggle');
          const closureType = document.getElementById('closureType');
          const closureReason = document.getElementById('closureReason');
          const reopenWrapper = document.getElementById('reopenWrapper');
          const reopenDate = document.getElementById('reopenDate');
          const reopenHour = document.getElementById('reopenHour');
          const reopenMinute = document.getElementById('reopenMinute');
          const reopenAMPM = document.getElementById('reopenAMPM');
          const saveClosureBtn = document.getElementById('saveClosureBtn');
          const closureStatusText = document.getElementById('closureStatusText');

          function updateClosureUI() {
            const closed = closureToggle.checked;
            closureType.disabled = !closed;
            closureReason.disabled = !closed;
            if (!closed) {
              closureType.value = '';
              reopenWrapper.style.display = 'none';
              reopenDate.disabled = true;
              reopenHour.disabled = true;
              reopenMinute.disabled = true;
              reopenAMPM.disabled = true;
              closureStatusText.textContent = 'Open';
              closureStatusText.style.color = '#10b981';
            } else {
              closureStatusText.textContent = closureType.value === 'temporary' ? 'Temporarily Closed' : (closureType.value === 'indefinite' ? 'Indefinitely Closed' : 'Closed');
              closureStatusText.style.color = '#dc2626';
              if (closureType.value === 'temporary') {
                reopenWrapper.style.display = '';
                reopenDate.disabled = false;
                reopenHour.disabled = false;
                reopenMinute.disabled = false;
                reopenAMPM.disabled = false;
              } else {
                reopenWrapper.style.display = 'none';
                reopenDate.disabled = true;
                reopenHour.disabled = true;
                reopenMinute.disabled = true;
                reopenAMPM.disabled = true;
              }
            }
          }
          closureToggle.addEventListener('change', updateClosureUI);
          closureType.addEventListener('change', updateClosureUI);
          updateClosureUI();
        });
        </script>
      </div>

      <!-- =========================
           Delete Hub
      ========================== -->
      <div class="knx-card knx-danger-card" id="deleteHubBlock">
        <h2>⚠️ Delete Hub</h2>
        <div class="knx-danger-content">
          <div class="knx-danger-info">
            <p><strong>Permanent deletion of this hub and all associated data:</strong></p>
            <ul>
              <li>All hub items and categories</li>
              <li>All item modifiers and addons</li>
              <li>All order history and analytics</li>
              <li>Hub settings and configurations</li>
            </ul>
            <p><strong>This action cannot be reversed!</strong></p>
          </div>
          <button id="deleteHubBtn" class="knx-btn knx-btn-danger" data-hub-id="<?php echo esc_attr($hub_id); ?>" data-hub-name="<?php echo esc_attr($hub->name ?? 'Unknown Hub'); ?>">
            <i class="fas fa-trash"></i> Delete Hub Permanently
          </button>
        </div>
      </div>

    </div> <!-- /.knx-edit-hub-wrapper -->

    <!-- Delete Confirmation Modal -->
    <div id="deleteHubModal" class="knx-delete-modal">
      <div class="knx-delete-modal-content">
        <h3>
          <i class="fas fa-exclamation-triangle"></i>
          Delete Hub Confirmation
        </h3>
        
        <div class="knx-delete-modal-body">
          <p><strong>You are about to permanently delete this hub and ALL associated data.</strong></p>
          
          <div class="knx-delete-warning">
            <h4>⚠️ What will be deleted:</h4>
            <ul>
              <li>All hub items and categories</li>
              <li>All item modifiers and addons</li>
              <li>All order history and analytics</li>
              <li>Hub settings and configurations</li>
              <li>Hub logo and media files</li>
            </ul>
            <p><strong>This action cannot be reversed!</strong></p>
          </div>
          
          <div class="knx-confirmation-input">
            <label for="confirmHubName">To confirm, type the hub name exactly:</label>
            <div class="knx-target-text" id="targetHubName"></div>
            <input type="text" id="confirmHubName" placeholder="Type hub name here..." autocomplete="off">
          </div>
        </div>
        
        <div class="knx-delete-modal-actions">
          <button type="button" class="knx-modal-btn knx-modal-btn-cancel" id="cancelDeleteBtn">
            Cancel
          </button>
          <button type="button" class="knx-modal-btn knx-modal-btn-delete" id="confirmDeleteBtn" disabled>
            <i class="fas fa-trash"></i> Delete Permanently
          </button>
        </div>
      </div>
    </div>

    <!-- Expose small globals -->
    <script>
      const knx_api = { root: "<?php echo esc_js($api_root); ?>" };
      const knx_edit_hub = {
        hub_id: <?php echo intval($hub_id); ?>,
        nonce: "<?php echo esc_js($nonce); ?>",
        wp_nonce: "<?php echo esc_js($wp_nonce); ?>"
      };
      window.knx_session = { role: "<?php echo esc_js($session->role ?? 'guest'); ?>" };
    </script>

    <!-- Core Toast -->
    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.js'); ?>"></script>

    <!-- JS Modules (keep file names consistent) -->
    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-identity.js'); ?>"></script>
    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-logo.js'); ?>"></script>
    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-settings.js'); ?>"></script>

    <!-- Hub Location Editor (v5.0 CANONICAL - Already enqueued in hub-location-ui.php) -->

    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-hours.js'); ?>"></script>
    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-closure.js'); ?>"></script>
    
    <!-- Slug and Delete Hub functionality -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Slug management
        const slugInput = document.getElementById('hubSlug');
        const generateBtn = document.getElementById('generateSlugBtn');
        const saveSlugBtn = document.getElementById('saveSlugBtn');
        const slugPreview = document.getElementById('slugPreview');
        const hubNameInput = document.getElementById('hubName');
        
        // Update slug preview in real-time
        if (slugInput && slugPreview) {
            slugInput.addEventListener('input', function() {
                slugPreview.textContent = this.value || 'your-hub-name';
            });
        }
        
        // Generate slug from hub name
        if (generateBtn && hubNameInput && slugInput) {
            generateBtn.addEventListener('click', function() {
                const hubName = hubNameInput.value.trim();
                if (hubName) {
                    const slug = hubName
                        .toLowerCase()
                        .replace(/[^a-z0-9\s-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-')
                        .replace(/^-|-$/g, '');
                    slugInput.value = slug;
                    slugPreview.textContent = slug || 'your-hub-name';
                } else {
                    knxToast('Please enter a hub name first', 'warning');
                    hubNameInput.focus();
                }
            });
        }
        
        // Save slug
        if (saveSlugBtn) {
            saveSlugBtn.addEventListener('click', function() {
                const hubId = this.dataset.hubId;
                const nonce = this.dataset.nonce;
                const newSlug = slugInput.value.trim();
                
                if (!newSlug) {
                    knxToast('Please enter a slug', 'error');
                    return;
                }
                
                // Validate slug format
                if (!/^[a-z0-9-]+$/.test(newSlug)) {
                    knxToast('Slug can only contain lowercase letters, numbers, and hyphens', 'error');
                    return;
                }
                
                this.disabled = true;
                this.textContent = 'Updating...';
                
                fetch(knx_api.root + 'knx/v1/update-hub-slug', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': knx_edit_hub.wp_nonce
                    },
                    body: JSON.stringify({
                        hub_id: hubId,
                        slug: newSlug,
                        nonce: nonce
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        knxToast('Slug updated successfully', 'success');
                    } else {
                        knxToast(data.message || 'Failed to update slug', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    knxToast('Network error occurred', 'error');
                })
                .finally(() => {
                    this.disabled = false;
                    this.textContent = 'Update Slug';
                });
            });
        }
        
        // Delete hub functionality with modal
        const deleteBtn = document.getElementById('deleteHubBtn');
        const deleteModal = document.getElementById('deleteHubModal');
        const confirmHubName = document.getElementById('confirmHubName');
        const targetHubName = document.getElementById('targetHubName');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
        
        let currentHubId = null;
        let currentHubName = null;
        
        // Open delete modal
        if (deleteBtn && deleteModal) {
            deleteBtn.addEventListener('click', function() {
                currentHubId = this.dataset.hubId;
                currentHubName = this.dataset.hubName;
                
                // Set hub name in modal
                targetHubName.textContent = currentHubName;
                confirmHubName.value = '';
                confirmDeleteBtn.disabled = true;
                
                // Show modal
                deleteModal.classList.add('show');
                
                // Focus on input
                setTimeout(() => confirmHubName.focus(), 100);
            });
        }
        
        // Close modal
        function closeDeleteModal() {
            if (deleteModal) {
                deleteModal.classList.remove('show');
                confirmHubName.value = '';
                confirmDeleteBtn.disabled = true;
                currentHubId = null;
                currentHubName = null;
            }
        }
        
        // Cancel button
        if (cancelDeleteBtn) {
            cancelDeleteBtn.addEventListener('click', closeDeleteModal);
        }
        
        // Close modal when clicking outside
        if (deleteModal) {
            deleteModal.addEventListener('click', function(e) {
                if (e.target === deleteModal) {
                    closeDeleteModal();
                }
            });
        }
        
        // Close modal with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && deleteModal && deleteModal.classList.contains('show')) {
                closeDeleteModal();
            }
        });
        
        // Validation for confirmation input
        if (confirmHubName && confirmDeleteBtn) {
            confirmHubName.addEventListener('input', function() {
                const inputValue = this.value.trim();
                const isMatch = inputValue === currentHubName;
                confirmDeleteBtn.disabled = !isMatch;
                
                // Visual feedback
                if (inputValue.length > 0) {
                    if (isMatch) {
                        this.style.borderColor = '#10b981';
                        this.style.backgroundColor = '#f0fdf4';
                    } else {
                        this.style.borderColor = '#ef4444';
                        this.style.backgroundColor = '#fef2f2';
                    }
                } else {
                    this.style.borderColor = '#e5e7eb';
                    this.style.backgroundColor = 'white';
                }
            });
        }
        
        // Confirm delete
        if (confirmDeleteBtn) {
            confirmDeleteBtn.addEventListener('click', function() {
                if (!currentHubId || !currentHubName || this.disabled) return;
                
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
                
                fetch(knx_api.root + 'knx/v1/delete-hub', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        hub_id: currentHubId,
                        knx_nonce: knx_edit_hub.nonce
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        knxToast('Hub deleted successfully. Redirecting...', 'success');
                        closeDeleteModal();
                        setTimeout(() => {
                            window.location.href = '<?php echo esc_js(site_url('/hubs')); ?>';
                        }, 2000);
                    } else {
                        knxToast(data.message || 'Failed to delete hub', 'error');
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-trash"></i> Delete Permanently';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    knxToast('Network error occurred', 'error');
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-trash"></i> Delete Permanently';
                });
            });
        }
    });
    </script>

    <?php
});
