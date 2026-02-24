<?php
if (!defined('ABSPATH')) exit;

/**
 * ════════════════════════════════════════════════════════════════
 * KNX OPS — All Orders History (Shortcode)
 * Shortcode: [knx_all_orders]
 * ════════════════════════════════════════════════════════════════
 *
 * - Visible to: super_admin, manager
 * - super_admin: city picker (remember checkboxes) + see all history
 * - manager:     scoped to assigned cities (same city-picker UX)
 * - Table view: ID pill, restaurant logo + name, status badge
 * - Nexus-style pagination
 *
 * [KNX-OPS-ALL-ORDERS-1.0]
 */

add_shortcode('knx_all_orders', function () {
    global $wpdb;

    if (!function_exists('knx_get_session')) {
        return '<div class="knx-ao-error">Session unavailable.</div>';
    }

    $session = knx_get_session();
    $role    = $session && isset($session->role) ? (string)$session->role : '';

    if (!in_array($role, ['super_admin', 'manager'], true)) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    // Manager assigned cities (for city picker constraint)
    $managed_cities = [];
    if ($role === 'manager') {
        $user_id  = (int)($session->user_id ?? 0);
        $mc_table = $wpdb->prefix . 'knx_manager_cities';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $mc_table));
        if (empty($exists)) {
            return '<div class="knx-ao-error">Manager city assignment not configured.</div>';
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city_id FROM {$mc_table} WHERE manager_user_id = %d",
            $user_id
        ));
        $managed_cities = array_values(array_filter(array_map('intval', (array)$ids)));

        if (empty($managed_cities)) {
            return '<div class="knx-ao-error">No cities assigned to this manager.</div>';
        }
    }

    // Inline assets
    $css_path = __DIR__ . '/all-orders-style.css';
    $js_path  = __DIR__ . '/all-orders-script.js';
    $css = file_exists($css_path) ? (string)file_get_contents($css_path) : '';
    $js  = file_exists($js_path)  ? (string)file_get_contents($js_path)  : '';

    $api_url      = esc_url(rest_url('knx/v1/ops/all-orders'));
    $cities_url   = esc_url(rest_url('knx/v2/cities/get'));
    $view_url     = esc_url(site_url('/view-order'));
    $rest_nonce   = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

    ob_start();
    ?>
    <?php if ($css): ?><style data-knx="all-orders-style"><?php echo $css; ?></style><?php endif; ?>

    <div id="knxAllOrdersApp"
         class="knx-ao"
         data-api-url="<?php echo $api_url; ?>"
         data-cities-url="<?php echo $cities_url; ?>"
         data-view-order-url="<?php echo $view_url; ?>"
         data-rest-nonce="<?php echo esc_attr($rest_nonce); ?>"
         data-role="<?php echo esc_attr($role); ?>"
         data-managed-cities='<?php echo wp_json_encode($managed_cities); ?>'>

        <!-- ── Top bar ── -->
        <div class="knx-ao__topbar">
            <div class="knx-ao__topbar-left">
                <button type="button" class="knx-ao-btn knx-ao-btn--outline" id="knxAOCitiesBtn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    Cities
                </button>
                <span class="knx-ao__cities-pill" id="knxAOCitiesPill">No cities selected</span>
            </div>

            <div class="knx-ao__topbar-right">
                <!-- Status filter -->
                <select class="knx-ao-select" id="knxAOStatusFilter" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="accepted_by_driver">Accepted by Driver</option>
                    <option value="accepted_by_hub">Accepted by Hub</option>
                    <option value="preparing">Preparing</option>
                    <option value="prepared">Prepared</option>
                    <option value="picked_up">Picked Up</option>
                    <option value="completed">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <!-- Search -->
                <div class="knx-ao__search-wrap">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="search" class="knx-ao-input" id="knxAOSearch"
                           placeholder="Order ID or restaurant…" autocomplete="off">
                </div>
            </div>
        </div>

        <!-- ── State message ── -->
        <div class="knx-ao__state" id="knxAOState">Select a city to view orders.</div>

        <!-- ── Table ── -->
        <div class="knx-ao__table-wrap" id="knxAOTableWrap" hidden>
            <table class="knx-ao__table" aria-label="Orders history">
                <thead>
                    <tr>
                        <th class="knx-ao__th knx-ao__th--id">ID</th>
                        <th class="knx-ao__th knx-ao__th--restaurant">RESTAURANT</th>
                        <th class="knx-ao__th knx-ao__th--status">LAST STATUS</th>
                    </tr>
                </thead>
                <tbody id="knxAOTableBody">
                </tbody>
            </table>
        </div>

        <!-- ── Pagination ── -->
        <nav class="knx-ao__pagination" id="knxAOPagination" hidden aria-label="Orders pagination">
            <button type="button" class="knx-ao-btn knx-ao-btn--ghost knx-ao__pg-prev" id="knxAOPrev"
                    aria-label="Previous page" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>

            <div class="knx-ao__pg-pages" id="knxAOPageNumbers" role="list"></div>

            <button type="button" class="knx-ao-btn knx-ao-btn--ghost knx-ao__pg-next" id="knxAONext"
                    aria-label="Next page" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
        </nav>

        <!-- ── City picker modal ── -->
        <div class="knx-ao-modal" id="knxAOModal" aria-hidden="true">
            <div class="knx-ao-modal__backdrop" data-close="1"></div>
            <div class="knx-ao-modal__panel" role="dialog" aria-modal="true" aria-labelledby="knxAOModalTitle">
                <div class="knx-ao-modal__header">
                    <div>
                        <div class="knx-ao-modal__title" id="knxAOModalTitle">Select Cities</div>
                        <div class="knx-ao-modal__hint">
                            <?php if ($role === 'super_admin'): ?>
                                Super Admin: choose any cities.
                            <?php else: ?>
                                Manager: limited to your assigned cities.
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="button" class="knx-ao-btn knx-ao-btn--ghost" data-close="1"
                            aria-label="Close">✕</button>
                </div>

                <div class="knx-ao-modal__actions">
                    <button type="button" class="knx-ao-btn knx-ao-btn--ghost" id="knxAOSelectAll">Select all</button>
                    <button type="button" class="knx-ao-btn knx-ao-btn--ghost" id="knxAOClearAll">Clear</button>
                </div>

                <div class="knx-ao-modal__list" id="knxAOCityList">
                    <div class="knx-ao-skel">Loading cities…</div>
                </div>

                <div class="knx-ao-modal__footer">
                    <button type="button" class="knx-ao-btn knx-ao-btn--primary" id="knxAOApplyCities">Apply</button>
                </div>
            </div>
        </div>

    </div>

    <?php if ($js): ?>
    <script data-knx="all-orders-script"><?php echo $js; ?></script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
});
