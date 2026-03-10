<?php
// File: inc/public/home/home-shortcode.php
/**
 * Kingdom Nexus - Home shortcode (landing page) v4.2
 * - Mobile-first layout (matches app-style screenshot)
 * - Autocomplete via Nominatim (JS)
 * - Redirects to /explore-hubs/?location=City%2C%20State
 *
 * Canon:
 * - NO wp_enqueue (assets injected via echo like navbar)
 * - CSS scoped to #knx-home
 * - Keeps IDs:
 *   #knx-address-input, #knx-search-btn, #knx-autocomplete-dropdown,
 *   #knx-geolocation-status, #knx-detect-location
 *
 * v4.2:
 * - Home center image renders inside a larger horizontal framed container (shadow + border)
 * - Applies saved display view (pan + zoom) via knx_home_center_image_view
 * - If no image (or image fails), shows a hardcoded RED delivery icon (global)
 * - Home headline is configurable via option knx_home_headline_text (fallback to current text)
 */
if (!defined('ABSPATH')) exit;

function knx_home_shortcode() {

    // ---- Pass explore URL to JS (no wp_localize) ----
    $explore_url = home_url('/explore-hubs/');

    // ---- Optional greeting by session ----
    $first_name = '';
    if (isset($_SESSION['knx_user_id'])) {
        global $wpdb;
        $uid = intval($_SESSION['knx_user_id']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT first_name FROM {$wpdb->prefix}knx_users WHERE id = %d", $uid));
        if ($row && !empty($row->first_name)) {
            $first_name = esc_html($row->first_name);
        }
    }

    // ---- Home center image + view (pan/zoom) ----
    $center_img = get_option('knx_home_center_image', '');

    $view_raw = get_option('knx_home_center_image_view', '');
    $view = ['scale' => 1, 'x' => 0, 'y' => 0];

    if (is_string($view_raw) && $view_raw) {
        $d = json_decode($view_raw, true);
        if (is_array($d)) {
            $view['scale'] = isset($d['scale']) ? floatval($d['scale']) : 1;
            $view['x']     = isset($d['x']) ? floatval($d['x']) : 0;
            $view['y']     = isset($d['y']) ? floatval($d['y']) : 0;
        }
    }

    // Clamp view to safe ranges
    $view['scale'] = max(0.6, min(2.6, $view['scale']));
    $view['x']     = max(-520, min(520, $view['x']));
    $view['y']     = max(-320, min(320, $view['y']));

    $center_style = sprintf(
        '--knx-home-center-scale:%s;--knx-home-center-x:%spx;--knx-home-center-y:%spx;',
        esc_attr($view['scale']),
        esc_attr($view['x']),
        esc_attr($view['y'])
    );

    // ---- Home headline text (editable in settings) ----
    $default_headline = 'A percentage of every order placed helps to support non-profits organizations that take care of those who need it in your community';
    $headline = get_option('knx_home_headline_text', '');
    $headline = is_string($headline) ? trim($headline) : '';
    if ($headline === '') $headline = $default_headline;

    // Safety clamp (server-side)
    if (function_exists('mb_substr')) {
        $headline = mb_substr($headline, 0, 160);
    } else {
        $headline = substr($headline, 0, 160);
    }

    // ---- Inject Home assets (echo, not enqueue) ----
    echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/public/home/home.css?v=' . KNX_VERSION) . '">';
    echo '<script src="' . esc_url(KNX_URL . 'inc/public/home/home.js?v=' . KNX_VERSION) . '" defer></script>';

    // Keep your location modules if you rely on them for the detect button
    echo '<link rel="stylesheet" href="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-modal.css?v=' . KNX_VERSION) . '">';
    echo '<script src="' . esc_url(KNX_URL . 'inc/modules/home/knx-location-detector.js?v=' . KNX_VERSION) . '" defer></script>';

    ob_start();
?>
<section class="knx-home" id="knx-home">

    <div class="knx-home__inner">

        <!-- Center Image / Icon -->
        <div class="knx-home__center-art">

            <?php if (!empty($center_img)): ?>
                <div class="knx-home__center-frame" id="knxHomeCenterFrame" style="<?php echo esc_attr($center_style); ?>">
                    <img class="knx-home__center-img"
                         id="knxHomeCenterImg"
                         src="<?php echo esc_url($center_img); ?>"
                         alt="Home Center Image"
                         loading="eager"
                         decoding="async"
                         onerror="this.style.display='none';document.getElementById('knxHomeDefaultIcon').style.display='flex';">
                </div>
            <?php endif; ?>

            <!-- Hardcoded global fallback (RED delivery icon) -->
            <div class="knx-home__default-icon" id="knxHomeDefaultIcon" style="<?php echo !empty($center_img) ? 'display:none;' : 'display:flex;'; ?>">
                <svg width="92" height="92" viewBox="0 0 64 64" aria-hidden="true">
                    <g fill="none" stroke="none">
                        <path d="M18 22c0-6 4-10 10-10h8c6 0 10 4 10 10v22c0 4-3 7-7 7H25c-4 0-7-3-7-7V22z"
                              fill="#dc2626"/>
                        <path d="M26 22c0-3 2-5 5-5h2c3 0 5 2 5 5"
                              fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                        <path d="M22 30h20" stroke="#ffffff" stroke-width="3" stroke-linecap="round" opacity="0.92"/>
                        <circle cx="24" cy="52" r="3.2" fill="#111827" opacity="0.92"/>
                        <circle cx="40" cy="52" r="3.2" fill="#111827" opacity="0.92"/>
                        <path d="M22 48h20" stroke="#111827" stroke-width="3" stroke-linecap="round" opacity="0.9"/>
                    </g>
                </svg>
            </div>

        </div>

        <!-- Headline -->
        <div class="knx-home__copy">
            <h2 class="knx-home__title">
                <?php if ($first_name): ?>
                    Welcome back, <?php echo $first_name; ?>!<br>
                <?php endif; ?>
                <?php echo esc_html($headline); ?>
            </h2>

            <p class="knx-home__sub">Enter your street or address</p>
        </div>

        <!-- Search -->
        <div class="knx-home__search">

            <div class="knx-home__input-row">
                <div class="knx-search-wrapper knx-home__input-wrap">
                    <input
                        type="text"
                        id="knx-address-input"
                        class="knx-home__input"
                        placeholder="Enter your street or address"
                        autocomplete="off"
                    />
                    <div id="knx-autocomplete-dropdown" class="knx-autocomplete-dropdown"></div>
                </div>

                <button type="button" id="knx-detect-location" class="knx-home__locate-btn" aria-label="Detect my location">
                    <span class="knx-home__locate-icon">📍</span>
                </button>
            </div>

            <button type="button" id="knx-search-btn" class="knx-home__search-btn">
                Search
            </button>

            <div id="knx-geolocation-status" class="knx-status-message" aria-live="polite"></div>
        </div>

        <!-- Cities / Cards -->
        <div class="knx-cities-section knx-home__cities">
            <h3 class="knx-cities-title">Cities</h3>
            <?php echo do_shortcode('[knx_cities_grid]'); ?>
        </div>

    </div>

    <script>
        window.knxHome = window.knxHome || {};
        window.knxHome.exploreUrl = <?php echo json_encode($explore_url); ?>;
    </script>

</section>
<?php

    // Corporate sidebar on home (super_admin only)
    $role = '';
    if (function_exists('knx_get_session')) {
        $sess = knx_get_session();
        if (is_array($sess) && isset($sess['role'])) $role = $sess['role'];
        elseif (is_object($sess) && isset($sess->role)) $role = $sess->role;
    }

    if ($role === 'super_admin' && function_exists('knx_get_corporate_sidebar_html')) {
        echo knx_get_corporate_sidebar_html(true);
    }

    return ob_get_clean();
}
add_shortcode('knx_home', 'knx_home_shortcode');