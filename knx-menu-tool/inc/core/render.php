<?php
if (!defined('ABSPATH')) exit;

final class KNX_Menu_Tool_Render {

    /**
     * Render standalone 403 page when KNX is missing.
     *
     * @return void
     */
    public static function render_nexus_required_page() {
        $home_url = home_url('/');

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>KNX Menu Tool</title>';
        echo '<style>
            body{
                margin:0;
                min-height:100vh;
                display:flex;
                align-items:center;
                justify-content:center;
                background:#f5f7fb;
                color:#0b1220;
                font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
                padding:24px;
            }
            .knxmt-shell{
                width:100%;
                max-width:520px;
                background:#fff;
                border:1px solid rgba(11,18,32,.10);
                border-radius:20px;
                box-shadow:0 18px 44px rgba(11,18,32,.08);
                padding:24px;
                text-align:center;
            }
            .knxmt-title{
                font-size:24px;
                font-weight:900;
                margin-bottom:10px;
            }
            .knxmt-copy{
                color:#6b7280;
                line-height:1.5;
                margin-bottom:20px;
            }
            .knxmt-btn{
                display:inline-block;
                text-decoration:none;
                border-radius:14px;
                padding:12px 16px;
                font-weight:800;
                background:#0b793a;
                color:#fff;
            }
        </style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="knxmt-shell">';
        echo '<div class="knxmt-title">KNX Menu Tool</div>';
        echo '<div class="knxmt-copy">Kingdom Nexus required.</div>';
        echo '<a class="knxmt-btn" href="' . esc_url($home_url) . '">Go Home</a>';
        echo '</div>';
        echo '</body>';
        echo '</html>';
    }

    /**
     * Render the standalone app page.
     *
     * @param int $user_id
     * @param string $role
     * @return void
     */
    public static function render_app_page($user_id, $role) {
        $nonce = wp_create_nonce('knx_menu_tool_nonce');
        $ajax_url = admin_url('admin-ajax.php');

        $css_path = KNX_MENU_TOOL_PATH . 'assets/menu-tool.css';
        $js_path  = KNX_MENU_TOOL_PATH . 'assets/menu-tool.js';

        $css_url = KNX_MENU_TOOL_URL . 'assets/menu-tool.css';
        $js_url  = KNX_MENU_TOOL_URL . 'assets/menu-tool.js';

        $utils_path     = KNX_MENU_TOOL_PATH . 'assets/modules/mt-utils.js';
        $db_path        = KNX_MENU_TOOL_PATH . 'assets/modules/mt-db.js';
        $renderer_path  = KNX_MENU_TOOL_PATH . 'assets/modules/mt-renderer.js';
        $crop_path      = KNX_MENU_TOOL_PATH . 'assets/modules/mt-crop.js';
        $ocr_path       = KNX_MENU_TOOL_PATH . 'assets/modules/mt-ocr.js';
        $workspace_path = KNX_MENU_TOOL_PATH . 'assets/modules/mt-workspace.js';
        $export_path    = KNX_MENU_TOOL_PATH . 'assets/modules/mt-export.js';
        $groups_path    = KNX_MENU_TOOL_PATH . 'assets/modules/mt-groups.js';
        $ui_path        = KNX_MENU_TOOL_PATH . 'assets/modules/mt-ui.js';

        $utils_url     = KNX_MENU_TOOL_URL . 'assets/modules/mt-utils.js';
        $db_url        = KNX_MENU_TOOL_URL . 'assets/modules/mt-db.js';
        $renderer_url  = KNX_MENU_TOOL_URL . 'assets/modules/mt-renderer.js';
        $crop_url      = KNX_MENU_TOOL_URL . 'assets/modules/mt-crop.js';
        $ocr_url       = KNX_MENU_TOOL_URL . 'assets/modules/mt-ocr.js';
        $workspace_url = KNX_MENU_TOOL_URL . 'assets/modules/mt-workspace.js';
        $export_url    = KNX_MENU_TOOL_URL . 'assets/modules/mt-export.js';
        $groups_url    = KNX_MENU_TOOL_URL . 'assets/modules/mt-groups.js';
        $ui_url        = KNX_MENU_TOOL_URL . 'assets/modules/mt-ui.js';

        $css_ver       = file_exists($css_path) ? (int) filemtime($css_path) : time();
        $js_ver        = file_exists($js_path) ? (int) filemtime($js_path) : time();
        $utils_ver     = file_exists($utils_path) ? (int) filemtime($utils_path) : time();
        $db_ver        = file_exists($db_path) ? (int) filemtime($db_path) : time();
        $renderer_ver  = file_exists($renderer_path) ? (int) filemtime($renderer_path) : time();
        $crop_ver      = file_exists($crop_path) ? (int) filemtime($crop_path) : time();
        $ocr_ver       = file_exists($ocr_path) ? (int) filemtime($ocr_path) : time();
        $workspace_ver = file_exists($workspace_path) ? (int) filemtime($workspace_path) : time();
        $export_ver    = file_exists($export_path) ? (int) filemtime($export_path) : time();
        $groups_ver    = file_exists($groups_path) ? (int) filemtime($groups_path) : time();
        $ui_ver        = file_exists($ui_path) ? (int) filemtime($ui_path) : time();

        $payload = [
            'ajaxUrl' => $ajax_url,
            'nonce' => $nonce,
            'role' => $role,
            'userId' => (int) $user_id,
            'toolUrl' => KNX_Menu_Tool_Security::get_tool_url(),
            'loginUrl' => KNX_MENU_TOOL_LOGIN_URL,
            'deviceProfile' => 'tablet-horizontal',
            'version' => '4.2.0',
        ];

        echo '<!DOCTYPE html>';
        echo '<html ' . get_language_attributes() . '>';
        echo '<head>';
        echo '<meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>KNX Menu Tool</title>';
        echo '<link rel="stylesheet" href="' . esc_url($css_url) . '?v=' . $css_ver . '">';
        echo '</head>';
        echo '<body>';

        echo '<div id="knxMenuToolApp" data-config="' . esc_attr(wp_json_encode($payload)) . '">';

        echo '<div class="knxmt-shell knx-mt-app">';

        echo '  <header class="knxmt-topbar knx-mt-topbar">';
        echo '      <div class="knxmt-brand">';
        echo '          <div class="knxmt-title">KNX Menu Tool</div>';
        echo '          <div class="knxmt-subtitle">One item at a time → freeze → add to CSV collection → export when you want</div>';
        echo '      </div>';

        echo '      <div class="knxmt-topbar-right">';
        echo '          <span class="knxmt-status-pill" id="knxmtStatusPill">Ready</span>';
        echo '          <span class="knxmt-chip knxmt-chip-muted" id="knxmtFrozenCounter">0 items ready</span>';
        echo '          <button type="button" class="knxmt-btn knxmt-btn-ghost" id="knxmtNewBatchBtn">Reset Workspace</button>';
        echo '          <button type="button" class="knxmt-btn knxmt-btn-primary" id="knxmtDownloadCsvBtn" disabled>Download CSV</button>';
        echo '      </div>';
        echo '  </header>';

        echo '  <main class="knxmt-main">';

        echo '      <section class="knxmt-card">';
        echo '          <div class="knxmt-card-head">';
        echo '              <div>';
        echo '                  <div class="knxmt-card-title">Workspace</div>';
        echo '                  <div class="knxmt-card-copy">Draft items stay editable. Frozen items go to the CSV collection and are ready for export.</div>';
        echo '              </div>';
        echo '              <div class="knxmt-batch-meta">';
        echo '                  <span class="knxmt-chip" id="knxmtBatchCountChip">0 items</span>';
        echo '                  <span class="knxmt-chip knxmt-chip-muted" id="knxmtRoleChip">' . esc_html($role) . '</span>';
        echo '              </div>';
        echo '          </div>';

        echo '          <div class="knxmt-form-grid">';
        echo '              <div class="knxmt-field">';
        echo '                  <label class="knxmt-label" for="knxmtBatchName">Workspace Name</label>';
        echo '                  <input id="knxmtBatchName" class="knxmt-input" type="text" placeholder="Current import session">';
        echo '              </div>';
        echo '              <div class="knxmt-field">';
        echo '                  <label class="knxmt-label" for="knxmtItemPicker">Current Item</label>';
        echo '                  <select id="knxmtItemPicker" class="knxmt-select"></select>';
        echo '              </div>';
        echo '          </div>';

        echo '          <div class="knxmt-actions-row">';
        echo '              <button type="button" class="knxmt-btn knxmt-btn-primary" id="knxmtAddItemBtn">Add Item</button>';
        echo '              <button type="button" class="knxmt-btn knxmt-btn-ghost" id="knxmtDuplicateItemBtn">Duplicate Item</button>';
        echo '              <button type="button" class="knxmt-btn knxmt-btn-danger" id="knxmtDeleteItemBtn">Delete Item</button>';
        echo '          </div>';
        echo '      </section>';

        echo '      <section class="knxmt-card">';
        echo '          <div class="knxmt-card-head">';
        echo '              <div>';
        echo '                  <div class="knxmt-card-title">Current Item Status</div>';
        echo '                  <div class="knxmt-card-copy" id="knxmtCurrentItemCopy">Select or create an item to begin.</div>';
        echo '              </div>';
        echo '              <div class="knxmt-actions-row knxmt-actions-row-tight">';
        echo '                  <span class="knxmt-chip knxmt-chip-warn" id="knxmtCurrentItemStatusChip">No item</span>';
        echo '              </div>';
        echo '          </div>';

        echo '          <div class="knxmt-current-summary" id="knxmtCurrentSummary">';
        echo '              <div class="knxmt-current-summary-empty">Waiting for item data...</div>';
        echo '          </div>';
        echo '      </section>';

        echo '      <section class="knxmt-card">';
        echo '          <div class="knxmt-card-head">';
        echo '              <div>';
        echo '                  <div class="knxmt-card-title">CSV Collection</div>';
        echo '                  <div class="knxmt-card-copy">Only frozen items are included in the final CSV.</div>';
        echo '              </div>';
        echo '              <div class="knxmt-actions-row knxmt-actions-row-tight">';
        echo '                  <span class="knxmt-chip knxmt-chip-muted" id="knxmtFrozenCollectionCount">0 frozen</span>';
        echo '              </div>';
        echo '          </div>';

        echo '          <div class="knxmt-frozen-list" id="knxmtFrozenList">';
        echo '              <div class="knxmt-card-copy">No frozen items yet.</div>';
        echo '          </div>';
        echo '      </section>';

        echo '      <section class="knxmt-card">';
        echo '          <div class="knxmt-card-head">';
        echo '              <div>';
        echo '                  <div class="knxmt-card-title">Screenshot</div>';
        echo '                  <div class="knxmt-card-copy">Upload one screenshot per item view. Crop is mandatory before OCR + parse.</div>';
        echo '              </div>';
        echo '              <span class="knxmt-chip knxmt-chip-warn" id="knxmtCropChip">Needs crop</span>';
        echo '          </div>';

        echo '          <div class="knxmt-upload-wrap">';
        echo '              <label class="knxmt-upload" for="knxmtImageInput">';
        echo '                  <input id="knxmtImageInput" type="file" accept="image/png,image/jpeg,image/webp">';
        echo '                  <span class="knxmt-upload-title">Upload Screenshot</span>';
        echo '                  <span class="knxmt-upload-sub">JPG, PNG, WEBP · crop required</span>';
        echo '              </label>';
        echo '          </div>';

        echo '          <div class="knxmt-preview-wrap" id="knxmtPreviewWrap" hidden>';
        echo '              <img id="knxmtPreviewImage" class="knxmt-preview-image" alt="Preview">';
        echo '          </div>';

        echo '          <div class="knxmt-actions-row">';
        echo '              <button type="button" class="knxmt-btn knxmt-btn-primary" id="knxmtOpenCropBtn" disabled>Open Crop</button>';
        echo '              <button type="button" class="knxmt-btn knxmt-btn-primary" id="knxmtRunOcrParseBtn" disabled>Run OCR + Parse</button>';
        echo '              <button type="button" class="knxmt-btn knxmt-btn-ghost" id="knxmtClearImageBtn" disabled>Clear Image</button>';
        echo '          </div>';
        echo '      </section>';

        echo '      <section class="knxmt-card knx-mt-editor-card">';
        echo '          <div class="knxmt-card-head">';
        echo '              <div>';
        echo '                  <div class="knxmt-card-title">Item Editor</div>';
        echo '                  <div class="knxmt-card-copy">Fix the item until it is ready, then freeze it into the CSV collection.</div>';
        echo '              </div>';
        echo '              <div class="knxmt-actions-row knxmt-actions-row-tight">';
        echo '                  <button type="button" class="knxmt-btn knxmt-btn-primary" id="knxmtFreezeBtn">Freeze Item</button>';
        echo '              </div>';
        echo '          </div>';

        echo '          <div class="knx-mt-item-card">';
        echo '              <div class="knxmt-form-grid">';
        echo '                  <div class="knxmt-field">';
        echo '                      <label class="knxmt-label" for="knxmtTitle">Title</label>';
        echo '                      <input id="knxmtTitle" class="knxmt-input" type="text" placeholder="Chicken Alfredo Fettuccine Pasta">';
        echo '                  </div>';
        echo '                  <div class="knxmt-field">';
        echo '                      <label class="knxmt-label" for="knxmtBasePrice">Base Price</label>';
        echo '                      <input id="knxmtBasePrice" class="knxmt-input" type="number" step="0.01" min="0" placeholder="0.00">';
        echo '                  </div>';
        echo '              </div>';

        echo '              <div class="knxmt-field">';
        echo '                  <label class="knxmt-label" for="knxmtDescription">Description</label>';
        echo '                  <textarea id="knxmtDescription" class="knxmt-textarea" placeholder="Short description..."></textarea>';
        echo '              </div>';

        echo '              <div class="knxmt-switch-row">';
        echo '                  <label class="knxmt-switch">';
        echo '                      <input id="knxmtSpecialInstructions" type="checkbox">';
        echo '                      <span>Allow special instructions</span>';
        echo '                  </label>';
        echo '              </div>';
        echo '          </div>';

        echo '          <div class="knxmt-section-head">';
        echo '              <div class="knxmt-card-title knxmt-card-title-sm">Groups</div>';
        echo '              <button type="button" class="knxmt-btn knxmt-btn-primary" id="knxmtAddGroupBtn">Add Group</button>';
        echo '          </div>';

        echo '          <div id="knxmtGroupsWrap" class="knxmt-groups-wrap knx-mt-groups">';
        echo '              <div class="knx-mt-empty-groups">No groups yet. Add one below.</div>';
        echo '          </div>';

        echo '          <div class="knx-mt-add-group-row" hidden>';
        echo '              <button type="button" class="knxmt-btn knxmt-btn-ghost" id="knxmtAddGroupSecondaryBtn">+ Add Group</button>';
        echo '          </div>';

        echo '      </section>';

        echo '      <section class="knxmt-card">';
        echo '          <div class="knxmt-card-head">';
        echo '              <div>';
        echo '                  <div class="knxmt-card-title">OCR Output</div>';
        echo '                  <div class="knxmt-card-copy">Auto-filled by browser OCR. Keep this mainly for debugging or quick cleanup.</div>';
        echo '              </div>';
        echo '          </div>';

        echo '          <div class="knxmt-field">';
        echo '              <label class="knxmt-label" for="knxmtOcrText">OCR Text</label>';
        echo '              <textarea id="knxmtOcrText" class="knxmt-textarea" placeholder="OCR will appear here automatically..."></textarea>';
        echo '          </div>';

        echo '          <div class="knxmt-warnings" id="knxmtWarnings"></div>';
        echo '      </section>';

        echo '      <section class="knxmt-card">';
        echo '          <div class="knxmt-card-head">';
        echo '              <div>';
        echo '                  <div class="knxmt-card-title">Last Frozen Snapshot</div>';
        echo '                  <div class="knxmt-card-copy">This panel shows only the last frozen item for quick inspection. CSV export uses all frozen items.</div>';
        echo '              </div>';
        echo '          </div>';
        echo '          <pre class="knxmt-console" id="knxmtConsole">Waiting...</pre>';
        echo '      </section>';

        echo '  </main>';
        echo '</div>';

        echo '<div class="knxmt-modal" id="knxmtCropModal" hidden>';
        echo '  <div class="knxmt-modal-backdrop" id="knxmtCropBackdrop"></div>';
        echo '  <div class="knxmt-modal-dialog knxmt-modal-dialog-xl">';
        echo '      <div class="knxmt-modal-head">';
        echo '          <div>';
        echo '              <div class="knxmt-card-title">Crop Gate</div>';
        echo '              <div class="knxmt-card-copy">Confirm crop before OCR + parse.</div>';
        echo '          </div>';
        echo '          <button type="button" class="knxmt-icon-btn" id="knxmtCloseCropBtn">×</button>';
        echo '      </div>';

        echo '      <div class="knxmt-crop-stage">';
        echo '          <div class="knxmt-crop-canvas-wrap">';
        echo '              <canvas id="knxmtCropCanvas"></canvas>';
        echo '          </div>';
        echo '      </div>';

        echo '      <div class="knxmt-modal-foot">';
        echo '          <button type="button" class="knxmt-btn knxmt-btn-ghost" id="knxmtResetCropBtn">Reset</button>';
        echo '          <button type="button" class="knxmt-btn knxmt-btn-primary" id="knxmtConfirmCropBtn">Confirm Crop</button>';
        echo '      </div>';
        echo '  </div>';
        echo '</div>';

        echo '</div>';

        echo '<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>';
        echo '<script src="' . esc_url($utils_url) . '?v=' . $utils_ver . '"></script>';
        echo '<script src="' . esc_url($db_url) . '?v=' . $db_ver . '"></script>';
        echo '<script src="' . esc_url($renderer_url) . '?v=' . $renderer_ver . '"></script>';
        echo '<script src="' . esc_url($crop_url) . '?v=' . $crop_ver . '"></script>';
        echo '<script src="' . esc_url($ocr_url) . '?v=' . $ocr_ver . '"></script>';
        echo '<script src="' . esc_url($workspace_url) . '?v=' . $workspace_ver . '"></script>';
        echo '<script src="' . esc_url($export_url) . '?v=' . $export_ver . '"></script>';
        echo '<script src="' . esc_url($groups_url) . '?v=' . $groups_ver . '"></script>';
        echo '<script src="' . esc_url($ui_url) . '?v=' . $ui_ver . '"></script>';
        echo '<script src="' . esc_url($js_url) . '?v=' . $js_ver . '"></script>';
        echo '</body>';
        echo '</html>';
    }
}