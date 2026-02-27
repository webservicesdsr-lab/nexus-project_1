<?php
// File: inc/modules/settings/settings-shortcode.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Settings (Branding) Shortcode (v3.4)
 * Shortcode: [knx_settings]
 *
 * Includes:
 * - Site Logo upload (target=site_logo)
 * - Home Center Image upload (target=home_center)
 * - Home Headline text (target=home_copy)
 * - Display Adjust modal (pan + zoom) for both images (view_json)
 *
 * Canon:
 * - No wp_enqueue
 * - Assets injected via echo
 * - Fail-closed auth (super_admin)
 * - Endpoint: POST /wp-json/knx/v1/save-branding
 * ==========================================================
 */

function knx_settings_shortcode() {

    // ---- Auth guard (fail-closed) ----
    $ok = function_exists('knx_require_role') ? knx_require_role('super_admin') : false;
    if (!$ok) {
        return '<div class="knx-settings-wrap" id="knx-settings">
            <div class="knx-settings-card">
                <h2>Settings</h2>
                <p class="knx-settings-error">Access denied. Only super_admin may change branding.</p>
            </div>
        </div>';
    }

    $upload = wp_upload_dir();

    // ---- Current URLs ----
    $current_logo       = get_option('knx_site_logo', '');
    $current_home_center = get_option('knx_home_center_image', '');

    // ---- Views ----
    $logo_view_raw = get_option('knx_site_logo_view', '');
    $home_view_raw = get_option('knx_home_center_image_view', '');

    $logo_view = ['scale' => 1, 'x' => 0, 'y' => 0];
    $home_view = ['scale' => 1, 'x' => 0, 'y' => 0];

    if (is_string($logo_view_raw) && $logo_view_raw) {
        $d = json_decode($logo_view_raw, true);
        if (is_array($d)) {
            $logo_view['scale'] = isset($d['scale']) ? floatval($d['scale']) : 1;
            $logo_view['x']     = isset($d['x']) ? floatval($d['x']) : 0;
            $logo_view['y']     = isset($d['y']) ? floatval($d['y']) : 0;
        }
    }

    if (is_string($home_view_raw) && $home_view_raw) {
        $d = json_decode($home_view_raw, true);
        if (is_array($d)) {
            $home_view['scale'] = isset($d['scale']) ? floatval($d['scale']) : 1;
            $home_view['x']     = isset($d['x']) ? floatval($d['x']) : 0;
            $home_view['y']     = isset($d['y']) ? floatval($d['y']) : 0;
        }
    }

    $clamp = function($v){
        $v['scale'] = max(0.6, min(2.6, floatval($v['scale'] ?? 1)));
        $v['x']     = max(-520, min(520, floatval($v['x'] ?? 0)));
        $v['y']     = max(-320, min(320, floatval($v['y'] ?? 0)));
        return $v;
    };
    $logo_view = $clamp($logo_view);
    $home_view = $clamp($home_view);

    // ---- Auto-heal broken stored URLs (disk missing) ----
    if (!empty($upload['baseurl']) && !empty($upload['basedir'])) {

        if ($current_logo) {
            $relative  = str_replace(trailingslashit($upload['baseurl']), '', $current_logo);
            $disk_path = trailingslashit($upload['basedir']) . ltrim($relative, '/');
            if (!file_exists($disk_path)) {
                delete_option('knx_site_logo');
                delete_option('knx_site_logo_id');
                delete_option('knx_site_logo_view');
                $current_logo = '';
                $logo_view = ['scale' => 1, 'x' => 0, 'y' => 0];
            }
        }

        if ($current_home_center) {
            $relative  = str_replace(trailingslashit($upload['baseurl']), '', $current_home_center);
            $disk_path = trailingslashit($upload['basedir']) . ltrim($relative, '/');
            if (!file_exists($disk_path)) {
                delete_option('knx_home_center_image');
                delete_option('knx_home_center_image_id');
                delete_option('knx_home_center_image_view');
                $current_home_center = '';
                $home_view = ['scale' => 1, 'x' => 0, 'y' => 0];
            }
        }
    }

    // ---- Home headline ----
    $headline_default = 'A percentage of every order placed helps to support non-profits organizations that take care of those who need it in your community';
    $headline_value = get_option('knx_home_headline_text', '');
    $headline_value = is_string($headline_value) ? trim($headline_value) : '';
    if ($headline_value === '') $headline_value = $headline_default;

    $headline_max = 160;

    // ---- Placeholder (safe, no 404) ----
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="160" viewBox="0 0 240 160">
      <rect x="0" y="0" width="240" height="160" rx="18" fill="#f3f4f6"/>
      <rect x="22" y="26" width="196" height="108" rx="14" fill="#ffffff" stroke="#e5e7eb"/>
      <path d="M58 108l26-30 22 26 18-18 42 48H58z" fill="#e5e7eb"/>
      <circle cx="86" cy="66" r="10" fill="#e5e7eb"/>
      <text x="120" y="150" text-anchor="middle" font-size="12" fill="#9ca3af" font-family="Arial, sans-serif">NO IMAGE</text>
    </svg>';
    $placeholder = 'data:image/svg+xml;base64,' . base64_encode($svg);

    // ---- Assets (canonical names/paths) ----
    $ver = defined('KNX_VERSION') ? KNX_VERSION : time();
    $css_url = defined('KNX_URL') ? (KNX_URL . 'inc/modules/settings/brand-logo-style.css?v=' . rawurlencode($ver)) : '';
    $js_url  = defined('KNX_URL') ? (KNX_URL . 'inc/modules/settings/brand-logo-upload.js?v=' . rawurlencode($ver)) : '';

    $root = esc_url_raw(rest_url());
    $wp_nonce = wp_create_nonce('wp_rest');

    // Display frames (window sizes)
    $frame_nav  = ['w' => 160, 'h' => 45];
    $frame_home = ['w' => 420, 'h' => 150];

    ob_start();
    ?>
    <div class="knx-settings-wrap" id="knx-settings">

        <?php if ($css_url): ?>
            <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
        <?php endif; ?>

        <!-- CARD: Site Logo -->
        <div class="knx-settings-card">
            <div class="knx-settings-head">
                <h2>Site Branding</h2>
                <p class="knx-settings-sub">Upload a logo for the platform. This will be used across the Nexus Shell UI.</p>
            </div>

            <div class="knx-settings-branding">
                <div class="knx-settings-preview">
                    <img
                        id="knxBrandingPreview"
                        src="<?php echo esc_url($current_logo ?: $placeholder); ?>"
                        alt="Site Logo"
                        onerror="this.onerror=null;this.src='<?php echo esc_js($placeholder); ?>';"
                    >
                </div>

                <div class="knx-settings-actions">
                    <label class="knx-settings-file">
                        <input type="file" id="knxBrandingFileInput" accept="image/jpeg,image/png,image/webp">
                        <span>Select image</span>
                    </label>

                    <div class="knx-settings-btnrow">
                        <button id="knxBrandingAdjustBtn" class="knx-btn-secondary" type="button" data-target="site_logo">Adjust display</button>
                        <button id="knxBrandingSaveBtn" class="knx-btn-primary" type="button" data-target="site_logo">Upload</button>
                    </div>

                    <p class="knx-settings-hint">JPG, PNG, WEBP. Max 5MB. Saved into uploads/knx-branding/. Display can be adjusted (pan + zoom).</p>
                </div>
            </div>
        </div>

        <!-- CARD: Home Center Image -->
        <div class="knx-settings-card">
            <div class="knx-settings-head">
                <h2>Home Center Image</h2>
                <p class="knx-settings-sub">Upload the image shown on the Home screen above the message.</p>
            </div>

            <div class="knx-settings-branding">
                <div class="knx-settings-preview knx-settings-preview--center">
                    <img
                        id="knxHomeCenterPreview"
                        src="<?php echo esc_url($current_home_center ?: $placeholder); ?>"
                        alt="Home Center Image"
                        onerror="this.onerror=null;this.src='<?php echo esc_js($placeholder); ?>';"
                    >
                </div>

                <div class="knx-settings-actions">
                    <label class="knx-settings-file">
                        <input type="file" id="knxHomeCenterFileInput" accept="image/jpeg,image/png,image/webp">
                        <span>Select image</span>
                    </label>

                    <div class="knx-settings-btnrow">
                        <button id="knxHomeCenterAdjustBtn" class="knx-btn-secondary" type="button" data-target="home_center">Adjust display</button>
                        <button id="knxHomeCenterSaveBtn" class="knx-btn-primary" type="button" data-target="home_center">Upload</button>
                    </div>

                    <p class="knx-settings-hint">JPG, PNG, WEBP. Max 5MB. Saved into uploads/knx-branding/. Display can be adjusted (pan + zoom).</p>
                </div>
            </div>
        </div>

        <!-- CARD: Home Headline Text -->
        <div class="knx-settings-card">
            <div class="knx-settings-head">
                <h2>Home Headline</h2>
                <p class="knx-settings-sub">Edit the message shown on Home. Keep it concise for mobile.</p>
            </div>

            <div class="knx-settings-form">
                <label class="knx-settings-label" for="knxHomeHeadlineInput">Headline text</label>

                <textarea
                    id="knxHomeHeadlineInput"
                    class="knx-settings-textarea"
                    maxlength="<?php echo intval($headline_max); ?>"
                    rows="4"
                ><?php echo esc_textarea($headline_value); ?></textarea>

                <div class="knx-settings-meta">
                    <span class="knx-settings-counter">
                        <span id="knxHomeHeadlineCount">0</span>/<span id="knxHomeHeadlineMax"><?php echo intval($headline_max); ?></span>
                    </span>

                    <button id="knxHomeHeadlineSaveBtn" class="knx-btn-primary" type="button" data-target="home_copy">
                        Save text
                    </button>
                </div>

                <p class="knx-settings-hint">Max <?php echo intval($headline_max); ?> characters. Saved globally.</p>
            </div>
        </div>

        <!-- DISPLAY ADJUST MODAL (pan + zoom) -->
        <div class="knx-modal" id="knxBrandingModal" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="knx-modal__overlay" id="knxBrandingModalOverlay"></div>

            <div class="knx-modal__panel" role="document" aria-label="Adjust display">
                <div class="knx-modal__head">
                    <div>
                        <h3 id="knxBrandingModalTitle">Adjust display</h3>
                        <p id="knxBrandingModalDesc">Drag to reposition. Use the slider to zoom.</p>
                    </div>
                    <button class="knx-modal__close" id="knxBrandingModalClose" type="button" aria-label="Close">×</button>
                </div>

                <div class="knx-modal__body">
                    <div class="knx-cropper">

                        <div class="knx-cropper__mask">
                            <div class="knx-cropper__frame" id="knxBrandingFrame">
                                <img id="knxBrandingFrameImg" src="<?php echo esc_url($current_logo ?: $placeholder); ?>" alt="Preview" draggable="false">
                            </div>
                        </div>

                        <div class="knx-cropper__controls">
                            <label class="knx-control">
                                <span>Zoom</span>
                                <input id="knxBrandingZoom" type="range" min="0.6" max="2.6" step="0.02" value="1">
                            </label>

                            <div class="knx-control__row">
                                <button type="button" class="knx-btn-ghost" id="knxBrandingReset">Reset</button>
                                <button type="button" class="knx-btn-primary" id="knxBrandingApply">Apply</button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <script>
            window.knx_settings = {
                root: <?php echo json_encode($root); ?>,
                wp_nonce: <?php echo json_encode($wp_nonce); ?>,
                placeholder: <?php echo json_encode($placeholder); ?>,
                home_headline: {
                    value: <?php echo json_encode($headline_value); ?>,
                    max: <?php echo json_encode($headline_max); ?>
                },
                targets: {
                    site_logo: {
                        url: <?php echo json_encode($current_logo ?: ''); ?>,
                        view: <?php echo json_encode($logo_view); ?>,
                        frame: <?php echo json_encode($frame_nav); ?>
                    },
                    home_center: {
                        url: <?php echo json_encode($current_home_center ?: ''); ?>,
                        view: <?php echo json_encode($home_view); ?>,
                        frame: <?php echo json_encode($frame_home); ?>
                    }
                }
            };
        </script>

        <?php if ($js_url): ?>
            <script src="<?php echo esc_url($js_url); ?>" defer></script>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('knx_settings', 'knx_settings_shortcode');