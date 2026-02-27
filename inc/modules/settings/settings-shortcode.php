<?php
// File: inc/modules/settings/settings-shortcode.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Kingdom Nexus — Settings (Site Branding) Shortcode (v1.0)
 * Shortcode: [knx_settings]
 *
 * Canon:
 * - No wp_enqueue
 * - Self-contained assets via echo
 * - Fail-closed auth (super_admin)
 * - Uses REST endpoint /knx/v1/save-branding
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

    $upload      = wp_upload_dir();
    $current_logo = get_option('knx_site_logo', '');

    // ---- Auto-heal broken stored URLs (disk missing) ----
    if ($current_logo && !empty($upload['baseurl']) && !empty($upload['basedir'])) {
        $relative  = str_replace(trailingslashit($upload['baseurl']), '', $current_logo);
        $disk_path = trailingslashit($upload['basedir']) . ltrim($relative, '/');
        if (!file_exists($disk_path)) {
            delete_option('knx_site_logo');
            delete_option('knx_site_logo_id');
            $current_logo = '';
        }
    }

    // ---- Safe placeholder (no 404) ----
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="120" viewBox="0 0 160 120">
      <rect x="0" y="0" width="160" height="120" rx="14" fill="#f3f4f6"/>
      <rect x="18" y="18" width="124" height="84" rx="12" fill="#ffffff" stroke="#e5e7eb"/>
      <path d="M46 78l16-18 14 16 12-12 26 30H46z" fill="#e5e7eb"/>
      <circle cx="64" cy="52" r="8" fill="#e5e7eb"/>
      <text x="80" y="108" text-anchor="middle" font-size="10" fill="#9ca3af" font-family="Arial, sans-serif">NO LOGO</text>
    </svg>';
    $placeholder = 'data:image/svg+xml;base64,' . base64_encode($svg);

    // ---- Assets ----
    $ver = defined('KNX_VERSION') ? KNX_VERSION : time();
    $css_url = defined('KNX_URL') ? (KNX_URL . 'inc/modules/settings/brand-logo-style.css?v=' . rawurlencode($ver)) : '';
    $js_url  = defined('KNX_URL') ? (KNX_URL . 'inc/modules/settings/brand-logo-upload.js?v=' . rawurlencode($ver)) : '';

    $root = esc_url_raw(rest_url()); // ends with /wp-json/
    $wp_nonce = wp_create_nonce('wp_rest');

    ob_start();
    ?>
    <div class="knx-settings-wrap" id="knx-settings">

        <?php if ($css_url): ?>
            <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
        <?php endif; ?>

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

                    <button id="knxBrandingSaveBtn" class="knx-btn-primary" type="button">
                        Upload
                    </button>

                    <p class="knx-settings-hint">JPG, PNG, WEBP. Max 5MB. Will be cropped to 590×400.</p>
                </div>
            </div>
        </div>

        <script>
            window.knx_settings = {
                root: <?php echo json_encode($root); ?>,
                wp_nonce: <?php echo json_encode($wp_nonce); ?>
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