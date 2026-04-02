<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Management — Settings Shortcode  [knx_hub_settings]
 * ----------------------------------------------------------
 * Visual clone of [knx_edit_hub] (edit-hub-template.php)
 * reduced to safe fields only.
 *
 * Sections kept:
 *  - Identity (name, phone, email, address)
 *  - Logo
 *  - Working Hours
 *  - Temporary Closure
 *  - My Food Truck Locations (if type = 'Food Truck') — saved locations CRUD
 *
 * Sections removed:
 *  - City / category / status / featured / slug (admin only)
 *  - Hub Settings (timezone, currency, tax, min order) (admin only)
 *  - Location & Delivery polygons/radius (admin only)
 *  - Delete Hub (admin only)
 *
 * Security: session + hub role regex + ownership
 * ==========================================================
 */

add_shortcode('knx_hub_settings', function () {
    global $wpdb;

    // ── Session + role + ownership (fail-closed) ───────────
    $guard = knx_hub_management_guard();
    if (!$guard) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }
    [$session, $hub_id] = $guard;

    $user_id = (int) $session->user_id;

    // ── Fetch hub ──────────────────────────────────────────
    $table_hubs = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_hubs} WHERE id = %d LIMIT 1", $hub_id));
    if (!$hub) {
        return '<div class="knx-warning">⚠️ Hub not found.</div>';
    }

    $is_food_truck = ($hub->type === 'Food Truck');

    // ── Nonces and API URLs ────────────────────────────────
    $nonce    = wp_create_nonce('knx_hub_settings_nonce');
    $wp_nonce = wp_create_nonce('wp_rest');

    $dashboard_url = esc_url(site_url('/hub-dashboard'));
    $items_url     = esc_url(site_url('/hub-items?hub_id=' . $hub_id));

    ob_start();
    ?>

    <!-- Reuse canonical edit-hub styles -->
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.css'); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-style.css'); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/edit-hub-closure.css'); ?>">

    <style>
      #knxTopNavbar, .knx-top-navbar, .knx-navbar, .site-header { display: none !important; }

      .knx-actionbar{
        display:flex;gap:10px;justify-content:flex-end;align-items:center;
        margin:8px 0 18px;flex-wrap:wrap;
      }
      .knx-actionbar .knx-btn{
        display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;
        border:1px solid #e7e7e7;background:#fff;text-decoration:none;font-weight:600;line-height:1;
        transition:transform .06s ease, box-shadow .12s ease, background .12s ease;
        min-width: 120px; justify-content: center;
      }
      .knx-actionbar .knx-btn:hover{
        transform:translateY(-2px);box-shadow:0 6px 22px rgba(0,0,0,.08);
      }
      .knx-actionbar .knx-btn.knx-btn-secondary{ background: #fff; color: #0b1220; border-color: #d1d5db; }
      .knx-actionbar .knx-btn.knx-btn-secondary:hover{ background:#f3f4f6; }
      .knx-actionbar .knx-btn.primary{ background:#0b793a;color:#fff;border-color:#0b793a; }
      .knx-actionbar .knx-btn i{ font-size:14px; margin-right:6px; }
      .knx-actionbar .knx-btn-back { order: -1; margin-right: auto; min-width: 90px; }
      @media (max-width: 720px){ .knx-actionbar{ justify-content:flex-start; } }

    </style>

    <div class="knx-edit-hub-wrapper"
         data-hub-id="<?php echo esc_attr($hub_id); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-wp-nonce="<?php echo esc_attr($wp_nonce); ?>"
         data-api-identity="<?php echo esc_url(rest_url('knx/v1/update-hub-identity')); ?>"
         data-api-hours="<?php echo esc_url(rest_url('knx/v1/save-hours')); ?>"
         data-api-closure="<?php echo esc_url(rest_url('knx/v1/update-closure')); ?>"
         data-api-logo="<?php echo esc_url(rest_url('knx/v1/upload-logo')); ?>">

      <!-- Header -->
      <div class="knx-edit-header">
        <i class="fas fa-store" style="font-size:22px;color:#0B793A;"></i>
        <h1>Hub Settings</h1>
      </div>

      <!-- Action Bar -->
      <div class="knx-actionbar">
        <a class="knx-btn knx-btn-secondary" href="<?php echo $items_url; ?>" aria-label="Hub Items">
          <i class="fas fa-utensils"></i> Hub Items
        </a>
        <a class="knx-btn knx-btn-secondary knx-btn-back" href="<?php echo $dashboard_url; ?>" aria-label="Back to Dashboard">
          <i class="fas fa-arrow-left"></i> Dashboard
        </a>
      </div>

      <!-- =========================
           Identity (reduced)
      ========================== -->
      <div class="knx-card" id="identityBlock">
        <h2>Identity</h2>

        <div class="knx-form-group">
          <label>Hub Name</label>
          <input type="text" id="hubName" value="<?php echo esc_attr($hub->name ?? ''); ?>" placeholder="Hub name">
        </div>

        <div class="knx-form-group">
          <label>Phone</label>
          <input type="text" id="hubPhone" value="<?php echo esc_attr($hub->phone ?? ''); ?>" placeholder="+1 708 000 0000">
        </div>

        <div class="knx-form-group">
          <label>Email</label>
          <input type="email" id="hubEmail" value="<?php echo esc_attr($hub->email ?? ''); ?>" placeholder="email@example.com">
        </div>

        <div class="knx-form-actions">
          <button type="button" class="knx-btn" id="saveIdentityBtn">
            <i class="fas fa-save"></i> Save Identity
          </button>
        </div>
      </div>





      <!-- =========================
           Temporary Closure
      ========================== -->
      <div class="knx-card" id="closureBlock">
        <h2>Temporary Closure</h2>
        <div class="knx-form-group">
          <label class="knx-toggle">
            <input type="checkbox" id="closureToggle" <?php echo !empty($hub->closure_start) ? 'checked' : ''; ?>>
            <span class="knx-toggle-slider"></span>
          </label>
          <span id="closureStatusText"><?php echo !empty($hub->closure_start) ? 'Hub is temporarily closed' : 'Hub is open'; ?></span>
        </div>
        <div id="closureDetails" <?php echo empty($hub->closure_start) ? 'style="display:none"' : ''; ?>>
          <div class="knx-form-group">
            <label>Closure Type</label>
            <select id="closureType">
              <option value="indefinite" <?php echo empty($hub->closure_until) ? 'selected' : ''; ?>>Indefinite</option>
              <option value="temporary" <?php echo !empty($hub->closure_until) ? 'selected' : ''; ?>>Temporary (auto-reopen)</option>
            </select>
          </div>
          <div class="knx-form-group">
            <label>Reason / Note</label>
            <textarea id="closureReason" rows="2" placeholder="Optional note..."><?php echo esc_textarea($hub->closure_reason ?? ''); ?></textarea>
          </div>
          <div class="knx-form-group" id="closureReopenGroup" <?php echo empty($hub->closure_until) ? 'style="display:none"' : ''; ?>>
            <label>Reopen Date & Time</label>
            <div style="display:flex;gap:10px;">
              <input type="date" id="closureReopenDate" value="<?php echo $hub->closure_until ? date('Y-m-d', strtotime($hub->closure_until)) : ''; ?>">
              <input type="time" id="closureReopenTime" value="<?php echo $hub->closure_until ? date('H:i', strtotime($hub->closure_until)) : ''; ?>">
            </div>
          </div>
        </div>
        <div class="knx-form-actions" style="margin-top:12px;">
          <button type="button" class="knx-btn" id="saveClosureBtn">
            <i class="fas fa-lock"></i> Save Closure
          </button>
        </div>
      </div>

      <?php if ($is_food_truck) : ?>
      <!-- =========================
           Food Truck — Saved Locations CRUD
      ========================== -->
      <div class="knx-card" id="ftLocationsBlock" style="border-left:4px solid #fb923c;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
          <div>
            <h2 style="margin:0;"><i class="fas fa-map-marker-alt" style="color:#fb923c;"></i> My Food Truck Locations</h2>
            <p style="margin:4px 0 0;color:#6b7280;font-size:14px;">
              Add the spots where your food truck operates. Select one to set as your current serving location.
            </p>
          </div>
          <button type="button" class="knx-btn" id="ftAddLocationBtn" style="background:#fb923c;border-color:#fb923c;color:#fff;">
            <i class="fas fa-plus"></i> Add Location
          </button>
        </div>

        <!-- Coverage warning (hidden by default, shown by JS when outside zone) -->
        <div id="ftCoverageWarning" style="display:none;padding:12px 16px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;margin-bottom:16px;">
          <p style="margin:0;color:#dc2626;font-weight:600;font-size:14px;">
            <i class="fas fa-exclamation-triangle"></i>
            Sorry, your current location is outside the allowed delivery range. Customers will not be able to place orders for delivery.
          </p>
        </div>

        <!-- Locations List -->
        <div id="ftLocationsList" class="knx-ft-locs-grid">
          <div id="ftLocationsLoading" style="text-align:center;padding:24px;color:#6b7280;">
            <i class="fas fa-spinner fa-spin"></i> Loading locations…
          </div>
        </div>

        <div id="ftLocationsEmpty" style="display:none;text-align:center;padding:32px;color:#6b7280;">
          <i class="fas fa-map-pin" style="font-size:32px;opacity:0.3;display:block;margin-bottom:12px;"></i>
          <p style="margin:0 0 12px;">No saved locations yet.</p>
          <button type="button" class="knx-btn" id="ftAddLocationEmptyBtn" style="background:#fb923c;border-color:#fb923c;color:#fff;">
            <i class="fas fa-plus"></i> Add Your First Location
          </button>
        </div>
      </div>

      <!-- =========================
           Food Truck — Add/Edit Location Modal (minimal)
      ========================== -->
      <div class="knx-ft-loc-modal" id="ftLocModal" aria-hidden="true">
        <div class="knx-ft-loc-modal__backdrop" id="ftLocModalBG"></div>
        <div class="knx-ft-loc-modal__sheet" role="dialog" aria-modal="true">
          <header class="knx-ft-loc-modal__header">
            <h2 id="ftLocModalTitle">Add Location</h2>
            <button type="button" class="knx-ft-loc-modal__close" id="ftLocModalClose" aria-label="Close">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </header>

          <form id="ftLocForm" class="knx-ft-loc-modal__body" autocomplete="off">
            <input type="hidden" id="ftLocId" value="">

            <!-- Single Autocomplete Search -->
            <div class="knx-addr-form__group">
              <label for="ftLocSearch">Search address</label>
              <div class="knx-addr-form__search-wrap">
                <svg class="knx-addr-form__search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" id="ftLocSearch" placeholder="Type an address, e.g. 123 Main St…" autocomplete="off">
              </div>
              <ul class="knx-addr-form__suggestions" id="ftLocSuggestions"></ul>
            </div>

            <!-- Map Preview + Geolocation -->
            <div class="knx-addr-form__group">
              <label>Pin Location</label>
              <div style="display:flex;gap:8px;margin-bottom:8px;">
                <button type="button" class="knx-btn knx-btn-sm" id="ftLocGeoBtn" style="background:#fb923c;color:#fff;border:none;">
                  <i class="fas fa-crosshairs"></i> Use My Current Location
                </button>
              </div>
              <div id="ftLocMap" style="width:100%;height:280px;border-radius:8px;border:1px solid #d1d5db;background:#f3f4f6;"></div>
              <p style="margin:6px 0 0;font-size:12px;color:#9ca3af;">Click the map or drag the pin to adjust</p>
            </div>

            <!-- Location Note -->
            <div class="knx-addr-form__group">
              <label for="ftLocNote">Location Note</label>
              <input type="text" id="ftLocNote" placeholder="e.g. Parking lot near entrance, corner of Main & 5th…" maxlength="255">
            </div>

            <!-- Coverage feedback (inside modal) -->
            <div id="ftLocCoverageFeedback" style="display:none;padding:10px 14px;border-radius:8px;margin:8px 0;font-size:13px;font-weight:600;"></div>

            <input type="hidden" id="ftLocLat" value="">
            <input type="hidden" id="ftLocLng" value="">

            <!-- Actions -->
            <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:16px;border-top:1px solid #e5e7eb;">
              <button type="button" class="knx-btn" id="ftLocCancelBtn" style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;">Cancel</button>
              <button type="submit" class="knx-btn" id="ftLocSaveBtn" style="background:#fb923c;color:#fff;border-color:#fb923c;">
                <i class="fas fa-check"></i> Save Location
              </button>
            </div>
          </form>
        </div>
      </div>

      <style>
        /* Food Truck Locations Grid */
        .knx-ft-locs-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
          gap: 12px;
        }
        .knx-ft-loc-card {
          background: #fff;
          border: 2px solid #e5e7eb;
          border-radius: 12px;
          padding: 16px;
          transition: border-color 0.2s, box-shadow 0.2s;
          cursor: pointer;
          position: relative;
        }
        .knx-ft-loc-card:hover {
          border-color: #fb923c;
          box-shadow: 0 4px 16px rgba(251,146,60,0.12);
        }
        .knx-ft-loc-card.is-active {
          border-color: #0b793a;
          background: #f0fdf4;
        }
        .knx-ft-loc-card.is-out-of-range {
          border-color: #fecaca;
          background: #fef2f2;
        }
        .knx-ft-loc-card__label {
          font-weight: 700;
          font-size: 15px;
          margin-bottom: 4px;
          color: #111827;
        }
        .knx-ft-loc-card__address {
          font-size: 13px;
          color: #6b7280;
          line-height: 1.5;
        }
        .knx-ft-loc-card__actions {
          display: flex;
          gap: 8px;
          margin-top: 12px;
          flex-wrap: wrap;
        }
        .knx-ft-loc-card__actions button {
          padding: 6px 12px;
          border-radius: 6px;
          font-size: 12px;
          font-weight: 600;
          border: 1px solid #e5e7eb;
          background: #fff;
          cursor: pointer;
          transition: all 0.15s;
        }
        .knx-ft-loc-card__actions .ft-select-btn {
          background: #0b793a;
          color: #fff;
          border-color: #0b793a;
        }
        .knx-ft-loc-card__actions .ft-select-btn:hover { background: #095a2a; }
        .knx-ft-loc-card__actions .ft-edit-btn:hover { background: #f3f4f6; }
        .knx-ft-loc-card__actions .ft-delete-btn { color: #dc2626; border-color: #fecaca; }
        .knx-ft-loc-card__actions .ft-delete-btn:hover { background: #fef2f2; }
        .knx-ft-loc-card__badge {
          position: absolute;
          top: 10px;
          right: 10px;
          font-size: 11px;
          font-weight: 700;
          padding: 3px 8px;
          border-radius: 4px;
        }
        .knx-ft-loc-card__badge--active {
          background: #d1fae5;
          color: #065f46;
        }
        .knx-ft-loc-card__badge--warning {
          background: #fef2f2;
          color: #dc2626;
        }

        /* Modal */
        .knx-ft-loc-modal {
          display: none;
          position: fixed;
          inset: 0;
          z-index: 10000;
        }
        .knx-ft-loc-modal[aria-hidden="false"] {
          display: flex;
          justify-content: center;
          align-items: flex-end;
        }
        @media (min-width: 640px) {
          .knx-ft-loc-modal[aria-hidden="false"] {
            align-items: center;
          }
        }
        .knx-ft-loc-modal__backdrop {
          position: absolute;
          inset: 0;
          background: rgba(0,0,0,0.6);
          backdrop-filter: blur(4px);
        }
        .knx-ft-loc-modal__sheet {
          position: relative;
          background: #fff;
          border-radius: 16px 16px 0 0;
          max-height: 90vh;
          overflow-y: auto;
          width: 100%;
          max-width: 540px;
          animation: ftModalSlideUp 0.3s ease;
        }
        @media (min-width: 640px) {
          .knx-ft-loc-modal__sheet {
            border-radius: 16px;
          }
        }
        @keyframes ftModalSlideUp {
          from { transform: translateY(30px); opacity: 0; }
          to { transform: translateY(0); opacity: 1; }
        }
        .knx-ft-loc-modal__header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 20px 24px 12px;
          border-bottom: 1px solid #e5e7eb;
        }
        .knx-ft-loc-modal__header h2 {
          margin: 0;
          font-size: 18px;
          font-weight: 700;
        }
        .knx-ft-loc-modal__close {
          background: none;
          border: none;
          cursor: pointer;
          padding: 4px;
          color: #6b7280;
        }
        .knx-ft-loc-modal__body {
          padding: 20px 24px 24px;
        }
        /* Reuse address form styles from my-addresses */
        .knx-ft-loc-modal .knx-addr-form__group { margin-bottom: 14px; }
        .knx-ft-loc-modal .knx-addr-form__group label { display:block; font-weight:500; font-size:14px; color:#374151; margin-bottom:6px; }
        .knx-ft-loc-modal .knx-addr-form__group input[type="text"] { width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:15px; }
        .knx-ft-loc-modal .knx-addr-form__group input:focus { outline:none; border-color:#fb923c; box-shadow:0 0 0 3px rgba(251,146,60,0.15); }
        .knx-ft-loc-modal .knx-addr-form__search-wrap { position:relative; }
        .knx-ft-loc-modal .knx-addr-form__search-wrap input { padding-left:38px; }
        .knx-ft-loc-modal .knx-addr-form__search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); pointer-events:none; }
        .knx-ft-loc-modal .knx-addr-form__suggestions {
          list-style: none; margin: 4px 0 0; padding: 0; border: 1px solid #e5e7eb;
          border-radius: 8px; max-height: 200px; overflow-y: auto; display: none; background: #fff;
          box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }
        .knx-ft-loc-modal .knx-addr-form__suggestions li {
          padding: 10px 14px; cursor: pointer; font-size: 14px; border-bottom: 1px solid #f3f4f6;
        }
        .knx-ft-loc-modal .knx-addr-form__suggestions li:hover { background: #f9fafb; }
      </style>

      <!-- Leaflet for modal map -->
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

      <!-- Food Truck Locations JS Controller -->
      <script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/ft-locations-script.js?v=' . KNX_VERSION); ?>"></script>
      <script>
        // Boot the food truck locations controller
        if (typeof window.KnxFtLocations !== 'undefined') {
          window.KnxFtLocations.init({
            hubId:     <?php echo (int)$hub_id; ?>,
            nonce:     "<?php echo esc_js($nonce); ?>",
            wpNonce:   "<?php echo esc_js($wp_nonce); ?>",
            apiBase:   "<?php echo esc_url(rest_url('knx/v1/hub-management/ft-locations')); ?>",
            apiCheck:  "<?php echo esc_url(rest_url('knx/v1/hub-management/ft-locations/check')); ?>"
          });
        }
      </script>
      <?php endif; ?>

    </div>

    <!-- Toast notifications -->
    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.js'); ?>"></script>
    <!-- Hub Settings JS -->
    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/hub-settings-script.js?v=' . KNX_VERSION); ?>"></script>

    <?php
    return ob_get_clean();
});
