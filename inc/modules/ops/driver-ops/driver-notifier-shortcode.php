<?php
// File: inc/modules/ops/driver-ops/driver-notifier-shortcode.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX DRIVER OPS — Notifier (NEXUS Shell, minimal)
 * Shortcode: [knx_driver_ops_notifier]
 *
 * Notes:
 * - Minimal floating link to the driver dashboard page.
 * - No JS. Keeps scope tight and avoids duplicated polling.
 * ==========================================================
 */

add_shortcode('knx_driver_ops_notifier', function ($atts = []) {

    $atts = shortcode_atts([
        'dashboard_url' => site_url('/driver-dashboard'),
        'label'         => 'Driver Dashboard',
    ], (array)$atts, 'knx_driver_ops_notifier');

    $dashboard_url = esc_url($atts['dashboard_url']);
    $label = trim((string)$atts['label']);
    if ($label === '') $label = 'Driver Dashboard';

    // Inline CSS only (reuse same driver-ops CSS for consistent shell tokens).
    $css = '';
    $css_path = defined('KNX_PATH') ? (KNX_PATH . 'inc/modules/ops/driver-ops/driver-ops-style.css') : '';
    if ($css_path && file_exists($css_path)) {
        $css = (string)file_get_contents($css_path);
    }

    ob_start();
    ?>
    <?php if (!empty($css)) : ?>
        <style data-knx="driver-ops-style"><?php echo $css; ?></style>
    <?php endif; ?>

    <div class="knx-driver-notifier" aria-label="Driver notifier">
        <a class="knx-do-notifybtn" href="<?php echo $dashboard_url; ?>">
            <?php echo esc_html($label); ?>
        </a>
    </div>
    <?php
    return ob_get_clean();
});
