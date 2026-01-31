<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Ops — Live Orders Shortcode
 * Shortcode: [knx_ops_live_orders]
 * Renders scaffold UI and enqueues assets inline via echo
 * ==========================================================
 */

add_shortcode('knx_ops_live_orders', function() {
    global $wpdb;

    // Require session + allowed roles
    $session = knx_get_session();
    if (!$session || !in_array(($session->role ?? ''), ['super_admin', 'manager'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $role = $session->role;

    // Manager: compute assigned cities if configured
    $managed_cities = [];
    if ($role === 'manager') {
        $hubs_table = $wpdb->prefix . 'knx_hubs';
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$hubs_table} LIKE %s", 'manager_user_id'));
        if (empty($col)) {
            // Render informative message
            ob_start();
            echo '<div class="knx-live-orders-error">Manager city assignment not configured.</div>';
            return ob_get_clean();
        }

        $user_id = isset($session->user_id) ? absint($session->user_id) : 0;
        if ($user_id) {
            $managed_cities = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT city_id FROM {$hubs_table} WHERE manager_user_id = %d AND city_id IS NOT NULL",
                $user_id
            ));
        }
    }

    // URLs
    $api_url = esc_url(rest_url('knx/v1/ops/live-orders'));
    $cities_url = esc_url(rest_url('knx/v2/cities/get'));

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/ops/live-orders/live-orders-style.css'); ?>">

    <div id="knxLiveOrdersApp"
         data-api-url="<?php echo $api_url; ?>"
         data-cities-url="<?php echo $cities_url; ?>"
         data-session-role="<?php echo esc_attr($role); ?>"
         data-managed-cities='<?php echo wp_json_encode(array_values(array_map('intval', (array)$managed_cities))); ?>'>

        <div class="knx-live-orders-header">
            <h2>Live Orders</h2>
            <div class="knx-live-orders-controls">
                <div id="knxCitySelectorContainer"></div>
            </div>
        </div>

        <div id="knxLiveOrdersState" class="knx-live-orders-state">Select a city to view live orders</div>

        <div id="knxLiveOrdersList" class="knx-live-orders-list"></div>
    </div>

    <script src="<?php echo esc_url(KNX_URL . 'inc/modules/ops/live-orders/live-orders-script.js'); ?>" defer></script>

    <?php
    return ob_get_clean();
});
