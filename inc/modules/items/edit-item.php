<?php
if (!defined('ABSPATH')) exit;

/**
 * Edit Item Page — Clean Row Meta (v2.1)
 * - Layout reordenado: Name → (Price|Category) → Description → Image
 * - Botones centrados en columna
 * - Header de "Available choices" centrado con botones debajo (full width)
 */

add_shortcode('knx_edit_item', function () {

  $session = knx_get_session();
  if (!$session || !in_array($session->role, ['manager','super_admin','hub_management','menu_uploader'])) {
    wp_safe_redirect(site_url('/login'));
    exit;
  }

  $hub_id  = isset($_GET['hub_id']) ? intval($_GET['hub_id']) : 0;
  $item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
  if (!$hub_id || !$item_id) {
    echo '<div class="knx-warning">Invalid or missing item parameters.</div>';
    return;
  }

  $nonce = wp_create_nonce('knx_edit_hub_nonce');
  $back_to_items = esc_url(add_query_arg(['id' => $hub_id], site_url('/edit-hub-items')));
  ob_start(); ?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-item.css?v=' . KNX_VERSION); ?>">

<div class="knx-content">
  <div class="knx-edit-item-wrapper"
       data-api-get="<?php echo esc_url(rest_url('knx/v1/get-item-details')); ?>"
       data-api-update="<?php echo esc_url(rest_url('knx/v1/update-item')); ?>"
       data-api-cats="<?php echo esc_url(rest_url('knx/v1/get-item-categories')); ?>"
       data-api-modifiers="<?php echo esc_url(rest_url('knx/v1/get-item-modifiers')); ?>"
       data-api-global-modifiers="<?php echo esc_url(rest_url('knx/v1/get-global-modifiers')); ?>"
       data-api-clone-modifier="<?php echo esc_url(rest_url('knx/v1/clone-global-modifier')); ?>"
       data-api-save-modifier="<?php echo esc_url(rest_url('knx/v1/save-modifier')); ?>"
       data-api-delete-modifier="<?php echo esc_url(rest_url('knx/v1/delete-modifier')); ?>"
       data-api-reorder-modifier="<?php echo esc_url(rest_url('knx/v1/reorder-modifier')); ?>"
       data-api-save-option="<?php echo esc_url(rest_url('knx/v1/save-modifier-option')); ?>"
       data-api-delete-option="<?php echo esc_url(rest_url('knx/v1/delete-modifier-option')); ?>"
       data-api-reorder-option="<?php echo esc_url(rest_url('knx/v1/reorder-modifier-option')); ?>"
       data-hub-id="<?php echo esc_attr($hub_id); ?>"
       data-item-id="<?php echo esc_attr($item_id); ?>"
       data-nonce="<?php echo esc_attr($nonce); ?>">

    <div class="knx-edit-header">
      <h2><i class="fas fa-pen"></i> Edit item</h2>
      <a href="<?php echo $back_to_items; ?>" class="knx-btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to items
      </a>
    </div>

    <form id="knxEditItemForm" enctype="multipart/form-data">
      <div class="knx-form-grid">
        <!-- Name (full width primero) -->
        <div class="knx-form-group full">
          <label for="knxItemName">Name</label>
          <input type="text" id="knxItemName" name="name" placeholder="Item name" required>
        </div>

        <!-- Row 3 columnas: Price | Category | Status -->
        <div class="knx-form-row-three">
          <div class="knx-form-group">
            <label for="knxItemPrice">Price (USD)</label>
            <input type="number" id="knxItemPrice" step="0.01" name="price" placeholder="0.00" required>
          </div>
          <div class="knx-form-group">
            <label for="knxItemCategory">Category</label>
            <select id="knxItemCategory" name="category_id" required></select>
          </div>
          <div class="knx-form-group">
            <label for="knxItemStatus">Status</label>
            <select id="knxItemStatus" name="status" required>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>

        <!-- Description -->
        <div class="knx-form-group full">
          <label for="knxItemDescription">Description</label>
          <textarea id="knxItemDescription" name="description" placeholder="Optional description"></textarea>
        </div>

        <!-- Image -->
        <div class="knx-form-group full">
          <label for="knxItemImage">Image</label>
          <input type="file" id="knxItemImage" name="item_image" accept="image/*">
          <div id="knxItemPreview" class="knx-item-preview"></div>
        </div>
      </div>

      <!-- Botonera centrada en columna -->
      <div class="knx-actions-centered">
        <button type="submit" class="knx-btn knx-btn-xl"><i class="fas fa-save"></i> Save changes</button>
        <a href="<?php echo $back_to_items; ?>" class="knx-btn-secondary knx-btn-xl">Cancel</a>
      </div>
    </form>

    <!-- Available choices centrado + botones debajo en columna -->
   
      <div class="knx-choices-header center-stack">
        <h3>Available choices</h3>
        <div class="knx-choices-toolbar">
          <button type="button" id="knxBrowseGlobalBtn" class="knx-btn knx-btn-outline knx-btn-xl">
            <i class="fas fa-globe"></i> Browse library
          </button>
          <button type="button" id="knxAddModifierBtn" class="knx-btn knx-btn-xl">
            <i class="fas fa-plus"></i> Add group
          </button>
        </div>
      </div>

      <div id="knxModifiersList" class="knx-modifiers-list">
        <div class="knx-loading-small">Loading…</div>
      </div>

  </div>
</div>

<script src="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-item.js?v=' . KNX_VERSION); ?>"></script>
<?php
  return ob_get_clean();
});
