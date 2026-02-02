<?php
// File: inc/modules/ops/driver-ops/driver-ops-shortcode.php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX DRIVER OPS — Available Orders (NEXUS Shell)
 * Shortcode: [knx_driver_ops_dashboard]
 *
 * Canon notes:
 * - UX-only module. Authority remains in core REST.
 * - Assets injected inline via file_get_contents (no wp_footer, no wp_enqueue).
 * - Focus: available orders + self-assign (auto-assignable).
 * ==========================================================
 */

add_shortcode('knx_driver_ops_dashboard', function ($atts = []) {

    if (!function_exists('knx_get_session')) {
        return '<div class="knx-driver-ops-error">Session unavailable.</div>';
    }

    $session = knx_get_session();
    $role = $session && isset($session->role) ? (string)$session->role : '';

    // Canon: drivers + super_admin only (fail-closed).
    if (!in_array($role, ['super_admin', 'driver'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $atts = shortcode_atts([
        'poll_ms'        => 12000,
        'view_order_url' => site_url('/view-order'),
        'my_orders_url'  => '', // optional: link to another shortcode/page (future)
        'title'          => 'Available Orders',
    ], (array)$atts, 'knx_driver_ops_dashboard');

    $poll_ms = (int)$atts['poll_ms'];
    if ($poll_ms < 6000) $poll_ms = 6000;
    if ($poll_ms > 60000) $poll_ms = 60000;

    $api_url = esc_url(rest_url('knx/v1/ops/driver-available-orders'));
    $assign_url = esc_url(rest_url('knx/v1/ops/driver-self-assign'));

    $view_order_url = esc_url($atts['view_order_url']);
    $my_orders_url  = trim((string)$atts['my_orders_url']);
    $my_orders_url  = $my_orders_url !== '' ? esc_url($my_orders_url) : '';

    $title = trim((string)$atts['title']);
    if ($title === '') $title = 'Available Orders';

    // REST nonce (best-effort).
    $nonce = '';
    if (function_exists('knx_rest_get_nonce')) {
        $n = knx_rest_get_nonce();
        $nonce = is_string($n) ? $n : '';
    }
    if ($nonce === '' && function_exists('wp_create_nonce')) {
        $nonce = (string) wp_create_nonce('wp_rest');
    }

    // Inline assets (fail-safe).
    $css = '';
    $js  = '';

    $css_path = defined('KNX_PATH') ? (KNX_PATH . 'inc/modules/ops/driver-ops/driver-ops-style.css') : '';
    if ($css_path && file_exists($css_path)) {
        $css = (string)file_get_contents($css_path);
    }

    $js_path = defined('KNX_PATH') ? (KNX_PATH . 'inc/modules/ops/driver-ops/driver-ops-script.js') : '';
    if ($js_path && file_exists($js_path)) {
        $js = (string)file_get_contents($js_path);
    }

    ob_start();
    ?>
    <?php if (!empty($css)) : ?>
        <style data-knx="driver-ops-style"><?php echo $css; ?></style>
    <?php endif; ?>

    <div id="knxDriverOpsApp"
         class="knx-driver-ops"
         data-api-url="<?php echo $api_url; ?>"
         data-assign-url="<?php echo $assign_url; ?>"
         data-view-order-url="<?php echo $view_order_url; ?>"
         data-my-orders-url="<?php echo $my_orders_url; ?>"
         data-role="<?php echo esc_attr($role); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-poll-ms="<?php echo (int)$poll_ms; ?>">

        <h2 class="knx-visually-hidden"><?php echo esc_html($title); ?></h2>

        <div class="knx-driver-ops__top">
            <div class="knx-driver-ops__controls">
                <button type="button" class="knx-do-btn knx-do-btn--primary" id="knxDORefreshBtn">
                    Refresh
                </button>

                <div class="knx-driver-ops__pill" id="knxDOPill">
                    <?php echo esc_html($title); ?>
                </div>

                <div class="knx-driver-ops__pulse" id="knxDOPulse" aria-hidden="true"></div>
            </div>

            <?php if (!empty($my_orders_url)) : ?>
                <div class="knx-driver-ops__actions">
                    <a class="knx-do-btn knx-do-btn--ghost" href="<?php echo $my_orders_url; ?>">
                        My Orders
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="knx-driver-ops__state" id="knxDOState">
            Loading available orders…
        </div>

        <div class="knx-driver-ops__list" id="knxDOList" aria-live="polite">
            <div class="knx-do-skel">Loading…</div>
        </div>

        <div class="knx-do-toastwrap" id="knxDOToasts" aria-live="polite" aria-atomic="true"></div>
    </div>

    <?php if (!empty($js)) : ?>
        <script data-knx="driver-ops-script">
            <?php echo $js; ?>
        </script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
});
