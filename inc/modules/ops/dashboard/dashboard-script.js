/**
 * ════════════════════════════════════════════════════════════════
 * KNX OPS — Dashboard Analytics Script  v1.0
 * ════════════════════════════════════════════════════════════════
 *
 * Fetches /knx/v1/ops/dashboard and renders:
 *  - 8 KPI cards (animated count-up)
 *  - Sales Value line chart  (Chart.js)
 *  - Total Orders bar chart  (Chart.js)
 */

(function () {
    'use strict';

    // DOMContentLoaded may have already fired when inline <script> runs
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        const app = document.getElementById('knxDashboardApp');
        if (!app) return;

        const apiUrl    = app.dataset.apiUrl    || '';
        const restNonce = app.dataset.restNonce || '';

        // Show skeleton shimmer
        setCardLoading(true);

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
            renderSalesChart(data.sales_series || []);
            renderOrdersChart(data.orders_series || []);

        } catch (e) {
            showError('Network error — please refresh.');
        }
    }

    // ── Cards ───────────────────────────────────────────────────
    function renderCards(cards) {
        setCardLoading(false);

        setVal('knxDashOrders',      formatInt(cards.orders));
        setVal('knxDashSalesVol',    '$' + formatMoney(cards.sales_volume));
        setVal('knxDashCustomers',   formatInt(cards.unique_customers) + ' Unique users');
        setVal('knxDashExposure',    formatInt(cards.exposure) + ' Users');
        setVal('knxDashDeliveryFee', '$' + formatMoney(cards.delivery_fee));
        setVal('knxDashStaticFee',   '$' + formatMoney(cards.static_fee));
        setVal('knxDashTotalFee',    '$' + formatMoney(cards.total_fee));
        setVal('knxDashExposure2',   formatInt(cards.exposure) + ' Users');
    }

    function setVal(id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function setCardLoading(on) {
        const vals = document.querySelectorAll('.knx-dash__card-value');
        vals.forEach(v => {
            if (on) {
                v.textContent = '';
                v.classList.add('knx-dash__card-value--loading');
            } else {
                v.classList.remove('knx-dash__card-value--loading');
            }
        });
    }

    // ── Sales Chart (Line / Area) ───────────────────────────────
    function renderSalesChart(series) {
        const ctx = document.getElementById('knxDashSalesChart');
        if (!ctx || typeof Chart === 'undefined') return;

        const labels = series.map(s => s.month);
        const values = series.map(s => s.value);

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Sales value',
                    data: values,
                    borderColor: '#1E6FF0',
                    backgroundColor: createGradient(ctx, 'rgba(30,111,240,0.25)', 'rgba(30,111,240,0)'),
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: '#1E6FF0',
                    borderWidth: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#94a3b8',
                        bodyColor: '#f8fafc',
                        bodyFont: { weight: '600' },
                        padding: 12,
                        cornerRadius: 10,
                        callbacks: {
                            label: function (ctx) {
                                return '$' + formatMoney(ctx.parsed.y);
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: 'rgba(255,255,255,0.45)', font: { size: 12 } },
                        border: { display: false },
                    },
                    y: {
                        grid: { color: 'rgba(255,255,255,0.06)' },
                        ticks: {
                            color: 'rgba(255,255,255,0.45)',
                            font: { size: 11 },
                            callback: function (v) { return formatCompact(v); },
                        },
                        border: { display: false },
                        beginAtZero: true,
                    },
                },
            },
        });
    }

    // ── Orders Chart (Bar) ──────────────────────────────────────
    function renderOrdersChart(series) {
        const ctx = document.getElementById('knxDashOrdersChart');
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
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.55,
                    categoryPercentage: 0.7,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#94a3b8',
                        bodyColor: '#f8fafc',
                        bodyFont: { weight: '600' },
                        padding: 12,
                        cornerRadius: 10,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6b7280', font: { size: 12 } },
                        border: { display: false },
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.05)', drawTicks: false },
                        ticks: {
                            color: '#6b7280',
                            font: { size: 11 },
                            stepSize: 20,
                        },
                        border: { display: false },
                        beginAtZero: true,
                    },
                },
            },
        });
    }

    // ── Helpers ─────────────────────────────────────────────────
    function createGradient(canvas, top, bottom) {
        const c = canvas.getContext('2d');
        const g = c.createLinearGradient(0, 0, 0, canvas.parentElement.clientHeight || 300);
        g.addColorStop(0, top);
        g.addColorStop(1, bottom);
        return g;
    }

    function formatMoney(n) {
        return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatInt(n) {
        return Number(n || 0).toLocaleString('en-US');
    }

    function formatCompact(value) {
        if (value >= 1000000) return (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (value >= 1000)    return (value / 1000).toFixed(0) + 'k';
        return value;
    }

    function showError(msg) {
        setCardLoading(false);
        const app = document.getElementById('knxDashboardApp');
        if (!app) return;
        const div = document.createElement('div');
        div.className = 'knx-dash__error';
        div.textContent = msg;
        app.appendChild(div);
    }
})();
