<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Management — View Order Shortcode [knx_hub_view_order]
 * ----------------------------------------------------------
 * Dedicated order detail page for hub_management role.
 *
 * Features:
 *  - Full order detail with items + modifiers
 *  - Customer name (no address)
 *  - Driver info for delivery orders
 *  - Clear pickup vs delivery distinction
 *  - Status timeline
 *  - Ready for Pickup signal button
 *  - Totals breakdown
 *
 * Security: session + hub role + ownership (fail-closed)
 * ==========================================================
 */

add_shortcode('knx_hub_view_order', function () {
    global $wpdb;

    // ── Session (fail-closed) ──────────────────────────────
    if (!function_exists('knx_get_session')) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Session engine unavailable.</div>';
    }

    $session = knx_get_session();
    if (!$session) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $role    = isset($session->role) ? (string) $session->role : '';
    $user_id = isset($session->user_id) ? (int) $session->user_id : 0;

    // ── Role check (hub family) ────────────────────────────
    if (!preg_match('/\bhub(?:[_\-\s]?(?:management|owner|staff|manager))\b/i', $role)) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Access denied.</div>';
    }

    if ($user_id <= 0) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">Invalid session.</div>';
    }

    // ── Ownership ──────────────────────────────────────────
    $hub_ids = knx_get_managed_hub_ids($user_id);
    if (empty($hub_ids)) {
        return '<div style="padding:2rem;text-align:center;color:#6b7280;">No hubs assigned to your account.</div>';
    }

    $hub_id = $hub_ids[0];

    // Hub name
    $hubs_table = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$hubs_table} WHERE id = %d LIMIT 1", $hub_id));
    $hub_name = $hub ? esc_html($hub->name) : 'Hub #' . $hub_id;

    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    $wp_nonce = wp_create_nonce('wp_rest');
    $knx_nonce = wp_create_nonce('knx_hub_orders_nonce');

    $api_order  = esc_url(rest_url('knx/v1/orders/' . $order_id));
    $api_signal = esc_url(rest_url('knx/v1/hub-management/orders/signal'));
    $back_url   = esc_url(site_url('/hub-orders'));

    $ver = defined('KNX_VERSION') ? KNX_VERSION : '1.0';

    ob_start();
    ?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.css'); ?>">

<style>
/* ── Hub View Order Page ───────────────────────────────── */
.knx-hvo {
    max-width: 720px;
    margin: 0 auto;
    padding: 16px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* Top bar */
.knx-hvo__topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.knx-hvo__back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #fff;
    font-weight: 600;
    font-size: 13px;
    color: #374151;
    text-decoration: none;
    transition: all 0.12s ease;
}

.knx-hvo__back:hover {
    background: #f3f4f6;
    transform: translateY(-1px);
}

.knx-hvo__order-id {
    font-weight: 700;
    font-size: 1.2rem;
    color: #0b1220;
}

/* Loading / error */
.knx-hvo__loading,
.knx-hvo__error {
    text-align: center;
    padding: 48px 24px;
    color: #6b7280;
}

.knx-hvo__loading i { font-size: 32px; color: #d1d5db; }
.knx-hvo__error i { font-size: 48px; color: #fca5a5; display: block; margin-bottom: 12px; }

/* Section card */
.knx-hvo__section {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    padding: 16px 20px;
    margin-bottom: 14px;
}

.knx-hvo__section-title {
    font-size: 14px;
    font-weight: 700;
    color: #0b1220;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.knx-hvo__section-title i { color: #6b7280; font-size: 14px; }

/* Status + fulfillment header */
.knx-hvo__status-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}

.knx-hvo__status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.knx-hvo__status-badge.st-confirmed       { background: #dbeafe; color: #1e40af; }
.knx-hvo__status-badge.st-accepted_by_driver { background: #fef3c7; color: #92400e; }
.knx-hvo__status-badge.st-accepted_by_hub { background: #fef3c7; color: #92400e; }
.knx-hvo__status-badge.st-preparing       { background: #ffedd5; color: #9a3412; }
.knx-hvo__status-badge.st-prepared        { background: #d1fae5; color: #065f46; }
.knx-hvo__status-badge.st-picked_up       { background: #e0e7ff; color: #3730a3; }
.knx-hvo__status-badge.st-completed       { background: #f3f4f6; color: #6b7280; }
.knx-hvo__status-badge.st-cancelled       { background: #fee2e2; color: #991b1b; }

.knx-hvo__fulfillment-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
}

.knx-hvo__fulfillment-badge.is-delivery { background: #eff6ff; color: #1e40af; }
.knx-hvo__fulfillment-badge.is-pickup   { background: #f0fdf4; color: #166534; }

.knx-hvo__time {
    font-size: 12px;
    color: #9ca3af;
}

/* Customer info */
.knx-hvo__info-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #374151;
    margin-bottom: 6px;
}

.knx-hvo__info-row i { color: #9ca3af; font-size: 13px; width: 16px; text-align: center; }

.knx-hvo__info-label {
    font-weight: 600;
    color: #6b7280;
    min-width: 50px;
}

/* Driver section */
.knx-hvo__driver {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 10px;
    background: #eff6ff;
}

.knx-hvo__driver-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #1e40af;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}

.knx-hvo__driver-name { font-weight: 700; color: #1e40af; font-size: 14px; }
.knx-hvo__driver-phone { font-size: 12px; color: #6b7280; }

.knx-hvo__driver-pending {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 10px;
    background: #f9fafb;
    color: #9ca3af;
    font-size: 13px;
    font-weight: 500;
}

/* Items */
.knx-hvo__item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
}

.knx-hvo__item:last-child { border-bottom: none; }

.knx-hvo__item-img {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    object-fit: cover;
    background: #f3f4f6;
    flex-shrink: 0;
}

.knx-hvo__item-img-placeholder {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #d1d5db;
    font-size: 18px;
    flex-shrink: 0;
}

.knx-hvo__item-body { flex: 1; min-width: 0; }

.knx-hvo__item-name {
    font-weight: 600;
    font-size: 14px;
    color: #0b1220;
}

.knx-hvo__item-qty {
    font-size: 12px;
    color: #6b7280;
    margin-top: 2px;
}

.knx-hvo__item-mods {
    margin-top: 4px;
}

.knx-hvo__item-mod-group {
    font-size: 12px;
    color: #6b7280;
    margin-top: 2px;
}

.knx-hvo__item-mod-group strong {
    color: #374151;
    font-weight: 600;
}

.knx-hvo__mod-remove {
    color: #dc2626;
    font-style: italic;
}

.knx-hvo__item-total {
    font-weight: 700;
    font-size: 14px;
    color: #0b1220;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Totals */
.knx-hvo__totals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
    font-size: 14px;
    color: #374151;
}

.knx-hvo__totals-row.is-total {
    border-top: 2px solid #e5e7eb;
    margin-top: 6px;
    padding-top: 10px;
    font-weight: 700;
    font-size: 16px;
    color: #0b1220;
}

/* Notes */
.knx-hvo__notes {
    font-size: 13px;
    color: #6b7280;
    background: #f9fafb;
    border-radius: 8px;
    padding: 10px 14px;
    line-height: 1.5;
}

/* Timeline */
.knx-hvo__timeline {
    position: relative;
    padding-left: 28px;
}

.knx-hvo__tl-step {
    position: relative;
    padding-bottom: 16px;
}

.knx-hvo__tl-step:last-child { padding-bottom: 0; }

.knx-hvo__tl-dot {
    position: absolute;
    left: -28px;
    top: 2px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #e5e7eb;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #e5e7eb;
}

.knx-hvo__tl-step.is-done .knx-hvo__tl-dot { background: #10b981; box-shadow: 0 0 0 2px #10b981; }
.knx-hvo__tl-step.is-current .knx-hvo__tl-dot { background: #3b82f6; box-shadow: 0 0 0 2px #3b82f6; animation: knx-pulse 1.5s infinite; }
.knx-hvo__tl-step.is-cancelled .knx-hvo__tl-dot { background: #ef4444; box-shadow: 0 0 0 2px #ef4444; }

@keyframes knx-pulse {
    0%, 100% { box-shadow: 0 0 0 2px #3b82f6; }
    50% { box-shadow: 0 0 0 6px rgba(59, 130, 246, 0.25); }
}

.knx-hvo__tl-line {
    position: absolute;
    left: -22px;
    top: 18px;
    bottom: 0;
    width: 2px;
    background: #e5e7eb;
}

.knx-hvo__tl-step.is-done .knx-hvo__tl-line { background: #10b981; }
.knx-hvo__tl-step:last-child .knx-hvo__tl-line { display: none; }

.knx-hvo__tl-label {
    font-weight: 600;
    font-size: 13px;
    color: #374151;
}

.knx-hvo__tl-step.is-done .knx-hvo__tl-label { color: #065f46; }
.knx-hvo__tl-step.is-current .knx-hvo__tl-label { color: #1e40af; font-weight: 700; }
.knx-hvo__tl-step.is-cancelled .knx-hvo__tl-label { color: #dc2626; font-weight: 700; }

.knx-hvo__tl-time {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 1px;
}

/* Signal button */
.knx-hvo__signal-section {
    text-align: center;
    margin-bottom: 14px;
}

.knx-hvo__signal-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 28px;
    border-radius: 12px;
    border: none;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.12s ease;
    background: #10b981;
    color: #fff;
}

.knx-hvo__signal-btn:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
}

.knx-hvo__signal-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.knx-hvo__signal-btn.sent {
    background: #d1fae5;
    color: #065f46;
    cursor: default;
}

.knx-hvo__signaled {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    background: #d1fae5;
    color: #065f46;
}

@media (max-width: 600px) {
    .knx-hvo { padding: 12px; }
    .knx-hvo__section { padding: 14px; }
    .knx-hvo__item { gap: 8px; }
    .knx-hvo__item-img, .knx-hvo__item-img-placeholder { width: 40px; height: 40px; }
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

<div class="knx-hvo"
     data-order-id="<?php echo esc_attr($order_id); ?>"
     data-hub-id="<?php echo esc_attr($hub_id); ?>"
     data-hub-name="<?php echo esc_attr($hub_name); ?>"
     data-wp-nonce="<?php echo esc_attr($wp_nonce); ?>"
     data-knx-nonce="<?php echo esc_attr($knx_nonce); ?>"
     data-api-order="<?php echo esc_attr($api_order); ?>"
     data-api-signal="<?php echo esc_attr($api_signal); ?>"
     data-back-url="<?php echo esc_attr($back_url); ?>">

    <!-- Top bar -->
    <div class="knx-hvo__topbar">
        <a href="<?php echo $back_url; ?>" class="knx-hvo__back">
            <i class="fas fa-arrow-left"></i> Orders
        </a>
        <span class="knx-hvo__order-id"><?php echo $order_id > 0 ? '#' . $order_id . ' ' . $hub_name : 'Missing order'; ?></span>
    </div>

    <!-- Content (rendered by JS) -->
    <div id="hvoContent">
        <?php if ($order_id > 0): ?>
            <div class="knx-hvo__loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading order details…</p>
            </div>
        <?php else: ?>
            <div class="knx-hvo__error">
                <i class="fas fa-exclamation-circle"></i>
                <p>No order ID provided.</p>
                <a href="<?php echo $back_url; ?>" class="knx-hvo__back" style="margin-top:12px;">
                    <i class="fas fa-arrow-left"></i> Back to Orders
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.js'); ?>"></script>
<script>
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var wrap = document.querySelector('.knx-hvo');
        if (!wrap) return;

        var orderId   = parseInt(wrap.dataset.orderId, 10) || 0;
        var hubId     = wrap.dataset.hubId;
        var hubName   = wrap.dataset.hubName || '';
        var wpNonce   = wrap.dataset.wpNonce;
        var knxNonce  = wrap.dataset.knxNonce;
        var apiOrder  = wrap.dataset.apiOrder;
        var apiSignal = wrap.dataset.apiSignal;
        var backUrl   = wrap.dataset.backUrl;

        var contentEl = document.getElementById('hvoContent');

        if (!orderId || orderId <= 0) return;

        var STATUS_LABELS = {
            confirmed:           'New Order',
            accepted_by_driver:  'Driver Accepted',
            accepted_by_hub:     'Accepted',
            preparing:           'Preparing',
            prepared:            'Prepared',
            picked_up:           'Picked Up',
            ready_for_pickup:    'Ready for Pickup',
            completed:           'Completed',
            cancelled:           'Cancelled',
            order_created:       'Order Created',
        };

        // ── Fetch order ───────────────────────────────────
        fetch(apiOrder, {
            method: 'GET',
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin',
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success && json.order) {
                renderOrder(json.order);
            } else {
                contentEl.innerHTML = '<div class="knx-hvo__error"><i class="fas fa-exclamation-circle"></i><p>' +
                    esc(json.error || 'Failed to load order.') + '</p>' +
                    '<a href="' + esc(backUrl) + '" class="knx-hvo__back" style="margin-top:12px;"><i class="fas fa-arrow-left"></i> Back to Orders</a></div>';
            }
        })
        .catch(function() {
            contentEl.innerHTML = '<div class="knx-hvo__error"><i class="fas fa-exclamation-circle"></i><p>Network error. Please try again.</p>' +
                '<a href="' + esc(backUrl) + '" class="knx-hvo__back" style="margin-top:12px;"><i class="fas fa-arrow-left"></i> Back to Orders</a></div>';
        });

        // ── Render full order ─────────────────────────────
        function renderOrder(order) {
            var html = '';

            // ── Status + fulfillment header ───────────────
            var statusLabel = STATUS_LABELS[order.status] || order.status.replace(/_/g, ' ');
            var isPickup = order.fulfillment_type === 'pickup';
            var isActive = ['confirmed', 'accepted_by_driver', 'accepted_by_hub', 'preparing', 'prepared'].indexOf(order.status) !== -1;

            html += '<div class="knx-hvo__section">';
            html += '<div class="knx-hvo__status-row">';
            html += '<span class="knx-hvo__status-badge st-' + esc(order.status) + '">' + esc(statusLabel) + '</span>';

            if (isPickup) {
                html += '<span class="knx-hvo__fulfillment-badge is-pickup"><i class="fas fa-shopping-bag"></i> Pickup</span>';
            } else {
                html += '<span class="knx-hvo__fulfillment-badge is-delivery"><i class="fas fa-motorcycle"></i> Delivery</span>';
            }

            html += '<span class="knx-hvo__time">' + esc(formatDate(order.created_at)) + '</span>';
            html += '</div>';
            html += '</div>';

            // ── Signal button (if active) ─────────────────
            if (isActive) {
                html += '<div class="knx-hvo__signal-section" id="hvoSignalSection">';
                html += '<button type="button" class="knx-hvo__signal-btn" id="hvoSignalBtn">' +
                    '<i class="fas fa-bell"></i> Ready for Pickup</button>';
                html += '</div>';
            }

            // ── Customer info ─────────────────────────────
            var cust = order.customer || {};
            html += '<div class="knx-hvo__section">';
            html += '<div class="knx-hvo__section-title"><i class="fas fa-user"></i> Customer</div>';
            if (cust.name) {
                html += '<div class="knx-hvo__info-row"><i class="fas fa-user"></i> ' + esc(cust.name) + '</div>';
            }
            if (cust.phone) {
                html += '<div class="knx-hvo__info-row"><i class="fas fa-phone"></i> ' + esc(cust.phone) + '</div>';
            }
            html += '</div>';

            // ── Driver info (delivery only) ───────────────
            if (!isPickup) {
                var drv = order.driver || {};
                html += '<div class="knx-hvo__section">';
                html += '<div class="knx-hvo__section-title"><i class="fas fa-motorcycle"></i> Driver</div>';
                if (drv.name) {
                    html += '<div class="knx-hvo__driver">';
                    html += '<div class="knx-hvo__driver-icon"><i class="fas fa-user-shield"></i></div>';
                    html += '<div>';
                    html += '<div class="knx-hvo__driver-name">' + esc(drv.name) + '</div>';
                    if (drv.phone) {
                        html += '<div class="knx-hvo__driver-phone"><i class="fas fa-phone"></i> ' + esc(drv.phone) + '</div>';
                    }
                    html += '</div>';
                    html += '</div>';
                } else {
                    html += '<div class="knx-hvo__driver-pending"><i class="fas fa-clock"></i> Awaiting driver assignment</div>';
                }
                html += '</div>';
            }

            // ── Items ─────────────────────────────────────
            var items = order.items || [];
            html += '<div class="knx-hvo__section">';
            html += '<div class="knx-hvo__section-title"><i class="fas fa-utensils"></i> Items (' + items.length + ')</div>';

            if (items.length) {
                items.forEach(function(it) {
                    var name = it.name_snapshot || it.name || 'Item';
                    var qty  = it.qty || it.quantity || 1;
                    var unit = parseFloat(it.unit_price || 0);
                    var line = parseFloat(it.line_total || 0);
                    var img  = it.image_snapshot || it.image || '';

                    html += '<div class="knx-hvo__item">';

                    // Image
                    if (img) {
                        html += '<img class="knx-hvo__item-img" src="' + esc(img) + '" alt="' + esc(name) + '" loading="lazy">';
                    } else {
                        html += '<div class="knx-hvo__item-img-placeholder"><i class="fas fa-image"></i></div>';
                    }

                    // Body
                    html += '<div class="knx-hvo__item-body">';
                    html += '<div class="knx-hvo__item-name">' + esc(name) + '</div>';
                    html += '<div class="knx-hvo__item-qty">' + qty + ' × $' + money(unit) + '</div>';

                    // Modifiers
                    var mods = normalizeModifiers(it.modifiers);
                    if (mods.length) {
                        html += '<div class="knx-hvo__item-mods">';
                        mods.forEach(function(m) {
                            html += '<div class="knx-hvo__item-mod-group">';
                            if (m.group) html += '<strong>' + m.group + ':</strong> ';
                            html += m.optionsHtml;
                            html += '</div>';
                        });
                        html += '</div>';
                    }

                    html += '</div>';

                    // Line total
                    html += '<div class="knx-hvo__item-total">$' + money(line) + '</div>';
                    html += '</div>';
                });
            } else {
                html += '<div style="color:#9ca3af;font-size:13px;">No items recorded.</div>';
            }

            html += '</div>';

            // ── Notes ─────────────────────────────────────
            if (order.notes) {
                html += '<div class="knx-hvo__section">';
                html += '<div class="knx-hvo__section-title"><i class="fas fa-sticky-note"></i> Notes</div>';
                html += '<div class="knx-hvo__notes">' + esc(order.notes) + '</div>';
                html += '</div>';
            }

            contentEl.innerHTML = html;

            // ── Attach signal handler ─────────────────────
            var signalBtn = document.getElementById('hvoSignalBtn');
            if (signalBtn) {
                signalBtn.addEventListener('click', function() {
                    sendSignal(signalBtn);
                });
            }
        }

        // ── Send Signal ───────────────────────────────────
        function sendSignal(btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

            fetch(apiSignal, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpNonce,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    hub_id: hubId,
                    knx_nonce: knxNonce,
                    order_id: orderId,
                }),
            })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success) {
                    var section = document.getElementById('hvoSignalSection');
                    if (section) {
                        section.innerHTML = '<span class="knx-hvo__signaled"><i class="fas fa-check-circle"></i> Ready signal sent</span>';
                    }
                    toast('success', 'Ready signal sent to driver');
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-bell"></i> Ready for Pickup';
                    toast('error', json.message || json.error || 'Failed to send signal');
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-bell"></i> Ready for Pickup';
                toast('error', 'Network error');
            });
        }

        // ── Helpers ───────────────────────────────────────
        function totalsRow(label, val) {
            var v = parseFloat(val || 0);
            if (v === 0) return '';
            var sign = v < 0 ? '-' : '';
            return '<div class="knx-hvo__totals-row"><span>' + esc(label) + '</span><span>' + sign + '$' + money(Math.abs(v)) + '</span></div>';
        }

        function normalizeModifiers(mods) {
            if (!Array.isArray(mods) || !mods.length) return [];
            var out = [];
            mods.forEach(function(group) {
                var gName = String((group && (group.group || group.name)) || '').trim();
                var options = Array.isArray(group && group.options) ? group.options : (Array.isArray(group && group.selected) ? group.selected : []);
                if (!gName && !options.length) return;

                var rendered = [];
                options.forEach(function(opt) {
                    var oName = String((opt && (opt.option || opt.name || opt.value)) || '').trim();
                    if (!oName) return;

                    if (opt && opt.option_action === 'remove') {
                        rendered.push('<span class="knx-hvo__mod-remove">No ' + esc(oName) + '</span>');
                        return;
                    }

                    var deltaRaw = (opt.price_adjustment !== undefined) ? opt.price_adjustment :
                        (opt.price !== undefined) ? opt.price :
                        (opt.delta !== undefined) ? opt.delta : undefined;
                    var delta = Number(deltaRaw);
                    var deltaTxt = (isFinite(delta) && delta !== 0) ? ' (+$' + money(delta) + ')' : '';
                    rendered.push(esc(oName) + deltaTxt);
                });

                out.push({
                    group: esc(gName),
                    optionsHtml: rendered.length ? rendered.join(', ') : '',
                });
            });
            return out;
        }

        function money(val) {
            var n = parseFloat(val);
            if (!isFinite(n)) return '0.00';
            return n.toFixed(2);
        }

        function formatDate(mysql) {
            if (!mysql) return '';
            try {
                var parts = mysql.split(' ');
                if (parts.length !== 2) return mysql;
                var d = parts[0].split('-').map(Number);
                var t = parts[1].split(':').map(Number);
                var dt = new Date(Date.UTC(d[0], d[1] - 1, d[2], t[0] || 0, t[1] || 0, t[2] || 0));
                var now = new Date();
                var diff = now - dt;
                if (diff < 0) diff = 0;
                var sec = Math.floor(diff / 1000);
                if (sec < 60) return sec + 's ago';
                var min = Math.floor(sec / 60);
                if (min < 60) return min + 'm ago';
                var hr = Math.floor(min / 60);
                if (hr < 24) return hr + 'h ago';
                // Show actual date for older orders
                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return months[d[1]-1] + ' ' + d[2] + ', ' + d[0] + ' ' + String(t[0]).padStart(2,'0') + ':' + String(t[1]).padStart(2,'0');
            } catch(e) {
                return mysql;
            }
        }

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = (s === null || s === undefined) ? '' : String(s);
            return d.innerHTML;
        }

        function toast(type, msg) {
            if (typeof window.knxToast === 'function') {
                window.knxToast(msg, type);
            } else if (typeof window.KnxToast !== 'undefined' && typeof window.KnxToast.show === 'function') {
                window.KnxToast.show(msg, type);
            }
        }
    }
})();
</script>

<?php
    return ob_get_clean();
});
