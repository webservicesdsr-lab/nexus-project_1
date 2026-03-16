<?php
/**
 * Plugin Name:  KNX Menu Studio
 * Description:  Shortcode [knx_menu_studio] — modular workspace for menu data tools.
 * Version:      2.1.0
 * Author:       Our Local Collective
 * Text Domain:  knx-menu-studio
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'KNX_STUDIO_VERSION', '2.1.0' );
define( 'KNX_STUDIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'KNX_STUDIO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Return version string for assets using filemtime when possible.
 *
 * @param string $relative_path Relative file path from plugin root.
 * @return int|string
 */
function knx_studio_asset_ver( $relative_path ) {
    $abs = KNX_STUDIO_DIR . $relative_path;
    return file_exists( $abs ) ? filemtime( $abs ) : KNX_STUDIO_VERSION;
}

define( 'KNX_STUDIO_ALLOWED_ROLES', [
    'super_admin',
    'manager',
    'hub_management',
    'menu_uploader',
] );

add_shortcode( 'knx_menu_studio', 'knx_menu_studio_shortcode' );

/**
 * Main shortcode router.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function knx_menu_studio_shortcode( $atts ) {
    $atts = shortcode_atts(
        [
            'screen' => 'home',
        ],
        $atts,
        'knx_menu_studio'
    );

    if ( ! function_exists( 'knx_get_session' ) ) {
        return '';
    }

    $session = knx_get_session();

    if ( ! $session || ! in_array( $session->role, KNX_STUDIO_ALLOWED_ROLES, true ) ) {
        return '';
    }

    ob_start();

    echo '<link rel="stylesheet" href="' . esc_url( KNX_STUDIO_URL . 'assets/studio.css?v=' . knx_studio_asset_ver( 'assets/studio.css' ) ) . '">';

    switch ( $atts['screen'] ) {
        case 'capture':
            knx_studio_render_capture( $session );
            break;

        case 'harvest':
            knx_studio_render_harvest( $session );
            break;

        default:
            knx_studio_render_home( $session );
            break;
    }

    return ob_get_clean();
}

/**
 * Render Studio home.
 *
 * @param object $session KNX session object.
 * @return void
 */
function knx_studio_render_home( $session ) {
    $capture_url = esc_url( site_url( '/menu-studio/capture/' ) );
    $home_url    = esc_url( site_url( '/' ) );
    $username    = esc_html( $session->username ?? 'Operator' );
    ?>
    <div class="ms-home">
      <div class="ms-home__header">
        <h1 class="ms-home__title">KNX Menu Studio</h1>
        <p class="ms-home__subtitle">Internal tools for menu data operations</p>
        <span class="ms-home__user">Logged in as <strong><?php echo $username; ?></strong></span>
      </div>

      <div class="ms-home__cards">
        <a class="ms-tool-card" href="<?php echo $capture_url; ?>">
          <div class="ms-tool-card__icon">📋</div>
          <h2 class="ms-tool-card__title">Migration Capture</h2>
          <p class="ms-tool-card__desc">
            Capture menu data from images and screenshots for new hub onboarding.
            Touch-first, app-like, CSV export.
          </p>
          <span class="ms-tool-card__cta">Open Tool →</span>
        </a>

        <div class="ms-tool-card ms-tool-card--disabled">
          <div class="ms-tool-card__icon">🌐</div>
          <h2 class="ms-tool-card__title">Web Harvest</h2>
          <p class="ms-tool-card__desc">
            Extract and structure menu data from websites and online ordering platforms.
          </p>
          <span class="ms-badge-soon">Coming Soon</span>
        </div>
      </div>

      <a class="ms-home__back" href="<?php echo $home_url; ?>">← Back to KNX</a>
    </div>
    <?php
}

/**
 * Render capture screen.
 *
 * @param object $session KNX session object.
 * @return void
 */
function knx_studio_render_capture( $session ) {
    $upload_nonce = wp_create_nonce( 'knx_studio_upload_action' );
    $ajax_url     = admin_url( 'admin-ajax.php' );
    $home_url     = site_url( '/menu-studio/' );

    echo '<link rel="stylesheet" href="' . esc_url( KNX_STUDIO_URL . 'assets/migration-core-layout.css?v='       . knx_studio_asset_ver( 'assets/migration-core-layout.css' ) ) . '">';
    echo '<link rel="stylesheet" href="' . esc_url( KNX_STUDIO_URL . 'assets/migration-modals-components.css?v=' . knx_studio_asset_ver( 'assets/migration-modals-components.css' ) ) . '">';
    echo '<link rel="stylesheet" href="' . esc_url( KNX_STUDIO_URL . 'assets/migration-interactive.css?v='       . knx_studio_asset_ver( 'assets/migration-interactive.css' ) ) . '">';
    echo '<link rel="stylesheet" href="' . esc_url( KNX_STUDIO_URL . 'assets/migration-responsive.css?v='        . knx_studio_asset_ver( 'assets/migration-responsive.css' ) ) . '">';
    echo '<link rel="stylesheet" href="' . esc_url( KNX_STUDIO_URL . 'assets/tablet-app-mode.css?v='             . knx_studio_asset_ver( 'assets/tablet-app-mode.css' ) ) . '">';

    ?>
    <div class="mc-shell"
         data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
         data-nonce="<?php echo esc_attr( $upload_nonce ); ?>"
         data-role="<?php echo esc_attr( $session->role ); ?>"
         data-app-mode="false">

      <header class="mc-topbar">
        <a class="mc-topbar__back" href="<?php echo esc_url( $home_url ); ?>">‹</a>
        <h1 class="mc-topbar__title">Migration Capture</h1>

        <div class="mc-cat-bar">
          <div class="mc-cat-bar__select-wrap">
            <input
              id="ctx-category"
              class="mc-cat-bar__input"
              placeholder="Select or create category…"
              autocomplete="off"
              list="cat-datalist">
            <datalist id="cat-datalist"></datalist>
          </div>
        </div>

        <div class="mc-topbar__actions">
          <button id="btn-upload-top" class="mc-topbar__upload-btn" disabled title="Select a category first">📸 Upload</button>
          <button id="btn-new-item" class="mc-topbar__new-item-btn" disabled>+ New Item</button>
          <button id="btn-export-top" class="mc-topbar__export-btn" disabled>Export CSV</button>
          <button id="btn-reset-workspace" class="mc-topbar__reset-btn" title="Reset workspace">⟲</button>
        </div>
      </header>

      <input type="file" id="image-file-input" accept="image/*" multiple style="display:none;">

      <div class="mc-workspace">
        <div class="mc-builder" id="mc-builder">

          <div id="builder-empty" class="mc-builder__empty">
            <div class="mc-builder__empty-icon">📋</div>
            <h2>Migration Capture</h2>
            <p>Select a <strong>category</strong> above to start building a KNX item.</p>
          </div>

          <div id="builder-content" class="mc-builder__content" style="display:none;">

            <div class="mc-live-modal-shell">
              <div class="mc-live-modal-scroll">

                <div class="mc-modal-dialog mc-modal-dialog--builder">

                  <div class="mc-modal-header-card">
                    <div class="mc-modal-header-card__row">
                      <input
                        id="builder-item-name"
                        class="mc-modal-title-input"
                        placeholder="Item name"
                        autocomplete="off">
                      <button class="mc-pick-btn" data-pick-target="builder-item-name" title="Pick from active image">⎗</button>
                    </div>

                    <div class="mc-modal-desc-row">
                      <textarea
                        id="builder-description"
                        class="mc-modal-desc-input"
                        placeholder="Item description"
                        rows="2"
                        autocomplete="off"></textarea>
                      <button class="mc-pick-btn" data-pick-target="builder-description" title="Pick from active image">⎗</button>
                    </div>

                    <div class="mc-modal-price-row">
                      <span class="mc-modal-price-label">$</span>
                      <input
                        id="builder-base-price"
                        class="mc-modal-price-input"
                        placeholder="0.00"
                        inputmode="decimal"
                        autocomplete="off">
                      <button class="mc-pick-btn" data-pick-target="builder-base-price" title="Pick from active image">⎗</button>
                    </div>
                  </div>

                  <div id="builder-groups" class="mc-modal-groups mc-modal-groups--grid"></div>

                  <button id="btn-add-group" class="mc-modal-add-group-btn">+ Add Group</button>




<div id="group-draft" class="mc-group-draft">

  <div class="mc-group-draft__header">
    <div class="mc-group-draft__header-info">
      <span class="mc-group-draft__eyebrow">New Group</span>
      <span id="draft-group-summary" class="mc-group-draft__summary">Start with the group title, then add options.</span>
    </div>

    <div class="mc-group-draft__header-actions">
      <button id="btn-group-guided-capture" class="mc-group-draft__guided-btn" type="button" title="Capture from image">📷 OCR</button>
      <button id="btn-show-raw-ocr" class="mc-group-draft__raw-btn" type="button" style="display:none;">Raw</button>
      <button id="btn-cancel-group" class="mc-group-draft__cancel" type="button" title="Cancel">✕</button>
    </div>
  </div>

  <div class="mc-group-draft__title-row">
    <div class="mc-group-draft__field mc-group-draft__field--hero">
      <label class="mc-group-draft__field-label" for="draft-group-name">Group Title</label>
      <div class="mc-group-draft__field-control">
        <input
          id="draft-group-name"
          class="mc-group-draft__hero-input"
          placeholder="Choose Your Size"
          autocomplete="off">
        <button class="mc-pick-btn" data-pick-target="draft-group-name" type="button" title="Pick from image">⎗</button>
      </div>
    </div>
  </div>

  <div class="mc-group-draft__config-card">
    <div class="mc-group-draft__config-head">
      <span class="mc-group-draft__section-title">Group Rules</span>

      <div class="mc-group-draft__meta-strip">
        <span id="draft-pill-required" class="mc-group-draft__meta-pill">Optional</span>
        <span id="draft-pill-type" class="mc-group-draft__meta-pill">Multi</span>
        <span id="draft-pill-action" class="mc-group-draft__meta-pill mc-group-draft__meta-pill--add">Add</span>
      </div>
    </div>

    <div class="mc-group-draft__seg-bar">

      <div class="mc-seg-group">
        <span class="mc-seg-group__label">Action</span>
        <div class="mc-seg" data-chip-group="action">
          <button class="mc-seg__btn mc-seg__btn--add mc-seg__btn--active" type="button" data-value="add">Add</button>
          <button class="mc-seg__btn mc-seg__btn--remove" type="button" data-value="remove">Remove</button>
        </div>
      </div>

      <div class="mc-seg-group">
        <span class="mc-seg-group__label">Required</span>
        <div class="mc-seg" data-chip-group="required">
          <button class="mc-seg__btn" type="button" data-value="1">Yes</button>
          <button class="mc-seg__btn mc-seg__btn--active" type="button" data-value="0">No</button>
        </div>
      </div>

      <div class="mc-seg-group">
        <span class="mc-seg-group__label">Type</span>
        <div class="mc-seg" data-chip-group="type">
          <button class="mc-seg__btn" type="button" data-value="single">Single</button>
          <button class="mc-seg__btn mc-seg__btn--active" type="button" data-value="multiple">Multi</button>
        </div>
      </div>

    </div>
  </div>

  <div class="mc-group-draft__quick-add">
    <div class="mc-group-draft__quick-add-head">
      <span class="mc-group-draft__section-title">Quick Add Option</span>
      <span class="mc-group-draft__quick-add-help">Add options fast, then review them below.</span>
    </div>

    <div class="mc-group-draft__option-row">
      <div class="mc-group-draft__quick-cell mc-group-draft__quick-cell--name">
        <label class="mc-group-draft__field-label" for="draft-option-name">Option Name</label>
        <div class="mc-group-draft__field-control">
          <input
            id="draft-option-name"
            class="mc-group-draft__opt-name"
            placeholder="Half Pan"
            autocomplete="off">
          <button class="mc-pick-btn mc-pick-btn--sm" data-pick-target="draft-option-name" type="button" title="Pick from image">⎗</button>
        </div>
      </div>

      <div class="mc-group-draft__quick-cell mc-group-draft__quick-cell--price">
        <div class="mc-group-draft__price-field">
          <label class="mc-group-draft__field-label" for="draft-option-price">Price</label>
          <div class="mc-group-draft__field-control">
            <input
              id="draft-option-price"
              class="mc-group-draft__opt-price"
              placeholder="0.00"
              inputmode="decimal"
              autocomplete="off">
            <button class="mc-pick-btn mc-pick-btn--sm" data-pick-target="draft-option-price" type="button" title="Pick from image">⎗</button>
          </div>
        </div>

        <button id="btn-quick-fill" class="mc-quick-fill" type="button" title="Set $0.00">$0</button>
        <button id="btn-add-option" class="mc-group-draft__add-opt" type="button" title="Add option">+</button>
      </div>
    </div>
  </div>

  <div class="mc-group-draft__options-head">
    <span class="mc-group-draft__section-title">Options</span>
  </div>

  <div id="draft-options-grid" class="mc-draft-options-grid"></div>

  <button id="btn-commit-group" class="mc-group-draft__commit" type="button" disabled>✓ Save Group</button>

</div>

                </div>
              </div>
            </div>

            <div class="mc-builder__footer">
              <button id="btn-add-to-list" class="mc-builder__add-btn" disabled>Add to List</button>
              <button id="btn-clear-item" class="mc-builder__clear-btn">Clear Item</button>
            </div>

          </div>
        </div>

        <div class="mc-session" id="mc-session">
          <div class="mc-session__header">
            <span class="mc-session__title" id="session-title">Session — 0 items</span>

            <div class="mc-session__filters">
              <input
                id="session-filter-search"
                class="mc-session__search"
                placeholder="Search items…"
                autocomplete="off">
            </div>

            <div class="mc-session__actions">
              <button id="btn-export-bottom" class="mc-session__export-btn" disabled>Export CSV</button>
            </div>
          </div>

          <div class="mc-session__scroll">
            <div id="session-empty" class="mc-session__empty">
              Build items above, then "Add to List" to see them here.
            </div>

            <table id="session-table" class="mc-session-table" style="display:none;">
              <thead>
                <tr>
                  <th>Category</th>
                  <th>Item Name</th>
                  <th>Description</th>
                  <th>Base Price</th>
                  <th>Groups</th>
                  <th>Images</th>
                  <th>Status</th>
                  <th class="mc-session-table__th-actions"></th>
                </tr>
              </thead>
              <tbody id="session-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <div id="img-bubble" class="mc-bubble" style="display:none;">
        <div class="mc-bubble__mini" id="bubble-mini">
          <img id="bubble-mini-img" class="mc-bubble__mini-img" src="" alt="Menu reference">
          <span class="mc-bubble__mini-badge">📷</span>
        </div>

        <div class="mc-bubble__expanded" id="bubble-expanded" style="display:none;">
          <div class="mc-bubble__expanded-header">
            <div class="mc-bubble__expanded-header-left">
              <span class="mc-bubble__expanded-title">Source Images</span>
              <span id="bubble-image-counter" class="mc-bubble__expanded-subtitle">1 / 1 active</span>
            </div>

            <div class="mc-bubble__expanded-actions">
              <button id="btn-bubble-add-more" class="mc-bubble__action-btn">Add More</button>
              <button id="btn-bubble-replace" class="mc-bubble__action-btn">Replace All</button>
              <button id="btn-bubble-minimize" class="mc-bubble__action-btn">Minimize</button>
              <button id="btn-bubble-close" class="mc-bubble__action-btn mc-bubble__action-btn--close">×</button>
            </div>
          </div>

          <div id="bubble-image-rail" class="mc-bubble__image-rail" style="display:none;"></div>

          <div class="mc-bubble__expanded-body" id="bubble-expanded-body">
            <div class="mc-bubble__source-panel">
              <div class="mc-bubble__stage-wrap">
                <div id="bubble-stage" class="mc-bubble__stage">
                  <img id="bubble-expanded-img" class="mc-bubble__expanded-img" src="" alt="Menu reference">
                  <canvas id="ocr-canvas" class="mc-bubble__ocr-canvas"></canvas>
                </div>
              </div>
            </div>

            <div id="item-guided-preview" class="mc-guided-item-preview" style="display:none;">
              <div class="mc-guided-item-preview__head">
                <div>
                  <span class="mc-guided-item-preview__eyebrow">Guided Capture</span>
                  <h3 class="mc-guided-item-preview__title">Live Item Preview</h3>
                </div>
                <button id="btn-restart-item-capture" class="mc-guided-item-preview__restart">Restart</button>
              </div>

              <div class="mc-guided-item-preview__steps">
                <span id="preview-step-name" class="mc-guided-item-preview__step">Name</span>
                <span id="preview-step-description" class="mc-guided-item-preview__step">Description</span>
                <span id="preview-step-price" class="mc-guided-item-preview__step">Price</span>
              </div>

              <div class="mc-guided-item-preview__card">
                <div id="preview-item-name" class="mc-guided-item-preview__name">Item name</div>
                <div id="preview-item-description" class="mc-guided-item-preview__description">Item description</div>
                <div class="mc-guided-item-preview__price-row">
                  <span class="mc-guided-item-preview__price-label">$</span>
                  <span id="preview-item-price" class="mc-guided-item-preview__price">0.00</span>
                </div>
              </div>

              <div id="preview-item-hint" class="mc-guided-item-preview__hint">
                Select the item name from the image.
              </div>
            </div>
          </div>

          <div class="mc-bubble__expanded-footer" id="bubble-expanded-footer">
            <span class="mc-bubble__ocr-hint" id="ocr-hint">Draw over the text on the active image to extract it</span>

            <div id="ocr-guide-actions" class="mc-bubble__guide-actions" style="display:none;">
              <span id="ocr-guide-step" class="mc-bubble__guide-step" style="display:none;"></span>
              <button id="btn-guide-skip" class="mc-bubble__guide-btn">Skip</button>
            </div>
          </div>
        </div>
      </div>

      <div id="eye-preview-overlay" class="mc-eye-overlay" style="display:none;">
        <div class="mc-eye-overlay__backdrop"></div>
        <div class="mc-eye-overlay__dialog">
          <button id="btn-eye-close" class="mc-eye-overlay__close">×</button>
          <div id="eye-preview-body" class="mc-eye-overlay__body"></div>
        </div>
      </div>

      <div id="ocr-capture-panel" class="mc-ocr-panel" style="display:none;">
        <div class="mc-ocr-panel__header">
          <span class="mc-ocr-panel__title">Raw OCR</span>
          <button id="btn-ocr-panel-close" class="mc-ocr-panel__close">×</button>
        </div>
        <pre id="ocr-capture-text" class="mc-ocr-panel__text"></pre>
      </div>

    </div>

    <div id="mc-autosave-badge" class="mc-autosave-badge">Draft saved</div>
    <div id="mc-toast" class="mc-toast"></div>

    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script src="<?php echo esc_url( KNX_STUDIO_URL . 'assets/migration-core.js?v=' . knx_studio_asset_ver( 'assets/migration-core.js' ) ); ?>"></script>
    <script src="<?php echo esc_url( KNX_STUDIO_URL . 'assets/migration-builder.js?v=' . knx_studio_asset_ver( 'assets/migration-builder.js' ) ); ?>"></script>
    <script src="<?php echo esc_url( KNX_STUDIO_URL . 'assets/migration-ocr.js?v=' . knx_studio_asset_ver( 'assets/migration-ocr.js' ) ); ?>"></script>
    <script src="<?php echo esc_url( KNX_STUDIO_URL . 'assets/migration-capture.js?v=' . knx_studio_asset_ver( 'assets/migration-capture.js' ) ); ?>"></script>
    <script src="<?php echo esc_url( KNX_STUDIO_URL . 'assets/tablet-app-mode.js?v=' . knx_studio_asset_ver( 'assets/tablet-app-mode.js' ) ); ?>"></script>

    <!-- Price Calculator bubble widget -->
    <link rel="stylesheet" href="<?php echo esc_url( KNX_URL . 'inc/modules/core/resources/knx-price-calc.css?v=' . KNX_VERSION ); ?>">
    <script src="<?php echo esc_url( KNX_URL . 'inc/modules/core/resources/knx-price-calc.js?v=' . KNX_VERSION ); ?>"></script>
    <?php
}

/**
 * Render harvest placeholder.
 *
 * @param object $session KNX session object.
 * @return void
 */
function knx_studio_render_harvest( $session ) {
    $home_url = site_url( '/menu-studio/' );
    ?>
    <div class="ms-placeholder">
      <div class="ms-placeholder__icon">🌐</div>
      <h1 class="ms-placeholder__title">Web Harvest</h1>
      <p class="ms-placeholder__desc">
        Extract and structure menu data from websites and online ordering platforms.
        This tool is not yet available.
      </p>
      <a class="ms-placeholder__back" href="<?php echo esc_url( $home_url ); ?>">← Studio Home</a>
    </div>
    <?php
}

add_action( 'wp_ajax_knx_studio_upload', 'knx_menu_studio_handle_upload' );

/**
 * Normalize uploaded image payload into a flat files array.
 *
 * @return array
 */
function knx_studio_collect_uploaded_images() {
    $files = [];

    if ( ! empty( $_FILES['images'] ) && is_array( $_FILES['images']['name'] ) ) {
        $count = count( $_FILES['images']['name'] );

        for ( $i = 0; $i < $count; $i++ ) {
            if ( empty( $_FILES['images']['name'][ $i ] ) ) {
                continue;
            }

            $files[] = [
                'name'     => $_FILES['images']['name'][ $i ],
                'type'     => $_FILES['images']['type'][ $i ],
                'tmp_name' => $_FILES['images']['tmp_name'][ $i ],
                'error'    => $_FILES['images']['error'][ $i ],
                'size'     => $_FILES['images']['size'][ $i ],
            ];
        }
    } elseif ( ! empty( $_FILES['image'] ) ) {
        $files[] = $_FILES['image'];
    }

    return $files;
}

/**
 * Handle temporary image upload for OCR reference.
 *
 * @return void
 */
function knx_menu_studio_handle_upload() {
    if ( function_exists( 'knx_get_session' ) ) {
        $session = knx_get_session();

        if ( ! $session || ! in_array( $session->role, KNX_STUDIO_ALLOWED_ROLES, true ) ) {
            wp_send_json_error( [ 'error' => 'Unauthorized.' ], 403 );
        }
    }

    if (
        empty( $_REQUEST['nonce'] ) ||
        ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ),
            'knx_studio_upload_action'
        )
    ) {
        wp_send_json_error( [ 'error' => 'Invalid nonce.' ], 403 );
    }

    $files = knx_studio_collect_uploaded_images();

    if ( empty( $files ) ) {
        wp_send_json_error( [ 'error' => 'No file received.' ], 400 );
    }

    if ( count( $files ) > 5 ) {
        wp_send_json_error( [ 'error' => 'Maximum 5 images per item.' ], 400 );
    }

    $allowed = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp' ];

    if ( ! class_exists( 'finfo' ) ) {
        wp_send_json_error( [ 'error' => 'Fileinfo extension is required.' ], 500 );
    }

    $finfo = new finfo( FILEINFO_MIME_TYPE );

    $upload_dir = wp_upload_dir();
    $dest_dir   = trailingslashit( $upload_dir['basedir'] ) . 'knx-studio-temp/';
    $dest_url   = trailingslashit( $upload_dir['baseurl'] ) . 'knx-studio-temp/';

    if ( ! is_dir( $dest_dir ) ) {
        wp_mkdir_p( $dest_dir );
        file_put_contents( $dest_dir . 'index.php', '<?php // Silence is golden' );
    }

    $ext_map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/bmp'  => 'bmp',
    ];

    $urls = [];

    foreach ( $files as $file ) {
        if ( empty( $file['tmp_name'] ) || (int) $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'error' => 'One of the files could not be uploaded.' ], 400 );
        }

        if ( (int) $file['size'] > 10 * 1024 * 1024 ) {
            wp_send_json_error( [ 'error' => 'Each image must be 10 MB or less.' ], 413 );
        }

        $mime = $finfo->file( $file['tmp_name'] );

        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_send_json_error( [ 'error' => 'Only image files accepted.' ], 415 );
        }

        $ext      = $ext_map[ $mime ] ?? 'jpg';
        $filename = wp_generate_password( 16, false ) . '.' . $ext;
        $dest     = $dest_dir . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( [ 'error' => 'Failed to save uploaded image.' ], 500 );
        }

        $urls[] = $dest_url . $filename;
    }

    wp_send_json_success(
        [
            'urls' => $urls,
            'url'  => ! empty( $urls[0] ) ? $urls[0] : '',
        ]
    );
}

/**
 * Handle AJAX cleanup of temporary images after adding item to list
 */
function knx_studio_handle_cleanup_images() {
    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'knx_studio_nonce' ) ) {
        wp_send_json_error( [ 'error' => 'Invalid nonce.' ], 403 );
    }

    $urls = $_POST['urls'] ?? [];
    if ( ! is_array( $urls ) || empty( $urls ) ) {
        wp_send_json_success( [ 'cleaned' => 0 ] );
    }

    $upload_dir = wp_upload_dir();
    $temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'knx-studio-temp/';
    $temp_url   = trailingslashit( $upload_dir['baseurl'] ) . 'knx-studio-temp/';
    
    $cleaned_count = 0;
    
    foreach ( $urls as $url ) {
        if ( ! is_string( $url ) || strpos( $url, $temp_url ) !== 0 ) {
            continue; // Only delete files from our temp directory
        }
        
        $filename = basename( $url );
        $filepath = $temp_dir . $filename;
        
        if ( file_exists( $filepath ) && is_file( $filepath ) ) {
            if ( unlink( $filepath ) ) {
                $cleaned_count++;
            }
        }
    }
    
    wp_send_json_success( [ 'cleaned' => $cleaned_count ] );
}

/**
 * Clean up old temporary images (older than 1 hour) to prevent disk space issues
 */
function knx_studio_cleanup_old_temp_files() {
    $upload_dir = wp_upload_dir();
    $temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'knx-studio-temp/';
    
    if ( ! is_dir( $temp_dir ) ) {
        return 0;
    }
    
    $cleaned = 0;
    $cutoff_time = time() - 3600; // 1 hour ago
    
    $files = glob( $temp_dir . '*' );
    foreach ( $files as $file ) {
        if ( is_file( $file ) && basename( $file ) !== 'index.php' ) {
            if ( filemtime( $file ) < $cutoff_time ) {
                if ( unlink( $file ) ) {
                    $cleaned++;
                }
            }
        }
    }
    
    return $cleaned;
}

// Clean old temp files on upload to prevent accumulation
add_action( 'wp_ajax_knx_studio_upload', function() {
    knx_studio_cleanup_old_temp_files();
}, 5 );