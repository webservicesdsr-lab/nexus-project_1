<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus - Edit Item Categories (v2.7 Production)
 * ----------------------------------------------------------
 * ✅ Integrado al Sidebar global y Toast universal
 * ✅ REST real: get-item-categories, save, reorder, toggle, delete
 * ✅ Layout coherente con edit-hub-items
 * ✅ Sin navbar superior
 * ✅ Sort automático, sin input manual
 * ==========================================================
 */

add_shortcode('knx_edit_item_categories', function () {

    $session = knx_get_session();
    if (!$session || !in_array($session->role, ['manager','super_admin','hub_management','menu_uploader'])) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $hub_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$hub_id) {
        echo '<div class="knx-warning">Invalid or missing Hub ID.</div>';
        return;
    }

    $nonce = wp_create_nonce('knx_edit_hub_nonce');
    $back_to_items = esc_url(add_query_arg(['id' => $hub_id], site_url('/edit-hub-items')));

    ob_start(); ?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-hub-items.css?v=' . KNX_VERSION); ?>">

<div class="knx-content knx-with-sidebar">
  <div class="knx-items-wrapper"
       data-api-get="<?php echo esc_url(rest_url('knx/v1/get-item-categories')); ?>"
       data-api-save="<?php echo esc_url(rest_url('knx/v1/save-item-category')); ?>"
       data-api-reorder="<?php echo esc_url(rest_url('knx/v1/reorder-item-category')); ?>"
       data-api-toggle="<?php echo esc_url(rest_url('knx/v1/toggle-item-category')); ?>"
       data-api-delete="<?php echo esc_url(rest_url('knx/v1/delete-item-category')); ?>"
       data-hub-id="<?php echo esc_attr($hub_id); ?>"
       data-nonce="<?php echo esc_attr($nonce); ?>">

    <div class="knx-hubs-header">
      <h2><i class="fas fa-layer-group"></i> Item Categories</h2>
      <div class="knx-hubs-controls">
        <a class="knx-btn-secondary" href="<?php echo $back_to_items; ?>">
          <i class="fas fa-arrow-left"></i> Back to Items
        </a>
        <button id="knxAddCatBtn" class="knx-add-btn"><i class="fas fa-plus"></i> Add Category</button>
      </div>
    </div>

    <div id="knxCategoriesList" class="knx-categories-list">
      <p style="text-align:center;">Loading categories...</p>
    </div>
  </div>
</div>

<!-- Modal: Add/Edit Category -->
<div id="knxCatModal" class="knx-modal" role="dialog" aria-modal="true" aria-labelledby="knxCatModalTitle">
  <div class="knx-modal-content">
    <h3 id="knxCatModalTitle">Add Category</h3>
    <form id="knxCatForm">
      <input type="hidden" name="id" id="knxCatId" value="">
      <div class="knx-form-group">
        <label for="knxCatName">Name</label>
        <input type="text" id="knxCatName" name="name" required>
      </div>
      <div class="knx-modal-actions">
        <button type="submit" class="knx-btn">Save</button>
        <button type="button" id="knxCatCancel" class="knx-btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Delete Category -->
<div id="knxDeleteCatModal" class="knx-modal" role="dialog" aria-modal="true" aria-labelledby="knxDeleteCatTitle">
  <div class="knx-modal-content">
    <h3 id="knxDeleteCatTitle">Confirm delete</h3>
    <p>This will permanently remove the category.</p>
    <div class="knx-modal-actions">
      <button type="button" class="knx-btn" id="knxConfirmDeleteCatBtn">Delete</button>
      <button type="button" class="knx-btn-secondary" id="knxCancelDeleteCatBtn">Cancel</button>
    </div>
    <input type="hidden" id="knxDeleteCatId" value="">
  </div>
</div>

<script src="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-item-categories.js?v=' . KNX_VERSION); ?>"></script>

<style>
.knx-with-sidebar {
  margin-left: 230px;
  min-height: 100vh;
  padding-bottom: 40px;
}
@media (max-width: 900px) {
  .knx-with-sidebar { margin-left: 70px; }
}
</style>

<?php
    return ob_get_clean();
});
