<?php
/**
 * ════════════════════════════════════════════════════════════════
 * KNX OPS — Dashboard Shortcode  [knx_ops_dashboard]
 * ════════════════════════════════════════════════════════════════
 *
 * Renders the analytics dashboard for super_admin / manager.
 * Uses Chart.js (CDN) for the two graphs.
 */

if (!defined('ABSPATH')) exit;

add_shortcode('knx_ops_dashboard', function ($atts = []) {
    if (!function_exists('knx_get_session')) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Session engine unavailable.</div>';
    }

    $session = knx_get_session();
    $role    = $session ? (string) ($session->role    ?? '') : '';
    $user_id = $session ? (int)    ($session->user_id ?? 0)  : 0;

    if (!$session || !in_array($role, ['super_admin', 'manager'], true)) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Access denied.</div>';
    }

    $api_url    = esc_url(rest_url('knx/v1/ops/dashboard'));
    $rest_nonce = wp_create_nonce('wp_rest');

    // Inline CSS
    $css_path = __DIR__ . '/dashboard-style.css';
    $css      = file_exists($css_path) ? file_get_contents($css_path) : '';

    // Inline JS
    $js_path = __DIR__ . '/dashboard-script.js';
    $js      = file_exists($js_path) ? file_get_contents($js_path) : '';

    ob_start();
    ?>
    <style><?php echo $css; ?></style>

    <div id="knxDashboardApp"
         class="knx-dash"
         data-api-url="<?php echo $api_url; ?>"
         data-rest-nonce="<?php echo $rest_nonce; ?>"
         data-role="<?php echo esc_attr($role); ?>">

        <!-- ── KPI Cards Row 1 ─────────────────────────────────── -->
        <div class="knx-dash__cards">
            <div class="knx-dash__card" data-card="orders">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">ORDERS ( 30 DAYS )</span>
                    <span class="knx-dash__card-value" id="knxDashOrders">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--yellow">
                    <i class="fas fa-shopping-bag"></i>
                </div>
            </div>

            <div class="knx-dash__card" data-card="sales_volume">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">SALES VOLUME ( 30 DAYS )</span>
                    <span class="knx-dash__card-value" id="knxDashSalesVol">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--red">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>

            <div class="knx-dash__card" data-card="unique_customers">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">ORDERS FROM</span>
                    <span class="knx-dash__card-value" id="knxDashCustomers">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--orange">
                    <i class="fas fa-phone-alt"></i>
                </div>
            </div>

            <div class="knx-dash__card" data-card="exposure">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">EXPOSURE TO</span>
                    <span class="knx-dash__card-value" id="knxDashExposure">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--cyan">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>

        <!-- ── KPI Cards Row 2 ─────────────────────────────────── -->
        <div class="knx-dash__cards">
            <div class="knx-dash__card" data-card="delivery_fee">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">DELIVERY FEE ( 30 DAYS )</span>
                    <span class="knx-dash__card-value" id="knxDashDeliveryFee">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--yellow">
                    <i class="fas fa-truck"></i>
                </div>
            </div>

            <div class="knx-dash__card" data-card="static_fee">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">STATIC FEE ( 30 DAYS )</span>
                    <span class="knx-dash__card-value" id="knxDashStaticFee">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--red">
                    <i class="fas fa-chart-bar"></i>
                </div>
            </div>

            <div class="knx-dash__card" data-card="dynamic_fee">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">DYNAMIC FEE ( 30 DAYS )</span>
                    <span class="knx-dash__card-value" id="knxDashDynamicFee">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--orange">
                    <i class="fas fa-percentage"></i>
                </div>
            </div>

            <div class="knx-dash__card" data-card="total_fee">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">TOTAL FEE ( 30 DAYS )</span>
                    <span class="knx-dash__card-value" id="knxDashTotalFee">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--cyan">
                    <i class="fas fa-coins"></i>
                </div>
            </div>
        </div>

        <!-- ── Charts Row ──────────────────────────────────────── -->
        <div class="knx-dash__charts">
            <div class="knx-dash__chart-card knx-dash__chart-card--sales">
                <div class="knx-dash__chart-header">
                    <span class="knx-dash__chart-overtitle">OVERVIEW</span>
                    <span class="knx-dash__chart-title">Sales value</span>
                </div>
                <div class="knx-dash__chart-canvas-wrap">
                    <canvas id="knxDashSalesChart"></canvas>
                </div>
            </div>

            <div class="knx-dash__chart-card knx-dash__chart-card--orders">
                <div class="knx-dash__chart-header">
                    <span class="knx-dash__chart-overtitle">PERFORMANCE</span>
                    <span class="knx-dash__chart-title">Total orders</span>
                </div>
                <div class="knx-dash__chart-canvas-wrap">
                    <canvas id="knxDashOrdersChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script><?php echo $js; ?></script>
    <?php
    return ob_get_clean();
});
