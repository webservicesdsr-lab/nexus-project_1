<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — View Order Shortcode
 * Shortcode: [knx_ops_view_order]
 *
 * Notes:
 * - Assets are injected via echo/link/script (no wp_footer dependency).
 * - Reads order_id from query string (?order_id=123).
 * - Managers are fail-closed if {prefix}knx_manager_cities is missing/empty.
 * - Does NOT expose order_id/city_id in DOM datasets.
 * ==========================================================
 */

function knx_ops_view_order_shortcode() {
    global $wpdb;

    if (!function_exists('knx_get_session')) {
        return '<div class="knx-ops-err">Session unavailable.</div>';
    }

    $session = knx_get_session();
    $role = ($session && isset($session->role)) ? (string)$session->role : '';

    if (!$session || !in_array($role, ['super_admin', 'manager'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // Manager scope preflight (fail-closed)
    if ($role === 'manager') {
        $user_id = isset($session->user_id) ? (int)$session->user_id : 0;
        if (!$user_id) {
            return '<div class="knx-ops-err">Unauthorized.</div>';
        }

        $mc_table = $wpdb->prefix . 'knx_manager_cities';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $mc_table));
        if (empty($exists)) {
            return '<div class="knx-ops-err">Manager city assignment not configured.</div>';
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id
             FROM {$mc_table}
             WHERE manager_user_id = %d
               AND city_id IS NOT NULL",
            $user_id
        ));

        $ids = array_map('intval', (array)$ids);
        $ids = array_values(array_filter($ids, static function ($v) { return $v > 0; }));

        if (empty($ids)) {
            return '<div class="knx-ops-err">No cities assigned to this manager.</div>';
        }
    }

    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

    $api_url  = esc_url(rest_url('knx/v1/ops/view-order'));
    $back_url = esc_url(site_url('/live-orders'));
    $ver = defined('KNX_VERSION') ? KNX_VERSION : (string)time();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/ops/view-order/view-order-style.css'); ?>?v=<?php echo esc_attr($ver); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/ops/view-order/view-order-actions.css'); ?>?v=<?php echo esc_attr($ver); ?>">

    <div id="knxOpsViewOrderApp"
         class="knx-ops-vo"
         data-api-url="<?php echo $api_url; ?>"
         data-role="<?php echo esc_attr($role); ?>"
         data-back-url="<?php echo $back_url; ?>"
         data-update-status-url="<?php echo esc_url(rest_url('knx/v1/ops/update-status')); ?>"
         data-assign-driver-url="<?php echo esc_url(rest_url('knx/v1/ops/assign-driver')); ?>"
         data-unassign-driver-url="<?php echo esc_url(rest_url('knx/v1/ops/unassign-driver')); ?>"
         data-drivers-url="<?php echo esc_url(rest_url('knx/v1/ops/drivers')); ?>"
         data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">

        <div class="knx-ops-vo__shell">
            <div class="knx-ops-vo__top">
                <div class="knx-ops-vo__row">
                    <a class="knx-ops-vo__back" href="<?php echo $back_url; ?>">&larr; Back to Live Orders</a>
                    <div id="knxViewOrderActions" data-knx-view-order-actions="1"></div>
                </div>

                <div class="knx-ops-vo__title">
                    <h2>Order Details</h2>
                    <div id="knxOpsVOState" class="knx-ops-vo__state">
                        <?php echo ($order_id > 0) ? 'Loading…' : 'Missing order_id'; ?>
                    </div>
                </div>
            </div>

            <div id="knxOpsVOContent" class="knx-ops-vo__content"></div>
        </div>
    </div>

    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/ops/view-order/view-order-script.js'); ?>?v=<?php echo esc_attr($ver); ?>" defer></script>
    <?php
    /**
     * Inline the single actions add-on (no wp_enqueue / no wp_footer).
     */
    $addon_path = __DIR__ . '/view-order-actions.js';
    if (is_readable($addon_path)) {
        echo "\n" . '<script>' . "\n" . file_get_contents($addon_path) . "\n" . '</script>' . "\n";
    }

    return ob_get_clean();
}

add_shortcode('knx_ops_view_order', 'knx_ops_view_order_shortcode');
add_shortcode('knx-view-orders', 'knx_ops_view_order_shortcode');
