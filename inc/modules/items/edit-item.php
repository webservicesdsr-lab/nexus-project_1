<?php
if (!defined('ABSPATH')) exit;

/**
 * Edit Item Page — Nexus Workspace Layout
 * - Real split layout for tablet landscape and desktop
 * - Left column: item form
 * - Right column: modifiers workspace
 * - Mobile-first structure with progressive enhancement
 */

add_shortcode('knx_edit_item', function () {

  $session = knx_get_session();
  if (!$session || !in_array($session->role, ['manager', 'super_admin', 'hub_management', 'menu_uploader'], true)) {
    wp_safe_redirect(site_url('/login'));
    exit;
  }

  $hub_id  = isset($_GET['hub_id']) ? intval($_GET['hub_id']) : 0;
  $item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;

  if (!$hub_id || !$item_id) {
    echo '<div class="knx-warning">Invalid or missing item parameters.</div>';
    return;
  }

  // Ownership guard: hub_management can only edit items in their assigned hubs
  if ($session->role === 'hub_management') {
      $managed_ids = function_exists('knx_get_managed_hub_ids')
          ? knx_get_managed_hub_ids((int) $session->user_id)
          : [];
      if (!in_array($hub_id, $managed_ids, true)) {
          wp_safe_redirect(site_url('/hub-dashboard'));
          exit;
      }
  }

  $nonce = wp_create_nonce('knx_edit_hub_nonce');

  // Role-aware back link: hub_management → /hub-items, others → /edit-hub-items
  if ($session->role === 'hub_management') {
      $back_to_items = esc_url(add_query_arg(['hub_id' => $hub_id], site_url('/hub-items')));
  } else {
      $back_to_items = esc_url(add_query_arg(['id' => $hub_id], site_url('/edit-hub-items')));
  }

  ob_start();
  ?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-item.css?v=' . KNX_VERSION); ?>">

<div class="knx-content">
  <div
    class="knx-edit-item-wrapper"
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
    data-nonce="<?php echo esc_attr($nonce); ?>"
  >

    <div class="knx-edit-header">
      <div class="knx-edit-header__title">
        <h2><i class="fas fa-pen"></i> Edit item</h2>
        <p>Update the item details on the left and manage its groups on the right.</p>
      </div>

      <div class="knx-edit-header__actions">
        <a href="<?php echo $back_to_items; ?>" class="knx-btn-secondary">
          <i class="fas fa-arrow-left"></i> Back to items
        </a>
      </div>
    </div>

    <div class="knx-edit-main">
      <!-- Left column -->
      <section class="knx-edit-left">
        <div class="knx-panel knx-panel-form">
          <div class="knx-panel-head">
            <h3>Item information</h3>
            <span class="knx-panel-head__meta">Core details</span>
          </div>

          <form id="knxEditItemForm" enctype="multipart/form-data">
            <div class="knx-form-grid">
              <div class="knx-form-group full">
                <label for="knxItemName">Name</label>
                <input type="text" id="knxItemName" name="name" placeholder="Item name" required>
              </div>

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

              <div class="knx-form-group full">
                <label for="knxItemDescription">Description</label>
                <textarea id="knxItemDescription" name="description" placeholder="Optional description"></textarea>
              </div>

              <div class="knx-form-group full knx-form-group-image">
                <label for="knxItemImage">Image</label>

                <div id="knxItemPreview" class="knx-item-preview"></div>

                <input type="file" id="knxItemImage" name="item_image" accept="image/*">
                <small class="knx-input-help">You can also paste an image from the clipboard.</small>
              </div>
            </div>

            <div class="knx-actions-centered">
              <button type="submit" class="knx-btn knx-btn-xl">
                <i class="fas fa-save"></i> Save changes
              </button>

              <a href="<?php echo $back_to_items; ?>" class="knx-btn-secondary knx-btn-xl">
                Cancel
              </a>
            </div>
          </form>
        </div>
      </section>

      <!-- Right column -->
      <aside class="knx-edit-right">
        <div class="knx-panel knx-panel-choices">
          <div class="knx-panel-head knx-panel-head--choices">
            <div>
              <h3>Available choices</h3>
              <span class="knx-panel-head__meta">Groups and options</span>
            </div>

            <div class="knx-choices-toolbar">
              <button type="button" id="knxBrowseGlobalBtn" class="knx-btn knx-btn-outline knx-btn-xl">
                <i class="fas fa-globe"></i> Browse library
              </button>

              <button type="button" id="knxAddModifierBtn" class="knx-btn knx-btn-xl">
                <i class="fas fa-plus"></i> Add group
              </button>
            </div>
          </div>

          <div class="knx-modifiers-shell">
            <div id="knxModifiersList" class="knx-modifiers-list">
              <div class="knx-loading-small">Loading…</div>
            </div>
          </div>
        </div>
      </aside>
    </div>

  </div>
</div>

<script src="<?php echo esc_url(KNX_URL . 'inc/modules/items/edit-item.js?v=' . KNX_VERSION); ?>"></script>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/core/resources/knx-price-calc.css?v=' . KNX_VERSION); ?>">
<script src="<?php echo esc_url(KNX_URL . 'inc/modules/core/resources/knx-price-calc.js?v=' . KNX_VERSION); ?>"></script>

<?php
  return ob_get_clean();
});