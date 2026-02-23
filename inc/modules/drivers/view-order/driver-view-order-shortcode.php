<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Driver — View Order Shortcode (DB-canon)
 * Shortcode: [knx_driver_view_order]
 *
 * Notes:
 * - Assets injected inline (no wp_footer).
 * - Reads order_id from query string (?order_id=123).
 * - Drivers are fail-closed if knx_driver_cities is missing/empty.
 * - Uses ONLY knx_orders.status (DB-canon).
 * ==========================================================
 */

function knx_driver_view_order_shortcode() {
    global $wpdb;

    if (!function_exists('knx_get_driver_context')) {
        return '<div class="knx-driver-vo-err">Driver context unavailable.</div>';
    }

    $ctx = knx_get_driver_context();
    if (!$ctx || !$ctx->session || !$ctx->session->user_id) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $role = isset($ctx->session->role) ? (string)$ctx->session->role : '';
    if ($role !== 'driver') {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // Driver scope preflight (fail-closed)
    $driver_user_id = (int)$ctx->session->user_id;
    $driver_profile_id = (is_object($ctx->driver) && isset($ctx->driver->id)) ? (int)$ctx->driver->id : 0;

    if ($driver_profile_id <= 0) {
        $drivers_table = $wpdb->prefix . 'knx_drivers';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drivers_table));
        if (!empty($exists)) {
            $cols = $wpdb->get_results("SHOW COLUMNS FROM {$drivers_table}", ARRAY_A);
            $names = $cols ? array_map(function($c){ return $c['Field']; }, $cols) : [];
            
            $conds = [];
            $args  = [];
            
            if (in_array('driver_user_id', $names, true)) { $conds[] = "driver_user_id = %d"; $args[] = $driver_user_id; }
            if (in_array('user_id', $names, true)) { $conds[] = "user_id = %d"; $args[] = $driver_user_id; }
            if (in_array('id', $names, true)) { $conds[] = "id = %d"; $args[] = $driver_user_id; }
            
            if (!empty($conds)) {
                $sql = "SELECT id FROM {$drivers_table} WHERE " . implode(' OR ', $conds) . " LIMIT 1";
                $driver_profile_id = (int)$wpdb->get_var($wpdb->prepare($sql, $args));
            }
        }
    }

    if ($driver_profile_id <= 0) {
        return '<div class="knx-driver-vo-err">Driver profile not found.</div>';
    }

    $dc_table = $wpdb->prefix . 'knx_driver_cities';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $dc_table));
    if (empty($table_exists)) {
        return '<div class="knx-driver-vo-err">Driver city assignment not configured.</div>';
    }

    $driver_cities = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_id FROM {$dc_table} WHERE driver_id = %d AND city_id IS NOT NULL",
        $driver_profile_id
    ));

    $driver_cities = array_map('intval', (array)$driver_cities);
    $driver_cities = array_values(array_filter($driver_cities, static function ($v) { return $v > 0; }));

    if (empty($driver_cities)) {
        return '<div class="knx-driver-vo-err">No cities assigned to this driver.</div>';
    }

    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

    $back_url = esc_url(site_url('/driver-active-orders'));
    $status_change_url = esc_url(rest_url('knx/v2/driver/orders'));
    $release_url = esc_url(rest_url('knx/v2/driver/orders'));

    $knx_nonce = wp_create_nonce('knx_nonce');

    // Inline assets
    $css = '';
    $js  = '';

    $css_path = __DIR__ . '/driver-view-order-style.css';
    if (file_exists($css_path)) {
        $css = (string)file_get_contents($css_path);
    }

    $js_path = __DIR__ . '/driver-view-order-script.js';
    if (file_exists($js_path)) {
        $js = (string)file_get_contents($js_path);
    }

    ob_start();
    ?>
    <?php if (!empty($css)) : ?>
        <style data-knx="driver-view-order-style"><?php echo $css; ?></style>
    <?php endif; ?>

    <div id="knxDriverViewOrderApp"
         class="knx-driver-vo"
         data-order-id="<?php echo (int)$order_id; ?>"
         data-driver-profile-id="<?php echo (int)$driver_profile_id; ?>"
         data-status-change-url="<?php echo esc_attr($status_change_url); ?>"
         data-release-url="<?php echo esc_attr($release_url); ?>"
         data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
         data-back-url="<?php echo esc_attr($back_url); ?>">

        <div class="knx-driver-vo__shell">

            <div class="knx-driver-vo__topbar">
                <a class="knx-driver-vo__back" href="<?php echo esc_url($back_url); ?>">&larr; Back to Orders</a>
            </div>

            <div class="knx-driver-vo__title">
                <h2>Order Details</h2>
                <div id="knxDriverVOState" class="knx-driver-vo__state">
                    <?php echo ($order_id > 0) ? 'Loading…' : 'Missing order_id'; ?>
                </div>
            </div>

            <div id="knxDriverVOContent" class="knx-driver-vo__content"></div>
        </div>
    </div>

    <?php if (!empty($js)) : ?>
        <script data-knx="driver-view-order-script"><?php echo $js; ?></script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

add_shortcode('knx_driver_view_order', 'knx_driver_view_order_shortcode');
