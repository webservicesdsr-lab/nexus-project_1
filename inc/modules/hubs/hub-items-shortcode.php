<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Management — Items Shortcode  [knx_hub_items]
 * ----------------------------------------------------------
 * Visual clone of [knx_edit_hub_items] (edit-hub-items.php)
 * reduced to hub-management scope.
 *
 * Additions:
 *  - Availability type selector (regular/daily/seasonal)
 *  - Daily day-of-week + time fields
 *  - Seasonal start/end datetime fields
 *
 * Removed:
 *  - CSV import/export (admin only for MVP)
 *  - Back to Hubs list link (hub_management sees own hub only)
 *
 * Security: session + hub role regex + ownership
 * ==========================================================
 */

add_shortcode('knx_hub_items', function () {

    // ── Session + role + ownership (fail-closed) ───────────
    $guard = knx_hub_management_guard();
    if (!$guard) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }
    [$session, $hub_id] = $guard;

    // ── Nonce & URLs ───────────────────────────────────────
    $nonce           = wp_create_nonce('knx_edit_hub_nonce');
    $dashboard_url   = esc_url(site_url('/hub-dashboard'));
    $settings_url    = esc_url(site_url('/hub-settings?hub_id=' . $hub_id));
    $manage_cats_url = esc_url(add_query_arg(['id' => $hub_id], site_url('/edit-item-categories')));
    $edit_item_url   = esc_url(site_url('/edit-item/'));

    ob_start(); ?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-hub-items.css?v=' . KNX_VERSION); ?>">

<style>
/* Availability fields */
.knx-avail-fields { margin-top: 10px; }
.knx-avail-fields .knx-form-group { margin-bottom: 8px; }
.knx-avail-fields label { font-size: 13px; color: #6b7280; }
.knx-days-checkboxes { display: flex; gap: 6px; flex-wrap: wrap; }
.knx-days-checkboxes label {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 6px;
    font-size: 12px; cursor: pointer; transition: all 0.1s ease;
}
.knx-days-checkboxes label:has(input:checked) {
    background: #0b793a; color: #fff; border-color: #0b793a;
}
.knx-days-checkboxes input { display: none; }
</style>

<div class="knx-content knx-with-sidebar">

  <div class="knx-items-wrapper"
      data-api-get="<?php echo esc_url(rest_url('knx/v1/get-hub-items')); ?>"
      data-api-add="<?php echo esc_url(rest_url('knx/v1/add-hub-item')); ?>"
      data-api-delete="<?php echo esc_url(rest_url('knx/v1/delete-hub-item')); ?>"
      data-api-reorder="<?php echo esc_url(rest_url('knx/v1/reorder-item')); ?>"
      data-api-cats="<?php echo esc_url(rest_url('knx/v1/get-item-categories')); ?>"
      data-hub-id="<?php echo esc_attr($hub_id); ?>"
      data-nonce="<?php echo esc_attr($nonce); ?>"
      data-edit-item-url="<?php echo $edit_item_url; ?>"
      data-mode="hub-management">

      <!-- ====== HEADER ====== -->
      <div class="knx-hubs-header">
        <h2><i class="fas fa-utensils"></i> Hub Menu Items</h2>

        <!-- ====== CONTROLS ====== -->
        <div class="knx-hubs-controls">

          <!-- Search -->
          <form class="knx-search-form" id="knxSearchForm">
            <input type="hidden" name="hub_id" value="<?php echo esc_attr($hub_id); ?>">
            <input type="text" id="knxSearchInput" name="search" placeholder="Search items...">
            <button type="submit"><i class="fas fa-search"></i></button>
          </form>

          <!-- Action Buttons -->
          <div class="knx-hubs-buttons">
            <a class="knx-btn-secondary" href="<?php echo $dashboard_url; ?>" aria-label="Back to Dashboard">
              <i class="fas fa-arrow-left"></i> Dashboard
            </a>

            <a class="knx-btn-secondary" href="<?php echo $settings_url; ?>" aria-label="Hub Settings">
              <i class="fas fa-cog"></i> Settings
            </a>

            <a class="knx-btn-yellow" href="<?php echo $manage_cats_url; ?>" aria-label="Manage Categories">
              <i class="fas fa-layer-group"></i> Manage Categories
            </a>

            <button id="knxAddItemBtn" class="knx-add-btn" aria-label="Add Item">
              <i class="fas fa-plus"></i> Add Item
            </button>
          </div>
        </div>
      </div>

      <!-- ====== ITEMS ====== -->
      <div id="knxCategoriesContainer" class="knx-categories-container"></div>

      <!-- ====== PAGINATION ====== -->
      <div class="knx-pagination"></div>
  </div>
</div>

<!-- ====== MODAL: ADD ITEM (with availability) ====== -->
<div id="knxAddItemModal" class="knx-modal" role="dialog" aria-modal="true" aria-labelledby="knxAddItemTitle">
  <div class="knx-modal-content">
    <h3 id="knxAddItemTitle">Add New Item</h3>
    <form id="knxAddItemForm" enctype="multipart/form-data">
      <div class="knx-form-group">
        <label for="knxItemCategorySelect">Category</label>
        <select name="category_id" id="knxItemCategorySelect" required></select>
      </div>
      <div class="knx-form-group">
        <label for="knxItemName">Name</label>
        <input type="text" id="knxItemName" name="name" placeholder="Item name" required>
      </div>
      <div class="knx-form-group">
        <label for="knxItemDescription">Description</label>
        <textarea id="knxItemDescription" name="description" placeholder="Optional description"></textarea>
      </div>
      <div class="knx-form-group">
        <label for="knxItemPrice">Price (USD)</label>
        <input type="number" id="knxItemPrice" step="0.01" name="price" placeholder="0.00" required>
      </div>
      <div class="knx-form-group">
        <label for="knxItemImageInput">Image</label>
        <input type="file" id="knxItemImageInput" name="item_image" accept="image/*" required>
      </div>

      <!-- Availability -->
      <div class="knx-form-group">
        <label for="knxItemAvailability">Availability</label>
        <select id="knxItemAvailability" name="availability_type">
          <option value="regular">Regular (always visible)</option>
          <option value="daily">Daily (specific days/times)</option>
          <option value="seasonal">Seasonal (date range)</option>
        </select>
      </div>

      <div class="knx-avail-fields" id="knxAvailDaily" style="display:none;">
        <div class="knx-form-group">
          <label>Days of Week</label>
          <div class="knx-days-checkboxes">
            <label><input type="checkbox" name="daily_days[]" value="1"> Mon</label>
            <label><input type="checkbox" name="daily_days[]" value="2"> Tue</label>
            <label><input type="checkbox" name="daily_days[]" value="3"> Wed</label>
            <label><input type="checkbox" name="daily_days[]" value="4"> Thu</label>
            <label><input type="checkbox" name="daily_days[]" value="5"> Fri</label>
            <label><input type="checkbox" name="daily_days[]" value="6"> Sat</label>
            <label><input type="checkbox" name="daily_days[]" value="7"> Sun</label>
          </div>
        </div>
        <div class="knx-form-group">
          <label>Time Range (optional)</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="time" name="daily_start_time" id="knxDailyStart">
            <span>—</span>
            <input type="time" name="daily_end_time" id="knxDailyEnd">
          </div>
        </div>
      </div>

      <div class="knx-avail-fields" id="knxAvailSeasonal" style="display:none;">
        <div class="knx-form-group">
          <label>Starts At</label>
          <input type="datetime-local" name="seasonal_starts_at" id="knxSeasonalStart">
        </div>
        <div class="knx-form-group">
          <label>Ends At</label>
          <input type="datetime-local" name="seasonal_ends_at" id="knxSeasonalEnd">
        </div>
      </div>

      <div class="knx-modal-actions">
        <button type="submit" class="knx-btn">Save</button>
        <button type="button" id="knxCloseModal" class="knx-btn-secondary">Cancel</button>
        <a class="knx-btn-link" id="knxGoManageCats" href="<?php echo $manage_cats_url; ?>">
          Manage categories
        </a>
      </div>
    </form>
  </div>
</div>

<!-- ====== MODAL: DELETE ITEM ====== -->
<div id="knxDeleteItemModal" class="knx-modal" role="dialog" aria-modal="true" aria-labelledby="knxDeleteItemTitle">
  <div class="knx-modal-content">
    <h3 id="knxDeleteItemTitle">Confirm delete</h3>
    <p>This action cannot be undone.</p>
    <div class="knx-modal-actions">
      <button type="button" class="knx-btn" id="knxConfirmDeleteItemBtn">Delete</button>
      <button type="button" class="knx-btn-secondary" id="knxCancelDeleteItemBtn">Cancel</button>
    </div>
    <input type="hidden" id="knxDeleteItemId" value="">
  </div>
</div>

<noscript>
  <p style="text-align:center;color:#b00020;margin-top:10px;">
    JavaScript is required for this page to function properly.
  </p>
</noscript>

<!-- Reuse canonical items JS + hub-items overlay -->
<script src="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-hub-items.js?v=' . KNX_VERSION); ?>"></script>
<script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/hub-items-script.js?v=' . KNX_VERSION); ?>"></script>

<?php
    return ob_get_clean();
});
