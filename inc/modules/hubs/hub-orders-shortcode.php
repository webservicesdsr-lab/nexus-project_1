<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Management — Orders Shortcode [knx_hub_orders]
 * ----------------------------------------------------------
 * Incoming orders page for hub_management role.
 *
 * Features:
 *  - Live list of paid orders for the hub
 *  - Status badge per order
 *  - FAKE "Ready for Pickup" button (visual signal only,
 *    does NOT change the real order status enum)
 *  - Manual refresh
 *
 * Security: session + hub role + ownership (fail-closed)
 * ==========================================================
 */

add_shortcode('knx_hub_orders', function () {
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

    // Hub name for header
    $hubs_table = $wpdb->prefix . 'knx_hubs';
    $hub = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$hubs_table} WHERE id = %d LIMIT 1", $hub_id));
    $hub_name = $hub ? esc_html($hub->name) : 'Hub #' . $hub_id;

    $nonce    = wp_create_nonce('knx_hub_orders_nonce');
    $wp_nonce = wp_create_nonce('wp_rest');

    $ver = defined('KNX_VERSION') ? KNX_VERSION : '1.0';

    ob_start();
    ?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.css'); ?>">

<style>
/* ── Hub Orders Page ───────────────────────────────────── */
.knx-hub-orders {
    max-width: 900px;
    margin: 0 auto;
    padding: 16px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.knx-ho-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.knx-ho-header h1 {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 700;
    color: #0b1220;
    display: flex;
    align-items: center;
    gap: 10px;
}

.knx-ho-header h1 i { color: #0b793a; }

.knx-ho-controls {
    display: flex;
    gap: 10px;
    align-items: center;
}

.knx-ho-refresh {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #fff;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.12s ease;
}

.knx-ho-refresh:hover {
    background: #f3f4f6;
    transform: translateY(-1px);
}

.knx-ho-count {
    font-size: 13px;
    color: #6b7280;
    font-weight: 600;
}

/* Auto-refresh toggle */
.knx-ho-auto-refresh {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #fff;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.12s ease;
    user-select: none;
}

.knx-ho-auto-refresh:hover {
    background: #f3f4f6;
}

.knx-ho-auto-refresh.active {
    background: #dcfce7;
    border-color: #10b981;
    color: #065f46;
}

.knx-ho-auto-refresh input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.knx-ho-countdown {
    font-size: 12px;
    color: #9ca3af;
    font-weight: 500;
}

/* Filter tabs */
.knx-ho-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.knx-ho-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    border: 2px solid #e5e7eb;
    background: #fff;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.15s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.knx-ho-tab:hover {
    background: #f9fafb;
    border-color: #0b793a;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(11, 121, 58, 0.1);
}

.knx-ho-tab.active {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    border-color: #059669;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    transform: translateY(-1px);
}

.knx-ho-tab i {
    font-size: 13px;
}

/* Order card */
.knx-ho-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    padding: 16px 20px;
    margin-bottom: 12px;
    border-left: 4px solid #e5e7eb;
    transition: box-shadow 0.12s ease, transform 0.12s ease;
}

.knx-ho-card.is-new { border-left-color: #3b82f6; }
.knx-ho-card.is-progress { border-left-color: #f59e0b; }
.knx-ho-card.is-ready { border-left-color: #10b981; }
.knx-ho-card.is-done { border-left-color: #6b7280; }

.knx-ho-card__top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 10px;
}

.knx-ho-card__id {
    font-weight: 700;
    font-size: 15px;
    color: #0b1220;
}

.knx-ho-card__customer {
    font-size: 14px;
    color: #374151;
    font-weight: 500;
}

.knx-ho-card__phone {
    font-size: 12px;
    color: #6b7280;
}

.knx-ho-card__time {
    font-size: 12px;
    color: #9ca3af;
}

.knx-ho-card__right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
}

.knx-ho-card__total {
    font-weight: 700;
    font-size: 15px;
    color: #0b1220;
}

/* Status badge */
.knx-ho-status {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.knx-ho-status.st-confirmed       { background: #dbeafe; color: #1e40af; }
.knx-ho-status.st-accepted_by_driver { background: #fef3c7; color: #92400e; }
.knx-ho-status.st-accepted_by_hub { background: #fef3c7; color: #92400e; }
.knx-ho-status.st-preparing       { background: #ffedd5; color: #9a3412; }
.knx-ho-status.st-prepared        { background: #d1fae5; color: #065f46; }
.knx-ho-status.st-picked_up       { background: #e0e7ff; color: #3730a3; }
.knx-ho-status.st-completed       { background: #f3f4f6; color: #6b7280; }
.knx-ho-status.st-cancelled       { background: #fee2e2; color: #991b1b; }

/* Fulfillment badge */
.knx-ho-fulfillment {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}

.knx-ho-fulfillment.is-delivery { background: #eff6ff; color: #1e40af; }
.knx-ho-fulfillment.is-pickup   { background: #f0fdf4; color: #166534; }

/* Notes */
.knx-ho-card__notes {
    font-size: 13px;
    color: #6b7280;
    background: #f9fafb;
    border-radius: 8px;
    padding: 8px 12px;
    margin-top: 8px;
}

/* Clickable card link */
.knx-ho-card-link {
    text-decoration: none;
    color: inherit;
    display: block;
}

.knx-ho-card-link:hover .knx-ho-card {
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}

/* Driver info */
.knx-ho-card__driver {
    font-size: 13px;
    color: #1e40af;
    font-weight: 600;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.knx-ho-card__driver i { font-size: 12px; }

.knx-ho-card__driver--pending {
    color: #9ca3af;
    font-weight: 500;
}

/* Ready button */
.knx-ho-card__actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    align-items: center;
}

.knx-ho-ready-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 10px;
    border: none;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.12s ease;
    background: #10b981;
    color: #fff;
}

.knx-ho-ready-btn:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.knx-ho-ready-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.knx-ho-ready-btn.sent {
    background: #d1fae5;
    color: #065f46;
    cursor: default;
}

/* Signaled chip */
.knx-ho-signaled {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    background: #d1fae5;
    color: #065f46;
}

/* Empty state */
.knx-ho-empty {
    text-align: center;
    padding: 48px 24px;
    color: #6b7280;
}

.knx-ho-empty i {
    font-size: 48px;
    color: #d1d5db;
    margin-bottom: 12px;
    display: block;
}

/* Items preview */
.knx-ho-card__items {
    margin-top: 8px;
    font-size: 13px;
    color: #374151;
}

.knx-ho-card__items span {
    display: inline-block;
    background: #f3f4f6;
    padding: 2px 8px;
    border-radius: 6px;
    margin: 2px 4px 2px 0;
    font-size: 12px;
}

.knx-ho-card__items-more {
    background: #e5e7eb !important;
    color: #6b7280;
    font-weight: 600;
}

@media (max-width: 600px) {
    .knx-hub-orders { padding: 12px; }
    .knx-ho-card { padding: 14px; }
    .knx-ho-card__top { flex-direction: column; }
    .knx-ho-card__right { align-items: flex-start; flex-direction: row; gap: 8px; }
    .knx-ho-header { flex-direction: column; align-items: stretch; }
    .knx-ho-controls {
        flex-wrap: wrap;
        justify-content: space-between;
    }
    .knx-ho-countdown {
        width: 100%;
        text-align: center;
    }
    .knx-ho-tabs {
        gap: 6px;
    }
    .knx-ho-tab {
        flex: 1 1 auto;
        min-width: fit-content;
        font-size: 12px;
        padding: 8px 12px;
        justify-content: center;
    }
    .knx-ho-tab i {
        font-size: 11px;
    }
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

<div class="knx-hub-orders"
     data-hub-id="<?php echo esc_attr($hub_id); ?>"
    data-hub-name="<?php echo esc_attr($hub_name); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-wp-nonce="<?php echo esc_attr($wp_nonce); ?>"
     data-api-orders="<?php echo esc_url(rest_url('knx/v1/hub-management/orders')); ?>"
     data-api-signal="<?php echo esc_url(rest_url('knx/v1/hub-management/orders/signal')); ?>">

    <!-- Header -->
    <div class="knx-ho-header">
        <h1><i class="fas fa-receipt"></i> Orders — <?php echo $hub_name; ?></h1>
        <div class="knx-ho-controls">
            <span class="knx-ho-count" id="hoOrderCount"></span>
            <span class="knx-ho-countdown" id="hoCountdown"></span>
            <label class="knx-ho-auto-refresh" id="hoAutoRefreshLabel">
                <input type="checkbox" id="hoAutoRefresh">
                <span>Auto-refresh</span>
            </label>
            <button type="button" class="knx-ho-refresh" id="hoRefreshBtn">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="knx-ho-tabs" id="hoTabs">
        <button class="knx-ho-tab active" data-filter="active">
            <i class="fas fa-clock"></i> Active
        </button>
        <button class="knx-ho-tab" data-filter="new">
            <i class="fas fa-star"></i> New
        </button>
        <button class="knx-ho-tab" data-filter="in-progress">
            <i class="fas fa-spinner"></i> In Progress
        </button>
        <button class="knx-ho-tab" data-filter="ready">
            <i class="fas fa-check-circle"></i> Ready
        </button>
        <button class="knx-ho-tab" data-filter="completed">
            <i class="fas fa-check-double"></i> Completed
        </button>
    </div>

    <!-- Orders List -->
    <div id="hoOrdersList">
        <div class="knx-ho-empty">
            <i class="fas fa-spinner fa-spin"></i>
            Loading orders...
        </div>
    </div>
</div>

<script src="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.js'); ?>"></script>
<script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/hub-orders-script.js?v=' . $ver); ?>"></script>

<?php
    return ob_get_clean();
});
