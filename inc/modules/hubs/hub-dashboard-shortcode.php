<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Management — Dashboard Shortcode  [knx_hub_dashboard]
 * ----------------------------------------------------------
 * Visual clone of [knx_ops_dashboard] reduced to hub-only scope.
 *
 * Structure: 1:1 clone of inc/modules/ops/dashboard/dashboard-shortcode.php
 * - 1 KPI card (orders count — 30 days)
 * - Total orders bar chart (Chart.js)
 * - Action shortcuts to hub-settings, hub-items
 *
 * Security: session + hub role regex + ownership via knx_hub_managers
 * ==========================================================
 */

function knx_hub_dashboard_shortcode($atts = []) {
    global $wpdb;

    // ── Session (fail-closed) ──────────────────────────────
    if (!function_exists('knx_get_session')) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Session engine unavailable.</div>';
    }

    $session = knx_get_session();
    if (!$session) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Please login to view this page.</div>';
    }

    $role    = isset($session->role) ? (string) $session->role : '';
    $user_id = isset($session->user_id) ? (int) $session->user_id : 0;

    // ── Role check (inline regex — hub family) ─────────────
    if (!preg_match('/\bhub(?:[_\-\s]?(?:management|owner|staff|manager))\b/i', $role)) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Access denied.</div>';
    }

    if ($user_id <= 0) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Invalid session.</div>';
    }

    // ── Ownership: derive hub IDs from canonical table ─────
    $hub_ids = knx_get_managed_hub_ids($user_id);
    if (empty($hub_ids)) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">No hubs assigned to your account.</div>';
    }

    // MVP: first hub
    $hub_id = $hub_ids[0];

    // ── Fetch hub row ──────────────────────────────────────
    $hubs_table = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, status, type FROM {$hubs_table} WHERE id = %d LIMIT 1",
        $hub_id
    ));

    if (!$hub) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Hub not found.</div>';
    }

    $hub_name   = esc_html($hub->name);
    $hub_status = ucfirst(esc_html($hub->status ?? 'unknown'));
    $is_food_truck = ($hub->type === 'Food Truck');

    // ── API + nonce for JS ─────────────────────────────────
    $api_url    = esc_url(rest_url('knx/v1/hub-management/dashboard') . '?hub_id=' . $hub_id);
    $rest_nonce = wp_create_nonce('wp_rest');

    // ── URLs for shortcuts ─────────────────────────────────
    $settings_url = esc_url(site_url('/hub-settings?hub_id=' . $hub_id));
    $items_url    = esc_url(site_url('/hub-items?hub_id=' . $hub_id));

    // ── Inline CSS (reuse canonical dashboard tokens) ──────
    $css_path = dirname(__DIR__) . '/ops/dashboard/dashboard-style.css';
    $css      = file_exists($css_path) ? file_get_contents($css_path) : '';

    ob_start();
    ?>
    <style><?php echo $css; ?></style>
    <style>
    /* Hub-dashboard overrides */
    .knx-hub-dash__header {
        display: flex; align-items: center; gap: 14px;
        margin-bottom: 20px; flex-wrap: wrap;
    }
    .knx-hub-dash__name {
        font-size: 1.35rem; font-weight: 700; color: var(--dash-text, #0b1220);
        margin: 0;
    }
    .knx-hub-dash__badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 4px 12px; border-radius: 20px;
        font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
    }
    .knx-hub-dash__badge--active   { background: #d1fae5; color: #065f46; }
    .knx-hub-dash__badge--inactive { background: #fee2e2; color: #991b1b; }
    .knx-hub-dash__shortcuts {
        display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap;
    }
    .knx-hub-dash__shortcut {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 16px; border-radius: 10px; border: 1px solid var(--dash-border, #e5e7eb);
        background: var(--dash-card, #ffffff); color: var(--dash-text, #0b1220);
        text-decoration: none; font-weight: 600; font-size: 14px;
        transition: transform 0.1s ease, box-shadow 0.12s ease;
    }
    .knx-hub-dash__shortcut:hover {
        transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.08);
    }
    .knx-hub-dash__shortcut i { font-size: 14px; }
    @media (max-width: 480px) {
        .knx-hub-dash__shortcuts { flex-direction: column; }
        .knx-dash__cards { grid-template-columns: 1fr !important; }
    }
    </style>

    <!-- Logout button (top-right, hub_management) -->
    <form method="post" class="knx-hm-logout" style="position:fixed;top:12px;right:16px;z-index:900;">
      <?php wp_nonce_field('knx_logout_action', 'knx_logout_nonce'); ?>
      <button type="submit" name="knx_logout" aria-label="Logout"
              style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-size:13px;font-weight:600;color:#374151;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.06);transition:all .12s ease;"
              onmouseover="this.style.background='#fef2f2';this.style.color='#dc2626';this.style.borderColor='#fecaca';"
              onmouseout="this.style.background='#fff';this.style.color='#374151';this.style.borderColor='#e5e7eb';">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Logout
      </button>
    </form>

    <div id="knxHubDashboardApp"
         class="knx-dash"
         data-api-url="<?php echo $api_url; ?>"
         data-rest-nonce="<?php echo $rest_nonce; ?>"
         data-role="<?php echo esc_attr($role); ?>"
         data-hub-id="<?php echo esc_attr($hub_id); ?>">

        <!-- ── Hub Header ──────────────────────────────────── -->
        <div class="knx-hub-dash__header">
            <h1 class="knx-hub-dash__name"><?php echo $hub_name; ?></h1>
            <span class="knx-hub-dash__badge knx-hub-dash__badge--<?php echo esc_attr($hub->status); ?>">
                <i class="fas fa-circle" style="font-size:8px;"></i>
                <?php echo $hub_status; ?>
            </span>
        </div>

        <!-- ── KPI Card (single — orders only) ────────────── -->
        <div class="knx-dash__cards" style="grid-template-columns: 1fr; max-width: 360px;">
            <div class="knx-dash__card" data-card="orders">
                <div class="knx-dash__card-body">
                    <span class="knx-dash__card-label">ORDERS ( 30 DAYS )</span>
                    <span class="knx-dash__card-value knx-dash__card-value--loading" id="knxHubDashOrders">—</span>
                </div>
                <div class="knx-dash__card-icon knx-dash__card-icon--yellow">
                    <i class="fas fa-shopping-bag"></i>
                </div>
            </div>
        </div>

        <!-- ── Chart (orders bar only) ─────────────────────── -->
        <div class="knx-dash__charts" style="grid-template-columns: 1fr;">
            <div class="knx-dash__chart-card knx-dash__chart-card--orders">
                <div class="knx-dash__chart-header">
                    <span class="knx-dash__chart-overtitle">PERFORMANCE</span>
                    <span class="knx-dash__chart-title">Total orders</span>
                </div>
                <div class="knx-dash__chart-canvas-wrap">
                    <canvas id="knxHubDashOrdersChart"></canvas>
                </div>
            </div>
        </div>

        <!-- ── Shortcuts ───────────────────────────────────── -->
        <div class="knx-hub-dash__shortcuts">
            <a class="knx-hub-dash__shortcut" href="<?php echo $settings_url; ?>">
                <i class="fas fa-cog"></i> Hub Settings
            </a>
            <a class="knx-hub-dash__shortcut" href="<?php echo $items_url; ?>">
                <i class="fas fa-utensils"></i> Hub Items
            </a>
            <?php if ($is_food_truck) : ?>
            <a class="knx-hub-dash__shortcut" href="<?php echo $settings_url; ?>&amp;tab=foodtruck">
                <i class="fas fa-truck"></i> Food Truck Location
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
    /**
     * KNX Hub Dashboard — JS Controller
     * Clone of dashboard-script.js reduced to hub scope
     */
    (function () {
        'use strict';

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }

        function init() {
            const app = document.getElementById('knxHubDashboardApp');
            if (!app) return;

            const apiUrl    = app.dataset.apiUrl    || '';
            const restNonce = app.dataset.restNonce || '';

            fetchDashboard(apiUrl, restNonce);
        }

        async function fetchDashboard(apiUrl, restNonce) {
            try {
                const res = await fetch(apiUrl, {
                    headers: { 'X-WP-Nonce': restNonce },
                    credentials: 'same-origin',
                });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    showError(json.message || 'Failed to load dashboard.');
                    return;
                }

                const data = json.data || {};
                renderCards(data.cards || {});
                renderOrdersChart(data.orders_series || []);

            } catch (e) {
                showError('Network error — please refresh.');
            }
        }

        function renderCards(cards) {
            setCardLoading(false);
            setVal('knxHubDashOrders', formatInt(cards.orders));
        }

        function setVal(id, text) {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        }

        function setCardLoading(on) {
            document.querySelectorAll('#knxHubDashboardApp .knx-dash__card-value').forEach(v => {
                if (on) {
                    v.textContent = '';
                    v.classList.add('knx-dash__card-value--loading');
                } else {
                    v.classList.remove('knx-dash__card-value--loading');
                }
            });
        }

        function renderOrdersChart(series) {
            const ctx = document.getElementById('knxHubDashOrdersChart');
            if (!ctx || typeof Chart === 'undefined') return;

            const labels = series.map(s => s.month);
            const values = series.map(s => s.value);

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Total orders',
                        data: values,
                        backgroundColor: '#0b793a',
                        borderRadius: 6, borderSkipped: false,
                        barPercentage: 0.55, categoryPercentage: 0.7,
                    }],
                },
                options: chartOpts(),
            });
        }

        function chartOpts() {
            return {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b', titleColor: '#94a3b8',
                        bodyColor: '#f8fafc', bodyFont: { weight: '600' },
                        padding: 12, cornerRadius: 10,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6b7280', font: { size: 12 } },
                        border: { display: false },
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: {
                            color: '#6b7280',
                            font: { size: 11 },
                            callback: function (v) { return formatCompact(v); },
                        },
                        border: { display: false }, beginAtZero: true,
                    },
                },
            };
        }

        function showError(msg) {
            const app = document.getElementById('knxHubDashboardApp');
            if (app) app.innerHTML = '<div style="padding:2rem;text-align:center;color:#ef4444;">' + msg + '</div>';
        }

        function formatInt(n)    { return (parseInt(n) || 0).toLocaleString('en-US'); }
        function formatCompact(n) {
            if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
            if (n >= 1000)    return (n / 1000).toFixed(1) + 'k';
            return n;
        }
    })();
    </script>
    <?php

    return ob_get_clean();
}

add_shortcode('knx_hub_dashboard', 'knx_hub_dashboard_shortcode');
