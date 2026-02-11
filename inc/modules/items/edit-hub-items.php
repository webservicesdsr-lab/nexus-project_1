<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Edit Hub Items (v3.3 Production FINAL CLEAN)
 * ----------------------------------------------------------
 * ✅ Compatible con Sidebar y Toast
 * ✅ REST Real (get/add/delete/reorder)
 * ✅ Categorías dinámicas (knx_items_categories)
 * ✅ Botón Edit apunta a /edit-item/?hub_id=&id=
 * ✅ Sin botón Manage Addons
 * ✅ Layout alineado con CSS v6.0
 * ==========================================================
 */

add_shortcode('knx_edit_hub_items', function() {

    // --- Auth Guard ---
    $session = knx_get_session();
    if (!$session || !in_array($session->role, ['manager','super_admin','hub_management','menu_uploader'])) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // --- Hub ID Required ---
    $hub_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$hub_id) {
        echo '<div class="knx-warning">Invalid or missing Hub ID.</div>';
        return;
    }

    // --- Nonce & URLs ---
    $nonce           = wp_create_nonce('knx_edit_hub_nonce');
    $back_hubs_url   = esc_url(site_url('/hubs'));
    $manage_cats_url = esc_url(add_query_arg(['id' => $hub_id], site_url('/edit-item-categories')));
    $edit_item_url   = esc_url(site_url('/edit-item/'));

    ob_start(); ?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-hub-items.css?v=' . KNX_VERSION); ?>">

<div class="knx-content knx-with-sidebar">

  <div class="knx-items-wrapper"
      data-api-get="<?php echo esc_url(rest_url('knx/v1/get-hub-items')); ?>"
      data-api-add="<?php echo esc_url(rest_url('knx/v1/add-hub-item')); ?>"
      data-api-delete="<?php echo esc_url(rest_url('knx/v1/delete-hub-item')); ?>"
      data-api-reorder="<?php echo esc_url(rest_url('knx/v1/reorder-item')); ?>"
      data-api-upload-csv="<?php echo esc_url(rest_url('knx/v1/upload-hub-items-csv')); ?>"
      data-api-cats="<?php echo esc_url(rest_url('knx/v1/get-item-categories')); ?>"
      data-hub-id="<?php echo esc_attr($hub_id); ?>"
      data-nonce="<?php echo esc_attr($nonce); ?>"
      data-edit-item-url="<?php echo $edit_item_url; ?>">

      <!-- ====== HEADER ====== -->
      <div class="knx-hubs-header">
        <h2><i class="fas fa-utensils"></i> Hub Menu Items</h2>

        <!-- ====== CONTROLS ====== -->
        <div class="knx-hubs-controls">

          <!-- Search -->
          <form class="knx-search-form" id="knxSearchForm">
            <input type="hidden" name="id" value="<?php echo esc_attr($hub_id); ?>">
            <input type="text" id="knxSearchInput" name="search" placeholder="Search items...">
            <button type="submit"><i class="fas fa-search"></i></button>
          </form>

          <!-- Action Buttons -->
            <div class="knx-hubs-buttons">
            <a class="knx-btn-secondary" href="<?php echo $back_hubs_url; ?>">
              <i class="fas fa-arrow-left"></i> Back to Hubs
            </a>

            <a class="knx-btn-yellow" href="<?php echo $manage_cats_url; ?>">
              <i class="fas fa-layer-group"></i> Manage Categories
            </a>

            <button id="knxAddItemBtn" class="knx-add-btn">
              <i class="fas fa-plus"></i> Add Item
            </button>

            <button id="knxUploadCsvBtn" class="knx-btn-secondary" title="Upload CSV">
              <i class="fas fa-file-csv"></i> Upload CSV
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

<!-- ====== MODAL: ADD ITEM ====== -->
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

  <!-- ====== MODAL: CSV UPLOAD ====== -->
  <div id="knxUploadCsvModal" class="knx-modal" role="dialog" aria-modal="true" aria-labelledby="knxUploadCsvTitle">
    <div class="knx-modal-content">
      <h3 id="knxUploadCsvTitle">Upload Items CSV</h3>
      <p>Expected columns: <code>name</code> (required), <code>price</code> (required), <code>category_id</code> or <code>category_name</code>, <code>description</code>, <code>status</code>, <code>image_url</code>.</p>
      <form id="knxUploadCsvForm" enctype="multipart/form-data">
        <div class="knx-form-group">
          <label for="knxCsvFile">CSV file</label>
          <input type="file" id="knxCsvFile" name="items_csv" accept=".csv, text/csv" required>
        </div>
        <div class="knx-modal-actions">
          <button type="submit" class="knx-btn">Upload</button>
          <button type="button" id="knxCloseCsvModal" class="knx-btn-secondary">Cancel</button>
        </div>
      </form>
    </div>
  </div>

<noscript>
  <p style="text-align:center;color:#b00020;margin-top:10px;">
    JavaScript is required for this page to function properly.
  </p>
</noscript>

<script src="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-hub-items.js?v=' . KNX_VERSION); ?>"></script>

<style>
@media (max-width: 900px) {
  .knx-with-sidebar { margin-left: 58px; }
}

@media (max-width: 1012px) {
}
</style>

<?php
    return ob_get_clean();
});
