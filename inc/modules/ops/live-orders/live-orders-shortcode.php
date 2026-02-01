<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX OPS — Live Orders Board (Shortcode)
 * Shortcode: [knx_ops_live_orders]
 *
 * Notes:
 * - Assets injected inline via echo/file_get_contents (no wp_footer dependency).
 * - Managers and super_admins share the same UI, scoped by role.
 * ==========================================================
 */

add_shortcode('knx_ops_live_orders', function ($atts = []) {
    global $wpdb;

    if (!function_exists('knx_get_session')) {
        return '<div class="knx-live-orders-error">Session unavailable.</div>';
    }

    $session = knx_get_session();
    $role = $session && isset($session->role) ? (string)$session->role : '';

    if (!in_array($role, ['super_admin', 'manager'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $atts = shortcode_atts([
        // Where "View Order" should navigate (shortcode page you will create)
        // Updated to match installed pages-installer route `/view-order`.
        'view_order_url'   => site_url('/view-order'),
        // Poll interval
        'poll_ms'          => 12000,
        // Include "Completed/Cancelled" column (recent only)
        'include_resolved' => 1,
        // How many hours back to show resolved items
        'resolved_hours'   => 24,
    ], (array)$atts, 'knx_ops_live_orders');

    $poll_ms = (int)$atts['poll_ms'];
    if ($poll_ms < 6000) $poll_ms = 6000;
    if ($poll_ms > 60000) $poll_ms = 60000;

    $include_resolved = ((int)$atts['include_resolved'] === 0) ? 0 : 1;

    $resolved_hours = (int)$atts['resolved_hours'];
    if ($resolved_hours <= 0) $resolved_hours = 24;
    if ($resolved_hours > 168) $resolved_hours = 168;

    // Manager cities (fail-closed if assignment isn't configured)
    $managed_cities = [];
    if ($role === 'manager') {
        $hubs_table = $wpdb->prefix . 'knx_hubs';
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$hubs_table} LIKE %s", 'manager_user_id'));
        if (empty($col)) {
            return '<div class="knx-live-orders-error">Manager city assignment not configured.</div>';
        }

        $user_id = isset($session->user_id) ? (int)$session->user_id : 0;
        if (!$user_id) {
            return '<div class="knx-live-orders-error">Unauthorized.</div>';
        }

        $managed_cities = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id
             FROM {$hubs_table}
             WHERE manager_user_id = %d
               AND city_id IS NOT NULL",
            $user_id
        ));
        $managed_cities = array_map('intval', (array)$managed_cities);
        $managed_cities = array_values(array_filter($managed_cities, function($v){ return $v > 0; }));

        if (empty($managed_cities)) {
            return '<div class="knx-live-orders-error">No cities assigned to this manager.</div>';
        }
    }

    $api_url = esc_url(rest_url('knx/v1/ops/live-orders'));
    $cities_url = esc_url(rest_url('knx/v2/cities/get'));

    $view_order_url = esc_url($atts['view_order_url']);

    // Inline assets (fail-safe)
    $css = '';
    $js = '';

    $css_path = defined('KNX_PATH') ? (KNX_PATH . 'inc/modules/ops/live-orders/live-orders-style.css') : '';
    if ($css_path && file_exists($css_path)) {
        $css = (string)file_get_contents($css_path);
    }

    $js_path = defined('KNX_PATH') ? (KNX_PATH . 'inc/modules/ops/live-orders/live-orders-script.js') : '';
    if ($js_path && file_exists($js_path)) {
        $js = (string)file_get_contents($js_path);
    }

    ob_start();
    ?>
    <?php if (!empty($css)) : ?>
        <style data-knx="live-orders-style"><?php echo $css; ?></style>
    <?php endif; ?>

    <div id="knxLiveOrdersApp"
         class="knx-live-orders"
         data-api-url="<?php echo $api_url; ?>"
         data-cities-url="<?php echo $cities_url; ?>"
         data-role="<?php echo esc_attr($role); ?>"
         data-managed-cities='<?php echo wp_json_encode($managed_cities); ?>'
         data-view-order-url="<?php echo $view_order_url; ?>"
         data-poll-ms="<?php echo (int)$poll_ms; ?>"
         data-include-resolved="<?php echo (int)$include_resolved; ?>"
         data-resolved-hours="<?php echo (int)$resolved_hours; ?>">

        <div class="knx-live-orders__top">
            <div class="knx-live-orders__title">
                <h2>Live Orders</h2>
                <div class="knx-live-orders__subtitle">
                    Operational board (New → In Progress → Completed)
                </div>
            </div>

            <div class="knx-live-orders__controls">
                <button type="button" class="knx-lo-btn knx-lo-btn--primary" id="knxLOSelectCitiesBtn">
                    Select Cities
                </button>
                <div class="knx-live-orders__pill" id="knxLOSelectedCitiesPill">No cities selected</div>
                <div class="knx-live-orders__pulse" id="knxLOPulse" aria-hidden="true"></div>
            </div>
        </div>

        <div class="knx-live-orders__state" id="knxLOState">
            Select a city to view live orders.
        </div>

        <div class="knx-live-orders__board">
            <section class="knx-lo-col" aria-label="New Orders">
                <div class="knx-lo-col__head">
                    <h3>New Orders</h3>
                    <span class="knx-lo-col__count" id="knxLOCountNew">0</span>
                </div>
                <div class="knx-lo-col__list" id="knxLOListNew"></div>
            </section>

            <section class="knx-lo-col" aria-label="In Progress">
                <div class="knx-lo-col__head">
                    <h3>In Progress</h3>
                    <span class="knx-lo-col__count" id="knxLOCountProgress">0</span>
                </div>
                <div class="knx-lo-col__list" id="knxLOListProgress"></div>
            </section>

            <section class="knx-lo-col" aria-label="Completed">
                <div class="knx-lo-col__head">
                    <h3>Completed</h3>
                    <span class="knx-lo-col__count" id="knxLOCountDone">0</span>
                </div>
                <div class="knx-lo-col__list" id="knxLOListDone"></div>
            </section>
        </div>

        <!-- Cities modal -->
        <div class="knx-lo-modal" id="knxLOModal" aria-hidden="true">
            <div class="knx-lo-modal__backdrop" data-close="1"></div>
            <div class="knx-lo-modal__panel" role="dialog" aria-modal="true" aria-labelledby="knxLOModalTitle">
                <div class="knx-lo-modal__header">
                    <div>
                        <div class="knx-lo-modal__title" id="knxLOModalTitle">Select cities</div>
                        <div class="knx-lo-modal__hint">Super Admin: choose any cities. Manager: limited to assigned cities.</div>
                    </div>
                    <button type="button" class="knx-lo-btn knx-lo-btn--ghost" data-close="1">Close</button>
                </div>

                <div class="knx-lo-modal__actions">
                    <button type="button" class="knx-lo-btn knx-lo-btn--ghost" id="knxLOSelectAllBtn">Select all</button>
                    <button type="button" class="knx-lo-btn knx-lo-btn--ghost" id="knxLOClearBtn">Clear</button>
                </div>

                <div class="knx-lo-modal__list" id="knxLOCityList">
                    <div class="knx-lo-skel">Loading cities…</div>
                </div>

                <div class="knx-lo-modal__footer">
                    <button type="button" class="knx-lo-btn knx-lo-btn--primary" id="knxLOApplyBtn">Apply</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($js)) : ?>
        <script data-knx="live-orders-script">
            <?php echo $js; ?>
        </script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
});
